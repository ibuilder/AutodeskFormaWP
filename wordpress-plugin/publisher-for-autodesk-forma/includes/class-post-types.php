<?php
/**
 * Custom post type and post meta registration.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the content types that store published Forma data.
 *
 * @since 1.0.0
 */
class Post_Types {

	/**
	 * Post type storing a published Forma project.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const PROJECT = 'forma_project';

	/**
	 * Post type storing a file or image linked to a project.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const ASSET = 'forma_asset';

	/**
	 * Post type storing audit trail entries.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const LOG = 'forma_log';

	/**
	 * Meta key holding the upstream Autodesk identifier.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_SOURCE_ID = '_forma_source_id';

	/**
	 * Meta key linking an asset to its parent project post.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const META_PARENT_PROJECT = '_forma_parent_project';

	/**
	 * Hooks registration into WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_post_types' ) );
		add_action( 'init', array( $this, 'register_post_meta' ) );
	}

	/**
	 * Registers every plugin post type.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_post_types() {
		register_post_type(
			self::PROJECT,
			array(
				'labels'              => array(
					'name'               => _x( 'Forma Projects', 'post type general name', 'publisher-for-autodesk-forma' ),
					'singular_name'      => _x( 'Forma Project', 'post type singular name', 'publisher-for-autodesk-forma' ),
					'menu_name'          => _x( 'Forma Projects', 'admin menu', 'publisher-for-autodesk-forma' ),
					'add_new_item'       => __( 'Add New Forma Project', 'publisher-for-autodesk-forma' ),
					'edit_item'          => __( 'Edit Forma Project', 'publisher-for-autodesk-forma' ),
					'new_item'           => __( 'New Forma Project', 'publisher-for-autodesk-forma' ),
					'view_item'          => __( 'View Forma Project', 'publisher-for-autodesk-forma' ),
					'search_items'       => __( 'Search Forma Projects', 'publisher-for-autodesk-forma' ),
					'not_found'          => __( 'No Forma projects found.', 'publisher-for-autodesk-forma' ),
					'not_found_in_trash' => __( 'No Forma projects found in Trash.', 'publisher-for-autodesk-forma' ),
					'all_items'          => __( 'All Projects', 'publisher-for-autodesk-forma' ),
				),
				'description'         => __( 'Projects published from Autodesk Forma.', 'publisher-for-autodesk-forma' ),
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => 'publisher-for-autodesk-forma',
				'show_in_rest'        => true,
				'rest_base'           => 'forma-projects',
				'has_archive'         => 'forma-projects',
				'hierarchical'        => false,
				'menu_icon'           => 'dashicons-building',
				'exclude_from_search' => false,
				'capability_type'     => array( 'forma_project', 'forma_projects' ),
				'capabilities'        => Capabilities::post_type_caps( 'forma_project', 'forma_projects' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
				'rewrite'             => array(
					'slug'       => 'forma-projects',
					'with_front' => false,
				),
			)
		);

		register_post_type(
			self::ASSET,
			array(
				'labels'              => array(
					'name'          => _x( 'Forma Assets', 'post type general name', 'publisher-for-autodesk-forma' ),
					'singular_name' => _x( 'Forma Asset', 'post type singular name', 'publisher-for-autodesk-forma' ),
					'menu_name'     => _x( 'Forma Assets', 'admin menu', 'publisher-for-autodesk-forma' ),
					'edit_item'     => __( 'Edit Forma Asset', 'publisher-for-autodesk-forma' ),
					'search_items'  => __( 'Search Forma Assets', 'publisher-for-autodesk-forma' ),
					'not_found'     => __( 'No Forma assets found.', 'publisher-for-autodesk-forma' ),
					'all_items'     => __( 'All Assets', 'publisher-for-autodesk-forma' ),
				),
				'description'         => __( 'Files and images published from Autodesk Forma.', 'publisher-for-autodesk-forma' ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'publisher-for-autodesk-forma',
				'show_in_rest'        => true,
				'rest_base'           => 'forma-assets',
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'hierarchical'        => false,
				'exclude_from_search' => true,
				'capability_type'     => array( 'forma_asset', 'forma_assets' ),
				'capabilities'        => Capabilities::post_type_caps( 'forma_asset', 'forma_assets' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'excerpt', 'thumbnail' ),
				'rewrite'             => false,
			)
		);

		register_post_type(
			self::LOG,
			array(
				'labels'              => array(
					'name'          => _x( 'Forma Publish Log', 'post type general name', 'publisher-for-autodesk-forma' ),
					'singular_name' => _x( 'Forma Publish Log Entry', 'post type singular name', 'publisher-for-autodesk-forma' ),
				),
				'description'         => __( 'Audit trail of inbound publish operations.', 'publisher-for-autodesk-forma' ),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_rest'        => false,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'exclude_from_search' => true,
				'capability_type'     => array( 'forma_log', 'forma_logs' ),
				'capabilities'        => Capabilities::post_type_caps( 'forma_log', 'forma_logs' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title' ),
				'rewrite'             => false,
				'can_export'          => false,
			)
		);
	}

	/**
	 * Returns the meta keys registered for project posts.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Meta key to scalar type map.
	 */
	public static function project_meta_keys() {
		return array(
			self::META_SOURCE_ID       => 'string',
			'_forma_source_system'     => 'string',
			'_forma_source_url'        => 'string',
			'_forma_hub_id'            => 'string',
			'_forma_project_id'        => 'string',
			'_forma_proposal_id'       => 'string',
			'_forma_sync_mode'         => 'string',
			'_forma_payload_hash'      => 'string',
			'_forma_connection_id'     => 'string',
			'_forma_last_synced'       => 'string',
			'_forma_source_updated_at' => 'string',
			'_forma_publish_state'     => 'string',
			'_forma_synced_modified'   => 'string',
			'_forma_held_at'           => 'string',
		);
	}

