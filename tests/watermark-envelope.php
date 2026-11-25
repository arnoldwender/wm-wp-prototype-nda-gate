<?php
/**
 * Measures where the forensic screen watermark can be read back and where it cannot.
 *
 * This is the evidence behind the table the README and the handbook state. It renders synthetic
 * screenshots with `WM_NDA_Watermark::render_onto()`, runs the extractor over them and prints one
 * line per case. The suite (`tests/test-suite.php`, group 13) asserts the cases that must hold; this
 * file also carries the ones that must NOT, so a change that quietly widens or narrows the envelope
 * shows up as a different table rather than as nothing at all.
 *
 *   php tests/watermark-envelope.php            # prints the table
 *   php tests/watermark-envelope.php --check     # exit 1 when a case disagrees with what is documented
 *
 * A case marked `expected: found` must name the account; `expected: not found` must name none —
 * reporting an account for an image that carries no watermark, or a different account for one that
 * does, is the failure this file exists to catch.
 *
 * Runtime: about twenty seconds. One screenshot takes roughly a fifth of a second to read.
 *
 * @package WM_Prototype_NDA_Gate
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'watermark-envelope-key-not-a-secret' );
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'watermark-envelope-salt';
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! class_exists( 'WM_NDA_Database' ) ) {
	class WM_NDA_Database {
		public static function is_usable_key( string $key ): bool {
			return '' !== $key && 'put your unique phrase here' !== $key;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-nda-watermark.php';

if ( ! extension_loaded( 'gd' ) ) {
	fwrite( STDERR, "GD is not loaded; the watermark cannot be rendered or read without it.\n" );
	exit( 1 );
}

const ENVELOPE_USER = 8125;

/** A page of text: the background the reader has an easy time with. */
function envelope_page( int $width, int $height, int $paper = 250, int $ink = 30 ) {
	$image = imagecreatetruecolor( $width, $height );
	imagefilledrectangle( $image, 0, 0, $width, $height, (int) imagecolorallocate( $image, $paper, $paper, $paper ) );
	$colour = (int) imagecolorallocate( $image, $ink, $ink, min( 255, $ink + 5 ) );
	for ( $y = 40; $y < $height - 40; $y += 26 ) {
		$line = (int) round( $width * 0.62 ) - ( intdiv( $y, 26 ) % 5 ) * 40;
		imagefilledrectangle( $image, 60, $y, 60 + max( 40, $line ), $y + 9, $colour );
	}
	return $image;
}

/** A page whose top half is a hero image: the shape that defeated the unweighted reader. */
function envelope_hero( int $width, int $height ) {
	$image = envelope_page( $width, $height );
	for ( $y = 0; $y < (int) ( $height * 0.42 ); $y++ ) {
		for ( $x = 0; $x < $width; $x++ ) {
			$r = (int) ( 127 + 120 * sin( $x / 47.0 ) );
			$g = (int) ( 127 + 118 * sin( $y / 29.0 ) );
			$b = (int) ( 127 + 122 * sin( ( $x + $y ) / 61.0 ) );
			imagesetpixel( $image, $x, $y, (int) imagecolorallocate( $image, max( 0, min( 255, $r ) ), max( 0, min( 255, $g ) ), max( 0, min( 255, $b ) ) ) );
		}
	}
	return $image;
}

/** Nothing but interference patterns: the background that still defeats it. */
function envelope_photo( int $width, int $height ) {
	$image = imagecreatetruecolor( $width, $height );
	for ( $y = 0; $y < $height; $y++ ) {
		for ( $x = 0; $x < $width; $x++ ) {
			$r = (int) ( 127 + 120 * sin( $x / 57.0 ) );
			$g = (int) ( 127 + 120 * sin( $y / 41.0 ) );
			$b = (int) ( 127 + 120 * sin( ( $x + $y ) / 73.0 ) );
			imagesetpixel( $image, $x, $y, (int) imagecolorallocate( $image, max( 0, min( 255, $r ) ), max( 0, min( 255, $g ) ), max( 0, min( 255, $b ) ) ) );
		}
	}
	return $image;
}

/** Pixel noise far stronger than the pattern. */
function envelope_noise( int $width, int $height ) {
	$image = imagecreatetruecolor( $width, $height );
	mt_srand( 7 );
	for ( $y = 0; $y < $height; $y++ ) {
		for ( $x = 0; $x < $width; $x++ ) {
			$value = mt_rand( 90, 200 );
			imagesetpixel( $image, $x, $y, (int) imagecolorallocate( $image, $value, $value, $value ) );
		}
	}
	return $image;
}

