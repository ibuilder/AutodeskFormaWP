import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { test } from 'node:test';
import { decrypt, encrypt } from '../dist/security/crypto.js';

const KEY = randomBytes( 32 ).toString( 'hex' );

test( 'round trips a token', () => {
	const secret = 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.payload.signature';

	assert.equal( decrypt( encrypt( secret, KEY ), KEY ), secret );
} );

test( 'produces a different ciphertext each time', () => {
	assert.notEqual( encrypt( 'same', KEY ), encrypt( 'same', KEY ) );
} );

test( 'rejects a tampered ciphertext', () => {
	const encrypted = Buffer.from( encrypt( 'sensitive', KEY ), 'base64' );

	encrypted[ encrypted.length - 1 ] ^= 0xff;

	assert.throws( () => decrypt( encrypted.toString( 'base64' ), KEY ) );
} );

test( 'rejects the wrong key', () => {
	const encrypted = encrypt( 'sensitive', KEY );

	assert.throws( () => decrypt( encrypted, randomBytes( 32 ).toString( 'hex' ) ) );
} );

test( 'rejects a malformed key', () => {
	assert.throws( () => encrypt( 'x', 'too-short' ), /32 bytes/ );
} );

test( 'accepts a base64 encoded key', () => {
	const base64Key = randomBytes( 32 ).toString( 'base64' );

	assert.equal( decrypt( encrypt( 'value', base64Key ), base64Key ), 'value' );
} );
