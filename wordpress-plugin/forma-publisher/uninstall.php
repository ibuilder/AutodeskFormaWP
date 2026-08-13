<?php
/**
 * Uninstall routine.
 *
 * Removes plugin options, scheduled events, capabilities and log entries.
 * Published projects and assets are intentionally preserved so that a site
 * does not lose editorial content when the plugin is removed.
 *
 * @package Forma_Publisher
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes plugin data for a single site.
 *
 * @since 1.0.0
 *
 * @return void
 */
function forma_publisher_uninstall_site() {
	delete_option( 'forma_publisher_settings' );
	delete_option( 'forma_publisher_connections' );
	delete_option( 'forma_publisher_installed_version' );

	wp_clear_scheduled_hook( 'forma_publisher_sync_refresh' );
	wp_clear_scheduled_hook( 'forma_publisher_purge_logs' );

	$log_entries = get_posts(
		array(
			'post_type'              => 'forma_log',
			'post_status'            => 'any',
			'numberposts'            => -1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	foreach ( $log_entries as $log_entry_id ) {
		wp_delete_post( (int) $log_entry_id, true );
	}

	$capabilities = array(
		'forma_manage_settings',
		'forma_view_logs',
	);

	$types = array(
		'forma_project' => 'forma_projects',
		'forma_asset'   => 'forma_assets',
		'forma_log'     => 'forma_logs',
	);

	foreach ( $types as $singular => $plural ) {
		$capabilities[] = 'edit_' . $singular;
		$capabilities[] = 'read_' . $singular;
		$capabilities[] = 'delete_' . $singular;
		$capabilities[] = 'edit_' . $plural;
		$capabilities[] = 'edit_others_' . $plural;
		$capabilities[] = 'publish_' . $plural;
		$capabilities[] = 'read_private_' . $plural;
		$capabilities[] = 'delete_' . $plural;
		$capabilities[] = 'delete_private_' . $plural;
		$capabilities[] = 'delete_published_' . $plural;
		$capabilities[] = 'delete_others_' . $plural;
		$capabilities[] = 'edit_private_' . $plural;
		$capabilities[] = 'edit_published_' . $plural;
	}

	$roles = wp_roles();

	foreach ( array_keys( $roles->roles ) as $role_name ) {
		$role = get_role( $role_name );

		if ( ! $role instanceof WP_Role ) {
			continue;
		}

		foreach ( $capabilities as $capability ) {
			$role->remove_cap( $capability );
		}
	}
}

if ( is_multisite() ) {
	$forma_publisher_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $forma_publisher_sites as $forma_publisher_site_id ) {
		switch_to_blog( (int) $forma_publisher_site_id );
		forma_publisher_uninstall_site();
		restore_current_blog();
	}
} else {
	forma_publisher_uninstall_site();
}
