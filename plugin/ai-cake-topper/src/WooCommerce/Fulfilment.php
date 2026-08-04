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
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The print file, made when somebody asks for it — D-048.
 *
 * ### There is no background worker any more
 *
 * This used to be the §13.4 job system: a status transition queued one Action
 * Scheduler job per line item, which rendered, archived, retried three times on
 * failure, emailed the shop and left the order screen showing „Ruošiama…" until
 * it finished.
 *
 * All of that existed to keep a slow render off the request. **The render is
 * not slow.** Measured on the testbed against real designs: 0.75–1.1 s for a
 * full A4 sheet at 300 DPI. Ruslan's own conclusion, and he is right — a
 * download button that renders on the spot is the whole feature, and it deletes
 * the queue, the retries, the attempt counters, the „Ruošiama…" state, the
 * retry button, the failure email and every order note along with it.
 *
 * What remains is one question, asked by the person who wants the file:
 * `ensure_print_file()` — is it on disk, and if not, make it.
 *
 * ### It still does not touch the order's status (D-047)
 *
 * The shop moves orders by hand. Nothing here calls `update_status()` or writes
 * an order note, and nothing here emails anybody.
 *
 * ### Idempotency is now the cheap kind
 *
 * A render costs money only in the upscale, and the production upscaler is GD
 * doing bicubic in PHP — free. Even so the archived file is checked first, so
 * pressing the button twice serves the same bytes rather than making them
 * again: the archive is what the order screen links to and what the shop keeps.
 */
class Fulfilment {

	/**
	 * Item meta: where the print file ended up. Also the idempotency key.
	 */
	public const META_PRINT = '_aicake_print_file';

	private DesignRepository $designs;

	private FulfilPipeline $pipeline;

	private OrderArchive $archive;

	private Logger $logger;

	/**
	 * @param DesignRepository $designs  Designs.
	 * @param FulfilPipeline   $pipeline Rendering.
	 * @param OrderArchive     $archive  Permanent storage.
	 * @param Logger           $logger   Logging.
	 */
	public function __construct(
		DesignRepository $designs,
		FulfilPipeline $pipeline,
		OrderArchive $archive,
		Logger $logger
	) {
		$this->designs  = $designs;
		$this->pipeline = $pipeline;
		$this->archive  = $archive;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 *
	 * One filter. There is no longer anything to do when an order is paid —
	 * the file is made when the shop asks for it.
	 */
	public function register(): void {
		// §12.6: without this, "Order again" copies the line item but not the
		// design, and the shop prints a blank topper for a repeat customer.
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'carry_design_to_reorder' ), 10, 3 );
	}

	/**
	 * The print file for one line item, rendering it if it does not exist yet.
	 *
	 * @param int $order_id Order id.
	 * @param int $item_id  Order item id.
	 *
	 * @return string Absolute path, or '' with the reason in `$error`.
	 */
	public function ensure_print_file( int $order_id, int $item_id, string &$error = '' ): string {
		$error = '';
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			$error = __( 'Užsakymas nerastas.', 'ai-cake-topper' );

			return '';
		}

		$item = $order->get_item( $item_id );

		if ( ! $item instanceof WC_Order_Item_Product ) {
			$error = __( 'Prekė nerasta.', 'ai-cake-topper' );

			return '';
		}

		$existing = (string) $item->get_meta( self::META_PRINT );

		if ( '' !== $existing && is_readable( $existing ) ) {
			return $existing;
		}

		$public_id = (string) $item->get_meta( '_aicake_design' );
		$design    = '' === $public_id ? null : $this->designs->find_by_public_id( $public_id );

		if ( null === $design ) {
			$error = __( 'Piešinys nerastas.', 'ai-cake-topper' );

			return '';
		}

		if ( ! is_readable( (string) $design['file_master'] ) ) {
			$error = __( 'Trūksta originalaus piešinio failo.', 'ai-cake-topper' );

			return '';
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
			$error = __( 'Nepavyko paruošti spausdinimo failo.', 'ai-cake-topper' );

			return '';
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
			$error = __( 'Nepavyko įrašyti spausdinimo failo.', 'ai-cake-topper' );

			return '';
		}

		$item->update_meta_data( self::META_PRINT, $path );
		$item->save();

		$this->logger->info(
			'Print file rendered on request.',
			array(
				'order' => $order_id,
				'item'  => $item_id,
				'size'  => $print->describe(),
			)
		);

		return $path;
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
	public function design_items( WC_Order $order ): array {
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
