<?php
/**
 * Generates the WordPress.org directory assets.
 *
 *   php bin/build-assets.php [output-directory]
 *
 * These files belong in the plugin's SVN `assets/` directory after approval.
 * They are deliberately written outside the plugin folder so they can never be
 * swept into the distributed zip, which the directory rejects them from.
 *
 * Everything here is drawn from primitives, so the artwork is original and
 * GPL-compatible. No WordPress logo is used, which the guidelines forbid on
 * banners.
 *
 * @package Publisher_For_Autodesk_Forma
 */

declare( strict_types = 1 );

if ( ! extension_loaded( 'gd' ) ) {
	fwrite( STDERR, "The gd extension is required.\n" );
	exit( 1 );
}

if ( ! function_exists( 'imagettftext' ) ) {
	fwrite( STDERR, "gd was built without FreeType, so text cannot be drawn.\n" );
	exit( 1 );
}

$output = $argv[1] ?? __DIR__ . '/../assets';

if ( ! is_dir( $output ) && ! mkdir( $output, 0755, true ) && ! is_dir( $output ) ) {
	fwrite( STDERR, "Could not create {$output}\n" );
	exit( 1 );
}

/**
 * Finds a usable font, preferring a bold face.
 *
 * @param bool $bold Whether a bold face is wanted.
 * @return string Absolute path to a TrueType font.
 */
function forma_find_font( bool $bold ): string {
	$candidates = $bold
		? array(
			getenv( 'FORMA_FONT_BOLD' ) ?: '',
			'C:/Windows/Fonts/segoeuib.ttf',
			'C:/Windows/Fonts/arialbd.ttf',
			'/System/Library/Fonts/Supplemental/Arial Bold.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
		)
		: array(
			getenv( 'FORMA_FONT_REGULAR' ) ?: '',
			'C:/Windows/Fonts/segoeui.ttf',
			'C:/Windows/Fonts/arial.ttf',
			'/System/Library/Fonts/Supplemental/Arial.ttf',
			'/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
			'/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
		);

	foreach ( $candidates as $path ) {
		if ( '' !== $path && is_readable( $path ) ) {
			return $path;
		}
	}

	fwrite( STDERR, "No usable TrueType font found. Set FORMA_FONT_BOLD and FORMA_FONT_REGULAR.\n" );
	exit( 1 );
}

/**
 * Draws a rectangle with rounded corners.
 *
 * @param GdImage $im     Target image.
 * @param int     $x1     Left.
 * @param int     $y1     Top.
 * @param int     $x2     Right.
 * @param int     $y2     Bottom.
 * @param int     $radius Corner radius.
 * @param int     $colour Allocated colour.
 * @return void
 */
function forma_rounded_rect( $im, int $x1, int $y1, int $x2, int $y2, int $radius, int $colour ): void {
	imagefilledrectangle( $im, $x1 + $radius, $y1, $x2 - $radius, $y2, $colour );
	imagefilledrectangle( $im, $x1, $y1 + $radius, $x2, $y2 - $radius, $colour );

	$d = $radius * 2;

	imagefilledellipse( $im, $x1 + $radius, $y1 + $radius, $d, $d, $colour );
	imagefilledellipse( $im, $x2 - $radius, $y1 + $radius, $d, $d, $colour );
	imagefilledellipse( $im, $x1 + $radius, $y2 - $radius, $d, $d, $colour );
	imagefilledellipse( $im, $x2 - $radius, $y2 - $radius, $d, $d, $colour );
}

/**
 * Draws the mark: a rising skyline, standing for projects and their metrics.
 *
 * @param GdImage $im     Target image.
 * @param float   $cx     Centre x.
 * @param float   $cy     Centre y.
 * @param float   $scale  Overall size in pixels.
 * @param int     $colour Allocated colour.
 * @return void
 */
