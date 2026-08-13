/**
 * Client for the publishing backend.
 *
 * The extension never talks to Autodesk or WordPress directly: it only calls
 * this backend, which holds every credential.
 */

export interface CanonicalMetric {
	key: string;
	label?: string;
	value?: number | string | null;
	unit?: string;
	category?: string;
	precision?: number;
}

export interface CanonicalAsset {
	source_id: string;
	title: string;
	kind?: string;
	url?: string;
	mime_type?: string;
	size?: number;
}

export interface CanonicalProject {
	source_id: string;
	title: string;
	summary?: string;
	content?: string;
	status?: string;
	source_url?: string;
	tags?: string[];
	statuses?: string[];
	metrics?: CanonicalMetric[];
	assets?: CanonicalAsset[];
	location?: { latitude?: number; longitude?: number; address?: string };
}

export interface PublishJob {
	id: string;
	operation: string;
	mode: string;
	sourceId: string;
	title: string;
	status: 'queued' | 'running' | 'succeeded' | 'failed' | 'skipped';
	attempts: number;
	createdAt: string;
	updatedAt: string;
	error?: string;
	result?: Record< string, unknown >;
}

export interface PublishTemplate {
	id: string;
	label: string;
	description: string;
	defaultStatus: string;
}

export interface BackendSettings {
	baseUrl: string;
	apiKey: string;
}

export class ApiError extends Error {
	constructor( message: string, readonly status: number ) {
		super( message );
		this.name = 'ApiError';
	}
}

const STORAGE_KEY = 'forma-wp-publisher.settings';

export function loadSettings(): BackendSettings {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );

		if ( raw ) {
			const parsed = JSON.parse( raw ) as Partial< BackendSettings >;

			return {
				baseUrl: typeof parsed.baseUrl === 'string' ? parsed.baseUrl : '',
				apiKey: typeof parsed.apiKey === 'string' ? parsed.apiKey : '',
			};
		}
	} catch {
		// Ignore unreadable storage and fall through to defaults.
	}

	return { baseUrl: '', apiKey: '' };
}

export function saveSettings( settings: BackendSettings ): void {
	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( settings ) );
	} catch {
		// Storage may be unavailable in a restricted frame; settings then live
		// only for the current session.
	}
}

export class BackendClient {
	constructor( private settings: BackendSettings ) {}

	update( settings: BackendSettings ): void {
		this.settings = settings;
	}

	get configured(): boolean {
		return this.settings.baseUrl.trim() !== '' && this.settings.apiKey.trim() !== '';
	}

	health(): Promise< { status: string } > {
		return this.request( 'GET', '/health', undefined, false );
	}

	session(): Promise< { connected: boolean } > {
		return this.request( 'GET', '/auth/session', undefined, false );
	}

	wordpressStatus(): Promise< Record< string, unknown > > {
		return this.request( 'GET', '/api/wordpress/status' );
	}

	hubs(): Promise< { hubs: Array< { id: string; name: string } > } > {
		return this.request( 'GET', '/api/hubs' );
	}

	projects( hubId: string ): Promise< { projects: Array< { id: string; name: string } > } > {
		return this.request( 'GET', `/api/hubs/${ encodeURIComponent( hubId ) }/projects` );
	}

	templates(): Promise< { templates: PublishTemplate[] } > {
		return this.request( 'GET', '/api/templates' );
	}

	preview( source: {
		hubId: string;
		projectId: string;
		proposalId?: string;
		includeFiles: boolean;
		template?: string;
		overrides?: Record< string, unknown >;
	} ): Promise< { project: CanonicalProject } > {
		return this.request( 'POST', '/api/preview', source );
	}

	publish( payload: {
		operation: string;
		mode: string;
		force?: boolean;
		template?: string;
		project?: CanonicalProject;
		source?: Record< string, unknown >;
	} ): Promise< { job: PublishJob } > {
		return this.request( 'POST', '/api/publish', payload );
	}

	jobs(): Promise< { jobs: PublishJob[] } > {
		return this.request( 'GET', '/api/jobs' );
	}

	loginUrl(): string {
		return `${ this.base() }/auth/login`;
	}

	private base(): string {
		return this.settings.baseUrl.trim().replace( /\/+$/, '' );
	}

	private async request< T >(
		method: 'GET' | 'POST',
		path: string,
		body?: unknown,
		requiresKey = true
	): Promise< T > {
		if ( this.base() === '' ) {
			throw new ApiError( 'Set the backend URL on the Connection tab first.', 0 );
		}

		if ( requiresKey && this.settings.apiKey.trim() === '' ) {
			throw new ApiError( 'Set the backend API key on the Connection tab first.', 0 );
		}

		const headers: Record< string, string > = { accept: 'application/json' };

		if ( requiresKey ) {
			headers[ 'x-api-key' ] = this.settings.apiKey.trim();
		}

		if ( body !== undefined ) {
			headers[ 'content-type' ] = 'application/json';
		}

		let response: Response;

		try {
			response = await fetch( `${ this.base() }${ path }`, {
				method,
				headers,
				body: body === undefined ? undefined : JSON.stringify( body ),
			} );
		} catch ( error ) {
			throw new ApiError(
				`Could not reach the backend: ${ error instanceof Error ? error.message : String( error ) }`,
				0
			);
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
			const detail = parsed as { message?: string; error?: string } | null;

			throw new ApiError(
				detail?.message ?? detail?.error ?? `Backend returned HTTP ${ response.status }.`,
				response.status
			);
		}

		return parsed as T;
	}
}
