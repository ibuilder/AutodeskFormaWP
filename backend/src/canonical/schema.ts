import { z } from 'zod';

/**
 * The canonical publish contract.
 *
 * This is the single source of truth shared with the WordPress plugin. It is
 * deliberately independent of Autodesk response shapes so that upstream API
 * changes never reach WordPress unmediated.
 */
export const SCHEMA_VERSION = '1.0';

export const metricSchema = z.object( {
	key: z.string().min( 1 ).max( 100 ),
	label: z.string().max( 200 ).optional(),
	value: z.union( [ z.number(), z.string(), z.null() ] ).optional(),
	unit: z.string().max( 32 ).optional(),
	category: z.string().max( 100 ).optional(),
	precision: z.number().int().min( 0 ).max( 8 ).optional(),
} );

export const assetSchema = z.object( {
	source_id: z.string().min( 1 ).max( 255 ),
	title: z.string().min( 1 ).max( 255 ),
	kind: z.enum( [ 'image', 'document', 'model', 'dataset', 'link' ] ).optional(),
	url: z.string().url().optional(),
	mime_type: z.string().max( 128 ).optional(),
	size: z.number().int().min( 0 ).optional(),
	checksum: z.string().max( 128 ).optional(),
	summary: z.string().max( 2000 ).optional(),
} );

export const locationSchema = z.object( {
	latitude: z.number().min( -90 ).max( 90 ).optional(),
	longitude: z.number().min( -180 ).max( 180 ).optional(),
	address: z.string().max( 500 ).optional(),
} );

export const featuredImageSchema = z.object( {
	url: z.string().url(),
	alt: z.string().max( 255 ).optional(),
	filename: z.string().max( 200 ).optional(),
} );

export const projectSchema = z
	.object( {
		source_id: z.string().min( 1 ).max( 255 ),
		source_system: z.string().max( 64 ).default( 'autodesk-forma' ),
		title: z.string().min( 1 ).max( 255 ),
		slug: z.string().max( 200 ).optional(),
		summary: z.string().max( 2000 ).optional(),
		content: z.string().max( 200_000 ).optional(),
		status: z.enum( [ 'publish', 'draft', 'pending', 'private' ] ).optional(),
		source_url: z.string().url().optional(),
		hub_id: z.string().max( 255 ).optional(),
		project_id: z.string().max( 255 ).optional(),
		proposal_id: z.string().max( 255 ).optional(),
		source_updated_at: z.string().max( 40 ).optional(),
		tags: z.array( z.string().max( 100 ) ).max( 50 ).optional(),
		statuses: z.array( z.string().max( 100 ) ).max( 20 ).optional(),
		location: locationSchema.optional(),
		featured_image: featuredImageSchema.optional(),
		metrics: z.array( metricSchema ).max( 200 ).optional(),
		assets: z.array( assetSchema ).max( 200 ).optional(),
	} )
	.strict();

export const OPERATIONS = [ 'publish', 'update', 'unpublish', 'archive', 'delete' ] as const;

export const payloadSchema = z
	.object( {
		schema_version: z.literal( SCHEMA_VERSION ),
		operation: z.enum( OPERATIONS ),
		mode: z.enum( [ 'snapshot', 'sync' ] ).default( 'snapshot' ),
		job_id: z.string().min( 1 ).max( 128 ),
		generated_at: z.string().optional(),
		project: projectSchema,
	} )
	.strict();

export type CanonicalMetric = z.infer<typeof metricSchema>;
export type CanonicalAsset = z.infer<typeof assetSchema>;
export type CanonicalProject = z.infer<typeof projectSchema>;
export type CanonicalPayload = z.infer<typeof payloadSchema>;
export type Operation = ( typeof OPERATIONS )[ number ];
