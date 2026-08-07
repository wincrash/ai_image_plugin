<?php
/**
 * The money path: WC Fields Factory prices the line, we price nothing (D-036).
 *
 * Run inside the container, against the deployed copy:
 *
 *   docker compose exec -u www-data wordpress \
 *     wp eval-file /var/lib/aicake/wcff-check.php --path=/var/www/html
 *
 * **Run as the web user, never as root** — same reason as `order-check.php`
 * (D-031). This creates a product and edits a WCFF group; nothing it writes is
 * file-backed, but the habit is worth keeping.
 *
 * The setup half is idempotent and part of the check on purpose. A gate that
 * needs a human to prepare the fixture first is a gate nobody re-runs, and the
 * Phase 3 and Phase 5 assertions were lost exactly that way.
 *
 * What this proves, and why it is the whole reason WCFF was installed:
 *
 *   - `wcff_persister.php::persist_fields()` mines `$_REQUEST` by field key, so
 *     the wizard's add-to-cart posts `wccpf_<key>=<value>` like any other field;
 *   - `wcff_negotiator.php::handle_custom_pricing()` then reads the *cart item*
 *     and calls `set_price()`.
 *
 * So the plugin writes no pricing code and couples to nothing internal. The
 * alternative — hand-building WCFF's `wccpf_*` cart-item array — also works and
 * is deliberately not used: it depends on an undocumented shape that a WCFF
 * update can change silently.
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification

use AiCake\WooCommerce\FieldsFactory;

/*
 * Explicitly in $GLOBALS: `wp eval-file` runs this inside a function, so a
 * plain assignment is a local that `global` in the helper can never see —
 * which shows up as a run where every check passes and the total is zero.
 */
$GLOBALS['aicake_fake_masters'] = array();
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

/* ---------------------------------------------------------------- fixtures */

const AICAKE_PRODUCT_SLUG = 'ai-paveikslelis';
const AICAKE_SHEET_LABEL  = 'Lakšto tipas';
const AICAKE_AI_LABEL     = 'AI paveikslėlis';

/**
 * The single AI product (D-035) — created once, reused after.
 *
 * Simple, not variable: the shop does not use variations for this, sheet type
 * is a Fields Factory field (D-036).
 */
function aicake_product(): int {
	$existing = get_page_by_path( AICAKE_PRODUCT_SLUG, OBJECT, 'product' );

	if ( $existing instanceof WP_Post ) {
		$product = wc_get_product( $existing->ID );
	} else {
		$product = new WC_Product_Simple();
		$product->set_name( 'Valgomas paveikslėlis (AI)' );
		$product->set_slug( AICAKE_PRODUCT_SLUG );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_virtual( false );
	}

	// Re-asserted every run: the base price is the one number the check's
	// arithmetic depends on, and an accidental admin edit would otherwise
	// turn every price assertion below into a confusing failure.
	$product->set_regular_price( '3.50' );
	$product->set_price( '3.50' );

	return (int) $product->save();
}

/**
 * Bind the WCFF group to that product.
 *
 * A freshly created group carries `endpoint: -1`, meaning "bound to nothing",
 * and every surcharge is then silently absent — the failure looks like WCFF
 * being broken rather than unconfigured.
 *
 * @param int $product_id Product to bind to.
 */
function aicake_bind_group( int $product_id ): void {
	$factory  = new FieldsFactory();
	$group_id = $factory->group_id();

	if ( 0 === $group_id ) {
		return;
	}

	update_post_meta(
		$group_id,
		'wccpf_condition_rules',
		wp_slash(
			wp_json_encode(
				array( array( array( 'context' => 'product', 'logic' => '==', 'endpoint' => (string) $product_id ) ) )
			)
		)
	);
}

/**
 * Add the AI surcharge field to the group, if it is not there yet.
 *
 * In production an admin adds this in the Fields Factory UI; here it is
 * scripted so the check can run from nothing. The *key* is stable only because
 * this script writes it — the plugin still resolves keys by label at runtime
 * (`FieldsFactory::field_key()`), because a hand-added field gets a random one.
 */
