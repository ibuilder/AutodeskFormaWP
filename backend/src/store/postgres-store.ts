import pg from 'pg';
import type { DocumentStore } from './document-store.js';

/**
 * PostgreSQL backed document store.
 *
 * Unlike the JSON file store this is safe across processes: every mutation runs
 * inside a transaction that takes a row lock, so two instances of the service
 * cannot lose each other's updates. Reads are never cached in memory for the
 * same reason — a cached read would defeat the point of sharing a database.
 */
export class PostgresStore<T extends object> implements DocumentStore<T> {
	private static pools = new Map<string, pg.Pool>();
	private static schemaReady = new Map<pg.Pool, Promise<void>>();

	private readonly pool: pg.Pool;
	private readonly name: string;
	private readonly fallback: T;

	constructor( connectionString: string, name: string, fallback: T ) {
		this.pool = PostgresStore.pool( connectionString );
		this.name = name;
		this.fallback = fallback;
	}

	/** Reuses one pool per connection string across every store instance. */
	private static pool( connectionString: string ): pg.Pool {
		const existing = PostgresStore.pools.get( connectionString );

		if ( existing ) {
			return existing;
		}

		const created = new pg.Pool( {
			connectionString,
			max: 10,
			idleTimeoutMillis: 30_000,
			// Managed providers commonly require TLS without a locally trusted CA.
			...( /\bsslmode=require\b/.test( connectionString )
				? { ssl: { rejectUnauthorized: false } }
				: {} ),
		} );

		PostgresStore.pools.set( connectionString, created );

		return created;
	}

	private async ensureSchema(): Promise<void> {
		const cached = PostgresStore.schemaReady.get( this.pool );

		if ( cached ) {
			await cached;

			return;
		}

		const ready: Promise<void> = this.pool
			.query(
					`CREATE TABLE IF NOT EXISTS forma_documents (
						name       TEXT PRIMARY KEY,
						data       JSONB NOT NULL,
						updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
					)`
			)
			.then( () => undefined );

		PostgresStore.schemaReady.set( this.pool, ready );

		await ready;
	}

	async read(): Promise<T> {
		await this.ensureSchema();

		const result = await this.pool.query<{ data: T }>(
			'SELECT data FROM forma_documents WHERE name = $1',
			[ this.name ]
		);

		return result.rows[ 0 ]?.data ?? structuredClone( this.fallback );
	}

	async mutate<R>( mutator: ( state: T ) => R | Promise<R> ): Promise<R> {
		await this.ensureSchema();

		const client = await this.pool.connect();

		try {
			await client.query( 'BEGIN' );

			// Guarantee the row exists so it can be locked.
			await client.query(
				'INSERT INTO forma_documents (name, data) VALUES ($1, $2) ON CONFLICT (name) DO NOTHING',
				[ this.name, JSON.stringify( this.fallback ) ]
			);

			const locked = await client.query<{ data: T }>(
				'SELECT data FROM forma_documents WHERE name = $1 FOR UPDATE',
				[ this.name ]
			);

			const state = locked.rows[ 0 ]?.data ?? structuredClone( this.fallback );
			const result = await mutator( state );

			await client.query(
				'UPDATE forma_documents SET data = $2, updated_at = now() WHERE name = $1',
				[ this.name, JSON.stringify( state ) ]
			);

			await client.query( 'COMMIT' );

			return result;
		} catch ( error ) {
			await client.query( 'ROLLBACK' ).catch( () => undefined );

			throw error;
		} finally {
			client.release();
		}
	}

	async close(): Promise<void> {
		// Pools are shared, so closing is handled once at shutdown.
		await PostgresStore.closeAll();
	}

	/** Closes every pool. Called on service shutdown and by tests. */
	static async closeAll(): Promise<void> {
		const pools = Array.from( PostgresStore.pools.values() );

		PostgresStore.pools.clear();
		PostgresStore.schemaReady.clear();

		await Promise.all( pools.map( ( pool ) => pool.end().catch( () => undefined ) ) );
	}
}
