<?php
/**
 * D-073 — the picture the customer approved is the picture inside the cut line.
 *
 * Run against the DEPLOYED copy:
 *
 *   wp eval-file /var/lib/aicake/bleed-check.php --path=/var/www/html
 *
 * Ruslan found this the way this project finds print bugs — by looking at a
 * finished sheet beside the preview the customer had approved:
 *
 * > *"the black line is inside the image, it should not be like this ... it
 * > should exactly fit to circles on the right as final product, and the user
 * > see exactly what was created in preview, but in orders i get another view."*
 *
 * The whole check is one measurement repeated: a master carries a red ring at a
 * known fraction of its own radius, and the question is always **what fraction
 * of the cut circle that ring lands on after the pipeline has run**. Before
 * D-073 a generated or found picture was `cover()`ed to the bled box, so the
 * ring came out at 0.8 of the *bled* radius and the blade took the outer 12%
 * away. It has to come out at 0.8 of the **trim** radius, from either kind of
 * master, or the preview is a promise the print does not keep.
 *
 * **Falsified two ways, and the halves are independent.** Putting
 * `render_piece()` back to a plain `cover( $master, $target_w, $target_h )`
 * turns **1 and 2** red at 738.0 px — the bled radius, to within a pixel — and
 * leaves **6**, the D-070 upload mapping, green: the two routes are genuinely
 * different rather than one hiding the other. Making
 * `PreviewPipeline::inside_the_cut_line()` return the master untouched turns
 * only **8** red, at 308.0 px against 320.0, and nothing in the print half
 * moves.
 *
 * `wp eval-file` eval()s this, so no `declare( strict_types=1 )` — a declare
 * must be the first statement of a *script* and is a fatal inside an eval.
 *
 * @package AiCake
 */

use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\SourceCatalogue;
use AiCake\Imaging\SheetLayout;
use AiCake\Support\Mm;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp eval-file.\n" );
	exit( 1 );
}

$GLOBALS['aicake_pass'] = 0;
$GLOBALS['aicake_fail'] = 0;

/**
 * Assert equality.
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

/**
 * Assert a measurement, with the tolerance stated rather than implied.
 *
 * Every number here is a pixel radius read off a rendered image, so an exact
 * comparison would be measuring GD's rounding rather than the geometry. The
 * tolerances are small enough that the bug this file exists for — a 28 px
 * difference at ⌀15 cm — cannot hide inside one.
 *
 * @param string $label     What is claimed.
 * @param float  $expect    Expected value.
 * @param float  $actual    Measured value.
 * @param float  $tolerance Allowed difference.
 */
function aicake_near( string $label, float $expect, float $actual, float $tolerance ): void {
	$off = abs( $expect - $actual );

	if ( $off <= $tolerance ) {
		++$GLOBALS['aicake_pass'];
		printf( "  ok    %-58s %.1f (want %.1f ±%.1f)\n", $label, $actual, $expect, $tolerance );

		return;
	}

	++$GLOBALS['aicake_fail'];
	printf( "  FAIL  %-58s %.1f, expected %.1f ±%.1f\n", $label, $actual, $expect, $tolerance );
}

/**
 * Assert a measurement is *not* where the bug would have put it.
 *
 * The paired opposite of `aicake_near()`, and worth its own assertion rather
 * than being left implied: "within 4 px of the right answer" and "nowhere near
 * the old wrong answer" fail differently, and a pipeline that returned garbage
 * would satisfy the second on its own.
 *
 * @param string $label   What is claimed.
 * @param float  $wrong   The value that would mean the bug is back.
 * @param float  $actual  Measured value.
 * @param float  $clear_by How far away it has to be.
 */
function aicake_far( string $label, float $wrong, float $actual, float $clear_by ): void {
	$off = abs( $wrong - $actual );

	if ( $off >= $clear_by ) {
		++$GLOBALS['aicake_pass'];
		printf( "  ok    %-58s %.1f, clear of %.1f by %.1f\n", $label, $actual, $wrong, $off );

		return;
	}

	++$GLOBALS['aicake_fail'];
	printf( "  FAIL  %-58s %.1f is only %.1f from %.1f\n", $label, $actual, $off, $wrong );
}

