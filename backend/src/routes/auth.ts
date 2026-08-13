import { Router } from 'express';
import { z } from 'zod';
import { ApsOAuth } from '../aps/oauth.js';
import { logger } from '../logger.js';

const callbackSchema = z.object( {
	code: z.string().min( 1 ),
	state: z.string().min( 1 ),
} );

/**
 * Autodesk OAuth routes.
 *
 * These are the only browser facing routes in the service. They never return a
 * token to the caller; the session lives entirely server side.
 */
export function authRoutes( oauth: ApsOAuth ): Router {
	const router = Router();

	router.get( '/login', ( _req, res ) => {
		const { url } = oauth.beginAuthorization();

		res.redirect( url );
	} );

	router.get( '/callback', async ( req, res ) => {
		const parsed = callbackSchema.safeParse( req.query );

		if ( ! parsed.success ) {
			res.status( 400 ).json( { error: 'invalid_callback', message: 'Missing authorization code or state.' } );

			return;
		}

		try {
			await oauth.completeAuthorization( parsed.data.code, parsed.data.state );

			res
				.status( 200 )
				.type( 'html' )
				.send(
					'<!doctype html><meta charset="utf-8"><title>Connected</title>' +
						'<p>Autodesk account connected. You can close this window and return to Forma.</p>'
				);
		} catch ( error ) {
			const message = error instanceof Error ? error.message : String( error );

			logger.error( 'Autodesk authorization failed', { error: message } );
			res.status( 400 ).json( { error: 'authorization_failed', message } );
		}
	} );

	router.get( '/session', async ( _req, res ) => {
		res.json( { connected: await oauth.hasUserSession() } );
	} );

	router.post( '/logout', async ( _req, res ) => {
		await oauth.signOut();
		res.json( { connected: false } );
	} );

	return router;
}
