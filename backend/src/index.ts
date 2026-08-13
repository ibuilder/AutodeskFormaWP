import { getConfig } from './config.js';
import { logger } from './logger.js';
import { createServer } from './server.js';
import { closeStores } from './store/factory.js';

function main(): void {
	let config;

	try {
		config = getConfig();
	} catch ( error ) {
		process.stderr.write( `${ error instanceof Error ? error.message : String( error ) }\n` );
		process.exit( 1 );

		return;
	}

	const server = createServer().listen( config.PORT, () => {
		logger.info( 'Forma to WordPress backend listening', {
			port: config.PORT,
			env: config.NODE_ENV,
		} );
	} );

	const shutdown = ( signal: string ): void => {
		logger.info( 'Shutting down', { signal } );

		server.close( () => {
			void closeStores().finally( () => process.exit( 0 ) );
		} );

		// Do not let a hung connection block the shutdown indefinitely.
		setTimeout( () => process.exit( 0 ), 10_000 ).unref();
	};

	process.on( 'SIGTERM', () => shutdown( 'SIGTERM' ) );
	process.on( 'SIGINT', () => shutdown( 'SIGINT' ) );
}

main();
