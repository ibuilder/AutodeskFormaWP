/**
 * Live verification against real Autodesk and WordPress credentials.
 *
 *     npm run verify
 *
 * The automated suites prove the code against recorded Autodesk response
 * shapes. This command proves it against your actual tenant: it reads your
 * configuration, exercises each dependency in turn and reports precisely which
 * part of the pipeline works and which does not. It performs no writes.
 */
import { ApsClient } from '../aps/client.js';
import { ApsOAuth } from '../aps/oauth.js';
import { toCanonicalProject } from '../canonical/transform.js';
import { projectSchema } from '../canonical/schema.js';
import { getConfig } from '../config.js';
import { closeStores } from '../store/factory.js';
import { WordPressClient } from '../wordpress/client.js';

const PASS = '  [ ok ]';
const FAIL = '  [fail]';
const WARN = '  [warn]';
const INFO = '       ';

let failures = 0;

function out( line: string ): void {
	process.stdout.write( `${ line }\n` );
}

function pass( message: string ): void {
	out( `${ PASS } ${ message }` );
}

function fail( message: string, detail?: unknown ): void {
	failures++;
	out( `${ FAIL } ${ message }` );

	if ( detail !== undefined ) {
		out( `${ INFO } ${ detail instanceof Error ? detail.message : String( detail ) }` );
	}
}

function warn( message: string ): void {
	out( `${ WARN } ${ message }` );
}

function info( message: string ): void {
	out( `${ INFO } ${ message }` );
}

function heading( title: string ): void {
	out( `\n${ title }` );
	out( '-'.repeat( Math.max( 20, title.length ) ) );
}

