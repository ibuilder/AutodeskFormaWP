<?php
/**
 * Signature verification, replay protection and rate limiting.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

use Forma_Publisher\Settings;

group( 'Security: signature verification' );

$connection = make_connection( 'Security tests' );
$key_id     = $connection['key_id'];
$secret     = $connection['secret'];
$route      = '/forma-publisher/v1/ingest';
$source     = unique_source( 'sec' );
$body       = wp_json_encode( payload( $source ) );

$response = rest_do_request( signed_request( 'POST', $route, $body, $key_id, $secret ) );
same( 'a correctly signed request is accepted', 200, $response->get_status() );

$response = rest_do_request( signed_request( 'POST', $route, $body, $key_id, 'wrong-secret' ) );
same( 'a wrong secret is rejected', 401, $response->get_status() );

$request = signed_request( 'POST', $route, $body, $key_id, $secret );
$request->set_body( str_replace( 'Test Project', 'Tampered', $body ) );
same( 'a tampered body is rejected', 401, rest_do_request( $request )->get_status() );

$response = rest_do_request( signed_request( 'POST', $route, $body, 'fp_doesnotexist', $secret ) );
same( 'an unknown key id is rejected', 401, $response->get_status() );

$unsigned = new \WP_REST_Request( 'POST', $route );
$unsigned->set_header( 'content-type', 'application/json' );
$unsigned->set_body( $body );
same( 'an unsigned request is rejected', 401, rest_do_request( $unsigned )->get_status() );

// A signature valid for one route must not be reusable on another.
$cross = signed_request( 'POST', '/forma-publisher/v1/lookup', $body, $key_id, $secret );
$cross->set_route( $route );
same( 'a signature bound to another route is rejected', 401, rest_do_request( $cross )->get_status() );

group( 'Security: timestamp handling' );

same(
	'a stale timestamp is rejected',
	401,
	rest_do_request( signed_request( 'POST', $route, $body, $key_id, $secret, time() - 5000 ) )->get_status()
);

same(
	'a far future timestamp is rejected',
	401,
	rest_do_request( signed_request( 'POST', $route, $body, $key_id, $secret, time() + 5000 ) )->get_status()
);

$malformed = signed_request( 'POST', $route, $body, $key_id, $secret );
$malformed->set_header( 'x-forma-timestamp', 'not-a-number' );
same( 'a non numeric timestamp is rejected', 401, rest_do_request( $malformed )->get_status() );

$overflow = signed_request( 'POST', $route, $body, $key_id, $secret );
$overflow->set_header( 'x-forma-timestamp', '999999999999999999999' );
same( 'an oversized timestamp is rejected', 401, rest_do_request( $overflow )->get_status() );

group( 'Security: replay protection' );

$replay_nonce = 'replay-' . wp_generate_password( 12, false, false );
$replay_body  = wp_json_encode( payload( unique_source( 'replay' ) ) );

same(
	'the first use of a nonce is accepted',
	200,
	rest_do_request( signed_request( 'POST', $route, $replay_body, $key_id, $secret, time(), $replay_nonce ) )->get_status()
);

same(
	'the second use of the same nonce is rejected',
	409,
	rest_do_request( signed_request( 'POST', $route, $replay_body, $key_id, $secret, time(), $replay_nonce ) )->get_status()
);

$short_nonce = signed_request( 'POST', $route, $body, $key_id, $secret, time(), 'short' );
same( 'an implausibly short nonce is rejected', 401, rest_do_request( $short_nonce )->get_status() );

$long_nonce = signed_request( 'POST', $route, $body, $key_id, $secret, time(), str_repeat( 'a', 200 ) );
same( 'an oversized nonce is rejected', 401, rest_do_request( $long_nonce )->get_status() );

group( 'Security: connection state' );

$settings = new Settings();
$settings->set_connection_enabled( $key_id, false );

same(
	'a disabled connection is rejected',
	401,
	rest_do_request( signed_request( 'POST', $route, wp_json_encode( payload( unique_source( 'disabled' ) ) ), $key_id, $secret ) )->get_status()
);

$settings->set_connection_enabled( $key_id, true );

same(
	're-enabling a connection restores access',
	200,
	rest_do_request( signed_request( 'POST', $route, wp_json_encode( payload( unique_source( 'reenabled' ) ) ), $key_id, $secret ) )->get_status()
);

group( 'Security: rate limiting' );

$limited = make_connection( 'Rate limit tests' );

$limit_filter = static function () {
	return 3;
};

add_filter( 'forma_publisher_rate_limit', $limit_filter );

$statuses = array();

for ( $i = 0; $i < 5; $i++ ) {
	$statuses[] = rest_do_request(
		signed_request(
			'POST',
			$route,
			wp_json_encode( payload( unique_source( 'rate' . $i ) ) ),
			$limited['key_id'],
			$limited['secret']
		)
	)->get_status();
}

remove_filter( 'forma_publisher_rate_limit', $limit_filter );

ok(
	'the rate limit filter is honoured',
	in_array( 429, $statuses, true ),
	'statuses: ' . implode( ',', $statuses )
);

ok(
	'requests under the limit still succeed',
	200 === $statuses[0],
	'first status: ' . $statuses[0]
);

group( 'Security: constant defined connections' );

// FORMA_PUBLISHER_CONNECTIONS is defined by the runner before WordPress loads
// the plugin settings, mirroring a wp-config.php definition.
if ( defined( 'FORMA_PUBLISHER_CONNECTIONS' ) ) {
	$constant_settings = new Settings();
	$constant_list     = $constant_settings->connections();

	ok(
		'a constant defined connection is exposed',
		isset( $constant_list['fp_constantkey'] ),
		'keys: ' . implode( ',', array_keys( $constant_list ) )
	);

	same(
		'a constant defined connection is marked as code managed',
		'constant',
		isset( $constant_list['fp_constantkey']['source'] ) ? $constant_list['fp_constantkey']['source'] : ''
	);

	same(
		'a constant defined connection can sign requests',
		200,
		rest_do_request(
			signed_request(
				'POST',
				$route,
				wp_json_encode( payload( unique_source( 'constant' ) ) ),
				'fp_constantkey',
				'constant-secret-value-for-testing-1234'
			)
		)->get_status()
	);
} else {
	ok( 'constant defined connections were not exercised', false, 'FORMA_PUBLISHER_CONNECTIONS was not defined' );
}
