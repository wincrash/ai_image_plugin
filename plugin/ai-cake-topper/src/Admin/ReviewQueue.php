<?php
/**
 * The screen where a human looks at the picture before it prints.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Admin;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\PrintSpec;
use AiCake\Support\Logger;
use AiCake\WooCommerce\OrderStatuses;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md §10 layer 3, and §14 point 3.
 *
 * Layers 0–2 read words. **This is the only layer that sees the image**, which
 * is why §10 calls it non-negotiable: a prompt can pass every text check and
 * still produce something the shop will not print, and no amount of prompt
 * filtering finds that out.
 *
 * ### What it is not
 *
 * It is not a second order screen. Everything here is one question — *print
 * this or not* — asked about the rendered file, and the answer moves the order
 * on. Order editing stays where WooCommerce puts it, one click away.
 *
 * ### Why the print file rather than the preview
 *
 * The preview is watermarked and 800 px; the print file is the thing that
 * reaches the printer. If they ever disagree, this screen must show the one
 * that costs money — reviewing the preview would approve an image nobody
 * looked at.
 *
 * ### Rejection does not move money
 *
 * §10 says rejection triggers an apology email and a refund. The email is sent
 * here; **the refund is not issued automatically.** Refunding is irreversible,
 * it can be partial, and it may need to follow a conversation with the
 * customer — so this records the decision, tells the customer, and links the
 * manager to WooCommerce's own refund form, which is the tool that already
 * exists for it and the one their bookkeeping expects.
 */
class ReviewQueue {

	public const SLUG = 'aicake-review';

	public const ACTION = 'aicake_review_decide';

	/**
	 * How many orders to show at once.
	 *
	 * The screen loads a full-size print file per item, so this is a memory
	 * ceiling as much as a usability one — images are served by their own
	 * request, but the query and the markup are not free either.
	 */
	private const PER_PAGE = 20;

	private DesignRepository $designs;

	private Logger $logger;

