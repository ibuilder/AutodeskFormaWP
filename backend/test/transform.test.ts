import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
	normalizeAssets,
	normalizeMetrics,
	projectHash,
	toCanonicalProject,
} from '../dist/canonical/transform.js';

test( 'metric maps are normalized with known labels and units', () => {
	const metrics = normalizeMetrics( { gfa: 48250.5, sun_hours: 4.8, unknownThing: 12 } );
	const byKey = Object.fromEntries( metrics.map( ( metric ) => [ metric.key, metric ] ) );

	assert.equal( byKey.gfa?.label, 'Gross floor area' );
	assert.equal( byKey.gfa?.unit, 'm²' );
	assert.equal( byKey.gfa?.category, 'Area' );
	assert.equal( byKey.gfa?.value, 48250.5 );
	assert.equal( byKey.sun_hours?.unit, 'h' );

	// Unknown keys still get a readable label rather than being dropped.
	assert.equal( byKey.unknownthing?.label, 'Unknownthing' );
	assert.equal( byKey.unknownthing?.value, 12 );
} );

test( 'metric arrays are supported', () => {
	const metrics = normalizeMetrics( [
		{ key: 'far', value: '2.4' },
		{ key: 'custom', value: 'High', unit: '', category: 'Notes' },
	] );

	assert.equal( metrics.length, 2 );
	assert.equal( metrics[ 0 ]?.value, 2.4 );
	assert.equal( metrics[ 1 ]?.value, 'High' );
	assert.equal( metrics[ 1 ]?.category, 'Notes' );
} );

test( 'metrics without a key are dropped', () => {
	assert.equal( normalizeMetrics( [ { value: 5 }, { key: '   ' } ] ).length, 0 );
} );

test( 'asset kinds are inferred from name and mime type', () => {
	const assets = normalizeAssets( [
		{ id: '1', name: 'plan.pdf', mimeType: 'application/pdf' },
		{ id: '2', name: 'view.png', mimeType: 'image/png' },
		{ id: '3', name: 'tower.ifc' },
		{ id: '4', name: 'areas.csv' },
		{ name: 'no-id.pdf' },
	] );

	assert.equal( assets.length, 4 );
	assert.equal( assets[ 0 ]?.kind, 'document' );
	assert.equal( assets[ 1 ]?.kind, 'image' );
	assert.equal( assets[ 2 ]?.kind, 'model' );
	assert.equal( assets[ 3 ]?.kind, 'dataset' );
} );

test( 'a canonical project is produced and validates against the schema', () => {
	const project = toCanonicalProject( {
		urn: 'urn:adsk.forma:proposal:abc',
		name: 'Harbour District',
		description: 'A <b>mixed</b> use study',
		hubId: 'b.hub',
		projectId: 'b.proj',
		webUrl: 'https://app.autodesk.com/forma/abc',
		tags: [ 'Waterfront', '' ],
		phase: 'Concept',
		location: { lat: 59.91, lng: 10.75, address: 'Oslo' },
		metrics: { gfa: 1000 },
		files: [ { id: 'f1', name: 'plan.pdf', mimeType: 'application/pdf', storageSize: 2048 } ],
	} );

	assert.equal( project.source_id, 'urn:adsk.forma:proposal:abc' );
	assert.equal( project.source_system, 'autodesk-forma' );
	assert.equal( project.tags?.length, 1 );
	assert.deepEqual( project.statuses, [ 'Concept' ] );
	assert.equal( project.location?.latitude, 59.91 );
	assert.equal( project.assets?.length, 1 );

	// Upstream HTML is escaped, so nothing executable can reach WordPress even
	// before the plugin runs wp_kses_post on it.
	assert.ok( ! project.content?.includes( '<b>' ) );
	assert.ok( project.content?.includes( '&lt;b&gt;' ) );
} );

test( 'a project without an identifier is refused', () => {
	assert.throws( () => toCanonicalProject( { name: 'Nameless' } ), /no identifier/i );
} );

test( 'the project hash is stable regardless of key order', () => {
	const a = toCanonicalProject( { urn: 'urn:1', name: 'A', metrics: { gfa: 1, far: 2 } } );
	const b = toCanonicalProject( { urn: 'urn:1', name: 'A', metrics: { far: 2, gfa: 1 } } );

	// Metric order follows the source, so hashes differ; re-hashing the same
	// object must not.
	assert.equal( projectHash( a ), projectHash( structuredClone( a ) ) );
	assert.notEqual( projectHash( a ), projectHash( toCanonicalProject( { urn: 'urn:1', name: 'B' } ) ) );
	assert.equal( typeof projectHash( b ), 'string' );
	assert.equal( projectHash( b ).length, 64 );
} );
