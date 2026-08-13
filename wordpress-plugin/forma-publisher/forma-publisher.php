<?php
/**
 * Plugin Name:       Forma Publisher
 * Plugin URI:        https://github.com/ibuilder/forma-to-wordpress
 * Description:       Receives signed, normalized Autodesk Forma content from a trusted backend service and renders it as WordPress projects, metrics and assets.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            iBuilder
 * Author URI:        https://github.com/ibuilder
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       forma-publisher
 * Domain Path:       /languages
 *
 * @package Forma_Publisher
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'FORMA_PUBLISHER_VERSION' ) ) {
	return;
}

define( 'FORMA_PUBLISHER_VERSION', '1.0.0' );
define( 'FORMA_PUBLISHER_FILE', __FILE__ );
define( 'FORMA_PUBLISHER_DIR', plugin_dir_path( __FILE__ ) );
define( 'FORMA_PUBLISHER_URL', plugin_dir_url( __FILE__ ) );
define( 'FORMA_PUBLISHER_BASENAME', plugin_basename( __FILE__ ) );

require_once FORMA_PUBLISHER_DIR . 'includes/class-autoloader.php';

Forma_Publisher\Autoloader::register();

register_activation_hook( __FILE__, array( 'Forma_Publisher\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Forma_Publisher\\Installer', 'deactivate' ) );

/**
 * Returns the shared plugin instance.
 *
 * @since 1.0.0
 *
 * @return \Forma_Publisher\Plugin Plugin container.
 */
function forma_publisher() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new Forma_Publisher\Plugin();
	}

	return $instance;
}

forma_publisher()->boot();
