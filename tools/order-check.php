<?php
/**
 * Phase 7's gate, re-runnable: a test order produces print files in orders/.
 *
 * Run inside the container, against the deployed copy:
 *
 *   wp eval-file wp-content/plugins/ai-cake-topper/../../../tools/order-check.php
 *
 * or, the way it is actually invoked from the host:
 *
 *   docker exec wordpress-test-wordpress-1 \
 *     wp eval-file /var/lib/aicake/order-check.php --allow-root --path=/var/www/html
 *
 * The master is synthetic on purpose, and stays that way now that fal is
 * funded (D-030): quadrants, a ring at the trim line and an up-marker make a
 * wrong crop, a wrong mask or a wrong rotation *visible* rather than merely
 * asserted, and it costs nothing to re-run. A real generated master has been
 * pushed through the same pipeline separately (D-030) — this gate does not
 * need to spend $0.012 to prove the same geometry twice.
 *
 * Everything downstream of "an image exists on disk" is the real code path,
 * triggered by the real `woocommerce_order_status_processing` hook.
 *
 * This is committed because the Phase 3 and Phase 5 equivalents were not, and
 * their assertions are now unrepeatable.
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions

/*
 * Explicitly in $GLOBALS: `wp eval-file` runs this inside a function, so a
 * plain assignment here is a local that `global` in the helper below can never
 * see — which shows up as a run where every check passes and the total is zero.
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

$plugin  = AiCake\Plugin::instance();
$designs = $plugin->designs();
$storage = $plugin->storage();

/**
 * A master that makes geometry errors visible by eye.
 *
 * @param int $size Edge length.
 */
function aicake_master( int $size = 1024 ): string {
	$img  = imagecreatetruecolor( $size, $size );
	$half = (int) ( $size / 2 );

	$quadrants = array(
		imagecolorallocate( $img, 232, 93, 117 ),
		imagecolorallocate( $img, 84, 173, 214 ),
		imagecolorallocate( $img, 246, 197, 84 ),
		imagecolorallocate( $img, 118, 190, 130 ),
	);

	imagefilledrectangle( $img, 0, 0, $half, $half, $quadrants[0] );
	imagefilledrectangle( $img, $half, 0, $size, $half, $quadrants[1] );
	imagefilledrectangle( $img, 0, $half, $half, $size, $quadrants[2] );
	imagefilledrectangle( $img, $half, $half, $size, $size, $quadrants[3] );

	$ink = imagecolorallocate( $img, 40, 40, 40 );
	imagesetthickness( $img, 8 );
	imageellipse( $img, $half, $half, (int) ( $size * 0.94 ), (int) ( $size * 0.94 ), $ink );
	imagefilledpolygon( $img, array( $half, 40, $half - 40, 120, $half + 40, 120 ), $ink );

	ob_start();
	imagepng( $img );
	imagedestroy( $img );

	return (string) ob_get_clean();
}

/**
 * A finished design row with a master on disk.
 *
 * @param int        $product_id Product.
 * @param array|null $text       Text payload, or null.
 * @return array<string, mixed>
 */
function aicake_design( int $product_id, ?array $text = null ): array {
	$plugin    = AiCake\Plugin::instance();
	$designs   = $plugin->designs();
	$storage   = $plugin->storage();
	$public_id = $designs->new_public_id();
	$master    = $storage->store_master( $public_id, aicake_master() );

	$id = $designs->create(
		array(
			'public_id'    => $public_id,
			'session_key'  => 'phase7-check',
			'ip_hash'      => str_repeat( 'a', 64 ),
			'product_id'   => $product_id,
			'prompt_raw'   => 'linksmas dinozauras su gimtadienio tortu',
			'prompt_en'    => 'a cheerful cartoon dinosaur with a birthday cake',
			'prompt_final' => 'a cheerful cartoon dinosaur with a birthday cake, flat vector',
			'text_payload' => null === $text ? null : wp_json_encode( $text ),
			'provider'     => 'synthetic',
			'model'        => 'phase7-fixture',
			'aspect'       => '1:1',
			'status'       => AiCake\Domain\DesignRepository::STATUS_DONE,
			'file_master'  => $master,
			'file_preview' => $storage->write( $storage->session_path( $public_id, 'preview.webp' ), 'placeholder' ),
			'cost_usd'     => 0.003,
			'moderation'   => wp_json_encode( array( 'verdict' => 'allow', 'layer' => 'blocklist' ) ),
		)
	);

	return array(
		'id'        => $id,
		'public_id' => $public_id,
		'master'    => $master,
	);
}

