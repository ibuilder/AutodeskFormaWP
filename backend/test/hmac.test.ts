import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { test } from 'node:test';
import { HEADER, canonicalString, signRequest, verifyInbound } from '../dist/security/hmac.js';

const SECRET = 'a-shared-secret-that-is-long-enough';

test( 'canonical string matches the documented format', () => {
	const body = '{"a":1}';
	const canonical = canonicalString( 'post', '/forma-publisher/v1/ingest', '1700000000', 'abc', body );

	assert.equal(
		canonical,
		[
			'POST',
			'/forma-publisher/v1/ingest',
			'1700000000',
			'abc',
			createHash( 'sha256' ).update( body ).digest( 'hex' ),
		].join( '\n' )
	);
} );

test( 'a signed request verifies', () => {
	const body = JSON.stringify( { hello: 'world' } );
	const headers = signRequest( {
		method: 'POST',
		route: '/api/refresh',
		body,
		keyId: 'fp_test',
		secret: SECRET,
	} );

	assert.match( headers[ HEADER.signature ] as string, /^sha256=[0-9a-f]{64}$/ );

	const failure = verifyInbound( {
		method: 'POST',
		route: '/api/refresh',
		body,
		secret: SECRET,
		headers,
	} );

	assert.equal( failure, null );
} );

test( 'a tampered body is rejected', () => {
	const body = JSON.stringify( { amount: 1 } );
	const headers = signRequest( {
		method: 'POST',
		route: '/api/refresh',
		body,
		keyId: 'fp_test',
		secret: SECRET,
	} );

	const failure = verifyInbound( {
		method: 'POST',
		route: '/api/refresh',
		body: JSON.stringify( { amount: 1000 } ),
		secret: SECRET,
		headers,
	} );

	assert.equal( failure, 'invalid signature' );
} );

test( 'a different route is rejected', () => {
	const body = '{}';
	const headers = signRequest( {
		method: 'POST',
		route: '/api/refresh',
		body,
		keyId: 'fp_test',
		secret: SECRET,
	} );

	assert.equal(
		verifyInbound( { method: 'POST', route: '/api/other', body, secret: SECRET, headers } ),
		'invalid signature'
	);
} );

test( 'a wrong secret is rejected', () => {
	const body = '{}';
	const headers = signRequest( {
		method: 'POST',
		route: '/api/refresh',
		body,
		keyId: 'fp_test',
		secret: SECRET,
	} );

	assert.equal(
		verifyInbound( { method: 'POST', route: '/api/refresh', body, secret: 'other-secret', headers } ),
		'invalid signature'
	);
} );

test( 'a stale timestamp is rejected', () => {
	const body = '{}';
	const headers = signRequest( {
		method: 'POST',
		route: '/api/refresh',
		body,
		keyId: 'fp_test',
		secret: SECRET,
		timestamp: Math.floor( Date.now() / 1000 ) - 5_000,
	} );

	assert.equal(
		verifyInbound( { method: 'POST', route: '/api/refresh', body, secret: SECRET, headers } ),
		'stale request'
	);
} );

test( 'a replayed nonce is rejected', () => {
	const body = '{}';
	const headers = signRequest( {
		method: 'POST',
		route: '/api/refresh',
		body,
		keyId: 'fp_test',
		secret: SECRET,
		nonce: 'fixed-nonce-for-replay-test',
	} );

	assert.equal( verifyInbound( { method: 'POST', route: '/api/refresh', body, secret: SECRET, headers } ), null );
	assert.equal(
		verifyInbound( { method: 'POST', route: '/api/refresh', body, secret: SECRET, headers } ),
		'replayed request'
	);
} );

test( 'missing headers are rejected', () => {
	assert.equal(
		verifyInbound( { method: 'POST', route: '/api/refresh', body: '{}', secret: SECRET, headers: {} } ),
		'missing signature headers'
	);
} );
