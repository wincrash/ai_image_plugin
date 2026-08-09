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
 *   docker compose exec -u www-data wordpress \
 *     wp eval-file /var/lib/aicake/order-check.php --path=/var/www/html
 *
 * **Run it as the web user, never as root.** This creates
 * `orders/YYYY/MM/<id>/`, and the dated parent is created by whoever gets
 * there first. Run as root — which is what `--allow-root` means — and that
 * parent ends up owned by root while PHP runs as the web user, so the next
 * real order cannot create its folder and dies with "Nepavyko įrašyti
 * spausdinimo failo." The gate passes and the shop breaks (D-031).
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

	/*
	 * This ring comes out **1 px**, not 8: GD ignores `imagesetthickness()` for
	 * ellipses. Left as it is, because it is decoration on a test fixture and
	 * because it is a standing demonstration of the bug that produced D-048's
	 * hairline cut line — if you crop a rendered sheet and find a thin circle
	 * outside the thick black one, this is it, and it is not the print path.
	 */
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
 * Ask for every print file on an order, the way the download button does.
 *
 * Under D-048 there is no queue to drain: `ensure_print_file()` is the whole
 * fulfilment path, and it runs inside the request that wants the file.
 *
 * @param int $order_id Order to render.
 *
 * @return int How many items produced a readable file.
 */
/**
 * Pending Action Scheduler jobs in this plugin's group.
 *
 * Under D-048 this must never grow. It is not zero on the testbed — the queue
 * that D-048 deleted left work behind — so it is only useful as a delta.
 */
function aicake_pending_actions(): int {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return 0;
	}

	return count(
		(array) as_get_scheduled_actions(
			array(
				'group'    => 'ai-cake-topper',
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 200,
			)
		)
	);
}

/**
 * How many of an order's design items already have a print file on disk.
 *
 * @param int $order_id Order.
 */
function aicake_files_on_disk( int $order_id ): int {
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		return 0;
	}

	$found = 0;

	foreach ( AiCake\Plugin::instance()->fulfilment()->design_items( $order ) as $item ) {
		$path = (string) $item->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT );

		if ( '' !== $path && is_readable( $path ) ) {
			++$found;
		}
	}

	return $found;
}

function aicake_render_order( int $order_id ): int {
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order ) {
		return 0;
	}

	$made = 0;

	foreach ( AiCake\Plugin::instance()->fulfilment()->design_items( $order ) as $item_id => $item ) {
		unset( $item );

		$error = '';
		$path  = AiCake\Plugin::instance()->fulfilment()->ensure_print_file( $order_id, (int) $item_id, $error );

		if ( '' !== $path && is_readable( $path ) ) {
			++$made;
		}
	}

	return $made;
}

/* ------------------------------------------------------------- statuses */

/*
 * D-047 deleted all five. The shop runs the ordinary WooCommerce flow and moves
 * orders by hand; a plugin status is a second order process running alongside
 * the real one.
 *
 * Asserted from three directions because they are three separate registrations
 * and the old code did all three — a plugin that stops registering only the one
 * its own site reads still puts a status on somebody else's.
 */

echo "statuses — none of ours (D-047)\n";

$ours = array();

foreach ( array_keys( wc_get_order_statuses() ) as $slug ) {
	if ( str_starts_with( $slug, 'wc-aicake-' ) ) {
		$ours[] = $slug;
	}
}

aicake_check( 'no aicake status in the dropdown', array(), $ours );
aicake_check( 'none registered on the legacy post path', null, get_post_status_object( 'wc-aicake-approval' ) );
aicake_check( 'none registered on the HPOS path', array(), array_filter(
	array_keys( (array) apply_filters( 'woocommerce_register_shop_order_post_statuses', array() ) ),
	static fn( $slug ) => str_starts_with( (string) $slug, 'wc-aicake-' )
) );
aicake_check( 'nothing added to the paid-status list', array(), array_filter(
	wc_get_is_paid_statuses(),
	static fn( $slug ) => str_starts_with( (string) $slug, 'aicake-' )
) );

/* ------------------------------------------------------- storage layout */

echo "\nstorage layout (§12.2)\n";

$ts = mktime( 0, 0, 0, 8, 15, 2026 );

