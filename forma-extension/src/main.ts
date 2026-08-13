import './styles.css';
import {
	ApiError,
	BackendClient,
	loadSettings,
	saveSettings,
	type CanonicalProject,
	type PublishJob,
} from './lib/api.js';
import { readFormaContext, type FormaContext } from './lib/forma.js';

type TabId = 'content' | 'preview' | 'connection' | 'history';

interface State {
	tab: TabId;
	context: FormaContext;
	settings: ReturnType< typeof loadSettings >;
	preview: CanonicalProject | null;
	jobs: PublishJob[];
	message: { kind: 'ok' | 'err'; text: string } | null;
	busy: boolean;
	includeFiles: boolean;
	mode: 'snapshot' | 'sync';
	postStatus: 'draft' | 'publish' | 'pending' | 'private';
	backendConnected: boolean | null;
}

const state: State = {
	tab: 'content',
	context: { insideForma: false, hubId: '', projectId: '', proposalId: '', projectName: '' },
	settings: loadSettings(),
	preview: null,
	jobs: [],
	message: null,
	busy: false,
	includeFiles: true,
	mode: 'snapshot',
	postStatus: 'draft',
	backendConnected: null,
};

const client = new BackendClient( state.settings );
const root = document.getElementById( 'app' );

/** Creates an element with text content assigned safely. */
function element< K extends keyof HTMLElementTagNameMap >(
	tag: K,
	options: { className?: string; text?: string; attrs?: Record< string, string > } = {}
): HTMLElementTagNameMap[ K ] {
	const node = document.createElement( tag );

	if ( options.className ) {
		node.className = options.className;
	}

	if ( options.text !== undefined ) {
		node.textContent = options.text;
	}

	for ( const [ name, value ] of Object.entries( options.attrs ?? {} ) ) {
		node.setAttribute( name, value );
	}

	return node;
}

function field( label: string, input: HTMLElement ): HTMLElement {
	const wrapper = element( 'div', { className: 'field' } );
	const labelNode = element( 'label', { text: label } );
	const id = `f-${ Math.random().toString( 36 ).slice( 2, 9 ) }`;

	input.id = id;
	labelNode.setAttribute( 'for', id );
	wrapper.append( labelNode, input );

	return wrapper;
}

function textInput( value: string, onInput: ( value: string ) => void, type = 'text' ): HTMLInputElement {
	const input = element( 'input' );

	input.type = type;
	input.value = value;
	input.addEventListener( 'input', () => onInput( input.value ) );

	return input;
}

function setMessage( kind: 'ok' | 'err', text: string ): void {
	state.message = { kind, text };
	render();
}

async function guard( work: () => Promise< void > ): Promise< void > {
	state.busy = true;
	state.message = null;
	render();

	try {
		await work();
	} catch ( error ) {
		const text =
			error instanceof ApiError || error instanceof Error ? error.message : String( error );
		state.message = { kind: 'err', text };
	} finally {
		state.busy = false;
		render();
	}
}

function renderContent(): HTMLElement {
	const panel = element( 'div', { className: 'panel' } );

	const intro = element( 'p', {
		className: 'muted',
		text: state.context.insideForma
			? 'Content is read from the current Forma project through the backend service.'
			: 'Running outside Forma. Enter the hub and project identifiers manually.',
	} );

	panel.append( intro );

	panel.append(
		field(
			'Hub ID',
			textInput( state.context.hubId, ( value ) => {
				state.context.hubId = value;
			} )
		)
	);

	panel.append(
		field(
			'Project ID',
			textInput( state.context.projectId, ( value ) => {
				state.context.projectId = value;
			} )
		)
	);

	panel.append(
		field(
			'Proposal ID (optional)',
			textInput( state.context.proposalId, ( value ) => {
				state.context.proposalId = value;
			} )
		)
	);

	const filesToggle = element( 'input' );
	filesToggle.type = 'checkbox';
	filesToggle.checked = state.includeFiles;
	filesToggle.addEventListener( 'change', () => {
		state.includeFiles = filesToggle.checked;
	} );

	const filesLabel = element( 'label' );
	filesLabel.append( filesToggle, document.createTextNode( ' Include project files as assets' ) );
	panel.append( filesLabel );

	const modeSelect = element( 'select' );

	for ( const [ value, label ] of [
		[ 'snapshot', 'Snapshot — copy content at publish time' ],
		[ 'sync', 'Sync — keep a live link for scheduled refresh' ],
	] as const ) {
		const option = element( 'option', { text: label } );
		option.value = value;
		option.selected = state.mode === value;
		modeSelect.append( option );
	}

	modeSelect.addEventListener( 'change', () => {
		state.mode = modeSelect.value === 'sync' ? 'sync' : 'snapshot';
	} );

	panel.append( field( 'Publishing mode', modeSelect ) );

	const statusSelect = element( 'select' );

	for ( const [ value, label ] of [
		[ 'draft', 'Draft' ],
		[ 'pending', 'Pending review' ],
		[ 'publish', 'Published' ],
		[ 'private', 'Private' ],
	] as const ) {
		const option = element( 'option', { text: label } );
		option.value = value;
		option.selected = state.postStatus === value;
		statusSelect.append( option );
	}

	statusSelect.addEventListener( 'change', () => {
		state.postStatus = statusSelect.value as State[ 'postStatus' ];
	} );

	panel.append( field( 'WordPress status', statusSelect ) );

	const row = element( 'div', { className: 'row' } );
	const previewButton = element( 'button', { className: 'action', text: 'Build preview' } );
	previewButton.disabled = state.busy;
	previewButton.addEventListener( 'click', () => void buildPreview() );

	const publishButton = element( 'button', { className: 'action secondary', text: 'Publish now' } );
	publishButton.disabled = state.busy;
	publishButton.addEventListener( 'click', () => void publish( 'publish' ) );

	row.append( previewButton, publishButton );
	panel.append( row );

	return panel;
}

