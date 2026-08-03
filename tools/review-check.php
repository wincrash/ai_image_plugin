<?php
/**
 * The review queue: §10 layer 3, the only moderation layer that sees the image.
 *
 * Run inside the container, against the deployed copy:
 *
 *   docker compose exec -u www-data wordpress \
 *     wp eval-file /var/lib/aicake/review-check.php --path=/var/www/html
 *
 * **Run as the web user, never as root** (D-031) — this writes order notes and
 * moves order statuses.
 *
 * What is worth asserting here is not "the page renders". It is the three
 * things that decide whether a shop can trust the screen:
 *
 *   1. an order only leaves the queue by a decision, and only once;
 *   2. the decision is attributable and the customer is told why;
 *   3. the screen shows the *print* file, not the watermarked preview — an
 *      approval of the preview is an approval of an image nobody looked at.
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.Security.NonceVerification

use AiCake\Admin\ReviewQueue;
use AiCake\Domain\DesignRepository;
use AiCake\WooCommerce\OrderStatuses;

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

/**
 * A file that actually exists, because the screen now checks.
 *
 * The queue refuses to show an image whose file is missing — a column pointing
 * at nothing renders as an invisible broken image beside an Approve button, on
 * the one screen whose whole job is looking at the picture. So the fixture has
 * to put real bytes on disk or it is testing the wrong branch.
 *
 * @param string $name File name under the storage root.
 */
function aicake_fixture_file( string $name ): string {
	$plugin = AiCake\Plugin::instance();
	$image  = $plugin->images()->blank( 40, 40, false );

	if ( null === $image ) {
		return '';
	}

	$bytes = $plugin->images()->to_png( $image );
	$plugin->images()->free( $image );

	return $plugin->storage()->write( $plugin->storage()->session_path( 'review-check', $name ), $bytes );
}

/**
 * A design that has been through the whole pipeline, with a print file.
 *
 * @param string $verdict What moderation concluded.
 * @param string $layer   Which layer concluded it.
 */
function aicake_reviewable_design( string $verdict = 'review', string $layer = 'llm' ): array {
	$designs = AiCake\Plugin::instance()->designs();

	$id = $designs->create(
		array(
			'session_key'  => 'review-check',
			'ip_hash'      => 'review-check',
			'user_id'      => 1,
			'prompt_raw'   => 'linksmas ežiukas su balionu',
			'prompt_en'    => 'a cheerful hedgehog with a balloon',
			'aspect'       => '1:1',
			'format_type'  => 'circle',
			'format_mm'    => 150.0,
			'status'       => DesignRepository::STATUS_DONE,
			'provider'     => 'fal',
			'file_master'  => 'review-check-master.png',
			'file_preview' => aicake_fixture_file( 'review-check-preview.webp' ),
			'file_proof'   => aicake_fixture_file( 'review-check-proof.webp' ),
			'file_print'   => aicake_fixture_file( 'review-check-print.png' ),
			'moderation'   => wp_json_encode(
				array(
					'verdict'    => $verdict,
					'reasons'    => array( 'ambiguous_character' ),
					'categories' => array( 'copyright_character' => true, 'real_person' => false ),
					'confidence' => 0.61,
					'layer'      => $layer,
				)
			),
		)
	);

	return (array) $designs->find( $id );
}

/**
 * An order sitting in the queue, carrying that design.
 *
 * @param array<string, mixed> $design Design row.
 */
function aicake_waiting_order( array $design ): WC_Order {
	$order = wc_create_order();

	$product = wc_get_product( ( get_page_by_path( 'ai-paveikslelis', OBJECT, 'product' ) )->ID );

	$item_id = $order->add_product( $product, 1 );
	$item    = $order->get_item( $item_id );

	$item->add_meta_data( '_aicake_design', (string) $design['public_id'], true );
	$item->save();

	$order->set_billing_first_name( 'Emilija' );
	$order->set_billing_last_name( 'Testauskė' );
	$order->calculate_totals();
	$order->set_status( OrderStatuses::APPROVAL );
	$order->save();

	// Re-read, because that is what the screen does. An in-memory order can
	// carry item meta the database never got.
	return wc_get_order( $order->get_id() );
}

