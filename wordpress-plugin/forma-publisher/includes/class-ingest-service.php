<?php
/**
 * Applies canonical publish payloads to WordPress content.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a validated canonical payload into projects, metrics and assets.
 *
 * @since 1.0.0
 */
class Ingest_Service {

	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Content repository.
	 *
	 * @since 1.0.0
	 * @var Repository
	 */
	private $repository;

	/**
	 * Audit trail writer.
	 *
	 * @since 1.0.0
	 * @var Audit_Log
	 */
	private $audit_log;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings   $settings   Settings instance.
	 * @param Repository $repository Repository instance.
	 * @param Audit_Log  $audit_log  Audit log instance.
	 */
	public function __construct( Settings $settings, Repository $repository, Audit_Log $audit_log ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->audit_log  = $audit_log;
	}

	/**
	 * Applies a canonical payload.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $payload       Validated canonical payload.
	 * @param string              $connection_id Verified connection key id.
	 * @return array<string,mixed>|\WP_Error Result summary, or an error.
	 */
	public function handle( array $payload, $connection_id ) {
		$operation = isset( $payload['operation'] ) ? sanitize_key( $payload['operation'] ) : '';
		$mode      = isset( $payload['mode'] ) ? sanitize_key( $payload['mode'] ) : 'snapshot';
		$job_id    = isset( $payload['job_id'] ) ? sanitize_text_field( $payload['job_id'] ) : '';
		$project   = isset( $payload['project'] ) && is_array( $payload['project'] ) ? $payload['project'] : array();
		$source_id = isset( $project['source_id'] ) ? sanitize_text_field( $project['source_id'] ) : '';

		if ( '' === $source_id ) {
			return new \WP_Error(
				'forma_publisher_missing_source_id',
				__( 'The payload does not contain a project source id.', 'forma-publisher' ),
				array( 'status' => 400 )
			);
		}

		switch ( $operation ) {
			case 'publish':
			case 'update':
				$result = $this->upsert_project( $project, $connection_id, $mode );
				break;
			case 'unpublish':
				$result = $this->change_state( $source_id, 'draft', 'unpublished' );
				break;
			case 'archive':
				$result = $this->change_state( $source_id, 'private', 'archived' );
				break;
			case 'delete':
				$result = $this->trash( $source_id );
				break;
			default:
				$result = new \WP_Error(
					'forma_publisher_unsupported_operation',
					__( 'The requested operation is not supported.', 'forma-publisher' ),
					array( 'status' => 400 )
				);
		}

		if ( is_wp_error( $result ) ) {
			$this->audit_log->log(
				array(
					'operation'  => $operation,
					'result'     => 'error',
					'message'    => $result->get_error_message(),
					'connection' => $connection_id,
					'job_id'     => $job_id,
					'source_id'  => $source_id,
				)
			);

			return $result;
		}

		$this->audit_log->log(
			array(
				'operation'    => $operation,
				'result'       => isset( $result['status'] ) && 'unchanged' === $result['status'] ? 'skipped' : 'success',
				'message'      => isset( $result['message'] ) ? $result['message'] : '',
				'connection'   => $connection_id,
				'job_id'       => $job_id,
				'source_id'    => $source_id,
				'post_id'      => isset( $result['post_id'] ) ? $result['post_id'] : 0,
				'payload_hash' => isset( $result['payload_hash'] ) ? $result['payload_hash'] : '',
			)
		);

		/**
		 * Fires after a publish operation has been applied.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,mixed> $result        Operation result.
		 * @param array<string,mixed> $payload       Canonical payload.
		 * @param string              $connection_id Connection key id.
		 */
		do_action( 'forma_publisher_ingested', $result, $payload, $connection_id );

		do_action( 'forma_publisher_content_changed' );

		return $result;
	}

