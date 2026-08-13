<?php
/**
 * Front end and editor asset registration.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Registers plugin stylesheets and enqueues them only where needed.
 *
 * @since 1.0.0
 */
class Assets {

	/**
	 * Handle of the shared front end stylesheet.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const STYLE_HANDLE = 'forma-publisher';

	/**
	 * Hooks asset registration into WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_styles' ), 5 );
		add_action( 'enqueue_block_assets', array( $this, 'register_styles' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_admin_styles' ) );
	}

	/**
	 * Registers the shared stylesheet without enqueuing it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_styles() {
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::STYLE_HANDLE,
			FORMA_PUBLISHER_URL . 'assets/css/forma-publisher.css',
			array(),
			FORMA_PUBLISHER_VERSION
		);
	}

	/**
	 * Enqueues the admin stylesheet on plugin screens only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin screen hook.
	 * @return void
	 */
	public function register_admin_styles( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, 'forma-publisher' ) ) {
			return;
		}

		wp_enqueue_style(
			'forma-publisher-admin',
			FORMA_PUBLISHER_URL . 'assets/css/admin.css',
			array(),
			FORMA_PUBLISHER_VERSION
		);
	}

	/**
	 * Enqueues the front end stylesheet on demand.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function enqueue_frontend_style() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::STYLE_HANDLE,
				FORMA_PUBLISHER_URL . 'assets/css/forma-publisher.css',
				array(),
				FORMA_PUBLISHER_VERSION
			);
		}

		wp_enqueue_style( self::STYLE_HANDLE );
	}
}