/**
 * An order carrying the given designs.
 *
 * @param array<int, array<string, mixed>> $pairs product_id => design.
 * @return array{0:WC_Order, 1:array<int, int>}
 */
function aicake_order( array $pairs ): array {
	$order    = wc_create_order();
	$item_ids = array();

	foreach ( $pairs as $product_id => $design ) {
		$item = new WC_Order_Item_Product();
		$item->set_product_id( $product_id );
		$item->set_name( (string) get_the_title( $product_id ) );
		$item->set_quantity( 1 );
		$item->set_total( 10 );
		$item->add_meta_data( '_aicake_design', $design['public_id'], true );
		$order->add_item( $item );
		$order->save();

		$item_ids[ $product_id ] = $item->get_id();
	}

	$order->set_total( 10 * count( $pairs ) );
	$order->save();

	return array( $order, $item_ids );
}

/**
 * Run the fulfilment actions queued for one order.
 *
 * Scoped to the order on purpose. A previous run's failure-path retries are
 * still pending in the queue, so draining everything both miscounts and
 * re-executes another order's work as a side effect.
 *
 * @param int $order_id Order to drain.
 */
function aicake_drain_queue( int $order_id ): int {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return 0;
	}

	$actions = as_get_scheduled_actions(
		array(
			'hook'     => AiCake\WooCommerce\Fulfilment::HOOK,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'group'    => AiCake\WooCommerce\Fulfilment::GROUP,
			'per_page' => 100,
		)
	);

	$ran = 0;

	foreach ( $actions as $action_id => $action ) {
		$args = $action->get_args();

		if ( ( (int) ( $args[0] ?? 0 ) ) !== $order_id ) {
			continue;
		}

		ActionScheduler::runner()->process_action( $action_id );
		++$ran;
	}

	return $ran;
}

/* ------------------------------------------------------------- statuses */

echo "statuses (§13.3)\n";

$statuses = wc_get_order_statuses();

foreach ( array( 'rendering', 'approval', 'approved', 'rejected', 'failed' ) as $slug ) {
	aicake_check( "wc-aicake-{$slug} is registered", true, isset( $statuses[ 'wc-aicake-' . $slug ] ) );
}

$keys = array_keys( $statuses );
$at   = array_search( 'wc-processing', $keys, true );

aicake_check( 'they follow processing, not completed', 'wc-aicake-rendering', $keys[ $at + 1 ] ?? '' );
aicake_check( 'registered for the non-HPOS path too', true, null !== get_post_status_object( 'wc-aicake-approval' ) );
aicake_check( 'in-flight orders still count as paid', true, in_array( 'aicake-approval', wc_get_is_paid_statuses(), true ) );
aicake_check( 'rejected does not count as paid', false, in_array( 'aicake-rejected', wc_get_is_paid_statuses(), true ) );

// Every slug must fit the 20-character status column in both backends.
$longest = 0;

foreach ( array_keys( $statuses ) as $slug ) {
	if ( str_starts_with( $slug, 'wc-aicake-' ) ) {
		$longest = max( $longest, strlen( $slug ) );
	}
}

aicake_check( 'the longest slug fits the status column', true, $longest <= 20 && $longest > 0 );

/* ------------------------------------------------------- storage layout */

echo "\nstorage layout (§12.2)\n";

$ts = mktime( 0, 0, 0, 8, 15, 2026 );

aicake_check( 'print file naming', true, str_ends_with( $storage->order_path( 10432, 57, 'print.png', $ts ), '/orders/2026/08/10432/item-57-print.png' ) );
aicake_check( 'sidecar naming, no stray dash', true, str_ends_with( $storage->order_path( 10432, 57, '.json', $ts ), '/orders/2026/08/10432/item-57.json' ) );
aicake_check( 'the folder follows the order, not the clock', true, str_contains( $storage->order_path( 1, 1, 'print.png', mktime( 0, 0, 0, 1, 5, 2025 ) ), '/orders/2025/01/' ) );

/* --------------------------------------------------------- the happy path */

echo "\nfulfilment\n";

$single = aicake_design( 646, array( 'text' => 'Su gimtadieniu, Emilija', 'placement' => 'arc_bottom', 'colour' => '#ffffff' ) );
$sheet  = aicake_design( 649 );

list( $order, $item_ids ) = aicake_order(
	array(
		646 => $single,
		649 => $sheet,
	)
);

$order_id = $order->get_id();

// The hook a payment gateway fires. Nothing below calls the pipeline directly.
$order->update_status( 'processing' );

aicake_check( 'queued one action per design item', 2, aicake_drain_queue( $order_id ) );

$order = wc_get_order( $order_id );