	/**
	 * Creates or updates the project post for a canonical project.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $project       Canonical project data.
	 * @param string              $connection_id Connection key id.
	 * @param string              $mode          Publishing mode.
	 * @param bool                $force         Skip the local edit check. Used when an
	 *                                           operator has explicitly chosen to apply
	 *                                           an update that was held for review.
	 * @return array<string,mixed>|\WP_Error Result summary, or an error.
	 */
	private function upsert_project( array $project, $connection_id, $mode, $force = false ) {
		$source_id = sanitize_text_field( $project['source_id'] );
		$hash      = hash( 'sha256', wp_json_encode( $project ) );
		$existing  = $this->repository->find_by_source_id( Post_Types::PROJECT, $source_id );

		if ( $existing && get_post_meta( $existing, '_forma_payload_hash', true ) === $hash ) {
			return array(
				'status'       => 'unchanged',
				'post_id'      => $existing,
				'source_id'    => $source_id,
				'payload_hash' => $hash,
				'message'      => __( 'Payload matches the stored version; nothing to update.', 'forma-publisher' ),
			);
		}

		/*
		 * Protect editorial work. If the post changed since the plugin last
		 * wrote to it, an incoming update would silently discard those edits.
		 */
		if ( ! $force && $existing && Review::has_local_edits( $existing ) ) {
			$policy = (string) $this->settings->get( 'conflict_policy', 'hold' );

			if ( 'skip' === $policy ) {
				return array(
					'status'       => 'skipped_local_edit',
					'post_id'      => $existing,
					'source_id'    => $source_id,
					'payload_hash' => $hash,
					'message'      => __( 'The project was edited in WordPress, so the update was discarded.', 'forma-publisher' ),
				);
			}

			if ( 'hold' === $policy ) {
				Review::hold( $existing, $project, $connection_id, $mode, $hash );
				Review::flush_attention_count();

				return array(
					'status'       => 'held_for_review',
					'post_id'      => $existing,
					'source_id'    => $source_id,
					'payload_hash' => $hash,
					'message'      => __( 'The project was edited in WordPress, so the update is waiting for review.', 'forma-publisher' ),
				);
			}
		}

		$status = isset( $project['status'] ) ? sanitize_key( $project['status'] ) : '';

		if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ) {
			$status = (string) $this->settings->get( 'default_post_status', 'draft' );
		}

		// A new project can be required to pass through editorial review first.
		if ( ! $existing && $this->settings->get( 'require_approval', false ) && 'publish' === $status ) {
			$status = 'pending';
		}

		$postarr = array(
			'post_type'    => Post_Types::PROJECT,
			'post_title'   => sanitize_text_field( $project['title'] ),
			'post_content' => isset( $project['content'] ) ? wp_kses_post( $project['content'] ) : '',
			'post_excerpt' => isset( $project['summary'] ) ? sanitize_textarea_field( $project['summary'] ) : '',
			'post_status'  => $status,
		);

