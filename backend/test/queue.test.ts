import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { after, test } from 'node:test';

// The configuration is validated at import time, so the environment must be in
// place before any module under test is loaded.
const dataDir = mkdtempSync( join( tmpdir(), 'forma-queue-' ) );

process.env.NODE_ENV = 'test';
process.env.DATA_DIR = dataDir;
process.env.ENCRYPTION_KEY = 'a'.repeat( 64 );
process.env.APS_CLIENT_ID = 'test-client';
process.env.APS_CLIENT_SECRET = 'test-secret';
process.env.APS_CALLBACK_URL = 'http://localhost:3000/auth/callback';
process.env.WORDPRESS_URL = 'https://example.com';
process.env.WORDPRESS_KEY_ID = 'fp_test';
process.env.WORDPRESS_SECRET = 'a-shared-secret-long-enough';
process.env.EXTENSION_API_KEY = 'an-extension-key-long-enough';
process.env.JOB_MAX_ATTEMPTS = '3';
process.env.JOB_BASE_DELAY_MS = '100';

const { PublishQueue } = await import( '../dist/jobs/queue.js' );
const { WordPressError } = await import( '../dist/wordpress/client.js' );

after( () => {
	rmSync( dataDir, { recursive: true, force: true } );
} );

function project( sourceId: string, title = 'Test project' ) {
	return {
		source_id: sourceId,
		source_system: 'autodesk-forma',
		title,
		metrics: [],
		assets: [],
	};
}

/** A stand-in for the WordPress client that records calls and can be scripted. */
type Behaviour = 'ok' | 'retry-then-ok' | 'fatal' | 'always-fail';

class FakeClient {
	calls: unknown[] = [];
	behaviour: Behaviour;
	private failures = 0;

	// Written as an explicit assignment rather than a constructor parameter
	// property, which Node's strip-only TypeScript support cannot transform.
	constructor( behaviour: Behaviour = 'ok' ) {
		this.behaviour = behaviour;
	}

	async ingest( payload: unknown ) {
		this.calls.push( payload );

		if ( this.behaviour === 'fatal' ) {
			throw new WordPressError( 'Bad request', 400, 'bad_request', false );
		}

		if ( this.behaviour === 'always-fail' ) {
			throw new WordPressError( 'Server error', 500, 'server_error', true );
		}

		if ( this.behaviour === 'retry-then-ok' && this.failures < 1 ) {
			this.failures++;
			throw new WordPressError( 'Temporarily unavailable', 503, 'unavailable', true );
		}

		return { success: true, result: { status: 'created', post_id: 42, permalink: 'https://example.com/p' } };
	}
}

test( 'a successful publish is recorded', async () => {
	const client = new FakeClient( 'ok' );
	const queue = new PublishQueue( client as never );

	const job = await queue.enqueue( { operation: 'publish', project: project( 'urn:q:1' ) } );
	await queue.settle();

	const finished = await queue.get( job.id );
	assert.equal( finished?.status, 'succeeded' );
	assert.equal( client.calls.length, 1 );

	const published = await queue.published();
	assert.equal( published[ 'urn:q:1' ]?.postId, 42 );
} );

test( 'an unchanged republish is skipped without contacting WordPress', async () => {
	const client = new FakeClient( 'ok' );
	const queue = new PublishQueue( client as never );

	await queue.enqueue( { operation: 'publish', project: project( 'urn:q:2' ) } );
	await queue.settle();
	assert.equal( client.calls.length, 1 );

	const second = await queue.enqueue( { operation: 'publish', project: project( 'urn:q:2' ) } );
	await queue.settle();

	assert.equal( second.status, 'skipped' );
	assert.equal( client.calls.length, 1, 'no second request should be sent' );
} );

test( 'force overrides the unchanged short circuit', async () => {
	const client = new FakeClient( 'ok' );
	const queue = new PublishQueue( client as never );

	await queue.enqueue( { operation: 'publish', project: project( 'urn:q:3' ) } );
	await queue.settle();

	const forced = await queue.enqueue( { operation: 'publish', project: project( 'urn:q:3' ), force: true } );
	await queue.settle();

	assert.notEqual( forced.status, 'skipped' );
	assert.equal( client.calls.length, 2 );
} );

test( 'changed content is published rather than skipped', async () => {
	const client = new FakeClient( 'ok' );
	const queue = new PublishQueue( client as never );

	await queue.enqueue( { operation: 'publish', project: project( 'urn:q:4', 'First' ) } );
	await queue.settle();

	const changed = await queue.enqueue( { operation: 'update', project: project( 'urn:q:4', 'Second' ) } );
	await queue.settle();

	assert.notEqual( changed.status, 'skipped' );
	assert.equal( ( await queue.get( changed.id ) )?.status, 'succeeded' );
} );

