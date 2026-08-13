import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import type { DocumentStore } from './document-store.js';

/**
 * A small, dependency free persistence layer.
 *
 * Writes go to a temporary file and are renamed into place so a crash mid write
 * cannot leave a partial document behind. Mutations are serialized through a
 * promise chain so concurrent callers cannot interleave read-modify-write
 * cycles. Swap this class for a real database in a production deployment; the
 * repository classes only depend on the methods below.
 */
export class JsonStore<T extends object> implements DocumentStore<T> {
	private readonly file: string;
	private readonly fallback: T;
	private cache: T | null = null;
	private queue: Promise<unknown> = Promise.resolve();

	constructor( dataDir: string, name: string, fallback: T ) {
		this.file = join( resolve( dataDir ), `${ name }.json` );
		this.fallback = fallback;
	}

	/**
	 * Returns a copy of the stored document.
	 *
	 * The copy matters: handing out the internal cache would let a caller mutate
	 * it in place, and that mutation would then be written to disk by the next
	 * unrelated `mutate()` call. It also keeps this store's observable behaviour
	 * identical to the PostgreSQL one, which necessarily returns fresh objects.
	 */
	async read(): Promise<T> {
		return structuredClone( await this.load() );
	}

	/** Loads the document into the cache and returns the live object. */
	private async load(): Promise<T> {
		if ( this.cache ) {
			return this.cache;
		}

		try {
			const raw = await readFile( this.file, 'utf8' );
			this.cache = JSON.parse( raw ) as T;
		} catch ( error ) {
			const code = ( error as NodeJS.ErrnoException ).code;

			if ( code !== 'ENOENT' ) {
				throw error;
			}

			this.cache = structuredClone( this.fallback );
		}

		return this.cache;
	}

	/**
	 * Applies a mutation to the stored document and persists the result.
	 * Mutations are queued, so two concurrent calls never lose an update.
	 */
	async mutate<R>( mutator: ( state: T ) => R | Promise<R> ): Promise<R> {
		const run = this.queue.then( async () => {
			const state = await this.load();
			const result = await mutator( state );

			await this.persist( state );

			return result;
		} );

		// Keep the chain alive even when a mutation rejects.
		this.queue = run.then(
			() => undefined,
			() => undefined
		);

		return run;
	}

	private async persist( state: T ): Promise<void> {
		await mkdir( dirname( this.file ), { recursive: true } );

		const temp = `${ this.file }.${ process.pid }.tmp`;

		await writeFile( temp, JSON.stringify( state, null, 2 ), 'utf8' );
		await rename( temp, this.file );

		this.cache = state;
	}
}
