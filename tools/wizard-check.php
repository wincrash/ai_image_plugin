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
$wizard = new Wizard( $plugin->settings(), $fields, $plugin->logger() );

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

/*
 * One flat list now, not three buckets (D-055). The counts below are the same
 * sixteen formats §3.5 has always specified — what changed is that the wizard
 * asks about them once instead of asking for a type and then a size.
 */
$formats = $wizard->formats();

$by_type = array();

foreach ( $formats as $option ) {
	$by_type[ $option['type'] ][] = $option;
}

aicake_check( 'sixteen formats in one list', 16, count( $formats ) );
aicake_check( 'one whole-sheet format', 1, count( $by_type[ FormatCatalogue::TYPE_SHEET ] ) );
aicake_check( 'eleven circle sizes', 11, count( $by_type[ FormatCatalogue::TYPE_CIRCLE ] ) );
aicake_check( 'four cupcake sizes', 4, count( $by_type[ FormatCatalogue::TYPE_CUPCAKE ] ) );

/*
 * Largest first, so the one list reads as a single scale rather than three
 * lists concatenated. The whole sheet leads because it is the whole sheet.
 */
aicake_check( 'the whole sheet comes first', FormatCatalogue::TYPE_SHEET, $formats[0]['type'] );
aicake_check( 'the largest circle comes second', 200.0, $formats[1]['mm'] );
aicake_check( 'the smallest cupcake comes last', 40.0, $formats[ count( $formats ) - 1 ]['mm'] );

/*
 * Every card has to be drawable, or the grid renders as empty rectangles that
 * read like a diagram which failed to load. cols x rows is what the browser
 * lays out, so it must agree with the count the label promises.
 */
$undrawable = 0;

foreach ( $formats as $option ) {
	if ( $option['cols'] * $option['rows'] !== $option['perSheet'] || $option['sheetW'] <= 0 ) {
		++$undrawable;
	}
}

aicake_check( 'every format carries geometry the browser can draw', 0, $undrawable );

/*
 * The type is derived from the diameter now, never asked for (D-055). That
 * only works while the two size lists stay clear of the boundary — a size
 * added into the gap would be mislabelled silently, one format at a time.
 */
$misderived = 0;

foreach ( $formats as $option ) {
	if ( FormatCatalogue::type_for_diameter( (float) $option['mm'] ) !== $option['type'] ) {
		++$misderived;
	}
}

aicake_check( 'the type is derivable from the diameter for every format', 0, $misderived );

/*
 * The count has to reach the browser, because "as many as fit" is invisible
 * otherwise (D-039) — someone buying a 10 cm circle receives four.
 */
$ten = null;

