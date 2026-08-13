<?php
/**
 * Multisite behaviour.
 *
 * Only meaningful on a network install; the runner skips this suite otherwise.
 * Verifies that content and credentials stay isolated per site, and that the
 * uninstall routine reaches every site in the network.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

use Forma_Publisher\Capabilities;
use Forma_Publisher\Post_Types;
use Forma_Publisher\Repository;
use Forma_Publisher\Settings;

group( 'Multisite: network shape' );

ok( 'the suite is running on a network install', is_multisite() );

$sites = get_sites( array( 'fields' => 'ids', 'number' => 10 ) );
ok( 'the network has more than one site', count( $sites ) > 1, 'sites: ' . wp_json_encode( $sites ) );

$primary   = (int) get_current_blog_id();
$secondary = 0;

foreach ( $sites as $site_id ) {
	if ( (int) $site_id !== $primary ) {
		$secondary = (int) $site_id;
		break;
	}
}

ok( 'a second site is available for isolation tests', $secondary > 0 );

if ( $secondary < 1 ) {
	return;
}

group( 'Multisite: connections are per site' );

$primary_connection = make_connection( 'Primary site connection' );

switch_to_blog( $secondary );

$secondary_settings    = new Settings();
$secondary_connections = $secondary_settings->connections();

ok(
	'a connection created on one site is not visible on another',
	! isset( $secondary_connections[ $primary_connection['key_id'] ] ),
	'keys on secondary: ' . implode( ',', array_keys( $secondary_connections ) )
);

$secondary_connection = $secondary_settings->create_connection( 'Secondary site connection' );

restore_current_blog();

$primary_settings = new Settings();
$primary_list     = $primary_settings->connections();

ok(
	'a connection created on the second site is not visible on the first',
	! isset( $primary_list[ $secondary_connection['key_id'] ] ),
	'keys on primary: ' . implode( ',', array_keys( $primary_list ) )
);

ok(
	'the first site still has its own connection',
	isset( $primary_list[ $primary_connection['key_id'] ] )
);

group( 'Multisite: a signature is only valid on its own site' );

$route         = '/forma-publisher/v1/ingest';
$shared_source = unique_source( 'ms' );

$response = rest_do_request(
	signed_request( 'POST', $route, wp_json_encode( payload( $shared_source ) ), $primary_connection['key_id'], $primary_connection['secret'] )
);

same( 'publishing works on the primary site', 200, $response->get_status() );
$primary_post = (int) $response->get_data()['result']['post_id'];

switch_to_blog( $secondary );

$cross_site = rest_do_request(
	signed_request( 'POST', $route, wp_json_encode( payload( unique_source( 'cross' ) ) ), $primary_connection['key_id'], $primary_connection['secret'] )
);

same(
	'a credential from another site is rejected',
	401,
	$cross_site->get_status()
);

restore_current_blog();

group( 'Multisite: content is isolated' );

switch_to_blog( $secondary );

$secondary_repository = new Repository();
same(
	'the project published on the primary site is not found on the secondary',
	0,
	$secondary_repository->find_by_source_id( Post_Types::PROJECT, $shared_source )
);

// Publish the same upstream project on the second site; both must coexist.
$secondary_response = rest_do_request(
	signed_request( 'POST', $route, wp_json_encode( payload( $shared_source ) ), $secondary_connection['key_id'], $secondary_connection['secret'] )
);

same( 'the same source id can publish independently on a second site', 200, $secondary_response->get_status() );
$secondary_post = (int) $secondary_response->get_data()['result']['post_id'];

ok( 'the second site created its own post', $secondary_post > 0 );

restore_current_blog();

$primary_repository = new Repository();
same(
	'the primary site still resolves its own post',
	$primary_post,
	$primary_repository->find_by_source_id( Post_Types::PROJECT, $shared_source )
);

group( 'Multisite: capabilities are per site' );

Capabilities::add_caps();
ok( 'the primary administrator role has the manage capability', get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );

switch_to_blog( $secondary );
Capabilities::add_caps();
ok( 'the secondary administrator role has the manage capability', get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
restore_current_blog();

group( 'Multisite: uninstall reaches every site' );

// Leave a marker on each site so the network wide sweep can be observed.
$primary_marker = wp_insert_post(
	array(
		'post_type'   => Post_Types::PROJECT,
		'post_title'  => 'Primary survivor',
		'post_status' => 'publish',
	)
);

switch_to_blog( $secondary );
$secondary_marker = wp_insert_post(
	array(
		'post_type'   => Post_Types::PROJECT,
		'post_title'  => 'Secondary survivor',
		'post_status' => 'publish',
	)
);
restore_current_blog();

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'forma-publisher/forma-publisher.php' );
}

require FORMA_PUBLISHER_DIR . 'uninstall.php';

same( 'primary site settings are removed', false, get_option( Settings::OPTION ) );
same( 'primary site connections are removed', false, get_option( Settings::CONNECTIONS_OPTION ) );
ok( 'primary site capabilities are revoked', ! get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
ok( 'primary site content survives', get_post( $primary_marker ) instanceof \WP_Post );

switch_to_blog( $secondary );

same( 'secondary site settings are removed', false, get_option( Settings::OPTION ) );
same( 'secondary site connections are removed', false, get_option( Settings::CONNECTIONS_OPTION ) );
ok( 'secondary site capabilities are revoked', ! get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
ok( 'secondary site content survives', get_post( $secondary_marker ) instanceof \WP_Post );

$secondary_logs = new \WP_Query(
	array(
		'post_type'      => Post_Types::LOG,
		'post_status'    => 'any',
		'posts_per_page' => 1,
		'fields'         => 'ids',
	)
);
same( 'secondary site log entries are removed', 0, count( $secondary_logs->posts ) );

restore_current_blog();

// Restore a usable state on both sites.
Capabilities::add_caps();
( new Settings() )->ensure_defaults();

switch_to_blog( $secondary );
Capabilities::add_caps();
( new Settings() )->ensure_defaults();
restore_current_blog();
