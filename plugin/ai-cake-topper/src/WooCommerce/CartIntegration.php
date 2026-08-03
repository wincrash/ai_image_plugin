<?php
/**
 * Carrying a design from the product page into the order.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\WooCommerce;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\PrintSpec;
use AiCake\Frontend\Wizard;
use AiCake\Throttle\IdentityResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Add-to-cart validation, cart display, and the hand-off to the order.
 *
 * Two things here are security controls rather than plumbing.
 *
 * **Ownership.** A design id is a 32-character handle that appears in the
 * customer's own markup, so nothing stops someone pasting a different one.
 * PLAN.md §16 requires ownership verified on add-to-cart *and* on every file
 * request; this is the first half.
 *
 * **Whether AI was used.** That answer decides a €1 surcharge, and it is
 * derived here from whether the design really has a generated image — never
 * read from the request. The Fields Factory field is an ordinary visible radio
 * on the product page, so a customer could otherwise answer it themselves: use
 * AI and not pay, or pay and not use it. Hiding the field would be
 * presentation; deriving the value is the control (D-036).
 */
class CartIntegration {

	public const CART_KEY = 'aicake_design';

	private DesignRepository $designs;

	private IdentityResolver $identity;

	private FieldsFactory $fields;

	/**
	 * @param DesignRepository $designs  Designs.
	 * @param IdentityResolver $identity Identity.
	 * @param FieldsFactory    $fields   Fields Factory reader, for the AI field key.
	 */
	public function __construct( DesignRepository $designs, IdentityResolver $identity, FieldsFactory $fields ) {
		$this->designs  = $designs;
		$this->identity = $identity;
		$this->fields   = $fields;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 2 );

