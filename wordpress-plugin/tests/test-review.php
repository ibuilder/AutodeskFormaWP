<?php
/**
 * Editorial review and local edit protection.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

use Forma_Publisher\Audit_Log;
use Forma_Publisher\Ingest_Service;
use Forma_Publisher\Repository;
use Forma_Publisher\Review;
use Forma_Publisher\Settings;

$connection = make_connection( 'Review tests' );
$route      = '/forma-publisher/v1/ingest';

/**
 * Simulates a human editing the project in WordPress.
 *
 * @param int    $post_id Post id.
 * @param string $title   New title.
 * @return void
 */
function edit_locally( $post_id, $title ) {
	// post_modified only advances when the stored value differs, so wait until
	// the clock moves rather than relying on sub-second resolution.
	$before = get_post( $post_id )->post_modified_gmt;

	do {
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $title,
			)
		);

		clean_post_cache( $post_id );
		$after = get_post( $post_id )->post_modified_gmt;

		if ( $after === $before ) {
			sleep( 1 );
		}
	} while ( $after === $before );
}

group( 'Review: a clean project updates normally' );

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'conflict_policy' => 'hold' ) ), false );

$source   = unique_source( 'review-clean' );
$response = rest_do_request( signed_request( 'POST', $route, wp_json_encode( payload( $source ) ), $connection['key_id'], $connection['secret'] ) );
$post_id  = (int) $response->get_data()['result']['post_id'];

same( 'the initial publish succeeds', 200, $response->get_status() );
ok( 'a sync time is recorded', '' !== (string) get_post_meta( $post_id, Review::META_SYNCED_MODIFIED, true ) );
ok( 'no local edits are detected immediately after a publish', ! Review::has_local_edits( $post_id ) );

$updated = payload( $source, array( 'operation' => 'update', 'project' => array( 'title' => 'Updated cleanly' ) ) );
$result  = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $updated ), $connection['key_id'], $connection['secret'] ) )->get_data();

same( 'an unedited project is updated in place', 'updated', $result['result']['status'] );
clean_post_cache( $post_id );
same( 'the incoming title is applied', 'Updated cleanly', get_post( $post_id )->post_title );
ok( 'the project is still considered clean afterwards', ! Review::has_local_edits( $post_id ) );

group( 'Review: a local edit is detected' );

edit_locally( $post_id, 'Edited by a human' );

ok( 'the local edit is detected', Review::has_local_edits( $post_id ) );

group( 'Review: the hold policy parks the update' );

$incoming = payload( $source, array( 'operation' => 'update', 'project' => array( 'title' => 'Incoming from Forma' ) ) );
$held     = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $incoming ), $connection['key_id'], $connection['secret'] ) )->get_data();

same( 'the update is reported as held', 'held_for_review', $held['result']['status'] );

clean_post_cache( $post_id );
same( 'the local title is preserved', 'Edited by a human', get_post( $post_id )->post_title );

$parked = Review::held( $post_id );
ok( 'the incoming payload is parked', is_array( $parked ) );
same( 'the parked payload carries the incoming title', 'Incoming from Forma', $parked['project']['title'] );
ok( 'the parked update appears in the review queue', in_array( $post_id, wp_list_pluck( Review::held_posts(), 'ID' ), true ) );

group( 'Review: applying a held update' );

$service = new Ingest_Service( new Settings(), new Repository(), new Audit_Log() );
$applied = $service->apply_held_update( $post_id );

ok( 'applying returns a result rather than an error', ! is_wp_error( $applied ), is_wp_error( $applied ) ? $applied->get_error_message() : '' );

clean_post_cache( $post_id );
same( 'the incoming title is now applied', 'Incoming from Forma', get_post( $post_id )->post_title );
ok( 'the hold is cleared', null === Review::held( $post_id ) );
ok( 'the project is clean again', ! Review::has_local_edits( $post_id ) );

ok(
	'applying with nothing held returns an error',
	is_wp_error( $service->apply_held_update( $post_id ) )
);

group( 'Review: discarding a held update' );

edit_locally( $post_id, 'Locally curated' );

$second = payload( $source, array( 'operation' => 'update', 'project' => array( 'title' => 'Second incoming' ) ) );
rest_do_request( signed_request( 'POST', $route, wp_json_encode( $second ), $connection['key_id'], $connection['secret'] ) );

