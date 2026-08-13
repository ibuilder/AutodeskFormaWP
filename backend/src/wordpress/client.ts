import { getConfig } from '../config.js';
import { logger } from '../logger.js';
import { signRequest } from '../security/hmac.js';
import type { CanonicalPayload } from '../canonical/schema.js';

export interface IngestResult {
	success: boolean;
	result?: {
		status: string;
		post_id?: number;
		source_id?: string;
		permalink?: string;
		payload_hash?: string;
		assets?: { created: number; updated: number; removed: number };
		message?: string;
	};
}

export class WordPressError extends Error {
	constructor(
		message: string,
		readonly status: number,
		readonly code: string,
		readonly retryable: boolean
	) {
		super( message );
		this.name = 'WordPressError';
	}
}

/**
 * Signed HTTP client for the WordPress receiver.
 *
 * The signed route must match `WP_REST_Request::get_route()` on the WordPress
 * side, which excludes the `/wp-json` prefix. Both the URL and the signed route
 * are derived from the same source here so they cannot drift apart.
 */
export class WordPressClient {
	private readonly baseUrl: string;
	private readonly restPrefix: string;
	private readonly keyId: string;
	private readonly secret: string;
	private readonly timeoutMs: number;

	constructor( options?: {
		baseUrl?: string;
		restPrefix?: string;
		keyId?: string;
		secret?: string;
		timeoutMs?: number;
	} ) {
		const config = getConfig();

		this.baseUrl = ( options?.baseUrl ?? config.WORDPRESS_URL ).replace( /\/+$/, '' );
		this.restPrefix = `/${ ( options?.restPrefix ?? config.WORDPRESS_REST_PREFIX ).replace( /^\/+|\/+$/g, '' ) }`;
		this.keyId = options?.keyId ?? config.WORDPRESS_KEY_ID;
		this.secret = options?.secret ?? config.WORDPRESS_SECRET;
		this.timeoutMs = options?.timeoutMs ?? config.HTTP_TIMEOUT_MS;
	}

	/** Sends a canonical payload to the ingest endpoint. */
	async ingest( payload: CanonicalPayload ): Promise<IngestResult> {
		return this.send<IngestResult>( '/forma-publisher/v1/ingest', 'POST', payload );
	}

	/** Reads the stored state of one project. */
	async lookup( sourceId: string ): Promise<Record<string, unknown>> {
		return this.send<Record<string, unknown>>( '/forma-publisher/v1/lookup', 'POST', {
			source_id: sourceId,
		} );
	}

	/** Reads receiver status, used as a connectivity check. */
	async status(): Promise<Record<string, unknown>> {
		return this.send<Record<string, unknown>>( '/forma-publisher/v1/status', 'GET' );
	}

	private async send<T>( route: string, method: 'GET' | 'POST', payload?: unknown ): Promise<T> {
		const body = method === 'GET' || payload === undefined ? '' : JSON.stringify( payload );
		const url = `${ this.baseUrl }${ this.restPrefix }${ route }`;

		const headers: Record<string, string> = {
			accept: 'application/json',
			...signRequest( {
				method,
				route,
				body,
				keyId: this.keyId,
				secret: this.secret,
			} ),
		};

		if ( body !== '' ) {
			headers[ 'content-type' ] = 'application/json';
		}

		const controller = new AbortController();
		const timer = setTimeout( () => controller.abort(), this.timeoutMs );

		let response: Response;

		try {
			response = await fetch( url, {
				method,
				headers,
				body: body === '' ? undefined : body,
				signal: controller.signal,
			} );
		} catch ( error ) {
			const message = error instanceof Error ? error.message : String( error );

			throw new WordPressError( `Request to WordPress failed: ${ message }`, 0, 'network_error', true );
		} finally {
			clearTimeout( timer );
		}

		const text = await response.text();
		let parsed: unknown = null;

		if ( text ) {
			try {
				parsed = JSON.parse( text );
			} catch {
				parsed = null;
			}
		}

		if ( ! response.ok ) {
			const detail = parsed as { code?: string; message?: string } | null;
			const code = detail?.code ?? `http_${ response.status }`;
			const message = detail?.message ?? `WordPress returned HTTP ${ response.status }.`;

			// 409 means the payload was already applied; 4xx are caller errors and
			// must not be retried. 429 and 5xx are transient.
			const retryable = response.status === 429 || response.status >= 500;

			logger.warn( 'WordPress rejected a request', { route, status: response.status, code } );

			throw new WordPressError( message, response.status, code, retryable );
		}

		return parsed as T;
	}
}
