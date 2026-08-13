import { createHash, randomBytes } from 'node:crypto';
import { getConfig } from '../config.js';
import { logger } from '../logger.js';
import { decrypt, encrypt } from '../security/crypto.js';
import { createStore } from '../store/factory.js';
import type { DocumentStore } from '../store/document-store.js';

/**
 * Autodesk Platform Services OAuth.
 *
 * Uses the authorization code flow with PKCE for user context, and the client
 * credentials flow for service-to-service reads. Tokens are encrypted before
 * they touch disk and never leave this process in plaintext.
 */

interface StoredToken {
	accessToken: string;
	refreshToken?: string;
	expiresAt: number;
	scope: string;
}

interface TokenFile {
	users: Record<string, StoredToken>;
	client?: StoredToken;
}

interface PendingAuth {
	verifier: string;
	createdAt: number;
}

const pending = new Map<string, PendingAuth>();

export class ApsOAuth {
	private readonly store: DocumentStore<TokenFile>;

	constructor() {
		const config = getConfig();
		this.store = createStore<TokenFile>( 'aps-tokens', { users: {} } );
	}

	/** Builds the authorization URL and remembers the PKCE verifier. */
	beginAuthorization(): { url: string; state: string } {
		const config = getConfig();
		const state = randomBytes( 16 ).toString( 'hex' );
		const verifier = randomBytes( 32 ).toString( 'base64url' );
		const challenge = createHash( 'sha256' ).update( verifier ).digest( 'base64url' );

		pending.set( state, { verifier, createdAt: Date.now() } );
		this.prunePending();

		const url = new URL( '/authentication/v2/authorize', config.APS_BASE_URL );
		url.searchParams.set( 'response_type', 'code' );
		url.searchParams.set( 'client_id', config.APS_CLIENT_ID );
		url.searchParams.set( 'redirect_uri', config.APS_CALLBACK_URL );
		url.searchParams.set( 'scope', config.APS_SCOPES );
		url.searchParams.set( 'state', state );
		url.searchParams.set( 'code_challenge', challenge );
		url.searchParams.set( 'code_challenge_method', 'S256' );

		return { url: url.toString(), state };
	}

	/** Exchanges an authorization code for tokens and stores them. */
	async completeAuthorization( code: string, state: string, userKey = 'default' ): Promise<void> {
		const record = pending.get( state );

		if ( ! record ) {
			throw new Error( 'Unknown or expired authorization state.' );
		}

		pending.delete( state );

		const config = getConfig();
		const body = new URLSearchParams( {
			grant_type: 'authorization_code',
			code,
			redirect_uri: config.APS_CALLBACK_URL,
			code_verifier: record.verifier,
			client_id: config.APS_CLIENT_ID,
		} );

		const token = await this.requestToken( body, true );

		await this.store.mutate( ( state_ ) => {
			state_.users[ userKey ] = this.encryptToken( token );
		} );

		logger.info( 'Stored Autodesk user token', { userKey, scope: token.scope } );
	}

	/** Returns a valid user access token, refreshing it when needed. */
	async getUserAccessToken( userKey = 'default' ): Promise<string> {
		const config = getConfig();
		const state = await this.store.read();
		const stored = state.users[ userKey ];

		if ( ! stored ) {
			throw new Error( 'No Autodesk user session. Complete the OAuth flow at /auth/login first.' );
		}

		const token = this.decryptToken( stored );

		if ( token.expiresAt > Date.now() + 60_000 ) {
			return token.accessToken;
		}

		if ( ! token.refreshToken ) {
			throw new Error( 'The Autodesk session expired and no refresh token is available.' );
		}

		const body = new URLSearchParams( {
			grant_type: 'refresh_token',
			refresh_token: token.refreshToken,
			client_id: config.APS_CLIENT_ID,
			scope: config.APS_SCOPES,
		} );

		const refreshed = await this.requestToken( body, true );

		await this.store.mutate( ( state_ ) => {
			state_.users[ userKey ] = this.encryptToken( {
				...refreshed,
				// Autodesk may omit the refresh token on rotation-free responses.
				refreshToken: refreshed.refreshToken ?? token.refreshToken,
			} );
		} );

		logger.info( 'Refreshed Autodesk user token', { userKey } );

		return refreshed.accessToken;
	}

