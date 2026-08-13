<?php
/**
 * Scheduled maintenance and sync refresh requests.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the plugin cron events.
 *
 * @since 1.0.0
 */
class Scheduler {

	/**
	 * Cron hook that asks the backend to refresh sync mode projects.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const SYNC_HOOK = 'forma_publisher_sync_refresh';

	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Audit trail writer.
	 *
	 * @since 1.0.0
	 * @var Audit_Log
	 */
	private $audit_log;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings  $settings  Settings instance.
	 * @param Audit_Log $audit_log Audit log instance.
	 */
	public function __construct( Settings $settings, Audit_Log $audit_log ) {
		$this->settings  = $settings;
		$this->audit_log = $audit_log;
	}

	/**
	 * Returns the selectable refresh intervals.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Interval slug to label map.
	 */
	public static function intervals() {
		return array(
			'none'       => __( 'Disabled', 'forma-publisher' ),
			'hourly'     => __( 'Hourly', 'forma-publisher' ),
			'twicedaily' => __( 'Twice daily', 'forma-publisher' ),
			'daily'      => __( 'Daily', 'forma-publisher' ),
		);
	}

	/**
	 * Hooks cron handling into WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'ensure_events' ) );
		add_action( self::SYNC_HOOK, array( $this, 'run_sync_refresh' ) );
		add_action( 'update_option_' . Settings::OPTION, array( $this, 'ensure_events' ), 20 );
	}

	/**
	 * Schedules or clears cron events to match the current settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ensure_events() {
		if ( ! wp_next_scheduled( Audit_Log::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Audit_Log::CLEANUP_HOOK );
		}

		$interval = (string) $this->settings->get( 'sync_interval', 'none' );
		$existing = wp_next_scheduled( self::SYNC_HOOK );

		if ( 'none' === $interval ) {
			if ( $existing ) {
				wp_unschedule_event( $existing, self::SYNC_HOOK );
			}

			return;
		}

		if ( ! $existing ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::SYNC_HOOK );
		}
	}

	/**
	 * Removes every scheduled plugin event.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function clear_events() {
		wp_clear_scheduled_hook( self::SYNC_HOOK );
		wp_clear_scheduled_hook( Audit_Log::CLEANUP_HOOK );
	}

	/**
	 * Asks the backend service to re-push every sync mode project.
	 *
	 * WordPress never pulls from Autodesk directly; it only signals the trusted
	 * backend, which owns all Autodesk credentials.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function run_sync_refresh() {
		$backend = (string) $this->settings->get( 'backend_url', '' );
		$key_id  = (string) $this->settings->get( 'sync_connection', '' );

		if ( '' === $backend || '' === $key_id ) {
			return;
		}

		$connection = $this->settings->connection( $key_id );

		if ( null === $connection ) {
			$this->audit_log->log(
				array(
					'operation' => 'sync_refresh',
					'result'    => 'error',
					'message'   => __( 'The configured sync connection is missing or disabled.', 'forma-publisher' ),
				)
			);

			return;
		}

		$endpoint = trailingslashit( $backend ) . 'api/refresh';
		$body     = wp_json_encode(
			array(
				'site_url'       => home_url(),
				'requested_at'   => gmdate( 'c' ),
				'schema_version' => Schema::VERSION,
			)
		);

		if ( ! is_string( $body ) ) {
			return;
		}

		$timestamp = (string) time();
		$nonce     = wp_generate_password( 32, false, false );
		$path      = (string) wp_parse_url( $endpoint, PHP_URL_PATH );
		$canonical = Signature::canonical_string( 'POST', $path, $timestamp, $nonce, $body );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'              => 'application/json',
					Signature::HEADER_KEY       => $key_id,
					Signature::HEADER_TIMESTAMP => $timestamp,
					Signature::HEADER_NONCE     => $nonce,
					Signature::HEADER_SIGNATURE => 'sha256=' . hash_hmac( 'sha256', $canonical, $connection['secret'] ),
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->audit_log->log(
				array(
					'operation'  => 'sync_refresh',
					'result'     => 'error',
					'message'    => $response->get_error_message(),
					'connection' => $key_id,
				)
			);

			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		$this->audit_log->log(
			array(
				'operation'  => 'sync_refresh',
				'result'     => ( $code >= 200 && $code < 300 ) ? 'success' : 'error',
				/* translators: %d: HTTP status code returned by the backend service. */
				'message'    => sprintf( __( 'Backend responded with HTTP %d.', 'forma-publisher' ), $code ),
				'connection' => $key_id,
			)
		);
	}
}
