<?php
/**
 * Daily and monthly spend ceiling.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Throttle;

use AiCake\Installer;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The difference between a bad day and a bad month (PLAN.md §11.4).
 *
 * Checked before every paid call. Sums cost_usd out of the designs table, so
 * there is no separate counter to drift out of step with reality.
 *
 * The ceiling is not latched: it is recomputed from actual spend, so a daily
 * breach clears itself at midnight with no administrator action.
 */
class BudgetGuard {

	private const NOTIFIED_OPTION = 'aicake_budget_notified';

	private const TRIPPED_OPTION = 'aicake_budget_last_trip';

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param Settings $settings Configuration.
	 * @param Logger   $logger   Logging.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * May we spend money right now?
	 *
	 * @param float $estimate Cost of the call about to be made, in USD.
	 * @return true|WP_Error
	 */
	public function check( float $estimate = 0.0 ) {
		if ( ! (bool) $this->settings->get( 'generation_enabled', true ) ) {
			return new WP_Error( 'aicake_disabled', $this->unavailable_message(), array( 'status' => 503 ) );
		}

		$daily = (float) $this->settings->get( 'budget_daily_usd', 5.0 );

		if ( $daily > 0 && $this->spent_today() + $estimate > $daily ) {
			$this->trip( 'daily', $this->spent_today(), $daily );

			return new WP_Error( 'aicake_budget_daily', $this->unavailable_message(), array( 'status' => 503 ) );
		}

		$monthly = (float) $this->settings->get( 'budget_monthly_usd', 50.0 );

		if ( $monthly > 0 && $this->spent_this_month() + $estimate > $monthly ) {
			$this->trip( 'monthly', $this->spent_this_month(), $monthly );

			return new WP_Error( 'aicake_budget_monthly', $this->unavailable_message(), array( 'status' => 503 ) );
		}

		return true;
	}

	/**
	 * Whether generation is currently stopped, for the product page notice.
	 */
	public function is_blocked(): bool {
		return is_wp_error( $this->check() );
	}

	/**
	 * Spend since the start of the local day, in USD.
	 */
	public function spent_today(): float {
		return $this->sum_since( get_gmt_from_date( current_time( 'Y-m-d' ) . ' 00:00:00' ) );
	}

	/**
	 * Spend since the start of the local month, in USD.
	 */
	public function spent_this_month(): float {
		return $this->sum_since( get_gmt_from_date( current_time( 'Y-m' ) . '-01 00:00:00' ) );
	}

	/**
	 * Sum cost_usd from a GMT datetime onwards.
	 *
	 * @param string $since_gmt GMT datetime lower bound.
	 */
	private function sum_since( string $since_gmt ): float {
		global $wpdb;

		$table = Installer::table( 'designs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare( "SELECT COALESCE(SUM(cost_usd), 0) FROM {$table} WHERE created_at >= %s", $since_gmt );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (float) $wpdb->get_var( $sql );
	}

	/**
	 * What the customer sees. Never mentions money — this is our problem.
	 */
	private function unavailable_message(): string {
		return __( 'Piešinių kūrimas laikinai nepasiekiamas. Bandykite vėliau.', 'ai-cake-topper' );
	}

	/**
	 * Record the breach and email the administrator, at most once per period.
	 *
	 * @param string $scope 'daily' or 'monthly'.
	 * @param float  $spent Amount already spent, USD.
	 * @param float  $limit The ceiling that was hit, USD.
	 */
	private function trip( string $scope, float $spent, float $limit ): void {
		$period = 'daily' === $scope ? current_time( 'Y-m-d' ) : current_time( 'Y-m' );
		$marker = $scope . ':' . $period;

		update_option(
			self::TRIPPED_OPTION,
			array(
				'scope' => $scope,
				'spent' => $spent,
				'limit' => $limit,
				'at'    => gmdate( 'Y-m-d H:i:s' ),
			),
			false
		);

		if ( get_option( self::NOTIFIED_OPTION ) === $marker ) {
			return;
		}

		update_option( self::NOTIFIED_OPTION, $marker, false );

		$this->logger->error(
			'Budget ceiling reached; generation stopped.',
			array(
				'scope' => $scope,
				'spent' => $spent,
				'limit' => $limit,
			)
		);

		$to = (string) $this->settings->get( 'budget_notify_email', '' );

		if ( '' === $to ) {
			$to = (string) get_option( 'admin_email' );
		}

		if ( '' === $to ) {
			return;
		}

		wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name */
				__( '[%s] AI generation stopped — budget ceiling reached', 'ai-cake-topper' ),
				wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )
			),
			sprintf(
				/* translators: 1: daily or monthly, 2: amount spent, 3: configured limit */
				__(
					"AI image generation has been stopped because the %1\$s spending ceiling was reached.\n\nSpent: \$%2\$.2f\nLimit: \$%3\$.2f\n\nCustomers now see a \"temporarily unavailable\" message. This clears itself when the period rolls over, or immediately if you raise the limit in the plugin settings.",
					'ai-cake-topper'
				),
				$scope,
				$spent,
				$limit
			)
		);
	}
}
