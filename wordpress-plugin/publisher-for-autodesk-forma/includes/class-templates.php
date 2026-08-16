<?php
/**
 * Template loading with theme overrides.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Locates and renders plugin templates, allowing themes to override them.
 *
 * A theme overrides a template by placing a file with the same name inside a
 * `publisher-for-autodesk-forma` directory in the theme or child theme.
 *
 * @since 1.0.0
 */
class Templates {

	/**
	 * Directory name themes use to override plugin templates.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const THEME_DIR = 'publisher-for-autodesk-forma';

	/**
	 * Hooks the single project template fallback into WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'template_include', array( $this, 'single_project_template' ) );
	}

	/**
	 * Falls back to the bundled single project template when the theme has none.
	 *
	 * @since 1.0.0
	 *
	 * @param string $template Resolved template path.
	 * @return string Template path to load.
	 */
	public function single_project_template( $template ) {
		if ( ! is_singular( Post_Types::PROJECT ) ) {
			return $template;
		}

		$theme_template = locate_template( array( 'single-' . Post_Types::PROJECT . '.php' ) );

		if ( $theme_template ) {
			return $theme_template;
		}

		$override = self::locate( 'single-project.php' );

		return $override ? $override : $template;
	}

	/**
	 * Resolves a template file path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $name Template file name, for example `project-card.php`.
	 * @return string Absolute path, or an empty string when not found.
	 */
	public static function locate( $name ) {
		$name = basename( (string) $name );

		if ( '' === $name || ! preg_match( '/^[a-z0-9\-]+\.php$/', $name ) ) {
			return '';
		}

		$candidates = array(
			trailingslashit( get_stylesheet_directory() ) . self::THEME_DIR . '/' . $name,
			trailingslashit( get_template_directory() ) . self::THEME_DIR . '/' . $name,
			FORMA_PUBLISHER_DIR . 'templates/' . $name,
		);

		/**
		 * Filters the candidate paths searched for a plugin template.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $candidates Absolute candidate paths in priority order.
		 * @param string   $name       Template file name.
		 */
		$candidates = (array) apply_filters( 'forma_publisher_template_candidates', $candidates, $name );

		foreach ( $candidates as $candidate ) {
			if ( is_readable( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Renders a template straight to the output buffer.
	 *
	 * Preferred wherever the caller is itself producing output. Capturing a
	 * template into a string only to echo it immediately buys nothing, and it
	 * obscures the fact that every value was already escaped inside the
	 * template. Use render() only when a string is genuinely required, such as
	 * a shortcode return value.
	 *
	 * @since 1.2.0
	 *
	 * @param string              $name Template file name.
	 * @param array<string,mixed> $data Variables exposed to the template as `$forma_publisher_data`.
	 * @return void
	 */
	public static function output( $name, array $data = array() ) {
		$path = self::locate( $name );

		if ( '' === $path ) {
			return;
		}

		Assets::enqueue_frontend_style();

		/**
		 * Template variables.
		 *
		 * @var array<string,mixed> $forma_publisher_data
		 */
		$forma_publisher_data = $data;

		include $path;
	}

	/**
	 * Renders a template and returns its markup.
	 *
	 * @since 1.0.0
	 *
	 * @param string              $name Template file name.
	 * @param array<string,mixed> $data Variables exposed to the template as `$forma_publisher_data`.
	 * @return string Rendered markup.
	 */
	public static function render( $name, array $data = array() ) {
		$path = self::locate( $name );

		if ( '' === $path ) {
			return '';
		}

		Assets::enqueue_frontend_style();

		ob_start();

		/**
		 * Template variables.
		 *
		 * @var array<string,mixed> $forma_publisher_data
		 */
		$forma_publisher_data = $data;

		include $path;

		$markup = ob_get_clean();

		return is_string( $markup ) ? $markup : '';
	}
}
