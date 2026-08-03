<?php
/**
 * Does a stored text layer sit inside its safe zones?
 *
 * Run inside the container, against the deployed copy:
 *
 *   docker compose exec -u www-data wordpress \
 *     wp eval-file /var/lib/aicake/layer-check.php <design-public-id> --path=/var/www/html
 *
 * With no id it takes the most recent design that has a layer.
 *
 * `tools/text-check.php` proves the endpoint's contract using layers this
 * codebase built. That leaves a different question open: whether the *browser
 * editor* keeps its side of D-033 — the safe zone is a constraint, not a guide,
 * and the customer cuts with scissors. Only a real export can answer that, and
 * this is what reads one.
 *
 * It is also the diagnostic to reach for when a printed sheet comes back with a
 * name clipped: it says immediately whether the layer was wrong or the cutting
 * was.
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery

use AiCake\Domain\PrintSpec;
use AiCake\Domain\TextLayer;
use AiCake\Installer;
use AiCake\Support\Mm;

$plugin  = AiCake\Plugin::instance();
$designs = $plugin->designs();

$public_id = isset( $args[0] ) ? (string) $args[0] : '';

if ( '' === $public_id ) {
	global $wpdb;

	$table = Installer::table( 'designs' );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$public_id = (string) $wpdb->get_var( "SELECT public_id FROM {$table} WHERE text_payload IS NOT NULL AND text_payload LIKE '%\"path\"%' ORDER BY id DESC LIMIT 1" );

	if ( '' === $public_id ) {
		echo "No design has a stored text layer.\n";
		exit( 1 );
	}
}

$design = $designs->find_by_public_id( $public_id );

if ( null === $design ) {
	printf( "No such design: %s\n", $public_id );
	exit( 1 );
}

$layer = TextLayer::from_design( $design );

if ( ! $layer instanceof TextLayer || ! $layer->has_bitmap() ) {
	printf( "Design %s has no stored layer.\n", $public_id );
	exit( 1 );
}

$spec   = PrintSpec::for_design( $design );
$layout = $spec->editor_layout();

list( $canvas_w, $canvas_h ) = $spec->canvas_px();

printf( "\nDesign      : %s  (%s %s mm)\n", $public_id, $design['format_type'], $design['format_mm'] );
printf( "Text        : %s\n", $layer->text );
printf( "Colours     : %s\n", implode( ' ', $layer->colours ) );
printf( "Layer       : %s\n", $layer->path );
printf( "Size        : %dx%d against a %dx%d print canvas — %s\n\n", $layer->width_px, $layer->height_px, $canvas_w, $canvas_h, ( $layer->width_px === $canvas_w && $layer->height_px === $canvas_h ) ? 'MATCH' : 'MISMATCH' );

$image = imagecreatefrompng( $layer->path );

if ( false === $image ) {
	echo "The layer will not decode.\n";
	exit( 1 );
}

imagealphablending( $image, false );

$failures = 0;
$inked    = array();

foreach ( $layout['pieces'] as $i => $piece ) {
	$half = (int) ceil( $piece['w'] / 2 );
	$ink  = 0;

	/*
	 * How far the worst inked pixel pushes past the editor's limit, in pixels.
	 * Negative is clearance.
	 *
	 * Measured against `limit_*` — the trim line since D-042 — because that is
	 * what the editor constrains to. Auditing against a different boundary than
	 * the one being enforced makes this report noise. The safe margin is
	 * reported alongside as advisory, since text at the trim line is text a
	 * wandering cut can catch.
	 *
	 * A round piece is measured radially, not as a bounding box: a corner can
	 * sit inside the box and outside the circle, which is precisely where the
	 * cut removes it. A rectangular piece has two dimensions and each axis is
	 * checked against its own.
	 */
	$overshoot = -INF;
	$past_safe = -INF;

	for ( $y = max( 0, $piece['cy'] - $half ); $y <= min( imagesy( $image ) - 1, $piece['cy'] + $half ); $y++ ) {
		for ( $x = max( 0, $piece['cx'] - $half ); $x <= min( imagesx( $image ) - 1, $piece['cx'] + $half ); $x++ ) {
			if ( 127 === ( imagecolorat( $image, $x, $y ) >> 24 & 0x7F ) ) {
				continue;
			}

			++$ink;

			$dx = $x - $piece['cx'];
			$dy = $y - $piece['cy'];

			if ( $layout['round'] ) {
				$radius = sqrt( ( $dx * $dx ) + ( $dy * $dy ) );

				$overshoot = max( $overshoot, $radius - ( $piece['limit_w'] / 2 ) );
				$past_safe = max( $past_safe, $radius - ( $piece['safe_w'] / 2 ) );
			} else {
				$overshoot = max( $overshoot, max( abs( $dx ) - ( $piece['limit_w'] / 2 ), abs( $dy ) - ( $piece['limit_h'] / 2 ) ) );
				$past_safe = max( $past_safe, max( abs( $dx ) - ( $piece['safe_w'] / 2 ), abs( $dy ) - ( $piece['safe_h'] / 2 ) ) );
			}
		}
	}

	if ( 0 === $ink ) {
		continue;
	}

	$inside = $overshoot <= 0;

	$inked[] = $i;

	if ( ! $inside ) {
		++$failures;
	}

	printf(
		"  piece %-3d ink %-8d past the cut line by %+7.2f mm   past the 5 mm safe margin by %+6.2f mm   %s\n",
		$i,
		$ink,
		$overshoot / Mm::px_per_mm( $spec->dpi ),
		$past_safe / Mm::px_per_mm( $spec->dpi ),
		$inside ? 'inside' : 'OVER THE CUT LINE'
	);
}

printf( "\nPieces carrying text: %s\n", array() === $inked ? 'none' : implode( ', ', $inked ) );
printf( "%d piece(s) over the cut line.\n", $failures );

exit( $failures > 0 ? 1 : 0 );
