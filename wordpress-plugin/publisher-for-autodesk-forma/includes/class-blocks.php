<?php
/**
 * Block registration.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the server rendered blocks bundled with the plugin.
 *
 * @since 1.0.0
 */
class Blocks {

	/**
	 * Block directory names relative to `blocks/`.
	 *
	 * @since 1.0.0
	 * @var string[]
	 */
	private $blocks = array( 'project-list', 'project', 'metrics', 'assets' );

	/**
	 * Hooks block registration into WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_filter( 'block_categories_all', array( $this, 'register_category' ) );
	}

	/**
	 * Registers every bundled block from its block.json metadata.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_blocks() {
		foreach ( $this->blocks as $block ) {
			$path = FORMA_PUBLISHER_DIR . 'blocks/' . $block;

			if ( is_readable( $path . '/block.json' ) ) {
				register_block_type( $path );
			}
		}
	}

	/**
	 * Adds the plugin block category to the editor.
	 *
	 * @since 1.0.0
	 *
	 * @param array<int,array<string,mixed>> $categories Registered categories.
	 * @return array<int,array<string,mixed>> Categories including the plugin category.
	 */
	public function register_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			return $categories;
		}

		foreach ( $categories as $category ) {
			if ( isset( $category['slug'] ) && 'publisher-for-autodesk-forma' === $category['slug'] ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => 'publisher-for-autodesk-forma',
			'title' => __( 'Forma', 'publisher-for-autodesk-forma' ),
			'icon'  => null,
		);

		return $categories;
	}
}
