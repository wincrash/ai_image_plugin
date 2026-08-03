<?php
/**
 * What the shop sees on an order.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Admin;

use AiCake\Domain\DesignRepository;
use AiCake\WooCommerce\Fulfilment;
use WC_Order;
use WC_Order_Item_Product;

defined( 'ABSPATH' ) || exit;

/**
 * The design column on the admin order screen (PLAN.md §13.2).
 *
 * This is the screen the shop actually works from, so it answers the three
 * questions somebody standing at a printer has, in this order: what does it
 * look like, is the file ready, and if not what went wrong. The retry button
 * exists because §13.4 requires a render failure to be recoverable without a
 * developer.
 */
class OrderScreen {

	/**
	 * The retry action, as an admin-post handler.
	 */
	private const RETRY_ACTION = 'aicake_retry_render';

	private DesignRepository $designs;

	private Fulfilment $fulfilment;

	/**
	 * @param DesignRepository $designs    Designs.
	 * @param Fulfilment       $fulfilment Rendering.
	 */
	public function __construct( DesignRepository $designs, Fulfilment $fulfilment ) {
		$this->designs    = $designs;
		$this->fulfilment = $fulfilment;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_admin_order_item_headers', array( $this, 'header' ) );
		add_action( 'woocommerce_admin_order_item_values', array( $this, 'value' ), 10, 3 );
		add_action( 'admin_post_' . self::RETRY_ACTION, array( $this, 'handle_retry' ) );
	}

	/**
	 * The column heading.
	 */
	public function header(): void {
		echo '<th class="aicake-design">' . esc_html__( 'Piešinys', 'ai-cake-topper' ) . '</th>';
	}

	/**
	 * One item's cell.
	 *
	 * @param mixed $product The product, or false.
	 * @param mixed $item    The order item.
	 * @param mixed $item_id The order item id.
	 */
	public function value( $product, $item, $item_id ): void {
		unset( $product );

		echo '<td class="aicake-design">';

		if ( ! $item instanceof WC_Order_Item_Product ) {
			echo '&mdash;</td>';

			return;
		}

		$public_id = (string) $item->get_meta( '_aicake_design' );

		if ( '' === $public_id ) {
			echo '&mdash;</td>';

			return;
		}

		$this->render_preview( $public_id );
		$this->render_state( $item, (int) $item_id );

		echo '</td>';
	}

	/**
	 * The thumbnail, through the gateway — never a direct file URL (§12.4).
	 *
	 * @param string $public_id Design handle.
	 */
	private function render_preview( string $public_id ): void {
		$design = $this->designs->find_by_public_id( $public_id );

		if ( null === $design ) {
			echo '<em>' . esc_html__( 'Piešinys nerastas.', 'ai-cake-topper' ) . '</em>';

			return;
		}

		printf(
			'<img src="%s" alt="" style="width:80px;height:auto;display:block;margin-bottom:4px" /><small>%s</small>',
			esc_url( $this->gateway_url( $public_id, 'preview' ) ),
			esc_html( wp_trim_words( (string) $design['prompt_raw'], 12 ) )
		);
	}

	/**
	 * A gateway URL that will actually authenticate.
	 *
	 * The nonce is in the query string because these are a plain `<img src>`
	 * and a plain `<a href>` — neither can send an `X-WP-Nonce` header. Without
	 * it WordPress's REST cookie check leaves the request as user 0, the
	 * capability test fails, and the shop's own download button answers 404
	 * while looking perfectly correct. That is the same trap as D-025, in a
	 * second place, and it was found the same way: by making the request.
	 *
	 * The nonce ages out after a day. This markup is never cached, so a reload
	 * is the cure and there is nothing to store.
	 *
	 * @param string $public_id Design handle.
	 * @param string $variant   preview | print.
	 */
	private function gateway_url( string $public_id, string $variant ): string {
		return add_query_arg(
			'_wpnonce',
			wp_create_nonce( 'wp_rest' ),
			rest_url( 'aicake/v1/file/' . rawurlencode( $public_id ) . '/' . $variant )
		);
	}

	/**
	 * Whether the print file is ready, and what to do about it.
	 *
	 * @param WC_Order_Item_Product $item    The order item.
	 * @param int                   $item_id The order item id.
	 */
	private function render_state( WC_Order_Item_Product $item, int $item_id ): void {
		$print = (string) $item->get_meta( Fulfilment::META_PRINT );
		$error = (string) $item->get_meta( Fulfilment::META_ERROR );

		if ( '' !== $print && is_readable( $print ) ) {
			$public_id = (string) $item->get_meta( '_aicake_design' );

			printf(
				'<a href="%s" class="button button-small">%s</a><br /><small>%s</small>',
				esc_url( $this->gateway_url( $public_id, 'print' ) ),
				esc_html__( 'Spausdinimo failas', 'ai-cake-topper' ),
				esc_html( size_format( (int) filesize( $print ) ) )
			);

			return;
		}

		if ( '' !== $error ) {
			printf(
				'<span style="color:#b32d2e">%s</span><br />',
				esc_html( $error )
			);
		} else {
			printf(
				'<span style="color:#996800">%s</span><br />',
				esc_html__( 'Ruošiama…', 'ai-cake-topper' )
			);
		}

		$order_id = (int) $item->get_order_id();

		printf(
			'<a href="%s" class="button button-small">%s</a>',
			esc_url(
				wp_nonce_url(
					admin_url( 'admin-post.php?action=' . self::RETRY_ACTION . '&order_id=' . $order_id . '&item_id=' . $item_id ),
					self::RETRY_ACTION . '_' . $item_id
				)
			),
			esc_html__( 'Bandyti dar kartą', 'ai-cake-topper' )
		);
	}

	/**
	 * Re-run one item's render.
	 */
	public function handle_retry(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$item_id  = isset( $_GET['item_id'] ) ? absint( wp_unslash( $_GET['item_id'] ) ) : 0;

		check_admin_referer( self::RETRY_ACTION . '_' . $item_id );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Neturite teisių.', 'ai-cake-topper' ) );
		}

		$order = wc_get_order( $order_id );

		if ( $order instanceof WC_Order ) {
			$item = $order->get_item( $item_id );

			if ( $item instanceof WC_Order_Item_Product ) {
				/*
				 * Clear the attempt counter first. Without this the retry
				 * inherits a count of 3 and gives up immediately, which reads
				 * as the button doing nothing — the failure mode most likely
				 * to make somebody conclude the plugin is broken.
				 */
				$item->delete_meta_data( Fulfilment::META_ATTEMPTS );
				$item->save();
			}

			// Run it here rather than scheduling: an admin who pressed a button
			// wants to see the result on the page they land on.
			$this->fulfilment->fulfil_item( $order_id, $item_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order_id ) );
		exit;
	}
}
