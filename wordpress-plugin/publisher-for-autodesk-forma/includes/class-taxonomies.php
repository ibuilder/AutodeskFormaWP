<?php
/**
 * Taxonomy registration.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the taxonomies used to classify published projects.
 *
 * @since 1.0.0
 */
class Taxonomies {

	/**
	 * Free form tag taxonomy.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const TAG = 'forma_project_tag';

	/**
	 * Editorial status taxonomy.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const STATUS = 'forma_project_status';

	/**
	 * Hooks registration into WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_taxonomies' ) );
	}

	/**
	 * Registers every plugin taxonomy.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_taxonomies() {
		register_taxonomy(
			self::TAG,
			array( Post_Types::PROJECT ),
			array(
				'labels'            => array(
					'name'          => _x( 'Forma Tags', 'taxonomy general name', 'publisher-for-autodesk-forma' ),
					'singular_name' => _x( 'Forma Tag', 'taxonomy singular name', 'publisher-for-autodesk-forma' ),
					'search_items'  => __( 'Search Forma Tags', 'publisher-for-autodesk-forma' ),
					'all_items'     => __( 'All Forma Tags', 'publisher-for-autodesk-forma' ),
					'edit_item'     => __( 'Edit Forma Tag', 'publisher-for-autodesk-forma' ),
					'add_new_item'  => __( 'Add New Forma Tag', 'publisher-for-autodesk-forma' ),
				),
				'public'            => true,
				'hierarchical'      => false,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'capabilities'      => array(
					'manage_terms' => 'edit_forma_projects',
					'edit_terms'   => 'edit_forma_projects',
					'delete_terms' => 'edit_forma_projects',
					'assign_terms' => 'edit_forma_projects',
				),
				'rewrite'           => array(
					'slug'       => 'forma-tag',
					'with_front' => false,
				),
			)
		);

		register_taxonomy(
			self::STATUS,
			array( Post_Types::PROJECT ),
			array(
				'labels'            => array(
					'name'          => _x( 'Forma Statuses', 'taxonomy general name', 'publisher-for-autodesk-forma' ),
					'singular_name' => _x( 'Forma Status', 'taxonomy singular name', 'publisher-for-autodesk-forma' ),
					'search_items'  => __( 'Search Forma Statuses', 'publisher-for-autodesk-forma' ),
					'all_items'     => __( 'All Forma Statuses', 'publisher-for-autodesk-forma' ),
					'edit_item'     => __( 'Edit Forma Status', 'publisher-for-autodesk-forma' ),
					'add_new_item'  => __( 'Add New Forma Status', 'publisher-for-autodesk-forma' ),
				),
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'capabilities'      => array(
					'manage_terms' => 'edit_forma_projects',
					'edit_terms'   => 'edit_forma_projects',
					'delete_terms' => 'edit_forma_projects',
					'assign_terms' => 'edit_forma_projects',
				),
				'rewrite'           => array(
					'slug'       => 'forma-status',
					'with_front' => false,
				),
			)
		);
	}
}
