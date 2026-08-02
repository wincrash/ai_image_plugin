<?php
/**
 * Host capability check for the AI Cake Topper plugin.
 *
 * Standalone — needs no WordPress. Upload it anywhere web-accessible, open it
 * with the token in the URL, read the result, then DELETE IT.
 *
 *     https://valgomosdekoracijos.lt/host-check.php?token=CHANGE-THIS-TOKEN
 *
 * It only reads capabilities and writes one temp file to test writability.
 * It changes nothing else.
 *
 * The token exists so a passer-by cannot read your server configuration.
 * Change it below before uploading.
 */

const CHECK_TOKEN = 'CHANGE-THIS-TOKEN';

if ( ! isset( $_GET['token'] ) || ! hash_equals( CHECK_TOKEN, (string) $_GET['token'] ) ) {
	http_response_code( 404 );
	exit( 'Not found' );
}

header( 'Content-Type: text/plain; charset=utf-8' );

// A fatal (most likely out-of-memory) should still tell us something useful.
register_shutdown_function(
	static function () {
		$e = error_get_last();
		if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR ), true ) ) {
			echo "\n\n!!! FATAL: {$e['message']}\n";
			echo "    in {$e['file']}:{$e['line']}\n";
			echo "    (if this is an allocation failure, memory_limit is the problem)\n";
		}
	}
);

function section( string $t ): void {
	echo "\n== $t " . str_repeat( '=', max( 0, 58 - strlen( $t ) ) ) . "\n";
}
function row( string $k, $v, ?bool $ok = null ): void {
	$mark = $ok === null ? '  ' : ( $ok ? 'OK' : '!!' );
	printf( "%s %-34s %s\n", $mark, $k, is_bool( $v ) ? ( $v ? 'yes' : 'NO' ) : (string) $v );
}

echo "AI Cake Topper — host capability check\n";
echo date( 'c' ) . "\n";

// ---------------------------------------------------------------- PHP ----
section( 'PHP' );
row( 'PHP version', PHP_VERSION, version_compare( PHP_VERSION, '8.1', '>=' ) );
row( 'memory_limit', ini_get( 'memory_limit' ) );
row( 'max_execution_time', ini_get( 'max_execution_time' ) );
row( 'cURL extension', extension_loaded( 'curl' ), extension_loaded( 'curl' ) );
row( 'allow_url_fopen', (bool) ini_get( 'allow_url_fopen' ) );
row( 'JSON extension', extension_loaded( 'json' ), extension_loaded( 'json' ) );

$limit_bytes = (int) preg_replace_callback(
	'/^(\d+)([KMG])?$/i',
	static fn( $m ) => (int) $m[1] * array( '' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824 )[ strtoupper( $m[2] ?? '' ) ],
	trim( (string) ini_get( 'memory_limit' ) )
);
row( 'memory_limit >= 256M', $limit_bytes >= 268435456 || $limit_bytes <= 0, $limit_bytes >= 268435456 || $limit_bytes <= 0 );

// ------------------------------------------------------------- imaging ----
section( 'Imaging' );
row( 'Imagick extension', extension_loaded( 'imagick' ) );   // expected: no
row( 'GD extension', extension_loaded( 'gd' ), extension_loaded( 'gd' ) );

