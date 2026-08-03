<?php
/**
 * Wizard step 3: what the text-layer endpoint accepts, and what it refuses.
 *
 * Run inside the container, against the deployed copy:
 *
 *   docker compose exec -u www-data wordpress \
 *     wp eval-file /var/lib/aicake/text-check.php --path=/var/www/html
 *
 * D-033 moved text composition into the browser, which means the server now
 * takes a bitmap from a stranger. Moderation layers 0–2 read words; a bitmap
 * has none. So the assertions that matter here are the **refusals** — an
 * endpoint that accepts everything passes any test that only feeds it good
 * input, and would look perfectly healthy while protecting nothing.
 *
 * Creates its own fixtures and cleans them up, so it re-runs from nothing.
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery

use AiCake\Domain\DesignRepository;
use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\PrintSpec;
use AiCake\Domain\TextLayer;
use AiCake\Pipeline\ProofPipeline;
use AiCake\Throttle\IdentityResolver;

$GLOBALS['aicake_pass'] = 0;
$GLOBALS['aicake_fail'] = 0;

/**
 * @param string $label  What is being asserted.
 * @param mixed  $expect Expected value.
 * @param mixed  $actual Actual value.
 */
function aicake_check( string $label, $expect, $actual ): void {
	global $aicake_pass, $aicake_fail;

	if ( $expect === $actual ) {
		printf( "  ok    %-56s %s\n", $label, is_scalar( $actual ) ? (string) $actual : gettype( $actual ) );
		++$aicake_pass;

		return;
	}

	printf( "  FAIL  %-56s expected %s, got %s\n", $label, var_export( $expect, true ), var_export( $actual, true ) );
	++$aicake_fail;
}

$plugin  = AiCake\Plugin::instance();
$designs = $plugin->designs();
$storage = $plugin->storage();

/* -------------------------------------------------------------- fixtures */

/*
 * A session key we control, planted in the cookie superglobal so
 * IdentityResolver resolves the same identity the design was written with.
 * Without it every request is a stranger and every assertion below reads 404
 * for the wrong reason.
 */
$session = str_repeat( 'a', 32 );
$stranger = str_repeat( 'b', 32 );

$_COOKIE[ IdentityResolver::COOKIE ] = $session;

/**
 * A design owned by a given session, in a given format.
 *
 * @param DesignRepository $designs Repository.
 * @param string           $session Session key.
 * @param string           $type    Format type.
 * @param float            $mm      Format size.
 * @return array<string, mixed> The design row.
 */
function aicake_fixture_design( DesignRepository $designs, string $session, string $type, float $mm ): array {
	$id = $designs->create(
		array(
			'session_key' => $session,
			'prompt_raw'  => 'text-check fixture',
			'status'      => DesignRepository::STATUS_DONE,
			'format_type' => $type,
			'format_mm'   => $mm,
			'aspect'      => '1:1',
		)
	);

	$design = (array) $designs->find( $id );

	/*
	 * A real preview file, not just a row. The D-045 proof lays this out per
	 * piece and composites the layer over it, so a fixture with no artwork on
	 * disk cannot exercise it — and would report the feature broken rather
	 * than the fixture incomplete.
	 *
	 * Deliberately not a flat colour: „the proof differs from the preview"
	 * has to fail for the right reason, and two solid images of different
	 * sizes differ for the wrong one.
	 */
	$plugin = AiCake\Plugin::instance();
	$canvas = $plugin->images()->blank( 400, 400, false );

	if ( null !== $canvas ) {
		imagefilledrectangle( $canvas, 0, 0, 399, 399, imagecolorallocate( $canvas, 210, 180, 140 ) );
		imagefilledellipse( $canvas, 200, 200, 260, 260, imagecolorallocate( $canvas, 90, 140, 200 ) );

		$path = $plugin->storage()->write(
			$plugin->storage()->session_path( (string) $design['public_id'], 'preview.webp' ),
			$plugin->images()->to_webp( $canvas, 82 )
		);

		$plugin->images()->free( $canvas );

		if ( '' !== $path ) {
			$designs->update( $id, array( 'file_preview' => $path ) );

			$design = (array) $designs->find( $id );
		}
	}

	return $design;
}

