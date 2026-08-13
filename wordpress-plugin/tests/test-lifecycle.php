<?php
/**
 * Capabilities, scheduled events, log retention and uninstall behaviour.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

use Forma_Publisher\Audit_Log;
use Forma_Publisher\Capabilities;
use Forma_Publisher\Post_Types;
use Forma_Publisher\Scheduler;
use Forma_Publisher\Settings;

group( 'Lifecycle: capabilities' );

Capabilities::add_caps();

$administrator = get_role( 'administrator' );
$editor        = get_role( 'editor' );
$subscriber    = get_role( 'subscriber' );

ok( 'the administrator can manage settings', $administrator->has_cap( Capabilities::MANAGE ) );
ok( 'the administrator can view logs', $administrator->has_cap( Capabilities::VIEW_LOGS ) );
ok( 'the administrator can edit projects', $administrator->has_cap( 'edit_forma_projects' ) );
ok( 'the editor cannot manage settings', ! $editor->has_cap( Capabilities::MANAGE ) );
ok( 'the editor can view logs', $editor->has_cap( Capabilities::VIEW_LOGS ) );
ok( 'the editor can edit projects', $editor->has_cap( 'edit_forma_projects' ) );
ok( 'the subscriber has no project capability', ! $subscriber->has_cap( 'edit_forma_projects' ) );
ok( 'the subscriber cannot view logs', ! $subscriber->has_cap( Capabilities::VIEW_LOGS ) );

$all_caps = Capabilities::all_caps();
ok( 'the capability list is de-duplicated', count( $all_caps ) === count( array_unique( $all_caps ) ) );
ok( 'the capability list covers all three post types', count( $all_caps ) > 20, 'count: ' . count( $all_caps ) );

// Removal must be complete, because uninstall relies on it.
Capabilities::remove_caps();

$administrator = get_role( 'administrator' );
ok( 'removing capabilities strips the administrator', ! $administrator->has_cap( Capabilities::MANAGE ) );
ok( 'removing capabilities strips project editing', ! $administrator->has_cap( 'edit_forma_projects' ) );

$editor = get_role( 'editor' );
ok( 'removing capabilities strips the editor', ! $editor->has_cap( 'edit_forma_projects' ) );

// Restore for the remaining tests.
Capabilities::add_caps();
$administrator = get_role( 'administrator' );
ok( 'capabilities can be granted again after removal', $administrator->has_cap( Capabilities::MANAGE ) );

group( 'Lifecycle: scheduled events' );

$settings  = new Settings();
$scheduler = new Scheduler( $settings, new Audit_Log() );

Scheduler::clear_events();
ok( 'clearing removes the sync event', false === wp_next_scheduled( Scheduler::SYNC_HOOK ) );
ok( 'clearing removes the purge event', false === wp_next_scheduled( Audit_Log::CLEANUP_HOOK ) );

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'sync_interval' => 'none' ) ), false );
$settings->flush_cache();
$scheduler->ensure_events();

ok( 'the purge event is always scheduled', false !== wp_next_scheduled( Audit_Log::CLEANUP_HOOK ) );
ok( 'no sync event is scheduled while syncing is disabled', false === wp_next_scheduled( Scheduler::SYNC_HOOK ) );

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'sync_interval' => 'hourly' ) ), false );
$settings->flush_cache();
$scheduler->ensure_events();

ok( 'enabling sync schedules the event', false !== wp_next_scheduled( Scheduler::SYNC_HOOK ) );

$event = wp_get_scheduled_event( Scheduler::SYNC_HOOK );
same( 'the event uses the configured recurrence', 'hourly', $event ? $event->schedule : '' );

// Changing the interval must actually change the recurrence, not silently keep
// the previous one because an event already exists.
update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'sync_interval' => 'daily' ) ), false );
$settings->flush_cache();
$scheduler->ensure_events();

$event = wp_get_scheduled_event( Scheduler::SYNC_HOOK );
same( 'changing the interval reschedules the event', 'daily', $event ? $event->schedule : '' );

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'sync_interval' => 'none' ) ), false );
$settings->flush_cache();
$scheduler->ensure_events();

ok( 'disabling sync unschedules the event', false === wp_next_scheduled( Scheduler::SYNC_HOOK ) );

// Repeated calls must not stack duplicate events.
update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'sync_interval' => 'hourly' ) ), false );
$settings->flush_cache();
$scheduler->ensure_events();
$first = wp_next_scheduled( Scheduler::SYNC_HOOK );
$scheduler->ensure_events();
$scheduler->ensure_events();
same( 'repeated scheduling is idempotent', $first, wp_next_scheduled( Scheduler::SYNC_HOOK ) );

Scheduler::clear_events();
update_option( Settings::OPTION, Settings::defaults(), false );

group( 'Lifecycle: sync refresh guard' );

$log_before = ( new Audit_Log() )->entries( 1, 1 );

// With no backend URL configured the refresh must be a no-op rather than an
// outbound request to an empty host.
$scheduler->run_sync_refresh();

$log_after = ( new Audit_Log() )->entries( 1, 1 );
same( 'an unconfigured refresh writes no log entry', $log_before['total'], $log_after['total'] );

group( 'Lifecycle: audit log retention' );

$audit = new Audit_Log();

$recent_id = $audit->log(
	array(
		'operation' => 'publish',
		'result'    => 'success',
		'source_id' => 'urn:retention:recent',
	)
);

$old_id = $audit->log(
	array(
		'operation' => 'publish',
		'result'    => 'success',
		'source_id' => 'urn:retention:old',
	)
);

ok( 'a log entry is created', $recent_id > 0 && $old_id > 0 );

// Backdate one entry well beyond the retention window.
wp_update_post(
	array(
		'ID'            => $old_id,
		'post_date'     => gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) ),
		'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) ),
	)
);

$audit->purge_expired();

ok( 'an expired entry is purged', null === get_post( $old_id ) || 'trash' === get_post( $old_id )->post_status );
ok( 'a recent entry survives the purge', get_post( $recent_id ) instanceof \WP_Post );

group( 'Lifecycle: logging can be disabled' );

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'enable_logging' => false ) ), false );

same( 'no entry is written while logging is off', 0, $audit->log( array( 'operation' => 'publish' ) ) );

update_option( Settings::OPTION, Settings::defaults(), false );
ok( 'entries resume once logging is re-enabled', $audit->log( array( 'operation' => 'publish' ) ) > 0 );

group( 'Lifecycle: post type registration' );

foreach ( array( Post_Types::PROJECT, Post_Types::ASSET, Post_Types::LOG ) as $type ) {
	ok( 'post type ' . $type . ' is registered', post_type_exists( $type ) );
}

$project_object = get_post_type_object( Post_Types::PROJECT );
ok( 'projects are publicly queryable', $project_object && $project_object->public );

$log_object = get_post_type_object( Post_Types::LOG );
ok( 'log entries are not public', $log_object && ! $log_object->public );
ok( 'log entries are excluded from search', $log_object && $log_object->exclude_from_search );
ok( 'log entries have no admin UI', $log_object && ! $log_object->show_ui );

$asset_object = get_post_type_object( Post_Types::ASSET );
ok( 'assets are not publicly queryable', $asset_object && ! $asset_object->publicly_queryable );

group( 'Lifecycle: uninstall routine' );

// Exercise the uninstall file directly. It must remove options, capabilities
// and log entries while leaving published content in place.
$survivor = wp_insert_post(
	array(
		'post_type'   => Post_Types::PROJECT,
		'post_title'  => 'Survives uninstall',
		'post_status' => 'publish',
	)
);

$audit->log( array( 'operation' => 'publish', 'source_id' => 'urn:uninstall:probe' ) );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'forma-publisher/forma-publisher.php' );
}

require FORMA_PUBLISHER_DIR . 'uninstall.php';

same( 'settings are removed', false, get_option( Settings::OPTION ) );
same( 'connections are removed', false, get_option( Settings::CONNECTIONS_OPTION ) );
same( 'the version marker is removed', false, get_option( 'forma_publisher_installed_version' ) );

$remaining_logs = new \WP_Query(
	array(
		'post_type'      => Post_Types::LOG,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	)
);
same( 'log entries are removed', 0, count( $remaining_logs->posts ) );

$after_uninstall = get_role( 'administrator' );
ok( 'capabilities are revoked on uninstall', ! $after_uninstall->has_cap( Capabilities::MANAGE ) );
ok( 'project capabilities are revoked on uninstall', ! $after_uninstall->has_cap( 'edit_forma_projects' ) );

$kept = get_post( $survivor );
ok( 'published project content survives uninstall', $kept instanceof \WP_Post && 'publish' === $kept->post_status );

ok(
	'scheduled events are cleared on uninstall',
	false === wp_next_scheduled( Scheduler::SYNC_HOOK ) && false === wp_next_scheduled( Audit_Log::CLEANUP_HOOK )
);

// Restore state so later suites still work if the order ever changes.
Capabilities::add_caps();
( new Settings() )->ensure_defaults();
