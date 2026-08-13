<?php
/**
 * Publish audit trail.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Records every inbound publish operation as an auditable log entry.
 *
 * Entries are stored as posts of the private `forma_log` post type so that no
 * custom database table or direct SQL is required.
 *
 * @since 1.0.0
 */
class Audit_Log {

	/**
	 * Cron hook used to trim old entries.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const CLEANUP_HOOK = 'forma_publisher_purge_logs';

	/**
	 * Registers cleanup hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CLEANUP_HOOK, array( $this, 'purge_expired' ) );
	}

	/**
	 * Writes a log entry.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $entry {
	 *     Log entry fields.
	 *
	 *     @type string $operation    Operation name.
	 *     @type string $result       Either `success`, `error` or `skipped`.
	 *     @type string $message      Human readable outcome.
	 *     @type string $connection   Connection key id.
	 *     @type string $job_id       Backend job id.
	 *     @type string $source_id    Upstream identifier.
	 *     @type int    $post_id      Affected post id.
	 *     @type string $payload_hash Hash of the accepted payload.
	 * }
	 * @return int Log post id, or 0 when logging is disabled or failed.
	 */
	public function log( array $entry ) {
		$settings = new Settings();

		if ( ! $settings->get( 'enable_logging', true ) ) {
			return 0;
		}

		$defaults = array(
			'operation'    => 'unknown',
			'result'       => 'success',
			'message'      => '',
			'connection'   => '',
			'job_id'       => '',
			'source_id'    => '',
			'post_id'      => 0,
			'payload_hash' => '',
		);

		$entry = array_merge( $defaults, $entry );

		$title = sprintf(
			/* translators: 1: operation name, 2: upstream source identifier. */
			__( '%1$s · %2$s', 'forma-publisher' ),
			sanitize_text_field( $entry['operation'] ),
			sanitize_text_field( '' !== $entry['source_id'] ? $entry['source_id'] : '—' )
		);

		$post_id = wp_insert_post(
			array(
				'post_type'    => Post_Types::LOG,
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '',
				'meta_input'   => array(
					'_forma_log_operation'    => sanitize_text_field( $entry['operation'] ),
					'_forma_log_result'       => sanitize_key( $entry['result'] ),
					'_forma_log_message'      => sanitize_text_field( $entry['message'] ),
					'_forma_log_connection'   => sanitize_key( $entry['connection'] ),
					'_forma_log_job_id'       => sanitize_text_field( $entry['job_id'] ),
					'_forma_log_source_id'    => sanitize_text_field( $entry['source_id'] ),
					'_forma_log_post_id'      => absint( $entry['post_id'] ),
					'_forma_log_payload_hash' => sanitize_text_field( $entry['payload_hash'] ),
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		return (int) $post_id;
	}

	/**
	 * Returns recent log entries.
	 *
	 * @since 1.0.0
	 *
	 * @param int $paged    Page number, starting at 1.
	 * @param int $per_page Entries per page.
	 * @return array{items:\WP_Post[],total:int} Log entries and total count.
	 */
	public function entries( $paged = 1, $per_page = 25 ) {
		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::LOG,
				'post_status'            => 'publish',
				'posts_per_page'         => max( 1, min( 100, (int) $per_page ) ),
				'paged'                  => max( 1, (int) $paged ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'update_post_term_cache' => false,
			)
		);

		return array(
			'items' => $query->posts,
			'total' => (int) $query->found_posts,
		);
	}

	/**
	 * Deletes log entries older than the configured retention period.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of entries removed.
	 */
	public function purge_expired() {
		$settings = new Settings();
		$days     = (int) $settings->get( 'log_retention_days', 30 );
		$days     = max( 1, min( 365, $days ) );

		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::LOG,
				'post_status'            => 'any',
				'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Batch size for deletion, not a rendered listing.
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
				'date_query'             => array(
					array(
						'column' => 'post_date_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ),
					),
				),
			)
		);

		$deleted = 0;

		foreach ( $query->posts as $post_id ) {
			if ( wp_delete_post( (int) $post_id, true ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Deletes every log entry.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of entries removed.
	 */
	public function purge_all() {
		$deleted = 0;

		do {
			$query = new \WP_Query(
				array(
					'post_type'              => Post_Types::LOG,
					'post_status'            => 'any',
					'posts_per_page'         => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Batch size for deletion, not a rendered listing.
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'update_post_meta_cache' => false,
				)
			);

			foreach ( $query->posts as $post_id ) {
				if ( wp_delete_post( (int) $post_id, true ) ) {
					++$deleted;
				}
			}
		} while ( ! empty( $query->posts ) );

		return $deleted;
	}
}