/**
 * A master whose every pixel says where it is.
 *
 * Blue everywhere, with a red annulus whose **inner** edge sits at
 * `$fraction` of the inscribed radius. Blue rather than white on purpose: the
 * bleed ring has to be shown to carry ink, and on a white field "inked" and
 * "blank" look identical — which is the same confusion that let the original
 * bug through.
 *
 * @param int   $size      Square side in pixels.
 * @param float $fraction  Where the ring's inner edge goes, 0..1 of the radius.
 * @param int   $thickness Ring thickness in pixels.
 * @return string PNG bytes.
 */
function aicake_ring_master( int $size, float $fraction, int $thickness ): string {
	$image = imagecreatetruecolor( $size, $size );

	$blue = imagecolorallocate( $image, 0, 0, 200 );
	$red  = imagecolorallocate( $image, 255, 0, 0 );

	imagefilledrectangle( $image, 0, 0, $size - 1, $size - 1, $blue );

	$inner = (int) round( ( $size / 2 ) * $fraction );

	imagefilledellipse( $image, (int) round( $size / 2 ), (int) round( $size / 2 ), ( $inner + $thickness ) * 2, ( $inner + $thickness ) * 2, $red );
	imagefilledellipse( $image, (int) round( $size / 2 ), (int) round( $size / 2 ), $inner * 2, $inner * 2, $blue );

	ob_start();
	imagepng( $image );
	$png = (string) ob_get_clean();

	imagedestroy( $image );

	return $png;
}

/**
 * How far from the centre the red ring starts, measured along one ray.
 *
 * The **minimum** of the four axes is what the callers use, and that is
 * deliberate: the preview carries a watermark drawn over the artwork, and
 * washing a red pixel toward white can only ever push a measurement outward.
 * Taking the smallest reading makes a watermarked ray unable to inflate the
 * result, while four rays that disagree still show up as a spread.
 *
 * @param GdImage $image Rendered image.
 * @param int     $cx    Centre x.
 * @param int     $cy    Centre y.
 * @param int     $dx    Ray direction x.
 * @param int     $dy    Ray direction y.
 * @return float Radius in pixels, or -1.0 if this ray never met red.
 */
function aicake_red_along( $image, int $cx, int $cy, int $dx, int $dy ): float {
	$w = imagesx( $image );
	$h = imagesy( $image );

	for ( $step = 1; $step < max( $w, $h ); $step++ ) {
		$x = $cx + ( $dx * $step );
		$y = $cy + ( $dy * $step );

		if ( $x < 0 || $y < 0 || $x >= $w || $y >= $h ) {
			return -1.0;
		}

		$rgb = imagecolorat( $image, $x, $y );

		$r = ( $rgb >> 16 ) & 0xFF;
		$g = ( $rgb >> 8 ) & 0xFF;
		$b = $rgb & 0xFF;

		if ( $r > 150 && $g < 110 && $b < 110 ) {
			return (float) $step;
		}
	}

	return -1.0;
}

/**
 * The four rays, so a ring that is not concentric with its cut line shows up.
 *
 * @param GdImage $image Rendered image.
 * @param int     $cx    Centre x.
 * @param int     $cy    Centre y.
 * @return array{0:float, 1:float} Smallest reading, and the spread across rays.
 */
function aicake_red_radius( $image, int $cx, int $cy ): array {
	$rays = array(
		aicake_red_along( $image, $cx, $cy, 1, 0 ),
		aicake_red_along( $image, $cx, $cy, -1, 0 ),
		aicake_red_along( $image, $cx, $cy, 0, 1 ),
		aicake_red_along( $image, $cx, $cy, 0, -1 ),
	);

	$found = array_values( array_filter( $rays, static fn( float $r ): bool => $r > 0 ) );

	if ( empty( $found ) ) {
		return array( -1.0, -1.0 );
	}

	return array( min( $found ), max( $found ) - min( $found ) );
}

