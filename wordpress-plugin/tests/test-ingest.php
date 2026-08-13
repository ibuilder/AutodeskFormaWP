<?php
/**
 * Publish, update, idempotency, asset synchronization and lifecycle operations.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

use Forma_Publisher\Post_Types;
use Forma_Publisher\Repository;
use Forma_Publisher\Schema;

group( 'Ingest: routes' );

$routes = array_keys( rest_get_server()->get_routes() );

foreach ( array( '/forma-publisher/v1/ingest', '/forma-publisher/v1/status', '/forma-publisher/v1/lookup' ) as $expected ) {
	ok( 'route ' . $expected . ' is registered', in_array( $expected, $routes, true ) );
}

$connection = make_connection( 'Ingest tests' );
$key_id     = $connection['key_id'];
$secret     = $connection['secret'];
$route      = '/forma-publisher/v1/ingest';
$source     = unique_source( 'ingest' );

group( 'Ingest: publish' );

$rich = payload(
	$source,
	array(
		'project' => array(
			'title'             => 'Harbour District Massing Study',
			'slug'              => 'harbour-district-massing-study',
			'summary'           => 'A mixed use massing study.',
			'content'           => '<p>Concept with <strong>three</strong> towers.</p><script>alert(1)</script>',
			'source_url'        => 'https://app.autodesk.com/forma/proposal/demo',
			'hub_id'            => 'b.hub123',
			'project_id'        => 'b.proj456',
			'source_updated_at' => '2026-08-01T10:00:00Z',
			'tags'              => array( 'Mixed Use', 'Waterfront' ),
			'statuses'          => array( 'Concept' ),
			'location'          => array(
				'latitude'  => 59.91,
				'longitude' => 10.75,
				'address'   => 'Oslo, Norway',
			),
			'metrics'           => array(
				array(
					'key'       => 'gfa',
					'label'     => 'Gross floor area',
					'value'     => 48250.5,
					'unit'      => 'm2',
					'category'  => 'Area',
					'precision' => 1,
				),
				array(
					'key'       => 'sun_hours',
					'label'     => 'Average sun hours',
					'value'     => 4.8,
					'unit'      => 'h',
					'category'  => 'Environment',
					'precision' => 1,
				),
			),
			'assets'            => array(
				array(
					'source_id' => $source . ':asset:1',
					'title'     => 'Site plan',
					'kind'      => 'document',
					'url'       => 'https://example.com/site-plan.pdf',
					'mime_type' => 'application/pdf',
					'size'      => 204800,
				),
				array(
					'source_id' => $source . ':asset:2',
					'title'     => 'Massing model',
					'kind'      => 'model',
					'url'       => 'https://example.com/model.glb',
					'size'      => 1048576,
				),
			),
		),
	)
);

$response = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $rich ), $key_id, $secret ) );
$data     = $response->get_data();

same( 'publishing returns 200', 200, $response->get_status() );
same( 'the result reports a creation', 'created', isset( $data['result']['status'] ) ? $data['result']['status'] : '' );

$post_id = isset( $data['result']['post_id'] ) ? (int) $data['result']['post_id'] : 0;
ok( 'a project post is created', $post_id > 0 );

$post = get_post( $post_id );
same( 'the post type is correct', Post_Types::PROJECT, $post->post_type );
same( 'the title is stored', 'Harbour District Massing Study', $post->post_title );
same( 'the post status is honoured', 'publish', $post->post_status );
ok( 'script tags are stripped from content', false === strpos( $post->post_content, '<script' ), $post->post_content );
ok( 'safe markup is preserved', false !== strpos( $post->post_content, '<strong>' ) );
same( 'the source id is stored', $source, get_post_meta( $post_id, Post_Types::META_SOURCE_ID, true ) );
same( 'metrics are stored', 2, count( (array) get_post_meta( $post_id, '_forma_metrics', true ) ) );

$location = (array) get_post_meta( $post_id, '_forma_location', true );
same( 'the location address is stored', 'Oslo, Norway', isset( $location['address'] ) ? $location['address'] : '' );
same( 'the latitude is stored as a float', 59.91, isset( $location['latitude'] ) ? $location['latitude'] : null );

$tags = wp_get_object_terms( $post_id, 'forma_project_tag', array( 'fields' => 'names' ) );
same( 'tags are applied', 2, count( $tags ) );

$statuses = wp_get_object_terms( $post_id, 'forma_project_status', array( 'fields' => 'names' ) );
same( 'statuses are applied', 1, count( $statuses ) );

$repository = new Repository();
same( 'assets are created', 2, count( $repository->assets_for_project( $post_id ) ) );

group( 'Ingest: idempotency' );

$repeat = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $rich ), $key_id, $secret ) );
$repeat_data = $repeat->get_data();

same( 'an identical republish is reported as unchanged', 'unchanged', isset( $repeat_data['result']['status'] ) ? $repeat_data['result']['status'] : '' );
same( 'an identical republish reuses the same post', $post_id, isset( $repeat_data['result']['post_id'] ) ? (int) $repeat_data['result']['post_id'] : 0 );

group( 'Ingest: update' );

$updated = $rich;
$updated['operation']         = 'update';
$updated['project']['title']  = 'Harbour District Massing Study v2';

$response = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $updated ), $key_id, $secret ) );
$data     = $response->get_data();

same( 'an update is reported as updated', 'updated', isset( $data['result']['status'] ) ? $data['result']['status'] : '' );
same( 'an update reuses the same post', $post_id, isset( $data['result']['post_id'] ) ? (int) $data['result']['post_id'] : 0 );

clean_post_cache( $post_id );
same( 'the title is updated in place', 'Harbour District Massing Study v2', get_post( $post_id )->post_title );

group( 'Ingest: asset synchronization' );

$pruned = $updated;
$pruned['project']['assets'] = array( $rich['project']['assets'][0] );
$pruned['project']['title']  = 'Harbour District Massing Study v3';

$response = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $pruned ), $key_id, $secret ) );
$data     = $response->get_data();

same( 'a removed asset is pruned', 1, isset( $data['result']['assets']['removed'] ) ? (int) $data['result']['assets']['removed'] : -1 );
same( 'the remaining asset is kept', 1, count( $repository->assets_for_project( $post_id ) ) );

group( 'Ingest: schema validation' );

$invalid = payload( unique_source( 'invalid' ) );
unset( $invalid['project']['source_id'] );
same( 'a missing source id is rejected', 400, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $invalid ), $key_id, $secret ) )->get_status() );

$bad_operation = payload( unique_source( 'badop' ), array( 'operation' => 'drop_everything' ) );
same( 'an unknown operation is rejected', 400, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $bad_operation ), $key_id, $secret ) )->get_status() );

$extra_field                          = payload( unique_source( 'extra' ) );
$extra_field['project']['evil_field'] = 'boom';
same( 'an unknown project property is rejected', 400, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $extra_field ), $key_id, $secret ) )->get_status() );

$bad_version = payload( unique_source( 'version' ), array( 'schema_version' => '2.0' ) );
same( 'an unsupported schema version is rejected', 400, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $bad_version ), $key_id, $secret ) )->get_status() );

$bad_latitude = payload( unique_source( 'lat' ), array( 'project' => array( 'location' => array( 'latitude' => 200 ) ) ) );
same( 'an out of range latitude is rejected', 400, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $bad_latitude ), $key_id, $secret ) )->get_status() );

$long_title = payload( unique_source( 'long' ), array( 'project' => array( 'title' => str_repeat( 'x', 300 ) ) ) );
same( 'an over long title is rejected', 400, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $long_title ), $key_id, $secret ) )->get_status() );

$empty_title = payload( unique_source( 'empty' ), array( 'project' => array( 'title' => '' ) ) );
same( 'an empty title is rejected', 400, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $empty_title ), $key_id, $secret ) )->get_status() );

$not_json = signed_request( 'POST', $route, 'this is not json', $key_id, $secret );
ok( 'a non JSON body is rejected', rest_do_request( $not_json )->get_status() >= 400 );

group( 'Ingest: status and lookup' );

$status_response = rest_do_request( signed_request( 'GET', '/forma-publisher/v1/status', '', $key_id, $secret ) );
$status_data     = $status_response->get_data();

same( 'the status endpoint responds', 200, $status_response->get_status() );
same( 'the status reports the plugin version', FORMA_PUBLISHER_VERSION, isset( $status_data['plugin_version'] ) ? $status_data['plugin_version'] : '' );
same( 'the status reports the schema version', Schema::VERSION, isset( $status_data['schema_version'] ) ? $status_data['schema_version'] : '' );

$lookup_body     = wp_json_encode( array( 'source_id' => $source ) );
$lookup_response = rest_do_request( signed_request( 'POST', '/forma-publisher/v1/lookup', $lookup_body, $key_id, $secret ) );
$lookup_data     = $lookup_response->get_data();

same( 'a known project is found', 200, $lookup_response->get_status() );
same( 'the lookup returns the post id', $post_id, isset( $lookup_data['post_id'] ) ? (int) $lookup_data['post_id'] : 0 );
ok( 'the lookup returns a payload hash', ! empty( $lookup_data['payload_hash'] ) );

$missing_body = wp_json_encode( array( 'source_id' => 'urn:definitely:absent' ) );
same( 'an unknown project returns 404', 404, rest_do_request( signed_request( 'POST', '/forma-publisher/v1/lookup', $missing_body, $key_id, $secret ) )->get_status() );

group( 'Ingest: unpublish, archive and delete' );

$unpublish = payload( $source, array( 'operation' => 'unpublish' ) );
same( 'unpublishing succeeds', 200, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $unpublish ), $key_id, $secret ) )->get_status() );
clean_post_cache( $post_id );
same( 'the project becomes a draft', 'draft', get_post( $post_id )->post_status );

$archive = payload( $source, array( 'operation' => 'archive' ) );
same( 'archiving succeeds', 200, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $archive ), $key_id, $secret ) )->get_status() );
clean_post_cache( $post_id );
same( 'the project becomes private', 'private', get_post( $post_id )->post_status );
same( 'the publish state is recorded', 'archived', get_post_meta( $post_id, '_forma_publish_state', true ) );

$delete = payload( $source, array( 'operation' => 'delete' ) );
same( 'deleting succeeds', 200, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $delete ), $key_id, $secret ) )->get_status() );
clean_post_cache( $post_id );
same( 'the project is trashed rather than erased', 'trash', get_post( $post_id )->post_status );

$absent = payload( 'urn:not:published', array( 'operation' => 'unpublish' ) );
same( 'unpublishing an unknown project returns 404', 404, rest_do_request( signed_request( 'POST', $route, wp_json_encode( $absent ), $key_id, $secret ) )->get_status() );

group( 'Ingest: audit trail' );

$log = ( new \Forma_Publisher\Audit_Log() )->entries( 1, 100 );
ok( 'publish activity is recorded', $log['total'] > 5, 'entries: ' . $log['total'] );

$first = $log['items'][0];
ok( 'entries record an operation', '' !== (string) get_post_meta( $first->ID, '_forma_log_operation', true ) );
ok( 'entries record a result', '' !== (string) get_post_meta( $first->ID, '_forma_log_result', true ) );

group( 'Ingest: repository lookups' );

$fresh_source = unique_source( 'lookup' );
$fresh        = rest_do_request( signed_request( 'POST', $route, wp_json_encode( payload( $fresh_source ) ), $key_id, $secret ) );
$fresh_id     = (int) $fresh->get_data()['result']['post_id'];

same( 'a project resolves by numeric id', $fresh_id, $repository->resolve_project( (string) $fresh_id )->ID );
same( 'a project resolves by source id', $fresh_id, $repository->resolve_project( $fresh_source )->ID );
ok( 'an unknown reference resolves to null', null === $repository->resolve_project( 'urn:nope' ) );
ok( 'an empty reference resolves to null', null === $repository->resolve_project( '' ) );

$non_project = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Not a project', 'post_status' => 'publish' ) );
ok( 'a post of another type does not resolve as a project', null === $repository->resolve_project( (string) $non_project ) );