/**
 * A transparent PNG of a given size with text-like ink in given colours.
 *
 * @param int      $w       Width.
 * @param int      $h       Height.
 * @param string[] $colours Colours to draw with, #rrggbb.
 * @param int      $bars    How many bars of ink.
 * @return string Data URL.
 */
function aicake_fixture_layer( int $w, int $h, array $colours, int $bars = 6 ): string {
	$image = imagecreatetruecolor( $w, $h );
	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	imagefilledrectangle( $image, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );

	foreach ( $colours as $i => $hex ) {
		$r = (int) hexdec( substr( $hex, 1, 2 ) );
		$g = (int) hexdec( substr( $hex, 3, 2 ) );
		$b = (int) hexdec( substr( $hex, 5, 2 ) );

		$ink = imagecolorallocatealpha( $image, $r, $g, $b, 0 );

		for ( $n = 0; $n < $bars; $n++ ) {
			$y = 40 + ( ( ( $i * $bars ) + $n ) * 30 );

			if ( $y + 12 >= $h ) {
				break;
			}

			imagefilledrectangle( $image, 40, $y, min( $w - 40, 400 ), $y + 12, $ink );
		}
	}

	ob_start();
	imagepng( $image );
	$bytes = (string) ob_get_clean();

	imagedestroy( $image );

	return 'data:image/png;base64,' . base64_encode( $bytes );
}

/**
 * Put one off-palette pixel into an existing data URL.
 *
 * @param string $data_url The layer.
 * @param int    $r        Red.
 * @param int    $g        Green.
 * @param int    $b        Blue.
 * @return string Data URL.
 */
function aicake_taint( string $data_url, int $r, int $g, int $b ): string {
	$bytes = base64_decode( substr( $data_url, strlen( 'data:image/png;base64,' ) ), true );
	$image = imagecreatefromstring( (string) $bytes );

	imagealphablending( $image, false );
	imagesavealpha( $image, true );
	imagesetpixel( $image, 12, 12, imagecolorallocatealpha( $image, $r, $g, $b, 0 ) );

	ob_start();
	imagepng( $image );
	$out = (string) ob_get_clean();

	imagedestroy( $image );

	return 'data:image/png;base64,' . base64_encode( $out );
}

/**
 * Post a layer and return [ status, body ].
 *
 * Goes through `rest_do_request` rather than calling the handler, so route
 * registration, the argument schema and the permission callback are all
 * exercised — a handler tested in isolation passes happily while the route it
 * is wired to is unreachable.
 *
 * @param string          $public_id Design handle.
 * @param string          $layer     Data URL.
 * @param string[]|string $colours   Declared colours.
 * @param string          $text      The plain string.
 * @return array{0:int, 1:mixed}
 */
function aicake_post_layer( string $public_id, string $layer, $colours, string $text = 'Sveikinu' ): array {
	$request = new WP_REST_Request( 'POST', '/aicake/v1/text-layer' );

	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_body_params(
		array(
			'design'  => $public_id,
			'layer'   => $layer,
			'colours' => $colours,
			'text'    => $text,
		)
	);

	$response = rest_do_request( $request );

	return array( $response->get_status(), $response->get_data() );
}

/**
 * Clear the endpoint's own cooldown between scenarios.
 *
 * The cooldown is real and is asserted once, deliberately. Leaving it in place
 * between the other scenarios would make every one after the first return 429
 * — which reads exactly like the endpoint being broken.
 */
function aicake_clear_cooldown(): void {
	$identity = AiCake\Plugin::instance()->identity();

	delete_transient( 'aicake_layer_' . md5( $identity->session_key() . '|' . $identity->ip_hash() ) );
}

$circle  = aicake_fixture_design( $designs, $session, FormatCatalogue::TYPE_CIRCLE, 150.0 );
$cupcake = aicake_fixture_design( $designs, $session, FormatCatalogue::TYPE_CUPCAKE, 45.0 );
$theirs  = aicake_fixture_design( $designs, $stranger, FormatCatalogue::TYPE_CIRCLE, 150.0 );

$circle_spec = PrintSpec::for_design( $circle );

list( $cw, $ch ) = $circle_spec->canvas_px();

printf( "\nFixtures\n" );
printf( "  circle design  %s  canvas %dx%d\n", $circle['public_id'], $cw, $ch );
printf( "  cupcake design %s\n", $cupcake['public_id'] );

echo "\nThe geometry the editor is handed\n";