if ( extension_loaded( 'gd' ) ) {
	$gd = gd_info();
	row( 'GD version', $gd['GD Version'] ?? '?' );

	// THE CRITICAL ONE. Every name and greeting on every topper is rendered
	// with imagettftext(), which needs FreeType. Without it there is no text
	// layer at all, and the product loses most of its value.
	$freetype = ! empty( $gd['FreeType Support'] ) && function_exists( 'imagettftext' );
	row( 'FreeType (TTF text)  <-- CRITICAL', $freetype, $freetype );

	row( 'PNG support', ! empty( $gd['PNG Support'] ), ! empty( $gd['PNG Support'] ) );
	row( 'WebP support', ! empty( $gd['WebP Support'] ), ! empty( $gd['WebP Support'] ) );
	row( 'JPEG support', ! empty( $gd['JPEG Support'] ) );

	foreach ( array( 'imagecreatetruecolor', 'imagecopyresampled', 'imagefilledellipse', 'imagepng', 'imagewebp', 'imagettfbbox', 'imagesavealpha' ) as $fn ) {
		row( "fn $fn()", function_exists( $fn ), function_exists( $fn ) );
	}

	// Render test — proves FreeType actually works and can draw Lithuanian
	// diacritics, which a capability flag alone does not.
	$font = null;
	foreach ( glob( __DIR__ . '/*.ttf' ) ?: array() as $f ) {
		$font = $f;
		break;
	}
	foreach ( array( '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf' ) as $f ) {
		if ( ! $font && is_readable( $f ) ) {
			$font = $f;
		}
	}

	if ( $freetype && $font ) {
		$im = imagecreatetruecolor( 700, 90 );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		imagettftext( $im, 28, 0, 12, 58, imagecolorallocate( $im, 0, 0, 0 ), $font, 'ĄČĘĖĮŠŲŪŽ ąčęėįšųūž' );
		$ink = 0;
		for ( $x = 0; $x < 700; $x += 3 ) {
			for ( $y = 0; $y < 90; $y += 3 ) {
				if ( ( imagecolorat( $im, $x, $y ) & 0xFF ) < 128 ) {
					++$ink;
				}
			}
		}
		imagedestroy( $im );
		row( 'Lithuanian glyphs rendered', "$ink dark samples", $ink > 100 );
		row( 'font used', basename( $font ) );
	} else {
		row( 'render test', 'skipped — put a .ttf next to this file to run it' );
	}

	// Can this host actually hold a print-resolution image?
	$before = memory_get_usage();
	$a4     = @imagecreatetruecolor( 2480, 3508 );          // A4 @300 DPI
	if ( $a4 ) {
		row( 'A4 300 DPI canvas (2480x3508)', 'allocated', true );
		imagedestroy( $a4 );
	} else {
		row( 'A4 300 DPI canvas (2480x3508)', 'FAILED', false );
	}
	$big = @imagecreatetruecolor( 4096, 4096 );             // 4x upscale target
	if ( $big ) {
		row( '4096x4096 canvas', 'allocated', true );
		imagedestroy( $big );
	} else {
		row( '4096x4096 canvas', 'FAILED — upscale path needs tiling', false );
	}
	row( 'peak memory', round( memory_get_peak_usage( true ) / 1048576, 1 ) . ' MB' );
}

// ------------------------------------------------------------ storage ----
section( 'Storage' );
$candidates = array(
	dirname( __DIR__ ) . '/aicake-private',      // one level above the webroot
	dirname( $_SERVER['DOCUMENT_ROOT'] ?? __DIR__ ) . '/aicake-private',
	sys_get_temp_dir() . '/aicake-test',
);
foreach ( array_unique( $candidates ) as $dir ) {
	$ok = @mkdir( $dir, 0755, true ) || is_dir( $dir );
	if ( $ok ) {
		$probe = $dir . '/.probe';
		$ok    = (bool) @file_put_contents( $probe, 'x' );
		@unlink( $probe );
	}
	row( 'writable: ' . $dir, $ok, $ok );
}
row( 'DOCUMENT_ROOT', $_SERVER['DOCUMENT_ROOT'] ?? '(unknown)' );

// ----------------------------------------------------------- loopback ----
// The job dispatcher fires a non-blocking request back at this same host. Some
// shared hosts block that; the plugin has a fallback, but we want to know.
section( 'Loopback' );
$self = ( ( $_SERVER['HTTPS'] ?? '' ) === 'on' ? 'https' : 'http' ) . '://' . ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) . '/';
if ( extension_loaded( 'curl' ) ) {
	$ch = curl_init( $self );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true, CURLOPT_SSL_VERIFYPEER => false ) );
	curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$err  = curl_error( $ch );
	curl_close( $ch );
	row( 'self request ' . $self, $code ? "HTTP $code" : "failed: $err", (bool) $code );
} else {
	row( 'self request', 'skipped — no cURL' );
}

// -------------------------------------------------------- outbound API ----
section( 'Outbound HTTPS' );
foreach ( array( 'https://fal.run', 'https://generativelanguage.googleapis.com' ) as $host ) {
	if ( ! extension_loaded( 'curl' ) ) {
		break;
	}
	$ch = curl_init( $host );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_NOBODY => true ) );
	curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$err  = curl_error( $ch );
	curl_close( $ch );
	row( $host, $code ? "reachable (HTTP $code)" : "BLOCKED: $err", (bool) $code );
}

echo "\n\nDone. Delete this file now.\n";