ok( 'the second update is parked', null !== Review::held( $post_id ) );

// Discarding keeps the local version and accepts it as the agreed state.
Review::clear( $post_id );
Review::record_sync( $post_id );

clean_post_cache( $post_id );
same( 'the local title survives', 'Locally curated', get_post( $post_id )->post_title );
ok( 'nothing remains held', null === Review::held( $post_id ) );
ok( 'the project is no longer flagged as edited', ! Review::has_local_edits( $post_id ) );

group( 'Review: the skip policy discards the update' );

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'conflict_policy' => 'skip' ) ), false );

$skip_source = unique_source( 'review-skip' );
$skip_post   = (int) rest_do_request( signed_request( 'POST', $route, wp_json_encode( payload( $skip_source ) ), $connection['key_id'], $connection['secret'] ) )->get_data()['result']['post_id'];

edit_locally( $skip_post, 'Kept local' );

$skipped = rest_do_request(
	signed_request( 'POST', $route, wp_json_encode( payload( $skip_source, array( 'operation' => 'update', 'project' => array( 'title' => 'Should be discarded' ) ) ) ), $connection['key_id'], $connection['secret'] )
)->get_data();

same( 'the update is reported as skipped', 'skipped_local_edit', $skipped['result']['status'] );
clean_post_cache( $skip_post );
same( 'the local title is kept', 'Kept local', get_post( $skip_post )->post_title );
ok( 'nothing is parked under the skip policy', null === Review::held( $skip_post ) );

group( 'Review: the overwrite policy applies the update' );

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'conflict_policy' => 'overwrite' ) ), false );

$over_source = unique_source( 'review-over' );
$over_post   = (int) rest_do_request( signed_request( 'POST', $route, wp_json_encode( payload( $over_source ) ), $connection['key_id'], $connection['secret'] ) )->get_data()['result']['post_id'];

edit_locally( $over_post, 'Will be replaced' );

$overwritten = rest_do_request(
	signed_request( 'POST', $route, wp_json_encode( payload( $over_source, array( 'operation' => 'update', 'project' => array( 'title' => 'Overwritten by Forma' ) ) ) ), $connection['key_id'], $connection['secret'] )
)->get_data();

same( 'the update is applied under the overwrite policy', 'updated', $overwritten['result']['status'] );
clean_post_cache( $over_post );
same( 'the incoming title wins', 'Overwritten by Forma', get_post( $over_post )->post_title );

group( 'Review: approval required for new projects' );

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'require_approval' => true ) ), false );

$approval_source = unique_source( 'review-approval' );
$approval_result = rest_do_request(
	signed_request( 'POST', $route, wp_json_encode( payload( $approval_source, array( 'project' => array( 'status' => 'publish' ) ) ) ), $connection['key_id'], $connection['secret'] )
)->get_data();

$approval_post = (int) $approval_result['result']['post_id'];
same( 'a new project is held as pending rather than published', 'pending', get_post( $approval_post )->post_status );

update_option( Settings::OPTION, Settings::defaults(), false );

$direct_source = unique_source( 'review-direct' );
$direct_post   = (int) rest_do_request(
	signed_request( 'POST', $route, wp_json_encode( payload( $direct_source, array( 'project' => array( 'status' => 'publish' ) ) ) ), $connection['key_id'], $connection['secret'] )
)->get_data()['result']['post_id'];

same( 'without approval required a project publishes directly', 'publish', get_post( $direct_post )->post_status );

group( 'Review: upgrades do not retroactively hold everything' );

// A project that predates the feature has no recorded sync time and must not
// be treated as edited, or upgrading would park every project at once.
$legacy = wp_insert_post(
	array(
		'post_type'   => 'forma_project',
		'post_title'  => 'Legacy project',
		'post_status' => 'publish',
	)
);

delete_post_meta( $legacy, Review::META_SYNCED_MODIFIED );
ok( 'a project with no recorded sync time is treated as clean', ! Review::has_local_edits( $legacy ) );

group( 'Review: attention count' );

Review::flush_attention_count();
$count = Review::attention_count();
ok( 'the attention count is a non negative integer', is_int( $count ) && $count >= 0, 'count: ' . $count );

update_option( Settings::OPTION, Settings::defaults(), false );