aicake_check( 'order reaches awaiting-approval', 'aicake-approval', $order->get_status() );

foreach ( $item_ids as $product_id => $item_id ) {
	$item  = $order->get_item( $item_id );
	$print = (string) $item->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT );

	aicake_check( "product {$product_id}: print file on disk", true, '' !== $print && is_readable( $print ) );

	if ( '' === $print || ! is_readable( $print ) ) {
		continue;
	}

	$size = getimagesize( $print );
	$dpi  = $plugin->images()->read_dpi( (string) file_get_contents( $print ) );

	printf(
		"        %s  %dx%d  %d dpi  %s  %s\n",
		basename( $print ),
		$size[0],
		$size[1],
		$dpi,
		AiCake\Support\Mm::describe( $size[0], $size[1], $dpi ),
		size_format( filesize( $print ) )
	);

	aicake_check( "product {$product_id}: declares 300 dpi", 300, $dpi );
	aicake_check( "product {$product_id}: exactly one pHYs chunk", 1, substr_count( (string) file_get_contents( $print ), 'pHYs' ) );
	aicake_check( "product {$product_id}: sidecar written", true, is_readable( dirname( $print ) . '/item-' . $item_id . '.json' ) );
	aicake_check( "product {$product_id}: left the ephemeral zone", false, str_contains( $print, '/sessions/' ) );
}

/* ------------------------------------------------------------- geometry */

echo "\ngeometry (§3)\n";

$single_print = (string) $order->get_item( $item_ids[646] )->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT );
$sheet_print  = (string) $order->get_item( $item_ids[649] )->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT );

if ( is_readable( $single_print ) ) {
	$s = getimagesize( $single_print );
	// 150 mm trim + 3 mm bleed per edge = 156 mm at 300 dpi.
	aicake_check( '15 cm topper is 1843 px square', array( 1843, 1843 ), array( $s[0], $s[1] ) );
}

if ( is_readable( $sheet_print ) ) {
	$s = getimagesize( $sheet_print );
	// Usable area 200 x 287 mm at 300 dpi. 2363 not 2362: Mm::to_px ceils,
	// because a pixel short is a visible white edge on the cut line.
	aicake_check( '24-up sheet is the usable A4 area', array( 2363, 3390 ), array( $s[0], $s[1] ) );
}

/* -------------------------------------------------------------- sidecar */

echo "\nsidecar (§12.3)\n";

$sidecar = dirname( $sheet_print ) . '/item-' . $item_ids[649] . '.json';

if ( is_readable( $sidecar ) ) {
	$record = json_decode( (string) file_get_contents( $sidecar ), true );

	aicake_check( 'records the order', $order_id, (int) $record['order_id'] );
	aicake_check( 'records the Lithuanian prompt', 'linksmas dinozauras su gimtadienio tortu', $record['prompt']['raw_lt'] );
	aicake_check( 'records the derived copy count', 24, (int) $record['print_spec']['copies'] );
	aicake_check( 'records the moderation verdict', 'allow', $record['moderation']['verdict'] );
	aicake_check( 'records what it cost', 0.003, (float) $record['generation']['cost_usd'] );
}

/* ---------------------------------------------------------- idempotency */

echo "\nidempotency (§13.4)\n";

$before = filemtime( $sheet_print );
sleep( 1 );
$plugin->fulfilment()->fulfil_item( $order_id, $item_ids[649] );
clearstatcache();

aicake_check( 'a second run does not re-render', $before, filemtime( $sheet_print ) );

/* --------------------------------------------------------- the DB link */

echo "\ndatabase\n";

$row = $designs->find( $sheet['id'] );

aicake_check( 'design points at the order', $order_id, (int) $row['order_id'] );
aicake_check( 'design points at the item', $item_ids[649], (int) $row['order_item_id'] );
aicake_check( 'file_print repointed', true, str_contains( (string) $row['file_print'], '/orders/' ) );
aicake_check( 'file_master repointed', true, str_contains( (string) $row['file_master'], '/orders/' ) );
aicake_check( 'the ephemeral master is gone', false, is_readable( $sheet['master'] ) );

/* ------------------------------------------------------ the admin screen */

echo "\nadmin screen\n";

$screen = new AiCake\Admin\OrderScreen( $designs, $plugin->fulfilment() );

ob_start();
$screen->value( null, $order->get_item( $item_ids[649] ), $item_ids[649] );
$cell = (string) ob_get_clean();

aicake_check( 'offers the print file', true, str_contains( $cell, '/print?' ) || str_contains( $cell, '/print&' ) );
aicake_check( 'never emits a filesystem path', false, str_contains( $cell, '/var/lib/aicake' ) );