aicake_check( 'print file naming', true, str_ends_with( $storage->order_path( 10432, 57, 'print.png', $ts ), '/orders/2026/08/10432/item-57-print.png' ) );
aicake_check( 'sidecar naming, no stray dash', true, str_ends_with( $storage->order_path( 10432, 57, '.json', $ts ), '/orders/2026/08/10432/item-57.json' ) );
aicake_check( 'the folder follows the order, not the clock', true, str_contains( $storage->order_path( 1, 1, 'print.png', mktime( 0, 0, 0, 1, 5, 2025 ) ), '/orders/2025/01/' ) );

/* --------------------------------------------------------- the happy path */

echo "\nfulfilment\n";

/*
 * `$single` deliberately carries a **legacy** `TextSpec` payload — the shape
 * the retired server-side renderer used, which real rows in the database still
 * have. D-045 deleted that renderer, so the payload no longer draws anything;
 * what matters is that such an order still fulfils rather than fataling on a
 * class that no longer exists.
 */
$single = aicake_design( 646, array( 'text' => 'Su gimtadieniu, Emilija', 'placement' => 'arc_bottom', 'colour' => '#ffffff' ) );
$sheet  = aicake_design( 649 );

/*
 * Asserted on the *behaviour*, not on the mechanism. A legacy payload still
 * reads back as a layer — deliberately, because its `text` is what the shop
 * manager reads on the order — but it carries no bitmap, so there is nothing to
 * composite and the print is the artwork alone.
 */
aicake_check(
	'a legacy text payload has no bitmap to composite',
	false,
	AiCake\Domain\TextLayer::from_design(
		array( 'text_payload' => wp_json_encode( array( 'text' => 'Su gimtadieniu', 'placement' => 'arc_bottom' ) ) )
	)->has_bitmap()
);

list( $order, $item_ids ) = aicake_order(
	array(
		646 => $single,
		649 => $sheet,
	)
);

$order_id = $order->get_id();

/*
 * The transition a payment gateway fires. Under D-048 it must do **nothing** —
 * there is no hook on it any more. Asserted below rather than assumed, because
 * "the background worker is gone" is exactly the kind of claim that stays true
 * in the code and false on the server if a stale copy is deployed.
 */
$aicake_queued_before = aicake_pending_actions();

$order->update_status( 'processing' );

/*
 * Measured as a delta, not an absolute. The testbed carries leftover actions
 * from the queue D-048 deleted, so "the group is empty" would be red for a
 * reason that has nothing to do with this order.
 */
aicake_check( 'paying schedules no background work', $aicake_queued_before, aicake_pending_actions() );
aicake_check( 'and produces no print file on its own', 0, aicake_files_on_disk( $order_id ) );

/*
 * What the download button does, with the order notes counted across it.
 *
 * The window matters: `update_status()` above makes WooCommerce write its own
 * status-change note, so counting notes on the whole order would measure
 * WooCommerce rather than us. Nothing but our code runs inside this window, so
 * a delta of zero is the precise form of "the plugin says nothing" — and unlike
 * matching known strings, it catches a note somebody adds later without
 * thinking (D-047, D-048).
 */
$aicake_notes_before = count( wc_get_order_notes( array( 'order_id' => $order_id ) ) );

aicake_check( 'the download button renders every item', 2, aicake_render_order( $order_id ) );

aicake_check(
	'and writes no order note doing it',
	$aicake_notes_before,
	count( wc_get_order_notes( array( 'order_id' => $order_id ) ) )
);

$order = wc_get_order( $order_id );

/*
 * The assertion the whole of D-047 rests on. Both print files exist and the
 * order is still exactly where the shop left it. Reintroducing any
 * `update_status()` in `Fulfilment` turns this red.
 */
aicake_check( 'fulfilment left the status alone', 'processing', $order->get_status() );

/*
 * The promise with no list: nothing the plugin does may produce a note the
 * customer can read, because a customer note is what WooCommerce emails.
 */
aicake_check(
	'and told the customer nothing',
	array(),
	array_values( array_map(
		static fn( $n ) => $n->content,
		array_filter( wc_get_order_notes( array( 'order_id' => $order_id ) ), static fn( $n ) => (bool) $n->customer_note )
	) )
);

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

/* ------------------------------------------------- the D-033 text layer */

echo "\nD-033 text layer on the print file\n";

/*
 * The case the suite was missing, and the reason a real bug survived: every
 * scenario above used the legacy payload, so nothing exercised what happens
 * when a design carries a composed layer. Both payload shapes have a `text`
 * key, and a layer was falling through into the old server-side renderer —
 * which D-045 has since deleted outright.
 *
 * This asserts the thing that matters on paper — that ink from the layer lands
 * at the pixel the layer put it at, on the imposed sheet, without scaling.
 */
