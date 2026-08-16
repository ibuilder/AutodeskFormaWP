<?php
/**
 * Custom capability management.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Declares and assigns the capabilities used across the plugin.
 *
 * @since 1.0.0
 */
class Capabilities {

	/**
	 * Capability required to change plugin settings and connections.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const MANAGE = 'forma_manage_settings';

	/**
	 * Capability required to view the publish audit trail.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const VIEW_LOGS = 'forma_view_logs';

	/**
	 * Returns the capability map for a Forma content post type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $singular Singular capability base, for example `forma_project`.
	 * @param string $plural   Plural capability base, for example `forma_projects`.
	 * @return array<string,string> Capability map for register_post_type().
	 */
	public static function post_type_caps( $singular, $plural ) {
		return array(
			'edit_post'              => 'edit_' . $singular,
			'read_post'              => 'read_' . $singular,
			'delete_post'            => 'delete_' . $singular,
			'edit_posts'             => 'edit_' . $plural,
			'edit_others_posts'      => 'edit_others_' . $plural,
			'publish_posts'          => 'publish_' . $plural,
			'read_private_posts'     => 'read_private_' . $plural,
			'delete_posts'           => 'delete_' . $plural,
			'delete_private_posts'   => 'delete_private_' . $plural,
			'delete_published_posts' => 'delete_published_' . $plural,
			'delete_others_posts'    => 'delete_others_' . $plural,
			'edit_private_posts'     => 'edit_private_' . $plural,
			'edit_published_posts'   => 'edit_published_' . $plural,
			'create_posts'           => 'edit_' . $plural,
		);
	}

	/**
	 * Returns every capability introduced by the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Flat list of capability names.
	 */
	public static function all_caps() {
		$caps = array( self::MANAGE, self::VIEW_LOGS );

		$types = array(
			'forma_project' => 'forma_projects',
			'forma_asset'   => 'forma_assets',
			'forma_log'     => 'forma_logs',
		);

		foreach ( $types as $singular => $plural ) {
			foreach ( self::post_type_caps( $singular, $plural ) as $cap ) {
				$caps[] = $cap;
			}
		}

		return array_values( array_unique( $caps ) );
	}

	/**
	 * Grants plugin capabilities to the administrator and editor roles.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function add_caps() {
		$all = self::all_caps();

		$administrator = get_role( 'administrator' );

		if ( $administrator instanceof \WP_Role ) {
			foreach ( $all as $cap ) {
				$administrator->add_cap( $cap );
			}
		}

		$editor = get_role( 'editor' );

		if ( $editor instanceof \WP_Role ) {
			foreach ( $all as $cap ) {
				if ( self::MANAGE === $cap ) {
					continue;
				}

				$editor->add_cap( $cap );
			}
		}
	}

	/**
	 * Removes every plugin capability from every role.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function remove_caps() {
		$roles = wp_roles();
		$all   = self::all_caps();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( ! $role instanceof \WP_Role ) {
				continue;
			}

			foreach ( $all as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
