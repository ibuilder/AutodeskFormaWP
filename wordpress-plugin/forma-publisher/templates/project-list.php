<?php
/**
 * Project list template.
 *
 * Override by copying this file to `your-theme/forma-publisher/project-list.php`.
 *
 * @package Forma_Publisher
 *
 * @var array<string,mixed> $forma_publisher_data {
 *     @type \WP_Post[] $projects       Project posts.
 *     @type int        $columns        Grid column count.
 *     @type bool       $show_excerpt   Whether to render excerpts.
 *     @type bool       $show_thumbnail Whether to render thumbnails.
 *     @type bool       $show_metrics   Whether to render a metric summary.
 * }
 */

defined( 'ABSPATH' ) || exit;

$forma_publisher_projects       = isset( $forma_publisher_data['projects'] ) ? (array) $forma_publisher_data['projects'] : array();
$forma_publisher_columns        = isset( $forma_publisher_data['columns'] ) ? (int) $forma_publisher_data['columns'] : 3;
$forma_publisher_show_excerpt   = ! empty( $forma_publisher_data['show_excerpt'] );
$forma_publisher_show_thumbnail = ! empty( $forma_publisher_data['show_thumbnail'] );
$forma_publisher_show_metrics   = ! empty( $forma_publisher_data['show_metrics'] );

if ( empty( $forma_publisher_projects ) ) {
	?>
	<p class="forma-publisher-empty"><?php esc_html_e( 'No Forma projects have been published yet.', 'forma-publisher' ); ?></p>
	<?php
	return;
}
?>
<div class="forma-publisher forma-publisher-list forma-publisher-cols-<?php echo esc_attr( (string) $forma_publisher_columns ); ?>">
	<?php foreach ( $forma_publisher_projects as $forma_publisher_project ) : ?>
		<?php
		if ( ! $forma_publisher_project instanceof WP_Post ) {
			continue;
		}

		$forma_publisher_permalink = get_permalink( $forma_publisher_project );
		?>
		<article class="forma-publisher-card" id="forma-project-<?php echo esc_attr( (string) $forma_publisher_project->ID ); ?>">
			<?php if ( $forma_publisher_show_thumbnail && has_post_thumbnail( $forma_publisher_project ) ) : ?>
				<a class="forma-publisher-card__media" href="<?php echo esc_url( (string) $forma_publisher_permalink ); ?>">
					<?php echo get_the_post_thumbnail( $forma_publisher_project, 'medium_large', array( 'loading' => 'lazy' ) ); ?>
				</a>
			<?php endif; ?>

			<h3 class="forma-publisher-card__title">
				<a href="<?php echo esc_url( (string) $forma_publisher_permalink ); ?>">
					<?php echo esc_html( get_the_title( $forma_publisher_project ) ); ?>
				</a>
			</h3>

			<?php if ( $forma_publisher_show_excerpt ) : ?>
				<?php $forma_publisher_excerpt = get_the_excerpt( $forma_publisher_project ); ?>
				<?php if ( '' !== $forma_publisher_excerpt ) : ?>
					<p class="forma-publisher-card__excerpt"><?php echo esc_html( $forma_publisher_excerpt ); ?></p>
				<?php endif; ?>
			<?php endif; ?>

			<?php
			if ( $forma_publisher_show_metrics ) :
				$forma_publisher_metrics = array_slice( Forma_Publisher\Renderer::metrics_for( $forma_publisher_project->ID ), 0, 3 );
				?>
				<?php if ( ! empty( $forma_publisher_metrics ) ) : ?>
					<ul class="forma-publisher-card__metrics">
						<?php foreach ( $forma_publisher_metrics as $forma_publisher_metric ) : ?>
							<li>
								<span class="forma-publisher-metric__label"><?php echo esc_html( $forma_publisher_metric['label'] ); ?></span>
								<span class="forma-publisher-metric__value"><?php echo esc_html( Forma_Publisher\Renderer::format_metric( $forma_publisher_metric ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</div>