test( 'a retryable failure is retried and can succeed', async () => {
	const client = new FakeClient( 'retry-then-ok' );
	const queue = new PublishQueue( client as never );

	const job = await queue.enqueue( { operation: 'publish', project: project( 'urn:q:5' ) } );
	await queue.settle();

	const finished = await queue.get( job.id );
	assert.equal( finished?.status, 'succeeded' );
	assert.equal( finished?.attempts, 2 );
	assert.equal( client.calls.length, 2 );
} );

test( 'a non retryable failure fails immediately without retrying', async () => {
	const client = new FakeClient( 'fatal' );
	const queue = new PublishQueue( client as never );

	const job = await queue.enqueue( { operation: 'publish', project: project( 'urn:q:6' ) } );
	await queue.settle();

	const finished = await queue.get( job.id );
	assert.equal( finished?.status, 'failed' );
	assert.equal( finished?.attempts, 1, 'a 4xx must not be retried' );
	assert.equal( client.calls.length, 1 );
} );

test( 'a persistently failing job stops at the attempt ceiling', async () => {
	const client = new FakeClient( 'always-fail' );
	const queue = new PublishQueue( client as never );

	const job = await queue.enqueue( { operation: 'publish', project: project( 'urn:q:7' ) } );
	await queue.settle();

	const finished = await queue.get( job.id );
	assert.equal( finished?.status, 'failed' );
	assert.equal( finished?.attempts, 3 );
	assert.equal( client.calls.length, 3 );
} );

test( 'a failed publish is not recorded as published', async () => {
	const client = new FakeClient( 'fatal' );
	const queue = new PublishQueue( client as never );

	await queue.enqueue( { operation: 'publish', project: project( 'urn:q:8' ) } );
	await queue.settle();

	const published = await queue.published();
	assert.equal( published[ 'urn:q:8' ], undefined );
} );

test( 'an unpublish removes the published entry', async () => {
	const client = new FakeClient( 'ok' );
	const queue = new PublishQueue( client as never );

	await queue.enqueue( { operation: 'publish', project: project( 'urn:q:9' ) } );
	await queue.settle();
	assert.ok( ( await queue.published() )[ 'urn:q:9' ] );

	await queue.enqueue( { operation: 'unpublish', project: project( 'urn:q:9' ) } );
	await queue.settle();

	assert.equal( ( await queue.published() )[ 'urn:q:9' ], undefined );
} );

test( 'the source descriptor is retained for sync refresh', async () => {
	const client = new FakeClient( 'ok' );
	const queue = new PublishQueue( client as never );

	await queue.enqueue( {
		operation: 'publish',
		project: project( 'urn:q:10' ),
		mode: 'sync',
		source: { hubId: 'b.hub', projectId: 'b.proj', includeFiles: true },
	} );
	await queue.settle();

	const entry = ( await queue.published() )[ 'urn:q:10' ];
	assert.equal( entry?.mode, 'sync' );
	assert.equal( entry?.source?.hubId, 'b.hub' );
	assert.equal( entry?.source?.projectId, 'b.proj' );
} );

test( 'a later publish without a descriptor keeps the recorded one', async () => {
	const client = new FakeClient( 'ok' );
	const queue = new PublishQueue( client as never );

	await queue.enqueue( {
		operation: 'publish',
		project: project( 'urn:q:11', 'One' ),
		mode: 'sync',
		source: { hubId: 'b.hub', projectId: 'b.proj' },
	} );
	await queue.settle();

	await queue.enqueue( { operation: 'update', project: project( 'urn:q:11', 'Two' ), mode: 'sync' } );
	await queue.settle();

	assert.equal( ( await queue.published() )[ 'urn:q:11' ]?.source?.hubId, 'b.hub' );
} );

test( 'job history is listed newest first', async () => {
	const client = new FakeClient( 'ok' );
	const queue = new PublishQueue( client as never );

	await queue.enqueue( { operation: 'publish', project: project( 'urn:q:12', 'Older' ) } );
	await queue.settle();
	await queue.enqueue( { operation: 'publish', project: project( 'urn:q:13', 'Newer' ) } );
	await queue.settle();

	const jobs = await queue.list( 5 );
	assert.equal( jobs[ 0 ]?.sourceId, 'urn:q:13' );
} );
