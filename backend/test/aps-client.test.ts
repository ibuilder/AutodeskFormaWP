import assert from 'node:assert/strict';
import { createServer, type Server } from 'node:http';
import { after, before, test } from 'node:test';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * Contract tests for the Autodesk client.
 *
 * A local HTTP server replies with Autodesk Platform Services shaped payloads
 * (JSON:API, with versions carried in `included` and linked through
 * `relationships.tip`). This exercises the whole path — HTTP, parsing,
 * normalization, canonical validation — without needing live credentials.
 */

const dataDir = mkdtempSync( join( tmpdir(), 'forma-aps-' ) );

const HUB_ID = 'b.b1a2c3d4-0000-1111-2222-333344445555';
const PROJECT_ID = 'b.9f8e7d6c-aaaa-bbbb-cccc-ddddeeeeffff';
const FOLDER_ID = 'urn:adsk.wipprod:fs.folder:co.AbCdEfGhIjKlMnOp';
const ITEM_ID = 'urn:adsk.wipprod:dm.lineage:QrStUvWxYz012345';
const VERSION_ID = 'urn:adsk.wipprod:fs.file:vf.QrStUvWxYz012345?version=1';

const requested: string[] = [];

let server: Server;
let baseUrl = '';

function send( response: import( 'node:http' ).ServerResponse, status: number, body: unknown ): void {
	const payload = JSON.stringify( body );

	response.writeHead( status, { 'content-type': 'application/vnd.api+json' } );
	response.end( payload );
}

before( async () => {
	server = createServer( ( request, response ) => {
		const url = new URL( request.url ?? '/', 'http://localhost' );

		// Autodesk requires resource urns to be percent encoded in the path, so
		// the client encodes them. Decode here to match against readable routes.
		const pathname = decodeURIComponent( url.pathname );
		requested.push( pathname );

		if ( request.headers.authorization !== 'Bearer test-access-token' ) {
			send( response, 401, { developerMessage: 'Missing or invalid token' } );

			return;
		}

		if ( pathname === '/project/v1/hubs' ) {
			send( response, 200, {
				data: [
					{ type: 'hubs', id: HUB_ID, attributes: { name: 'Acme Architecture', region: 'US' } },
					{ type: 'hubs', id: 'b.other', attributes: {} },
				],
			} );

			return;
		}

		if ( pathname === `/project/v1/hubs/${ HUB_ID }/projects` ) {
			send( response, 200, {
				data: [
					{ type: 'projects', id: PROJECT_ID, attributes: { name: 'Harbour District' } },
				],
			} );

			return;
		}

		if ( pathname === `/project/v1/hubs/${ HUB_ID }/projects/${ PROJECT_ID }/topFolders` ) {
			send( response, 200, {
				data: [
					{ type: 'folders', id: FOLDER_ID, attributes: { displayName: 'Project Files' } },
				],
			} );

			return;
		}

		if ( pathname === `/data/v1/projects/${ PROJECT_ID }/folders/${ FOLDER_ID }/contents` ) {
			send( response, 200, {
				data: [
					{
						type: 'items',
						id: ITEM_ID,
						attributes: {
							displayName: 'Site Plan.pdf',
							lastModifiedTime: '2026-07-14T09:12:00.000Z',
						},
						// The version urn differs from the item urn; this link is
						// the only reliable way to reach size and mime type.
						relationships: { tip: { data: { type: 'versions', id: VERSION_ID } } },
					},
					{
						type: 'folders',
						id: 'urn:adsk.wipprod:fs.folder:co.NestedFolder',
						attributes: { displayName: 'Subfolder' },
					},
				],
				included: [
					{
						type: 'versions',
						id: VERSION_ID,
						attributes: {
							name: 'Site Plan.pdf',
							storageSize: 204800,
							mimeType: 'application/pdf',
						},
					},
				],
			} );

			return;
		}

		send( response, 404, { developerMessage: 'Not found' } );
	} );

	await new Promise<void>( ( resolve ) => server.listen( 0, '127.0.0.1', resolve ) );

	const address = server.address();
	const port = typeof address === 'object' && address ? address.port : 0;
	baseUrl = `http://127.0.0.1:${ port }`;

	process.env.NODE_ENV = 'test';
	process.env.DATA_DIR = dataDir;
	process.env.ENCRYPTION_KEY = 'b'.repeat( 64 );
	process.env.APS_CLIENT_ID = 'test-client';
	process.env.APS_CLIENT_SECRET = 'test-secret';
	process.env.APS_CALLBACK_URL = 'http://localhost:3000/auth/callback';
	process.env.APS_BASE_URL = baseUrl;
	process.env.WORDPRESS_URL = 'https://example.com';
	process.env.WORDPRESS_KEY_ID = 'fp_test';
	process.env.WORDPRESS_SECRET = 'a-shared-secret-long-enough';
	process.env.EXTENSION_API_KEY = 'an-extension-key-long-enough';
} );

after( async () => {
	await new Promise<void>( ( resolve ) => server.close( () => resolve() ) );
	rmSync( dataDir, { recursive: true, force: true } );
} );

const { ApsClient } = await import( '../dist/aps/client.js' );
const { toCanonicalProject } = await import( '../dist/canonical/transform.js' );
const { projectSchema } = await import( '../dist/canonical/schema.js' );

/** Minimal stand-in for the OAuth store. */
const fakeOAuth = {
	getUserAccessToken: async () => 'test-access-token',
} as never;