function aicake_ensure_ai_field(): void {
	$factory  = new FieldsFactory();
	$group_id = $factory->group_id();

	if ( 0 === $group_id || null !== $factory->field_key( AICAKE_AI_LABEL ) ) {
		return;
	}

	$key = 'wccpf_aicakeAiFee';

	update_post_meta(
		$group_id,
		$key,
		wp_slash(
			wp_json_encode(
				array(
					'key'            => $key,
					'type'           => 'radio',
					'label'          => AICAKE_AI_LABEL,
					'order'          => '1',
					'is_enable'      => true,
					'is_unremovable' => false,
					'choices'        => 'taip|Taip;ne|Ne;',
					'render_method'  => 'none',
					'layout'         => 'horizontal',
					'required'       => 'no',
					'message'        => '',
					'visibility'     => 'yes',
					'order_meta'     => 'yes',
					'email_meta'     => 'yes',
					'cart_editable'  => 'no',
					'cloneable'      => 'yes',
					'initial_show'   => 'yes',
					'pricing_rules'  => array(
						array(
							'expected_value' => 'taip',
							'amount'         => '1',
							'ptype'          => 'add',
							'tprice'         => 'cost',
							'title'          => 'AI paveikslėlio mokestis',
							'logic'          => 'equal',
						),
					),
				)
			)
		)
	);
}

/**
 * Make WCFF believe this is a fresh request.
 *
 * `split_cart_item_for_cloning()` sets its own `is_native_add_to_cart` to false
 * after the first add-to-cart, and `fields_persister()` only mines `$_REQUEST`
 * while that flag is true. In a real page load that is correct — one
 * add-to-cart per request. In this script it means only the *first* scenario
 * captures fields and every later one silently prices at base, which reads
 * exactly like WCFF being broken.
 *
 * Reached through the hook registry rather than `wcff()`'s own property names,
 * so it survives WCFF reshuffling its internals. If a future version drops the
 * flag entirely this quietly does nothing, which is why the price assertions
 * are the real gate and this is only their setup.
 */
function aicake_reset_native_flag(): void {
	global $wp_filter;

	if ( ! isset( $wp_filter['woocommerce_add_cart_item_data'] ) ) {
		return;
	}

	foreach ( $wp_filter['woocommerce_add_cart_item_data']->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$function = $callback['function'] ?? null;

			if ( ! is_array( $function ) || ! is_object( $function[0] ) || 'fields_persister' !== ( $function[1] ?? '' ) ) {
				continue;
			}

			$property = new ReflectionProperty( $function[0], 'is_native_add_to_cart' );
			$property->setAccessible( true );
			$property->setValue( $function[0], true );
		}
	}
}

/**
 * A finished design belonging to the current user.
 *
 * `$with_ai` is what the €1 turns on, and it is written the way the pipeline
 * writes it: a provider name and a master file. Both, because a master with no
 * provider is what an uploaded photo will look like when that arrives, and a
 * provider with no master is a generation that failed.
 *
 * @param bool   $with_ai Whether a provider actually generated an image.
 * @param string $format  Format type recorded on the design.
 * @param float  $mm      Format size.
 * @param int    $user_id Owner.
 */
/**
 * Write a throwaway master file and return its absolute path.
 *
 * Under the storage root so it looks like the real thing, and tracked so the
 * run cleans up after itself.
 */
function aicake_fake_master(): string {
	$dir = AiCake\Plugin::instance()->settings()->storage_dir() . '/sessions/wcff-check';

	wp_mkdir_p( $dir );

	$path = $dir . '/master-' . wp_generate_password( 8, false ) . '.png';

	file_put_contents( $path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) );

	$GLOBALS['aicake_fake_masters'][] = $path;

	return $path;
}

function aicake_design( bool $with_ai, string $format = 'circle', float $mm = 150.0, int $user_id = 1 ): string {
	$designs = AiCake\Plugin::instance()->designs();

	$id = $designs->create(
		array(
			'session_key'  => 'wcff-check',
			'ip_hash'      => 'wcff-check',
			'user_id'      => $user_id,
			'prompt_raw'   => 'wcff-check ' . ( $with_ai ? 'ai' : 'plain' ),
			'aspect'       => '1:1',
			'product_id'   => aicake_product(),
			'format_type'  => $format,
			'format_mm'    => $mm,
			'status'       => AiCake\Domain\DesignRepository::STATUS_DONE,
			'file_preview' => 'wcff-check-preview.webp',
			'provider'     => $with_ai ? 'fal' : null,
			/*
			 * A real file on disk, not just a path. The cart now asks whether
			 * the master is readable rather than whether the column is
			 * non-empty, because Fulfilment always did and the two disagreeing
			 * is how a customer gets charged for a picture that was deleted
			 * (see "a design whose files were deleted" below). A fixture whose
			 * master never existed cannot tell those apart.
			 */
			'file_master'  => $with_ai ? aicake_fake_master() : null,
		)
	);

	$row = $designs->find( $id );

	return (string) ( $row['public_id'] ?? '' );
}

