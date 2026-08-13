import 'dotenv/config';
import { z } from 'zod';

/**
 * Runtime configuration, validated once at startup so that a misconfigured
 * deployment fails immediately instead of at the first publish attempt.
 */
const schema = z.object( {
	NODE_ENV: z.enum( [ 'development', 'test', 'production' ] ).default( 'development' ),
	PORT: z.coerce.number().int().min( 1 ).max( 65535 ).default( 3000 ),
	LOG_LEVEL: z.enum( [ 'debug', 'info', 'warn', 'error' ] ).default( 'info' ),

	/** Absolute or relative path used for the JSON backed data store. */
	DATA_DIR: z.string().min( 1 ).default( './data' ),

	/**
	 * 32 byte key, hex or base64 encoded, used to encrypt Autodesk tokens at
	 * rest. Generate with: openssl rand -hex 32
	 */
	ENCRYPTION_KEY: z.string().min( 32 ),

	/** Autodesk Platform Services application credentials. */
	APS_CLIENT_ID: z.string().min( 1 ),
	APS_CLIENT_SECRET: z.string().min( 1 ),
	APS_CALLBACK_URL: z.string().url(),
	APS_BASE_URL: z.string().url().default( 'https://developer.api.autodesk.com' ),
	APS_SCOPES: z.string().default( 'data:read account:read' ),

	/** WordPress receiver configuration. */
	WORDPRESS_URL: z.string().url(),
	WORDPRESS_REST_PREFIX: z.string().default( '/wp-json' ),
	WORDPRESS_KEY_ID: z.string().min( 1 ),
	WORDPRESS_SECRET: z.string().min( 16 ),

	/** Shared secret the Forma extension presents to this backend. */
	EXTENSION_API_KEY: z.string().min( 16 ),

	/** Publish job retry behaviour. */
	JOB_MAX_ATTEMPTS: z.coerce.number().int().min( 1 ).max( 20 ).default( 5 ),
	JOB_BASE_DELAY_MS: z.coerce.number().int().min( 100 ).max( 60_000 ).default( 1_000 ),
	HTTP_TIMEOUT_MS: z.coerce.number().int().min( 1_000 ).max( 120_000 ).default( 30_000 ),
} );

export type Config = z.infer<typeof schema>;

let cached: Config | null = null;

/**
 * Returns the validated configuration, throwing a readable error when the
 * environment is incomplete.
 */
export function getConfig(): Config {
	if ( cached ) {
		return cached;
	}

	const parsed = schema.safeParse( process.env );

	if ( ! parsed.success ) {
		const details = parsed.error.issues
			.map( ( issue ) => `  ${ issue.path.join( '.' ) }: ${ issue.message }` )
			.join( '\n' );

		throw new Error( `Invalid environment configuration:\n${ details }` );
	}

	cached = parsed.data;

	return cached;
}

/** Resets the memoized configuration. Used by tests. */
export function resetConfig(): void {
	cached = null;
}