/*
 * The editor draws against these numbers, so if they ever stop agreeing with
 * imposition the text moves on the printed sheet and nowhere else.
 */
$layout    = PrintSpec::for_design( $cupcake )->editor_layout();
$imposed   = PrintSpec::for_design( $cupcake )->sheet_plan();

aicake_check( 'a piece per cupcake', 24, count( $layout['pieces'] ) );
aicake_check( 'matching the imposition', (int) $imposed['per_sheet'], count( $layout['pieces'] ) );
aicake_check( 'first centre agrees with SheetLayout', (int) $imposed['centres_px'][0]['x'], $layout['pieces'][0]['cx'] );
aicake_check( 'last centre agrees with SheetLayout', (int) $imposed['centres_px'][23]['y'], $layout['pieces'][23]['cy'] );
aicake_check( 'the editor limit is the cut line (D-042)', $layout['pieces'][0]['w'], $layout['pieces'][0]['limit_w'] );
aicake_check( 'the safe box is still reported, inside it', true, $layout['pieces'][0]['safe_w'] < $layout['pieces'][0]['limit_w'] );

echo "\nWhat it accepts\n";

aicake_clear_cooldown();

$good              = aicake_fixture_layer( $cw, $ch, array( '#c62828', '#ffffff' ) );
list( $status, $body ) = aicake_post_layer( $circle['public_id'], $good, array( '#c62828', '#ffffff' ) );

aicake_check( 'a clean layer is accepted', 200, $status );
aicake_check( 'and reports ink', true, ( $body['ink_px'] ?? 0 ) > 0 );

$stored = TextLayer::from_design( (array) $designs->find( (int) $circle['id'] ) );

aicake_check( 'the payload is stored', true, $stored instanceof TextLayer );
aicake_check( 'the plain string is kept for moderation', 'Sveikinu', $stored->text );
aicake_check( 'the file is on disk', true, $stored->has_bitmap() );
aicake_check( 'at the canvas size', $cw, $stored->width_px );

/*
 * Re-encoded rather than stored as received. The DPI is the visible proof: the
 * bytes posted above came from a plain `imagepng()` with no pHYs at 300, so a
 * file that declares 300 cannot be the one that was uploaded (D-027).
 */
aicake_check( 'and re-encoded, declaring print DPI', 300, $plugin->images()->read_dpi( (string) file_get_contents( $stored->path ) ) );

echo "\nThe proof the cart shows (D-045)\n";

/*
 * The cart, the order screen and the email have no canvas, so they cannot show
 * step 4's proof. Without a server-built one they showed the bare artwork, and
 * a customer who placed twelve names saw one plain circle and no way to tell
 * whether their text had survived.
 *
 * Asserted on the *file*, not on the column: a path recorded for an image that
 * was never written is exactly the failure this is meant to rule out.
 */
$row   = (array) $designs->find( (int) $circle['id'] );
$proof = (string) ( $row['file_proof'] ?? '' );

aicake_check( 'a proof is recorded', true, '' !== $proof );
aicake_check( 'and it is on disk', true, '' !== $proof && is_readable( $proof ) );

$proof_size = '' !== $proof && is_readable( $proof )
	? (array) getimagesize( $proof )
	: array( 0, 0 );

/*
 * Its shape is the print canvas's shape, scaled — so a 24-up sheet's proof is
 * sheet-shaped and a single topper's is square. Compared as a ratio rather than
 * as fixed pixels, because the long edge is a constant that may be tuned.
 */
$want_ratio = $cw / max( 1, $ch );
$got_ratio  = ( $proof_size[0] ?? 0 ) / max( 1, $proof_size[1] ?? 1 );

aicake_check( 'the proof has the canvas aspect', true, abs( $want_ratio - $got_ratio ) < 0.02 );
aicake_check( 'and its long edge is the proof size', ProofPipeline::PROOF_PX, max( (int) ( $proof_size[0] ?? 0 ), (int) ( $proof_size[1] ?? 0 ) ) );

/*
 * And it really carries the text, rather than being a copy of the preview.
 * The layer is the only thing between the two, so a proof that weighs the same
 * as a shaped preview is a proof with nothing composited onto it.
 */
aicake_check(
	'the proof differs from the bare preview',
	true,
	'' !== $proof && is_readable( $proof ) && (string) file_get_contents( $proof ) !== (string) file_get_contents( (string) $row['file_preview'] )
);