/*
 * **Product 646, deliberately.** It is the 15 cm single topper, whose geometry
 * is a 1843 px square — nothing like the cupcake sheet the design asks for. An
 * earlier version of this used 649, whose product geometry already *is* a 4.5
 * cm cupcake sheet, so `for_product()` and `for_design()` agreed and the
 * assertion below passed either way. It was decoration. Reverting the fix must
 * turn this red, and with 646 it does.
 */
$layer_design  = aicake_design( 646 );
$layer_spec    = AiCake\Domain\PrintSpec::for_design(
	array(
		'format_type' => AiCake\Domain\FormatCatalogue::TYPE_CUPCAKE,
		'format_mm'   => 45.0,
	)
);

list( $canvas_w, $canvas_h ) = $layer_spec->canvas_px();

$layout   = $layer_spec->editor_layout();
$marked   = $layout['pieces'][7];
$mark_rgb = array( 0xC6, 0x28, 0x28 );

$layer_image = imagecreatetruecolor( $canvas_w, $canvas_h );
imagealphablending( $layer_image, false );
imagesavealpha( $layer_image, true );
imagefilledrectangle( $layer_image, 0, 0, $canvas_w - 1, $canvas_h - 1, imagecolorallocatealpha( $layer_image, 0, 0, 0, 127 ) );

// A solid block on piece 7 only. Its position is what proves the layer was
// composited unscaled and unmoved.
$ink = imagecolorallocatealpha( $layer_image, $mark_rgb[0], $mark_rgb[1], $mark_rgb[2], 0 );
imagefilledrectangle( $layer_image, $marked['cx'] - 40, $marked['cy'] - 12, $marked['cx'] + 40, $marked['cy'] + 12, $ink );

ob_start();
imagepng( $layer_image );
$layer_png = (string) ob_get_clean();
imagedestroy( $layer_image );

$layer_path = $plugin->storage()->write(
	$plugin->storage()->session_path( $layer_design['public_id'], 'text.png' ),
	$layer_png
);

$plugin->designs()->update(
	(int) $layer_design['id'],
	array(
		'format_type'  => AiCake\Domain\FormatCatalogue::TYPE_CUPCAKE,
		'format_mm'    => 45.0,
		'text_payload' => wp_json_encode(
			array(
				'text'      => 'Emilija',
				'colours'   => array( '#c62828' ),
				'path'      => $layer_path,
				'width_px'  => $canvas_w,
				'height_px' => $canvas_h,
			)
		),
	)
);

list( $layer_order, $layer_items ) = aicake_order( array( 646 => $layer_design ) );

$layer_order->update_status( 'processing' );
aicake_render_order( $layer_order->get_id() );

$layer_print = (string) wc_get_order( $layer_order->get_id() )
	->get_item( $layer_items[646] )
	->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT );

aicake_check( 'a design with a layer still produces a print file', true, '' !== $layer_print && is_readable( $layer_print ) );

