import { createHash } from 'node:crypto';
import {
	type CanonicalAsset,
	type CanonicalMetric,
	type CanonicalProject,
	projectSchema,
} from './schema.js';

/**
 * The loosely typed shape this service reads from Autodesk responses. Only the
 * fields the transform actually consumes are declared, so an upstream addition
 * never breaks parsing.
 */
export interface FormaSourceProject {
	id?: string;
	urn?: string;
	proposalId?: string;
	projectId?: string;
	hubId?: string;
	name?: string;
	title?: string;
	description?: string;
	summary?: string;
	webUrl?: string;
	updatedAt?: string;
	lastModifiedTime?: string;
	tags?: string[];
	phase?: string;
	location?: {
		lat?: number;
		latitude?: number;
		lon?: number;
		lng?: number;
		longitude?: number;
		address?: string;
	};
	metrics?: Record<string, unknown> | Array<Record<string, unknown>>;
	files?: Array<Record<string, unknown>>;
	thumbnail?: { url?: string; alt?: string } | string;
}

/** Human readable labels for the metric keys Forma analyses commonly expose. */
const METRIC_LABELS: Record<string, { label: string; unit?: string; category?: string }> = {
	gfa: { label: 'Gross floor area', unit: 'm²', category: 'Area' },
	gross_floor_area: { label: 'Gross floor area', unit: 'm²', category: 'Area' },
	far: { label: 'Floor area ratio', category: 'Area' },
	footprint: { label: 'Building footprint', unit: 'm²', category: 'Area' },
	site_area: { label: 'Site area', unit: 'm²', category: 'Area' },
	building_count: { label: 'Buildings', category: 'Massing' },
	max_height: { label: 'Maximum height', unit: 'm', category: 'Massing' },
	dwelling_units: { label: 'Dwelling units', category: 'Programme' },
	sun_hours: { label: 'Average sun hours', unit: 'h', category: 'Environment' },
	daylight_potential: { label: 'Daylight potential', unit: '%', category: 'Environment' },
	wind_comfort: { label: 'Wind comfort', unit: '%', category: 'Environment' },
	operational_carbon: { label: 'Operational carbon', unit: 'kgCO₂e/m²', category: 'Carbon' },
	embodied_carbon: { label: 'Embodied carbon', unit: 'kgCO₂e/m²', category: 'Carbon' },
	noise_level: { label: 'Noise level', unit: 'dB', category: 'Environment' },
};

function titleCase( key: string ): string {
	return key
		.replace( /[_\-.]+/g, ' ' )
		.replace( /([a-z])([A-Z])/g, '$1 $2' )
		.trim()
		.replace( /^./, ( character ) => character.toUpperCase() );
}

/** Normalizes either a metric map or a metric array into canonical metrics. */
export function normalizeMetrics(
	metrics: FormaSourceProject[ 'metrics' ]
): CanonicalMetric[] {
	if ( ! metrics ) {
		return [];
	}

	const rows: Array<{ key: string; raw: unknown }> = Array.isArray( metrics )
		? metrics
				.filter( ( entry ): entry is Record<string, unknown> => !! entry && typeof entry === 'object' )
				.map( ( entry ) => ( {
					key: String( entry.key ?? entry.name ?? '' ),
					raw: entry,
				} ) )
		: Object.entries( metrics ).map( ( [ key, raw ] ) => ( { key, raw } ) );

	const out: CanonicalMetric[] = [];

	for ( const row of rows ) {
		const key = row.key.trim().toLowerCase().replace( /[^a-z0-9_]+/g, '_' ).replace( /^_+|_+$/g, '' );

		if ( ! key ) {
			continue;
		}

		const known = METRIC_LABELS[ key ];
		const source = ( row.raw && typeof row.raw === 'object' ? row.raw : {} ) as Record<string, unknown>;

		const rawValue =
			row.raw !== null && typeof row.raw === 'object' ? source.value ?? source.amount ?? null : row.raw;

		let value: number | string | null = null;

		if ( typeof rawValue === 'number' && Number.isFinite( rawValue ) ) {
			value = rawValue;
		} else if ( typeof rawValue === 'string' && rawValue.trim() !== '' ) {
			const numeric = Number( rawValue );
			value = Number.isFinite( numeric ) ? numeric : rawValue.slice( 0, 200 );
		} else if ( typeof rawValue === 'boolean' ) {
			value = rawValue ? 'Yes' : 'No';
		}

		const unit = typeof source.unit === 'string' ? source.unit : known?.unit;
		const category = typeof source.category === 'string' ? source.category : known?.category;
		const label = typeof source.label === 'string' ? source.label : known?.label ?? titleCase( key );

		const metric: CanonicalMetric = {
			key,
			label,
			value,
			precision: Number.isInteger( value ) ? 0 : 2,
		};

		if ( unit ) {
			metric.unit = unit;
		}

		if ( category ) {
			metric.category = category;
		}

		out.push( metric );
	}

	return out;
}

function guessKind( mime: string, name: string ): CanonicalAsset[ 'kind' ] {
	if ( mime.startsWith( 'image/' ) ) {
		return 'image';
	}

	if ( /\.(rvt|ifc|glb|gltf|obj|fbx|3dm|skp)$/i.test( name ) || mime.includes( 'model' ) ) {
		return 'model';
	}

	if ( /\.(csv|json|xlsx?)$/i.test( name ) || mime.includes( 'json' ) || mime.includes( 'csv' ) ) {
		return 'dataset';
	}

	return 'document';
}

