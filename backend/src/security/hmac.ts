import { createHash, createHmac, randomBytes, timingSafeEqual } from 'node:crypto';

/**
 * Signature headers exchanged between this service and the WordPress plugin.
 * These names must stay in sync with `Forma_Publisher\Signature`.
 */
export const HEADER = {
	key: 'x-forma-key',
	timestamp: 'x-forma-timestamp',
	nonce: 'x-forma-nonce',
	signature: 'x-forma-signature',
} as const;

/**
 * Builds the canonical string that both sides sign.
 *
 * The format is identical to the PHP implementation:
 *
 *     METHOD \n ROUTE \n TIMESTAMP \n NONCE \n sha256hex( RAW_BODY )
 *
 * `route` is the REST route without the `/wp-json` prefix, because that is what
 * `WP_REST_Request::get_route()` reports on the receiving side.
 */
export function canonicalString(
	method: string,
	route: string,
	timestamp: string,
	nonce: string,
	body: string
): string {
	const bodyHash = createHash( 'sha256' ).update( body, 'utf8' ).digest( 'hex' );

	return [ method.toUpperCase(), route, timestamp, nonce, bodyHash ].join( '\n' );
}

export interface SignedHeaders {
	[ key: string ]: string;
}

/** Signs a request and returns the headers to send with it. */
export function signRequest( options: {
	method: string;
	route: string;
	body: string;
	keyId: string;
	secret: string;
	timestamp?: number;
	nonce?: string;
} ): SignedHeaders {
	const timestamp = String( options.timestamp ?? Math.floor( Date.now() / 1000 ) );
	const nonce = options.nonce ?? randomBytes( 18 ).toString( 'hex' );
	const canonical = canonicalString( options.method, options.route, timestamp, nonce, options.body );
	const digest = createHmac( 'sha256', options.secret ).update( canonical, 'utf8' ).digest( 'hex' );

	return {
		[ HEADER.key ]: options.keyId,
		[ HEADER.timestamp ]: timestamp,
		[ HEADER.nonce ]: nonce,
		[ HEADER.signature ]: `sha256=${ digest }`,
	};
}

/** Compares two signature strings without leaking timing information. */
export function safeEqual( a: string, b: string ): boolean {
	const left = Buffer.from( a, 'utf8' );
	const right = Buffer.from( b, 'utf8' );

	if ( left.length !== right.length ) {
		// Still perform a comparison so that the branch cost stays similar.
		timingSafeEqual( left, left );

		return false;
	}

	return timingSafeEqual( left, right );
}

const seenNonces = new Map<string, number>();

/**
 * Verifies an inbound signature, for example on the refresh endpoint that
 * WordPress calls. Returns an error message, or null when the request is valid.
 */
export function verifyInbound( options: {
	method: string;
	route: string;
	body: string;
	secret: string;
	headers: Record<string, string | string[] | undefined>;
	toleranceSeconds?: number;
} ): string | null {
	const header = ( name: string ): string => {
		const value = options.headers[ name ];

		if ( Array.isArray( value ) ) {
			return value[ 0 ] ?? '';
		}

		return typeof value === 'string' ? value : '';
	};

	const timestamp = header( HEADER.timestamp );
	const nonce = header( HEADER.nonce );
	const provided = header( HEADER.signature ).replace( /^sha256=/i, '' ).toLowerCase();

	if ( ! timestamp || ! nonce || ! provided ) {
		return 'missing signature headers';
	}

	if ( ! /^-?\d{1,12}$/.test( timestamp ) ) {
		return 'invalid timestamp';
	}

	const tolerance = options.toleranceSeconds ?? 300;
	const skew = Math.abs( Math.floor( Date.now() / 1000 ) - Number( timestamp ) );

	if ( skew > tolerance ) {
		return 'stale request';
	}

	const canonical = canonicalString( options.method, options.route, timestamp, nonce, options.body );
	const expected = createHmac( 'sha256', options.secret ).update( canonical, 'utf8' ).digest( 'hex' );

	if ( ! safeEqual( expected, provided ) ) {
		return 'invalid signature';
	}

	const now = Date.now();

	for ( const [ key, expiry ] of seenNonces ) {
		if ( expiry < now ) {
			seenNonces.delete( key );
		}
	}

	if ( seenNonces.has( nonce ) ) {
		return 'replayed request';
	}

	seenNonces.set( nonce, now + tolerance * 2 * 1000 );

	return null;
}
