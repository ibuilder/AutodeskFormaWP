<?php
/**
 * Server side render callback for the Forma project block.
 *
 * @package Forma_Publisher
 *
 * @var array<string,mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$forma_publisher_markup = Forma_Publisher\Renderer::project(
	array(
		'id'             => isset( $attributes['projectId'] ) ? (string) $attributes['projectId'] : '',
		'show_metrics'   => ! isset( $attributes['showMetrics'] ) || (bool) $attributes['showMetrics'],
		'show_assets'    => ! isset( $attributes['showAssets'] ) || (bool) $attributes['showAssets'],
		'show_thumbnail' => ! isset( $attributes['showThumbnail'] ) || (bool) $attributes['showThumbnail'],
		'show_content'   => ! isset( $attributes['showContent'] ) || (bool) $attributes['showContent'],
	)
);

if ( '' === $forma_publisher_markup ) {
	return;
}
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $forma_publisher_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
