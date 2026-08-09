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
/*
 * Read from settings rather than typed, because the plugin resolves this field
 * by whatever the shop called it (D-071). A fixture with its own spelling would
 * build a field the plugin never looks for.
 */
define( 'AICAKE_SOURCE_LABEL', AiCake\Domain\SourceCatalogue::field_label( AiCake\Plugin::instance()->settings() ) );

/**
 * What this fixture charges for each source, keyed by source (D-071).
 *
 * **Four different numbers on purpose, and none of them the shop's.** Ruslan
 * sets the real prices in the Fields Factory UI; what this check has to prove is
 * that the four are told apart, and a fixture where two sources cost the same
 * cannot tell a correct derivation from one that confuses them. `none` has no
 * rule at all rather than a rule adding zero, because that is how a shop that
 * does not surcharge text would really leave it.
 */
const AICAKE_SOURCE_FEES = array(
	'none'   => 0.0,
	'upload' => 0.5,
	'ai'     => 1.0,
	'search' => 0.75,
);

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
 * Add the „Paveikslėlio tipas" field to the group, if it is not there yet.
 *
 * In production an admin adds this in the Fields Factory UI; here it is
 * scripted so the check can run from nothing. The *key* is stable only because
 * this script writes it — the plugin still resolves keys by label at runtime
 * (`FieldsFactory::field_key()`), because a hand-added field gets a random one.
 *
 * The choices are the plugin's own settings values, read rather than retyped.
 * That is the seam D-071 is most likely to break on: WCFF matches its price
 * rule against the posted string, so a fixture with its own spelling would
 * assert that this file agrees with itself.
 */
