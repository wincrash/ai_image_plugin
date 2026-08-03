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
 * Add to cart with the given fields posted, and report the line price.
 *
 * `$_REQUEST` is what WCFF's persister mines, which is exactly the point: the
 * wizard will post these the same way, from its own form.
 *
 * @param int                   $product_id Product.
 * @param array<string, string> $posted     Field key => value.
 */
function aicake_line_price( int $product_id, array $posted ): float {
	aicake_reset_native_flag();

	foreach ( array_keys( $_REQUEST ) as $key ) {
		if ( 0 === strpos( (string) $key, 'wccpf_' ) ) {
			unset( $_REQUEST[ $key ], $_POST[ $key ] );
		}
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

aicake_check(
	'krakmolo, no AI',
	3.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas', $ai_key => 'ne' ) )
);

aicake_check(
	'storas krakmolo, no AI',
	4.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Storas krakmolo lakštas', $ai_key => 'ne' ) )
);

aicake_check(
	'cukrinis, no AI',
	5.00,
	aicake_line_price( $product_id, array( $sheet_key => 'Cukrinis lakštas', $ai_key => 'ne' ) )
);

aicake_check(
	'cukrinis + AI',
	6.00,
	aicake_line_price( $product_id, array( $sheet_key => 'Cukrinis lakštas', $ai_key => 'taip' ) )
);

aicake_check(
	'krakmolo + AI',
	4.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas', $ai_key => 'taip' ) )
);

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

WC()->cart->empty_cart();

printf(
	"\n%d passed, %d failed\n\n",
	(int) $GLOBALS['aicake_pass'],
	(int) $GLOBALS['aicake_fail']
);
