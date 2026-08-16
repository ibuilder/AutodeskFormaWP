<?php
/**
 * Server side render callback for the Forma metrics block.
 *
 * @package Forma_Publisher
 *
 * @var array<string,mixed> $attributes Block attributes.
 */

defined( 'ABSPATH' ) || exit;

$forma_publisher_markup = Forma_Publisher\Renderer::metrics(
	array(
		'project'  => isset( $attributes['projectId'] ) ? (string) $attributes['projectId'] : '',
		'category' => isset( $attributes['category'] ) ? (string) $attributes['category'] : '',
		'keys'     => isset( $attributes['keys'] ) ? (string) $attributes['keys'] : '',
		'layout'   => isset( $attributes['layout'] ) ? (string) $attributes['layout'] : 'table',
	)
);

if ( '' === $forma_publisher_markup ) {
	return;
}
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo $forma_publisher_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
