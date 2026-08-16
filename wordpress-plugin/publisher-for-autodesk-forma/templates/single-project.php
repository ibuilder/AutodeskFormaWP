<?php
/**
 * Fallback single project template.
 *
 * Used only when the active theme provides no `single-forma_project.php`.
 * Override by copying this file to `your-theme/forma-publisher/single-project.php`
 * or by adding `single-forma_project.php` to the theme root.
 *
 * @package Forma_Publisher
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$forma_publisher_post = get_post();

	if ( $forma_publisher_post instanceof WP_Post ) {
		$forma_publisher_repository = new Forma_Publisher\Repository();

		// project.php writes straight to the output buffer and escapes its own
		// values, so nothing unescaped passes through this file.
		Forma_Publisher\Templates::output(
			'project.php',
			array(
				'project'        => $forma_publisher_post,
				'metrics'        => Forma_Publisher\Renderer::metrics_for( $forma_publisher_post->ID ),
				'assets'         => $forma_publisher_repository->assets_for_project( $forma_publisher_post->ID ),
				'location'       => Forma_Publisher\Renderer::location_for( $forma_publisher_post->ID ),
				'show_thumbnail' => true,
				'show_content'   => true,
			)
		);
	}

	if ( comments_open() || get_comments_number() ) {
		comments_template();
	}

endwhile;

get_footer();