function forma_draw_mark( $im, float $cx, float $cy, float $scale, int $colour ): void {
	// Four bars of increasing height, evenly spaced.
	$heights = array( 0.42, 0.62, 0.86, 1.0 );
	$count   = count( $heights );
	$gap     = $scale * 0.07;
	$width   = ( $scale - $gap * ( $count - 1 ) ) / $count;
	$left    = $cx - $scale / 2;
	$bottom  = $cy + $scale / 2;
	$radius  = (int) max( 2, round( $width * 0.18 ) );

	foreach ( $heights as $i => $h ) {
		$x1 = (int) round( $left + $i * ( $width + $gap ) );
		$x2 = (int) round( $x1 + $width );
		$y1 = (int) round( $bottom - $scale * $h );
		$y2 = (int) round( $bottom );

		forma_rounded_rect( $im, $x1, $y1, $x2, $y2, $radius, $colour );
	}
}

/**
 * Writes a PNG and reports it.
 *
 * @param GdImage $im   Image to save.
 * @param string  $path Destination path.
 * @return void
 */
function forma_save( $im, string $path ): void {
	imagesavealpha( $im, true );
	imagepng( $im, $path, 9 );
	imagedestroy( $im );

	printf( "  %-28s %s\n", basename( $path ), number_format( (float) filesize( $path ) ) . ' bytes' );
}

$font_bold    = forma_find_font( true );
$font_regular = forma_find_font( false );

echo "Fonts:\n  bold    {$font_bold}\n  regular {$font_regular}\n\nWriting to {$output}\n";

/*
 * Icons. A solid accent tile with the mark centred, which stays legible at the
 * 128 pixel size the directory renders in search results.
 */
foreach ( array( 128, 256 ) as $size ) {
	$im = imagecreatetruecolor( $size, $size );
	imagealphablending( $im, true );
	imageantialias( $im, true );

	$accent = imagecolorallocate( $im, 11, 116, 196 );
	$deep   = imagecolorallocate( $im, 8, 92, 158 );
	$white  = imagecolorallocate( $im, 255, 255, 255 );

	forma_rounded_rect( $im, 0, 0, $size - 1, $size - 1, (int) round( $size * 0.18 ), $accent );

	// A subtle darker base grounds the mark without adding a second element.
	imagefilledrectangle( $im, 0, (int) round( $size * 0.82 ), $size - 1, $size - 1, $deep );
	forma_rounded_rect( $im, 0, (int) round( $size * 0.62 ), $size - 1, $size - 1, (int) round( $size * 0.18 ), $deep );

	forma_draw_mark( $im, $size / 2, $size * 0.46, $size * 0.52, $white );

	forma_save( $im, "{$output}/icon-{$size}x{$size}.png" );
}

/*
 * Banners. Same composition at both sizes so the retina version is a true
 * scale-up rather than a different design.
 */
foreach ( array( array( 772, 250 ), array( 1544, 500 ) ) as [$w, $h] ) {
	$im = imagecreatetruecolor( $w, $h );
	imagealphablending( $im, true );
	imageantialias( $im, true );

	$bg    = imagecolorallocate( $im, 15, 24, 35 );
	$white = imagecolorallocate( $im, 255, 255, 255 );
	$muted = imagecolorallocate( $im, 154, 173, 194 );
	$mark  = imagecolorallocate( $im, 30, 128, 205 );
	$glow  = imagecolorallocate( $im, 21, 44, 68 );

	imagefilledrectangle( $im, 0, 0, $w, $h, $bg );

	// A soft band behind the mark, keeping the right side from looking empty.
	imagefilledellipse( $im, (int) ( $w * 0.84 ), (int) ( $h * 0.5 ), (int) ( $w * 0.42 ), (int) ( $h * 1.5 ), $glow );

	forma_draw_mark( $im, $w * 0.845, $h * 0.52, $h * 0.52, $mark );

	$title_size   = $h * 0.132;
	$tagline_size = $h * 0.062;
	$left         = (int) round( $w * 0.062 );

	imagettftext( $im, $title_size, 0, $left, (int) round( $h * 0.46 ), $white, $font_bold, 'Publisher for' );
	imagettftext( $im, $title_size, 0, $left, (int) round( $h * 0.66 ), $white, $font_bold, 'Autodesk Forma' );
	imagettftext(
		$im,
		$tagline_size,
		0,
		$left,
		(int) round( $h * 0.84 ),
		$muted,
		$font_regular,
		'Publish Forma projects to WordPress, safely.'
	);

	forma_save( $im, "{$output}/banner-{$w}x{$h}.png" );
}

echo "\nDone. These belong in SVN assets/, never in the plugin zip.\n";
