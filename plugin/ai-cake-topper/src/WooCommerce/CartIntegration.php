<?php
/**
 * Carrying a design from the product page into the order.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\WooCommerce;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\PrintSpec;
use AiCake\Throttle\IdentityResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Add-to-cart validation, cart display, and the hand-off to the order.
 *
 * The ownership check is the important part. A design id is a 32-character
 * handle that appears in the customer's own markup, so nothing stops someone
 * pasting a different one — PLAN.md §16 requires ownership verified on
 * add-to-cart *and* on every file request, and this is the first half.
 */
class CartIntegration {

	public const CART_KEY = 'aicake_design';

	private DesignRepository $designs;

	private IdentityResolver $identity;

	/**
	 * @param DesignRepository $designs  Designs.
	 * @param IdentityResolver $identity Identity.
	 */
	public function __construct( DesignRepository $designs, IdentityResolver $identity ) {
		$this->designs  = $designs;
		$this->identity = $identity;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'attach' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'persist' ), 10, 4 );
		add_filter( 'woocommerce_cart_item_thumbnail', array( $this, 'thumbnail' ), 10, 3 );
	}

	/**
	 * Refuse to add a product that needs a design without a valid one.
	 *
	 * @param bool $passed     Whether validation has passed so far.
	 * @param int  $product_id Product being added.
	 */
	public function validate( bool $passed, int $product_id ): bool {
		if ( ! $passed ) {
			return false;
		}

		$spec = PrintSpec::for_product( $product_id );

		if ( ! $spec->enabled ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce validates the add-to-cart request itself.
		$public_id = isset( $_REQUEST[ self::CART_KEY ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::CART_KEY ] ) ) : '';

		if ( '' === $public_id ) {
			wc_add_notice(
				__( 'Pirmiausia sukurkite piešinį.', 'ai-cake-topper' ),
				'error'
			);

			return false;
		}

		$design = $this->find_owned( $public_id );

		if ( null === $design ) {
			/*
			 * Deliberately the same message whether the design does not exist,
			 * belongs to someone else, or never finished. Distinguishing them
			 * would confirm to a prober which ids are real.
			 */
			wc_add_notice(
				__( 'Šis piešinys nerastas. Sukurkite jį iš naujo.', 'ai-cake-topper' ),
				'error'
			);

			return false;
		}

		return true;
	}

	/**
	 * Attach the design to the cart line.
	 *
	 * @param array<string, mixed> $data       Existing cart item data.
	 * @param int                  $product_id Product being added.
	 * @return array<string, mixed>
	 */
	public function attach( array $data, int $product_id ): array {
		$spec = PrintSpec::for_product( $product_id );

		if ( ! $spec->enabled ) {
			return $data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$public_id = isset( $_REQUEST[ self::CART_KEY ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::CART_KEY ] ) ) : '';
		$design    = '' === $public_id ? null : $this->find_owned( $public_id );

		if ( null === $design ) {
			return $data;
		}

		$data[ self::CART_KEY ] = array(
			'public_id' => $public_id,
			'prompt'    => (string) $design['prompt_raw'],
		);

		/*
		 * Two identical products with different designs must be separate cart
		 * lines, or the second silently increments the first and one design is
		 * lost. WooCommerce keys lines by a hash of this array, so including
		 * the id is what keeps them apart.
		 */
		$data['unique_key'] = md5( $public_id );

		return $data;
	}

	/**
	 * Show the prompt in the cart and at checkout.
	 *
	 * @param array<int, array<string, mixed>> $items Existing item data.
	 * @param array<string, mixed>             $cart  Cart item.
	 * @return array<int, array<string, mixed>>
	 */
	public function display( array $items, array $cart ): array {
		if ( empty( $cart[ self::CART_KEY ]['prompt'] ) ) {
			return $items;
		}

		$items[] = array(
			'key'   => __( 'Piešinys', 'ai-cake-topper' ),
			'value' => wp_strip_all_tags( (string) $cart[ self::CART_KEY ]['prompt'] ),
		);

		return $items;
	}

	/**
	 * Show the customer's own preview as the cart thumbnail.
	 *
	 * @param string               $html    Existing thumbnail markup.
	 * @param array<string, mixed> $cart    Cart item.
	 * @param string               $cart_key Cart item key.
	 */
	public function thumbnail( string $html, array $cart, string $cart_key ): string {
		unset( $cart_key );

		if ( empty( $cart[ self::CART_KEY ]['public_id'] ) ) {
			return $html;
		}

		$url = rest_url( 'aicake/v1/file/' . rawurlencode( (string) $cart[ self::CART_KEY ]['public_id'] ) . '/preview' );

		return sprintf(
			'<img src="%s" alt="" style="max-width:100%%;height:auto" />',
			esc_url( $url )
		);
	}

	/**
	 * Copy the design onto the order line, so it survives the cart.
	 *
	 * @param \WC_Order_Item_Product $item          Order line.
	 * @param string                 $cart_item_key Cart key.
	 * @param array<string, mixed>   $values        Cart item.
	 * @param \WC_Order              $order         Order.
	 */
	public function persist( $item, string $cart_item_key, array $values, $order ): void {
		unset( $cart_item_key, $order );

		if ( empty( $values[ self::CART_KEY ]['public_id'] ) ) {
			return;
		}

		$public_id = (string) $values[ self::CART_KEY ]['public_id'];

		// Hidden meta: the id is an access handle, not something to print on
		// a packing slip.
		$item->add_meta_data( '_aicake_design', $public_id, true );

		$item->add_meta_data(
			__( 'Piešinys', 'ai-cake-topper' ),
			wp_strip_all_tags( (string) ( $values[ self::CART_KEY ]['prompt'] ?? '' ) ),
			true
		);
	}

	/**
	 * A finished design belonging to the current visitor.
	 *
	 * @param string $public_id Design handle.
	 * @return array<string, mixed>|null
	 */
	private function find_owned( string $public_id ): ?array {
		$design = $this->designs->find_by_public_id( $public_id );

		if ( null === $design ) {
			return null;
		}

		// An unfinished or rejected design must never reach an order.
		if ( DesignRepository::STATUS_DONE !== $design['status'] || '' === (string) $design['file_preview'] ) {
			return null;
		}

		$user_id = $this->identity->user_id();

		if ( 0 !== $user_id && (int) $design['user_id'] === $user_id ) {
			return $design;
		}

		$session = $this->identity->session_key();

		if ( '' !== $session && hash_equals( (string) $design['session_key'], $session ) ) {
			return $design;
		}

		return null;
	}
}