function renderPreview(): HTMLElement {
	const panel = element( 'div', { className: 'panel' } );

	if ( ! state.preview ) {
		panel.append(
			element( 'p', { className: 'muted', text: 'Build a preview on the Content tab to see the output.' } )
		);

		return panel;
	}

	const project = state.preview;
	const header = element( 'div', { className: 'card' } );

	header.append( element( 'h3', { text: project.title } ) );
	header.append( element( 'p', { className: 'muted', text: project.summary ?? 'No summary.' } ) );
	header.append( element( 'p', { className: 'muted', text: `Source ID: ${ project.source_id }` } ) );
	panel.append( header );

	if ( project.metrics && project.metrics.length > 0 ) {
		const card = element( 'div', { className: 'card' } );
		card.append( element( 'h3', { text: `Metrics (${ project.metrics.length })` } ) );

		const grid = element( 'div', { className: 'metric-grid' } );

		for ( const metric of project.metrics.slice( 0, 24 ) ) {
			const cell = element( 'div', { className: 'metric' } );
			const value =
				metric.value === null || metric.value === undefined ? '—' : String( metric.value );

			cell.append(
				element( 'strong', { text: metric.unit ? `${ value } ${ metric.unit }` : value } ),
				element( 'span', { text: metric.label ?? metric.key } )
			);
			grid.append( cell );
		}

		card.append( grid );
		panel.append( card );
	}

	if ( project.assets && project.assets.length > 0 ) {
		const card = element( 'div', { className: 'card' } );
		card.append( element( 'h3', { text: `Assets (${ project.assets.length })` } ) );

		const table = element( 'table' );
		const head = element( 'tr' );
		head.append( element( 'th', { text: 'Title' } ), element( 'th', { text: 'Kind' } ) );
		table.append( head );

		for ( const asset of project.assets.slice( 0, 50 ) ) {
			const row = element( 'tr' );
			row.append(
				element( 'td', { text: asset.title } ),
				element( 'td', { text: asset.kind ?? 'document' } )
			);
			table.append( row );
		}

		card.append( table );
		panel.append( card );
	}

	const row = element( 'div', { className: 'row' } );
	const publishButton = element( 'button', { className: 'action', text: 'Publish this preview' } );
	publishButton.disabled = state.busy;
	publishButton.addEventListener( 'click', () => void publish( 'publish' ) );

	const updateButton = element( 'button', { className: 'action secondary', text: 'Update existing' } );
	updateButton.disabled = state.busy;
	updateButton.addEventListener( 'click', () => void publish( 'update' ) );

	const unpublishButton = element( 'button', { className: 'action secondary', text: 'Unpublish' } );
	unpublishButton.disabled = state.busy;
	unpublishButton.addEventListener( 'click', () => void publish( 'unpublish' ) );

	row.append( publishButton, updateButton, unpublishButton );
	panel.append( row );

	return panel;
}

function renderConnection(): HTMLElement {
	const panel = element( 'div', { className: 'panel' } );

	panel.append(
		field(
			'Backend service URL',
			textInput(
				state.settings.baseUrl,
				( value ) => {
					state.settings.baseUrl = value;
				},
				'url'
			)
		)
	);

	panel.append(
		field(
			'Backend API key',
			textInput(
				state.settings.apiKey,
				( value ) => {
					state.settings.apiKey = value;
				},
				'password'
			)
		)
	);

	const row = element( 'div', { className: 'row' } );

	const saveButton = element( 'button', { className: 'action', text: 'Save and test' } );
	saveButton.disabled = state.busy;
	saveButton.addEventListener( 'click', () => {
		saveSettings( state.settings );
		client.update( state.settings );
		void checkConnection();
	} );

	const autodeskButton = element( 'button', {
		className: 'action secondary',
		text: 'Connect Autodesk account',
	} );
	autodeskButton.addEventListener( 'click', () => {
		if ( ! client.configured ) {
			setMessage( 'err', 'Save the backend URL and API key first.' );

			return;
		}

		window.open( client.loginUrl(), '_blank', 'noopener,noreferrer' );
	} );

	row.append( saveButton, autodeskButton );
	panel.append( row );

	const card = element( 'div', { className: 'card' } );
	card.append( element( 'h3', { text: 'Status' } ) );

	const backendText =
		state.backendConnected === null
			? 'Not tested yet.'
			: state.backendConnected
				? 'Backend reachable.'
				: 'Backend unreachable.';

	card.append( element( 'p', { className: 'muted', text: backendText } ) );
	card.append(
		element( 'p', {
			className: 'muted',
			text: state.context.insideForma ? 'Running inside Forma.' : 'Running outside Forma.',
		} )
	);
	panel.append( card );

	return panel;
}