	/** Returns a two legged application token, refreshing it when needed. */
	async getClientAccessToken(): Promise<string> {
		const config = getConfig();
		const state = await this.store.read();

		if ( state.client ) {
			const token = this.decryptToken( state.client );

			if ( token.expiresAt > Date.now() + 60_000 ) {
				return token.accessToken;
			}
		}

		const body = new URLSearchParams( {
			grant_type: 'client_credentials',
			scope: config.APS_SCOPES,
		} );

		const token = await this.requestToken( body, false );

		await this.store.mutate( ( state_ ) => {
			state_.client = this.encryptToken( token );
		} );

		return token.accessToken;
	}

	/** Reports whether a user session exists, without exposing the token. */
	async hasUserSession( userKey = 'default' ): Promise<boolean> {
		const state = await this.store.read();

		return Boolean( state.users[ userKey ] );
	}

	/** Removes a stored user session. */
	async signOut( userKey = 'default' ): Promise<void> {
		await this.store.mutate( ( state ) => {
			delete state.users[ userKey ];
		} );
	}

	private async requestToken(
		body: URLSearchParams,
		usePkceClientId: boolean
	): Promise<{ accessToken: string; refreshToken?: string; expiresAt: number; scope: string }> {
		const config = getConfig();
		const headers: Record<string, string> = {
			'content-type': 'application/x-www-form-urlencoded',
			accept: 'application/json',
		};

		// Confidential clients authenticate with HTTP Basic; the PKCE flow still
		// supports it and Autodesk requires it for client_credentials.
		if ( ! usePkceClientId || config.APS_CLIENT_SECRET ) {
			const basic = Buffer.from( `${ config.APS_CLIENT_ID }:${ config.APS_CLIENT_SECRET }` ).toString(
				'base64'
			);
			headers.authorization = `Basic ${ basic }`;
			body.delete( 'client_id' );
		}

		const controller = new AbortController();
		const timer = setTimeout( () => controller.abort(), config.HTTP_TIMEOUT_MS );

		let response: Response;

		try {
			response = await fetch( new URL( '/authentication/v2/token', config.APS_BASE_URL ), {
				method: 'POST',
				headers,
				body: body.toString(),
				signal: controller.signal,
			} );
		} finally {
			clearTimeout( timer );
		}

		const text = await response.text();

		if ( ! response.ok ) {
			throw new Error( `Autodesk token request failed with HTTP ${ response.status }: ${ text.slice( 0, 400 ) }` );
		}

		const parsed = JSON.parse( text ) as {
			access_token: string;
			refresh_token?: string;
			expires_in: number;
			scope?: string;
		};

		return {
			accessToken: parsed.access_token,
			...( parsed.refresh_token ? { refreshToken: parsed.refresh_token } : {} ),
			expiresAt: Date.now() + Math.max( 0, parsed.expires_in - 30 ) * 1000,
			scope: parsed.scope ?? config.APS_SCOPES,
		};
	}

	private encryptToken( token: {
		accessToken: string;
		refreshToken?: string;
		expiresAt: number;
		scope: string;
	} ): StoredToken {
		const key = getConfig().ENCRYPTION_KEY;

		return {
			accessToken: encrypt( token.accessToken, key ),
			...( token.refreshToken ? { refreshToken: encrypt( token.refreshToken, key ) } : {} ),
			expiresAt: token.expiresAt,
			scope: token.scope,
		};
	}

	private decryptToken( token: StoredToken ): {
		accessToken: string;
		refreshToken?: string;
		expiresAt: number;
		scope: string;
	} {
		const key = getConfig().ENCRYPTION_KEY;

		return {
			accessToken: decrypt( token.accessToken, key ),
			...( token.refreshToken ? { refreshToken: decrypt( token.refreshToken, key ) } : {} ),
			expiresAt: token.expiresAt,
			scope: token.scope,
		};
	}

	private prunePending(): void {
		const cutoff = Date.now() - 10 * 60 * 1000;

		for ( const [ key, value ] of pending ) {
			if ( value.createdAt < cutoff ) {
				pending.delete( key );
			}
		}
	}
}