/**
 * Add to cart with the given fields posted, and report the line price.
 *
 * `$_REQUEST` is what WCFF's persister mines, which is exactly the point: the
 * wizard posts these the same way from its own form.
 *
 * The design goes in the request too, because under D-035 the AI product is
 * **not sellable without one** — `CartIntegration::validate()` refuses it, the
 * same as the wizard's own step 4 requires. A zero here therefore means
 * "refused", which is what the negative scenarios below assert.
 *
 * @param int                   $product_id Product.
 * @param array<string, string> $posted     Field key => value.
 * @param string                $design     Design handle, or '' to post none.
 */
function aicake_line_price( int $product_id, array $posted, string $design = '' ): float {
	aicake_reset_native_flag();

	foreach ( array_keys( $_REQUEST ) as $key ) {
		if ( 0 === strpos( (string) $key, 'wccpf_' ) ) {
			unset( $_REQUEST[ $key ], $_POST[ $key ] );
		}
	}

	unset( $_REQUEST['aicake_design'], $_POST['aicake_design'] );

	if ( '' !== $design ) {
		$_REQUEST['aicake_design'] = $design;
		$_POST['aicake_design']    = $design;
	}

	foreach ( $posted as $key => $value ) {
		$_REQUEST[ $key ] = $value;
		$_POST[ $key ]    = $value;
	}

	WC()->cart->empty_cart();
	WC()->cart->add_to_cart( $product_id );
	WC()->cart->calculate_totals();

	foreach ( WC()->cart->get_cart() as $item ) {
		return round( (float) $item['data']->get_price(), 2 );
	}

	return 0.0;
}

/* -------------------------------------------------------------------- run */

if ( null === WC()->session ) {
	WC()->initialize_session();
}

if ( null === WC()->cart ) {
	wc_load_cart();
}

$factory    = new FieldsFactory();
$product_id = aicake_product();

aicake_bind_group( $product_id );
aicake_ensure_ai_field();

echo "\nFields Factory wiring\n";

aicake_check( 'WCFF is active', true, $factory->is_active() );
aicake_check( 'group ai_image found', true, $factory->group_id() > 0 );
aicake_check( 'group is bound to the AI product', true, in_array( $product_id, $factory->bound_product_ids(), true ) );

$sheet_key = $factory->field_key( AICAKE_SHEET_LABEL );
$ai_key    = $factory->field_key( AICAKE_AI_LABEL );

aicake_check( 'sheet-type key resolves by label', true, is_string( $sheet_key ) );
aicake_check( 'AI key resolves by label', true, is_string( $ai_key ) );

/*
 * The keys are random by design (`wccpf_qkKQtVWBjYfI` on the testbed), so this
 * asserts they were *found*, never that they equal a constant. A check that
 * hardcoded them would pass here and fail on production.
 */
aicake_check( 'sheet-type key is not the AI key', true, $sheet_key !== $ai_key );

echo "\nWhat the rules say (read-only, for the wizard's running total)\n";

aicake_check( 'krakmolo adds nothing', 0.0, $factory->surcharge( AICAKE_SHEET_LABEL, 'Krakmolo lakštas' ) );
aicake_check( 'storas krakmolo adds 1.00', 1.0, $factory->surcharge( AICAKE_SHEET_LABEL, 'Storas krakmolo lakštas' ) );
aicake_check( 'cukrinis adds 1.50', 1.5, $factory->surcharge( AICAKE_SHEET_LABEL, 'Cukrinis lakštas' ) );
aicake_check( 'AI adds 1.00', 1.0, $factory->surcharge( AICAKE_AI_LABEL, 'taip' ) );
aicake_check( 'no AI adds nothing', 0.0, $factory->surcharge( AICAKE_AI_LABEL, 'ne' ) );

echo "\nWhat WooCommerce actually charges\n";

/*
 * The AI answer now comes from the design, never from the request — so each
 * scenario names which kind of design it is adding, and the posted `$ai_key`
 * below is deliberately the *wrong* one in places.
 */
wp_set_current_user( 1 );

$ai_design    = aicake_design( true );
$plain_design = aicake_design( false );

aicake_check(
	'krakmolo, no AI',
	3.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $plain_design )
);

aicake_check(
	'storas krakmolo, no AI',
	4.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Storas krakmolo lakštas' ), $plain_design )
);

aicake_check(
	'cukrinis, no AI',
	5.00,
	aicake_line_price( $product_id, array( $sheet_key => 'Cukrinis lakštas' ), $plain_design )
);