const badOAuth = {
	getUserAccessToken: async () => 'wrong-token',
} as never;

test( 'hubs are listed and named', async () => {
	const hubs = await new ApsClient( fakeOAuth ).listHubs();

	assert.equal( hubs.length, 2 );
	assert.equal( hubs[ 0 ]?.id, HUB_ID );
	assert.equal( hubs[ 0 ]?.name, 'Acme Architecture' );
	// A hub with no name attribute must still be usable.
	assert.equal( hubs[ 1 ]?.name, 'b.other' );
} );

test( 'projects are listed for a hub', async () => {
	const projects = await new ApsClient( fakeOAuth ).listProjects( HUB_ID );

	assert.equal( projects.length, 1 );
	assert.equal( projects[ 0 ]?.id, PROJECT_ID );
	assert.equal( projects[ 0 ]?.name, 'Harbour District' );
	assert.equal( projects[ 0 ]?.hubId, HUB_ID );
} );

test( 'top folders are listed', async () => {
	const folders = await new ApsClient( fakeOAuth ).listTopFolders( HUB_ID, PROJECT_ID );

	assert.equal( folders.length, 1 );
	assert.equal( folders[ 0 ]?.id, FOLDER_ID );
	assert.equal( folders[ 0 ]?.name, 'Project Files' );
} );

test( 'folder contents resolve size and mime type through the version link', async () => {
	const contents = await new ApsClient( fakeOAuth ).listFolderContents( PROJECT_ID, FOLDER_ID );

	// Folders are not publishable assets and must be filtered out.
	assert.equal( contents.length, 1 );

	const file = contents[ 0 ]!;
	assert.equal( file.name, 'Site Plan.pdf' );
	assert.equal(
		file.storageSize,
		204800,
		'size must be read from the linked version, not the item id'
	);
	assert.equal( file.mimeType, 'application/pdf' );
	assert.equal( file.lastModifiedTime, '2026-07-14T09:12:00.000Z' );
} );

test( 'a source project is assembled and passes canonical validation', async () => {
	const client = new ApsClient( fakeOAuth );

	const source = await client.buildSourceProject( {
		hubId: HUB_ID,
		projectId: PROJECT_ID,
		includeFiles: true,
	} );

	const project = toCanonicalProject( source );

	// Must satisfy the same schema the WordPress plugin enforces.
	assert.doesNotThrow( () => projectSchema.parse( project ) );

	assert.equal( project.title, 'Harbour District' );
	assert.equal( project.hub_id, HUB_ID );
	assert.equal( project.project_id, PROJECT_ID );
	assert.equal( project.assets?.length, 1 );

	const asset = project.assets![ 0 ]!;
	assert.equal( asset.title, 'Site Plan.pdf' );
	assert.equal( asset.kind, 'document', 'kind is inferred from the resolved mime type' );
	assert.equal( asset.size, 204800 );
	assert.equal( asset.mime_type, 'application/pdf' );
} );

test( 'metrics supplied by the extension are merged into the canonical project', async () => {
	const client = new ApsClient( fakeOAuth );

	const source = await client.buildSourceProject( {
		hubId: HUB_ID,
		projectId: PROJECT_ID,
		includeFiles: false,
		overrides: {
			metrics: { gfa: 48250.5, sun_hours: 4.8, embodied_carbon: 315 },
			tags: [ 'Waterfront' ],
		},
	} );

	const project = toCanonicalProject( source );
	const byKey = Object.fromEntries( ( project.metrics ?? [] ).map( ( m ) => [ m.key, m ] ) );

	assert.equal( byKey.gfa?.label, 'Gross floor area' );
	assert.equal( byKey.gfa?.unit, 'm²' );
	assert.equal( byKey.sun_hours?.category, 'Environment' );
	assert.equal( byKey.embodied_carbon?.category, 'Carbon' );
	assert.deepEqual( project.tags, [ 'Waterfront' ] );
} );

test( 'a project missing from the hub is reported clearly', async () => {
	await assert.rejects(
		new ApsClient( fakeOAuth ).buildSourceProject( {
			hubId: HUB_ID,
			projectId: 'b.does-not-exist',
			includeFiles: false,
		} ),
		/was not found in hub/
	);
} );

test( 'a file listing failure does not fail the whole build', async () => {
	const client = new ApsClient( fakeOAuth );

	// An unknown project id would make the folder calls 404; the build still
	// needs to succeed with no assets rather than throw.
	const source = await client.buildSourceProject( {
		hubId: HUB_ID,
		projectId: PROJECT_ID,
		includeFiles: true,
		overrides: { files: undefined },
	} );

	assert.ok( source );
} );

test( 'an unauthorized response surfaces the status', async () => {
	await assert.rejects( new ApsClient( badOAuth ).listHubs(), /HTTP 401/ );
} );

test( 'requests are addressed to the documented endpoints', () => {
	assert.ok( requested.includes( '/project/v1/hubs' ) );
	assert.ok( requested.includes( `/project/v1/hubs/${ HUB_ID }/projects` ) );
	assert.ok( requested.includes( `/project/v1/hubs/${ HUB_ID }/projects/${ PROJECT_ID }/topFolders` ) );
	assert.ok(
		requested.includes( `/data/v1/projects/${ PROJECT_ID }/folders/${ FOLDER_ID }/contents` )
	);
} );
