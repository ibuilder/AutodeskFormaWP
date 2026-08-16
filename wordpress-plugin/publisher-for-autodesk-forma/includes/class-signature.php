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
	 * Maximum unverified requests accepted from one address per minute.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	const UNVERIFIED_RATE_LIMIT = 20;

	/**
	 * Transient prefix used for the unverified request limiter.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const UNVERIFIED_PREFIX = 'forma_pub_u_';

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
	 * Rate limiting is deliberately split in two. Requests that fail to
	 * authenticate are charged to the origin address, and only requests that do
	 * authenticate are charged to the connection. Charging failures to the
	 * connection would let anyone who learns a key id exhaust that connection's
	 * budget and lock the real backend out.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return string|\WP_Error Verified connection key id, or an error.
	 */
	public function verify( \WP_REST_Request $request ) {
		$allowed = $this->check_unverified_rate_limit();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$result = $this->authenticate( $request );

		if ( is_wp_error( $result ) ) {
			$code = $result->get_error_code();

			/*
			 * A replay or a connection rate limit means the signature was valid,
			 * so those must not be charged to the origin address.
			 */
			if ( 'forma_publisher_replayed_request' !== $code && 'forma_publisher_rate_limited' !== $code ) {
				$this->record_unverified_failure();
			}
		}

		return $result;
	}

	/**
	 * Performs the actual signature checks.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return string|\WP_Error Verified connection key id, or an error.
	 */
	private function authenticate( \WP_REST_Request $request ) {
		if ( $this->settings->get( 'require_https' ) && ! is_ssl() && ! $this->is_local_request() ) {
			return new \WP_Error(
				'forma_publisher_https_required',
				__( 'Publish requests must be sent over HTTPS.', 'publisher-for-autodesk-forma' ),
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
				__( 'The request is missing one or more signature headers.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 401 )
			);
		}

		$key_id = sanitize_key( $key_id );
		$nonce  = preg_replace( '/[^A-Za-z0-9_\-]/', '', $nonce );

		if ( '' === $key_id || strlen( $nonce ) < 8 || strlen( $nonce ) > 128 ) {
			return new \WP_Error(
				'forma_publisher_invalid_signature',
				__( 'The request signature could not be verified.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 401 )
			);
		}

		if ( ! preg_match( '/^-?\d{1,12}$/', $timestamp ) ) {
			return new \WP_Error(
				'forma_publisher_invalid_timestamp',
				__( 'The request timestamp is not a valid Unix timestamp.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 401 )
			);
		}

		$tolerance = (int) $this->settings->get( 'timestamp_tolerance', 300 );
		$skew      = abs( time() - (int) $timestamp );

		if ( $skew > $tolerance ) {
			return new \WP_Error(
				'forma_publisher_stale_request',
				__( 'The request timestamp is outside the accepted tolerance.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 401 )
			);
		}

		$connection = $this->settings->connection( $key_id );

		if ( null === $connection ) {
			// Spend comparable work on unknown keys so timing does not leak key existence.
			$this->compute( 'unknown-connection-placeholder-secret', 'placeholder' );

			return new \WP_Error(
				'forma_publisher_unknown_connection',
				__( 'The request signature could not be verified.', 'publisher-for-autodesk-forma' ),
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
				__( 'The request signature could not be verified.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 401 )
			);
		}

		// Only a verified request is charged to the connection's budget.
		$rate = $this->check_rate_limit( $key_id );

		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		if ( ! $this->consume_nonce( $key_id, $nonce, $tolerance ) ) {
			return new \WP_Error(
				'forma_publisher_replayed_request',
				__( 'This request was already processed.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 409 )
			);
		}

		return $key_id;
	}

	/**
	 * Limits unverified requests by origin address.
	 *
	 * Only `REMOTE_ADDR` is used. Forwarded-for headers are attacker controlled
	 * unless a trusted proxy rewrites them, so trusting one here would make the
	 * limiter trivially bypassable.
	 *
	 * The address is hashed before it is used as a cache key, so no raw address
	 * is written to storage.
	 *
	 * @since 1.0.0
	 *
	 * @return true|\WP_Error True when under the limit.
	 */
	private function check_unverified_rate_limit() {
		$limit = $this->unverified_limit();

		if ( $limit <= 0 ) {
			return true;
		}

		$count = get_transient( $this->unverified_key() );
		$count = false === $count ? 0 : (int) $count;

		if ( $count >= $limit ) {
			return new \WP_Error(
				'forma_publisher_unverified_rate_limited',
				__( 'Too many unauthenticated requests. Please retry shortly.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Charges one failed authentication attempt to the origin address.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function record_unverified_failure() {
		if ( $this->unverified_limit() <= 0 ) {
			return;
		}

		$key   = $this->unverified_key();
		$count = get_transient( $key );
		$count = false === $count ? 0 : (int) $count;

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
	}

	/**
	 * Returns the configured unverified request limit.
	 *
	 * @since 1.0.0
	 *
	 * @return int Requests allowed per address per minute. Zero disables the limiter.
	 */
	private function unverified_limit() {
		/**
		 * Filters the number of failed authentication attempts accepted per
		 * address per minute. Return zero to disable the limiter.
		 *
		 * @since 1.0.0
		 *
		 * @param int $limit Failed attempts allowed per minute.
		 */
		return (int) apply_filters( 'forma_publisher_unverified_rate_limit', self::UNVERIFIED_RATE_LIMIT );
	}

	/**
	 * Builds the transient key for the current origin address and window.
	 *
	 * Only `REMOTE_ADDR` is used. Forwarded-for headers are attacker controlled
	 * unless a trusted proxy rewrites them, so trusting one would make the
	 * limiter trivially bypassable. The address is hashed before use as a key,
	 * so no raw address is written to storage.
	 *
	 * @since 1.0.0
	 *
	 * @return string Transient key.
	 */
	private function unverified_key() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$window = (int) floor( time() / MINUTE_IN_SECONDS );

		return self::UNVERIFIED_PREFIX . md5( $remote . '|' . $window );
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
				__( 'Too many publish requests. Please retry shortly.', 'publisher-for-autodesk-forma' ),
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
