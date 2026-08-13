import express, { type NextFunction, type Request, type Response } from 'express';
import { timingSafeEqual } from 'node:crypto';
import { ApsClient } from './aps/client.js';
import { ApsOAuth } from './aps/oauth.js';
import { getConfig } from './config.js';
import { PublishQueue } from './jobs/queue.js';
import { refreshSyncProjects } from './jobs/refresh.js';
import { logger } from './logger.js';
import { apiRoutes } from './routes/api.js';
import { authRoutes } from './routes/auth.js';
import { verifyInbound } from './security/hmac.js';

interface RawBodyRequest extends Request {
	rawBody?: string;
}

function constantTimeEquals( a: string, b: string ): boolean {
	const left = Buffer.from( a, 'utf8' );
	const right = Buffer.from( b, 'utf8' );

	if ( left.length !== right.length ) {
		timingSafeEqual( left, left );

		return false;
	}

	return timingSafeEqual( left, right );
}

/** Rejects extension traffic that does not present the shared API key. */
function requireExtensionKey( req: Request, res: Response, next: NextFunction ): void {
	const provided = req.header( 'x-api-key' ) ?? '';

	if ( ! provided || ! constantTimeEquals( provided, getConfig().EXTENSION_API_KEY ) ) {
		res.status( 401 ).json( { error: 'unauthorized', message: 'A valid extension API key is required.' } );

		return;
	}

	next();
}

export function createServer(): express.Express {
	const config = getConfig();
	const app = express();
	const oauth = new ApsOAuth();
	const queue = new PublishQueue();

	app.disable( 'x-powered-by' );

	app.use(
		express.json( {
			limit: '2mb',
			verify: ( req, _res, buffer ) => {
				( req as RawBodyRequest ).rawBody = buffer.toString( 'utf8' );
			},
		} )
	);

	app.use( ( req, res, next ) => {
		res.setHeader( 'x-content-type-options', 'nosniff' );
		res.setHeader( 'referrer-policy', 'no-referrer' );
		res.setHeader( 'cache-control', 'no-store' );
		next();
	} );

	app.get( '/health', ( _req, res ) => {
		res.json( { status: 'ok', schemaVersion: '1.0', uptime: Math.floor( process.uptime() ) } );
	} );

	app.use( '/auth', authRoutes( oauth ) );
	app.use( '/api', requireExtensionKey, apiRoutes( oauth, queue ) );

	/**
	 * Refresh endpoint called by the WordPress plugin on a schedule. It is
	 * authenticated with the same HMAC scheme, in the opposite direction, so the
	 * shared secret is the only credential either side needs.
	 */
	app.post( '/api/refresh', ( req: RawBodyRequest, res: Response ) => {
		const failure = verifyInbound( {
			method: 'POST',
			route: req.path,
			body: req.rawBody ?? '',
			secret: config.WORDPRESS_SECRET,
			headers: req.headers as Record<string, string | string[] | undefined>,
		} );

		if ( failure ) {
			logger.warn( 'Rejected refresh request', { reason: failure } );
			res.status( 401 ).json( { error: 'unauthorized', message: failure } );

			return;
		}

		// Rebuilding runs asynchronously so the WordPress cron request returns
		// immediately rather than blocking on Autodesk for every project.
		void ( async () => {
			try {
				await refreshSyncProjects( new ApsClient( oauth ), queue );
			} catch ( error ) {
				logger.error( 'Refresh handling failed', {
					error: error instanceof Error ? error.message : String( error ),
				} );
			}
		} )();

		res.status( 202 ).json( { accepted: true } );
	} );

	app.use( ( _req, res ) => {
		res.status( 404 ).json( { error: 'not_found' } );
	} );

	app.use( ( error: Error, _req: Request, res: Response, _next: NextFunction ) => {
		logger.error( 'Unhandled request error', { error: error.message } );

		if ( res.headersSent ) {
			return;
		}

		res.status( 500 ).json( { error: 'internal_error' } );
	} );

	return app;
}
