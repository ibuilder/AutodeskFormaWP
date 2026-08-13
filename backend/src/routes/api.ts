import { Router } from 'express';
import { z } from 'zod';
import { ApsClient } from '../aps/client.js';
import type { ApsOAuth } from '../aps/oauth.js';
import { OPERATIONS, projectSchema } from '../canonical/schema.js';
import { toCanonicalProject } from '../canonical/transform.js';
import type { PublishQueue } from '../jobs/queue.js';
import { logger } from '../logger.js';
import { WordPressClient } from '../wordpress/client.js';

const buildSchema = z.object( {
	hubId: z.string().min( 1 ),
	projectId: z.string().min( 1 ),
	proposalId: z.string().min( 1 ).optional(),
	includeFiles: z.boolean().default( true ),
	overrides: z.record( z.unknown() ).optional(),
} );

const publishSchema = z.object( {
	operation: z.enum( OPERATIONS ).default( 'publish' ),
	mode: z.enum( [ 'snapshot', 'sync' ] ).default( 'snapshot' ),
	force: z.boolean().default( false ),
	/** Either a fully formed canonical project, or a source descriptor to build one. */
	project: projectSchema.optional(),
	source: buildSchema.optional(),
} );

function fail( error: unknown ): { status: number; body: { error: string; message: string } } {
	const message = error instanceof Error ? error.message : String( error );

	return { status: 400, body: { error: 'request_failed', message } };
}

/** Routes consumed by the Forma embedded extension. */
export function apiRoutes( oauth: ApsOAuth, queue: PublishQueue ): Router {
	const router = Router();
	const aps = new ApsClient( oauth );

	router.get( '/hubs', async ( _req, res ) => {
		try {
			res.json( { hubs: await aps.listHubs() } );
		} catch ( error ) {
			const { status, body } = fail( error );
			res.status( status ).json( body );
		}
	} );

	router.get( '/hubs/:hubId/projects', async ( req, res ) => {
		try {
			res.json( { projects: await aps.listProjects( String( req.params.hubId ) ) } );
		} catch ( error ) {
			const { status, body } = fail( error );
			res.status( status ).json( body );
		}
	} );

	/** Builds and returns the canonical payload without publishing it. */
	router.post( '/preview', async ( req, res ) => {
		const parsed = buildSchema.safeParse( req.body );

		if ( ! parsed.success ) {
			res.status( 400 ).json( { error: 'invalid_request', issues: parsed.error.issues } );

			return;
		}

		try {
			const source = await aps.buildSourceProject( {
				hubId: parsed.data.hubId,
				projectId: parsed.data.projectId,
				...( parsed.data.proposalId ? { proposalId: parsed.data.proposalId } : {} ),
				includeFiles: parsed.data.includeFiles,
				...( parsed.data.overrides ? { overrides: parsed.data.overrides } : {} ),
			} );

			res.json( { project: toCanonicalProject( source ) } );
		} catch ( error ) {
			const { status, body } = fail( error );
			res.status( status ).json( body );
		}
	} );

	/** Queues a publish, update, unpublish, archive or delete operation. */
	router.post( '/publish', async ( req, res ) => {
		const parsed = publishSchema.safeParse( req.body );

		if ( ! parsed.success ) {
			res.status( 400 ).json( { error: 'invalid_request', issues: parsed.error.issues } );

			return;
		}

		if ( ! parsed.data.project && ! parsed.data.source ) {
			res.status( 400 ).json( {
				error: 'invalid_request',
				message: 'Provide either a canonical project or a source descriptor.',
			} );

			return;
		}

		try {
			let project = parsed.data.project;

			if ( ! project && parsed.data.source ) {
				const source = await aps.buildSourceProject( {
					hubId: parsed.data.source.hubId,
					projectId: parsed.data.source.projectId,
					...( parsed.data.source.proposalId ? { proposalId: parsed.data.source.proposalId } : {} ),
					includeFiles: parsed.data.source.includeFiles,
					...( parsed.data.source.overrides ? { overrides: parsed.data.source.overrides } : {} ),
				} );

				project = toCanonicalProject( source );
			}

			if ( ! project ) {
				res.status( 400 ).json( { error: 'invalid_request', message: 'No project could be resolved.' } );

				return;
			}

			const job = await queue.enqueue( {
				operation: parsed.data.operation,
				project,
				mode: parsed.data.mode,
				force: parsed.data.force,
			} );

			res.status( 202 ).json( { job } );
		} catch ( error ) {
			const { status, body } = fail( error );
			res.status( status ).json( body );
		}
	} );

	router.get( '/jobs', async ( req, res ) => {
		const limit = Number( req.query.limit ?? 50 );

		res.json( { jobs: await queue.list( Number.isFinite( limit ) ? limit : 50 ) } );
	} );

	router.get( '/jobs/:id', async ( req, res ) => {
		const job = await queue.get( String( req.params.id ) );

		if ( ! job ) {
			res.status( 404 ).json( { error: 'not_found', message: 'No job with that id.' } );

			return;
		}

		res.json( { job } );
	} );

	router.get( '/published', async ( _req, res ) => {
		res.json( { published: await queue.published() } );
	} );

	/** Connectivity check against the WordPress receiver. */
	router.get( '/wordpress/status', async ( _req, res ) => {
		try {
			res.json( await new WordPressClient().status() );
		} catch ( error ) {
			const message = error instanceof Error ? error.message : String( error );

			logger.warn( 'WordPress status check failed', { error: message } );
			res.status( 502 ).json( { error: 'wordpress_unreachable', message } );
		}
	} );

	return router;
}
