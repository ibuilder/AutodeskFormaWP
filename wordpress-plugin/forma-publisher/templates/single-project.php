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

		$forma_publisher_project_markup = Forma_Publisher\Templates::render(
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

		// Every value is escaped inside project.php before it reaches this point.
		echo $forma_publisher_project_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	if ( comments_open() || get_comments_number() ) {
		comments_template();
	}

endwhile;

get_footer();
