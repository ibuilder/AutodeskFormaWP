<?php
/**
 * Shortcode handlers.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the plugin views as shortcodes.
 *
 * @since 1.0.0
 */
class Shortcodes {

	/**
	 * Registers every shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'forma_project_list', array( $this, 'project_list' ) );
		add_shortcode( 'forma_project', array( $this, 'project' ) );
		add_shortcode( 'forma_metrics', array( $this, 'metrics' ) );
		add_shortcode( 'forma_assets', array( $this, 'assets' ) );
	}

	/**
	 * Renders the `[forma_project_list]` shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string Rendered markup.
	 */
	public function project_list( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'          => 10,
				'columns'        => 3,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'tag'            => '',
				'status'         => '',
				'show_excerpt'   => 'yes',
				'show_thumbnail' => 'yes',
				'show_metrics'   => 'no',
			),
			$this->normalize( $atts ),
			'forma_project_list'
		);

		return Renderer::project_list(
			array(
				'limit'          => (int) $atts['limit'],
				'columns'        => (int) $atts['columns'],
				'orderby'        => (string) $atts['orderby'],
				'order'          => (string) $atts['order'],
				'tag'            => (string) $atts['tag'],
				'status'         => (string) $atts['status'],
				'show_excerpt'   => self::to_bool( $atts['show_excerpt'] ),
				'show_thumbnail' => self::to_bool( $atts['show_thumbnail'] ),
				'show_metrics'   => self::to_bool( $atts['show_metrics'] ),
			)
		);
	}

	/**
	 * Renders the `[forma_project]` shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string Rendered markup.
	 */
	public function project( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'             => '',
				'show_metrics'   => 'yes',
				'show_assets'    => 'yes',
				'show_thumbnail' => 'yes',
				'show_content'   => 'yes',
			),
			$this->normalize( $atts ),
			'forma_project'
		);

		return Renderer::project(
			array(
				'id'             => (string) $atts['id'],
				'show_metrics'   => self::to_bool( $atts['show_metrics'] ),
				'show_assets'    => self::to_bool( $atts['show_assets'] ),
				'show_thumbnail' => self::to_bool( $atts['show_thumbnail'] ),
				'show_content'   => self::to_bool( $atts['show_content'] ),
			)
		);
	}

	/**
	 * Renders the `[forma_metrics]` shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string Rendered markup.
	 */
	public function metrics( $atts ) {
		$atts = shortcode_atts(
			array(
				'project'  => '',
				'category' => '',
				'keys'     => '',
				'layout'   => 'table',
			),
			$this->normalize( $atts ),
			'forma_metrics'
		);

		return Renderer::metrics(
			array(
				'project'  => (string) $atts['project'],
				'category' => (string) $atts['category'],
				'keys'     => (string) $atts['keys'],
				'layout'   => (string) $atts['layout'],
			)
		);
	}

	/**
	 * Renders the `[forma_assets]` shortcode.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 * @return string Rendered markup.
	 */
	public function assets( $atts ) {
		$atts = shortcode_atts(
			array(
				'project' => '',
				'kind'    => '',
				'limit'   => 50,
			),
			$this->normalize( $atts ),
			'forma_assets'
		);

		return Renderer::assets(
			array(
				'project' => (string) $atts['project'],
				'kind'    => (string) $atts['kind'],
				'limit'   => (int) $atts['limit'],
			)
		);
	}

	/**
	 * Normalizes the raw shortcode attribute value into an array.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed>|string $atts Raw attributes.
	 * @return array<string,mixed> Attribute array.
	 */
	private function normalize( $atts ) {
		return is_array( $atts ) ? $atts : array();
	}

	/**
	 * Converts a shortcode attribute into a boolean.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw attribute value.
	 * @return bool Parsed boolean.
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
}