/** Normalizes Autodesk Data Management style file entries into canonical assets. */
export function normalizeAssets( files: FormaSourceProject[ 'files' ] ): CanonicalAsset[] {
	if ( ! Array.isArray( files ) ) {
		return [];
	}

	const out: CanonicalAsset[] = [];

	for ( const file of files ) {
		if ( ! file || typeof file !== 'object' ) {
			continue;
		}

		const sourceId = String( file.id ?? file.urn ?? file.source_id ?? '' ).trim();
		const title = String( file.name ?? file.displayName ?? file.title ?? '' ).trim();

		if ( ! sourceId || ! title ) {
			continue;
		}

		const mime = String( file.mimeType ?? file.mime_type ?? '' );
		const asset: CanonicalAsset = {
			source_id: sourceId.slice( 0, 255 ),
			title: title.slice( 0, 255 ),
			kind: guessKind( mime, title ),
		};

		const url = file.url ?? file.webUrl ?? file.downloadUrl;

		if ( typeof url === 'string' && /^https?:\/\//i.test( url ) ) {
			asset.url = url;
		}

		if ( mime ) {
			asset.mime_type = mime.slice( 0, 128 );
		}

		const size = Number( file.storageSize ?? file.size ?? 0 );

		if ( Number.isFinite( size ) && size > 0 ) {
			asset.size = Math.floor( size );
		}

		out.push( asset );
	}

	return out;
}

/** Builds a canonical project from an upstream Forma project payload. */
export function toCanonicalProject( source: FormaSourceProject ): CanonicalProject {
	const sourceId = String( source.urn ?? source.id ?? source.proposalId ?? '' ).trim();

	if ( ! sourceId ) {
		throw new Error( 'The upstream project has no identifier to publish against.' );
	}

	const title = String( source.name ?? source.title ?? '' ).trim() || 'Untitled Forma project';
	const summary = String( source.summary ?? source.description ?? '' ).trim();

	const candidate: Record<string, unknown> = {
		source_id: sourceId.slice( 0, 255 ),
		source_system: 'autodesk-forma',
		title: title.slice( 0, 255 ),
		metrics: normalizeMetrics( source.metrics ),
		assets: normalizeAssets( source.files ),
	};

	if ( summary ) {
		candidate.summary = summary.slice( 0, 2000 );
		candidate.content = `<p>${ escapeHtml( summary.slice( 0, 20_000 ) ) }</p>`;
	}

	if ( source.webUrl && /^https?:\/\//i.test( source.webUrl ) ) {
		candidate.source_url = source.webUrl;
	}

	if ( source.hubId ) {
		candidate.hub_id = String( source.hubId ).slice( 0, 255 );
	}

	if ( source.projectId ) {
		candidate.project_id = String( source.projectId ).slice( 0, 255 );
	}

	if ( source.proposalId ) {
		candidate.proposal_id = String( source.proposalId ).slice( 0, 255 );
	}

	const updated = source.updatedAt ?? source.lastModifiedTime;

	if ( updated ) {
		candidate.source_updated_at = String( updated ).slice( 0, 40 );
	}

	if ( Array.isArray( source.tags ) && source.tags.length > 0 ) {
		candidate.tags = source.tags
			.filter( ( tag ): tag is string => typeof tag === 'string' && tag.trim() !== '' )
			.map( ( tag ) => tag.trim().slice( 0, 100 ) )
			.slice( 0, 50 );
	}

	if ( source.phase ) {
		candidate.statuses = [ String( source.phase ).slice( 0, 100 ) ];
	}

	const location = source.location;

	if ( location ) {
		const normalized: Record<string, unknown> = {};
		const latitude = location.latitude ?? location.lat;
		const longitude = location.longitude ?? location.lng ?? location.lon;

		if ( typeof latitude === 'number' && Number.isFinite( latitude ) ) {
			normalized.latitude = latitude;
		}

		if ( typeof longitude === 'number' && Number.isFinite( longitude ) ) {
			normalized.longitude = longitude;
		}

		if ( location.address ) {
			normalized.address = String( location.address ).slice( 0, 500 );
		}

		if ( Object.keys( normalized ).length > 0 ) {
			candidate.location = normalized;
		}
	}

	const thumbnail = typeof source.thumbnail === 'string' ? { url: source.thumbnail } : source.thumbnail;

	if ( thumbnail?.url && /^https?:\/\//i.test( thumbnail.url ) ) {
		candidate.featured_image = {
			url: thumbnail.url,
			...( thumbnail.alt ? { alt: String( thumbnail.alt ).slice( 0, 255 ) } : {} ),
		};
	}

	return projectSchema.parse( candidate );
}

function escapeHtml( value: string ): string {
	return value
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

/**
 * Stable hash of a canonical project, used to detect no-op republishes before a
 * request is ever sent.
 */
export function projectHash( project: CanonicalProject ): string {
	return createHash( 'sha256' ).update( stableStringify( project ), 'utf8' ).digest( 'hex' );
}

function stableStringify( value: unknown ): string {
	if ( Array.isArray( value ) ) {
		return `[${ value.map( stableStringify ).join( ',' ) }]`;
	}

	if ( value && typeof value === 'object' ) {
		const entries = Object.entries( value as Record<string, unknown> )
			.filter( ( [ , item ] ) => item !== undefined )
			.sort( ( a, b ) => a[ 0 ].localeCompare( b[ 0 ] ) )
			.map( ( [ key, item ] ) => `${ JSON.stringify( key ) }:${ stableStringify( item ) }` );

		return `{${ entries.join( ',' ) }}`;
	}

	return JSON.stringify( value ) ?? 'null';
}
