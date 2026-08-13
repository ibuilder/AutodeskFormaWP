type Level = 'debug' | 'info' | 'warn' | 'error';

const ORDER: Record<Level, number> = { debug: 10, info: 20, warn: 30, error: 40 };

/** Keys whose values must never reach the log output. */
const REDACTED = [
	'secret',
	'password',
	'token',
	'access_token',
	'refresh_token',
	'authorization',
	'client_secret',
	'signature',
	'api_key',
	'apikey',
];

function redact( value: unknown ): unknown {
	if ( Array.isArray( value ) ) {
		return value.map( redact );
	}

	if ( value && typeof value === 'object' ) {
		const out: Record<string, unknown> = {};

		for ( const [ key, item ] of Object.entries( value as Record<string, unknown> ) ) {
			out[ key ] = REDACTED.includes( key.toLowerCase() ) ? '[redacted]' : redact( item );
		}

		return out;
	}

	return value;
}

function emit( level: Level, message: string, context?: Record<string, unknown> ): void {
	const configured = ( process.env.LOG_LEVEL as Level ) ?? 'info';

	if ( ORDER[ level ] < ( ORDER[ configured ] ?? ORDER.info ) ) {
		return;
	}

	const line = JSON.stringify( {
		time: new Date().toISOString(),
		level,
		message,
		...( context ? ( redact( context ) as Record<string, unknown> ) : {} ),
	} );

	if ( level === 'error' || level === 'warn' ) {
		process.stderr.write( `${ line }\n` );
	} else {
		process.stdout.write( `${ line }\n` );
	}
}

export const logger = {
	debug: ( message: string, context?: Record<string, unknown> ) => emit( 'debug', message, context ),
	info: ( message: string, context?: Record<string, unknown> ) => emit( 'info', message, context ),
	warn: ( message: string, context?: Record<string, unknown> ) => emit( 'warn', message, context ),
	error: ( message: string, context?: Record<string, unknown> ) => emit( 'error', message, context ),
};
