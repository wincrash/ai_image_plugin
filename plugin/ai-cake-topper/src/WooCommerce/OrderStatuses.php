<?php
/**
 * The statuses an AI order passes through.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Custom order statuses (PLAN.md §13.3):
 *
 *   processing → aicake-rendering → aicake-approval → aicake-approved → completed
 *                      │                   │
 *                      └► aicake-failed    └► aicake-rejected → refunded
 *
 * `aicake-approval` and `aicake-rejected` become real filters in the orders
 * list, which is the point: the print queue is a view somebody can click,
 * rather than a note somebody has to remember to read.
 *
 * **Registration is deliberately doubled.** §13.1: HPOS reads statuses from
 * `woocommerce_register_shop_order_post_statuses`, the legacy post-table path
 * reads them from `register_post_status`, and the dropdowns come from
 * `wc_order_statuses`. Registering only the one that works on your own site is
 * how a plugin passes testing and shows blank statuses on the customer's.
 */
class OrderStatuses {

	/**
	 * Rendering the print files.
	 *
	 * Every slug is under 20 characters including the `wc-` prefix, which is
	 * the width of the status column in both storage backends. `wc-awaiting-
	 * approval` is exactly 20 and would fit today, but dropping the `aicake-`
	 * namespace to buy those characters means colliding with the next plugin
	 * that has the same obvious idea.
	 */
	public const RENDERING = 'aicake-rendering';

	/**
	 * Rendered, waiting for a human to look at it. §10 layer 3, non-negotiable.
	 */
	public const APPROVAL = 'aicake-approval';

	/**
	 * A human said yes. Ready to print.
	 */
	public const APPROVED = 'aicake-approved';

	/**
	 * A human said no. The customer is refunded from here.
	 */
	public const REJECTED = 'aicake-rejected';

	/**
	 * Rendering failed after every retry. **The customer has already paid**, so
	 * this exists to make sure that fact is visible rather than silent (§13.4).
	 */
	public const FAILED = 'aicake-failed';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_statuses' ) );
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( $this, 'register_hpos_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_to_dropdown' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'count_as_paid' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( $this, 'count_as_paid' ) );
	}

	/**
	 * Every status this plugin adds, with its label and count format.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function definitions(): array {
		return array(
			self::RENDERING => array(
				'label'    => _x( 'Ruošiamas piešinys', 'Order status', 'ai-cake-topper' ),
				/* translators: %s: order count */
				'singular' => __( 'Ruošiamas piešinys <span class="count">(%s)</span>', 'ai-cake-topper' ),
			),
			self::APPROVAL  => array(
				'label'    => _x( 'Laukia patvirtinimo', 'Order status', 'ai-cake-topper' ),
				/* translators: %s: order count */
				'singular' => __( 'Laukia patvirtinimo <span class="count">(%s)</span>', 'ai-cake-topper' ),
			),
			self::APPROVED  => array(
				'label'    => _x( 'Patvirtinta spausdinimui', 'Order status', 'ai-cake-topper' ),
				/* translators: %s: order count */
				'singular' => __( 'Patvirtinta spausdinimui <span class="count">(%s)</span>', 'ai-cake-topper' ),
			),
			self::REJECTED  => array(
				'label'    => _x( 'Atmesta', 'Order status', 'ai-cake-topper' ),
				/* translators: %s: order count */
				'singular' => __( 'Atmesta <span class="count">(%s)</span>', 'ai-cake-topper' ),
			),
			self::FAILED    => array(
				'label'    => _x( 'Nepavyko paruošti', 'Order status', 'ai-cake-topper' ),
				/* translators: %s: order count */
				'singular' => __( 'Nepavyko paruošti <span class="count">(%s)</span>', 'ai-cake-topper' ),
			),
		);
	}

	/**
	 * The legacy post-status registration.
	 */
	public function register_post_statuses(): void {
		foreach ( $this->definitions() as $slug => $definition ) {
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => $definition['label'],
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => _n_noop( $definition['singular'], $definition['singular'], 'ai-cake-topper' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingular,WordPress.WP.I18n.NonSingularStringLiteralPlural -- the strings are translated in definitions().
				)
			);
		}
	}

	/**
	 * The HPOS registration.
	 *
	 * @param array<string, array<string, mixed>> $statuses Existing statuses.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_hpos_statuses( array $statuses ): array {
		foreach ( $this->definitions() as $slug => $definition ) {
			$statuses[ 'wc-' . $slug ] = array(
				'label'                     => $definition['label'],
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop( $definition['singular'], $definition['singular'], 'ai-cake-topper' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingular,WordPress.WP.I18n.NonSingularStringLiteralPlural -- as above.
			);
		}

		return $statuses;
	}

	/**
	 * Put them in the status dropdown, in pipeline order.
	 *
	 * Inserted directly after `processing` rather than appended, because a
	 * dropdown that lists them after `completed` and `refunded` reads as if
	 * they come afterwards, and someone will eventually click accordingly.
	 *
	 * @param array<string, string> $statuses Existing statuses.
	 * @return array<string, string>
	 */
	public function add_to_dropdown( array $statuses ): array {
		$ours = array();

		foreach ( $this->definitions() as $slug => $definition ) {
			$ours[ 'wc-' . $slug ] = $definition['label'];
		}

		$merged = array();

		foreach ( $statuses as $slug => $label ) {
			$merged[ $slug ] = $label;

			if ( 'wc-processing' === $slug ) {
				$merged = array_merge( $merged, $ours );
			}
		}

		// No `processing` in the list at all — unusual, but appending beats
		// dropping them.
		return array_merge( $merged, array_diff_key( $ours, $merged ) );
	}

	/**
	 * Treat our in-flight statuses as paid.
	 *
	 * The customer has paid — the money is in the bank the moment the order
	 * leaves `processing`. Without this, revenue disappears from the reports
	 * for as long as an order is rendering or awaiting approval, and stock
	 * handling treats it as unpaid.
	 *
	 * `aicake-rejected` is excluded on purpose: it is on its way to a refund.
	 *
	 * @param array<int, string> $statuses Existing statuses.
	 * @return array<int, string>
	 */
	public function count_as_paid( array $statuses ): array {
		return array_merge( $statuses, array( self::RENDERING, self::APPROVAL, self::APPROVED, self::FAILED ) );
	}
}