async function main(): Promise<void> {
	out( 'Forma to WordPress — live verification' );
	out( 'No content is published and nothing is written to Autodesk.' );

	heading( '1. Configuration' );

	let config;

	try {
		config = getConfig();
		pass( 'environment is complete and valid' );
		info( `Autodesk base URL: ${ config.APS_BASE_URL }` );
		info( `WordPress URL:     ${ config.WORDPRESS_URL }` );
		info( `Storage:           ${ config.DATABASE_URL ? 'PostgreSQL' : `JSON files in ${ config.DATA_DIR }` }` );
	} catch ( error ) {
		fail( 'the environment is incomplete', error );
		out( '\nFix the configuration and run again.' );
		process.exitCode = 1;

		return;
	}

	heading( '2. Autodesk session' );

	const oauth = new ApsOAuth();
	let hasSession = false;

	try {
		hasSession = await oauth.hasUserSession();
	} catch ( error ) {
		fail( 'could not read the stored session', error );
	}

	if ( ! hasSession ) {
		fail( 'no Autodesk session is stored' );
		info( `Sign in first, then run this again: ${ config.APS_CALLBACK_URL.replace( /\/auth\/callback.*$/, '' ) }/auth/login` );
	} else {
		try {
			const token = await oauth.getUserAccessToken();
			pass( `a usable access token was obtained (${ token.length } characters, not shown)` );
		} catch ( error ) {
			fail( 'the stored session could not produce a token', error );
			hasSession = false;
		}
	}

	heading( '3. Autodesk data access' );

	const client = new ApsClient( oauth );
	let firstHub = '';
	let firstProject = '';

	if ( hasSession ) {
		try {
			const hubs = await client.listHubs();

			if ( hubs.length === 0 ) {
				warn( 'the account can see no hubs; check the entitlements on this APS application' );
			} else {
				pass( `${ hubs.length } hub(s) visible` );

				for ( const hub of hubs.slice( 0, 5 ) ) {
					info( `${ hub.id }  ${ hub.name }` );
				}

				firstHub = hubs[ 0 ]?.id ?? '';
			}
		} catch ( error ) {
			fail( 'listing hubs failed', error );
		}

		if ( firstHub ) {
			try {
				const projects = await client.listProjects( firstHub );

				if ( projects.length === 0 ) {
					warn( 'the first hub contains no visible projects' );
				} else {
					pass( `${ projects.length } project(s) visible in the first hub` );

					for ( const project of projects.slice( 0, 5 ) ) {
						info( `${ project.id }  ${ project.name }` );
					}

					firstProject = projects[ 0 ]?.id ?? '';
				}
			} catch ( error ) {
				fail( 'listing projects failed', error );
			}
		}
	} else {
		warn( 'skipped: no Autodesk session' );
	}

	heading( '4. Canonical payload' );

	if ( firstHub && firstProject ) {
		try {
			const source = await client.buildSourceProject( {
				hubId: firstHub,
				projectId: firstProject,
				includeFiles: true,
			} );

			const project = toCanonicalProject( source );
			const parsed = projectSchema.safeParse( project );

			if ( parsed.success ) {
				pass( 'a canonical project was built and validates against the shared schema' );
			} else {
				fail( 'the built project does not satisfy the canonical schema' );

				for ( const issue of parsed.error.issues.slice( 0, 5 ) ) {
					info( `${ issue.path.join( '.' ) }: ${ issue.message }` );
				}
			}

			info( `title:   ${ project.title }` );
			info( `metrics: ${ project.metrics?.length ?? 0 }` );
			info( `assets:  ${ project.assets?.length ?? 0 }` );

			const assets = project.assets ?? [];

			if ( assets.length === 0 ) {
				warn( 'no assets were resolved; the account may lack access to the project files' );
			} else {
				const sized = assets.filter( ( asset ) => typeof asset.size === 'number' && asset.size > 0 );
				const typed = assets.filter( ( asset ) => Boolean( asset.mime_type ) );

				if ( sized.length === 0 ) {
					warn( 'no asset resolved a file size; the version link may differ in your tenant' );
				} else {
					pass( `${ sized.length }/${ assets.length } asset(s) resolved a file size` );
				}

				if ( typed.length === 0 ) {
					warn( 'no asset resolved a MIME type; kinds will fall back to extension matching' );
				} else {
					pass( `${ typed.length }/${ assets.length } asset(s) resolved a MIME type` );
				}
			}

			if ( ( project.metrics?.length ?? 0 ) === 0 ) {
				warn(
					'no metrics were found. Forma analysis outputs are supplied by the extension through ' +
						'the overrides field; confirm the extension is sending them for this project.'
				);
			}
		} catch ( error ) {
			fail( 'building a canonical project failed', error );
		}
	} else {
		warn( 'skipped: no hub and project to build from' );
	}

	heading( '5. WordPress receiver' );

	try {
		const status = ( await new WordPressClient().status() ) as Record<string, unknown>;

		pass( 'the signed request was accepted by WordPress' );
		info( `plugin version: ${ String( status.plugin_version ?? 'unknown' ) }` );
		info( `schema version: ${ String( status.schema_version ?? 'unknown' ) }` );
		info( `site:           ${ String( status.site_url ?? 'unknown' ) }` );

		const remote = Number( status.server_time ?? 0 );
		const skew = Math.abs( Math.floor( Date.now() / 1000 ) - remote );

		if ( remote > 0 && skew > 60 ) {
			warn( `clocks differ by about ${ skew }s; large drift will cause stale-request rejections` );
		} else if ( remote > 0 ) {
			pass( `clocks agree within ${ skew }s` );
		}
	} catch ( error ) {
		fail( 'WordPress rejected or could not be reached', error );
		info( 'Check WORDPRESS_URL, WORDPRESS_KEY_ID and WORDPRESS_SECRET against Forma → Connections.' );
	}

	heading( 'Summary' );

	if ( failures === 0 ) {
		out( 'All checks passed. The pipeline is wired correctly end to end.' );
	} else {
		out( `${ failures } check(s) failed. See the detail above.` );
		process.exitCode = 1;
	}

	await closeStores();
}

main().catch( ( error ) => {
	out( `\nUnexpected failure: ${ error instanceof Error ? error.message : String( error ) }` );
	process.exitCode = 1;
} );
