/**
 * Builds the documentation site from the markdown in docs/.
 *
 * The markdown files are the single source of truth: they are read in the
 * repository and rendered here, so the published site cannot drift from the
 * documentation a contributor sees. Nothing is duplicated by hand.
 *
 *   node build.mjs
 */
import { cp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { marked } from 'marked';

const HERE = dirname( fileURLToPath( import.meta.url ) );
const DOCS = join( HERE, '..', 'docs' );
const OUT = join( HERE, '_site' );
const REPO = 'https://github.com/ibuilder/AutodeskFormaWP';

/** Sidebar structure. Order is deliberate: orientation, then setup, then reference. */
const NAV = [
	{
		title: 'Getting started',
		items: [
			{ file: 'overview.md', label: 'Overview' },
			{ file: 'installation.md', label: 'Installation & operations' },
			{ file: 'deployment.md', label: 'Deployment' },
			{ file: 'configuration.md', label: 'Configuration reference' },
		],
	},
	{
		title: 'How it works',
		items: [
			{ file: 'architecture.md', label: 'Architecture' },
			{ file: 'canonical-schema.md', label: 'Canonical schema' },
			{ file: 'editorial-review.md', label: 'Editorial review' },
			{ file: 'security.md', label: 'Security model' },
		],
	},
	{
		title: 'Reference',
		items: [
			{ file: 'rest-api.md', label: 'WordPress REST API' },
			{ file: 'rendering.md', label: 'Blocks, shortcodes & templates' },
			{ file: 'hooks.md', label: 'Hooks & post meta' },
		],
	},
];

const PAGES = NAV.flatMap( ( group ) => group.items );

function htmlName( file ) {
	return file.replace( /\.md$/, '.html' );
}

function escapeHtml( value ) {
	return value
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

/** Stable, readable heading anchors, de-duplicated within a page. */
function makeSlugger() {
	const seen = new Map();

	return ( text ) => {
		const base =
			text
				.toLowerCase()
				.replace( /<[^>]+>/g, '' )
				.replace( /[^\w\s-]/g, '' )
				.trim()
				.replace( /\s+/g, '-' ) || 'section';

		const count = seen.get( base ) ?? 0;
		seen.set( base, count + 1 );

		return count === 0 ? base : `${ base }-${ count }`;
	};
}

function renderMarkdown( source ) {
	const slug = makeSlugger();
	const headings = [];
	const renderer = new marked.Renderer();

	renderer.heading = function ( { tokens, depth } ) {
		const text = this.parser.parseInline( tokens );
		const id = slug( text );

		if ( depth === 2 ) {
			headings.push( { id, text } );
		}

		return `<h${ depth } id="${ id }"><a class="anchor" href="#${ id }" aria-label="Link to this section">#</a>${ text }</h${ depth }>\n`;
	};

	// Keep intra-documentation links working once rendered, and send links that
	// point outside docs/ to the repository.
	renderer.link = function ( { href, title, tokens } ) {
		const text = this.parser.parseInline( tokens );
		let target = href ?? '';
		let external = /^https?:/i.test( target );

		if ( ! external && target.startsWith( '../' ) ) {
			target = `${ REPO }/blob/main/${ target.replace( /^\.\.\//, '' ) }`;
			external = true;
		} else if ( ! external && target.endsWith( '.md' ) ) {
			target = htmlName( target );
		} else if ( ! external && target.includes( '.md#' ) ) {
			target = target.replace( '.md#', '.html#' );
		}

		const attrs = external ? ' rel="noopener"' : '';
		const titleAttr = title ? ` title="${ escapeHtml( title ) }"` : '';

		return `<a href="${ escapeHtml( target ) }"${ titleAttr }${ attrs }>${ text }</a>`;
	};

	const html = marked.parse( source, { renderer, gfm: true, breaks: false } );

	return { html, headings };
}

function sidebar( current ) {
	const groups = NAV.map( ( group ) => {
		const links = group.items
			.map( ( item ) => {
				const active = item.file === current ? ' class="active"' : '';

				return `<li><a href="${ htmlName( item.file ) }"${ active }>${ escapeHtml( item.label ) }</a></li>`;
			} )
			.join( '\n' );

		return `<div class="nav-group"><p class="nav-title">${ escapeHtml( group.title ) }</p><ul>${ links }</ul></div>`;
	} ).join( '\n' );

	return groups;
}

function layout( { title, current, body, headings } ) {
	const toc =
		headings.length > 1
			? `<nav class="toc" aria-label="On this page">
	<p class="nav-title">On this page</p>
	<ul>${ headings.map( ( h ) => `<li><a href="#${ h.id }">${ h.text }</a></li>` ).join( '' ) }</ul>
</nav>`
			: '';

	return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>${ escapeHtml( title ) } — Forma Publisher</title>
<meta name="description" content="Documentation for Forma Publisher, a source-driven publishing workflow from Autodesk Forma to WordPress." />
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏗️</text></svg>" />
<link rel="stylesheet" href="docs.css" />
</head>
<body>
<a class="skip" href="#content">Skip to content</a>
<header class="topbar">
	<div class="topbar-inner">
		<a class="brand" href="index.html">Forma&nbsp;Publisher <span>docs</span></a>
		<nav class="topnav">
			<a href="../index.html">Home</a>
			<a href="${ REPO }" rel="noopener">GitHub</a>
			<a href="${ REPO }/releases/latest" rel="noopener">Download</a>
		</nav>
	</div>
</header>
<div class="shell">
	<aside class="sidebar" aria-label="Documentation">
		${ sidebar( current ) }
	</aside>
	<main id="content">
		<article class="prose">
			${ body }
		</article>
		<p class="edit"><a href="${ REPO }/blob/main/docs/${ current }" rel="noopener">Edit this page on GitHub</a></p>
	</main>
	${ toc }
</div>
</body>
</html>
`;
}

const CSS = `:root {
	--bg: #ffffff; --bg-alt: #f6f7f9; --fg: #16191d; --muted: #5a6472;
	--border: #e2e5ea; --accent: #0b74c4; --accent-fg: #fff; --code-bg: #f2f4f7;
}
@media (prefers-color-scheme: dark) {
	:root {
		--bg: #0f1216; --bg-alt: #161a20; --fg: #e8eaed; --muted: #9aa4b2;
		--border: #262c35; --accent: #4aa8e8; --accent-fg: #07131d; --code-bg: #171c23;
	}
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
	background: var(--bg); color: var(--fg); margin: 0; line-height: 1.68;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	-webkit-font-smoothing: antialiased;
}
a { color: var(--accent); }
.skip { position: absolute; left: -9999px; }
.skip:focus { background: var(--accent); color: var(--accent-fg); left: 8px; padding: 8px 12px; top: 8px; z-index: 10; }

.topbar { background: var(--bg); border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 5; }
.topbar-inner { align-items: center; display: flex; gap: 20px; justify-content: space-between; margin: 0 auto; max-width: 1400px; padding: 12px 24px; }
.brand { color: var(--fg); font-weight: 700; text-decoration: none; }
.brand span { color: var(--muted); font-weight: 500; }
.topnav { display: flex; gap: 18px; font-size: .92rem; }
.topnav a { text-decoration: none; }

.shell { display: grid; gap: 40px; grid-template-columns: 250px minmax(0, 1fr) 210px; margin: 0 auto; max-width: 1400px; padding: 32px 24px 80px; }
@media (max-width: 1100px) { .shell { grid-template-columns: 230px minmax(0, 1fr); } .toc { display: none; } }
@media (max-width: 780px) { .shell { grid-template-columns: minmax(0, 1fr); } .sidebar { position: static; height: auto; } }

.sidebar { align-self: start; height: calc(100vh - 120px); overflow-y: auto; position: sticky; top: 76px; }
.nav-group { margin-bottom: 22px; }
.nav-title { color: var(--muted); font-size: .74rem; font-weight: 700; letter-spacing: .08em; margin: 0 0 8px; text-transform: uppercase; }
.sidebar ul, .toc ul { list-style: none; margin: 0; padding: 0; }
.sidebar li { margin: 0 0 2px; }
.sidebar a { border-radius: 6px; color: var(--fg); display: block; font-size: .92rem; padding: 5px 10px; text-decoration: none; }
.sidebar a:hover { background: var(--bg-alt); }
.sidebar a.active { background: var(--accent); color: var(--accent-fg); font-weight: 600; }

.toc { align-self: start; position: sticky; top: 76px; }
.toc a { color: var(--muted); display: block; font-size: .84rem; padding: 3px 0; text-decoration: none; }
.toc a:hover { color: var(--fg); }

.prose { max-width: 78ch; }
.prose h1 { font-size: 2rem; letter-spacing: -.02em; line-height: 1.2; margin: 0 0 20px; }
.prose h2 { border-top: 1px solid var(--border); font-size: 1.35rem; margin: 44px 0 14px; padding-top: 26px; }
.prose h3 { font-size: 1.06rem; margin: 28px 0 8px; }
.prose h1:first-child { margin-top: 0; }
.anchor { color: var(--border); float: left; margin-left: -1.1em; opacity: 0; padding-right: .3em; text-decoration: none; }
h2:hover .anchor, h3:hover .anchor { opacity: 1; }
.prose p, .prose li { color: var(--fg); }
.prose blockquote { border-left: 3px solid var(--accent); color: var(--muted); margin: 20px 0; padding: 2px 0 2px 16px; }
.prose code { background: var(--code-bg); border-radius: 4px; font-size: .88em; padding: .15em .4em; }
.prose pre { background: var(--code-bg); border: 1px solid var(--border); border-radius: 8px; margin: 18px 0; overflow-x: auto; padding: 14px 16px; }
.prose pre code { background: none; font-size: .86rem; padding: 0; }
code, pre { font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; }
.prose table { border-collapse: collapse; display: block; font-size: .92rem; margin: 18px 0; overflow-x: auto; width: 100%; }
.prose th, .prose td { border-bottom: 1px solid var(--border); padding: 9px 12px; text-align: left; vertical-align: top; }
.prose th { font-weight: 600; white-space: nowrap; }
.prose hr { border: 0; border-top: 1px solid var(--border); margin: 36px 0; }
.prose img { max-width: 100%; }
.edit { border-top: 1px solid var(--border); font-size: .88rem; margin-top: 56px; padding-top: 20px; }
.edit a { color: var(--muted); }
`;

async function main() {
	await rm( OUT, { recursive: true, force: true } );
	await mkdir( join( OUT, 'docs' ), { recursive: true } );

	const available = new Set( await readdir( DOCS ) );
	const missing = PAGES.filter( ( page ) => page.file !== 'overview.md' && ! available.has( page.file ) );

	if ( missing.length > 0 ) {
		throw new Error( `Navigation references documents that do not exist: ${ missing.map( ( p ) => p.file ).join( ', ' ) }` );
	}

	for ( const page of PAGES ) {
		const path = join( DOCS, page.file );
		const source = await readFile( path, 'utf8' );
		const { html, headings } = renderMarkdown( source );

		const titleMatch = source.match( /^#\s+(.+)$/m );
		const title = titleMatch ? titleMatch[ 1 ] : page.label;

		await writeFile(
			join( OUT, 'docs', htmlName( page.file ) ),
			layout( { title, current: page.file, body: html, headings } ),
			'utf8'
		);
	}

	// The docs landing page is the overview.
	await cp( join( OUT, 'docs', 'overview.html' ), join( OUT, 'docs', 'index.html' ) );

	await writeFile( join( OUT, 'docs', 'docs.css' ), CSS, 'utf8' );
	await cp( join( HERE, 'index.html' ), join( OUT, 'index.html' ) );

	// Pages serves this verbatim; without it Jekyll would try to process the output.
	await writeFile( join( OUT, '.nojekyll' ), '', 'utf8' );

	console.log( `Built ${ PAGES.length + 1 } pages into ${ OUT }` );
}

main().catch( ( error ) => {
	console.error( error.message );
	process.exitCode = 1;
} );
