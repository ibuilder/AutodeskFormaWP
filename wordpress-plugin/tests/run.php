<?php
/**
 * Integration test runner.
 *
 * Runs the whole suite against a real WordPress install:
 *
 *     wp eval-file tests/run.php
 *
 * Exits non-zero when any assertion fails, so CI can gate on it.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

if ( ! defined( 'ABSPATH' ) ) {
	echo "This runner must be executed through WP-CLI: wp eval-file tests/run.php\n";
	exit( 1 );
}

if ( ! defined( 'FORMA_PUBLISHER_VERSION' ) ) {
	echo "The Forma Publisher plugin is not active in this WordPress install.\n";
	exit( 1 );
}

// Mirrors a wp-config.php definition, so the code managed connection path is
// exercised rather than only the database backed one.
if ( ! defined( 'FORMA_PUBLISHER_CONNECTIONS' ) ) {
	define(
		'FORMA_PUBLISHER_CONNECTIONS',
		array( 'fp_constantkey' => 'constant-secret-value-for-testing-1234' )
	);
}

$forma_publisher_tests_dir = __DIR__;

require_once $forma_publisher_tests_dir . '/bootstrap.php';

echo "Forma Publisher integration suite\n";
echo 'WordPress ' . get_bloginfo( 'version' ) . ' | PHP ' . PHP_VERSION . ' | plugin ' . FORMA_PUBLISHER_VERSION . "\n";

/*
 * Ordering matters: the lifecycle suite exercises the uninstall routine, which
 * deliberately tears options and capabilities down, so it runs last.
 */
$forma_publisher_suites = array(
	'test-settings.php',
	'test-security.php',
	'test-ingest.php',
	'test-rendering.php',
	'test-media.php',
	'test-lifecycle.php',
);

foreach ( $forma_publisher_suites as $forma_publisher_suite ) {
	$forma_publisher_path = $forma_publisher_tests_dir . '/' . $forma_publisher_suite;

	if ( ! is_readable( $forma_publisher_path ) ) {
		echo "\nMissing suite: " . $forma_publisher_suite . "\n";
		++Harness::$failed;
		Harness::$failures[] = 'missing suite ' . $forma_publisher_suite;
		continue;
	}

	echo "\n" . str_repeat( '-', 60 ) . "\n";
	echo $forma_publisher_suite . "\n";
	echo str_repeat( '-', 60 ) . "\n";

	require $forma_publisher_path;
}

exit( Harness::summary() );
