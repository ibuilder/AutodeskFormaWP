/**
 * Thin wrapper around the Forma embedded view SDK.
 *
 * The extension must also run in a plain browser tab during development, where
 * no Forma host is present, so every SDK call is guarded and falls back to a
 * clearly labelled local context instead of throwing.
 */

export interface FormaContext {
	insideForma: boolean;
	hubId: string;
	projectId: string;
	proposalId: string;
	projectName: string;
}

interface FormaSdkShape {
	getProjectId?: () => Promise<string> | string;
	proposal?: { getName?: () => Promise<string> };
	project?: { get?: () => Promise<{ id?: string; name?: string; hubId?: string }> };
}

async function settle< T >( value: undefined | ( () => Promise< T > | T ) ): Promise< T | null > {
	if ( typeof value !== 'function' ) {
		return null;
	}

	try {
		return await value();
	} catch {
		return null;
	}
}

/**
 * Reads the identifiers the backend needs in order to build a publish payload.
 *
 * Forma exposes these through the embedded view SDK; when a value cannot be
 * resolved the caller can still supply it manually in the UI.
 */
export async function readFormaContext(): Promise< FormaContext > {
	const params = new URLSearchParams( window.location.search );

	const fallback: FormaContext = {
		insideForma: false,
		hubId: params.get( 'hubId' ) ?? '',
		projectId: params.get( 'projectId' ) ?? '',
		proposalId: params.get( 'proposalId' ) ?? '',
		projectName: '',
	};

	let sdk: FormaSdkShape | null = null;

	try {
		const module = ( await import( 'forma-embedded-view-sdk/auto' ) ) as { Forma?: FormaSdkShape };
		sdk = module.Forma ?? null;
	} catch {
		// Running outside Forma, or the SDK is not installed yet.
		return fallback;
	}

	if ( ! sdk ) {
		return fallback;
	}

	const projectId = await settle( sdk.getProjectId );
	const project = await settle( sdk.project?.get );
	const proposalName = await settle( sdk.proposal?.getName );

	const resolved: FormaContext = {
		insideForma: true,
		hubId: project?.hubId ?? fallback.hubId,
		projectId: projectId ?? project?.id ?? fallback.projectId,
		proposalId: fallback.proposalId,
		projectName: proposalName ?? project?.name ?? '',
	};

	return resolved;
}
