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
 * This is the screen the shop works from, and since D-048 it is the *only* one:
 * the whole plugin, from the shop's side, is a thumbnail and a download button.
 *
 * ### The button does the rendering
 *
 * There is no queue and no „Ruošiama…" state. Pressing Download serves the
 * archived print file if it exists and renders it on the spot if it does not —
 * measured at 0.75–1.1 s for a full A4 sheet at 300 DPI, which is not worth a
 * background worker, a retry ladder and a failure email to avoid.
 *
 * A failure is therefore reported to the person who pressed the button, on the
 * screen they are already looking at. Nothing is emailed: they are standing
 * there, and pressing it again is the retry.
 */
class OrderScreen {

	/**
	 * The download action, as an admin-post handler.
	 *
	 * `admin_post` rather than a plain link to the REST gateway because this
	 * one *does work* before answering — and because a plain link sends cookies
	 * and no nonce, which leaves the shop manager as user 0 and 404s while
	 * looking perfectly correct (D-028).
	 */
	private const DOWNLOAD_ACTION = 'aicake_download_print';

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
		add_action( 'admin_post_' . self::DOWNLOAD_ACTION, array( $this, 'handle_download' ) );
		add_action( 'admin_notices', array( $this, 'failure_notice' ) );
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
	 * The download button, and how big the file is if it already exists.
	 *
	 * The button reads the same either way. Whether the bytes are on disk
	 * already is our problem, not the shop's — and a button that changes its
	 * label depending on hidden state invites the question "so what does the
	 * other one do?".
	 *
	 * @param WC_Order_Item_Product $item    The order item.
	 * @param int                   $item_id The order item id.
	 */
	private function render_state( WC_Order_Item_Product $item, int $item_id ): void {
		$print = (string) $item->get_meta( Fulfilment::META_PRINT );

		printf(
			'<a href="%s" class="button button-small">%s</a>',
			esc_url(
				wp_nonce_url(
					admin_url(
						'admin-post.php?action=' . self::DOWNLOAD_ACTION
						. '&order_id=' . (int) $item->get_order_id() . '&item_id=' . $item_id
					),
					self::DOWNLOAD_ACTION . '_' . $item_id
				)
			),
			esc_html__( 'Atsisiųsti spausdinimui', 'ai-cake-topper' )
		);

		if ( '' !== $print && is_readable( $print ) ) {
			printf( '<br /><small>%s</small>', esc_html( size_format( (int) filesize( $print ) ) ) );
		}
	}

	/**
	 * Render if needed, then send the file.
	 */
	public function handle_download(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$item_id  = isset( $_GET['item_id'] ) ? absint( wp_unslash( $_GET['item_id'] ) ) : 0;

		check_admin_referer( self::DOWNLOAD_ACTION . '_' . $item_id );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Neturite teisių.', 'ai-cake-topper' ), '', array( 'response' => 403 ) );
		}

		$error = '';
		$path  = $this->fulfilment->ensure_print_file( $order_id, $item_id, $error );

		if ( '' === $path || ! is_readable( $path ) ) {
			/*
			 * Back to the order with the reason, rather than `wp_die()` on a
			 * blank page. The shop pressed a button expecting a file; the
			 * useful answer is why they did not get one, next to the button.
			 */
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'          => 'wc-orders',
						'action'        => 'edit',
						'id'            => $order_id,
						'aicake_failed' => rawurlencode( '' === $error ? __( 'Nepavyko.', 'ai-cake-topper' ) : $error ),
					),
					admin_url( 'admin.php' )
				)
			);

			exit;
		}

		$this->send( $path, $order_id, $item_id );
	}

	/**
	 * Stream the file as a download.
	 *
	 * `readfile()` rather than `file_get_contents()`: a 300 DPI A4 sheet is
	 * several megabytes and the render that produced it already peaked at
	 * ~326 MB. Reading it back into a string to echo it would be the largest
	 * allocation in the request, for nothing.
	 *
	 * @param string $path     Absolute path.
	 * @param int    $order_id Order id, for the filename.
	 * @param int    $item_id  Item id, for the filename.
	 */
	private function send( string $path, int $order_id, int $item_id ): void {
		nocache_headers();

		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . filesize( $path ) );
		header(
			'Content-Disposition: attachment; filename="'
			. sprintf( 'order-%d-item-%d-print.png', $order_id, $item_id ) . '"'
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private file, by design.
		readfile( $path );

		exit;
	}

	/**
	 * Show why a download did not produce a file.
	 */
	public function failure_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a message we put there.
		$message = isset( $_GET['aicake_failed'] ) ? sanitize_text_field( wp_unslash( $_GET['aicake_failed'] ) ) : '';

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