function aicake_ensure_source_field(): void {
	$factory  = new FieldsFactory();
	$group_id = $factory->group_id();

	if ( 0 === $group_id || null !== $factory->field_key( AICAKE_SOURCE_LABEL ) ) {
		return;
	}

	$settings = AiCake\Plugin::instance()->settings();
	$key      = 'wccpf_aicakeSourceFee';
	$choices  = array();
	$rules    = array();

	foreach ( AiCake\Domain\SourceCatalogue::all() as $source ) {
		$value     = AiCake\Domain\SourceCatalogue::field_value( $source, $settings );
		$choices[] = $value;

		if ( 0.0 === AICAKE_SOURCE_FEES[ $source ] ) {
			continue;
		}

		$rules[] = array(
			'expected_value' => $value,
			'amount'         => (string) AICAKE_SOURCE_FEES[ $source ],
			'ptype'          => 'add',
			'tprice'         => 'cost',
			'title'          => $value,
			'logic'          => 'equal',
		);
	}

	update_post_meta(
		$group_id,
		$key,
		wp_slash(
			wp_json_encode(
				array(
					'key'            => $key,
					'type'           => 'radio',
					'label'          => AICAKE_SOURCE_LABEL,
					'order'          => '1',
					'is_enable'      => true,
					'is_unremovable' => false,
					/*
					 * Value and label the same, exactly as the shop writes its
					 * sheet types. A radio stores the posted *value* verbatim as
					 * `user_val` and that is what the order and the e-mail show,
					 * so a `value|label` pair here would put „upload" in front of
					 * a customer.
					 */
					'choices'        => implode( ';', $choices ) . ';',
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
					'pricing_rules'  => $rules,
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
 * Written the way the pipeline writes each source (D-071): a picture on disk
 * for all three that have one, and a provider name only for `ai` — a master
 * with no provider is what an uploaded or searched photograph looks like, and a
 * provider with no master is a generation that failed.
 *
 * @param string $source  One of the four sources.
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

function aicake_design( string $source = 'ai', string $format = 'circle', float $mm = 150.0, int $user_id = 1 ): string {
	$has_image = AiCake\Domain\SourceCatalogue::NONE !== $source;

	$designs = AiCake\Plugin::instance()->designs();

	$id = $designs->create(
		array(
			'session_key'  => 'wcff-check',
			'ip_hash'      => 'wcff-check',
			'user_id'      => $user_id,
			'prompt_raw'   => 'wcff-check ' . $source,
			'aspect'       => '1:1',
			'product_id'   => aicake_product(),
			'format_type'  => $format,
			'format_mm'    => $mm,
			'status'       => AiCake\Domain\DesignRepository::STATUS_DONE,
			'file_preview' => 'wcff-check-preview.webp',
			'source'       => $source,
			'provider'     => AiCake\Domain\SourceCatalogue::AI === $source ? 'fal' : null,
			/*
			 * A real file on disk, not just a path. The cart now asks whether
			 * the master is readable rather than whether the column is
			 * non-empty, because Fulfilment always did and the two disagreeing
			 * is how a customer gets charged for a picture that was deleted
			 * (see "a design whose files were deleted" below). A fixture whose
			 * master never existed cannot tell those apart.
			 */
			'file_master'  => $has_image ? aicake_fake_master() : null,
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
aicake_ensure_source_field();

echo "\nFields Factory wiring\n";

aicake_check( 'WCFF is active', true, $factory->is_active() );
aicake_check( 'group ai_image found', true, $factory->group_id() > 0 );
aicake_check( 'group is bound to the AI product', true, in_array( $product_id, $factory->bound_product_ids(), true ) );

$sheet_key  = $factory->field_key( AICAKE_SHEET_LABEL );
$source_key = $factory->field_key( AICAKE_SOURCE_LABEL );
$settings   = AiCake\Plugin::instance()->settings();

aicake_check( 'sheet-type key resolves by label', true, is_string( $sheet_key ) );
aicake_check( 'picture-type key resolves by label', true, is_string( $source_key ) );

/*
 * The keys are random by design (`wccpf_qkKQtVWBjYfI` on the testbed), so this
 * asserts they were *found*, never that they equal a constant. A check that
 * hardcoded them would pass here and fail on production.
 */
aicake_check( 'sheet-type key is not the picture-type key', true, $sheet_key !== $source_key );

/*
 * And every source the plugin can post is an answer the field really offers.
 * This is the silent failure D-071 is built around: a value one letter away
 * from the choice matches no rule, charges base, and writes nothing on the
 * order. Nothing throws, so only an assertion finds it.
 */
foreach ( AiCake\Domain\SourceCatalogue::all() as $aicake_source ) {
	aicake_check(
		'the field offers „' . AiCake\Domain\SourceCatalogue::field_value( $aicake_source, $settings ) . '"',
		true,
		$factory->has_choice( AICAKE_SOURCE_LABEL, AiCake\Domain\SourceCatalogue::field_value( $aicake_source, $settings ) )
	);
}

echo "\nWhat the rules say (read-only, for the wizard's running total)\n";

aicake_check( 'krakmolo adds nothing', 0.0, $factory->surcharge( AICAKE_SHEET_LABEL, 'Krakmolo lakštas' ) );
aicake_check( 'storas krakmolo adds 1.00', 1.0, $factory->surcharge( AICAKE_SHEET_LABEL, 'Storas krakmolo lakštas' ) );
aicake_check( 'cukrinis adds 1.50', 1.5, $factory->surcharge( AICAKE_SHEET_LABEL, 'Cukrinis lakštas' ) );
foreach ( AICAKE_SOURCE_FEES as $aicake_source => $aicake_fee ) {
	aicake_check(
		'source „' . $aicake_source . '" adds ' . number_format( $aicake_fee, 2 ),
		$aicake_fee,
		$factory->surcharge( AICAKE_SOURCE_LABEL, AiCake\Domain\SourceCatalogue::field_value( $aicake_source, $settings ) )
	);
}

/*
 * A value the field does not offer adds nothing — which is exactly why
 * `surcharge()` cannot be the mismatch detector and `has_choice()` exists.
 */
aicake_check( 'an unknown answer adds nothing', 0.0, $factory->surcharge( AICAKE_SOURCE_LABEL, 'Sukurta su AI ' . uniqid() ) );

echo "\nWhat WooCommerce actually charges\n";

/*
 * The picture type now comes from the design, never from the request — so each
 * scenario names which kind of design it is adding, and the posted `$source_key`
 * below is deliberately the *wrong* one in places.
 */
wp_set_current_user( 1 );

$handles = array();

foreach ( AiCake\Domain\SourceCatalogue::all() as $aicake_source ) {
	$handles[ $aicake_source ] = aicake_design( $aicake_source );
}

/*
 * One line per source, all on the cheapest sheet, so the only thing moving
 * between them is the picture type. 3,50 base + the fixture's fee.
 */
foreach ( AICAKE_SOURCE_FEES as $aicake_source => $aicake_fee ) {
	aicake_check(
		'krakmolo + ' . $aicake_source,
		round( 3.50 + $aicake_fee, 2 ),
		aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $handles[ $aicake_source ] )
	);
}

// And the sheet type still stacks on top of it, which is the whole pricing
// surface: base + sheet + source (D-037, D-071).
aicake_check(
	'cukrinis + ai',
	6.00,
	aicake_line_price( $product_id, array( $sheet_key => 'Cukrinis lakštas' ), $handles['ai'] )
);

aicake_check(
	'storas krakmolo + upload',
	5.00,
	aicake_line_price( $product_id, array( $sheet_key => 'Storas krakmolo lakštas' ), $handles['upload'] )
);

echo "
The picture type is derived, not posted
";

/*
 * The control D-036 leaves open and this closes. The Fields Factory field is a
 * visible radio on the product page, so a customer can answer it themselves —
 * and since D-071 the answer is worth a different amount for each of four
 * choices, so it is worth answering dishonestly in both directions.
 * `CartIntegration` overwrites it from what the design row and the disk say, so
 * every lie below is ignored.
 *
 * The wizard does not post this field at all. These do, because the attack does.
 */
$cheapest  = AiCake\Domain\SourceCatalogue::field_value( 'none', $settings );
$dearest   = AiCake\Domain\SourceCatalogue::field_value( 'ai', $settings );

aicake_check(
	'claiming the cheapest type cannot dodge the AI fee',
	4.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas', $source_key => $cheapest ), $handles['ai'] )
);

aicake_check(
	'claiming AI cannot invent the AI fee',
	3.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas', $source_key => $dearest ), $handles['none'] )
);

/*
 * And a lie *between* two paid sources is overwritten too. Worth its own line:
 * the yes/no field only had a cheap answer and a dear one, so "the fee is
 * derived" used to be one bit. It is now a choice of four, and an upload priced
 * as a search would have been invisible to every assertion above.
 */
aicake_check(
	'and one paid type cannot be passed off as another',
	4.00,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas', $source_key => $dearest ), $handles['upload'] )
);

/*
 * And it reaches the order the same way any WCFF field does, because it *is*
 * one — the derived value goes into the request before WCFF mines it, so the
 * cart line, the order meta and the email all agree without us writing any of
 * them. It is also the string the customer reads, so it is asserted as the
 * Lithuanian phrase rather than as `upload`.
 */
$charged = '';

foreach ( WC()->cart->get_cart() as $item ) {
	$charged = (string) ( $item[ $source_key ]['user_val'] ?? '' );
}

aicake_check(
	'the cart line records the derived answer, in Lithuanian',
	AiCake\Domain\SourceCatalogue::field_value( 'upload', $settings ),
	$charged
);

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
$orphan = aicake_design( 'ai' );
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
 * picture that no longer exists, and „Tik užrašas" is what a design with no
 * picture honestly is (D-071).
 */
aicake_check(
	'and no route can charge the AI fee for it',
	3.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $orphan )
);

/* -------------------------------- D-058: the source has the first word */

/*
 * A design that says it came from somewhere other than AI must be priced as what
 * it says it is, **even when the rest of the row looks exactly like a
 * generation** — a provider name and a master on disk.
 *
 * Under D-058 this asserted "not charged for AI", which a plugin that had simply
 * stopped charging would also pass. With a price per source it asserts something
 * stronger and cheaper to get wrong: the row is charged the *upload* price, so
 * the source is being read rather than merely not-AI.
 */
$mislabelled = aicake_design( 'ai' );

AiCake\Plugin::instance()->designs()->update(
	(int) AiCake\Plugin::instance()->designs()->find_by_public_id( $mislabelled )['id'],
	array( 'source' => AiCake\Domain\SourceCatalogue::UPLOAD )
);

aicake_check(
	'a relabelled source is priced as what it says, whatever else the row says',
	4.00,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $mislabelled )
);

/*
 * And a source claiming a picture it does not have falls back to the cheapest
 * answer rather than being charged for one. The row keeps `source = upload`;
 * only the file goes. This is `has_image()`, and without it a design row written
 * before its upload finished would be sold as an uploaded photograph.
 */
$vanished = (string) ( AiCake\Plugin::instance()->designs()->find_by_public_id( $mislabelled )['file_master'] ?? '' );

AiCake\Plugin::instance()->designs()->update(
	(int) AiCake\Plugin::instance()->designs()->find_by_public_id( $mislabelled )['id'],
	array( 'file_master' => null )
);

aicake_check(
	'and a source with no picture behind it is charged as text only',
	3.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $mislabelled )
);

AiCake\Plugin::instance()->designs()->update(
	(int) AiCake\Plugin::instance()->designs()->find_by_public_id( $mislabelled )['id'],
	array( 'file_master' => $vanished )
);

/*
 * And the same row with `source = ai` restored pays the fee — otherwise the
 * assertion above would also pass against a plugin that had simply stopped
 * charging for AI altogether.
 */
AiCake\Plugin::instance()->designs()->update(
	(int) AiCake\Plugin::instance()->designs()->find_by_public_id( $mislabelled )['id'],
	array( 'source' => AiCake\Domain\SourceCatalogue::AI )
);

aicake_check(
	'and the identical row does pay it when the source says AI',
	4.50,
	aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $mislabelled )
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
aicake_check( 'a real design passes validation', true, aicake_validates( $product_id, $handles['ai'] ) );
aicake_check( 'no design at all is refused', false, aicake_validates( $product_id ) );
aicake_check( 'an unknown handle is refused', false, aicake_validates( $product_id, str_repeat( 'a', 32 ) ) );

$strangers = aicake_design( 'ai', 'circle', 150.0, 999 );

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
$rich = aicake_design( 'ai', 'cupcake', 45.0 );

$repository = AiCake\Plugin::instance()->designs();
$repository->update( (int) $repository->find_by_public_id( $rich )['id'], array( 'file_proof' => 'wcff-check-proof.webp' ) );

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
aicake_check(
	'cart item records the picture type',
	AiCake\Domain\SourceCatalogue::field_value( 'ai', $settings ),
	$titles[ $source_key ] ?? ''
);

/*
 * Last, because it leaves a non-AI design in the cart: falling back matters as
 * much as the proof itself. A design with no text has no proof, and neither
 * does any design saved before D-045 — neither may show a broken image.
 */
aicake_line_price( $product_id, array( $sheet_key => 'Krakmolo lakštas' ), $handles['none'] );

$fallback = '';

foreach ( WC()->cart->get_cart() as $key => $item ) {
	$fallback = (string) apply_filters( 'woocommerce_cart_item_thumbnail', '', $item, $key );
}

aicake_check( 'and falls back to the preview with no proof', true, false !== strpos( $fallback, '/' . $handles['none'] . '/preview' ) );

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
