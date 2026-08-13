<?php
/**
 * Inbound request signature verification.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Validates HMAC-SHA256 signatures on inbound publish requests.
 *
 * The canonical string signed by the backend is:
 *
 *     METHOD \n ROUTE \n TIMESTAMP \n NONCE \n sha256hex( RAW_BODY )
 *
 * @since 1.0.0
 */
class Signature {

	/**
	 * Header carrying the connection key id.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const HEADER_KEY = 'x-forma-key';

	/**
	 * Header carrying the request timestamp.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const HEADER_TIMESTAMP = 'x-forma-timestamp';

	/**
	 * Header carrying the per request nonce.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const HEADER_NONCE = 'x-forma-nonce';

	/**
	 * Header carrying the signature value.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const HEADER_SIGNATURE = 'x-forma-signature';

	/**
	 * Transient prefix used for replay protection.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const NONCE_PREFIX = 'forma_pub_n_';

	/**
	 * Transient prefix used for rate limiting.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const RATE_PREFIX = 'forma_pub_r_';

	/**
	 * Maximum accepted requests per connection per minute.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const RATE_LIMIT = 60;

	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Builds the canonical string that both sides sign.
	 *
	 * @since 1.0.0
	 *
	 * @param string $method    Uppercase HTTP method.
	 * @param string $route     REST route, for example `/forma-publisher/v1/ingest`.
	 * @param string $timestamp Unix timestamp as sent by the client.
	 * @param string $nonce     Unique per request nonce.
	 * @param string $body      Raw request body.
	 * @return string Canonical string.
	 */
	public static function canonical_string( $method, $route, $timestamp, $nonce, $body ) {
		return implode(
			"\n",
			array(
				strtoupper( $method ),
				$route,
				$timestamp,
				$nonce,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Verifies the signature on a REST request.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return string|\WP_Error Verified connection key id, or an error.
	 */
	public function verify( \WP_REST_Request $request ) {
		if ( $this->settings->get( 'require_https' ) && ! is_ssl() && ! $this->is_local_request() ) {
			return new \WP_Error(
				'forma_publisher_https_required',
				__( 'Publish requests must be sent over HTTPS.', 'forma-publisher' ),
				array( 'status' => 400 )
			);
		}

		$key_id    = (string) $request->get_header( self::HEADER_KEY );
		$timestamp = (string) $request->get_header( self::HEADER_TIMESTAMP );
		$nonce     = (string) $request->get_header( self::HEADER_NONCE );
		$signature = (string) $request->get_header( self::HEADER_SIGNATURE );

		if ( '' === $key_id || '' === $timestamp || '' === $nonce || '' === $signature ) {
			return new \WP_Error(
				'forma_publisher_missing_signature',
				__( 'The request is missing one or more signature headers.', 'forma-publisher' ),
				array( 'status' => 401 )
			);
		}

		$key_id = sanitize_key( $key_id );
		$nonce  = preg_replace( '/[^A-Za-z0-9_\-]/', '', $nonce );

		if ( '' === $key_id || strlen( $nonce ) < 8 || strlen( $nonce ) > 128 ) {
			return new \WP_Error(
				'forma_publisher_invalid_signature',
				__( 'The request signature could not be verified.', 'forma-publisher' ),
				array( 'status' => 401 )
			);
		}

		if ( ! preg_match( '/^-?\d{1,12}$/', $timestamp ) ) {
			return new \WP_Error(
				'forma_publisher_invalid_timestamp',
				__( 'The request timestamp is not a valid Unix timestamp.', 'forma-publisher' ),
				array( 'status' => 401 )
			);
		}

		$rate = $this->check_rate_limit( $key_id );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$tolerance = (int) $this->settings->get( 'timestamp_tolerance', 300 );
		$skew      = abs( time() - (int) $timestamp );

		if ( $skew > $tolerance ) {
			return new \WP_Error(
				'forma_publisher_stale_request',
				__( 'The request timestamp is outside the accepted tolerance.', 'forma-publisher' ),
				array( 'status' => 401 )
			);
		}

		$connection = $this->settings->connection( $key_id );

		if ( null === $connection ) {
			// Spend comparable work on unknown keys so timing does not leak key existence.
			$this->compute( 'unknown-connection-placeholder-secret', 'placeholder' );

			return new \WP_Error(
				'forma_publisher_unknown_connection',
				__( 'The request signature could not be verified.', 'forma-publisher' ),
				array( 'status' => 401 )
			);
		}

		$canonical = self::canonical_string(
			$request->get_method(),
			'/' . ltrim( $request->get_route(), '/' ),
			$timestamp,
			$nonce,
			$request->get_body()
		);

		$expected = $this->compute( $connection['secret'], $canonical );
		$provided = $this->normalize_signature( $signature );

		if ( ! hash_equals( $expected, $provided ) ) {
			return new \WP_Error(
				'forma_publisher_invalid_signature',
				__( 'The request signature could not be verified.', 'forma-publisher' ),
				array( 'status' => 401 )
			);
		}

		if ( ! $this->consume_nonce( $key_id, $nonce, $tolerance ) ) {
			return new \WP_Error(
				'forma_publisher_replayed_request',
				__( 'This request was already processed.', 'forma-publisher' ),
				array( 'status' => 409 )
			);
		}

		return $key_id;
	}

	/**
	 * Computes the hex encoded HMAC for a canonical string.
	 *
	 * @since 1.0.0
	 *
	 * @param string $secret    Shared secret.
	 * @param string $canonical Canonical string.
	 * @return string Lowercase hex digest.
	 */
	private function compute( $secret, $canonical ) {
		return hash_hmac( 'sha256', $canonical, $secret );
	}

	/**
	 * Strips an optional algorithm prefix from a signature header.
	 *
	 * @since 1.0.0
	 *
	 * @param string $signature Raw header value.
	 * @return string Lowercase hex digest.
	 */
	private function normalize_signature( $signature ) {
		$signature = trim( $signature );

		if ( 0 === stripos( $signature, 'sha256=' ) ) {
			$signature = substr( $signature, 7 );
		}

		return strtolower( preg_replace( '/[^A-Fa-f0-9]/', '', $signature ) );
	}

	/**
	 * Marks a nonce as used, rejecting repeats inside the replay window.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key_id    Connection key id.
	 * @param string $nonce     Request nonce.
	 * @param int    $tolerance Timestamp tolerance in seconds.
	 * @return bool True when the nonce was unused.
	 */
	private function consume_nonce( $key_id, $nonce, $tolerance ) {
		$transient = self::NONCE_PREFIX . md5( $key_id . '|' . $nonce );

		if ( false !== get_transient( $transient ) ) {
			return false;
		}

		set_transient( $transient, 1, max( 60, $tolerance * 2 ) );

		return true;
	}

	/**
	 * Applies a fixed window rate limit per connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key_id Connection key id.
	 * @return true|\WP_Error True when under the limit.
	 */
	private function check_rate_limit( $key_id ) {
		$window    = (int) floor( time() / MINUTE_IN_SECONDS );
		$transient = self::RATE_PREFIX . md5( $key_id . '|' . $window );

		$count = get_transient( $transient );
		$count = false === $count ? 0 : (int) $count;

		/**
		 * Filters the number of publish requests accepted per connection per minute.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $limit  Requests allowed per minute.
		 * @param string $key_id Connection key id.
		 */
		$limit = (int) apply_filters( 'forma_publisher_rate_limit', self::RATE_LIMIT, $key_id );

		if ( $count >= $limit ) {
			return new \WP_Error(
				'forma_publisher_rate_limited',
				__( 'Too many publish requests. Please retry shortly.', 'forma-publisher' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $transient, $count + 1, 2 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Detects requests originating from the local host.
	 *
	 * Used so that HTTPS enforcement does not block local development setups.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True for loopback requests.
	 */
	private function is_local_request() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true );
	}
}