/**
 * Is this order in the queue?
 *
 * Asked with a large page size on purpose. `waiting()` shows the **oldest**
 * twenty, which is right for a shop — the customer who has waited longest is
 * served next — but it means a freshly created fixture sits behind whatever
 * backlog the testbed already has. Paging past it here keeps the assertion
 * about the query rather than about how many orders happen to be waiting.
 *
 * @param ReviewQueue $queue    The screen.
 * @param int         $order_id Order to look for.
 */
function aicake_in_queue( ReviewQueue $queue, int $order_id ): bool {
	foreach ( $queue->waiting( 1, 500 ) as $order ) {
		if ( $order->get_id() === $order_id ) {
			return true;
		}
	}

	return false;
}

/**
 * Decide an order the way the screen does — through `admin_post`.
 *
 * The nonce and the capability check are the point: a review screen that can be
 * driven without either is a review screen anyone on the internet can drive.
 *
 * @param int    $order_id Order.
 * @param string $decision approve | reject.
 * @param string $reason   Rejection reason.
 */
function aicake_decide( int $order_id, string $decision, string $reason = '' ): void {
	$_REQUEST = array(
		'action'   => ReviewQueue::ACTION,
		'order'    => $order_id,
		'decision' => $decision,
		'_wpnonce' => wp_create_nonce( ReviewQueue::ACTION . '_' . $order_id ),
	);

	$_POST['reason'] = $reason;

	$queue = new ReviewQueue( AiCake\Plugin::instance()->designs(), AiCake\Plugin::instance()->logger() );

	/*
	 * `handle()` ends in a redirect and `exit`. Caught rather than refactored:
	 * the exit is correct on a real request — it is what stops a refresh
	 * re-deciding — and a method that behaves differently under test is a
	 * method the test no longer proves anything about.
	 */
	add_filter( 'wp_redirect', 'aicake_stop_redirect', 1 );

	try {
		$queue->handle();
	} catch ( Exception $e ) {
		unset( $e );
	}

	remove_filter( 'wp_redirect', 'aicake_stop_redirect', 1 );
}

/**
 * Turn the redirect into an exception so the script survives it.
 */
function aicake_stop_redirect(): bool {
	throw new Exception( 'redirected' );
}

/* -------------------------------------------------------------------- run */

wp_set_current_user( 1 );

if ( null === WC()->session ) {
	WC()->initialize_session();
}

echo "\nWhat the queue shows\n";

$queue  = new ReviewQueue( AiCake\Plugin::instance()->designs(), AiCake\Plugin::instance()->logger() );
$design = aicake_reviewable_design();
$order  = aicake_waiting_order( $design );

aicake_check( 'the order is in the queue', true, aicake_in_queue( $queue, $order->get_id() ) );

$items = $queue->items( $order );

aicake_check( 'one reviewable item', 1, count( $items ) );
aicake_check( 'the Lithuanian prompt is there', 'linksmas ežiukas su balionu', (string) $items[0]['design']['prompt_raw'] );
aicake_check( 'and the English one', 'a cheerful hedgehog with a balloon', (string) $items[0]['design']['prompt_en'] );
aicake_check( 'the verdict is shown', 'review', (string) $items[0]['moderation']['verdict'] );
aicake_check( 'and which layer decided', 'llm', (string) $items[0]['moderation']['layer'] );
aicake_check( 'the format is named', true, false !== strpos( (string) $items[0]['format'], '15' ) );

/*
 * The image must be the print file. Approving a watermarked 800 px preview is
 * approving something other than what goes to the printer, which is precisely
 * the mistake this layer exists to prevent.
 */
aicake_check( 'the image is the print file', true, false !== strpos( (string) $items[0]['image'], '/print' ) );
aicake_check( 'and it carries a nonce (D-028)', true, false !== strpos( (string) $items[0]['image'], '_wpnonce=' ) );

/*
 * And a design whose files are gone offers no image at all rather than an
 * `<img>` that renders as nothing. An invisible broken image beside an Approve
 * button is the worst possible failure on this particular screen: it looks like
 * a picture nobody objected to.
 */
$ghost = aicake_reviewable_design();

AiCake\Plugin::instance()->designs()->update(
	(int) $ghost['id'],
	array(
		'file_print'   => '/var/lib/aicake/does-not-exist-print.png',
		'file_proof'   => '/var/lib/aicake/does-not-exist-proof.webp',
		'file_preview' => '/var/lib/aicake/does-not-exist-preview.webp',
	)
);