aicake_check(
	'cukrinis + AI',
	6.00,
	aicake_line_price( $product_id, array( $sheet_key => 'Cukrinis lakštas' ), $ai_design )
);

aicake_check(
	'krakmolo + AI',
	4.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $ai_design )
);

echo "\nThe AI fee is derived, not posted\n";

/*
 * The control D-036 leaves open and this closes. The Fields Factory field is a
 * visible radio on the product page, so a customer can answer it themselves —
 * and the answer is worth €1. `CartIntegration` overwrites it from whether the
 * design really has a generated image, so both lies below are ignored.
 *
 * The wizard does not post this field at all. These do, because the attack
 * does.
 */
aicake_check(
	'a posted "ne" cannot dodge the fee',
	4.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas', $ai_key => 'ne' ), $ai_design )
);

aicake_check(
	'a posted "taip" cannot invent the fee',
	3.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas', $ai_key => 'taip' ), $plain_design )
);

/*
 * And it reaches the order the same way any WCFF field does, because it *is*
 * one — the derived value goes into the request before WCFF mines it, so the
 * cart line, the order meta and the email all agree without us writing any of
 * them.
 */
$charged = '';

foreach ( WC()->cart->get_cart() as $item ) {
	$charged = (string) ( $item[ $ai_key ]['user_val'] ?? '' );
}

aicake_check( 'the cart line records the derived answer', 'ne', $charged );

/* --------------------------------------------- a design whose files are gone */

echo "
A design whose files were deleted
";

/*
 * Retention deletes what is in `sessions/`, and on this shop Ruslan does it by
 * hand over FTP. The design row is deliberately kept — it holds the prompt and the
 * moderation verdict — so a row can outlive its files, and a logged-in
 * customer's cart persists indefinitely.
 *
 * Before this was fixed the cart read the `file_master` *column* while
 * `Fulfilment` read the *file*, so this exact case charged the AI surcharge,
 * took the money, and then could not render. There is no worse moment to find
 * out.
 */
$orphan = aicake_design( true );
$gone   = (string) ( AiCake\Plugin::instance()->designs()->find_by_public_id( $orphan )['file_master'] ?? '' );

aicake_check( 'the fixture really wrote a master', true, '' !== $gone && is_readable( $gone ) );

unlink( $gone );

aicake_check( 'and it is now gone, as after a cleanup', false, is_readable( $gone ) );

aicake_check(
	'the real add-to-cart route refuses it',
	false,
	aicake_validates( $product_id, $orphan )
);

/*
 * And through a route that never runs validation — `WC_Cart::add_to_cart()`
 * does not apply the filter — the line must at least not carry the AI fee.
 * 3.50 rather than 4.50 is the assertion: the customer is not charged for a
 * picture that no longer exists.
 */
aicake_check(
	'and no route can charge the AI fee for it',
	3.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $orphan )
);

echo "\nWhat the AI product refuses\n";

/*
 * Asserted through the filter rather than through `WC()->cart->add_to_cart()`,
 * because **`WC_Cart::add_to_cart()` never applies
 * `woocommerce_add_to_cart_validation`**. Only `WC_Form_Handler`, the AJAX
 * endpoint, the Store API and the cart-session restore do. Calling add_to_cart
 * here and asserting "the cart is empty" would therefore assert nothing at all
 * — it would pass for a plugin with no validation whatsoever.
 *
 * This is the exact call `class-wc-form-handler.php` makes on a real POST.
 *
 * It is also why the AI fee is derived on `woocommerce_add_cart_item_data`
 * instead: that one does run on every route into the cart.
 */
function aicake_validates( int $product_id, string $design = '' ): bool {
	unset( $_REQUEST['aicake_design'], $_POST['aicake_design'] );

	if ( '' !== $design ) {
		$_REQUEST['aicake_design'] = $design;
		$_POST['aicake_design']    = $design;
	}

	$passed = (bool) apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );

	wc_clear_notices();

	return $passed;
}

/*
 * Under D-035 there is one AI product and it carries no `_aicake_*` meta —
 * format lives on the design. So "does this product need a design?" cannot be
 * answered from product meta any more, and if it is, the wizard's own product
 * falls through as an ordinary sale: no design on the order, and a €3.50 line
 * that fulfilment cannot print.
 */
aicake_check( 'a real design passes validation', true, aicake_validates( $product_id, $ai_design ) );
aicake_check( 'no design at all is refused', false, aicake_validates( $product_id ) );
aicake_check( 'an unknown handle is refused', false, aicake_validates( $product_id, str_repeat( 'a', 32 ) ) );

