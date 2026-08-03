<?php
/**
 * Wizard step 1: what it offers, what it quotes, and what it refuses.
 *
 * Run inside the container, against the deployed copy:
 *
 *   docker compose exec -u www-data wordpress \
 *     wp eval-file /var/lib/aicake/wizard-check.php --path=/var/www/html
 *
 * Creates the page carrying the shortcode if it is missing, so the wizard is
 * reachable after a fresh testbed rather than requiring someone to remember.
 *
 * The assertions worth having here are the ones about **disagreement**: the
 * price the wizard shows against the price WooCommerce charges, and the format
 * the browser asks for against the format the catalogue allows. Both are places
 * where two sources of truth could drift apart quietly.
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions

use AiCake\Domain\FormatCatalogue;
use AiCake\Frontend\Wizard;
use AiCake\WooCommerce\FieldsFactory;

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
		printf( "  ok    %-54s %s\n", $label, is_scalar( $actual ) ? (string) $actual : gettype( $actual ) );
		++$aicake_pass;

		return;
	}

	printf( "  FAIL  %-54s expected %s, got %s\n", $label, var_export( $expect, true ), var_export( $actual, true ) );
	++$aicake_fail;
}

$plugin = AiCake\Plugin::instance();
$fields = new FieldsFactory();
$wizard = new Wizard( $plugin->settings(), $fields );

/* ------------------------------------------------------------------ page */

$page = get_page_by_path( 'ai-paveikslelis-vedlys' );

if ( ! $page instanceof WP_Post ) {
	$page_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Susikurkite savo paveikslėlį',
			'post_name'    => 'ai-paveikslelis-vedlys',
			'post_content' => '[' . Wizard::SHORTCODE . ']',
		)
	);

	$page = get_post( (int) $page_id );
}

printf( "\nWizard page: %s\n", get_permalink( $page ) );

echo "\nWhat step 1 offers\n";

$product = $wizard->product();

aicake_check( 'the AI product resolves', true, $product instanceof WC_Product );
aicake_check( 'and it is simple, not variable', 'simple', $product->get_type() );

$formats = $wizard->formats();

aicake_check( 'one whole-sheet format', 1, count( $formats[ FormatCatalogue::TYPE_SHEET ] ) );
aicake_check( 'eleven circle sizes', 11, count( $formats[ FormatCatalogue::TYPE_CIRCLE ] ) );
aicake_check( 'four cupcake sizes', 4, count( $formats[ FormatCatalogue::TYPE_CUPCAKE ] ) );

/*
 * The count has to reach the browser, because "as many as fit" is invisible
 * otherwise (D-039) — someone buying a 10 cm circle receives four.
 */
$ten = null;

foreach ( $formats[ FormatCatalogue::TYPE_CIRCLE ] as $option ) {
	if ( abs( $option['mm'] - 100.0 ) < 0.05 ) {
		$ten = $option;
	}
}

aicake_check( '10 cm circle is offered', true, is_array( $ten ) );
aicake_check( 'and says it yields four', 4, (int) $ten['perSheet'] );

echo "\nSheet types, read from Fields Factory rather than duplicated\n";

$sheets = $wizard->sheet_types();

aicake_check( 'three sheet types', 3, count( $sheets ) );

$by_value = array();

foreach ( $sheets as $sheet ) {
	$by_value[ $sheet['value'] ] = $sheet['surcharge'];
}

aicake_check( 'krakmolo adds nothing', 0.0, $by_value['Krakmolo lakštas'] ?? -1.0 );
aicake_check( 'storas krakmolo adds 1.00', 1.0, $by_value['Storas krakmolo lakštas'] ?? -1.0 );
aicake_check( 'cukrinis adds 1.50', 1.5, $by_value['Cukrinis lakštas'] ?? -1.0 );

echo "\nThe quoted price against the charged price\n";

$prices = $wizard->prices( $product );

aicake_check( 'a price for every sheet type and AI state', 6, count( $prices ) );

/*
 * The one that matters. `wcff-check.php` proves WooCommerce charges these
 * figures; this proves the wizard quotes the same ones. Two sources of truth
 * for a price is exactly how a shop ends up showing 5,00 € and taking 6,00 €.
 */
$expected = array(
	'Krakmolo lakštas|ne'         => 3.50,
	'Krakmolo lakštas|taip'       => 4.50,
	'Storas krakmolo lakštas|ne'  => 4.50,
	'Cukrinis lakštas|ne'         => 5.00,
	'Cukrinis lakštas|taip'       => 6.00,
);

foreach ( $expected as $key => $amount ) {
	aicake_check( sprintf( 'quote: %s', $key ), $amount, (float) ( $prices[ $key ]['amount'] ?? 0.0 ) );
}

echo "\nWhat the shortcode renders\n";

$html = do_shortcode( '[' . Wizard::SHORTCODE . ']' );

aicake_check( 'the wizard renders', true, false !== strpos( $html, 'aicake-wizard' ) );
aicake_check( 'step 1 is present', true, false !== strpos( $html, 'data-step="1"' ) );
// The exact attribute, not the bare class name — `aicake-format-card` also
// prefixes the title and note elements, so a loose count reads 9 for 3 cards.
aicake_check( 'all three format cards', 3, substr_count( $html, 'class="aicake-format-card"' ) );

/*
 * D-025: this markup is cacheable, so it must never carry a nonce for an
 * anonymous visitor. A cached nonce 403s every generation, and it took two
 * phases to find last time.
 */
wp_set_current_user( 0 );

aicake_check( 'no nonce in cacheable markup', true, false === strpos( do_shortcode( '[' . Wizard::SHORTCODE . ']' ), 'wp_rest' ) );

echo "\nWhat the server refuses\n";

/*
 * The browser can post anything. These are the two that cost money if they get
 * through: a size that is not for sale, and an aspect that disagrees with the
 * shape being generated (§3.2).
 */
aicake_check( 'an unlisted size is refused', null, FormatCatalogue::find( FormatCatalogue::TYPE_CIRCLE, 175.0 ) );
aicake_check( 'a round format generates 1:1', '1:1', FormatCatalogue::spec( FormatCatalogue::TYPE_CIRCLE, 150.0 )->generation_aspect() );
aicake_check( 'a whole sheet generates 2:3', '2:3', FormatCatalogue::spec( FormatCatalogue::TYPE_SHEET )->generation_aspect() );

printf(
	"\n%d passed, %d failed\n\n",
	(int) $GLOBALS['aicake_pass'],
	(int) $GLOBALS['aicake_fail']
);
