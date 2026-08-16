<?php
/**
 * Single project template partial.
 *
 * Override by copying this file to `your-theme/forma-publisher/project.php`.
 *
 * @package Forma_Publisher
 *
 * @var array<string,mixed> $forma_publisher_data {
 *     @type \WP_Post                       $project        Project post.
 *     @type array<int,array<string,mixed>> $metrics        Metric rows.
 *     @type \WP_Post[]                     $assets         Asset posts.
 *     @type array<string,mixed>            $location       Location data.
 *     @type bool                           $show_thumbnail Whether to render the thumbnail.
 *     @type bool                           $show_content   Whether to render the body content.
 * }
 */

defined( 'ABSPATH' ) || exit;

$forma_publisher_project = isset( $forma_publisher_data['project'] ) ? $forma_publisher_data['project'] : null;

if ( ! $forma_publisher_project instanceof WP_Post ) {
	return;
}

$forma_publisher_metrics        = isset( $forma_publisher_data['metrics'] ) ? (array) $forma_publisher_data['metrics'] : array();
$forma_publisher_assets         = isset( $forma_publisher_data['assets'] ) ? (array) $forma_publisher_data['assets'] : array();
$forma_publisher_location       = isset( $forma_publisher_data['location'] ) ? (array) $forma_publisher_data['location'] : array();
$forma_publisher_show_thumbnail = ! empty( $forma_publisher_data['show_thumbnail'] );
$forma_publisher_show_content   = ! empty( $forma_publisher_data['show_content'] );
$forma_publisher_source_url     = (string) get_post_meta( $forma_publisher_project->ID, '_forma_source_url', true );
$forma_publisher_last_synced    = (string) get_post_meta( $forma_publisher_project->ID, '_forma_last_synced', true );
?>
<article class="forma-publisher forma-publisher-project" id="forma-project-<?php echo esc_attr( (string) $forma_publisher_project->ID ); ?>">
	<header class="forma-publisher-project__header">
		<h2 class="forma-publisher-project__title"><?php echo esc_html( get_the_title( $forma_publisher_project ) ); ?></h2>

		<?php if ( ! empty( $forma_publisher_location['address'] ) ) : ?>
			<p class="forma-publisher-project__location"><?php echo esc_html( $forma_publisher_location['address'] ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( $forma_publisher_show_thumbnail && has_post_thumbnail( $forma_publisher_project ) ) : ?>
		<figure class="forma-publisher-project__media">
			<?php echo wp_kses_post( get_the_post_thumbnail( $forma_publisher_project, 'large', array( 'loading' => 'lazy' ) ) ); ?>
		</figure>
	<?php endif; ?>

	<?php if ( $forma_publisher_show_content ) : ?>
		<div class="forma-publisher-project__content">
			<?php echo wp_kses_post( wpautop( $forma_publisher_project->post_content ) ); ?>
		</div>
	<?php endif; ?>

	<?php
	// Each sub-template writes straight to the output buffer and escapes its
	// own values, so nothing unescaped passes through this file.
	if ( ! empty( $forma_publisher_metrics ) ) {
		Forma_Publisher\Templates::output(
			'metrics.php',
			array(
				'project' => $forma_publisher_project,
				'metrics' => $forma_publisher_metrics,
				'layout'  => 'table',
			)
		);
	}

	if ( ! empty( $forma_publisher_assets ) ) {
		Forma_Publisher\Templates::output(
			'assets.php',
			array(
				'project' => $forma_publisher_project,
				'assets'  => $forma_publisher_assets,
			)
		);
	}
	?>

	<footer class="forma-publisher-project__footer">
		<?php if ( '' !== $forma_publisher_source_url ) : ?>
			<p class="forma-publisher-project__source">
				<a href="<?php echo esc_url( $forma_publisher_source_url ); ?>" rel="nofollow noopener external" target="_blank">
					<?php esc_html_e( 'View in Autodesk Forma', 'publisher-for-autodesk-forma' ); ?>
				</a>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $forma_publisher_last_synced ) : ?>
			<?php $forma_publisher_synced_ts = strtotime( $forma_publisher_last_synced ); ?>
			<?php if ( $forma_publisher_synced_ts ) : ?>
				<p class="forma-publisher-project__synced">
					<?php
					printf(
						/* translators: %s: formatted date and time of the last synchronization. */
						esc_html__( 'Last synchronized %s', 'publisher-for-autodesk-forma' ),
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $forma_publisher_synced_ts ) )
					);
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</footer>
</article>
