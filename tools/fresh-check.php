<?php
/**
 * What a clean install must be true for, before production gets one.
 *
 * Run by `tools/fresh-install.sh` against the throwaway stack in
 * `infra/fresh/`. Not against the main testbed — half of these assertions
 * would pass there for the wrong reason, because that install has been
 * migrated forward for weeks.
 *
 * The question this answers is **not** "does the wizard work". That is M5, and
 * it needs a real order. This answers the question Ruslan actually asked:
 * *activating this on a live shop with 2500 products and 11 000 users changes
 * nothing for anybody who is not using it.*
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.NamingConventions.ValidVariableName, WordPress.DB.PreparedSQL

use AiCake\Installer;
use AiCake\Support\SecretStore;
use AiCake\Support\Settings;

// wp-cli does not load the admin includes, and this file activates and
// deactivates plugins.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

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

global $wpdb;

echo "\nA clean install\n";

/* ------------------------------------------------------------ it is on */

echo "\n== the plugin is active and did not complain\n";

aicake_check( 'plugin active', true, is_plugin_active( 'ai-cake-topper/ai-cake-topper.php' ) );
aicake_check( 'WooCommerce active', true, class_exists( 'WooCommerce' ) );
aicake_check( 'WC Fields Factory active', true, is_plugin_active( 'wc-fields-factory/wcff.php' ) );
aicake_check( 'the composition root booted', true, class_exists( 'AiCake\Plugin' ) );

/* ---------------------------------------------------------- the schema */

echo "\n== the tables it created from nothing\n";

foreach ( array( 'designs' => 30, 'jobs' => 9 ) as $name => $columns ) {
	$table  = Installer::table( $name );
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

	aicake_check( "table $name exists", $table, (string) $exists );

	if ( $exists ) {
		aicake_check( "table $name has $columns columns", $columns, count( (array) $wpdb->get_results( "DESCRIBE {$table}" ) ) );
	}
}

/*
 * dbDelta is not idempotent by accident — it is idempotent because the column
 * definitions match what MySQL reports back. A JSON column re-issues an ALTER
 * on every page load, which is why they are declared LONGTEXT (Phase 1). The
 * way to notice is to run the installer twice and see whether anything moves.
 */
$before = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = '" . Installer::table( 'designs' ) . "'" );

Installer::activate();

$after = $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.columns WHERE table_name = '" . Installer::table( 'designs' ) . "'" );

aicake_check( 'installing twice changes nothing', $before, $after );

/* --------------------------------------------------------- the storage */

echo "\n== storage, with no AICAKE_STORAGE_DIR defined\n";

$settings = new Settings();
$root     = $settings->storage_dir();

/*
 * An unconfigured install falls back to uploads. That is not the production
 * arrangement — §M3 defines the constant — but it is what the plugin does
 * between activation and configuration, and it must not be broken or
 * world-readable in the meantime.
 */
aicake_check( 'falls back to uploads when unconfigured', false, defined( 'AICAKE_STORAGE_DIR' ) );
aicake_check( 'the root was created', true, is_dir( $root ) );

foreach ( array( 'sessions', 'orders' ) as $zone ) {
	aicake_check( "zone $zone exists", true, is_dir( "$root/$zone" ) );
	aicake_check( "zone $zone is writable by the web user", true, is_writable( "$root/$zone" ) );
}

/*
 * D-003 and D-031, both of which cost a broken shop: a directory created by
 * root is unwritable by the web user, the zone root looks fine, and every
 * write into it fails. Nothing here may be owned by anyone else.
 */
$owner = fileowner( "$root/sessions" );

/*
 * Against root specifically, not against whoever is running this check — the
 * check runs under wp-cli and the shop runs under the web user, so they are
 * different by design. Root ownership is the actual hazard: the zone root
 * looks fine and every write into it fails.
 */
aicake_check( 'and is not owned by root', false, 0 === $owner );
// The name is a nicety and the posix extension is not in the wp-cli image.
// 33 is www-data on Debian, which is what both containers run PHP as.
aicake_check( 'it belongs to the web user (uid 33)', 33, $owner );

/* ---------------------------------------------------- it cannot spend */

echo "\n== a fresh install cannot spend money\n";

/*
 * The safe state docs/migration.md §M2 relies on: install, look around, and
 * configure afterwards. If a provider could be reached without a key, "install
 * inert" would be a story rather than a property.
 */
foreach ( array( 'fal', 'gemini', 'replicate' ) as $name ) {
	aicake_check( "no $name key is configured", '', $settings->secret( $name ) );
	aicake_check( "and $name reports itself unset", 'unset', $settings->secret_source( $name ) );
}

