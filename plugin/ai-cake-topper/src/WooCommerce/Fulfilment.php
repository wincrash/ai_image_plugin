<?php
/**
 * Turning a paid order into print files.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\WooCommerce;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\PrintSpec;
use AiCake\Domain\TextLayer;
use AiCake\Domain\TextSpec;
use AiCake\Pipeline\FulfilPipeline;
use AiCake\Storage\OrderArchive;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The post-payment job system (PLAN.md §13.4).
 *
 * `woocommerce_order_status_processing` → one Action Scheduler job per line
 * item → render → archive → when *every* item is done, move the order to
 * awaiting-approval.
 *
 * Two properties matter more than the flow:
 *
 * **Idempotency.** A retry that re-runs a paid upscale costs money, and a
 * retry that re-archives already-moved files would find nothing to move. Every
 * job checks for an existing print file first and returns early.
 *
 * **It must never fail silently.** The customer has already paid. After the
 * last attempt the order goes to `aicake-failed`, the admin is emailed, and
 * the order screen offers a retry button — rather than an order sitting in
 * `processing` forever with nobody aware.
 */
class Fulfilment {

	/**
	 * One item's render.
	 */
	public const HOOK = 'aicake_fulfil_item';

	/**
	 * Action Scheduler group, so these are filterable in its admin screen.
	 */
	public const GROUP = 'ai-cake-topper';

	/**
	 * Item meta: where the print file ended up. Also the idempotency key.
	 */
	public const META_PRINT = '_aicake_print_file';

	/**
	 * Item meta: how many attempts this item has had.
	 */
	public const META_ATTEMPTS = '_aicake_render_attempts';

	/**
	 * Item meta: why the last attempt failed.
	 */
	public const META_ERROR = '_aicake_render_error';

	/**
	 * Give up after this many. Three is the same ceiling the generation queue
	 * uses, for the same reason: a fourth attempt at a genuinely broken input
	 * is a loop, not a recovery.
	 */
	private const MAX_ATTEMPTS = 3;

	private DesignRepository $designs;

	private FulfilPipeline $pipeline;