if ( is_readable( $layer_print ) ) {
	$printed = imagecreatefrompng( $layer_print );

	/*
	 * The page, not the artwork. Since D-070 every print file is a full A4 sheet
	 * with the usable area at the top-left, so this no longer distinguishes the
	 * design's format from the product's — the ink assertions below do that, and
	 * they still go red if `for_design()` is reverted, because a layer authored
	 * at 2481 x 3331 is refused outright by a canvas built for a 15 cm topper.
	 */
	aicake_check(
		'the print is a full A4 page',
		array( AiCake\Support\Mm::to_px( 210.0 ), AiCake\Support\Mm::to_px( 297.0 ) ),
		array( imagesx( $printed ), imagesy( $printed ) )
	);

	$at_mark = imagecolorat( $printed, $marked['cx'], $marked['cy'] );

	$near = abs( ( $at_mark >> 16 & 0xFF ) - $mark_rgb[0] ) < 12
		&& abs( ( $at_mark >> 8 & 0xFF ) - $mark_rgb[1] ) < 12
		&& abs( ( $at_mark & 0xFF ) - $mark_rgb[2] ) < 12;

	aicake_check( 'the layer ink lands on the piece it was drawn on', true, $near );

	// And nowhere else: a layer drawn on one cupcake must not appear on the
	// other twenty-three, which is what per-piece text means (D-033).
	$elsewhere = imagecolorat( $printed, $layout['pieces'][0]['cx'], $layout['pieces'][0]['cy'] );

	$clean = abs( ( $elsewhere >> 16 & 0xFF ) - $mark_rgb[0] ) > 30
		|| abs( ( $elsewhere >> 8 & 0xFF ) - $mark_rgb[1] ) > 30
		|| abs( ( $elsewhere & 0xFF ) - $mark_rgb[2] ) > 30;

	aicake_check( 'and not on the pieces it was not', true, $clean );

	/*
	 * The cut line — D-033 specified it, D-048 finally drew it, and Ruslan found
	 * it missing by printing a sheet of cupcakes with no circles on it.
	 *
	 * Sampled on the trim radius rather than by eye: walk the circumference of
	 * one piece and require dark ink somewhere on it. Sampling a single point
	 * would pass on any dark artwork that happened to reach the edge, and the
	 * artwork is bled *past* the trim line by design, so "there is something
	 * dark there" is not enough — the ring has to be dark all the way round.
	 */
	$centre  = $layout['pieces'][0];
	$radius  = AiCake\Support\Mm::to_px( 45.0 ) / 2;
	$on_line = 0;
	$samples = 0;

	for ( $deg = 0; $deg < 360; $deg += 15 ) {
		$px = (int) round( $centre['cx'] + $radius * cos( deg2rad( $deg ) ) );
		$py = (int) round( $centre['cy'] + $radius * sin( deg2rad( $deg ) ) );

		if ( $px < 0 || $py < 0 || $px >= imagesx( $printed ) || $py >= imagesy( $printed ) ) {
			continue;
		}

		++$samples;

		$rgb = imagecolorat( $printed, $px, $py );

		// Dark on every channel. The trim line is solid black; bled artwork
		// underneath it may be any colour, which is why all three must be low.
		if ( ( $rgb >> 16 & 0xFF ) < 90 && ( $rgb >> 8 & 0xFF ) < 90 && ( $rgb & 0xFF ) < 90 ) {
			++$on_line;
		}
	}

	aicake_check( 'the cut line is drawn all the way round a cupcake', true, $samples > 20 && $on_line === $samples );

	/*
	 * And it is the thickness it claims to be.
	 *
	 * This is the assertion that matters and the one that was missing: the
	 * original drew a **1 px** hairline, because GD ignores
	 * `imagesetthickness()` for ellipses and says nothing about it. Presence
	 * alone was green throughout. On screen a hairline circle looks fine; it is
	 * 0.085 mm at 300 DPI, too thin to cut along and liable to print faint.
	 *
	 * Measured radially, so it is in the same units the specification is
	 * written in rather than "some dark pixels are near the edge".
	 */
	$want = AiCake\Support\Mm::to_px( 0.3 );
	$run  = 0;
	$got  = 0;

	for ( $px = (int) round( $centre['cx'] - $radius - 15 ); $px <= (int) round( $centre['cx'] - $radius + 15 ); $px++ ) {
		$rgb = imagecolorat( $printed, $px, (int) round( $centre['cy'] ) );
		$lum = ( ( $rgb >> 16 & 0xFF ) + ( $rgb >> 8 & 0xFF ) + ( $rgb & 0xFF ) ) / 3;

		if ( $lum < 100 ) {
			++$run;
			$got = max( $got, $run );
		} else {
			$run = 0;
		}
	}

	aicake_check( 'and is 0.3 mm thick, not a hairline', $want, $got );

	/*
	 * And only there. A gutter midway between two pieces must stay white, or
	 * the "cut line" is really just a dark sheet.
	 */
	$gap = imagecolorat(
		$printed,
		(int) round( ( $layout['pieces'][0]['cx'] + $layout['pieces'][1]['cx'] ) / 2 ),
		(int) round( ( $layout['pieces'][0]['cy'] + $layout['pieces'][1]['cy'] ) / 2 )
	);

	aicake_check( 'and the gutter between pieces stays clean', true, ( $gap >> 16 & 0xFF ) > 200 );

	imagedestroy( $printed );
}

/* ------------------------------------------------------------- geometry */

echo "\ngeometry (§3)\n";

$single_print = (string) $order->get_item( $item_ids[646] )->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT );
$sheet_print  = (string) $order->get_item( $item_ids[649] )->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT );

/*
 * **Both files are A4, and that is the whole of D-070.**
 *
 * They used to be the artwork's own size — 1843 px square for a 15 cm topper,
 * 210 x 282 mm for a sheet — which meant the print dialog decided where on the
 * page they went. "Actual size" centres, "fit to page" scales by 5.3%, and
 * neither lands where `ProofSheet` draws the same format. Ruslan cuts by the
 * printed black line against a proof he has already verified on paper (D-040),
 * so a file that is not a page is a file whose circles are somewhere else.
 */
$page = array( AiCake\Support\Mm::to_px( 210.0 ), AiCake\Support\Mm::to_px( 297.0 ) );

if ( is_readable( $single_print ) ) {
	$s = getimagesize( $single_print );

	aicake_check( 'a single topper is a full A4 page', $page, array( $s[0], $s[1] ) );

	/*
	 * And the piece is where the proof puts it, not merely somewhere on the
	 * page. Centring it instead would also be "A4" and would still print in the
	 * wrong place — the assertion has to name the coordinate.
	 *
	 * Measured off the cut line, because that is the thing being aligned:
	 * sample the trim circle all the way round at the centre `SheetLayout`
	 * derived, and require ink on every sample.
	 */
	$plan   = AiCake\Domain\FormatCatalogue::spec( AiCake\Domain\FormatCatalogue::TYPE_CIRCLE, 150.0 )->sheet_plan();
	$at     = $plan['centres_px'][0];
	$radius = AiCake\Support\Mm::to_px( 150.0 ) / 2;
	$page_image = imagecreatefrompng( $single_print );
	$inked  = 0;
	$taken  = 0;

	for ( $deg = 0; $deg < 360; $deg += 15 ) {
		$px = (int) round( $at['x'] + $radius * cos( deg2rad( $deg ) ) );
		$py = (int) round( $at['y'] + $radius * sin( deg2rad( $deg ) ) );

		if ( $px < 0 || $py < 0 || $px >= $page[0] || $py >= $page[1] ) {
			continue;
		}

		++$taken;

		$rgb = imagecolorat( $page_image, $px, $py );

		if ( ( $rgb >> 16 & 0xFF ) < 90 && ( $rgb >> 8 & 0xFF ) < 90 && ( $rgb & 0xFF ) < 90 ) {
			++$inked;
		}
	}

	aicake_check( 'and its cut line is where the proof draws it', true, $taken > 20 && $inked === $taken );

	imagedestroy( $page_image );
}

