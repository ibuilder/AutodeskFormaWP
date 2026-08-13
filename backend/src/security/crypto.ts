import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';

/**
 * Authenticated encryption for Autodesk tokens at rest.
 *
 * Format: base64( iv[12] || authTag[16] || ciphertext ).
 */
const IV_LENGTH = 12;
const TAG_LENGTH = 16;

function toKey( raw: string ): Buffer {
	const hex = raw.trim();

	if ( /^[0-9a-fA-F]{64}$/.test( hex ) ) {
		return Buffer.from( hex, 'hex' );
	}

	const decoded = Buffer.from( hex, 'base64' );

	if ( decoded.length === 32 ) {
		return decoded;
	}

	throw new Error( 'ENCRYPTION_KEY must be 32 bytes, hex or base64 encoded.' );
}

export function encrypt( plaintext: string, key: string ): string {
	const iv = randomBytes( IV_LENGTH );
	const cipher = createCipheriv( 'aes-256-gcm', toKey( key ), iv );
	const encrypted = Buffer.concat( [ cipher.update( plaintext, 'utf8' ), cipher.final() ] );

	return Buffer.concat( [ iv, cipher.getAuthTag(), encrypted ] ).toString( 'base64' );
}

export function decrypt( payload: string, key: string ): string {
	const raw = Buffer.from( payload, 'base64' );

	if ( raw.length <= IV_LENGTH + TAG_LENGTH ) {
		throw new Error( 'Encrypted payload is truncated.' );
	}

	const iv = raw.subarray( 0, IV_LENGTH );
	const tag = raw.subarray( IV_LENGTH, IV_LENGTH + TAG_LENGTH );
	const body = raw.subarray( IV_LENGTH + TAG_LENGTH );

	const decipher = createDecipheriv( 'aes-256-gcm', toKey( key ), iv );
	decipher.setAuthTag( tag );

	return Buffer.concat( [ decipher.update( body ), decipher.final() ] ).toString( 'utf8' );
}