function renderHistory(): HTMLElement {
	const panel = element( 'div', { className: 'panel' } );

	const refresh = element( 'button', { className: 'action secondary', text: 'Refresh' } );
	refresh.disabled = state.busy;
	refresh.addEventListener( 'click', () => void loadJobs() );
	panel.append( refresh );

	if ( state.jobs.length === 0 ) {
		panel.append( element( 'p', { className: 'muted', text: 'No publish jobs recorded yet.' } ) );

		return panel;
	}

	const table = element( 'table' );
	const head = element( 'tr' );

	head.append(
		element( 'th', { text: 'When' } ),
		element( 'th', { text: 'Operation' } ),
		element( 'th', { text: 'Project' } ),
		element( 'th', { text: 'Status' } )
	);
	table.append( head );

	for ( const job of state.jobs ) {
		const row = element( 'tr' );
		const statusCell = element( 'td' );

		statusCell.append( element( 'span', { className: `pill ${ job.status }`, text: job.status } ) );

		if ( job.error ) {
			statusCell.append( element( 'div', { className: 'muted', text: job.error } ) );
		}

		row.append(
			element( 'td', { text: new Date( job.updatedAt ).toLocaleString() } ),
			element( 'td', { text: job.operation } ),
			element( 'td', { text: job.title } ),
			statusCell
		);
		table.append( row );
	}

	panel.append( table );

	return panel;
}

function render(): void {
	if ( ! root ) {
		return;
	}

	root.replaceChildren();

	const tabs = element( 'div', { className: 'tabs', attrs: { role: 'tablist' } } );

	for ( const [ id, label ] of [
		[ 'content', 'Content' ],
		[ 'preview', 'Preview' ],
		[ 'connection', 'Connection' ],
		[ 'history', 'History' ],
	] as const ) {
		const button = element( 'button', {
			className: 'tab',
			text: label,
			attrs: { role: 'tab', 'aria-selected': String( state.tab === id ) },
		} );

		button.addEventListener( 'click', () => {
			state.tab = id;

			if ( id === 'history' ) {
				void loadJobs();
			} else {
				render();
			}
		} );

		tabs.append( button );
	}

	root.append( tabs );

	if ( state.message ) {
		root.append(
			element( 'div', {
				className: `status ${ state.message.kind === 'ok' ? 'ok' : 'err' }`,
				text: state.message.text,
			} )
		);
	}

	switch ( state.tab ) {
		case 'preview':
			root.append( renderPreview() );
			break;
		case 'connection':
			root.append( renderConnection() );
			break;
		case 'history':
			root.append( renderHistory() );
			break;
		default:
			root.append( renderContent() );
	}
}

async function checkConnection(): Promise< void > {
	await guard( async () => {
		await client.health();
		state.backendConnected = true;
		state.message = { kind: 'ok', text: 'Backend reachable.' };
	} );

	if ( state.message?.kind === 'err' ) {
		state.backendConnected = false;
		render();
	}
}

function sourceDescriptor(): Record< string, unknown > {
	return {
		hubId: state.context.hubId.trim(),
		projectId: state.context.projectId.trim(),
		...( state.context.proposalId.trim() ? { proposalId: state.context.proposalId.trim() } : {} ),
		includeFiles: state.includeFiles,
	};
}

async function buildPreview(): Promise< void > {
	await guard( async () => {
		if ( ! state.context.hubId.trim() || ! state.context.projectId.trim() ) {
			throw new Error( 'A hub ID and a project ID are required.' );
		}

		const response = await client.preview(
			sourceDescriptor() as {
				hubId: string;
				projectId: string;
				includeFiles: boolean;
			}
		);

		state.preview = response.project;
		state.tab = 'preview';
		state.message = { kind: 'ok', text: 'Preview built from live project data.' };
	} );
}

async function publish( operation: 'publish' | 'update' | 'unpublish' ): Promise< void > {
	await guard( async () => {
		const project = state.preview
			? { ...state.preview, status: state.postStatus }
			: undefined;

		const response = await client.publish( {
			operation,
			mode: state.mode,
			...( project ? { project } : { source: sourceDescriptor() } ),
		} );

		const job = response.job;

		state.message = {
			kind: job.status === 'failed' ? 'err' : 'ok',
			text:
				job.status === 'skipped'
					? 'Nothing to publish: the content already matches what is on the site.'
					: `Queued ${ operation } for "${ job.title }".`,
		};

		state.tab = 'history';
		await loadJobs();
	} );
}

async function loadJobs(): Promise< void > {
	await guard( async () => {
		state.jobs = ( await client.jobs() ).jobs;
	} );
}

async function boot(): Promise< void > {
	render();

	state.context = await readFormaContext();
	render();

	if ( client.configured ) {
		await checkConnection();
	}
}

void boot();