$ghost_items = $queue->items( aicake_waiting_order( (array) AiCake\Plugin::instance()->designs()->find( (int) $ghost['id'] ) ) );

aicake_check( 'a missing file offers no image', '', (string) $ghost_items[0]['image'] );

echo "\nApproving\n";

aicake_decide( $order->get_id(), 'approve' );

$order = wc_get_order( $order->get_id() );

aicake_check( 'the order moves to approved', OrderStatuses::APPROVED, $order->get_status() );
aicake_check( 'and leaves the queue', false, aicake_in_queue( $queue, $order->get_id() ) );

$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
$blob  = implode( ' ', array_map( static fn( $n ) => $n->content, $notes ) );

aicake_check( 'the decision is attributable', true, false !== strpos( $blob, 'Peržiūrėjo' ) );

/*
 * A second decision must do nothing. The Back button plus one click is the
 * normal way this happens, and a re-decision would look exactly as legitimate
 * as the first in the order notes.
 */
aicake_decide( $order->get_id(), 'reject', 'changed my mind' );

$order = wc_get_order( $order->get_id() );

aicake_check( 'a second decision is refused', OrderStatuses::APPROVED, $order->get_status() );

echo "\nRejecting\n";

$order2 = aicake_waiting_order( aicake_reviewable_design( 'block', 'blocklist' ) );

aicake_decide( $order2->get_id(), 'reject', 'piešinyje matomas žinomas personažas' );

$order2 = wc_get_order( $order2->get_id() );

aicake_check( 'the order moves to rejected', OrderStatuses::REJECTED, $order2->get_status() );

$notes2 = wc_get_order_notes( array( 'order_id' => $order2->get_id() ) );
$blob2  = implode( ' ', array_map( static fn( $n ) => $n->content, $notes2 ) );

aicake_check( 'the reason is recorded', true, false !== strpos( $blob2, 'žinomas personažas' ) );

$customer_notes = wc_get_order_notes(
	array(
		'order_id' => $order2->get_id(),
		'type'     => 'customer',
	)
);

aicake_check( 'the customer is told', true, count( $customer_notes ) > 0 );
aicake_check( 'and told why', true, false !== strpos( implode( ' ', array_map( static fn( $n ) => $n->content, $customer_notes ) ), 'žinomas personažas' ) );

/*
 * And no money moved. §10 asks for a refund on rejection; issuing one
 * automatically is irreversible, can be partial, and may need a conversation
 * first — so the screen records the decision and points at WooCommerce's own
 * refund form. If that ever changes, this assertion is the thing that says so
 * out loud rather than a shop discovering it from its bank.
 */
aicake_check( 'no refund was issued automatically', 0.0, (float) $order2->get_total_refunded() );

echo "\nWhat it refuses\n";

$order3 = aicake_waiting_order( aicake_reviewable_design() );

$_REQUEST = array(
	'action'   => ReviewQueue::ACTION,
	'order'    => $order3->get_id(),
	'decision' => 'approve',
	'_wpnonce' => 'not-a-real-nonce',
);

/*
 * `wp_die()` under WP-CLI prints and **exits the process**, which would end
 * this script before it could report anything — including the assertion that
 * the refusal happened. Swapping the handler for one that throws is the only
 * way to observe it; the production handler is untouched.
 */
add_filter( 'wp_die_handler', static fn() => static function () {
	throw new Exception( 'wp_die' );
} );

add_filter( 'wp_redirect', 'aicake_stop_redirect', 1 );

$refused = false;

try {
	( new ReviewQueue( AiCake\Plugin::instance()->designs(), AiCake\Plugin::instance()->logger() ) )->handle();
} catch ( Exception $e ) {
	$refused = 'wp_die' === $e->getMessage();
}

remove_filter( 'wp_redirect', 'aicake_stop_redirect', 1 );
remove_all_filters( 'wp_die_handler' );

aicake_check( 'a bad nonce is refused outright', true, $refused );

$order3 = wc_get_order( $order3->get_id() );

aicake_check( 'a bad nonce decides nothing', OrderStatuses::APPROVAL, $order3->get_status() );

printf(
	"\n%d passed, %d failed\n\n",
	(int) $GLOBALS['aicake_pass'],
	(int) $GLOBALS['aicake_fail']
);
