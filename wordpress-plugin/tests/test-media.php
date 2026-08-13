<?php
/**
 * Remote media import guards.
 *
 * These are the only outbound requests the plugin can be told to make, so the
 * allow list and the default-off behaviour are treated as security tests.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

use Forma_Publisher\Settings;

group( 'Media: import is off by default' );

$connection = make_connection( 'Media tests' );
$route      = '/forma-publisher/v1/ingest';
$settings   = new Settings();

update_option( Settings::OPTION, Settings::defaults(), false );
$settings->flush_cache();

// Count outbound HTTP attempts so a blocked import can be proven to make none.
$GLOBALS['fp_http_attempts'] = array();

$record_http = static function ( $preempt, $args, $url ) {
	$GLOBALS['fp_http_attempts'][] = $url;

	// Short-circuit so no real network traffic leaves the test run.
	return new \WP_Error( 'fp_test_blocked', 'Blocked by the test harness.' );
};

add_filter( 'pre_http_request', $record_http, 10, 3 );

$source  = unique_source( 'media-off' );
$payload = payload(
	$source,
	array(
		'project' => array(
			'featured_image' => array(
				'url' => 'https://cdn.example.com/render.png',
				'alt' => 'Render',
			),
		),
	)
);

$response = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $payload ), $connection['key_id'], $connection['secret'] ) );
$data     = $response->get_data();

same( 'the publish still succeeds', 200, $response->get_status() );
same( 'no outbound request is made while import is disabled', 0, count( $GLOBALS['fp_http_attempts'] ) );

$post_id = isset( $data['result']['post_id'] ) ? (int) $data['result']['post_id'] : 0;
ok( 'no thumbnail is attached', ! has_post_thumbnail( $post_id ) );
ok(
	'the skip is reported in the result message',
	isset( $data['result']['message'] ) && false !== stripos( $data['result']['message'], 'disabled' ),
	isset( $data['result']['message'] ) ? $data['result']['message'] : '(none)'
);

group( 'Media: host allow list' );

update_option(
	Settings::OPTION,
	array_merge(
		Settings::defaults(),
		array(
			'allow_media_import'  => true,
			'media_allowed_hosts' => array( 'cdn.allowed.example' ),
		)
	),
	false
);

$GLOBALS['fp_http_attempts'] = array();

$blocked_payload = payload(
	unique_source( 'media-blocked' ),
	array(
		'project' => array(
			'featured_image' => array( 'url' => 'https://evil.example.com/payload.png' ),
		),
	)
);

$response = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $blocked_payload ), $connection['key_id'], $connection['secret'] ) );
$data     = $response->get_data();

same( 'a publish with a disallowed image host still succeeds', 200, $response->get_status() );
same( 'no request is made to a host outside the allow list', 0, count( $GLOBALS['fp_http_attempts'] ) );
ok(
	'the block is reported in the result message',
	isset( $data['result']['message'] ) && false !== stripos( $data['result']['message'], 'allowed list' ),
	isset( $data['result']['message'] ) ? $data['result']['message'] : '(none)'
);

group( 'Media: allow list bypass attempts' );

$bypass_urls = array(
	'a subdomain of an allowed host'      => 'https://cdn.allowed.example.evil.com/x.png',
	'an allowed host in the path'         => 'https://evil.com/cdn.allowed.example/x.png',
	'an allowed host in the query string' => 'https://evil.com/x.png?host=cdn.allowed.example',
	'an allowed host as userinfo'         => 'https://cdn.allowed.example@evil.com/x.png',
	'a loopback address'                  => 'https://127.0.0.1/x.png',
	'a link local metadata address'       => 'https://169.254.169.254/latest/meta-data/',
);

foreach ( $bypass_urls as $label => $url ) {
	$GLOBALS['fp_http_attempts'] = array();

	$attempt = payload(
		unique_source( 'bypass' ),
		array( 'project' => array( 'featured_image' => array( 'url' => $url ) ) )
	);

	rest_do_request( signed_request( 'POST', $route, wp_json_encode( $attempt ), $connection['key_id'], $connection['secret'] ) );

	same( 'no request is made for ' . $label, 0, count( $GLOBALS['fp_http_attempts'] ) );
}

group( 'Media: an allowed host is attempted' );

$GLOBALS['fp_http_attempts'] = array();

$allowed_payload = payload(
	unique_source( 'media-allowed' ),
	array(
		'project' => array(
			'featured_image' => array( 'url' => 'https://cdn.allowed.example/render.png' ),
		),
	)
);

$response = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $allowed_payload ), $connection['key_id'], $connection['secret'] ) );

same( 'the publish succeeds even when the download fails', 200, $response->get_status() );
ok(
	'a request is attempted for an allow listed host',
	count( $GLOBALS['fp_http_attempts'] ) > 0,
	'attempts: ' . wp_json_encode( $GLOBALS['fp_http_attempts'] )
);

ok(
	'the attempted request targets the allow listed host only',
	1 === count( $GLOBALS['fp_http_attempts'] )
		&& false !== strpos( (string) $GLOBALS['fp_http_attempts'][0], 'cdn.allowed.example' ),
	wp_json_encode( $GLOBALS['fp_http_attempts'] )
);

remove_filter( 'pre_http_request', $record_http, 10 );

update_option( Settings::OPTION, Settings::defaults(), false );
