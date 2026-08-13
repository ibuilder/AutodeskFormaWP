import type { ApsClient } from '../aps/client.js';
import { applyTemplate, getTemplate } from '../canonical/templates.js';
import { toCanonicalProject } from '../canonical/transform.js';
import { logger } from '../logger.js';
import type { PublishQueue } from './queue.js';

export interface RefreshSummary {
	considered: number;
	refreshed: number;
	unchanged: number;
	failed: number;
	skippedWithoutSource: number;
}

/**
 * Rebuilds and re-publishes every project that was published in sync mode.
 *
 * Called when WordPress asks for a refresh on its cron schedule. Each project
 * is rebuilt from Autodesk and re-enqueued; the queue's content hash means a
 * project whose data has not moved is recorded as unchanged rather than
 * rewritten, so a frequent schedule stays cheap.
 *
 * A project published from a pre-built payload has no stored source
 * descriptor and therefore cannot be rebuilt without the extension. Those are
 * counted and reported rather than silently ignored.
 */
export async function refreshSyncProjects(
	aps: ApsClient,
	queue: PublishQueue
): Promise<RefreshSummary> {
	const published = await queue.published();
	const entries = Object.values( published ).filter( ( entry ) => entry.mode === 'sync' );

	const summary: RefreshSummary = {
		considered: entries.length,
		refreshed: 0,
		unchanged: 0,
		failed: 0,
		skippedWithoutSource: 0,
	};

	for ( const entry of entries ) {
		if ( ! entry.source ) {
			summary.skippedWithoutSource++;
			continue;
		}

		try {
			const source = await aps.buildSourceProject( {
				hubId: entry.source.hubId,
				projectId: entry.source.projectId,
				...( entry.source.proposalId ? { proposalId: entry.source.proposalId } : {} ),
				includeFiles: entry.source.includeFiles ?? true,
				...( entry.source.overrides ? { overrides: entry.source.overrides } : {} ),
			} );

			// Reapply the template the project was originally published with, so
			// an unattended refresh cannot widen what a project exposes.
			const template = getTemplate( entry.source.template );
			const project = toCanonicalProject( source );

			const job = await queue.enqueue( {
				operation: 'update',
				project: template ? applyTemplate( project, template ) : project,
				mode: 'sync',
				source: entry.source,
			} );

			if ( job.status === 'skipped' ) {
				summary.unchanged++;
			} else {
				summary.refreshed++;
			}
		} catch ( error ) {
			summary.failed++;
			logger.warn( 'Could not refresh a sync project', {
				sourceId: entry.sourceId,
				error: error instanceof Error ? error.message : String( error ),
			} );
		}
	}

	if ( summary.skippedWithoutSource > 0 ) {
		logger.warn( 'Some sync projects have no stored source descriptor and were not refreshed', {
			count: summary.skippedWithoutSource,
		} );
	}

	logger.info( 'Sync refresh complete', { ...summary } );

	return summary;
}