	/**
	 * Returns the meta keys registered for asset posts.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Meta key to scalar type map.
	 */
	public static function asset_meta_keys() {
		return array(
			self::META_SOURCE_ID    => 'string',
			'_forma_asset_kind'     => 'string',
			'_forma_asset_url'      => 'string',
			'_forma_asset_mime'     => 'string',
			'_forma_asset_checksum' => 'string',
			'_forma_connection_id'  => 'string',
			'_forma_last_synced'    => 'string',
		);
	}

	/**
	 * Registers post meta for the plugin post types.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_post_meta() {
		foreach ( self::project_meta_keys() as $key => $type ) {
			register_post_meta(
				self::PROJECT,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => array( __CLASS__, 'sanitize_scalar_meta' ),
					'auth_callback'     => array( __CLASS__, 'auth_project_meta' ),
				)
			);
		}

		register_post_meta(
			self::PROJECT,
			'_forma_featured_source',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'esc_url_raw',
				'auth_callback'     => array( __CLASS__, 'auth_project_meta' ),
			)
		);

		foreach ( array( '_forma_metrics', '_forma_location', '_forma_held_payload' ) as $key ) {
			register_post_meta(
				self::PROJECT,
				$key,
				array(
					'type'          => 'array',
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => array( __CLASS__, 'auth_project_meta' ),
				)
			);
		}

		foreach ( self::asset_meta_keys() as $key => $type ) {
			register_post_meta(
				self::ASSET,
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => false,
					'sanitize_callback' => array( __CLASS__, 'sanitize_scalar_meta' ),
					'auth_callback'     => array( __CLASS__, 'auth_asset_meta' ),
				)
			);
		}

		register_post_meta(
			self::ASSET,
			self::META_PARENT_PROJECT,
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_asset_meta' ),
			)
		);

		register_post_meta(
			self::ASSET,
			'_forma_asset_size',
			array(
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => array( __CLASS__, 'auth_asset_meta' ),
			)
		);
	}

	/**
	 * Sanitizes a scalar meta value.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw meta value.
	 * @return string Sanitized value.
	 */
	public static function sanitize_scalar_meta( $value ) {
		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		return '';
	}

	/**
	 * Authorizes writes to project meta.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $allowed   Current permission.
	 * @param string $meta_key  Meta key being written.
	 * @param int    $object_id Post id.
	 * @return bool True when the current user may edit the post.
	 */
	public static function auth_project_meta( $allowed, $meta_key, $object_id ) {
		unset( $allowed, $meta_key );

		return current_user_can( 'edit_forma_project', $object_id );
	}

	/**
	 * Authorizes writes to asset meta.
	 *
	 * @since 1.0.0
	 *
	 * @param bool   $allowed   Current permission.
	 * @param string $meta_key  Meta key being written.
	 * @param int    $object_id Post id.
	 * @return bool True when the current user may edit the post.
	 */
	public static function auth_asset_meta( $allowed, $meta_key, $object_id ) {
		unset( $allowed, $meta_key );

		return current_user_can( 'edit_forma_asset', $object_id );
	}
}