/*
 * The download is a plain <a href> and the preview a plain <img src>, neither
 * of which can send an X-WP-Nonce header. Without the nonce in the query
 * string, WordPress's REST cookie check leaves even a shop manager as user 0,
 * the capability test fails, and the button 404s while looking correct —
 * D-025's trap in a second place.
 */
aicake_check( 'the print link carries a nonce', true, (bool) preg_match( '#/print\?_wpnonce=[a-z0-9]+#', $cell ) );
aicake_check( 'the preview link carries a nonce', true, (bool) preg_match( '#/preview\?_wpnonce=[a-z0-9]+#', $cell ) );

/* --------------------------------------------------------- failure path */

echo "\nfailure path (§13.4)\n";

$broken = aicake_design( 646 );
unlink( $broken['master'] );

list( $bad_order, $bad_items ) = aicake_order( array( 646 => $broken ) );

$bad_id      = $bad_order->get_id();
$bad_item_id = $bad_items[646];

$bad_order->update_status( 'processing' );
$bad_order = wc_get_order( $bad_id );

aicake_check( 'a paid order still enters rendering', 'aicake-rendering', $bad_order->get_status() );

for ( $attempt = 0; $attempt < 3; $attempt++ ) {
	$plugin->fulfilment()->fulfil_item( $bad_id, $bad_item_id );
}

$bad_order = wc_get_order( $bad_id );
$bad_item  = $bad_order->get_item( $bad_item_id );

aicake_check( 'gives up as failed, never silently', 'aicake-failed', $bad_order->get_status() );
aicake_check( 'stops at the attempt ceiling', 3, (int) $bad_item->get_meta( AiCake\WooCommerce\Fulfilment::META_ATTEMPTS ) );
aicake_check( 'records why', true, '' !== (string) $bad_item->get_meta( AiCake\WooCommerce\Fulfilment::META_ERROR ) );
aicake_check( 'claims no print file', '', (string) $bad_item->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT ) );

$noted = false;

foreach ( wc_get_order_notes( array( 'order_id' => $bad_id ) ) as $note ) {
	if ( str_contains( $note->content, 'Nepavyko paruošti' ) ) {
		$noted = true;
	}
}

aicake_check( 'leaves an order note a human will see', true, $noted );

/* ------------------------------------------------------------- recovery */

echo "\nrecovery\n";

$designs->update( $broken['id'], array( 'file_master' => $storage->store_master( $broken['public_id'], aicake_master() ) ) );

// What the retry button does.
$bad_item->delete_meta_data( AiCake\WooCommerce\Fulfilment::META_ATTEMPTS );
$bad_item->save();

$plugin->fulfilment()->fulfil_item( $bad_id, $bad_item_id );

$bad_order = wc_get_order( $bad_id );
$bad_item  = $bad_order->get_item( $bad_item_id );

aicake_check( 'a retry produces the print file', true, is_readable( (string) $bad_item->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT ) ) );
aicake_check( 'and lifts the order out of failed', 'aicake-approval', $bad_order->get_status() );
aicake_check( 'and clears the error', '', (string) $bad_item->get_meta( AiCake\WooCommerce\Fulfilment::META_ERROR ) );

/* ------------------------------------------------------ ordinary orders */

echo "\nordinary orders\n";

$plain = wc_create_order();
$line  = new WC_Order_Item_Product();
$line->set_product_id( 646 );
$line->set_name( 'No design attached' );
$line->set_quantity( 1 );
$line->set_total( 5 );
$plain->add_item( $line );
$plain->set_total( 5 );
$plain->save();
$plain->update_status( 'processing' );

aicake_check( 'a sale with no design is left alone', 'processing', wc_get_order( $plain->get_id() )->get_status() );

/* ------------------------------------------------------------- reorder */

echo "\nreorder (§12.6)\n";

$carried = apply_filters(
	'woocommerce_order_again_cart_item_data',
	array(),
	$order->get_item( $item_ids[649] ),
	$order
);

aicake_check( 'carries the design across', $sheet['public_id'], $carried[ AiCake\WooCommerce\CartIntegration::CART_KEY ]['public_id'] ?? '' );
aicake_check( 'and keeps the cart line separate', md5( $sheet['public_id'] ), $carried['unique_key'] ?? '' );

printf( "\n%d passed, %d failed\n", $GLOBALS['aicake_pass'], $GLOBALS['aicake_fail'] );
printf( "order  %s\n", admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id ) );
printf( "files  %s\n", dirname( $sheet_print ) );

exit( $GLOBALS['aicake_fail'] > 0 ? 1 : 0 );
