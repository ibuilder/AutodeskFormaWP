<?php
/**
 * Project metrics template.
 *
 * Override by copying this file to `your-theme/forma-publisher/metrics.php`.
 *
 * @package Forma_Publisher
 *
 * @var array<string,mixed> $forma_publisher_data {
 *     @type \WP_Post                       $project Project post.
 *     @type array<int,array<string,mixed>> $metrics Metric rows.
 *     @type string                         $layout  Either `table` or `cards`.
 * }
 */

defined( 'ABSPATH' ) || exit;

$forma_publisher_metrics = isset( $forma_publisher_data['metrics'] ) ? (array) $forma_publisher_data['metrics'] : array();
$forma_publisher_layout  = isset( $forma_publisher_data['layout'] ) && 'cards' === $forma_publisher_data['layout'] ? 'cards' : 'table';

if ( empty( $forma_publisher_metrics ) ) {
	?>
	<p class="forma-publisher-empty"><?php esc_html_e( 'No metrics have been published for this project.', 'forma-publisher' ); ?></p>
	<?php
	return;
}

if ( 'cards' === $forma_publisher_layout ) :
	?>
	<div class="forma-publisher forma-publisher-metrics forma-publisher-metrics--cards">
		<?php foreach ( $forma_publisher_metrics as $forma_publisher_metric ) : ?>
			<div class="forma-publisher-metric">
				<span class="forma-publisher-metric__value"><?php echo esc_html( Forma_Publisher\Renderer::format_metric( $forma_publisher_metric ) ); ?></span>
				<span class="forma-publisher-metric__label"><?php echo esc_html( $forma_publisher_metric['label'] ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	return;
endif;
?>
<div class="forma-publisher forma-publisher-metrics forma-publisher-metrics--table">
	<table>
		<caption class="screen-reader-text"><?php esc_html_e( 'Published project metrics', 'forma-publisher' ); ?></caption>
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Metric', 'forma-publisher' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Value', 'forma-publisher' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $forma_publisher_metrics as $forma_publisher_metric ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $forma_publisher_metric['label'] ); ?></th>
					<td><?php echo esc_html( Forma_Publisher\Renderer::format_metric( $forma_publisher_metric ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
