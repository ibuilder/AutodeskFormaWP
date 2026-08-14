import type { PublishQueue } from '../jobs/queue.js';

/**
 * Prometheus text exposition of publish activity.
 *
 * Deliberately small. These are the numbers that answer "is publishing
 * working, and is anything stuck?" — the questions an alert should be built
 * on. Anything finer belongs in the audit log, which already records every
 * operation with its outcome.
 */
export async function renderMetrics( queue: PublishQueue ): Promise<string> {
	const stats = await queue.stats();
	const now = Math.floor( Date.now() / 1000 );

	const lines: string[] = [];

	const metric = ( name: string, help: string, type: string, samples: Array<[ string, number ]> ) => {
		lines.push( `# HELP ${ name } ${ help }` );
		lines.push( `# TYPE ${ name } ${ type }` );

		for ( const [ labels, value ] of samples ) {
			lines.push( `${ name }${ labels }${ Number.isFinite( value ) ? ` ${ value }` : ' 0' }` );
		}
	};

	metric(
		'forma_publish_jobs',
		'Publish jobs in recent history by status.',
		'gauge',
		Object.entries( stats.byStatus ).map( ( [ status, count ] ) => [ `{status="${ status }"}`, count ] )
	);

	metric( 'forma_publish_jobs_total', 'Publish jobs retained in history.', 'gauge', [ [ '', stats.total ] ] );

	metric(
		'forma_publish_queue_depth',
		'Jobs waiting to be sent to WordPress.',
		'gauge',
		[ [ '', stats.queueDepth ] ]
	);

	metric(
		'forma_published_projects',
		'Projects currently recorded as published.',
		'gauge',
		[ [ '', stats.published ] ]
	);

	metric(
		'forma_sync_tracked_projects',
		'Published projects tracked for scheduled refresh.',
		'gauge',
		[ [ '', stats.syncTracked ] ]
	);

	/*
	 * Seconds since the last outcome is more directly alertable than a
	 * timestamp: "no successful publish in 24h" is a threshold, whereas a raw
	 * epoch needs arithmetic in every alert rule.
	 */
	metric(
		'forma_seconds_since_last_success',
		'Seconds since the last successful publish. -1 when none has succeeded.',
		'gauge',
		[ [ '', stats.lastSuccessAt > 0 ? now - Math.floor( stats.lastSuccessAt / 1000 ) : -1 ] ]
	);

	metric(
		'forma_seconds_since_last_failure',
		'Seconds since the last failed publish. -1 when none has failed.',
		'gauge',
		[ [ '', stats.lastFailureAt > 0 ? now - Math.floor( stats.lastFailureAt / 1000 ) : -1 ] ]
	);

	metric(
		'forma_process_uptime_seconds',
		'Time since the service started.',
		'gauge',
		[ [ '', Math.floor( process.uptime() ) ] ]
	);

	return `${ lines.join( '\n' ) }\n`;
}