if ( is_readable( $sheet_print ) ) {
	$s = getimagesize( $sheet_print );
	/*
	 * Derived from the usable area rather than typed, and that is the point.
	 * This assertion was `array( 2363, 3390 )` — correct for the 200 × 287 mm
	 * the printer was assumed to have, and wrong the moment D-039 measured it
	 * as 210 × 282. A frozen number here would have gone red for the right
	 * reason and then been "fixed" by pasting in whatever the code produced,
	 * which asserts nothing. This still fails if the imposition stops using
	 * the usable area at all.
	 */
	aicake_check(
		'24-up sheet is a full A4 page',
		array(
			AiCake\Support\Mm::to_px( AiCake\Imaging\SheetLayout::PAPER_W_MM ),
			AiCake\Support\Mm::to_px( AiCake\Imaging\SheetLayout::PAPER_H_MM ),
		),
		array( $s[0], $s[1] )
	);

	/*
	 * The 15 mm that carries no icing is *below* the artwork, not split around
	 * it. Mounting the usable area centred instead would leave 7.5 mm of white
	 * above the top row and push the bottom row 7.5 mm into the bare strip — a
	 * file that passes the size assertion above and still prints half a row on
	 * backing paper.
	 *
	 * Not asserted by sampling the strip, which is white either way and would be
	 * green whatever the code did. Asserted on the first circle's **cut line**,
	 * at the coordinate `SheetLayout` derives and `ProofSheet` rings: shift the
	 * mount by a single millimetre and the samples fall off the ink.
	 */
	$page_image = imagecreatefrompng( $sheet_print );
	$at     = AiCake\Domain\FormatCatalogue::spec( AiCake\Domain\FormatCatalogue::TYPE_CUPCAKE, 45.0 )
		->sheet_plan()['centres_px'][0];
	$radius = AiCake\Support\Mm::to_px( 45.0 ) / 2;
	$inked  = 0;
	$taken  = 0;

	for ( $deg = 0; $deg < 360; $deg += 15 ) {
		$px = (int) round( $at['x'] + $radius * cos( deg2rad( $deg ) ) );
		$py = (int) round( $at['y'] + $radius * sin( deg2rad( $deg ) ) );

		if ( $px < 0 || $py < 0 || $px >= $s[0] || $py >= $s[1] ) {
			continue;
		}

		++$taken;

		$rgb = imagecolorat( $page_image, $px, $py );

		if ( ( $rgb >> 16 & 0xFF ) < 90 && ( $rgb >> 8 & 0xFF ) < 90 && ( $rgb & 0xFF ) < 90 ) {
			++$inked;
		}
	}

	aicake_check( 'and the top-left cupcake is where the proof draws it', true, $taken > 20 && $inked === $taken );

	imagedestroy( $page_image );
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

$aicake_err = '';
$before     = filemtime( $sheet_print );
sleep( 1 );
$again = $plugin->fulfilment()->ensure_print_file( $order_id, $item_ids[649], $aicake_err );
clearstatcache();

aicake_check( 'pressing download twice serves the same file', $sheet_print, $again );
aicake_check( 'and does not re-render it', $before, filemtime( $sheet_print ) );

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

aicake_check( 'offers the download button', true, str_contains( $cell, 'action=aicake_download_print' ) );
aicake_check( 'never emits a filesystem path', false, str_contains( $cell, '/var/lib/aicake' ) );

/*
 * D-048 moved the download from the REST gateway to `admin_post`, because it
 * now renders before answering. The nonce requirement is unchanged and is the
 * same trap in the same place: a plain <a href> sends cookies and no header, so
 * without `_wpnonce` even a shop manager is user 0 and the button fails while
 * looking perfectly correct (D-025, D-028).
 */
aicake_check( 'the download link carries a nonce', true, (bool) preg_match( '#_wpnonce=[a-z0-9]+#', $cell ) );

/*
 * And the *preview* is still served through the REST gateway as an <img>, which
 * has the identical problem and a separate nonce.
 */
aicake_check( 'the preview still goes through the gateway', true, (bool) preg_match( '#/preview\?_wpnonce=[a-z0-9]+#', $cell ) );
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

aicake_check( 'a paid order is not moved on our account', 'processing', $bad_order->get_status() );

/*
 * D-048: no retry ladder, no attempt counter, no email. The person who pressed
 * the button is told why, on the screen they are standing at, and pressing it
 * again *is* the retry.
 */
$bad_error = '';
$bad_path  = $plugin->fulfilment()->ensure_print_file( $bad_id, $bad_item_id, $bad_error );

$bad_order = wc_get_order( $bad_id );
$bad_item  = $bad_order->get_item( $bad_item_id );

aicake_check( 'a failure does not move the order either', 'processing', $bad_order->get_status() );
aicake_check( 'returns no path', '', $bad_path );
aicake_check( 'and says why, in Lithuanian', true, '' !== $bad_error && ! str_contains( $bad_error, 'Exception' ) );
aicake_check( 'claims no print file', '', (string) $bad_item->get_meta( AiCake\WooCommerce\Fulfilment::META_PRINT ) );

/*
 * The failure the customer must not hear about from us. Since D-048 nothing is
 * written to the order at all, so this asserts the strongest available form:
 * not one note of any kind, customer-visible or otherwise.
 */
aicake_check(
	'and tells the customer nothing about it',
	array(),
	array_values( array_map(
		static fn( $n ) => $n->content,
		array_filter( wc_get_order_notes( array( 'order_id' => $bad_id ) ), static fn( $n ) => (bool) $n->customer_note )
	) )
);

/* ------------------------------------------------------------- recovery */

echo "\nrecovery\n";

$designs->update( $broken['id'], array( 'file_master' => $storage->store_master( $broken['public_id'], aicake_master() ) ) );

// Pressing the button again is the whole recovery mechanism.
$bad_error = '';
$bad_path  = $plugin->fulfilment()->ensure_print_file( $bad_id, $bad_item_id, $bad_error );

$bad_order = wc_get_order( $bad_id );

aicake_check( 'pressing download again produces the file', true, '' !== $bad_path && is_readable( $bad_path ) );
aicake_check( 'with no error left over', '', $bad_error );
aicake_check( 'with the status still untouched', 'processing', $bad_order->get_status() );

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

/*
 * Since D-047 the status assertion above is true of every order in the shop and
 * no longer distinguishes "left alone" from "handled". This does: a sale with
 * no design has nothing for the download button to act on.
 */
aicake_check( 'and has nothing to render', 0, aicake_render_order( $plain->get_id() ) );

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
