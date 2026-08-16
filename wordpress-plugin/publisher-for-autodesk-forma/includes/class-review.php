<?php
/**
 * Editorial review and local edit protection.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Detects locally edited content and holds incoming updates for review.
 *
 * Snapshot publishing has an inherent conflict: an editor may improve a project
 * page after it was published, and the next sync would overwrite that work
 * without anyone noticing. This class records the post's modification time at
 * each sync so a later divergence can be recognised, and parks the incoming
 * payload instead of applying it.
 *
 * @since 1.1.0
 */
class Review {

	/**
	 * Meta key holding the modification time recorded at the last sync.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const META_SYNCED_MODIFIED = '_forma_synced_modified';

	/**
	 * Meta key holding an update parked for review.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const META_HELD_PAYLOAD = '_forma_held_payload';

	/**
	 * Meta key holding the time an update was parked.
	 *
	 * @since 1.1.0
	 * @var string
	 */
	const META_HELD_AT = '_forma_held_at';

	/**
	 * Records the current modification time as the last synced state.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Project post id.
	 * @return void
	 */
	public static function record_sync( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		update_post_meta( $post_id, self::META_SYNCED_MODIFIED, $post->post_modified_gmt );
	}

	/**
	 * Reports whether a post changed since the plugin last wrote to it.
	 *
	 * A post with no recorded sync time predates this feature. It is treated as
	 * unedited so that upgrading the plugin does not suddenly park every update.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Project post id.
	 * @return bool True when the post was edited outside the plugin.
	 */
	public static function has_local_edits( $post_id ) {
		$recorded = (string) get_post_meta( $post_id, self::META_SYNCED_MODIFIED, true );

		if ( '' === $recorded ) {
			return false;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return $post->post_modified_gmt !== $recorded;
	}

	/**
	 * Parks an incoming update against a post for later review.
	 *
	 * @since 1.1.0
	 *
	 * @param int                 $post_id       Project post id.
	 * @param array<string,mixed> $project       Canonical project data.
	 * @param string              $connection_id Connection key id.
	 * @param string              $mode          Publishing mode.
	 * @param string              $hash          Payload hash.
	 * @return void
	 */
	public static function hold( $post_id, array $project, $connection_id, $mode, $hash ) {
		update_post_meta(
			$post_id,
			self::META_HELD_PAYLOAD,
			array(
				'project'    => $project,
				'connection' => sanitize_key( $connection_id ),
				'mode'       => sanitize_key( $mode ),
				'hash'       => sanitize_text_field( $hash ),
			)
		);

		update_post_meta( $post_id, self::META_HELD_AT, gmdate( 'c' ) );
	}

	/**
	 * Returns the update parked against a post.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Project post id.
	 * @return array<string,mixed>|null Parked payload, or null when there is none.
	 */
	public static function held( $post_id ) {
		$held = get_post_meta( $post_id, self::META_HELD_PAYLOAD, true );

		if ( ! is_array( $held ) || empty( $held['project'] ) || ! is_array( $held['project'] ) ) {
			return null;
		}

		return $held;
	}

	/**
	 * Clears any parked update.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Project post id.
	 * @return void
	 */
	public static function clear( $post_id ) {
		delete_post_meta( $post_id, self::META_HELD_PAYLOAD );
		delete_post_meta( $post_id, self::META_HELD_AT );
	}

	/**
	 * Returns project posts with an update awaiting review.
	 *
	 * @since 1.1.0
	 *
	 * @param int $limit Maximum posts to return.
	 * @return \WP_Post[] Matching posts.
	 */
	public static function held_posts( $limit = 50 ) {
		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::PROJECT,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => max( 1, min( 100, (int) $limit ) ),
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed lookup on a single meta key for an admin screen.
					array(
						'key'     => self::META_HELD_PAYLOAD,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return $query->posts;
	}

	/**
	 * Returns the number of projects awaiting editorial attention.
	 *
	 * Counts both parked updates and projects sitting in pending review.
	 *
	 * @since 1.1.0
	 *
	 * @return int Count of items needing attention.
	 */
	public static function attention_count() {
		$cached = wp_cache_get( 'forma_attention_count', 'forma_publisher' );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$held = new \WP_Query(
			array(
				'post_type'              => Post_Types::PROJECT,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'         => 100,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed lookup on a single meta key.
					array(
						'key'     => self::META_HELD_PAYLOAD,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$counts  = wp_count_posts( Post_Types::PROJECT );
		$pending = isset( $counts->pending ) ? (int) $counts->pending : 0;
		$total   = count( $held->posts ) + $pending;

		wp_cache_set( 'forma_attention_count', $total, 'forma_publisher', 5 * MINUTE_IN_SECONDS );

		return $total;
	}

	/**
	 * Invalidates the cached attention count.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function flush_attention_count() {
		wp_cache_delete( 'forma_attention_count', 'forma_publisher' );
	}
}