foreach ( $by_type[ FormatCatalogue::TYPE_CIRCLE ] as $option ) {
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
/*
 * The cards are no longer in the markup (D-055). They are drawn by the browser
 * from `SheetLayout`'s plan, because each one is a picture of the real sheet
 * and rendering it twice — once here, once in the editor — is how a diagram
 * and a print drift apart.
 *
 * So what is asserted here is the container, and the *absence* of the old two
 * question flow. That second half is the one with teeth: bringing back the
 * type radios and the size select would leave "the wizard renders" green and
 * quietly restore the thing Ruslan asked to remove.
 */
aicake_check(
	'step 1 has one format grid for the browser to fill',
	1,
	substr_count( $html, 'data-role="formats"' )
);
aicake_check(
	'and no separate type question',
	false,
	strpos( $html, 'aicake_format_type' )
);
aicake_check(
	'and no separate size question',
	false,
	strpos( $html, 'id="aicake-size"' )
);

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

/*
 * And now through the real endpoint, which is where it actually matters.
 *
 * A **blocked** prompt is used deliberately: layer 1 refuses it before anything
 * is queued, so this costs nothing, and §10 still requires the rejection to be
 * written to the designs table with its prompt — which means the row is there
 * to inspect. A test that had to spend $0.012 to check a field would be run
 * once and then quietly dropped.
 */
wp_set_current_user( 1 );

/*
 * Lift the per-visitor throttle for this one request, then put it back.
 *
 * Not to dodge a real limit — the limiter has its own assertions elsewhere —
 * but because this scenario is about *moderation*, and the limiter is checked
 * first. A day of browser testing as the same user exhausts the 20 and this
 * request never reaches layer 1, which used to read as a pass. The gate has to
 * re-run from nothing on any day (D-031's principle), and that includes a busy
 * one.
 */
$settings  = $plugin->settings();
$throttled = array(
	'free_per_user'        => $settings->get( 'free_per_user' ),
	'free_per_session'     => $settings->get( 'free_per_session' ),
	'ip_daily_ceiling'     => $settings->get( 'ip_daily_ceiling' ),
	'min_interval_seconds' => $settings->get( 'min_interval_seconds' ),
);

$settings->update(
	array(
		'free_per_user'        => 100000,
		'free_per_session'     => 100000,
		'ip_daily_ceiling'     => 100000,
		'min_interval_seconds' => 0,
	)
);

$request = new WP_REST_Request( 'POST', '/aicake/v1/generate' );
$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body_params(
	array(
		'prompt'      => 'Elsos suknelė',
		// A client insisting on the wrong aspect for the chosen format. This
		// is the whole point: they are not independent (§3.2), and a wrongly
		// cropped generation costs money and looks like a bad model.
		'aspect'      => '1:1',
		'format_type' => FormatCatalogue::TYPE_SHEET,
		'format_mm'   => 0,
		'product_id'  => $product->get_id(),
	)
);

$response = rest_do_request( $request );

// Restored immediately, before any assertion can fail and skip it.
$settings->update( $throttled );

/*
 * The *specific* code, not merely "an error".
 *
 * `is_error()` was true for any refusal, including the 429 the throttle
 * returns once a day of testing has used the allowance — so a throttled run
 * passed this assertion, wrote no design row, and the three below then read
 * whatever row happened to be newest and failed for a reason that had nothing
 * to do with them. An assertion that passes when the thing under test never
 * ran is worse than no assertion.
 */
$code = $response->is_error() ? $response->as_error()->get_error_code() : '';

aicake_check( 'a blocked prompt is refused by moderation', 'aicake_rejected', $code );

global $wpdb;

/*
 * Found by its prompt rather than by "newest row". Other checks — and this
 * file's own later scenarios — write designs too, and "the last one inserted"
 * silently becomes someone else's row.
 */
$row = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT format_type, format_mm, aspect, status FROM {$wpdb->prefix}aicake_designs
		 WHERE prompt_raw = %s ORDER BY id DESC LIMIT 1",
		'Elsos suknelė'
	)
);

aicake_check( 'the rejection was logged', 'rejected', $row->status ?? '' );
aicake_check( 'with the format recorded', 'sheet', $row->format_type ?? '' );
aicake_check( 'and the aspect derived, not the posted one', '2:3', $row->aspect ?? '' );

echo "\nThe design tells the editor which canvas to draw\n";

/*
 * „Užrašo dydis netinka." came from these two parting company: the editor
 * chose its layout from the step-1 selection while the server measured the
 * saved bitmap against the design row. Change the format after generating and
 * every save failed.
 *
 * So the finished-job response now names the layout, and the browser looks that
 * up instead of deriving one. Checked through the real endpoint, because the
 * field being *emitted* is the new part — the geometry itself is covered by
 * `EditorLayoutTest` for all sixteen formats.
 */
$layouts = $wizard->layouts();
$key     = FormatCatalogue::layout_key( FormatCatalogue::TYPE_CUPCAKE, 45.0 );

aicake_check( 'the wizard ships a layout under that key', true, isset( $layouts[ $key ] ) );

$designs   = $plugin->designs();
$jobs      = $plugin->jobs();
$design_id = $designs->create(
	array(
		'session_key'  => 'wizard-check',
		'user_id'      => 1,
		'prompt_raw'   => 'wizard-check layout key',
		'aspect'       => '1:1',
		'product_id'   => $product->get_id(),
		'format_type'  => FormatCatalogue::TYPE_CUPCAKE,
		'format_mm'    => 45.0,
		'status'       => AiCake\Domain\DesignRepository::STATUS_DONE,
		// Only its emptiness is read — `body()` builds the URL from public_id.
		'file_preview' => 'wizard-check.webp',
	)
);

