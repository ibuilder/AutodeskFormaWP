<?php
/**
 * Plugin container and bootstrapper.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every plugin module into WordPress.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Instantiated modules keyed by service name.
	 *
	 * @since 1.0.0
	 * @var array<string,object>
	 */
	private $services = array();

	/**
	 * Tracks whether boot() already ran.
	 *
	 * @since 1.0.0
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Registers hooks for every module.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->services['settings']   = new Settings();
		$this->services['post_types'] = new Post_Types();
		$this->services['taxonomies'] = new Taxonomies();
		$this->services['audit_log']  = new Audit_Log();
		$this->services['repository'] = new Repository();
		$this->services['templates']  = new Templates();
		$this->services['assets']     = new Assets();
		$this->services['shortcodes'] = new Shortcodes();
		$this->services['blocks']     = new Blocks();
		$this->services['rest']       = new REST_Routes( $this->services['settings'], $this->services['repository'], $this->services['audit_log'] );
		$this->services['scheduler']  = new Scheduler( $this->services['settings'], $this->services['audit_log'] );

		if ( is_admin() ) {
			$this->services['admin'] = new Admin\Admin( $this->services['settings'], $this->services['audit_log'] );
		}

		foreach ( $this->services as $service ) {
			if ( method_exists( $service, 'register' ) ) {
				$service->register();
			}
		}

		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 5 );
	}

	/**
	 * Returns a registered service instance.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Service key.
	 * @return object|null Service instance, or null when not registered.
	 */
	public function service( $name ) {
		return isset( $this->services[ $name ] ) ? $this->services[ $name ] : null;
	}
}
