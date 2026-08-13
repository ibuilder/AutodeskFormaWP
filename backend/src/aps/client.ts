import { getConfig } from '../config.js';
import { logger } from '../logger.js';
import type { ApsOAuth } from './oauth.js';
import type { FormaSourceProject } from '../canonical/transform.js';

/**
 * Thin, read only client for the Autodesk Platform Services endpoints this
 * service consumes. Every call is scoped to data the connected account can
 * already see; the service never writes back to Autodesk.
 */
export class ApsClient {
	constructor( private readonly oauth: ApsOAuth ) {}

	/** Lists the hubs visible to the connected account. */
	async listHubs(): Promise<Array<{ id: string; name: string }>> {
		const body = await this.get<{ data?: Array<{ id: string; attributes?: { name?: string } }> }>(
			'/project/v1/hubs'
		);

		return ( body.data ?? [] ).map( ( hub ) => ( {
			id: hub.id,
			name: hub.attributes?.name ?? hub.id,
		} ) );
	}

	/** Lists the projects inside a hub. */
	async listProjects( hubId: string ): Promise<Array<{ id: string; name: string; hubId: string }>> {
		const body = await this.get<{ data?: Array<{ id: string; attributes?: { name?: string } }> }>(
			`/project/v1/hubs/${ encodeURIComponent( hubId ) }/projects`
		);

		return ( body.data ?? [] ).map( ( project ) => ( {
			id: project.id,
			name: project.attributes?.name ?? project.id,
			hubId,
		} ) );
	}

	/** Returns the top level folders of a project. */
	async listTopFolders( hubId: string, projectId: string ): Promise<Array<{ id: string; name: string }>> {
		const body = await this.get<{ data?: Array<{ id: string; attributes?: { displayName?: string } }> }>(
			`/project/v1/hubs/${ encodeURIComponent( hubId ) }/projects/${ encodeURIComponent(
				projectId
			) }/topFolders`
		);

		return ( body.data ?? [] ).map( ( folder ) => ( {
			id: folder.id,
			name: folder.attributes?.displayName ?? folder.id,
		} ) );
	}

	/** Returns the items inside a folder, flattened into publishable file records. */
	async listFolderContents(
		projectId: string,
		folderId: string
	): Promise<Array<Record<string, unknown>>> {
		const body = await this.get<{
			data?: Array<{
				id: string;
				type?: string;
				attributes?: {
					displayName?: string;
					name?: string;
					lastModifiedTime?: string;
					extension?: { type?: string };
				};
			}>;
			included?: Array<{
				id: string;
				attributes?: { storageSize?: number; mimeType?: string; name?: string };
			}>;
		}>(
			`/data/v1/projects/${ encodeURIComponent( projectId ) }/folders/${ encodeURIComponent(
				folderId
			) }/contents`
		);

		const sizes = new Map<string, { storageSize?: number; mimeType?: string }>();

		for ( const included of body.included ?? [] ) {
			sizes.set( included.id, {
				...( included.attributes?.storageSize !== undefined
					? { storageSize: included.attributes.storageSize }
					: {} ),
				...( included.attributes?.mimeType ? { mimeType: included.attributes.mimeType } : {} ),
			} );
		}

		return ( body.data ?? [] )
			.filter( ( entry ) => entry.type === 'items' )
			.map( ( entry ) => ( {
				id: entry.id,
				name: entry.attributes?.displayName ?? entry.attributes?.name ?? entry.id,
				lastModifiedTime: entry.attributes?.lastModifiedTime,
				...( sizes.get( entry.id ) ?? {} ),
			} ) );
	}

	/**
	 * Assembles the upstream project record this service publishes from.
	 *
	 * Autodesk exposes Forma specific analysis data through endpoints that vary
	 * by entitlement, so the extension may supply metrics it already holds in
	 * embedded-view context. Anything supplied there is merged over what the
	 * Data Management APIs return.
	 */
	async buildSourceProject( options: {
		hubId: string;
		projectId: string;
		proposalId?: string;
		includeFiles?: boolean;
		overrides?: Partial<FormaSourceProject>;
	} ): Promise<FormaSourceProject> {
		const projects = await this.listProjects( options.hubId );
		const project = projects.find( ( entry ) => entry.id === options.projectId );

		if ( ! project ) {
			throw new Error( `Project ${ options.projectId } was not found in hub ${ options.hubId }.` );
		}

		const source: FormaSourceProject = {
			id: options.proposalId ?? project.id,
			hubId: options.hubId,
			projectId: project.id,
			name: project.name,
			...( options.proposalId ? { proposalId: options.proposalId } : {} ),
		};

		if ( options.includeFiles ) {
			try {
				const folders = await this.listTopFolders( options.hubId, options.projectId );
				const files: Array<Record<string, unknown>> = [];

				for ( const folder of folders.slice( 0, 5 ) ) {
					files.push( ...( await this.listFolderContents( options.projectId, folder.id ) ) );
				}

				source.files = files.slice( 0, 200 );
			} catch ( error ) {
				// File listing is optional; a permissions gap must not fail a publish.
				logger.warn( 'Could not list project files', {
					projectId: options.projectId,
					error: error instanceof Error ? error.message : String( error ),
				} );
			}
		}

		return { ...source, ...( options.overrides ?? {} ) };
	}

	private async get<T>( path: string ): Promise<T> {
		const config = getConfig();
		const token = await this.oauth.getUserAccessToken();
		const controller = new AbortController();
		const timer = setTimeout( () => controller.abort(), config.HTTP_TIMEOUT_MS );

		let response: Response;

		try {
			response = await fetch( new URL( path, config.APS_BASE_URL ), {
				headers: {
					authorization: `Bearer ${ token }`,
					accept: 'application/vnd.api+json, application/json',
				},
				signal: controller.signal,
			} );
		} finally {
			clearTimeout( timer );
		}

		const text = await response.text();

		if ( ! response.ok ) {
			throw new Error( `Autodesk request to ${ path } failed with HTTP ${ response.status }: ${ text.slice( 0, 400 ) }` );
		}

		return JSON.parse( text ) as T;
	}
}
