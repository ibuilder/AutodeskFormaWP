<?php
/**
 * Lookup helpers for published Forma content.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Finds published posts by their upstream Autodesk identifiers.
 *
 * @since 1.0.0
 */
class Repository {

	/**
	 * No hooks are required; the class only exposes query helpers.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'forma_publisher_content_changed', array( $this, 'flush_lookup_cache' ) );
	}

	/**
	 * Returns the post id for an upstream source id.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type to search.
	 * @param string $source_id Upstream identifier.
	 * @return int Post id, or 0 when not found.
	 */
	public function find_by_source_id( $post_type, $source_id ) {
		$source_id = (string) $source_id;

		if ( '' === $source_id ) {
			return 0;
		}

		$cache_key = 'forma_src_' . md5( $post_type . '|' . $source_id );
		$cached    = wp_cache_get( $cache_key, 'forma_publisher' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed lookup on a single meta key, results cached below.
					array(
						'key'     => Post_Types::META_SOURCE_ID,
						'value'   => $source_id,
						'compare' => '=',
					),
				),
			)
		);

		$post_id = empty( $query->posts ) ? 0 : (int) $query->posts[0];

		wp_cache_set( $cache_key, $post_id, 'forma_publisher', HOUR_IN_SECONDS );

		return $post_id;
	}

	/**
	 * Stores the source id lookup cache entry for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type.
	 * @param string $source_id Upstream identifier.
	 * @param int    $post_id   Post id.
	 * @return void
	 */
	public function prime_lookup( $post_type, $source_id, $post_id ) {
		$cache_key = 'forma_src_' . md5( $post_type . '|' . (string) $source_id );

		wp_cache_set( $cache_key, (int) $post_id, 'forma_publisher', HOUR_IN_SECONDS );
	}

	/**
	 * Invalidates the source id lookup cache for a post.
	 *
	 * @since 1.0.0
	 *
	 * @param string $post_type Post type.
	 * @param string $source_id Upstream identifier.
	 * @return void
	 */
	public function forget_lookup( $post_type, $source_id ) {
		$cache_key = 'forma_src_' . md5( $post_type . '|' . (string) $source_id );

		wp_cache_delete( $cache_key, 'forma_publisher' );
	}

	/**
	 * Clears the whole plugin cache group where the object cache supports it.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function flush_lookup_cache() {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'forma_publisher' );
		}
	}

	/**
	 * Returns published project posts.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $args Query overrides.
	 * @return \WP_Post[] Matching posts.
	 */
	public function projects( array $args = array() ) {
		$defaults = array(
			'post_type'              => Post_Types::PROJECT,
			'post_status'            => 'publish',
			'posts_per_page'         => 10,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_term_cache' => false,
		);

		$query = new \WP_Query( array_merge( $defaults, $args ) );

		return $query->posts;
	}

	/**
	 * Returns asset posts attached to a project.
	 *
	 * @since 1.0.0
	 *
	 * @param int $project_id Project post id.
	 * @param int $limit      Maximum number of assets.
	 * @return \WP_Post[] Matching asset posts.
	 */
	public function assets_for_project( $project_id, $limit = 50 ) {
		$project_id = absint( $project_id );

		if ( ! $project_id ) {
			return array();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::ASSET,
				'post_status'            => array( 'publish', 'private' ),
				'posts_per_page'         => max( 1, min( 200, (int) $limit ) ),
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed lookup on a single meta key.
					array(
						'key'     => Post_Types::META_PARENT_PROJECT,
						'value'   => $project_id,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Resolves a project post from a shortcode or block attribute.
	 *
	 * Accepts a numeric post id or an upstream source id.
	 *
	 * @since 1.0.0
	 *
	 * @param string|int $reference Post id or source id.
	 * @return \WP_Post|null Project post, or null when not found.
	 */
	public function resolve_project( $reference ) {
		$reference = trim( (string) $reference );

		if ( '' === $reference ) {
			return null;
		}

		if ( ctype_digit( $reference ) ) {
			$post = get_post( (int) $reference );
		} else {
			$post_id = $this->find_by_source_id( Post_Types::PROJECT, $reference );
			$post    = $post_id ? get_post( $post_id ) : null;
		}

		if ( ! $post instanceof \WP_Post || Post_Types::PROJECT !== $post->post_type ) {
			return null;
		}

		return $post;
	}
}
