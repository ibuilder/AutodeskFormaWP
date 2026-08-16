<?php
/**
 * Class file autoloader.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Maps namespaced class names onto WordPress style class file names.
 *
 * @since 1.0.0
 */
class Autoloader {

	/**
	 * Sub directories searched for class files, in priority order.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private static $paths = array(
		'includes/',
		'includes/admin/',
		'includes/rest/',
		'includes/render/',
	);

	/**
	 * Registers the autoloader with the SPL stack.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Loads a class file for the given fully qualified class name.
	 *
	 * @since 1.0.0
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( $class_name ) {
		if ( 0 !== strpos( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( __NAMESPACE__ . '\\' ) );
		$relative = str_replace( '\\', '/', $relative );
		$segments = explode( '/', $relative );
		$short    = array_pop( $segments );

		$file_name = 'class-' . strtolower( str_replace( '_', '-', $short ) ) . '.php';

		foreach ( self::$paths as $path ) {
			$candidate = FORMA_PUBLISHER_DIR . $path . $file_name;

			if ( is_readable( $candidate ) ) {
				require_once $candidate;
				return;
			}
		}
	}
}
