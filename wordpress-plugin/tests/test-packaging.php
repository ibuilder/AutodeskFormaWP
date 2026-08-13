<?php
/**
 * Release packaging consistency.
 *
 * Version drift between the plugin header, readme.txt and the release tag is a
 * common cause of a rejected or broken release, and it is invisible until
 * someone tries to install the result. These assertions make it impossible to
 * ship a mismatched package unnoticed.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Tests;

group( 'Packaging: version consistency' );

$plugin_file = FORMA_PUBLISHER_DIR . 'forma-publisher.php';
$readme_file = FORMA_PUBLISHER_DIR . 'readme.txt';

ok( 'the main plugin file is readable', is_readable( $plugin_file ) );
ok( 'readme.txt is readable', is_readable( $readme_file ) );

if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

$headers = get_plugin_data( $plugin_file, false, false );
$readme  = (string) file_get_contents( $readme_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled file in a test.

same( 'the header version matches the runtime constant', FORMA_PUBLISHER_VERSION, $headers['Version'] );

preg_match( '/^Stable tag:\s*(.+)$/mi', $readme, $stable );
same(
	'the readme stable tag matches the plugin version',
	FORMA_PUBLISHER_VERSION,
	isset( $stable[1] ) ? trim( $stable[1] ) : ''
);

preg_match( '/^Requires at least:\s*(.+)$/mi', $readme, $requires_wp );
same(
	'the readme minimum WordPress version matches the header',
	$headers['RequiresWP'],
	isset( $requires_wp[1] ) ? trim( $requires_wp[1] ) : ''
);

preg_match( '/^Requires PHP:\s*(.+)$/mi', $readme, $requires_php );
same(
	'the readme minimum PHP version matches the header',
	$headers['RequiresPHP'],
	isset( $requires_php[1] ) ? trim( $requires_php[1] ) : ''
);

group( 'Packaging: required metadata' );

foreach ( array( 'Name', 'Description', 'Version', 'RequiresWP', 'RequiresPHP', 'TextDomain' ) as $key ) {
	ok( 'the header declares ' . $key, ! empty( $headers[ $key ] ), 'value: ' . wp_json_encode( isset( $headers[ $key ] ) ? $headers[ $key ] : null ) );
}

same( 'the text domain matches the plugin slug', 'forma-publisher', $headers['TextDomain'] );
same( 'the domain path is declared', '/languages', $headers['DomainPath'] );

/*
 * get_plugin_data() does not parse License or License URI, so read them with an
 * explicit header map rather than assuming they are absent.
 */
$licence = get_file_data(
	$plugin_file,
	array(
		'License'    => 'License',
		'LicenseURI' => 'License URI',
	)
);

ok( 'a GPL compatible licence is declared in the header', false !== stripos( $licence['License'], 'GPL' ), $licence['License'] );
ok( 'a licence URI is declared in the header', '' !== trim( $licence['LicenseURI'] ), $licence['LicenseURI'] );

preg_match( '/^License:\s*(.+)$/mi', $readme, $readme_licence );
ok(
	'the readme declares a GPL compatible licence',
	isset( $readme_licence[1] ) && false !== stripos( $readme_licence[1], 'GPL' ),
	isset( $readme_licence[1] ) ? trim( $readme_licence[1] ) : '(missing)'
);

preg_match( '/^Tags:\s*(.+)$/mi', $readme, $tags );
$tag_list = isset( $tags[1] ) ? array_filter( array_map( 'trim', explode( ',', $tags[1] ) ) ) : array();
ok( 'the readme declares at most five tags', count( $tag_list ) <= 5, 'count: ' . count( $tag_list ) );

// The short description is the line after the header block and is capped by
// the directory at 150 characters.
if ( preg_match( '/^Stable tag:.*$\R+.*$\R+(.+)$/mi', $readme, $short ) ) {
	$short_description = trim( $short[1] );
	ok(
		'the short description is within 150 characters',
		strlen( $short_description ) <= 150,
		'length: ' . strlen( $short_description )
	);
}

