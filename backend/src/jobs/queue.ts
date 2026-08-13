import { randomUUID } from 'node:crypto';
import { getConfig } from '../config.js';
import { logger } from '../logger.js';
import { createStore } from '../store/factory.js';
import type { DocumentStore } from '../store/document-store.js';
import { WordPressClient, WordPressError } from '../wordpress/client.js';
import { SCHEMA_VERSION, type CanonicalPayload, type Operation } from '../canonical/schema.js';
import type { CanonicalProject } from '../canonical/schema.js';
import { projectHash } from '../canonical/transform.js';

export type JobStatus = 'queued' | 'running' | 'succeeded' | 'failed' | 'skipped';

export interface PublishJob {
	id: string;
	operation: Operation;
	mode: 'snapshot' | 'sync';
	sourceId: string;
	title: string;
	status: JobStatus;
	attempts: number;
	maxAttempts: number;
	createdAt: string;
	updatedAt: string;
	payloadHash: string;
	error?: string;
	result?: Record<string, unknown>;
}

/** Where a published project came from, so a sync refresh can rebuild it. */
export interface SourceDescriptor {
	hubId: string;
	projectId: string;
	proposalId?: string;
	includeFiles?: boolean;
	overrides?: Record<string, unknown>;
	/** Publishing template applied when this project was first published. */
	template?: string;
}

export interface PublishedEntry {
	sourceId: string;
	postId?: number;
	permalink?: string;
	payloadHash: string;
	syncedAt: string;
	mode: string;
	/**
	 * Retained for sync mode so a scheduled refresh can rebuild the project
	 * from Autodesk without the extension being open.
	 */
	source?: SourceDescriptor;
}

interface JobFile {
	jobs: PublishJob[];
	published: Record<string, PublishedEntry>;
}

const MAX_HISTORY = 500;

/**
 * In-process publish queue with bounded retries and exponential backoff.
 *
 * Jobs are persisted so that history survives a restart. A production
 * deployment would swap this for a durable queue; the public surface is
 * intentionally small so that substitution is straightforward.
 */
export class PublishQueue {
	private readonly store: DocumentStore<JobFile>;
	private readonly client: WordPressClient;
	private running = false;
	private readonly waiting: string[] = [];

	constructor( client?: WordPressClient ) {
		const config = getConfig();

		this.store = createStore<JobFile>( 'jobs', { jobs: [], published: {} } );
		this.client = client ?? new WordPressClient();
	}

	/** Queues a publish operation and starts the worker. */
	async enqueue( options: {
		operation: Operation;
		project: CanonicalProject;
		mode?: 'snapshot' | 'sync';
		force?: boolean;
		source?: SourceDescriptor;
	} ): Promise<PublishJob> {
		const config = getConfig();
		const hash = projectHash( options.project );
		const now = new Date().toISOString();

		const job: PublishJob = {
			id: randomUUID(),
			operation: options.operation,
			mode: options.mode ?? 'snapshot',
			sourceId: options.project.source_id,
			title: options.project.title,
			status: 'queued',
			attempts: 0,
			maxAttempts: config.JOB_MAX_ATTEMPTS,
			createdAt: now,
			updatedAt: now,
			payloadHash: hash,
		};

		const shouldSkip = await this.store.mutate( ( state ) => {
			const previous = state.published[ options.project.source_id ];
			const isWrite = options.operation === 'publish' || options.operation === 'update';

			if ( ! options.force && isWrite && previous?.payloadHash === hash ) {
				job.status = 'skipped';
				job.result = { status: 'unchanged', reason: 'payload matches the last published version' };
			}

			state.jobs.unshift( job );
			state.jobs.splice( MAX_HISTORY );

			return job.status === 'skipped';
		} );

		if ( shouldSkip ) {
			logger.info( 'Skipped publish, payload unchanged', { sourceId: job.sourceId } );

			return job;
		}

		this.payloads.set( job.id, {
			schema_version: SCHEMA_VERSION,
			operation: options.operation,
			mode: job.mode,
			job_id: job.id,
			generated_at: now,
			project: options.project,
		} );

		if ( options.source ) {
			this.sources.set( job.id, options.source );
		}

		this.waiting.push( job.id );
		void this.drain();

		return job;
	}

