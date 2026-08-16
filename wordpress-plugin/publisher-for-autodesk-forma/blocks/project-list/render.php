<?php
/**
 * Server side render callback for the Forma project list block.
 *
 * @package Forma_Publisher
 *
 * @var array<string,mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$forma_publisher_markup = Forma_Publisher\Renderer::project_list(
	array(
		'limit'          => isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 10,
		'columns'        => isset( $attributes['columns'] ) ? (int) $attributes['columns'] : 3,
		'orderby'        => isset( $attributes['orderby'] ) ? (string) $attributes['orderby'] : 'date',
		'order'          => isset( $attributes['order'] ) ? (string) $attributes['order'] : 'DESC',
		'tag'            => isset( $attributes['tag'] ) ? (string) $attributes['tag'] : '',
		'status'         => isset( $attributes['status'] ) ? (string) $attributes['status'] : '',
		'show_excerpt'   => ! isset( $attributes['showExcerpt'] ) || (bool) $attributes['showExcerpt'],
		'show_thumbnail' => ! isset( $attributes['showThumbnail'] ) || (bool) $attributes['showThumbnail'],
		'show_metrics'   => isset( $attributes['showMetrics'] ) && (bool) $attributes['showMetrics'],
	)
);

if ( '' === $forma_publisher_markup ) {
	return;
}

// The wrapper attributes are generated and escaped by WordPress, and the markup
// is escaped inside the templates rendered above.
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $forma_publisher_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