$strangers = aicake_design( true, 'circle', 150.0, 999 );

aicake_check( "someone else's design is refused", false, aicake_validates( $product_id, $strangers ) );

echo "\nWhat the cart line says the customer bought\n";

/*
 * Format is a property of the design now (D-035), so without carrying it the
 * cart line, the confirmation email and the packing slip read "Valgomas
 * paveikslėlis (AI)" whether it is one 20 cm topper or 35 cupcake circles.
 */
/*
 * One design carrying a proof, because the thumbnail assertions below need one
 * and the line assertions do not care either way.
 */
$rich = aicake_design( true, 'cupcake', 45.0 );

$designs = AiCake\Plugin::instance()->designs();
$designs->update( (int) $designs->find_by_public_id( $rich )['id'], array( 'file_proof' => 'wcff-check-proof.webp' ) );

aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $rich );

$shown = array();
$thumb = '';

foreach ( WC()->cart->get_cart() as $key => $item ) {
	foreach ( (array) apply_filters( 'woocommerce_get_item_data', array(), $item ) as $row ) {
		// WooCommerce accepts either spelling and WCFF's own rows use `name`,
		// so reading only `key` warns on every line the shop already displays.
		$label = (string) ( $row['key'] ?? $row['name'] ?? '' );

		if ( '' !== $label ) {
			$shown[ $label ] = (string) ( $row['value'] ?? '' );
		}
	}

	$thumb = (string) apply_filters( 'woocommerce_cart_item_thumbnail', '', $item, $key );
}

aicake_check( 'the format is named on the line', true, isset( $shown['Formatas'] ) );
aicake_check( 'and it is the design\'s format, in words', true, false !== strpos( $shown['Formatas'] ?? '', '4,5' ) );
aicake_check( 'the prompt is still shown too', true, isset( $shown['Piešinys'] ) );

/*
 * And the picture on the line is the *proof* — the artwork laid out per piece
 * with the customer's own text over it (D-045) — not the bare artwork. Someone
 * who placed twelve names should see them here; the preview alone gives them no
 * way to tell whether their text survived.
 */
aicake_check( 'the cart thumbnail is the proof', true, false !== strpos( $thumb, '/' . $rich . '/proof' ) );


/*
 * The one that would have been missed: the surcharge must survive into the
 * cart item, or the customer sees 6,00 € with nothing explaining it and asks
 * why. `Užrašo mokestis` on the live site is the shape being matched.
 */
echo "\nWhat the customer sees on the line\n";

$titles = array();

foreach ( WC()->cart->get_cart() as $item ) {
	foreach ( $item as $ckey => $cval ) {
		if ( 0 === strpos( (string) $ckey, 'wccpf_' ) && isset( $cval['user_val'] ) ) {
			$titles[ (string) $ckey ] = (string) $cval['user_val'];
		}
	}
}

aicake_check( 'cart item carries both field values', 2, count( $titles ) );
aicake_check( 'cart item records the AI choice', 'taip', $titles[ $ai_key ] ?? '' );

/*
 * Last, because it leaves a non-AI design in the cart: falling back matters as
 * much as the proof itself. A design with no text has no proof, and neither
 * does any design saved before D-045 — neither may show a broken image.
 */
aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $plain_design );

$fallback = '';

foreach ( WC()->cart->get_cart() as $key => $item ) {
	$fallback = (string) apply_filters( 'woocommerce_cart_item_thumbnail', '', $item, $key );
}

aicake_check( 'and falls back to the preview with no proof', true, false !== strpos( $fallback, '/' . $plain_design . '/preview' ) );

WC()->cart->empty_cart();

/*
 * The fixtures write real PNGs now, so the run has to take them away again.
 * A check that leaves files behind is a check that slowly fills the inode
 * budget — which on this host is the binding limit, not disk space.
 */
foreach ( $GLOBALS['aicake_fake_masters'] as $fake ) {
	if ( is_readable( $fake ) ) {
		unlink( $fake );
	}
}

@rmdir( AiCake\Plugin::instance()->settings()->storage_dir() . '/sessions/wcff-check' );

aicake_check(
	'the run leaves no fixture files behind',
	0,
	count( array_filter( $GLOBALS['aicake_fake_masters'], 'is_readable' ) )
);

printf(
	"\n%d passed, %d failed\n\n",
	(int) $GLOBALS['aicake_pass'],
	(int) $GLOBALS['aicake_fail']
);
