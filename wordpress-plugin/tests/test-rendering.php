<?php
/**
 * Shortcodes, blocks, templates and output escaping.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

use Forma_Publisher\Renderer;
use Forma_Publisher\Templates;

$connection = make_connection( 'Rendering tests' );
$route      = '/forma-publisher/v1/ingest';
$source     = unique_source( 'render' );

$project_payload = payload(
	$source,
	array(
		'project' => array(
			'title'   => 'Render Test Project',
			'summary' => 'Summary for the render test.',
			'metrics' => array(
				array(
					'key'       => 'gfa',
					'label'     => 'Gross floor area',
					'value'     => 48250.5,
					'unit'      => 'm2',
					'category'  => 'Area',
					'precision' => 1,
				),
				array(
					'key'       => 'sun_hours',
					'label'     => 'Average sun hours',
					'value'     => 4.8,
					'unit'      => 'h',
					'category'  => 'Environment',
					'precision' => 1,
				),
			),
			'assets'  => array(
				array(
					'source_id' => $source . ':doc',
					'title'     => 'Site plan',
					'kind'      => 'document',
					'url'       => 'https://example.com/site-plan.pdf',
					'size'      => 204800,
				),
				array(
					'source_id' => $source . ':model',
					'title'     => 'Massing model',
					'kind'      => 'model',
					'url'       => 'https://example.com/model.glb',
				),
			),
		),
	)
);

$response = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $project_payload ), $connection['key_id'], $connection['secret'] ) );
$post_id  = (int) $response->get_data()['result']['post_id'];

group( 'Rendering: shortcodes' );

$list = do_shortcode( '[forma_project_list limit="5"]' );
ok( 'the project list renders', false !== strpos( $list, 'forma-publisher-list' ) );
ok( 'the project list includes the project', false !== strpos( $list, 'Render Test Project' ) );

$single = do_shortcode( '[forma_project id="' . $post_id . '"]' );
ok( 'a single project renders', false !== strpos( $single, 'forma-publisher-project' ) );
ok( 'metrics appear inside the project', false !== strpos( $single, 'Gross floor area' ) );
ok( 'assets appear inside the project', false !== strpos( $single, 'Site plan' ) );

$by_source = do_shortcode( '[forma_project id="' . $source . '"]' );
ok( 'a project renders when referenced by source id', false !== strpos( $by_source, 'Render Test Project' ) );

$metrics_cards = do_shortcode( '[forma_metrics project="' . $post_id . '" layout="cards"]' );
ok( 'the cards layout renders', false !== strpos( $metrics_cards, 'forma-publisher-metrics--cards' ) );
ok( 'numeric metrics are localized', false !== strpos( $metrics_cards, '48,250.5' ), substr( $metrics_cards, 0, 300 ) );

$metrics_table = do_shortcode( '[forma_metrics project="' . $post_id . '"]' );
ok( 'the table layout is the default', false !== strpos( $metrics_table, 'forma-publisher-metrics--table' ) );

$filtered = do_shortcode( '[forma_metrics project="' . $post_id . '" category="environment"]' );
ok( 'the category filter includes matching metrics', false !== strpos( $filtered, 'Average sun hours' ) );
ok( 'the category filter excludes other metrics', false === strpos( $filtered, 'Gross floor area' ) );

$by_key = do_shortcode( '[forma_metrics project="' . $post_id . '" keys="gfa"]' );
ok( 'the key filter selects a single metric', false !== strpos( $by_key, 'Gross floor area' ) && false === strpos( $by_key, 'Average sun hours' ) );

$assets = do_shortcode( '[forma_assets project="' . $post_id . '"]' );
ok( 'the asset list renders', false !== strpos( $assets, 'forma-publisher-assets' ) );
ok( 'the asset size is formatted', false !== strpos( $assets, 'KB' ) || false !== strpos( $assets, 'kB' ), substr( $assets, 0, 400 ) );

$documents = do_shortcode( '[forma_assets project="' . $post_id . '" kind="document"]' );
ok( 'the kind filter includes matching assets', false !== strpos( $documents, 'Site plan' ) );
ok( 'the kind filter excludes other assets', false === strpos( $documents, 'Massing model' ) );

group( 'Rendering: missing content is quiet for visitors' );

wp_set_current_user( 0 );
same( 'a missing project renders nothing for a visitor', '', trim( do_shortcode( '[forma_project id="99999999"]' ) ) );
same( 'a missing metrics target renders nothing for a visitor', '', trim( do_shortcode( '[forma_metrics project="99999999"]' ) ) );

$editors = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );

if ( ! empty( $editors ) ) {
	wp_set_current_user( (int) $editors[0] );
	$notice = do_shortcode( '[forma_project id="99999999"]' );
	ok( 'an editor sees a diagnostic notice', false !== strpos( $notice, 'forma-publisher-notice' ), $notice );
	wp_set_current_user( 0 );
}

group( 'Rendering: escaping' );

$hostile_source  = unique_source( 'xss' );
$hostile_payload = payload(
	$hostile_source,
	array(
		'project' => array(
			'title'   => 'XSS "><img src=x onerror=alert(1)> Probe',
			'summary' => '<script>alert("summary")</script>',
			'content' => '<p>ok</p><script>alert("content")</script><img src=x onerror=alert(2)>',
			'metrics' => array(
				array(
					'key'   => 'probe',
					'label' => '<script>alert("label")</script>',
					'value' => '<img src=x onerror=alert(3)>',
				),
			),
			'assets'  => array(
				array(
					'source_id' => $hostile_source . ':a',
					'title'     => '<script>alert("asset")</script>',
					'kind'      => 'document',
					'url'       => 'https://example.com/ok.pdf',
				),
			),
		),
	)
);

$hostile_response = rest_do_request( signed_request( 'POST', $route, wp_json_encode( $hostile_payload ), $connection['key_id'], $connection['secret'] ) );
same( 'a hostile payload is accepted and neutralized rather than rejected', 200, $hostile_response->get_status() );

$hostile_id = (int) $hostile_response->get_data()['result']['post_id'];
$rendered   = do_shortcode( '[forma_project id="' . $hostile_id . '"]' );

ok( 'no script tag survives rendering', false === stripos( $rendered, '<script' ), substr( $rendered, 0, 400 ) );
ok( 'no inline error handler survives rendering', false === stripos( $rendered, 'onerror' ), substr( $rendered, 0, 400 ) );

$hostile_list = do_shortcode( '[forma_project_list limit="20"]' );
ok( 'no script tag survives the list view', false === stripos( $hostile_list, '<script' ) );
ok( 'no inline error handler survives the list view', false === stripos( $hostile_list, 'onerror' ) );

$hostile_metrics = do_shortcode( '[forma_metrics project="' . $hostile_id . '"]' );
ok( 'no script tag survives the metrics view', false === stripos( $hostile_metrics, '<script' ) );

$hostile_assets = do_shortcode( '[forma_assets project="' . $hostile_id . '"]' );
ok( 'no script tag survives the assets view', false === stripos( $hostile_assets, '<script' ) );

group( 'Rendering: blocks' );

$registry = \WP_Block_Type_Registry::get_instance();

foreach ( array( 'project-list', 'project', 'metrics', 'assets' ) as $block ) {
	ok( 'block forma-publisher/' . $block . ' is registered', $registry->is_registered( 'forma-publisher/' . $block ) );
}

$block_markup = do_blocks( '<!-- wp:forma-publisher/project-list {"limit":3} /-->' );
ok( 'the project list block renders', false !== strpos( $block_markup, 'forma-publisher-list' ), substr( $block_markup, 0, 300 ) );

$block_markup = do_blocks( '<!-- wp:forma-publisher/project {"projectId":"' . $post_id . '"} /-->' );
ok( 'the project block renders', false !== strpos( $block_markup, 'forma-publisher-project' ), substr( $block_markup, 0, 300 ) );

$block_markup = do_blocks( '<!-- wp:forma-publisher/metrics {"projectId":"' . $post_id . '","layout":"cards"} /-->' );
ok( 'the metrics block honours its layout attribute', false !== strpos( $block_markup, 'forma-publisher-metrics--cards' ), substr( $block_markup, 0, 300 ) );

$block_markup = do_blocks( '<!-- wp:forma-publisher/assets {"projectId":"' . $post_id . '","kind":"model"} /-->' );
ok( 'the assets block honours its kind attribute', false !== strpos( $block_markup, 'Massing model' ) && false === strpos( $block_markup, 'Site plan' ), substr( $block_markup, 0, 400 ) );

$empty_block = do_blocks( '<!-- wp:forma-publisher/metrics {"projectId":""} /-->' );
same( 'a block with no target renders nothing for a visitor', '', trim( wp_strip_all_tags( $empty_block ) ) );

group( 'Rendering: templates' );

ok( 'a bundled template resolves', '' !== Templates::locate( 'project-list.php' ) );
same( 'a path traversal attempt is refused', '', Templates::locate( '../../../wp-config.php' ) );
same( 'an absolute path is refused', '', Templates::locate( '/etc/passwd' ) );
same( 'an unknown template returns empty', '', Templates::locate( 'does-not-exist.php' ) );
same( 'a non php template is refused', '', Templates::locate( 'project-list.html' ) );
same( 'rendering an unknown template returns empty', '', Templates::render( 'does-not-exist.php' ) );

// A theme override must take precedence over the bundled template.
$override_dir = trailingslashit( get_stylesheet_directory() ) . 'publisher-for-autodesk-forma';

if ( wp_mkdir_p( $override_dir ) ) {
	$override_file = $override_dir . '/metrics.php';
	file_put_contents( $override_file, "<?php\n// phpcs:ignore\necho '<div class=\"theme-override-marker\"></div>';\n" );

	clearstatcache();

	$overridden = Templates::render( 'metrics.php', array( 'metrics' => array(), 'layout' => 'table' ) );
	ok( 'a theme template overrides the bundled one', false !== strpos( $overridden, 'theme-override-marker' ), $overridden );

	wp_delete_file( $override_file );
	@rmdir( $override_dir );

	$restored = Templates::render( 'metrics.php', array( 'metrics' => array(), 'layout' => 'table' ) );
	ok( 'removing the override restores the bundled template', false === strpos( $restored, 'theme-override-marker' ) );
} else {
	ok( 'the theme override directory could not be created', false, $override_dir );
}

group( 'Rendering: metric formatting' );

same( 'a null metric renders as a dash', '—', Renderer::format_metric( array( 'key' => 'x', 'value' => null ) ) );
same( 'an empty metric renders as a dash', '—', Renderer::format_metric( array( 'key' => 'x', 'value' => '' ) ) );
same( 'a unit is appended', '5 m2', Renderer::format_metric( array( 'key' => 'x', 'value' => 5, 'unit' => 'm2', 'precision' => 0 ) ) );
same( 'no unit is appended to a dash', '—', Renderer::format_metric( array( 'key' => 'x', 'value' => null, 'unit' => 'm2' ) ) );
same( 'precision is respected', '1.50', Renderer::format_metric( array( 'key' => 'x', 'value' => 1.5, 'precision' => 2 ) ) );
same( 'a string metric passes through', 'High', Renderer::format_metric( array( 'key' => 'x', 'value' => 'High' ) ) );
same( 'zero is not treated as empty', '0', Renderer::format_metric( array( 'key' => 'x', 'value' => 0, 'precision' => 0 ) ) );

same( 'a known asset kind is labelled', 'Model', Renderer::asset_kind_label( 'model' ) );
same( 'an unknown asset kind falls back', 'File', Renderer::asset_kind_label( 'something-else' ) );
