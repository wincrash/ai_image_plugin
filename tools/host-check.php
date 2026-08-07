<?php
/**
 * Host capability check for the AI Cake Topper plugin.
 *
 * Standalone — needs no WordPress. Upload it anywhere web-accessible, open it
 * with the token in the URL, read the result, then DELETE IT.
 *
 *     https://valgomosdekoracijos.lt/host-check.php?token=sJE1SqqPpbsqAjX7HKOjhl-0
 *
 * It only reads capabilities and writes one temp file to test writability.
 * It changes nothing else.
 *
 * The token is just a password on the URL, so a passer-by or a crawler cannot
 * read your server configuration. It is already filled in below — no need to
 * change it. Delete the file when you are done and it stops mattering.
 */

const CHECK_TOKEN = 'sJE1SqqPpbsqAjX7HKOjhl-0';

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

/*
 * docs/migration.md §2.1. Production reports 256M and the measured render peak
 * is 339 MB. Whether PHP may raise its own limit decides whether that is solved
 * by configuration or only by cutting the peak. On cgi-fcgi with a per-user
 * php.ini this usually works; php_admin_value would forbid it.
 */
$before_raise = ini_get( 'memory_limit' );
@ini_set( 'memory_limit', '512M' );
$after_raise = ini_get( 'memory_limit' );
@ini_set( 'memory_limit', $before_raise );

row( 'ini_set(memory_limit, 512M) sticks', $after_raise, '512M' === $after_raise );

/*
 * Decides whether storage can live outside the webroot at all (§2.3). Empty
 * means unrestricted, which is the answer we want.
 */
$basedir = (string) ini_get( 'open_basedir' );
row( 'open_basedir', '' === $basedir ? '(none — unrestricted)' : $basedir, '' === $basedir );

/*
 * If timestamps are not validated, a file replaced over FTP keeps running the
 * old bytecode until the pool restarts — which reads exactly like an edit that
 * did nothing. Production's OPcache is also already full.
 */
if ( function_exists( 'opcache_get_configuration' ) ) {
	$oc = @opcache_get_configuration();

	if ( is_array( $oc ) ) {
		$validate = ! empty( $oc['directives']['opcache.validate_timestamps'] );
		row( 'opcache.validate_timestamps', $validate, $validate );
		row( 'opcache.revalidate_freq', $oc['directives']['opcache.revalidate_freq'] ?? '?' );
	}

	$status = @opcache_get_status( false );

	if ( is_array( $status ) ) {
		row( 'opcache cache full', ! empty( $status['cache_full'] ), empty( $status['cache_full'] ) );
	}
}

/*
 * D-050 — the settings screen refuses to store a key it cannot encrypt.
 */
row( 'sodium (key encryption)', function_exists( 'sodium_crypto_secretbox' ), function_exists( 'sodium_crypto_secretbox' ) || function_exists( 'openssl_encrypt' ) );
row( 'openssl (fallback cipher)', function_exists( 'openssl_encrypt' ) );

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

/*
 * The first candidate is the real target from docs/migration.md §2.3: a sibling
 * of public_html on this host's DirectAdmin layout, so nothing generated is ever
 * reachable by URL. The others are fallbacks worth knowing about.
 */
$docroot    = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
$candidates = array(
	dirname( $docroot ) . '/aicake-files',       // the intended location
	dirname( __DIR__ ) . '/aicake-private',
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
$self        = ( ( $_SERVER['HTTPS'] ?? '' ) === 'on' ? 'https' : 'http' ) . '://' . ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) . '/';
$loopback_ok = false;
if ( extension_loaded( 'curl' ) ) {
	$ch = curl_init( $self );
	curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => true, CURLOPT_SSL_VERIFYPEER => false ) );
	curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	$err  = curl_error( $ch );
	curl_close( $ch );
	$loopback_ok = (bool) $code;
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

/* ------------------------------------------------------- REST for visitors --
 * docs/migration.md §2.2. The wizard's entire audience is logged out, and Really
 * Simple Security has a hardening option that disables the REST API for exactly
 * those people. If this is not a 200 with a JSON body, the wizard cannot work
 * for a customer no matter what the plugin does — and it will look like our bug.
 */
section( 'REST API, as a logged-out visitor' );

/*
 * This probe is the server calling itself, which is the loopback path. If
 * loopback is blocked, a failure here says nothing whatsoever about the REST
 * API — and reporting "the wizard cannot work" on that basis would be a false
 * alarm on the single most alarming line in this file. So when loopback is
 * down, the honest answer is that a browser has to decide it.
 */
$rest_url = ( ( $_SERVER['HTTPS'] ?? '' ) === 'on' ? 'https' : 'http' ) . '://' . ( $_SERVER['HTTP_HOST'] ?? 'localhost' ) . '/wp-json/';

echo "  Open this in a private browser window, logged out. You should see JSON,\n";
echo "  not a 401 or an error page:\n\n      $rest_url\n\n";

if ( ! $loopback_ok ) {
	row( 'server-side probe', 'skipped — loopback is blocked, so it would prove nothing' );
} elseif ( extension_loaded( 'curl' ) ) {
	$ch = curl_init( $rest_url );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 12,
			CURLOPT_SSL_VERIFYPEER => false,
			// No cookies, no auth: this is what a first-time visitor is.
			CURLOPT_COOKIEFILE     => '',
		)
	);
	$body = (string) curl_exec( $ch );
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	$ok = 200 === (int) $code && str_contains( $body, '"namespaces"' );

	row( $rest_url, "HTTP $code", $ok );
	row( 'anonymous REST usable  <-- CRITICAL', $ok ? 'yes' : 'NO — the wizard cannot work logged out', $ok );

	if ( ! $ok ) {
		echo "      Check Really Simple Security -> Hardening for a REST API restriction.\n";
		echo "      The fix is an allowance for the aicake/v1 namespace, not switching\n";
		echo "      the hardening off site-wide.\n";
	}
} else {
	row( 'REST probe', 'skipped — no cURL' );
}

echo "\n\nDone. Delete this file now.\n";