		if ( ! empty( $project['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $project['slug'] );
		}

		if ( $existing ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;

		$this->repository->prime_lookup( Post_Types::PROJECT, $source_id, $post_id );
		$this->store_project_meta( $post_id, $project, $connection_id, $mode, $hash );
		$this->apply_terms( $post_id, $project );

		$assets = isset( $project['assets'] ) && is_array( $project['assets'] ) ? $project['assets'] : array();
		$synced = $this->sync_assets( $post_id, $assets, $connection_id );

		$media_note = '';

		if ( ! empty( $project['featured_image']['url'] ) ) {
			$attachment_id = $this->import_featured_image( $post_id, $project['featured_image'] );

			if ( is_wp_error( $attachment_id ) ) {
				$media_note = $attachment_id->get_error_message();
			}
		}

		/*
		 * Recorded last, after every write, so the stored modification time
		 * reflects the state the plugin produced. Anything later is a human.
		 */
		Review::record_sync( $post_id );
		Review::clear( $post_id );
		Review::flush_attention_count();

		return array(
			'status'       => $existing ? 'updated' : 'created',
			'post_id'      => $post_id,
			'source_id'    => $source_id,
			'permalink'    => get_permalink( $post_id ),
			'assets'       => $synced,
			'payload_hash' => $hash,
			'message'      => $media_note,
		);
	}

	/**
	 * Applies an update that was parked for editorial review.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id Project post id.
	 * @return array<string,mixed>|\WP_Error Result summary, or an error.
	 */
	public function apply_held_update( $post_id ) {
		$held = Review::held( $post_id );

		if ( null === $held ) {
			return new \WP_Error(
				'forma_publisher_no_held_update',
				__( 'There is no update waiting for review on that project.', 'forma-publisher' ),
				array( 'status' => 404 )
			);
		}

		Review::clear( $post_id );

		/*
		 * The conflict is derived from the post's modification time, which is
		 * still divergent, so the check has to be skipped explicitly. Clearing
		 * the hold alone would let the update be parked again immediately.
		 */
		$result = $this->upsert_project(
			$held['project'],
			isset( $held['connection'] ) ? (string) $held['connection'] : '',
			isset( $held['mode'] ) ? (string) $held['mode'] : 'snapshot',
			true
		);

		if ( ! is_wp_error( $result ) ) {
			$this->audit_log->log(
				array(
					'operation'  => 'review_applied',
					'result'     => 'success',
					'connection' => isset( $held['connection'] ) ? (string) $held['connection'] : '',
					'source_id'  => isset( $held['project']['source_id'] ) ? (string) $held['project']['source_id'] : '',
					'post_id'    => $post_id,
				)
			);
		}

		Review::flush_attention_count();

		return $result;
	}

	/**
	 * Persists project meta values.
	 *
	 * @since 1.0.0
	 *
	 * @param int                 $post_id       Project post id.
	 * @param array<string,mixed> $project       Canonical project data.
	 * @param string              $connection_id Connection key id.
	 * @param string              $mode          Publishing mode.
	 * @param string              $hash          Payload hash.
	 * @return void
	 */
	private function store_project_meta( $post_id, array $project, $connection_id, $mode, $hash ) {
		$map = array(
			Post_Types::META_SOURCE_ID => isset( $project['source_id'] ) ? $project['source_id'] : '',
			'_forma_source_system'     => isset( $project['source_system'] ) ? $project['source_system'] : 'autodesk-forma',
			'_forma_source_url'        => isset( $project['source_url'] ) ? esc_url_raw( $project['source_url'] ) : '',
			'_forma_hub_id'            => isset( $project['hub_id'] ) ? $project['hub_id'] : '',
			'_forma_project_id'        => isset( $project['project_id'] ) ? $project['project_id'] : '',
			'_forma_proposal_id'       => isset( $project['proposal_id'] ) ? $project['proposal_id'] : '',
			'_forma_source_updated_at' => isset( $project['source_updated_at'] ) ? $project['source_updated_at'] : '',
			'_forma_sync_mode'         => 'sync' === $mode ? 'sync' : 'snapshot',
			'_forma_connection_id'     => $connection_id,
			'_forma_payload_hash'      => $hash,
			'_forma_last_synced'       => gmdate( 'c' ),
			'_forma_publish_state'     => 'published',
		);

		foreach ( $map as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		update_post_meta( $post_id, '_forma_metrics', $this->sanitize_metrics( isset( $project['metrics'] ) ? $project['metrics'] : array() ) );
		update_post_meta( $post_id, '_forma_location', $this->sanitize_location( isset( $project['location'] ) ? $project['location'] : array() ) );
	}

	/**
	 * Normalizes the metrics collection.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $metrics Raw metrics value.
	 * @return array<int,array<string,mixed>> Sanitized metrics.
	 */
	private function sanitize_metrics( $metrics ) {
		if ( ! is_array( $metrics ) ) {
			return array();
		}

		$clean = array();

		foreach ( $metrics as $metric ) {
			if ( ! is_array( $metric ) || empty( $metric['key'] ) ) {
				continue;
			}

			$value = isset( $metric['value'] ) ? $metric['value'] : null;

			if ( is_string( $value ) ) {
				$value = sanitize_text_field( $value );
			} elseif ( ! is_numeric( $value ) && null !== $value ) {
				$value = null;
			}

			$clean[] = array(
				'key'       => sanitize_key( $metric['key'] ),
				'label'     => isset( $metric['label'] ) ? sanitize_text_field( $metric['label'] ) : sanitize_key( $metric['key'] ),
				'value'     => is_numeric( $value ) ? (float) $value : $value,
				'unit'      => isset( $metric['unit'] ) ? sanitize_text_field( $metric['unit'] ) : '',
				'category'  => isset( $metric['category'] ) ? sanitize_text_field( $metric['category'] ) : '',
				'precision' => isset( $metric['precision'] ) ? max( 0, min( 8, (int) $metric['precision'] ) ) : 2,
			);
		}

		return $clean;
	}

	/**
	 * Normalizes the location object.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $location Raw location value.
	 * @return array<string,mixed> Sanitized location.
	 */
	private function sanitize_location( $location ) {
		if ( ! is_array( $location ) ) {
			return array();
		}

		$clean = array();

		if ( isset( $location['latitude'] ) && is_numeric( $location['latitude'] ) ) {
			$clean['latitude'] = max( -90, min( 90, (float) $location['latitude'] ) );
		}

		if ( isset( $location['longitude'] ) && is_numeric( $location['longitude'] ) ) {
			$clean['longitude'] = max( -180, min( 180, (float) $location['longitude'] ) );
		}

		if ( ! empty( $location['address'] ) ) {
			$clean['address'] = sanitize_text_field( $location['address'] );
		}

		return $clean;
	}

	/**
	 * Applies tags and statuses to a project post.
	 *
	 * @since 1.0.0
	 *
	 * @param int                 $post_id Project post id.
	 * @param array<string,mixed> $project Canonical project data.
	 * @return void
	 */
	private function apply_terms( $post_id, array $project ) {
		$map = array(
			Taxonomies::TAG    => isset( $project['tags'] ) ? $project['tags'] : null,
			Taxonomies::STATUS => isset( $project['statuses'] ) ? $project['statuses'] : null,
		);

		foreach ( $map as $taxonomy => $terms ) {
			if ( ! is_array( $terms ) ) {
				continue;
			}

			$clean = array();

			foreach ( $terms as $term ) {
				$term = sanitize_text_field( (string) $term );

				if ( '' !== $term ) {
					$clean[] = $term;
				}
			}

			wp_set_object_terms( $post_id, $clean, $taxonomy, false );
		}
	}

	/**
	 * Creates or updates asset posts for a project and removes stale ones.
	 *
	 * @since 1.0.0
	 *
	 * @param int                            $project_id    Project post id.
	 * @param array<int,array<string,mixed>> $assets        Canonical asset list.
	 * @param string                         $connection_id Connection key id.
	 * @return array<string,int> Counts of created, updated and removed assets.
	 */
	private function sync_assets( $project_id, array $assets, $connection_id ) {
		$counts = array(
			'created' => 0,
			'updated' => 0,
			'removed' => 0,
		);

		$seen = array();

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || empty( $asset['source_id'] ) || empty( $asset['title'] ) ) {
				continue;
			}

			$source_id = sanitize_text_field( $asset['source_id'] );
			$existing  = $this->repository->find_by_source_id( Post_Types::ASSET, $source_id );

			$postarr = array(
				'post_type'    => Post_Types::ASSET,
				'post_title'   => sanitize_text_field( $asset['title'] ),
				'post_excerpt' => isset( $asset['summary'] ) ? sanitize_textarea_field( $asset['summary'] ) : '',
				'post_status'  => 'publish',
			);

			if ( $existing ) {
				$postarr['ID'] = $existing;
				$asset_id      = wp_update_post( $postarr, true );
			} else {
				$asset_id = wp_insert_post( $postarr, true );
			}

			if ( is_wp_error( $asset_id ) ) {
				continue;
			}

			$asset_id = (int) $asset_id;
			$seen[]   = $asset_id;

			$this->repository->prime_lookup( Post_Types::ASSET, $source_id, $asset_id );

			$kinds = array( 'image', 'document', 'model', 'dataset', 'link' );
			$kind  = isset( $asset['kind'] ) ? sanitize_key( $asset['kind'] ) : 'document';

			update_post_meta( $asset_id, Post_Types::META_SOURCE_ID, $source_id );
			update_post_meta( $asset_id, Post_Types::META_PARENT_PROJECT, $project_id );
			update_post_meta( $asset_id, '_forma_asset_kind', in_array( $kind, $kinds, true ) ? $kind : 'document' );
			update_post_meta( $asset_id, '_forma_asset_url', isset( $asset['url'] ) ? esc_url_raw( $asset['url'] ) : '' );
			update_post_meta( $asset_id, '_forma_asset_mime', isset( $asset['mime_type'] ) ? sanitize_mime_type( $asset['mime_type'] ) : '' );
			update_post_meta( $asset_id, '_forma_asset_size', isset( $asset['size'] ) ? absint( $asset['size'] ) : 0 );
			update_post_meta( $asset_id, '_forma_asset_checksum', isset( $asset['checksum'] ) ? sanitize_text_field( $asset['checksum'] ) : '' );
			update_post_meta( $asset_id, '_forma_connection_id', $connection_id );
			update_post_meta( $asset_id, '_forma_last_synced', gmdate( 'c' ) );

			if ( $existing ) {
				++$counts['updated'];
			} else {
				++$counts['created'];
			}
		}

		foreach ( $this->repository->assets_for_project( $project_id, 200 ) as $existing_asset ) {
			if ( in_array( (int) $existing_asset->ID, $seen, true ) ) {
				continue;
			}

			$source_id = (string) get_post_meta( $existing_asset->ID, Post_Types::META_SOURCE_ID, true );

			if ( wp_trash_post( $existing_asset->ID ) ) {
				$this->repository->forget_lookup( Post_Types::ASSET, $source_id );
				++$counts['removed'];
			}
		}

		return $counts;
	}

	/**
	 * Downloads and attaches a featured image when media import is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @param int                 $post_id Project post id.
	 * @param array<string,mixed> $image   Featured image descriptor.
	 * @return int|\WP_Error Attachment id, or an error explaining why it was skipped.
	 */
	private function import_featured_image( $post_id, array $image ) {
		if ( ! $this->settings->get( 'allow_media_import', false ) ) {
			return new \WP_Error(
				'forma_publisher_media_disabled',
				__( 'Remote media import is disabled; the featured image was skipped.', 'forma-publisher' )
			);
		}

		$url = isset( $image['url'] ) ? esc_url_raw( $image['url'] ) : '';

		if ( '' === $url ) {
			return new \WP_Error( 'forma_publisher_media_invalid_url', __( 'The featured image URL is not valid.', 'forma-publisher' ) );
		}

		$host    = wp_parse_url( $url, PHP_URL_HOST );
		$allowed = (array) $this->settings->get( 'media_allowed_hosts', array() );

		if ( ! is_string( $host ) || ! in_array( strtolower( $host ), $allowed, true ) ) {
			return new \WP_Error(
				'forma_publisher_media_host_blocked',
				__( 'The featured image host is not on the allowed list; the image was skipped.', 'forma-publisher' )
			);
		}

		$existing_hash = get_post_meta( $post_id, '_forma_featured_source', true );

		if ( $existing_hash === $url && has_post_thumbnail( $post_id ) ) {
			return (int) get_post_thumbnail_id( $post_id );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp_file = download_url( $url, 30 );

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$file_name = isset( $image['filename'] ) ? sanitize_file_name( $image['filename'] ) : '';

		if ( '' === $file_name ) {
			$path      = (string) wp_parse_url( $url, PHP_URL_PATH );
			$file_name = sanitize_file_name( basename( $path ) );
		}

		if ( '' === $file_name ) {
			$file_name = 'forma-image';
		}

		$file_array = array(
			'name'     => $file_name,
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $temp_file );

			return $attachment_id;
		}

		if ( ! empty( $image['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $image['alt'] ) );
		}

		set_post_thumbnail( $post_id, $attachment_id );
		update_post_meta( $post_id, '_forma_featured_source', $url );

		return (int) $attachment_id;
	}

	/**
	 * Moves a published project into another post status.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source_id Upstream identifier.
	 * @param string $status    Target post status.
	 * @param string $state     Stored publish state label.
	 * @return array<string,mixed>|\WP_Error Result summary, or an error.
	 */
	private function change_state( $source_id, $status, $state ) {
		$post_id = $this->repository->find_by_source_id( Post_Types::PROJECT, $source_id );

		if ( ! $post_id ) {
			return new \WP_Error(
				'forma_publisher_not_found',
				__( 'No published project matches that source id.', 'forma-publisher' ),
				array( 'status' => 404 )
			);
		}

		$updated = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		update_post_meta( $post_id, '_forma_publish_state', $state );
		update_post_meta( $post_id, '_forma_last_synced', gmdate( 'c' ) );

		return array(
			'status'    => $state,
			'post_id'   => $post_id,
			'source_id' => $source_id,
		);
	}

	/**
	 * Moves a project and its assets to the trash.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source_id Upstream identifier.
	 * @return array<string,mixed>|\WP_Error Result summary, or an error.
	 */
	private function trash( $source_id ) {
		$post_id = $this->repository->find_by_source_id( Post_Types::PROJECT, $source_id );

		if ( ! $post_id ) {
			return new \WP_Error(
				'forma_publisher_not_found',
				__( 'No published project matches that source id.', 'forma-publisher' ),
				array( 'status' => 404 )
			);
		}

		foreach ( $this->repository->assets_for_project( $post_id, 200 ) as $asset ) {
			$asset_source = (string) get_post_meta( $asset->ID, Post_Types::META_SOURCE_ID, true );

			wp_trash_post( $asset->ID );
			$this->repository->forget_lookup( Post_Types::ASSET, $asset_source );
		}

		if ( ! wp_trash_post( $post_id ) ) {
			return new \WP_Error(
				'forma_publisher_trash_failed',
				__( 'The project could not be moved to the trash.', 'forma-publisher' ),
				array( 'status' => 500 )
			);
		}

		$this->repository->forget_lookup( Post_Types::PROJECT, $source_id );

		return array(
			'status'    => 'trashed',
			'post_id'   => $post_id,
			'source_id' => $source_id,
		);
	}
}