	private OrderArchive $archive;

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param DesignRepository $designs  Designs.
	 * @param FulfilPipeline   $pipeline Rendering.
	 * @param OrderArchive     $archive  Permanent storage.
	 * @param Settings         $settings Configuration.
	 * @param Logger           $logger   Logging.
	 */
	public function __construct(
		DesignRepository $designs,
		FulfilPipeline $pipeline,
		OrderArchive $archive,
		Settings $settings,
		Logger $logger
	) {
		$this->designs  = $designs;
		$this->pipeline = $pipeline;
		$this->archive  = $archive;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'start' ), 10, 2 );
		add_action( self::HOOK, array( $this, 'fulfil_item' ), 10, 2 );

		// §12.6: without this, "Order again" copies the line item but not the
		// design, and the shop prints a blank topper for a repeat customer.
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'carry_design_to_reorder' ), 10, 3 );
	}

	/**
	 * Queue a render for every AI line item on a freshly paid order.
	 *
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order, when WooCommerce passes one.
	 */
	public function start( int $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$queued = 0;

		foreach ( $this->design_items( $order ) as $item_id => $item ) {
			unset( $item );

			if ( $this->schedule( $order_id, (int) $item_id ) ) {
				++$queued;
			}
		}

		if ( 0 === $queued ) {
			// An ordinary order with no AI items. Leave it entirely alone —
			// hijacking the status of a normal sale would be a nasty surprise.
			return;
		}

		$order->update_status(
			OrderStatuses::RENDERING,
			__( 'Ruošiami spausdinimo failai.', 'ai-cake-topper' )
		);

		$this->logger->info(
			'Fulfilment queued.',
			array(
				'order' => $order_id,
				'items' => $queued,
			)
		);
	}

	/**
	 * Render and archive one line item.
	 *
	 * @param int $order_id Order id.
	 * @param int $item_id  Order item id.
	 */
	public function fulfil_item( int $order_id, int $item_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			$this->logger->warning( 'Fulfilment skipped: no such order.', array( 'order' => $order_id ) );

			return;
		}

		$item = $order->get_item( $item_id );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		// Idempotency. Action Scheduler does not retry on its own — the retries
		// below are ours — but a job can still arrive twice: an admin pressing
		// retry, a duplicated status transition, a sweep catching up.
		$existing = (string) $item->get_meta( self::META_PRINT );

		if ( '' !== $existing && is_readable( $existing ) ) {
			$this->finish_if_complete( $order );

			return;
		}

		$public_id = (string) $item->get_meta( '_aicake_design' );
		$design    = '' === $public_id ? null : $this->designs->find_by_public_id( $public_id );

		if ( null === $design ) {
			$this->fail( $order, $item, __( 'Piešinys nerastas.', 'ai-cake-topper' ), false );

			return;
		}

		/*
		 * `for_design()`, not `for_product()`. Under D-035 the format is a
		 * wizard choice recorded on the design, and product meta only answers
		 * when the design did not — which `for_design()` already handles by
		 * falling through. Reading the product first meant a wizard design
		 * printed at whatever geometry the single AI product happened to
		 * carry, and worse, the text layer would then be measured against a
		 * canvas it was never authored for.
		 */
		$spec  = PrintSpec::for_design( $design );
		$print = $this->pipeline->render(
			(string) $design['file_master'],
			$spec,
			$this->text_spec( $design ),
			TextLayer::from_design( $design )
		);

		if ( null === $print ) {
			$this->fail( $order, $item, __( 'Nepavyko paruošti spausdinimo failo.', 'ai-cake-topper' ), true );

			return;
		}

		$path = $this->archive->archive(
			$design,
			$print,
			$spec,
			$order_id,
			$item_id,
			array(
				'order_number' => $order->get_order_number(),
				'product_name' => $item->get_name(),
				'created_ts'   => $this->created_ts( $order ),
			)
		);

		if ( '' === $path ) {
			$this->fail( $order, $item, __( 'Nepavyko įrašyti spausdinimo failo.', 'ai-cake-topper' ), true );

			return;
		}

		$item->update_meta_data( self::META_PRINT, $path );
		$item->delete_meta_data( self::META_ERROR );
		$item->save();

		$this->logger->info(
			'Item fulfilled.',
			array(
				'order' => $order_id,
				'item'  => $item_id,
				'size'  => $print->describe(),
			)
		);

		$this->finish_if_complete( $order );
	}

	/**
	 * Move the order on once every item has a print file.
	 *
	 * @param WC_Order $order Order.
	 */
	private function finish_if_complete( WC_Order $order ): void {
		$items = $this->design_items( $order );

		if ( array() === $items ) {
			return;
		}

		foreach ( $items as $item ) {
			$path = (string) $item->get_meta( self::META_PRINT );

			if ( '' === $path || ! is_readable( $path ) ) {
				return;
			}
		}

		/*
		 * Only from a state that is genuinely still upstream of approval.
		 * `aicake-failed` is in the list because a successful retry has to be
		 * able to lift an order back out of it — leaving a fully rendered
		 * order marked failed is exactly the silent-failure §13.4 forbids.
		 * Anything further along is left alone: an admin may have approved it
		 * while the last item was still rendering, and dragging that backwards
		 * would un-approve work somebody already checked.
		 */
		$upstream = array( OrderStatuses::RENDERING, OrderStatuses::FAILED, 'processing' );

		if ( ! $order->has_status( $upstream ) ) {
			return;
		}

		$order->update_status(
			OrderStatuses::APPROVAL,
			__( 'Spausdinimo failai paruošti. Laukiama patvirtinimo.', 'ai-cake-topper' )
		);
	}

	/**
	 * Record a failure, and either retry or give up.
	 *
	 * @param WC_Order              $order     Order.
	 * @param WC_Order_Item_Product $item      Item.
	 * @param string                $message   Customer-safe reason.
	 * @param bool                  $retryable Whether another attempt could help.
	 */
	private function fail( WC_Order $order, WC_Order_Item_Product $item, string $message, bool $retryable ): void {
		$attempts = (int) $item->get_meta( self::META_ATTEMPTS ) + 1;

		$item->update_meta_data( self::META_ATTEMPTS, $attempts );
		$item->update_meta_data( self::META_ERROR, $message );
		$item->save();

		$this->logger->error(
			'Fulfilment attempt failed.',
			array(
				'order'    => $order->get_id(),
				'item'     => $item->get_id(),
				'attempt'  => $attempts,
				'message'  => $message,
			)
		);

		if ( $retryable && $attempts < self::MAX_ATTEMPTS ) {
			// Backing off in minutes, not seconds: the plausible causes are a
			// full disk or a provider outage, and neither clears in ten seconds.
			$this->schedule( $order->get_id(), $item->get_id(), 60 * $attempts );

			return;
		}

		$order->update_status(
			OrderStatuses::FAILED,
			sprintf(
				/* translators: 1: order item name, 2: error message */
				__( 'Nepavyko paruošti „%1$s“: %2$s', 'ai-cake-topper' ),
				$item->get_name(),
				$message
			)
		);

		$this->notify_admin( $order, $item, $message );
	}

	/**
	 * Tell somebody. An order that has been paid for and cannot be produced is
	 * the one failure mode that must never wait to be noticed.
	 *
	 * @param WC_Order              $order   Order.
	 * @param WC_Order_Item_Product $item    Item.
	 * @param string                $message Reason.
	 */
	private function notify_admin( WC_Order $order, WC_Order_Item_Product $item, string $message ): void {
		$to = (string) $this->settings->get( 'admin_email', get_option( 'admin_email' ) );

		if ( '' === $to ) {
			return;
		}

		wp_mail(
			$to,
			sprintf(
				/* translators: %s: order number */
				__( 'Nepavyko paruošti užsakymo %s spausdinimo failo', 'ai-cake-topper' ),
				$order->get_order_number()
			),
			sprintf(
				/* translators: 1: item name, 2: error message, 3: admin URL */
				__( "Užsakymas: %1\$s\nKlaida: %2\$s\n\n%3\$s", 'ai-cake-topper' ),
				$item->get_name(),
				$message,
				$order->get_edit_order_url()
			)
		);
	}

	/**
	 * Schedule one item's render.
	 *
	 * @param int $order_id Order id.
	 * @param int $item_id  Item id.
	 * @param int $delay    Seconds from now.
	 * @return bool Whether anything was scheduled.
	 */
	private function schedule( int $order_id, int $item_id, int $delay = 0 ): bool {
		$args = array( $order_id, $item_id );

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			/*
			 * Action Scheduler ships with WooCommerce, so this is close to
			 * unreachable — but running the render inline is a far better
			 * failure than dropping a paid order, and the caller is already
			 * off the customer's request by this point.
			 */
			$this->fulfil_item( $order_id, $item_id );

			return true;
		}

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
			return true;
		}

		as_schedule_single_action( time() + max( 0, $delay ), self::HOOK, $args, self::GROUP );

		return true;
	}

	/**
	 * Copy the design reference when a customer reorders (§12.6).
	 *
	 * WooCommerce copies line items but knows nothing about our meta, so
	 * without this the repeat customer gets an empty prompt field and — worse —
	 * add-to-cart validation refuses the order they just tried to repeat.
	 *
	 * @param array<string, mixed> $data  Cart item data being built.
	 * @param object               $item  The original order item.
	 * @param WC_Order             $order The original order.
	 * @return array<string, mixed>
	 */
	public function carry_design_to_reorder( array $data, $item, $order ): array {
		unset( $order );

		if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) {
			return $data;
		}

		$public_id = (string) $item->get_meta( '_aicake_design' );

		if ( '' === $public_id ) {
			return $data;
		}

		$design = $this->designs->find_by_public_id( $public_id );

		if ( null === $design ) {
			return $data;
		}

		$data[ CartIntegration::CART_KEY ] = array(
			'public_id' => $public_id,
			'prompt'    => (string) $design['prompt_raw'],
		);

		$data['unique_key'] = md5( $public_id );

		return $data;
	}

	/**
	 * The line items that carry a design.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, WC_Order_Item_Product>
	 */
	private function design_items( WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			if ( '' !== (string) $item->get_meta( '_aicake_design' ) ) {
				$items[ (int) $item_id ] = $item;
			}
		}

		return $items;
	}

	/**
	 * The stored text layer, if the customer asked for one.
	 *
	 * @param array<string, mixed> $design Design row.
	 */
	private function text_spec( array $design ): ?TextSpec {
		$payload = (string) $design['text_payload'];

		if ( '' === $payload ) {
			return null;
		}

		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) || empty( $decoded['text'] ) ) {
			return null;
		}

		/*
		 * A D-033 layer, not a TextSpec. The two share this column while the
		 * browser editor is being built, and they are told apart by `path` —
		 * only a layer has one. A layer is composited by the pipeline instead;
		 * see `FulfilPipeline::composite_layer()`.
		 *
		 * This discrimination is load-bearing rather than tidy. **Both shapes
		 * carry a `text` key**, so without it a layer falls straight through
		 * into `TextSpec::from_array()` and the whole string renders through
		 * the old server-side path with every default it never set: bottom
		 * placement, white, auto-fit. Twelve cupcakes would each print all
		 * twelve names across the bottom, on top of the composited layer, and
		 * the order would look successful.
		 */
		if ( isset( $decoded['path'] ) ) {
			return null;
		}

		// from_array(), not the constructor: it is the one place that validates
		// the placement and the colours, and the print file is the last moment
		// to discover a stored payload is malformed.
		return TextSpec::from_array( $decoded );
	}

	/**
	 * When the order was created, for the archive folder.
	 *
	 * @param WC_Order $order Order.
	 */
	private function created_ts( WC_Order $order ): ?int {
		$created = $order->get_date_created();

		return null === $created ? null : $created->getTimestamp();
	}
}