aicake_check( 'a cipher is available for when one is entered', true, SecretStore::available() );

/*
 * The IP salt is the exception, deliberately (D-050): unconfigured it used to
 * hash with an empty string, which is the weakest answer reachable by doing
 * nothing at all.
 */
aicake_check( 'but the IP salt is derived rather than empty', 'derived', $settings->secret_source( 'ip_salt' ) );
aicake_check( 'and is not empty', false, '' === $settings->secret( 'ip_salt' ) );

/* --------------------------------------------- it disturbs nothing else */

echo "\n== an ordinary shop is unaffected — Ruslan's acceptance criterion\n";

/*
 * The whole reason this file exists. Production is a working shop; the plugin
 * has to be invisible to every part of it that is not the AI product.
 */
$plain = new WC_Product_Simple();
$plain->set_name( 'Ordinary cake decoration' );
$plain->set_regular_price( '7.50' );
$plain->save();

if ( null === WC()->session ) {
	WC()->initialize_session();
}

if ( null === WC()->cart ) {
	wc_load_cart();
}

WC()->cart->empty_cart();

$added = WC()->cart->add_to_cart( $plain->get_id() );

aicake_check( 'an ordinary product still adds to the cart', true, false !== $added && '' !== (string) $added );

WC()->cart->calculate_totals();

aicake_check( 'at its own price, unchanged', 7.50, round( (float) WC()->cart->get_cart_contents_total(), 2 ) );

/*
 * The validation filter is the one the plugin hooks. A product that is not an
 * AI product must sail through it — this is what "leaves ordinary products
 * alone" means at the hook level rather than in a comment.
 */
aicake_check(
	'and passes the add-to-cart validation untouched',
	true,
	(bool) apply_filters( 'woocommerce_add_to_cart_validation', true, $plain->get_id(), 1 )
);

/*
 * Fulfilment listens on the order status transition. An order with no design
 * must come out the other side with its status intact and no note added —
 * D-047, asserted here on a shop that has never seen a design at all.
 */
$order = wc_create_order();
$order->add_product( $plain, 1 );
$order->set_status( 'pending' );
$order->save();

$order->update_status( 'processing' );

$order = wc_get_order( $order->get_id() );

aicake_check( 'an ordinary order reaches processing', 'processing', $order->get_status() );

/*
 * Counting notes and subtracting the ones WooCommerce writes itself measures
 * WooCommerce, not us, and the fudge factor is wrong the moment Woo changes how
 * many it writes. What D-047 actually claims is narrower and checkable: the
 * plugin leaves no trace on an order it has nothing to do with.
 */
$traces = 0;

foreach ( $order->get_items() as $item ) {
	foreach ( $item->get_meta_data() as $meta ) {
		if ( str_starts_with( (string) $meta->key, '_aicake' ) ) {
			++$traces;
		}
	}
}

aicake_check( 'and the plugin leaves no meta on its items', 0, $traces );

$order_traces = array_filter(
	array_keys( (array) $order->get_meta_data() ),
	static fn( $k ) => str_starts_with( (string) $k, '_aicake' )
);

aicake_check( 'nor on the order itself', 0, count( $order_traces ) );

/*
 * And no note in the shop's own language about printing — the only order-facing
 * vocabulary the plugin has.
 */
$printing_notes = 0;

foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
	if ( false !== stripos( (string) $note->content, 'spausdinim' ) ) {
		++$printing_notes;
	}
}

aicake_check( 'and writes no note about printing', 0, $printing_notes );

$order->delete( true );
$plain->delete( true );

/* ------------------------------------------------------- deactivation */

echo "\n== deactivating leaves the shop working\n";

deactivate_plugins( 'ai-cake-topper/ai-cake-topper.php' );

aicake_check( 'plugin deactivates', false, is_plugin_active( 'ai-cake-topper/ai-cake-topper.php' ) );
aicake_check( 'and the data survives for a reactivation', true, (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Installer::table( 'designs' ) ) ) );

activate_plugin( 'ai-cake-topper/ai-cake-topper.php' );

aicake_check( 'and reactivates cleanly', true, is_plugin_active( 'ai-cake-topper/ai-cake-topper.php' ) );

printf( "\n%d passed, %d failed\n", $GLOBALS['aicake_pass'], $GLOBALS['aicake_fail'] );

if ( $GLOBALS['aicake_fail'] > 0 ) {
	exit( 1 );
}
