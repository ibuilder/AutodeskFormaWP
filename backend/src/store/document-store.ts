/**
 * The storage contract the rest of the service depends on.
 *
 * Deliberately tiny: a named JSON document that can be read, and mutated under
 * a lock. Anything richer would leak storage concerns into the job queue and
 * the token store.
 */
export interface DocumentStore<T extends object> {
	/** Returns the current document, or the fallback when it does not exist. */
	read(): Promise<T>;

	/**
	 * Applies a mutation and persists the result.
	 *
	 * Implementations must serialize concurrent mutations so two callers cannot
	 * interleave a read-modify-write cycle and lose an update.
	 */
	mutate<R>( mutator: ( state: T ) => R | Promise<R> ): Promise<R>;

	/** Releases any resources held by the store. */
	close?(): Promise<void>;
}
