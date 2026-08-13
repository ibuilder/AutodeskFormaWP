import type { CanonicalProject } from './schema.js';

/**
 * Publishing templates.
 *
 * A template is a pre-mapped view of a project: it decides which parts of the
 * canonical payload actually reach WordPress. Editors pick one in the Forma
 * extension instead of hand-selecting fields on every publish.
 *
 * Templates only ever remove or constrain content. They never invent fields,
 * so applying one can be reasoned about as a filter over the canonical form.
 */
export interface PublishTemplate {
	id: string;
	label: string;
	description: string;
	includeContent: boolean;
	includeMetrics: boolean;
	includeAssets: boolean;
	includeFeaturedImage: boolean;
	/** When set, only metrics in these categories survive (case insensitive). */
	metricCategories?: string[];
	/** Suggested WordPress post status; the caller may still override it. */
	defaultStatus: 'draft' | 'pending' | 'publish' | 'private';
}

export const TEMPLATES: PublishTemplate[] = [
	{
		id: 'full',
		label: 'Full project page',
		description: 'Description, every metric, all assets and the thumbnail.',
		includeContent: true,
		includeMetrics: true,
		includeAssets: true,
		includeFeaturedImage: true,
		defaultStatus: 'draft',
	},
	{
		id: 'summary',
		label: 'Summary only',
		description: 'Title, summary and thumbnail. No metrics or assets.',
		includeContent: true,
		includeMetrics: false,
		includeAssets: false,
		includeFeaturedImage: true,
		defaultStatus: 'draft',
	},
	{
		id: 'metrics',
		label: 'Metrics report',
		description: 'Summary and the full metric table, without file downloads.',
		includeContent: true,
		includeMetrics: true,
		includeAssets: false,
		includeFeaturedImage: true,
		defaultStatus: 'draft',
	},
	{
		id: 'sustainability',
		label: 'Sustainability highlights',
		description: 'Only environment and carbon metrics, for public reporting.',
		includeContent: true,
		includeMetrics: true,
		includeAssets: false,
		includeFeaturedImage: true,
		metricCategories: [ 'environment', 'carbon' ],
		defaultStatus: 'draft',
	},
	{
		id: 'downloads',
		label: 'Downloads only',
		description: 'Summary and the asset list, without analysis figures.',
		includeContent: true,
		includeMetrics: false,
		includeAssets: true,
		includeFeaturedImage: false,
		defaultStatus: 'draft',
	},
];

export const DEFAULT_TEMPLATE_ID = 'full';

/** Returns a template by id, or undefined when the id is unknown. */
export function getTemplate( id: string | undefined ): PublishTemplate | undefined {
	if ( ! id ) {
		return TEMPLATES.find( ( template ) => template.id === DEFAULT_TEMPLATE_ID );
	}

	return TEMPLATES.find( ( template ) => template.id === id );
}

/**
 * Applies a template to a canonical project, returning a filtered copy.
 *
 * The input is not mutated, so the same built project can be previewed against
 * several templates without rebuilding it from Autodesk.
 */
export function applyTemplate( project: CanonicalProject, template: PublishTemplate ): CanonicalProject {
	const result: CanonicalProject = { ...project };

	if ( ! template.includeContent ) {
		delete result.content;
	}

	if ( ! template.includeFeaturedImage ) {
		delete result.featured_image;
	}

	if ( ! template.includeAssets ) {
		result.assets = [];
	}

	if ( ! template.includeMetrics ) {
		result.metrics = [];
	} else if ( template.metricCategories && template.metricCategories.length > 0 ) {
		const wanted = template.metricCategories.map( ( category ) => category.toLowerCase() );

		result.metrics = ( project.metrics ?? [] ).filter( ( metric ) =>
			wanted.includes( ( metric.category ?? '' ).toLowerCase() )
		);
	}

	return result;
}
