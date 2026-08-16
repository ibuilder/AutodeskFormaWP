<?php
/**
 * Project asset list template.
 *
 * Override by copying this file to `your-theme/forma-publisher/assets.php`.
 *
 * @package Forma_Publisher
 *
 * @var array<string,mixed> $forma_publisher_data {
 *     @type \WP_Post   $project Project post.
 *     @type \WP_Post[] $assets  Asset posts.
 * }
 */

defined( 'ABSPATH' ) || exit;

$forma_publisher_assets = isset( $forma_publisher_data['assets'] ) ? (array) $forma_publisher_data['assets'] : array();

if ( empty( $forma_publisher_assets ) ) {
	?>
	<p class="forma-publisher-empty"><?php esc_html_e( 'No assets have been published for this project.', 'publisher-for-autodesk-forma' ); ?></p>
	<?php
	return;
}
?>
<div class="forma-publisher forma-publisher-assets">
	<ul class="forma-publisher-assets__list">
		<?php foreach ( $forma_publisher_assets as $forma_publisher_asset ) : ?>
			<?php
			if ( ! $forma_publisher_asset instanceof WP_Post ) {
				continue;
			}

			$forma_publisher_asset_url  = (string) get_post_meta( $forma_publisher_asset->ID, '_forma_asset_url', true );
			$forma_publisher_asset_kind = (string) get_post_meta( $forma_publisher_asset->ID, '_forma_asset_kind', true );
			$forma_publisher_asset_size = (int) get_post_meta( $forma_publisher_asset->ID, '_forma_asset_size', true );
			?>
			<li class="forma-publisher-asset forma-publisher-asset--<?php echo esc_attr( sanitize_html_class( $forma_publisher_asset_kind ) ); ?>">
				<span class="forma-publisher-asset__kind"><?php echo esc_html( Forma_Publisher\Renderer::asset_kind_label( $forma_publisher_asset_kind ) ); ?></span>

				<?php if ( '' !== $forma_publisher_asset_url ) : ?>
					<a class="forma-publisher-asset__link" href="<?php echo esc_url( $forma_publisher_asset_url ); ?>" rel="nofollow noopener external" target="_blank">
						<?php echo esc_html( get_the_title( $forma_publisher_asset ) ); ?>
					</a>
				<?php else : ?>
					<span class="forma-publisher-asset__title"><?php echo esc_html( get_the_title( $forma_publisher_asset ) ); ?></span>
				<?php endif; ?>

				<?php if ( $forma_publisher_asset_size > 0 ) : ?>
					<span class="forma-publisher-asset__size"><?php echo esc_html( size_format( $forma_publisher_asset_size ) ); ?></span>
				<?php endif; ?>

				<?php $forma_publisher_asset_excerpt = get_the_excerpt( $forma_publisher_asset ); ?>
				<?php if ( '' !== $forma_publisher_asset_excerpt ) : ?>
					<span class="forma-publisher-asset__summary"><?php echo esc_html( $forma_publisher_asset_excerpt ); ?></span>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