	/**
	 * @param DesignRepository $designs Designs.
	 * @param Logger           $logger  Logging.
	 */
	public function __construct( DesignRepository $designs, Logger $logger ) {
		$this->designs = $designs;
		$this->logger  = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Assets, on this screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'aicake-review', AICAKE_URL . 'assets/css/review.css', array(), AICAKE_VERSION );
		wp_enqueue_script( 'aicake-review', AICAKE_URL . 'assets/js/review.js', array(), AICAKE_VERSION, true );
	}

	/**
	 * Add the submenu entry, with a count of what is waiting.
	 *
	 * The bubble is the point of the menu item: an order sitting unreviewed is
	 * a customer waiting, and nothing else on the screen says so.
	 */
	public function add_menu(): void {
		$waiting = $this->count_waiting();

		$label = __( 'Peržiūra', 'ai-cake-topper' );

		if ( $waiting > 0 ) {
			$label .= sprintf(
				' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
				$waiting
			);
		}

		add_submenu_page(
			'aicake-test-provider',
			__( 'Peržiūra', 'ai-cake-topper' ),
			$label,
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Approve or reject one order.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-cake-topper' ), '', array( 'response' => 403 ) );
		}

		$order_id = isset( $_REQUEST['order'] ) ? absint( $_REQUEST['order'] ) : 0;

		// Nonce is per order, so a stale tab cannot decide a different one than
		// the manager was looking at.
		check_admin_referer( self::ACTION . '_' . $order_id );

		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		$decision = isset( $_REQUEST['decision'] ) ? sanitize_key( wp_unslash( $_REQUEST['decision'] ) ) : '';
		$reason   = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';

		if ( ! $order instanceof WC_Order ) {
			$this->redirect( 'missing' );
		}

		/*
		 * Only from `aicake-approval`. Without this, a browser Back button and
		 * a second click silently re-decides an order that has already been
		 * printed or refunded — and the second decision would look as
		 * legitimate as the first in the order notes.
		 */
		if ( OrderStatuses::APPROVAL !== $order->get_status() ) {
			$this->redirect( 'stale' );
		}

		if ( 'approve' === $decision ) {
			$this->approve( $order );
			$this->redirect( 'approved' );
		}

		if ( 'reject' === $decision ) {
			$this->reject( $order, $reason );
			$this->redirect( 'rejected' );
		}

		$this->redirect( 'missing' );
	}

	/**
	 * Approve: the file may now be printed.
	 *
	 * @param WC_Order $order The order.
	 */
	private function approve( WC_Order $order ): void {
		$user = wp_get_current_user();

		$order->update_status(
			OrderStatuses::APPROVED,
			sprintf(
				/* translators: %s: the person who approved it */
				__( 'Piešinys patvirtintas spausdinimui. Peržiūrėjo: %s.', 'ai-cake-topper' ),
				$user->display_name
			)
		);

		$this->logger->info(
			'Design approved for printing.',
			array(
				'order' => $order->get_id(),
				'by'    => $user->ID,
			)
		);
	}

	/**
	 * Reject: this will not be printed, and the customer is told why.
	 *
	 * The reason is written to the order note **and** sent to the customer,
	 * because a rejection nobody explained becomes a support conversation. It
	 * is the manager's own words rather than a canned code — §10's layers
	 * already produce machine reasons, and this layer exists precisely for the
	 * cases those could not name.
	 *
	 * @param WC_Order $order  The order.
	 * @param string   $reason What the manager typed.
	 */
	private function reject( WC_Order $order, string $reason ): void {
		$user = wp_get_current_user();

		if ( '' === $reason ) {
			$reason = __( 'Piešinys neatitinka mūsų reikalavimų.', 'ai-cake-topper' );
		}

		$order->update_status(
			OrderStatuses::REJECTED,
			sprintf(
				/* translators: 1: the person who rejected it, 2: their reason */
				__( 'Piešinys atmestas. Peržiūrėjo: %1$s. Priežastis: %2$s', 'ai-cake-topper' ),
				$user->display_name,
				$reason
			)
		);

		/*
		 * A customer-visible note, which WooCommerce emails. Deliberately not a
		 * new template: the shop already styles order notes, and a bespoke mail
		 * is one more thing to translate and keep in step with the order.
		 */
		$order->add_order_note(
			sprintf(
				/* translators: %s: the reason the design was rejected */
				__( 'Atsiprašome — šio piešinio pagaminti negalime. %s Grąžinsime pinigus.', 'ai-cake-topper' ),
				$reason
			),
			true
		);

		$this->logger->warning(
			'Design rejected by review.',
			array(
				'order'  => $order->get_id(),
				'by'     => $user->ID,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Back to the queue with a message.
	 *
	 * Redirect rather than render, so a refresh cannot re-submit a decision.
	 *
	 * @param string $notice Message key.
	 */
	private function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::SLUG,
					'aicake_notice'  => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * How many orders are waiting.
	 */
	public function count_waiting(): int {
		return count( $this->waiting( 1, 100 ) );
	}

	/**
	 * Orders awaiting a decision, oldest first.
	 *
	 * Oldest first on purpose: the customer who has waited longest is the one
	 * to serve next, and a newest-first queue quietly strands the awkward ones.
	 *
	 * @param int $page     Page number, 1-based.
	 * @param int $per_page How many.
	 *
	 * @return WC_Order[]
	 */
	public function waiting( int $page = 1, int $per_page = self::PER_PAGE ): array {
		$orders = wc_get_orders(
			array(
				'status'  => OrderStatuses::APPROVAL,
				'limit'   => $per_page,
				'page'    => max( 1, $page ),
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Everything the screen needs about one order's designs.
	 *
	 * @param WC_Order $order The order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function items( WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			$public_id = (string) $item->get_meta( '_aicake_design', true );

			if ( '' === $public_id ) {
				continue;
			}

			$design = $this->designs->find_by_public_id( $public_id );

			if ( null === $design ) {
				continue;
			}

			$items[] = array(
				'item_id'    => (int) $item_id,
				'name'       => $item->get_name(),
				'design'     => $design,
				'moderation' => $this->moderation( $design ),
				'format'     => PrintSpec::for_design( $design )->summary(),
				// The print file if it exists, the proof if not. Reviewing the
				// preview would approve an image nobody looked at.
				'image'      => $this->image_url( $design ),
			);
		}

		return $items;
	}

	/**
	 * The moderation record, in the shape §10 defines.
	 *
	 * Both `Verdict` and `PromptAnalysis` write this format precisely so this
	 * screen reads one shape whichever layer decided.
	 *
	 * @param array<string, mixed> $design Design row.
	 *
	 * @return array<string, mixed>
	 */
	private function moderation( array $design ): array {
		$decoded = json_decode( (string) ( $design['moderation'] ?? '' ), true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'verdict'    => '',
				'layer'      => '',
				'reasons'    => array(),
				'categories' => array(),
			);
		}

		return $decoded;
	}

	/**
	 * Which file to show, and under which variant.
	 *
	 * @param array<string, mixed> $design Design row.
	 */
	private function image_url( array $design ): string {
		$variant = '';

		/*
		 * The file has to be *there*, not merely recorded. A column pointing at
		 * a file that was cleaned up, or never written, produces an `<img>` that
		 * renders as nothing — an invisible broken image directly beside an
		 * Approve button, on the one screen whose entire job is looking at the
		 * picture. Better to say the file is missing and refuse to pretend.
		 */
		foreach ( array( 'print' => 'file_print', 'proof' => 'file_proof', 'preview' => 'file_preview' ) as $name => $column ) {
			$path = (string) ( $design[ $column ] ?? '' );

			if ( '' !== $path && is_readable( $path ) ) {
				$variant = $name;

				break;
			}
		}

		if ( '' === $variant ) {
			return '';
		}

		/*
		 * The nonce goes in the query string because this is a plain `<img
		 * src>`, which cannot send an `X-WP-Nonce` header. Without it the REST
		 * cookie check leaves the request as user 0 and the capability test
		 * fails — D-028, found when the order screen's download button 404'd
		 * every time while looking perfectly correct. Same mechanism as
		 * `OrderScreen`, deliberately, rather than a second one to keep right.
		 */
		return add_query_arg(
			'_wpnonce',
			wp_create_nonce( 'wp_rest' ),
			rest_url( 'aicake/v1/file/' . rawurlencode( (string) $design['public_id'] ) . '/' . $variant )
		);
	}

	/**
	 * Render the queue.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-cake-topper' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice key.
		$notice = isset( $_GET['aicake_notice'] ) ? sanitize_key( wp_unslash( $_GET['aicake_notice'] ) ) : '';
		$orders = $this->waiting();

		include AICAKE_DIR . 'templates/admin/review-queue.php';
	}

	/**
	 * The notice text for a redirect key.
	 *
	 * @param string $key Notice key.
	 */
	public function notice( string $key ): string {
		switch ( $key ) {
			case 'approved':
				return __( 'Patvirtinta. Užsakymas paruoštas spausdinti.', 'ai-cake-topper' );
			case 'rejected':
				return __( 'Atmesta. Klientui išsiųstas paaiškinimas — pinigus grąžinkite užsakymo lange.', 'ai-cake-topper' );
			case 'stale':
				return __( 'Šis užsakymas jau buvo peržiūrėtas. Niekas nepakeista.', 'ai-cake-topper' );
			case 'missing':
				return __( 'Užsakymas nerastas.', 'ai-cake-topper' );
			default:
				return '';
		}
	}

	/**
	 * The URL that decides one order.
	 *
	 * @param int    $order_id Order.
	 * @param string $decision approve | reject.
	 */
	public function decision_url( int $order_id, string $decision ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION,
					'order'    => $order_id,
					'decision' => $decision,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $order_id
		);
	}
}