/**
 * How far from the centre the black cut line is, along +x.
 *
 * @param GdImage $image Rendered image.
 * @param int     $cx    Centre x.
 * @param int     $cy    Centre y.
 */
function aicake_cut_radius( $image, int $cx, int $cy ): float {
	$w = imagesx( $image );

	for ( $step = 1; $cx + $step < $w; $step++ ) {
		$rgb = imagecolorat( $image, $cx + $step, $cy );

		if ( ( ( $rgb >> 16 ) & 0xFF ) < 60 && ( ( $rgb >> 8 ) & 0xFF ) < 60 && ( $rgb & 0xFF ) < 60 ) {
			return (float) $step;
		}
	}

	return -1.0;
}

$plugin   = AiCake\Plugin::instance();
$images   = $plugin->images();
$storage  = $plugin->storage();
$prints   = $plugin->prints();
$previews = $plugin->previews();

$spec = FormatCatalogue::spec( FormatCatalogue::TYPE_CIRCLE, 150.0 );

if ( null === $spec ) {
	fwrite( STDERR, "The ⌀15 cm circle is not in the catalogue; this check has nothing to measure.\n" );
	exit( 1 );
}

list( $trim_w )   = $spec->trim_px();
list( $target_w ) = $spec->target_px();

$trim_r   = $trim_w / 2;
$bled_r   = $target_w / 2;
$fraction = 0.8;

printf(
	"\n⌀%.0f mm · trim %d px · bled %d px · a ring at %.2f of the trim radius is %.1f px, of the bled radius %.1f px\n\n",
	$spec->width_mm,
	$trim_w,
	$target_w,
	$fraction,
	$fraction * $trim_r,
	$fraction * $bled_r
);

/* --------------------------------------------- the print, from a plain picture */

echo "A generated or found picture — nothing outside the artwork\n";

$plain_id   = 'bleedchk-plain';
$plain_path = $storage->store_master( $plain_id, aicake_ring_master( 1200, $fraction, 24 ) );

$print = $prints->render( $plain_path, $spec, null, SourceCatalogue::master_is_bled( SourceCatalogue::SEARCH ) );

aicake_check( 'a print file came back', true, null !== $print );

$page = null === $print ? null : imagecreatefromstring( $print->bytes );

if ( ! $page ) {
	fwrite( STDERR, "The print file would not decode; nothing below can be measured.\n" );
	exit( 1 );
}

/*
 * Where the piece sits on the page comes from the same plan `FulfilPipeline`
 * mounted it against (D-070). Deriving it here from the page size instead is
 * exactly the drift D-038 warns about, and it would make this file agree with
 * a wrong answer.
 */
$centre = $spec->sheet_plan()['centres_px'][0];
$cx     = (int) $centre['x'];
$cy     = (int) $centre['y'];

aicake_check(
	'the print file is still a full A4 page',
	Mm::to_px( SheetLayout::PAPER_W_MM, $spec->dpi ) . 'x' . Mm::to_px( SheetLayout::PAPER_H_MM, $spec->dpi ),
	imagesx( $page ) . 'x' . imagesy( $page )
);

list( $red, $spread ) = aicake_red_radius( $page, $cx, $cy );

aicake_near( '1 · the ring lands on the trim radius, not the bled one', $fraction * $trim_r, $red, 4.0 );
aicake_far( '2 · and not where the old cover() put it', $fraction * $bled_r, $red, 10.0 );
aicake_near( '3 · the four rays agree — artwork and cut line are concentric', 0.0, $spread, 3.0 );
aicake_near( '4 · the cut line is at the trim radius', $trim_r, aicake_cut_radius( $page, $cx, $cy ), 3.0 );

/*
 * The bleed has to carry ink. White in the bleed is the pale sliver on the cut
 * edge that bleed exists to prevent (D-070), and it is the obvious wrong way to
 * fit a picture inside the cut line — pad it and call it done.
 */
