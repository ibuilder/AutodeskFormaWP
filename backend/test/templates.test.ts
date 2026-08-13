import assert from 'node:assert/strict';
import { test } from 'node:test';
import { applyTemplate, getTemplate, TEMPLATES } from '../dist/canonical/templates.js';

function project() {
	return {
		source_id: 'urn:t:1',
		source_system: 'autodesk-forma',
		title: 'Template test',
		summary: 'A summary.',
		content: '<p>Body</p>',
		featured_image: { url: 'https://cdn.example.com/x.png' },
		metrics: [
			{ key: 'gfa', label: 'Gross floor area', value: 1000, category: 'Area' },
			{ key: 'sun_hours', label: 'Sun hours', value: 4.5, category: 'Environment' },
			{ key: 'embodied_carbon', label: 'Embodied carbon', value: 320, category: 'Carbon' },
		],
		assets: [ { source_id: 'urn:t:a', title: 'Plan', kind: 'document' as const } ],
	};
}

test( 'every bundled template has the required fields', () => {
	for ( const template of TEMPLATES ) {
		assert.ok( template.id, 'id' );
		assert.ok( template.label, 'label' );
		assert.ok( template.description, 'description' );
		assert.ok( [ 'draft', 'pending', 'publish', 'private' ].includes( template.defaultStatus ) );
	}

	const ids = TEMPLATES.map( ( template ) => template.id );
	assert.equal( ids.length, new Set( ids ).size, 'template ids must be unique' );
} );

test( 'an unknown id resolves to nothing, an absent id to the default', () => {
	assert.equal( getTemplate( 'no-such-template' ), undefined );
	assert.equal( getTemplate( undefined )?.id, 'full' );
} );

test( 'the full template keeps everything', () => {
	const result = applyTemplate( project(), getTemplate( 'full' )! );

	assert.equal( result.metrics?.length, 3 );
	assert.equal( result.assets?.length, 1 );
	assert.ok( result.content );
	assert.ok( result.featured_image );
} );

test( 'the summary template removes metrics and assets', () => {
	const result = applyTemplate( project(), getTemplate( 'summary' )! );

	assert.deepEqual( result.metrics, [] );
	assert.deepEqual( result.assets, [] );
	assert.ok( result.content, 'the description is retained' );
} );

test( 'the metrics template keeps metrics but drops assets', () => {
	const result = applyTemplate( project(), getTemplate( 'metrics' )! );

	assert.equal( result.metrics?.length, 3 );
	assert.deepEqual( result.assets, [] );
} );

test( 'the downloads template keeps assets but drops metrics and the image', () => {
	const result = applyTemplate( project(), getTemplate( 'downloads' )! );

	assert.deepEqual( result.metrics, [] );
	assert.equal( result.assets?.length, 1 );
	assert.equal( result.featured_image, undefined );
} );

test( 'the sustainability template restricts metrics to its categories', () => {
	const result = applyTemplate( project(), getTemplate( 'sustainability' )! );
	const keys = ( result.metrics ?? [] ).map( ( metric ) => metric.key );

	assert.deepEqual( keys.sort(), [ 'embodied_carbon', 'sun_hours' ] );
	assert.ok( ! keys.includes( 'gfa' ), 'area metrics must not leak into a sustainability page' );
} );

test( 'category matching is case insensitive', () => {
	const input = project();
	input.metrics = [ { key: 'sun_hours', label: 'Sun hours', value: 1, category: 'ENVIRONMENT' } ];

	const result = applyTemplate( input, getTemplate( 'sustainability' )! );
	assert.equal( result.metrics?.length, 1 );
} );

test( 'a metric with no category is excluded from a category restricted template', () => {
	const input = project();
	input.metrics = [ { key: 'mystery', label: 'Mystery', value: 1, category: '' } ];

	assert.deepEqual( applyTemplate( input, getTemplate( 'sustainability' )! ).metrics, [] );
} );

test( 'applying a template does not mutate the input', () => {
	const input = project();
	const before = JSON.stringify( input );

	applyTemplate( input, getTemplate( 'summary' )! );

	assert.equal( JSON.stringify( input ), before );
} );

test( 'a template can be applied twice without further loss', () => {
	const template = getTemplate( 'sustainability' )!;
	const once = applyTemplate( project(), template );
	const twice = applyTemplate( once, template );

	assert.deepEqual( twice.metrics, once.metrics );
} );