	/** Returns recent jobs, newest first. */
	async list( limit = 50 ): Promise<PublishJob[]> {
		const state = await this.store.read();

		return state.jobs.slice( 0, Math.max( 1, Math.min( MAX_HISTORY, limit ) ) );
	}

	/** Returns one job by id. */
	async get( id: string ): Promise<PublishJob | null> {
		const state = await this.store.read();

		return state.jobs.find( ( job ) => job.id === id ) ?? null;
	}

	/** Returns the published entry index, keyed by source id. */
	async published(): Promise<JobFile[ 'published' ]> {
		const state = await this.store.read();

		return state.published;
	}

	/** Waits until the queue is idle. Used by tests and the refresh endpoint. */
	async settle(): Promise<void> {
		while ( this.running || this.waiting.length > 0 ) {
			await new Promise( ( resolve ) => setTimeout( resolve, 25 ) );
		}
	}

	private readonly payloads = new Map<string, CanonicalPayload>();
	private readonly sources = new Map<string, SourceDescriptor>();

	private async drain(): Promise<void> {
		if ( this.running ) {
			return;
		}

		this.running = true;

		try {
			while ( this.waiting.length > 0 ) {
				const jobId = this.waiting.shift();

				if ( ! jobId ) {
					continue;
				}

				await this.run( jobId );
			}
		} finally {
			this.running = false;
		}
	}

	private async run( jobId: string ): Promise<void> {
		const config = getConfig();
		const payload = this.payloads.get( jobId );

		if ( ! payload ) {
			return;
		}

		for ( let attempt = 1; attempt <= config.JOB_MAX_ATTEMPTS; attempt++ ) {
			await this.update( jobId, ( job ) => {
				job.status = 'running';
				job.attempts = attempt;
			} );

			try {
				const response = await this.client.ingest( payload );

				await this.store.mutate( ( state ) => {
					const job = state.jobs.find( ( entry ) => entry.id === jobId );

					if ( job ) {
						job.status = 'succeeded';
						job.updatedAt = new Date().toISOString();
						job.result = ( response.result ?? {} ) as Record<string, unknown>;
						delete job.error;
					}

					const isWrite = payload.operation === 'publish' || payload.operation === 'update';

					if ( isWrite ) {
						const previous = state.published[ payload.project.source_id ];
						// Keep a previously recorded descriptor when this publish
						// came from a pre-built payload with no source attached.
						const source = this.sources.get( jobId ) ?? previous?.source;

						state.published[ payload.project.source_id ] = {
							sourceId: payload.project.source_id,
							...( response.result?.post_id ? { postId: response.result.post_id } : {} ),
							...( response.result?.permalink ? { permalink: response.result.permalink } : {} ),
							payloadHash: projectHash( payload.project ),
							syncedAt: new Date().toISOString(),
							mode: payload.mode,
							...( source ? { source } : {} ),
						};
					} else {
						delete state.published[ payload.project.source_id ];
					}
				} );

				this.payloads.delete( jobId );
				this.sources.delete( jobId );
				logger.info( 'Publish job succeeded', { jobId, sourceId: payload.project.source_id } );

				return;
			} catch ( error ) {
				const retryable = error instanceof WordPressError ? error.retryable : true;
				const message = error instanceof Error ? error.message : String( error );
				const last = attempt >= config.JOB_MAX_ATTEMPTS;

				logger.warn( 'Publish attempt failed', { jobId, attempt, retryable, error: message } );

				if ( ! retryable || last ) {
					await this.update( jobId, ( job ) => {
						job.status = 'failed';
						job.error = message;
					} );

					this.payloads.delete( jobId );
					this.sources.delete( jobId );

					return;
				}

				const delay = config.JOB_BASE_DELAY_MS * 2 ** ( attempt - 1 );
				const jitter = Math.floor( Math.random() * config.JOB_BASE_DELAY_MS );

				await new Promise( ( resolve ) => setTimeout( resolve, delay + jitter ) );
			}
		}
	}

	private async update( jobId: string, mutator: ( job: PublishJob ) => void ): Promise<void> {
		await this.store.mutate( ( state ) => {
			const job = state.jobs.find( ( entry ) => entry.id === jobId );

			if ( job ) {
				mutator( job );
				job.updatedAt = new Date().toISOString();
			}
		} );
	}
}