echo "\nWhat it refuses\n";

aicake_clear_cooldown();

list( $status ) = aicake_post_layer( $theirs['public_id'], $good, array( '#c62828', '#ffffff' ) );
aicake_check( "someone else's design is 404, not 403", 404, $status );

list( $status ) = aicake_post_layer( str_repeat( 'f', 32 ), $good, array( '#c62828', '#ffffff' ) );
aicake_check( 'an unknown design is 404', 404, $status );

aicake_clear_cooldown();

/*
 * The load-bearing one. A single off-palette pixel: a franchise character is
 * thousands, but the check has to bite at one or "mostly text with a small
 * logo pasted into the corner" walks straight through.
 */
list( $status ) = aicake_post_layer( $circle['public_id'], aicake_taint( $good, 0, 200, 0 ), array( '#c62828', '#ffffff' ) );
aicake_check( 'one undeclared pixel is refused', 422, $status );

aicake_clear_cooldown();

list( $status ) = aicake_post_layer( $circle['public_id'], $good, array( '#000000', '#ffffff' ) );
aicake_check( 'declaring the wrong colours is refused', 422, $status );

aicake_clear_cooldown();

list( $status ) = aicake_post_layer( $circle['public_id'], $good, array() );
aicake_check( 'declaring no colours is refused', 422, $status );

aicake_clear_cooldown();

$wrong_size = aicake_fixture_layer( 600, 600, array( '#c62828' ) );
list( $status ) = aicake_post_layer( $circle['public_id'], $wrong_size, array( '#c62828' ) );
aicake_check( 'a layer that is not the print canvas is refused', 400, $status );

aicake_clear_cooldown();

/*
 * A cupcake layer posted against a circle design. Same idea as the above but
 * the realistic version: both are valid canvases, just not for this design.
 */
list( $ccw, $cch ) = PrintSpec::for_design( $cupcake )->canvas_px();

if ( $ccw !== $cw || $cch !== $ch ) {
	list( $status ) = aicake_post_layer( $circle['public_id'], aicake_fixture_layer( $ccw, $cch, array( '#c62828' ) ), array( '#c62828' ) );
	aicake_check( "another format's canvas is refused", 400, $status );

	aicake_clear_cooldown();
}

list( $status ) = aicake_post_layer( $circle['public_id'], 'data:image/png;base64,bm90LWEtcG5n', array( '#c62828' ) );
aicake_check( 'bytes that are not a PNG are refused', 400, $status );

aicake_clear_cooldown();

list( $status ) = aicake_post_layer( $circle['public_id'], 'data:image/svg+xml;base64,PHN2Zy8+', array( '#c62828' ) );
aicake_check( 'an SVG data URL is refused', 400, $status );

aicake_clear_cooldown();

/*
 * §10 still applies to text typed into a canvas. This is the whole reason the
 * plain string crosses the wire at all (D-033).
 */
list( $status ) = aicake_post_layer( $circle['public_id'], $good, array( '#c62828', '#ffffff' ), 'Žmogaus voro tinklas' );
aicake_check( 'a blocked string is refused, bitmap or not', 422, $status );

echo "\nAnd it does not run the scan in a loop\n";

aicake_clear_cooldown();

list( $status ) = aicake_post_layer( $circle['public_id'], $good, array( '#c62828', '#ffffff' ) );
aicake_check( 'the first upload is accepted', 200, $status );

list( $status ) = aicake_post_layer( $circle['public_id'], $good, array( '#c62828', '#ffffff' ) );
aicake_check( 'an immediate second is throttled', 429, $status );

/* --------------------------------------------------------------- cleanup */

aicake_clear_cooldown();

foreach ( array( $circle, $cupcake, $theirs ) as $row ) {
	$layer = TextLayer::from_design( (array) $designs->find( (int) $row['id'] ) );

	if ( $layer instanceof TextLayer && '' !== $layer->path ) {
		$storage->delete( $layer->path );
	}

	$GLOBALS['wpdb']->delete( AiCake\Installer::table( 'designs' ), array( 'id' => (int) $row['id'] ) );
}

printf( "\n%d passed, %d failed\n", $GLOBALS['aicake_pass'], $GLOBALS['aicake_fail'] );

exit( $GLOBALS['aicake_fail'] > 0 ? 1 : 0 );
