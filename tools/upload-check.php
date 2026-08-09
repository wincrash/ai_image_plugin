<?php
/**
 * D-062 — a photograph gets in, and everything else does not.
 *
 * Run against the DEPLOYED copy:
 *
 *   wp eval-file /var/lib/aicake/upload-check.php --path=/var/www/html
 *
 * The happy path here is one assertion. The rest of the file is the refusals,
 * because that is where the value is: Ruslan's instruction was *"user can upload
 * what he wants ... maybe need think only on security"*, so the only question
 * this endpoint has to answer well is whether what arrived is genuinely an
 * image.
 *
 * `wp eval-file` eval()s this, so no `declare( strict_types=1 )` — a declare
 * must be the first statement of a *script* and is a fatal inside an eval.
 *
 * @package AiCake
 */

use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\SourceCatalogue;
use AiCake\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp eval-file.\n" );
	exit( 1 );
}

$GLOBALS['aicake_pass'] = 0;
$GLOBALS['aicake_fail'] = 0;

/**
 * Assert.
 *
 * @param string $label  What is claimed.
 * @param mixed  $expect Expected.
 * @param mixed  $actual Actual.
 */
function aicake_check( string $label, $expect, $actual ): void {
	if ( $expect === $actual ) {
		++$GLOBALS['aicake_pass'];
		printf( "  ok    %-58s %s\n", $label, var_export( $actual, true ) );

		return;
	}

	++$GLOBALS['aicake_fail'];
	printf( "  FAIL  %-58s expected %s, got %s\n", $label, var_export( $expect, true ), var_export( $actual, true ) );
}

$plugin   = AiCake\Plugin::instance();
$settings = $plugin->settings();
$designs  = $plugin->designs();

/**
 * A JPEG data URL of the given size, with something in it.
 *
 * Real pixels rather than flat colour, because a uniform image compresses to
 * almost nothing and would pass a byte cap that a real photograph would not.
 *
 * @param int $w Width.
 * @param int $h Height.
 * @return string Data URL.
 */
function aicake_photo( int $w, int $h ): string {
	$image = imagecreatetruecolor( $w, $h );

	for ( $i = 0; $i < 60; $i++ ) {
		imagefilledellipse(
			$image,
			wp_rand( 0, $w ),
			wp_rand( 0, $h ),
			wp_rand( 10, (int) max( 11, $w / 3 ) ),
			wp_rand( 10, (int) max( 11, $h / 3 ) ),
			imagecolorallocate( $image, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) )
		);
	}

	ob_start();
	imagejpeg( $image, null, 90 );
	$bytes = (string) ob_get_clean();

	imagedestroy( $image );

	return 'data:image/jpeg;base64,' . base64_encode( $bytes );
}

/**
 * A decompression bomb: a tiny PNG whose header claims to be enormous.
 *
 * Forged rather than rendered. An attacker does not build a real 20000 x 20000
 * image and upload 40 MB of it — they patch the IHDR of something small, and
 * the damage is done by the *decoder* believing it. So the fixture has to be
 * the same shape as the attack, or it tests the byte cap instead of the
 * dimension check.
 *
 * The IHDR is the first chunk and its layout is fixed: 8 bytes of signature,
 * a 4-byte length, the type, then width and height. The CRC covers the type
 * and the data together, so it has to be recomputed or `getimagesize()` will
 * refuse to read the header at all and the check would pass for the wrong
 * reason.
 *
 * @param int $width  Declared width.
 * @param int $height Declared height.
 * @return string PNG bytes.
 */
function aicake_bomb( int $width, int $height ): string {
	$small = imagecreatetruecolor( 4, 4 );

	ob_start();
	imagepng( $small );
	$png = (string) ob_get_clean();

	imagedestroy( $small );

	// Width at byte 16, height at byte 20 — both big-endian.
	$png = substr_replace( $png, pack( 'N', $width ), 16, 4 );
	$png = substr_replace( $png, pack( 'N', $height ), 20, 4 );

	// CRC over the chunk type and its data: bytes 12..28 inclusive.
	$crc = crc32( substr( $png, 12, 17 ) );

	return substr_replace( $png, pack( 'N', $crc ), 29, 4 );
}

/**
 * POST one upload; returns [status, body].
 *
 * @param string $image       Data URL or raw base64.
 * @param string $format_type Format type.
 * @param float  $mm          Diameter.
 * @return array{0:int, 1:mixed}
 */
function aicake_post_upload( string $image, string $format_type = 'circle', float $mm = 150.0 ): array {
	$request = new WP_REST_Request( 'POST', '/aicake/v1/upload' );

	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_body_params(
		array(
			'image'       => $image,
			'format_type' => $format_type,
			'format_mm'   => $mm,
		)
	);

	$response = rest_do_request( $request );

	return array( $response->get_status(), $response->get_data() );
}

/**
 * Clear the endpoint's own cooldown between scenarios.
 *
 * It is real and asserted once. Left in place, every scenario after the first
 * would return 429 — which reads exactly like the endpoint being broken.
 */
function aicake_clear_cooldown(): void {
	$identity = AiCake\Plugin::instance()->identity();

	delete_transient( 'aicake_upload_' . md5( $identity->session_key() . '|' . $identity->ip_hash() ) );
}

echo "\nUpload — D-062\n\n";

$was_upload = (bool) $settings->get( 'source_upload', true );
$settings->update( array( 'source_upload' => true ) );

$created = array();

/* ------------------------------------------------------- it accepts a photo */

aicake_clear_cooldown();

list( $status, $body ) = aicake_post_upload( aicake_photo( 900, 900 ) );

aicake_check( 'a photograph is accepted', 201, $status );
aicake_check( 'and needs no job to wait for', 'done', $body['status'] ?? '' );