function envelope_write( $image, string $format = 'png', int $quality = 90 ): string {
	$file = tempnam( sys_get_temp_dir(), 'wm-envelope' ) . '.' . $format;
	if ( 'png' === $format ) {
		imagepng( $image, $file );
	} else {
		imagejpeg( $image, $file, $quality );
	}
	return $file;
}

$minutes = WM_NDA_Watermark::minutes_since_epoch( (int) strtotime( '2026-09-16 10:30:00 UTC' ) );

/**
 * Every case: label, how to build the image, whether it carries a watermark, the device scale it was
 * painted at, the file format, and what the reader is expected to answer.
 */
$cases = [
	[ 'text page 1440x900', fn() => envelope_page( 1440, 900 ), true, 1, 'png', 90, true ],
	[ 'dark page 1440x900', fn() => envelope_page( 1440, 900, 24, 226 ), true, 1, 'png', 90, true ],
	[ 'hero image, top half', fn() => envelope_hero( 1440, 900 ), true, 1, 'png', 90, true ],
	[ '2x display 2880x1800', fn() => envelope_page( 2880, 1800 ), true, 2, 'png', 90, true ],
	[ 'JPEG quality 75', fn() => envelope_page( 1440, 900 ), true, 1, 'jpeg', 75, true ],
	[ 'JPEG quality 40', fn() => envelope_page( 1440, 900 ), true, 1, 'jpeg', 40, true ],
	[ 'heavy pixel noise', fn() => envelope_noise( 1440, 900 ), true, 1, 'png', 90, true ],
	[ 'crop 1120x768', fn() => envelope_page( 1120, 768 ), true, 1, 'png', 90, true ],
	[ 'crop 960x640', fn() => envelope_page( 960, 640 ), true, 1, 'png', 90, true ],
	[ 'crop 800x640', fn() => envelope_page( 800, 640 ), true, 1, 'png', 90, true ],
	// Where it gives up. Each of these must answer "not found", never another account.
	[ 'crop 300x240 (one block)', fn() => envelope_page( 300, 240 ), true, 1, 'png', 90, false ],
	[ 'interference background', fn() => envelope_photo( 1440, 900 ), true, 1, 'png', 90, false ],
	[ 'no watermark: text page', fn() => envelope_page( 1440, 900 ), false, 1, 'png', 90, false ],
	[ 'no watermark: photo', fn() => envelope_photo( 1440, 900 ), false, 1, 'png', 90, false ],
	[ 'no watermark: noise', fn() => envelope_noise( 1440, 900 ), false, 1, 'png', 90, false ],
	[ 'no watermark: flat', fn() => imagecreatetruecolor( 1440, 900 ), false, 1, 'png', 90, false ],
];

$check      = in_array( '--check', $argv, true );
$disagreed  = 0;

printf( "%-28s %-10s %-10s %-8s %s\n", 'case', 'expected', 'answer', 'blocks', 'account' );
echo str_repeat( '-', 72 ), "\n";

foreach ( $cases as [ $label, $builder, $marked, $scale, $format, $quality, $expected ] ) {
	$image = $builder();
	if ( $marked ) {
		WM_NDA_Watermark::render_onto( $image, ENVELOPE_USER, $minutes, $scale );
	}

	$file   = envelope_write( $image, $format, $quality );
	$result = WM_NDA_Watermark::extract_from_file( $file );
	unlink( $file );

	// "Found" only counts when it names the right account; a different one is worse than none.
	$found  = $result['found'] && ENVELOPE_USER === $result['user_id'];
	$agrees = $found === $expected && ( ! $result['found'] || ENVELOPE_USER === $result['user_id'] );

	if ( ! $agrees ) {
		++$disagreed;
	}

	printf(
		"%-28s %-10s %-10s %-8s %s%s\n",
		$label,
		$expected ? 'found' : 'not found',
		$result['found'] ? 'found' : 'not found',
		(string) ( $result['blocks'] ?? '-' ),
		(string) ( $result['user_id'] ?? '-' ),
		$agrees ? '' : '   <-- DISAGREES WITH THE DOCUMENTED ENVELOPE'
	);
}

echo str_repeat( '-', 72 ), "\n";
printf( "%d of %d cases as documented\n", count( $cases ) - $disagreed, count( $cases ) );

if ( $check && $disagreed > 0 ) {
	exit( 1 );
}

exit( 0 );