$job_id = $jobs->create( $design_id );
$jobs->mark_done( $job_id );

$request  = new WP_REST_Request( 'GET', '/aicake/v1/job/' . $job_id );
$response = rest_do_request( $request );
$body     = $response->get_data();

aicake_check( 'a finished job reports done', 'done', $body['status'] ?? '' );
aicake_check( 'and names the layout the design was made for', $key, $body['layout_key'] ?? '' );

/*
 * The pairing that matters: the layout that key selects has the canvas the
 * text-layer endpoint will measure the saved bitmap against. Asserted against
 * `PrintSpec` rather than a typed pair of numbers, so a geometry change moves
 * both or turns this red.
 */
$stored = $designs->find( $design_id );
$canvas = AiCake\Domain\PrintSpec::for_design( $stored )->canvas_px();

aicake_check( 'the editor canvas is the print canvas (w)', $canvas[0], $layouts[ $key ]['canvas']['w'] ?? 0 );
aicake_check( 'the editor canvas is the print canvas (h)', $canvas[1], $layouts[ $key ]['canvas']['h'] ?? 0 );

/*
 * And a design with no format omits the key rather than inventing one. The
 * product-page generator sends no format and has no editor; a key guessed from
 * the product would point the editor at a canvas nobody chose.
 */
$plain_id = $designs->create(
	array(
		'session_key'  => 'wizard-check',
		'user_id'      => 1,
		'prompt_raw'   => 'wizard-check no format',
		'aspect'       => '1:1',
		'product_id'   => $product->get_id(),
		'status'       => AiCake\Domain\DesignRepository::STATUS_DONE,
		'file_preview' => 'wizard-check.webp',
	)
);

$plain_job = $jobs->create( $plain_id );
$jobs->mark_done( $plain_job );

$plain_body = rest_do_request( new WP_REST_Request( 'GET', '/aicake/v1/job/' . $plain_job ) )->get_data();

aicake_check( 'an unformatted design reports done', 'done', $plain_body['status'] ?? '' );
aicake_check( 'and names no layout at all', false, array_key_exists( 'layout_key', $plain_body ) );

/* ------------------------------------------------ where the wizard lives */

/*
 * D-048. Ruslan hit all three of these in one test: adding to cart landed him
 * on a bare product page, that page offered a second „AI paveikslėlis" radio,
 * and clicking his own cart line opened the same "empty product".
 */

echo "\nentry points (D-048)\n";

$wizard_url = AiCake\Frontend\Wizard::page_url();

aicake_check( 'the wizard page is findable by its shortcode', true, '' !== $wizard_url );

$wiz = new AiCake\Frontend\Wizard(
	AiCake\Plugin::instance()->settings(),
	new AiCake\WooCommerce\FieldsFactory(),
	AiCake\Plugin::instance()->logger()
);

aicake_check(
	'an AI cart line links to the wizard',
	$wizard_url,
	$wiz->cart_item_permalink(
		'http://example.com/product/x',
		array( AiCake\WooCommerce\CartIntegration::CART_KEY => array( 'public_id' => 'abc' ) ),
		'k'
	)
);

/*
 * And nothing else is touched. A filter that rewrote every cart line would
 * send a customer buying sprinkles to the picture wizard.
 */
aicake_check(
	'an ordinary cart line is left alone',
	'http://example.com/product/x',
	$wiz->cart_item_permalink( 'http://example.com/product/x', array(), 'k' )
);

// The step-4 form posts to the cart, not to the product permalink.
$wizard_html = $wiz->render();

aicake_check(
	'the add-to-cart form posts to the cart',
	true,
	str_contains( $wizard_html, 'action="' . esc_url( wc_get_cart_url() ) . '"' )
);

printf(
	"\n%d passed, %d failed\n\n",
	(int) $GLOBALS['aicake_pass'],
	(int) $GLOBALS['aicake_fail']
);
