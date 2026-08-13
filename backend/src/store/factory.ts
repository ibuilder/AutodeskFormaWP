import { getConfig } from '../config.js';
import { logger } from '../logger.js';
import type { DocumentStore } from './document-store.js';
import { JsonStore } from './json-store.js';
import { PostgresStore } from './postgres-store.js';

let announced = false;

/**
 * Builds the document store for a named document.
 *
 * PostgreSQL is used when `DATABASE_URL` is set, which is what a multi-instance
 * deployment needs. Otherwise the JSON file store is used, which is fine for a
 * single process but cannot coordinate between them.
 */
export function createStore<T extends object>( name: string, fallback: T ): DocumentStore<T> {
	const config = getConfig();

	if ( config.DATABASE_URL ) {
		if ( ! announced ) {
			announced = true;
			logger.info( 'Using the PostgreSQL document store' );
		}

		return new PostgresStore<T>( config.DATABASE_URL, name, fallback );
	}

	if ( ! announced ) {
		announced = true;
		logger.info( 'Using the JSON file document store', { dataDir: config.DATA_DIR } );
	}

	return new JsonStore<T>( config.DATA_DIR, name, fallback );
}

/** Closes any pooled resources held by the storage layer. */
export async function closeStores(): Promise<void> {
	await PostgresStore.closeAll();
}