foreach ( array( '== Description ==', '== Installation ==', '== Changelog ==' ) as $section ) {
	ok( 'the readme contains the ' . trim( $section, '= ' ) . ' section', false !== strpos( $readme, $section ) );
}

ok(
	'the changelog documents the current version',
	false !== strpos( $readme, '= ' . FORMA_PUBLISHER_VERSION . ' =' ),
	'looking for "= ' . FORMA_PUBLISHER_VERSION . ' ="'
);

group( 'Packaging: no development files are shipped' );

/*
 * Everything below lives outside the plugin directory by design. If any of it
 * appears inside, it would be published to users.
 */
$forbidden = array(
	'tests',
	'node_modules',
	'vendor',
	'.git',
	'composer.json',
	'composer.lock',
	'phpcs.xml.dist',
	'package.json',
	'.env',
);

foreach ( $forbidden as $entry ) {
	ok(
		'the plugin directory does not contain ' . $entry,
		! file_exists( FORMA_PUBLISHER_DIR . $entry )
	);
}

$expected = array(
	'forma-publisher.php',
	'uninstall.php',
	'readme.txt',
	'includes',
	'templates',
	'blocks',
	'assets',
);

foreach ( $expected as $entry ) {
	ok( 'the plugin directory contains ' . $entry, file_exists( FORMA_PUBLISHER_DIR . $entry ) );
}

group( 'Packaging: translations' );

/*
 * The Domain Path header has to point at a directory that actually ships. An
 * empty directory is not tracked by git, so it would exist locally and be
 * missing from a fresh checkout and from the release archive.
 */
$domain_path = ltrim( $headers['DomainPath'], '/' );

ok(
	'the Domain Path header points at an existing directory',
	'' !== $domain_path && is_dir( FORMA_PUBLISHER_DIR . $domain_path ),
	FORMA_PUBLISHER_DIR . $domain_path
);

$pot = FORMA_PUBLISHER_DIR . $domain_path . '/forma-publisher.pot';

ok( 'a translation template ships', is_readable( $pot ), $pot );

if ( is_readable( $pot ) ) {
	$pot_contents = (string) file_get_contents( $pot ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled file in a test.

	ok(
		'the translation template declares the plugin text domain',
		false !== strpos( $pot_contents, 'forma-publisher' )
	);

	ok(
		'the translation template contains translatable strings',
		substr_count( $pot_contents, "\nmsgid " ) > 50,
		'msgid count: ' . substr_count( $pot_contents, "\nmsgid " )
	);
}

group( 'Packaging: block metadata' );

foreach ( array( 'project-list', 'project', 'metrics', 'assets' ) as $block ) {
	$path = FORMA_PUBLISHER_DIR . 'blocks/' . $block . '/block.json';

	if ( ! ok( 'block.json exists for ' . $block, is_readable( $path ) ) ) {
		continue;
	}

	$meta = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled file in a test.

	ok( 'block.json for ' . $block . ' is valid JSON', is_array( $meta ) );

	if ( ! is_array( $meta ) ) {
		continue;
	}

	same( 'the block name is namespaced for ' . $block, 'forma-publisher/' . $block, isset( $meta['name'] ) ? $meta['name'] : '' );
	same( 'the block declares the plugin text domain for ' . $block, 'forma-publisher', isset( $meta['textdomain'] ) ? $meta['textdomain'] : '' );
	same( 'the block version matches the plugin for ' . $block, FORMA_PUBLISHER_VERSION, isset( $meta['version'] ) ? $meta['version'] : '' );
	ok( 'the block declares a render file for ' . $block, isset( $meta['render'] ) );

	$render = FORMA_PUBLISHER_DIR . 'blocks/' . $block . '/' . str_replace( 'file:./', '', (string) $meta['render'] );
	ok( 'the declared render file exists for ' . $block, is_readable( $render ), $render );

	$script = FORMA_PUBLISHER_DIR . 'blocks/' . $block . '/index.js';
	ok( 'the editor script exists for ' . $block, is_readable( $script ) );
	ok( 'the editor asset manifest exists for ' . $block, is_readable( FORMA_PUBLISHER_DIR . 'blocks/' . $block . '/index.asset.php' ) );
}
