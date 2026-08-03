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
	 * How far the worst inked pixel pushes past the safe boundary, in pixels.
	 * Negative is clearance.
	 *
	 * A round piece is measured radially, not as a bounding box: a corner can
	 * sit inside the box and outside the circle, which is precisely where a
	 * hand cut removes it. A rectangular piece has two different safe
	 * dimensions and each axis is checked against its own.
	 */
	$overshoot = -INF;

	for ( $y = max( 0, $piece['cy'] - $half ); $y <= min( imagesy( $image ) - 1, $piece['cy'] + $half ); $y++ ) {
		for ( $x = max( 0, $piece['cx'] - $half ); $x <= min( imagesx( $image ) - 1, $piece['cx'] + $half ); $x++ ) {
			if ( 127 === ( imagecolorat( $image, $x, $y ) >> 24 & 0x7F ) ) {
				continue;
			}

			++$ink;

			$dx = $x - $piece['cx'];
			$dy = $y - $piece['cy'];

			$past = $layout['round']
				? sqrt( ( $dx * $dx ) + ( $dy * $dy ) ) - ( $piece['safe_w'] / 2 )
				: max( abs( $dx ) - ( $piece['safe_w'] / 2 ), abs( $dy ) - ( $piece['safe_h'] / 2 ) );

			$overshoot = max( $overshoot, $past );
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
		"  piece %-3d ink %-8d past the safe edge by %+8.1f px  %+6.2f mm   %s\n",
		$i,
		$ink,
		$overshoot,
		$overshoot / Mm::px_per_mm( $spec->dpi ),
		$inside ? 'inside' : 'OUTSIDE THE SAFE ZONE'
	);
}

printf( "\nPieces carrying text: %s\n", array() === $inked ? 'none' : implode( ', ', $inked ) );
printf( "%d piece(s) outside the safe zone.\n", $failures );

exit( $failures > 0 ? 1 : 0 );
