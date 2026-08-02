<?php
/**
 * Per-identity generation limits.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Throttle;

use AiCake\Installer;
use AiCake\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Counts prior generations out of the designs table.
 *
 * The table is both the audit log and the rate-limit source (PLAN.md §11.1).
 * Transients were rejected: with an object cache in front of them they can be
 * evicted early, and a limiter that silently stops limiting is invisible until
 * the invoice arrives.
 */
class RateLimiter {

	private Settings $settings;

	private IdentityResolver $identity;

	/**
	 * @param Settings         $settings Configuration.
	 * @param IdentityResolver $identity Identity resolution.
	 */
	public function __construct( Settings $settings, IdentityResolver $identity ) {
		$this->settings = $settings;
		$this->identity = $identity;
	}

	/**
	 * May this visitor generate right now?
	 *
	 * @return true|WP_Error True when allowed, WP_Error with a customer-facing
	 *                       message when not.
	 */
	public function check() {
		$interval = (int) $this->settings->get( 'min_interval_seconds', 3 );

		if ( $interval > 0 ) {
			$since = $this->seconds_since_last();

			if ( null !== $since && $since < $interval ) {
				return new WP_Error(
					'aicake_too_fast',
					sprintf(
						/* translators: %d: number of seconds to wait */
						_n(
							'Palaukite %d sekundę prieš kurdami kitą piešinį.',
							'Palaukite %d sekundes prieš kurdami kitą piešinį.',
							$interval - $since,
							'ai-cake-topper'
						),
						$interval - $since
					),
					array( 'status' => 429 )
				);
			}
		}

		// The hard per-IP ceiling. Deliberately separate and higher: without it,
		// clearing cookies resets the allowance and the whole thing is theatre.
		$ip_ceiling = (int) $this->settings->get( 'ip_daily_ceiling', 30 );

		if ( $ip_ceiling > 0 && $this->count_for( 'ip_hash', $this->identity->ip_hash(), $this->day_start_gmt() ) >= $ip_ceiling ) {
			return new WP_Error(
				'aicake_ip_limit',
				__( 'Pasiektas dienos piešinių limitas. Bandykite dar kartą rytoj.', 'ai-cake-topper' ),
				array( 'status' => 429 )
			);
		}

		if ( $this->used() >= $this->allowance() ) {
			return new WP_Error(
				'aicake_session_limit',
				0 === $this->identity->user_id()
					? __( 'Išnaudojote nemokamus piešinius. Susikurkite paskyrą ir kurkite toliau.', 'ai-cake-topper' )
					: __( 'Pasiektas dienos piešinių limitas. Bandykite dar kartą rytoj.', 'ai-cake-topper' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * How many generations this identity is entitled to.
	 */
	public function allowance(): int {
		return 0 === $this->identity->user_id()
			? (int) $this->settings->get( 'free_per_session', 5 )
			: (int) $this->settings->get( 'free_per_user', 20 );
	}

	/**
	 * How many this identity has already used.
	 *
	 * Counted against the *loosest* identifier (PLAN.md §11.2) so a customer on
	 * a CGNAT mobile connection is not charged for strangers sharing their IP:
	 *
	 * - anonymous: the session cookie, for the life of that cookie — this is
	 *   the "5 free, then sign up" gate;
	 * - logged in: their account, over a rolling day.
	 */
	public function used(): int {
		$user_id = $this->identity->user_id();

		if ( 0 !== $user_id ) {
			return $this->count_for( 'user_id', (string) $user_id, $this->day_start_gmt() );
		}

		$session = $this->identity->session_key();

		if ( '' === $session ) {
			return 0;
		}

		return $this->count_for( 'session_key', $session, null );
	}

	/**
	 * Generations left before the visitor is stopped.
	 */
	public function remaining(): int {
		return max( 0, $this->allowance() - $this->used() );
	}

	/**
	 * Count rows for one identifier.
	 *
	 * Failed generations are excluded: a provider outage should not burn a
	 * customer's free allowance.
	 *
	 * @param string      $column One of ip_hash, session_key, user_id.
	 * @param string      $value  Value to match.
	 * @param string|null $since  GMT datetime lower bound, or null for all time.
	 */
	private function count_for( string $column, string $value, ?string $since ): int {
		global $wpdb;

		$allowed = array( 'ip_hash', 'session_key', 'user_id' );

		if ( ! in_array( $column, $allowed, true ) ) {
			return 0;
		}

		$table = Installer::table( 'designs' );

		// $column is whitelisted above; $table is built from $wpdb->prefix.
		if ( null === $since ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE {$column} = %s AND status <> 'failed'",
				$value
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE {$column} = %s AND created_at >= %s AND status <> 'failed'",
				$value,
				$since
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Seconds since this identity's most recent generation, or null if none.
	 */
	private function seconds_since_last(): ?int {
		global $wpdb;

		$table   = Installer::table( 'designs' );
		$session = $this->identity->session_key();
		$user_id = $this->identity->user_id();

		if ( 0 !== $user_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT MAX(created_at) FROM {$table} WHERE user_id = %d", $user_id );
		} elseif ( '' !== $session ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare( "SELECT MAX(created_at) FROM {$table} WHERE session_key = %s", $session );
		} else {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$last = $wpdb->get_var( $sql );

		if ( null === $last ) {
			return null;
		}

		return max( 0, time() - (int) strtotime( (string) $last . ' UTC' ) );
	}

	/**
	 * Start of the current local day, expressed in GMT to match created_at.
	 *
	 * The shop owner thinks in Lithuanian days, not UTC days, so the boundary
	 * follows the site timezone.
	 */
	private function day_start_gmt(): string {
		$local = current_time( 'Y-m-d' ) . ' 00:00:00';

		return get_gmt_from_date( $local );
	}
}
