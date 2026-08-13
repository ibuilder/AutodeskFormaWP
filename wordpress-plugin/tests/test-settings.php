<?php
/**
 * Settings sanitization.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

use Forma_Publisher\Settings;

group( 'Settings: sanitization' );

$settings = new Settings();

$clean = $settings->sanitize( 'not-an-array' );
ok( 'a non array input falls back to defaults', $clean === Settings::defaults() );

$clean = $settings->sanitize( array( 'default_post_status' => 'trash' ) );
same( 'an unsupported post status falls back to the default', 'draft', $clean['default_post_status'] );

$clean = $settings->sanitize( array( 'default_post_status' => 'private' ) );
same( 'a supported post status is kept', 'private', $clean['default_post_status'] );

$clean = $settings->sanitize( array( 'timestamp_tolerance' => 1 ) );
same( 'a tiny tolerance is clamped up', 30, $clean['timestamp_tolerance'] );

$clean = $settings->sanitize( array( 'timestamp_tolerance' => 999999 ) );
same( 'a huge tolerance is clamped down', 3600, $clean['timestamp_tolerance'] );

$clean = $settings->sanitize( array( 'timestamp_tolerance' => '600' ) );
same( 'a numeric string tolerance is accepted', 600, $clean['timestamp_tolerance'] );

$clean = $settings->sanitize( array( 'log_retention_days' => 0 ) );
same( 'a zero retention is clamped up', 1, $clean['log_retention_days'] );

$clean = $settings->sanitize( array( 'log_retention_days' => 10000 ) );
same( 'an excessive retention is clamped down', 365, $clean['log_retention_days'] );

$clean = $settings->sanitize( array() );
same( 'an absent checkbox becomes false', false, $clean['allow_media_import'] );

$clean = $settings->sanitize( array( 'allow_media_import' => '1' ) );
same( 'a present checkbox becomes true', true, $clean['allow_media_import'] );

group( 'Settings: backend URL' );

$clean = $settings->sanitize( array( 'backend_url' => 'https://backend.example.com' ) );
same( 'an https URL is kept', 'https://backend.example.com', $clean['backend_url'] );

$clean = $settings->sanitize( array( 'backend_url' => 'javascript:alert(1)' ) );
same( 'a javascript URL is rejected', '', $clean['backend_url'] );

$clean = $settings->sanitize( array( 'backend_url' => 'ftp://backend.example.com' ) );
same( 'a disallowed scheme is rejected', '', $clean['backend_url'] );

$clean = $settings->sanitize( array( 'backend_url' => '   https://spaced.example.com   ' ) );
same( 'surrounding whitespace is trimmed', 'https://spaced.example.com', $clean['backend_url'] );

group( 'Settings: media host allow list' );

same(
	'a bare host is kept',
	array( 'developer.api.autodesk.com' ),
	Settings::sanitize_host_list( 'developer.api.autodesk.com' )
);

same(
	'a full URL is reduced to its host',
	array( 'cdn.example.com' ),
	Settings::sanitize_host_list( 'https://cdn.example.com/path/to/file.png' )
);

same(
	'host names are lowercased',
	array( 'cdn.example.com' ),
	Settings::sanitize_host_list( 'CDN.Example.COM' )
);

same(
	'newline and comma separated lists are split',
	array( 'a.example.com', 'b.example.com', 'c.example.com' ),
	Settings::sanitize_host_list( "a.example.com\nb.example.com, c.example.com" )
);

same(
	'duplicates are removed',
	array( 'a.example.com' ),
	Settings::sanitize_host_list( "a.example.com\na.example.com" )
);

same(
	'a value without a dot is rejected',
	array(),
	Settings::sanitize_host_list( 'localhost' )
);

same(
	'empty input yields an empty list',
	array(),
	Settings::sanitize_host_list( '' )
);

same(
	'a wildcard is not treated as a host',
	array(),
	Settings::sanitize_host_list( '*' )
);

$wildcard = Settings::sanitize_host_list( '*.example.com' );
ok(
	'a wildcard host is stripped to a literal host rather than kept as a pattern',
	! in_array( '*.example.com', $wildcard, true ),
	'got ' . wp_json_encode( $wildcard )
);

group( 'Settings: sync connection' );

$clean = $settings->sanitize( array( 'sync_connection' => 'fp_nonexistent' ) );
same( 'an unknown sync connection is discarded', '', $clean['sync_connection'] );

$sync_connection = make_connection( 'Sync connection test' );
$clean           = $settings->sanitize( array( 'sync_connection' => $sync_connection['key_id'] ) );
same( 'a known sync connection is kept', $sync_connection['key_id'], $clean['sync_connection'] );

$clean = $settings->sanitize( array( 'sync_interval' => 'every-second' ) );
same( 'an unsupported interval is discarded', 'none', $clean['sync_interval'] );

$clean = $settings->sanitize( array( 'sync_interval' => 'twicedaily' ) );
same( 'a supported interval is kept', 'twicedaily', $clean['sync_interval'] );

group( 'Settings: defaults and caching' );

$defaults = Settings::defaults();
same( 'the default post status is conservative', 'draft', $defaults['default_post_status'] );
same( 'media import is off by default', false, $defaults['allow_media_import'] );
same( 'HTTPS is required by default', true, $defaults['require_https'] );
same( 'the media allow list starts empty', array(), $defaults['media_allowed_hosts'] );

update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'log_retention_days' => 7 ) ), false );
$fresh = new Settings();
same( 'stored settings are read back', 7, $fresh->get( 'log_retention_days' ) );
same( 'an unknown key returns the supplied fallback', 'fallback', $fresh->get( 'nope', 'fallback' ) );

update_option( Settings::OPTION, array( 'log_retention_days' => 9 ), false );
$partial = new Settings();
same( 'a partial stored option is merged over the defaults', 'draft', $partial->get( 'default_post_status' ) );
same( 'the stored value still wins', 9, $partial->get( 'log_retention_days' ) );

update_option( Settings::OPTION, Settings::defaults(), false );
