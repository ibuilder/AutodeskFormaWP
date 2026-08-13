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

		const ready = this.createSchema();

		PostgresStore.schemaReady.set( this.pool, ready );

		try {
			await ready;
		} catch ( error ) {
			// Allow a later call to retry rather than caching the failure.
			PostgresStore.schemaReady.delete( this.pool );

			throw error;
		}
	}

	/**
	 * Creates the table, tolerating a concurrent creation by another instance.
	 *
	 * `CREATE TABLE IF NOT EXISTS` is not safe to run concurrently: two
	 * connections racing to create the same table can still collide, raising a
	 * unique violation on the system catalogue rather than quietly doing
	 * nothing. Two service instances starting at the same moment against a fresh
	 * database is exactly that race, so an advisory lock serializes the DDL and
	 * the duplicate-object codes are treated as success.
	 */
	private async createSchema(): Promise<void> {
		// Arbitrary constant, shared by every instance of this application.
		const lockId = 8_675_309;
		const client = await this.pool.connect();

		try {
			await client.query( 'SELECT pg_advisory_lock($1)', [ lockId ] );

			try {
				await client.query(
					`CREATE TABLE IF NOT EXISTS forma_documents (
						name       TEXT PRIMARY KEY,
						data       JSONB NOT NULL,
						updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
					)`
				);
			} finally {
				await client.query( 'SELECT pg_advisory_unlock($1)', [ lockId ] ).catch( () => undefined );
			}
		} catch ( error ) {
			const code = ( error as { code?: string } ).code;

			// 23505 unique_violation on pg_type, 42P07 duplicate_table.
			if ( code !== '23505' && code !== '42P07' ) {
				throw error;
			}
		} finally {
			client.release();
		}
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