$row = $designs->find_by_public_id( (string) ( $body['design'] ?? '' ) );

if ( is_array( $row ) ) {
	$created[] = $row;
}

aicake_check( 'the row records where it came from', SourceCatalogue::UPLOAD, $row['source'] ?? '' );
aicake_check( 'the picture is on disk', true, is_readable( (string) ( $row['file_master'] ?? '' ) ) );
aicake_check( 'and it has a preview like any other design', true, is_readable( (string) ( $row['file_preview'] ?? '' ) ) );

/*
 * No provider and no cost, so the AI surcharge cannot attach to it (D-058).
 * A customer who uploaded their own child's photograph must not be charged a
 * euro for artificial intelligence that never ran.
 */
aicake_check( 'nobody generated it', true, in_array( $row['provider'] ?? null, array( null, '' ), true ) );
aicake_check( 'and it cost nothing', 0.0, (float) ( $row['cost_usd'] ?? -1 ) );

/*
 * Re-encoded, not stored as received. What lands on disk is a PNG whatever
 * arrived — that is the whole defence, and it is checked by reading the file's
 * own signature rather than its extension.
 */
$master = (string) ( $row['file_master'] ?? '' );
$head   = '' === $master ? '' : (string) file_get_contents( $master, false, null, 0, 8 );

aicake_check( 'a JPEG upload is stored re-encoded as PNG', "\x89PNG\r\n\x1a\n", $head );

/* --------------------------------------------------------- what it refuses */

echo "\nWhat it refuses\n";

aicake_clear_cooldown();
list( $status ) = aicake_post_upload( 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' ) );
aicake_check( 'an SVG is refused — it is a document, not a bitmap', 400, $status );

aicake_clear_cooldown();
list( $status ) = aicake_post_upload( 'data:image/png;base64,' . base64_encode( '<?php echo "hello"; ?>' ) );
aicake_check( 'bytes that only claim to be a PNG are refused', 400, $status );

aicake_clear_cooldown();
list( $status ) = aicake_post_upload( 'data:image/jpeg;base64,' . base64_encode( "GIF89a" . str_repeat( 'x', 400 ) ) );
aicake_check( 'a GIF wearing a JPEG label is refused', 400, $status );

aicake_clear_cooldown();
list( $status ) = aicake_post_upload( '' );
aicake_check( 'nothing at all is refused', 400, $status );

/*
 * The decompression bomb, and the only refusal on this list that protects the
 * SHOP rather than the customer. This PNG is a few kilobytes on the wire and
 * declares 20000 x 20000 — about 1.6 GB the instant GD is asked for it, which
 * on a 256 MB host does not fail, it takes the worker down and whatever else
 * was sharing it.
 *
 * It has to be refused from the HEADER, before any decode. A check that
 * measures the image after decoding it has already lost.
 */
aicake_clear_cooldown();

$bomb_bytes = aicake_bomb( 20000, 20000 );

list( $status ) = aicake_post_upload( 'data:image/png;base64,' . base64_encode( $bomb_bytes ) );

aicake_check( 'a decompression bomb is refused on its header', 413, $status );

/*
 * The fixture is only a real bomb if it is *small*. An earlier version built a
 * genuine 20000 x 20000 image with `imagecreatetruecolor()` — which allocates
 * 1.6 GB inside the check itself, and produced a file over a megabyte that the
 * byte cap would have caught anyway. That version proved the byte cap works and
 * said nothing at all about the dimension check.
 */
aicake_check( 'and the bomb really was tiny on the wire', true, strlen( $bomb_bytes ) < 4096 );
aicake_check( 'while genuinely declaring its size', array( 20000, 20000 ), array_slice( (array) getimagesizefromstring( $bomb_bytes ), 0, 2 ) );

/* -------------------------------------------------- the switch, and the lock */

echo "\nThe switch\n";

aicake_clear_cooldown();
$settings->update( array( 'source_upload' => false ) );

$off = aicake_post_upload( aicake_photo( 400, 400 ) );

$settings->update( array( 'source_upload' => $was_upload ) );

aicake_check( 'switching uploads off refuses at the endpoint, not just the page', 403, $off[0] );

/* --------------------------------------------------------------- cooldown */

aicake_clear_cooldown();

list( $first ) = aicake_post_upload( aicake_photo( 400, 400 ) );
list( $again ) = aicake_post_upload( aicake_photo( 400, 400 ) );

aicake_check( 'the first upload is accepted', 201, $first );
aicake_check( 'an immediate second is throttled', 429, $again );

/* --------------------------------------------------------------- cleanup */

aicake_clear_cooldown();

$storage = $plugin->storage();
$table   = Installer::table( 'designs' );

// Everything this run created, found by source rather than by remembering ids —
// the throttle scenario deliberately does not keep hold of what it made.
$leftovers = $GLOBALS['wpdb']->get_results(
	$GLOBALS['wpdb']->prepare(
		"SELECT id, file_master, file_preview, file_proof FROM {$table}
		  WHERE source = %s AND order_id IS NULL",
		SourceCatalogue::UPLOAD
	),
	ARRAY_A
);

foreach ( (array) $leftovers as $candidate ) {
	foreach ( array( 'file_master', 'file_preview', 'file_proof' ) as $column ) {
		$path = (string) ( $candidate[ $column ] ?? '' );

		if ( '' !== $path ) {
			$storage->delete( $path );
		}
	}

	$GLOBALS['wpdb']->delete( $table, array( 'id' => (int) $candidate['id'] ), array( '%d' ) );
}

printf( "\n%d passed, %d failed\n", $GLOBALS['aicake_pass'], $GLOBALS['aicake_fail'] );

exit( $GLOBALS['aicake_fail'] > 0 ? 1 : 0 );
