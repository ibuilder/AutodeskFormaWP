<?php
/**
 * Server side render callback for the Forma assets block.
 *
 * @package Forma_Publisher
 *
 * @var array<string,mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$forma_publisher_markup = Forma_Publisher\Renderer::assets(
	array(
		'project' => isset( $attributes['projectId'] ) ? (string) $attributes['projectId'] : '',
		'kind'    => isset( $attributes['kind'] ) ? (string) $attributes['kind'] : '',
		'limit'   => isset( $attributes['limit'] ) ? (int) $attributes['limit'] : 50,
	)
);

if ( '' === $forma_publisher_markup ) {
	return;
}
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $forma_publisher_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
