<?php
/**
 * Render every offered format as a printable A4 proof, and check it is one.
 *
 * Run inside the container, against the deployed copy:
 *
 *   docker compose exec -u www-data wordpress \
 *     wp eval-file /var/lib/aicake/proof-check.php --path=/var/www/html
 *
 * **Run as the web user, never as root** (D-031) — this one genuinely writes
 * files, into `proofs/` under the storage root, so a root-owned directory here
 * breaks the next run for the web user.
 *
 * It writes as well as asserts on purpose. Ruslan validates the geometry by
 * printing every format and measuring it (D-039), and sixteen clicks through
 * the admin screen is a worse way to start that than one folder on the share.
 * The admin screen's per-format download is the same renderer.
 *
 * What is actually being checked is D-027's failure mode, which cost a whole
 * print run once already: a file that is correct and *prints at the wrong
 * physical size*. A proof sheet that lies about its own resolution would be
 * measured, believed, and used to sign off geometry that is wrong.
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions

use AiCake\Domain\FormatCatalogue;
use AiCake\Imaging\ProofSheet;
use AiCake\Imaging\SheetLayout;
use AiCake\Support\Mm;

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
		printf( "  ok    %-52s %s\n", $label, is_scalar( $actual ) ? (string) $actual : gettype( $actual ) );
		++$aicake_pass;

		return;
	}

	printf( "  FAIL  %-52s expected %s, got %s\n", $label, var_export( $expect, true ), var_export( $actual, true ) );
	++$aicake_fail;
}

$plugin = AiCake\Plugin::instance();
$proofs = new ProofSheet( $plugin->images(), $plugin->fonts() );

$directory = rtrim( $plugin->settings()->storage_dir(), '/\\' ) . '/proofs';

if ( ! is_dir( $directory ) ) {
	wp_mkdir_p( $directory );
}

$usable_w = SheetLayout::USABLE_WIDTH_MM;
$usable_h = SheetLayout::USABLE_HEIGHT_MM;

/*
 * Full A4, not the usable area. A proof cropped to the usable area would be
 * placed by the print dialog rather than by us, which is the one thing these
 * sheets exist to take out of the equation.
 */
$paper_w_px = Mm::to_px( ProofSheet::PAPER_W_MM );
$paper_h_px = Mm::to_px( ProofSheet::PAPER_H_MM );

$options = FormatCatalogue::offerable( $usable_w, $usable_h );

printf( "\nRendering %d proofs into %s\n\n", count( $options ), $directory );

$written = 0;

foreach ( $options as $option ) {
	$png = $proofs->render( $option, $usable_w, $usable_h );

	if ( null === $png ) {
		aicake_check( sprintf( '%s renders', $option['label'] ), true, false );

		continue;
	}

	$path = $directory . '/' . $proofs->filename( $option );

	file_put_contents( $path, $png );
	++$written;

	$size = getimagesizefromstring( $png );

	aicake_check(
		sprintf( '%s is A4 at 300 DPI', $option['label'] ),
		array( $paper_w_px, $paper_h_px, Mm::PRINT_DPI ),
		array( $size[0], $size[1], $plugin->images()->read_dpi( $png ) )
	);
}

echo "\nSanity on the rendering itself\n";

/*
 * A blank sheet would pass every assertion above. This is the cheapest check
 * that the circles were actually drawn: the 24-up sheet must be visibly busier
 * than the single 20 cm one.
 */
$twentyfour = filesize( $directory . '/aicake-proof-cupcake-45mm-x24.png' );
$single     = filesize( $directory . '/aicake-proof-circle-200mm-x1.png' );

aicake_check( '24-up carries more ink than one circle', true, $twentyfour > $single );
aicake_check( 'every offered format was written', count( $options ), $written );

printf(
	"\n%d passed, %d failed\n\n",
	(int) $GLOBALS['aicake_pass'],
	(int) $GLOBALS['aicake_fail']
);