		/*
		 * Priority 5, and it matters. Fields Factory's persister mines
		 * `$_REQUEST` at priority 10 on this same hook, so the derived AI
		 * answer has to be in place before it runs. Sharing priority 10 would
		 * work today only because our plugin happens to register first —
		 * an ordering nobody declared and a plugin update can change.
		 */
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'attach' ), 5, 2 );
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

		if ( ! $this->requires_design( $product_id ) ) {
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
	 * Whether this product may not be bought without a design.
	 *
	 * Two ways to be one, because D-035 changed where format lives without
	 * retiring what came before. The per-size products carry `_aicake_*` meta
	 * and answer through `PrintSpec`. **The single AI product carries none** —
	 * format is a wizard choice recorded on the design row — so asking the
	 * product would answer false for the very product the wizard sells, and the
	 * whole hand-off would fall through as though it were an ordinary sale.
	 *
	 * @param int $product_id Product being added.
	 */
	private function requires_design( int $product_id ): bool {
		if ( PrintSpec::for_product( $product_id )->enabled ) {
			return true;
		}

		return $product_id > 0 && $product_id === Wizard::product_id();
	}

	/**
	 * Answer "was AI used?" from the design, and put that answer in the request.
	 *
	 * The €1 surcharge keys off a Fields Factory radio, and WCFF mines
	 * `$_REQUEST[ <field key> ]` at priority 10 on `woocommerce_add_cart_item_data`
	 * to decide what to charge, display, and write to the order. Writing the
	 * value there first is what makes WCFF price the truth rather than whatever
	 * arrived from the browser — and it means we still write no pricing code
	 * (D-036).
	 *
	 * **Overwritten, not validated.** A posted flag about whether money was
	 * spent cannot be trusted even enough to check: the wizard does not post
	 * this field at all, and a customer posting it by hand from the product
	 * page has it replaced. The evidence is a provider having produced a master.
	 *
	 * A missing or unowned design settles to `ne` rather than being left alone,
	 * because "left alone" means whatever the customer typed.
	 *
	 * @param array<string, mixed>|null $design The owned design row, or null.
	 */
	private function settle_ai_field( ?array $design ): void {
		$key = $this->fields->field_key( Wizard::AI_LABEL );

		if ( null === $key ) {
			// No such field configured. The shop simply does not surcharge for
			// AI, which is a pricing decision and not our business (D-036).
			return;
		}

		$_REQUEST[ $key ] = ( null !== $design && $this->used_ai( $design ) ) ? 'taip' : 'ne';
	}

	/**
	 * Did an image provider actually generate this design?
	 *
	 * Both halves are required. A master with no provider is what an uploaded
	 * photo would look like — parked, but the surcharge must not apply to it
	 * when it arrives — and a provider with no master is a generation that
	 * failed.
	 *
	 * @param array<string, mixed> $design The design row.
	 */
	private function used_ai( array $design ): bool {
		return '' !== (string) ( $design['provider'] ?? '' )
			&& '' !== (string) ( $design['file_master'] ?? '' );
	}

	/**
	 * Attach the design to the cart line.
	 *
	 * @param array<string, mixed> $data       Existing cart item data.
	 * @param int                  $product_id Product being added.
	 * @return array<string, mixed>
	 */
	public function attach( array $data, int $product_id ): array {
		if ( ! $this->requires_design( $product_id ) ) {
			return $data;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$public_id = isset( $_REQUEST[ self::CART_KEY ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::CART_KEY ] ) ) : '';
		$design    = '' === $public_id ? null : $this->find_owned( $public_id );

		/*
		 * Settled here rather than during validation, because **`WC_Cart::
		 * add_to_cart()` does not apply `woocommerce_add_to_cart_validation`**
		 * — only the form handler, AJAX and the Store API do. Deriving the fee
		 * there would mean any other route into the cart charges nothing for
		 * AI, silently and in the shop's disfavour. This hook runs on every
		 * route, including a plain `add_to_cart()` call.
		 */
		$this->settle_ai_field( $design );

		if ( null === $design ) {
			return $data;
		}

		$data[ self::CART_KEY ] = array(
			'public_id' => $public_id,
			'prompt'    => (string) $design['prompt_raw'],
			/*
			 * The format in words. Under D-035 it is a property of the design
			 * rather than of the product, so without this the cart line, the
			 * confirmation email and the packing slip all read "Valgomas
			 * paveikslėlis (AI)" whether the customer bought one 20 cm topper
			 * or thirty-five cupcake circles.
			 */
			'format'    => $this->format_label( $design ),
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
		if ( empty( $cart[ self::CART_KEY ] ) ) {
			return $items;
		}

		if ( ! empty( $cart[ self::CART_KEY ]['format'] ) ) {
			$items[] = array(
				'key'   => __( 'Formatas', 'ai-cake-topper' ),
				'value' => wp_strip_all_tags( (string) $cart[ self::CART_KEY ]['format'] ),
			);
		}

		if ( ! empty( $cart[ self::CART_KEY ]['prompt'] ) ) {
			$items[] = array(
				'key'   => __( 'Piešinys', 'ai-cake-topper' ),
				'value' => wp_strip_all_tags( (string) $cart[ self::CART_KEY ]['prompt'] ),
			);
		}

		return $items;
	}

	/**
	 * What the customer chose, in the words the wizard offered it in.
	 *
	 * Read from `FormatCatalogue` rather than composed here, so the cart says
	 * exactly what step 1 said. A design whose format has since been withdrawn
	 * from sale returns nothing rather than a guess — it is still perfectly
	 * printable (§12.6), and a wrong label is worse than none on a line the
	 * customer is about to pay for.
	 *
	 * @param array<string, mixed> $design The design row.
	 */
	private function format_label( array $design ): string {
		$type = (string) ( $design['format_type'] ?? '' );

		if ( '' === $type ) {
			return '';
		}

		$option = FormatCatalogue::find( $type, (float) ( $design['format_mm'] ?? 0.0 ) );

		return null === $option ? '' : (string) $option['label'];
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

		$format = (string) ( $values[ self::CART_KEY ]['format'] ?? '' );

		if ( '' !== $format ) {
			$item->add_meta_data( __( 'Formatas', 'ai-cake-topper' ), wp_strip_all_tags( $format ), true );
		}

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