$mid   = (int) round( ( $trim_r + $bled_r ) / 2 );
$bleed = imagecolorat( $page, $cx + $mid, $cy );

aicake_check(
	'5 · the bleed ring is inked, not padded white',
	true,
	( ( $bleed >> 16 ) & 0xFF ) < 240 || ( ( $bleed >> 8 ) & 0xFF ) < 240 || ( $bleed & 0xFF ) < 240
);

imagedestroy( $page );

/* ------------------------------------------- the print, from a cropped upload */

echo "\nA cropped upload — the bleed is real photograph and D-070 owns the mapping\n";

$bled_id   = 'bleedchk-bled';
$bled_png  = aicake_ring_master( $target_w, $fraction * ( $trim_r / $bled_r ), (int) round( 24 * $target_w / 1200 ) );
$bled_path = $storage->store_master( $bled_id, $bled_png );

$print = $prints->render( $bled_path, $spec, null, SourceCatalogue::master_is_bled( SourceCatalogue::UPLOAD ) );

$page = null === $print ? null : imagecreatefromstring( $print->bytes );

aicake_check( 'a print file came back', true, false !== $page && null !== $page );

if ( $page ) {
	list( $red ) = aicake_red_radius( $page, $cx, $cy );

	/*
	 * The same expected number as assertion 1, from a master built the other
	 * way round. That is the point of D-073: two kinds of master, one rule —
	 * the ring the customer was shown is the ring the blade goes round.
	 */
	aicake_near( '6 · the upload mapping is unchanged, and agrees with 1', $fraction * $trim_r, $red, 4.0 );

	imagedestroy( $page );
}

/* ------------------------------------------------------------- the preview */

echo "\nThe preview — what the customer approves is what survives the blade\n";

$preview_r = AiCake\Pipeline\PreviewPipeline::PREVIEW_PX / 2;

$plain_preview = $previews->build( $plain_path, $plain_id, $spec, SourceCatalogue::master_is_bled( SourceCatalogue::SEARCH ) );
$bled_preview  = $previews->build( $bled_path, $bled_id, $spec, SourceCatalogue::master_is_bled( SourceCatalogue::UPLOAD ) );

foreach ( array(
	'7 · a found picture previews its cut circle'  => $plain_preview,
	'8 · an upload previews its cut circle'        => $bled_preview,
) as $label => $path ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
	$image = '' === $path ? false : imagecreatefromstring( (string) file_get_contents( $path ) );

	if ( ! $image ) {
		aicake_check( $label, true, false );

		continue;
	}

	list( $red ) = aicake_red_radius( $image, (int) round( imagesx( $image ) / 2 ), (int) round( imagesy( $image ) / 2 ) );

	aicake_near( $label, $fraction * $preview_r, $red, 6.0 );

	imagedestroy( $image );
}

/* ------------------------------------------------- the format with no bleed */

echo "\nThe whole-sheet format — no bleed, so nothing to invent\n";

$flat = $images->from_string( aicake_ring_master( 400, $fraction, 12 ) );

$covered = $images->cover( $flat, 600, 800 );
$bled    = $images->bleed_out( $flat, 600, 800, 600, 800 );

aicake_check(
	'9 · bleed_out() with no bleed is cover()',
	true,
	null !== $covered && null !== $bled
		&& imagecolorat( $covered, 300, 400 ) === imagecolorat( $bled, 300, 400 )
		&& imagecolorat( $covered, 300, 60 ) === imagecolorat( $bled, 300, 60 )
);

$images->free( $flat, $covered, $bled );

/* ---------------------------------------------------------------- clean up */

foreach ( array( $plain_path, $bled_path, $plain_preview, $bled_preview ) as $path ) {
	if ( '' !== (string) $path ) {
		$storage->delete( (string) $path );
	}
}

printf( "\n%d passed, %d failed\n", $GLOBALS['aicake_pass'], $GLOBALS['aicake_fail'] );

exit( $GLOBALS['aicake_fail'] > 0 ? 1 : 0 );
