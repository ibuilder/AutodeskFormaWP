<?php
/**
 * Activation, deactivation and upgrade routines.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Handles one time setup work for the plugin.
 *
 * @since 1.0.0
 */
class Installer {

	/**
	 * Option key holding the installed schema version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const VERSION_OPTION = 'forma_publisher_installed_version';

	/**
	 * Runs on plugin activation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function activate() {
		$post_types = new Post_Types();
		$post_types->register_post_types();

		$taxonomies = new Taxonomies();
		$taxonomies->register_taxonomies();

		Capabilities::add_caps();

		$settings = new Settings();
		$settings->ensure_defaults();

		update_option( self::VERSION_OPTION, FORMA_PUBLISHER_VERSION, false );

		flush_rewrite_rules();
	}

	/**
	 * Runs on plugin deactivation.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::clear_events();

		flush_rewrite_rules();
	}

	/**
	 * Applies upgrade routines when the stored version is behind the plugin version.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = get_option( self::VERSION_OPTION, '' );

		if ( FORMA_PUBLISHER_VERSION === $installed ) {
			return;
		}

		Capabilities::add_caps();

		$settings = new Settings();
		$settings->ensure_defaults();

		update_option( self::VERSION_OPTION, FORMA_PUBLISHER_VERSION, false );
	}
}
