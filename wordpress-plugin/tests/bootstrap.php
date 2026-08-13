<?php
/**
 * Minimal test harness for the Forma Publisher integration tests.
 *
 * The suite runs against a real WordPress install through WP-CLI rather than a
 * mocked environment, so REST dispatch, capabilities, cron and the object cache
 * all behave as they do in production.
 *
 * Usage: wp eval-file tests/run.php
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

/**
 * Shared counters and helpers.
 */
class Harness {

	/** @var int */
	public static $passed = 0;

	/** @var int */
	public static $failed = 0;

	/** @var string */
	public static $group = '';

	/** @var string[] */
	public static $failures = array();

	/**
	 * Starts a named group of assertions.
	 *
	 * @param string $name Group name.
	 * @return void
	 */
	public static function group( $name ) {
		self::$group = $name;
		echo "\n" . $name . "\n";
	}

	/**
	 * Records an assertion.
	 *
	 * @param string $label     What is being asserted.
	 * @param bool   $condition Assertion result.
	 * @param string $detail    Extra context shown on failure.
	 * @return bool The assertion result.
	 */
	public static function ok( $label, $condition, $detail = '' ) {
		if ( $condition ) {
			++self::$passed;
			echo "  ok    " . $label . "\n";

			return true;
		}

		++self::$failed;
		$message = self::$group . ' :: ' . $label . ( '' !== $detail ? ' :: ' . $detail : '' );
		self::$failures[] = $message;
		echo "  FAIL  " . $label . ( '' !== $detail ? ' :: ' . $detail : '' ) . "\n";

		return false;
	}

	/**
	 * Asserts two values are identical.
	 *
	 * @param string $label    What is being asserted.
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @return bool The assertion result.
	 */
	public static function same( $label, $expected, $actual ) {
		return self::ok(
			$label,
			$expected === $actual,
			'expected ' . wp_json_encode( $expected ) . ', got ' . wp_json_encode( $actual )
		);
	}

	/**
	 * Prints the summary and returns the process exit code.
	 *
	 * @return int Exit code.
	 */
	public static function summary() {
		echo "\n" . str_repeat( '=', 60 ) . "\n";
		echo 'PASSED: ' . self::$passed . '   FAILED: ' . self::$failed . "\n";

		if ( self::$failed > 0 ) {
			echo "\nFailures:\n";

			foreach ( self::$failures as $failure ) {
				echo '  - ' . $failure . "\n";
			}
		}

		echo str_repeat( '=', 60 ) . "\n";

		return self::$failed > 0 ? 1 : 0;
	}
}

/**
 * Shorthand assertion.
 *
 * @param string $label     What is being asserted.
 * @param bool   $condition Assertion result.
 * @param string $detail    Extra context shown on failure.
 * @return bool The assertion result.
 */
function ok( $label, $condition, $detail = '' ) {
	return Harness::ok( $label, $condition, $detail );
}

/**
 * Shorthand identity assertion.
 *
 * @param string $label    What is being asserted.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @return bool The assertion result.
 */
function same( $label, $expected, $actual ) {
	return Harness::same( $label, $expected, $actual );
}

/**
 * Starts a named group.
 *
 * @param string $name Group name.
 * @return void
 */
function group( $name ) {
	Harness::group( $name );
}

/**
 * Creates a throwaway connection and returns its credentials.
 *
 * @param string $label Connection label.
 * @return array{key_id:string,secret:string} Credentials.
 */
function make_connection( $label = 'Integration test' ) {
	$settings = new \Forma_Publisher\Settings();

	return $settings->create_connection( $label );
}

/**
 * Builds a signed REST request against the plugin.
 *
 * @param string      $method    HTTP method.
 * @param string      $route     REST route.
 * @param string      $body      Raw request body.
 * @param string      $key_id    Connection key id.
 * @param string      $secret    Connection secret.
 * @param int|null    $timestamp Override timestamp.
 * @param string|null $nonce     Override nonce.
 * @return \WP_REST_Request Signed request.
 */
function signed_request( $method, $route, $body, $key_id, $secret, $timestamp = null, $nonce = null ) {
	$timestamp = null === $timestamp ? (string) time() : (string) $timestamp;
	$nonce     = null === $nonce ? 'n' . wp_generate_password( 24, false, false ) : $nonce;

	$canonical = \Forma_Publisher\Signature::canonical_string( $method, $route, $timestamp, $nonce, $body );

	$request = new \WP_REST_Request( $method, $route );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_header( 'x-forma-key', $key_id );
	$request->set_header( 'x-forma-timestamp', $timestamp );
	$request->set_header( 'x-forma-nonce', $nonce );
	$request->set_header( 'x-forma-signature', 'sha256=' . hash_hmac( 'sha256', $canonical, $secret ) );
	$request->set_body( $body );

	return $request;
}

/**
 * Builds a canonical publish payload.
 *
 * @param string              $source_id Project source id.
 * @param array<string,mixed> $overrides Payload overrides, merged recursively.
 * @return array<string,mixed> Canonical payload.
 */
function payload( $source_id, array $overrides = array() ) {
	$base = array(
		'schema_version' => '1.0',
		'operation'      => 'publish',
		'mode'           => 'snapshot',
		'job_id'         => 'job-' . wp_generate_password( 8, false, false ),
		'generated_at'   => gmdate( 'c' ),
		'project'        => array(
			'source_id'     => $source_id,
			'source_system' => 'autodesk-forma',
			'title'         => 'Test Project',
			'summary'       => 'A test project.',
			'content'       => '<p>Body</p>',
			'status'        => 'publish',
			'metrics'       => array(
				array(
					'key'       => 'gfa',
					'label'     => 'Gross floor area',
					'value'     => 1000.5,
					'unit'      => 'm2',
					'category'  => 'Area',
					'precision' => 1,
				),
			),
			'assets'        => array(),
		),
	);

	return array_replace_recursive( $base, $overrides );
}

/**
 * Generates a unique source id for a test run.
 *
 * @param string $prefix Identifier prefix.
 * @return string Unique source id.
 */
function unique_source( $prefix ) {
	return 'urn:adsk.forma:test:' . $prefix . ':' . wp_generate_password( 10, false, false );
}
