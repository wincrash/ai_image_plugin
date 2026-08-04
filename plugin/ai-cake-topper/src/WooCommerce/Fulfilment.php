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
use AiCake\Pipeline\FulfilPipeline;
use AiCake\Storage\OrderArchive;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The post-payment job system (PLAN.md §13.4, as amended by D-047).
 *
 * `woocommerce_order_status_processing` → one Action Scheduler job per line
 * item → render → archive → the print file appears on the order screen.
 *
 * ### It does not touch the order's status — D-047
 *
 * The shop runs the ordinary WooCommerce flow, moved by hand: on-hold →
 * processing → completed. This class used to drive five statuses of its own
 * through an approval workflow, which meant the plugin was quietly running a
 * *second* order process alongside the one the shop actually uses. Everything
 * it needs to say it now says in **private order notes**, which are
 * admin-only and email nobody.
 *
 * Anything here calling `update_status()` is a bug, not a feature.
 *
 * Two properties still matter more than the flow:
 *
 * **Idempotency.** A retry that re-runs a paid upscale costs money, and a
 * retry that re-archives already-moved files would find nothing to move. Every
 * job checks for an existing print file first and returns early.
 *
 * **It must never fail silently.** The customer has already paid. After the
 * last attempt the failure is written to the order, the shop is emailed, and
 * the order screen offers a retry button. With no status to go red, that email
 * is now the *only* thing that surfaces a paid order with no printable file —
 * so it carries more weight than it did, not less.
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
	 * Order meta: the "files are ready" note has been written.
	 *
	 * Under D-047 there is no status transition to be idempotent for us, and
	 * `finish_if_complete()` runs after every item — including the early
	 * return of an already-rendered one. Without this flag a four-item order
	 * collects four identical notes, and pressing retry adds more.
	 */
	public const META_NOTIFIED = '_aicake_files_ready';

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
			// An ordinary order with no AI items. Nothing to say about it.
			return;
		}

		// Private: the shop is told work has started, the customer is not
		// emailed, and the status stays wherever the shop put it (D-047).
		$order->add_order_note( __( 'Ruošiami spausdinimo failai.', 'ai-cake-topper' ), false );

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
	 * Say so, once, when every item has a print file.
	 *
	 * The note is the audit trail rather than the delivery mechanism — the
	 * files are on the order screen with a download button either way. Its
	 * value is the negative case: an order with no such note is one where
	 * something did not finish.
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

		if ( '' !== (string) $order->get_meta( self::META_NOTIFIED ) ) {
			return;
		}

		$order->update_meta_data( self::META_NOTIFIED, (string) time() );

		$order->add_order_note(
			sprintf(
				/* translators: %d: how many print files were produced */
				_n(
					'Spausdinimo failas paruoštas (%d).',
					'Spausdinimo failai paruošti (%d).',
					count( $items ),
					'ai-cake-topper'
				),
				count( $items )
			),
			false
		);

		$order->save();
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

		/*
		 * Private, so a paid order that cannot be produced does not announce
		 * itself to the customer before the shop has decided what to do about
		 * it. That decision — refund, reprint, phone call — is the shop's, and
		 * WooCommerce already has the tools for all three (D-047).
		 */
		$order->add_order_note(
			sprintf(
				/* translators: 1: order item name, 2: error message */
				__( 'Nepavyko paruošti „%1$s“: %2$s', 'ai-cake-topper' ),
				$item->get_name(),
				$message
			),
			false
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
	 * When the order was created, for the archive folder.
	 *
	 * @param WC_Order $order Order.
	 */
	private function created_ts( WC_Order $order ): ?int {
		$created = $order->get_date_created();

		return null === $created ? null : $created->getTimestamp();
	}
}
