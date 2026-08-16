<?php
/**
 * Plugin settings and backend connection storage.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, sanitizes and persists plugin configuration.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Option name holding the general settings array.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const OPTION = 'forma_publisher_settings';

	/**
	 * Option name holding registered backend connections.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CONNECTIONS_OPTION = 'forma_publisher_connections';

	/**
	 * Settings page and option group slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const GROUP = 'forma_publisher_settings_group';

	/**
	 * Memoized settings array.
	 *
	 * @since 1.0.0
	 * @var array<string,mixed>|null
	 */
	private $cache = null;

	/**
	 * Registers the option with the WordPress settings API.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'settings_capability' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_cache' ) );
	}

	/**
	 * Registers the settings option and its sanitizer.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_setting() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Returns the capability required to save the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return string Capability name.
	 */
	public function settings_capability() {
		return Capabilities::MANAGE;
	}

	/**
	 * Clears the memoized settings array.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * Returns the default settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed> Default settings.
	 */
	public static function defaults() {
		return array(
			'default_post_status' => 'draft',
			'timestamp_tolerance' => 300,
			'allow_media_import'  => false,
			'media_allowed_hosts' => array(),
			'enable_logging'      => true,
			'log_retention_days'  => 30,
			'require_https'       => true,
			'backend_url'         => '',
			'sync_interval'       => 'none',
			'sync_connection'     => '',
			'require_approval'    => false,
			'conflict_policy'     => 'hold',
		);
	}

	/**
	 * Returns the selectable policies for handling locally edited content.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string,string> Policy slug to label map.
	 */
	public static function conflict_policies() {
		return array(
			'hold'      => __( 'Hold the update for review', 'publisher-for-autodesk-forma' ),
			'skip'      => __( 'Keep the local edits and discard the update', 'publisher-for-autodesk-forma' ),
			'overwrite' => __( 'Overwrite the local edits', 'publisher-for-autodesk-forma' ),
		);
	}

	/**
	 * Returns all settings merged over the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed> Effective settings.
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored = get_option( self::OPTION, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			$this->cache = array_merge( self::defaults(), $stored );
		}

		return $this->cache;
	}

	/**
	 * Returns a single setting value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default_value Value returned when the key is unknown.
	 * @return mixed Setting value.
	 */
	public function get( $key, $default_value = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default_value;
	}

	/**
	 * Writes default values for any missing setting.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function ensure_defaults() {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
			return;
		}

		update_option( self::OPTION, array_merge( self::defaults(), $stored ), false );
		$this->flush_cache();
	}

	/**
	 * Sanitizes the settings array submitted from the admin form.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string,mixed> Sanitized settings.
	 */
	public function sanitize( $input ) {
		$defaults = self::defaults();

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		$output = $defaults;

		$statuses = array( 'draft', 'publish', 'pending', 'private' );

		if ( isset( $input['default_post_status'] ) ) {
			$status = sanitize_key( $input['default_post_status'] );

			if ( in_array( $status, $statuses, true ) ) {
				$output['default_post_status'] = $status;
			}
		}

		if ( isset( $input['timestamp_tolerance'] ) ) {
			$tolerance                     = absint( $input['timestamp_tolerance'] );
			$output['timestamp_tolerance'] = max( 30, min( 3600, $tolerance ) );
		}

		$output['allow_media_import'] = ! empty( $input['allow_media_import'] );
		$output['enable_logging']     = ! empty( $input['enable_logging'] );
		$output['require_https']      = ! empty( $input['require_https'] );

		if ( isset( $input['log_retention_days'] ) ) {
			$output['log_retention_days'] = max( 1, min( 365, absint( $input['log_retention_days'] ) ) );
		}

		if ( isset( $input['media_allowed_hosts'] ) ) {
			$output['media_allowed_hosts'] = self::sanitize_host_list( $input['media_allowed_hosts'] );
		}

		if ( isset( $input['backend_url'] ) ) {
			$url = esc_url_raw( trim( (string) $input['backend_url'] ), array( 'https', 'http' ) );

			$output['backend_url'] = $url ? $url : '';
		}

		$intervals = array_keys( Scheduler::intervals() );

		if ( isset( $input['sync_interval'] ) ) {
			$interval = sanitize_key( $input['sync_interval'] );

			if ( in_array( $interval, $intervals, true ) ) {
				$output['sync_interval'] = $interval;
			}
		}

		if ( isset( $input['sync_connection'] ) ) {
			$key_id = sanitize_key( $input['sync_connection'] );

			$output['sync_connection'] = array_key_exists( $key_id, $this->connections() ) ? $key_id : '';
		}

		$output['require_approval'] = ! empty( $input['require_approval'] );

		if ( isset( $input['conflict_policy'] ) ) {
			$policy = sanitize_key( $input['conflict_policy'] );

			if ( array_key_exists( $policy, self::conflict_policies() ) ) {
				$output['conflict_policy'] = $policy;
			}
		}

		return $output;
	}

	/**
	 * Normalizes a newline or comma separated host list.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw host list.
	 * @return string[] Sanitized, unique host names.
	 */
	public static function sanitize_host_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/[\r\n,]+/', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$hosts = array();

		foreach ( $value as $host ) {
			$host = strtolower( trim( (string) $host ) );

			if ( '' === $host ) {
				continue;
			}

			// Accept a bare host name or strip one from a full URL.
			if ( false !== strpos( $host, '://' ) ) {
				$parsed = wp_parse_url( $host, PHP_URL_HOST );
				$host   = is_string( $parsed ) ? $parsed : '';
			}

			$host = preg_replace( '/[^a-z0-9.\-]/', '', $host );

			if ( '' === $host || false === strpos( $host, '.' ) ) {
				continue;
			}

			$hosts[] = $host;
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Returns every registered backend connection.
	 *
	 * Connections defined through the FORMA_PUBLISHER_CONNECTIONS constant take
	 * precedence and are never written to the database.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<string,mixed>> Connections keyed by key id.
	 */
	public function connections() {
		$stored = get_option( self::CONNECTIONS_OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$connections = array();

		foreach ( $stored as $key_id => $connection ) {
			$key_id = sanitize_key( $key_id );

			if ( '' === $key_id || ! is_array( $connection ) ) {
				continue;
			}

			$connections[ $key_id ] = array(
				'label'     => isset( $connection['label'] ) ? sanitize_text_field( $connection['label'] ) : $key_id,
				'secret'    => isset( $connection['secret'] ) ? (string) $connection['secret'] : '',
				'enabled'   => ! empty( $connection['enabled'] ),
				'created'   => isset( $connection['created'] ) ? absint( $connection['created'] ) : 0,
				'last_used' => isset( $connection['last_used'] ) ? absint( $connection['last_used'] ) : 0,
				'source'    => 'database',
			);
		}

		if ( defined( 'FORMA_PUBLISHER_CONNECTIONS' ) && is_array( FORMA_PUBLISHER_CONNECTIONS ) ) {
			foreach ( FORMA_PUBLISHER_CONNECTIONS as $key_id => $secret ) {
				$key_id = sanitize_key( $key_id );

				if ( '' === $key_id || ! is_string( $secret ) || '' === $secret ) {
					continue;
				}

				$connections[ $key_id ] = array(
					'label'     => $key_id,
					'secret'    => $secret,
					'enabled'   => true,
					'created'   => 0,
					'last_used' => isset( $connections[ $key_id ]['last_used'] ) ? $connections[ $key_id ]['last_used'] : 0,
					'source'    => 'constant',
				);
			}
		}

		return $connections;
	}

	/**
	 * Returns a single enabled connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key_id Connection key id.
	 * @return array<string,mixed>|null Connection data, or null when unknown or disabled.
	 */
	public function connection( $key_id ) {
		$key_id      = sanitize_key( $key_id );
		$connections = $this->connections();

		if ( ! isset( $connections[ $key_id ] ) ) {
			return null;
		}

		$connection = $connections[ $key_id ];

		if ( empty( $connection['enabled'] ) || '' === $connection['secret'] ) {
			return null;
		}

		return $connection;
	}

	/**
	 * Creates a connection and returns its generated shared secret.
	 *
	 * The secret is returned once and stored for signature verification.
	 *
	 * @since 1.0.0
	 *
	 * @param string $label Human readable connection label.
	 * @return array<string,string> Array with `key_id` and `secret` members.
	 */
	public function create_connection( $label ) {
		$connections = get_option( self::CONNECTIONS_OPTION, array() );

		if ( ! is_array( $connections ) ) {
			$connections = array();
		}

		$key_id = 'fp_' . strtolower( wp_generate_password( 16, false, false ) );
		$secret = wp_generate_password( 64, true, true );

		$connections[ $key_id ] = array(
			'label'     => sanitize_text_field( $label ),
			'secret'    => $secret,
			'enabled'   => true,
			'created'   => time(),
			'last_used' => 0,
		);

		update_option( self::CONNECTIONS_OPTION, $connections, false );

		return array(
			'key_id' => $key_id,
			'secret' => $secret,
		);
	}

	/**
	 * Enables or disables a stored connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key_id  Connection key id.
	 * @param bool   $enabled Whether the connection should accept requests.
	 * @return bool True when the connection was updated.
	 */
	public function set_connection_enabled( $key_id, $enabled ) {
		$key_id      = sanitize_key( $key_id );
		$connections = get_option( self::CONNECTIONS_OPTION, array() );

		if ( ! is_array( $connections ) || ! isset( $connections[ $key_id ] ) ) {
			return false;
		}

		$connections[ $key_id ]['enabled'] = (bool) $enabled;

		update_option( self::CONNECTIONS_OPTION, $connections, false );

		return true;
	}

	/**
	 * Permanently removes a stored connection.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key_id Connection key id.
	 * @return bool True when the connection was removed.
	 */
	public function delete_connection( $key_id ) {
		$key_id      = sanitize_key( $key_id );
		$connections = get_option( self::CONNECTIONS_OPTION, array() );

		if ( ! is_array( $connections ) || ! isset( $connections[ $key_id ] ) ) {
			return false;
		}

		unset( $connections[ $key_id ] );

		update_option( self::CONNECTIONS_OPTION, $connections, false );

		return true;
	}

	/**
	 * Records the time a connection was last used successfully.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key_id Connection key id.
	 * @return void
	 */
	public function touch_connection( $key_id ) {
		$key_id      = sanitize_key( $key_id );
		$connections = get_option( self::CONNECTIONS_OPTION, array() );

		if ( ! is_array( $connections ) || ! isset( $connections[ $key_id ] ) ) {
			return;
		}

		$connections[ $key_id ]['last_used'] = time();

		update_option( self::CONNECTIONS_OPTION, $connections, false );
	}
}
