import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, describe, test } from 'node:test';

const dataDir = mkdtempSync( join( tmpdir(), 'forma-store-' ) );

const { JsonStore } = await import( '../dist/store/json-store.js' );
const { PostgresStore } = await import( '../dist/store/postgres-store.js' );

interface Counter {
	value: number;
	items: string[];
}

const fallback: Counter = { value: 0, items: [] };

let suffix = 0;

function uniqueName( prefix: string ): string {
	suffix += 1;

	return `${ prefix }-${ process.pid }-${ suffix }`;
}

/**
 * The contract every document store must satisfy. Running it against both
 * implementations is what makes them substitutable.
 */
function contract( label: string, make: ( name: string ) => { read(): Promise<Counter>; mutate<R>( m: ( s: Counter ) => R ): Promise<R> } ) {
	describe( label, () => {
		test( 'returns the fallback for an unknown document', async () => {
			const store = make( uniqueName( 'absent' ) );

			assert.deepEqual( await store.read(), fallback );
		} );

		test( 'the fallback is not shared between reads', async () => {
			const store = make( uniqueName( 'isolation' ) );
			const first = await store.read();

			first.items.push( 'mutated' );

			const second = await store.read();
			assert.deepEqual( second.items, [], 'reading must not hand out the same object' );
		} );

		test( 'persists a mutation', async () => {
			const store = make( uniqueName( 'persist' ) );

			await store.mutate( ( state ) => {
				state.value = 42;
				state.items.push( 'a' );
			} );

			const read = await store.read();
			assert.equal( read.value, 42 );
			assert.deepEqual( read.items, [ 'a' ] );
		} );

		test( 'returns the mutator result', async () => {
			const store = make( uniqueName( 'result' ) );

			const returned = await store.mutate( ( state ) => {
				state.value = 7;

				return 'returned-value';
			} );

			assert.equal( returned, 'returned-value' );
		} );

		test( 'concurrent mutations do not lose updates', async () => {
			const store = make( uniqueName( 'concurrent' ) );
			const iterations = 50;

			await Promise.all(
				Array.from( { length: iterations }, ( _unused, index ) =>
					store.mutate( ( state ) => {
						state.value += 1;
						state.items.push( `item-${ index }` );
					} )
				)
			);

			const read = await store.read();
			assert.equal( read.value, iterations, 'every increment must be visible' );
			assert.equal( read.items.length, iterations );
			assert.equal( new Set( read.items ).size, iterations, 'no item may be lost or duplicated' );
		} );

		test( 'a throwing mutation does not wedge the queue', async () => {
			const store = make( uniqueName( 'throwing' ) );

			await assert.rejects(
				store.mutate( () => {
					throw new Error( 'deliberate failure' );
				} )
			);

			await store.mutate( ( state ) => {
				state.value = 99;
			} );

			assert.equal( ( await store.read() ).value, 99 );
		} );

		test( 'documents are independent of one another', async () => {
			const a = make( uniqueName( 'doc-a' ) );
			const b = make( uniqueName( 'doc-b' ) );

			await a.mutate( ( state ) => {
				state.value = 1;
			} );
			await b.mutate( ( state ) => {
				state.value = 2;
			} );

			assert.equal( ( await a.read() ).value, 1 );
			assert.equal( ( await b.read() ).value, 2 );
		} );
	} );
}

contract( 'JsonStore', ( name ) => new JsonStore<Counter>( dataDir, name, fallback ) as never );

const databaseUrl = process.env.DATABASE_URL;

if ( databaseUrl ) {
	contract( 'PostgresStore', ( name ) => new PostgresStore<Counter>( databaseUrl, name, fallback ) as never );

	describe( 'PostgresStore multi-instance behaviour', () => {
		test( 'a second instance sees the first instance writes', async () => {
			const name = uniqueName( 'shared' );
			const writer = new PostgresStore<Counter>( databaseUrl, name, fallback );
			const reader = new PostgresStore<Counter>( databaseUrl, name, fallback );

			await writer.mutate( ( state ) => {
				state.value = 123;
			} );

			// This is the property the JSON store cannot provide: a separate
			// instance, as a separate process would be, observes the write.
			assert.equal( ( await reader.read() ).value, 123 );
		} );

		test( 'concurrent mutations across instances do not lose updates', async () => {
			const name = uniqueName( 'shared-concurrent' );
			const instances = Array.from(
				{ length: 4 },
				() => new PostgresStore<Counter>( databaseUrl, name, fallback )
			);

			await Promise.all(
				instances.flatMap( ( store ) =>
					Array.from( { length: 10 }, () =>
						store.mutate( ( state ) => {
							state.value += 1;
						} )
					)
				)
			);

			assert.equal( ( await instances[ 0 ]!.read() ).value, 40 );
		} );
	} );
} else {
	test( 'PostgresStore suite skipped (DATABASE_URL not set)', ( t ) => {
		t.skip( 'Set DATABASE_URL to run the PostgreSQL contract tests.' );
	} );
}

after( async () => {
	await PostgresStore.closeAll();
	rmSync( dataDir, { recursive: true, force: true } );
} );
