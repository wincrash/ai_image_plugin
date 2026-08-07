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

	/**
	 * Per-user reset marker.
	 *
	 * A datetime rather than a counter: the count is derived from the designs
	 * table, so "reset" cannot mean "set a number back to zero" — there is no
	 * number to set. It means "stop counting what happened before now".
	 */
	public const USER_EPOCH_META = '_aicake_throttle_epoch';

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

		/*
		 * Rejected prompts DO count here. Nothing was spent on them, but
		 * without this someone could probe the blocklist indefinitely looking
		 * for a phrasing that slips through.
		 */
		if ( $ip_ceiling > 0 && $this->count_for( 'ip_hash', $this->identity->ip_hash(), $this->since( $this->day_start_gmt(), 0 ), array( 'failed' ) ) >= $ip_ceiling ) {
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
		/*
		 * Neither failures nor rejections consume the allowance. A provider
		 * outage is not the customer's fault, and a prompt refused by the
		 * blocklist cost nothing and produced nothing — taking one of five
		 * free generations for it would be indefensible when the customer's
		 * next attempt is usually a legitimate rewording.
		 *
		 * The per-IP ceiling above is what stops that being abused.
		 */
		$free = array( 'failed', 'rejected' );

		$user_id = $this->identity->user_id();

		if ( 0 !== $user_id ) {
			return $this->count_for( 'user_id', (string) $user_id, $this->since( $this->day_start_gmt(), $user_id ), $free );
		}

		$session = $this->identity->session_key();

		if ( '' === $session ) {
			return 0;
		}

		return $this->count_for( 'session_key', $session, $this->since( null, 0 ), $free );
	}

	/**
	 * The lower bound a count should actually use.
	 *
	 * Combines the natural window (a day, or all time for an anonymous session)
	 * with any reset the shop has performed. The latest of them wins, because a
	 * reset can only ever forgive generations, never resurrect them.
	 *
	 * All three are 'Y-m-d H:i:s' in GMT, so comparing them as strings is the
	 * same as comparing them as times.
	 *
	 * @param string|null $window  The natural lower bound, or null for all time.
	 * @param int         $user_id Whose personal reset applies, 0 for none. The
	 *                             per-IP ceiling passes 0: that is the abuse
	 *                             backstop, and one customer being forgiven must
	 *                             not lift it for everyone else behind the same
	 *                             address.
	 */
	private function since( ?string $window, int $user_id ): ?string {
		$bounds = array();

		if ( null !== $window ) {
			$bounds[] = $window;
		}

		$global = (string) $this->settings->get( 'throttle_epoch', '' );

		if ( '' !== $global ) {
			$bounds[] = $global;
		}

		if ( $user_id > 0 ) {
			$own = (string) get_user_meta( $user_id, self::USER_EPOCH_META, true );

			if ( '' !== $own ) {
				$bounds[] = $own;
			}
		}

		return array() === $bounds ? null : max( $bounds );
	}

	/**
	 * Forgive every visitor's used generations, right now.
	 *
	 * Deliberately affects the per-IP ceiling too. The shop owner pressing
	 * "reset" means all of it, and he is the only one who can press it.
	 */
	public function reset_all(): void {
		$this->settings->update( array( 'throttle_epoch' => self::now() ) );
	}

	/**
	 * The moment a reset takes effect from.
	 *
	 * One second in the future, and that is not an off-by-one. `created_at` is a
	 * DATETIME with no fractional part, so every generation in the current
	 * second is indistinguishable from the reset itself. "Reset" means
	 * everything up to and including now is forgiven, so the boundary has to sit
	 * after the current second rather than on it — otherwise pressing the button
	 * leaves behind whatever happened in the same second, which reads as the
	 * button not working.
	 */
	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s', time() + 1 );
	}

	/**
	 * Forgive one customer's used generations.
	 *
	 * Does **not** lift the per-IP daily ceiling — see since(). A customer
	 * stopped by that one is not helped by this, which is worth saying on the
	 * screen rather than leaving someone to press the button twice.
	 *
	 * @param int $user_id The customer.
	 */
	public function reset_user( int $user_id ): void {
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::USER_EPOCH_META, self::now() );
		}
	}

	/**
	 * When the shop last reset everybody, in GMT. Empty when never.
	 */
	public function last_reset(): string {
		return (string) $this->settings->get( 'throttle_epoch', '' );
	}

	/**
	 * When this customer was last forgiven, in GMT. Empty when never.
	 *
	 * @param int $user_id The customer.
	 */
	public function last_reset_for( int $user_id ): string {
		return $user_id > 0 ? (string) get_user_meta( $user_id, self::USER_EPOCH_META, true ) : '';
	}

	/**
	 * How many generations one customer has used against their allowance.
	 *
	 * The admin screen's version of used(): that one answers for whoever is
	 * making the request, which in wp-admin is always the shop owner.
	 *
	 * It shares since() with used() rather than working the bounds out again.
	 * It briefly did not, and falsifying the global reset changed nothing —
	 * because the screen was reading one rule and the customer another. A number
	 * on an admin screen that does not come from the code being administered is
	 * worse than no number.
	 *
	 * @param int $user_id The customer.
	 */
	public function used_by( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		return $this->count_for(
			'user_id',
			(string) $user_id,
			$this->since( $this->day_start_gmt(), $user_id ),
			array( 'failed', 'rejected' )
		);
	}

	/**
	 * Generations across the whole shop since a moment, for the settings screen.
	 *
	 * @param string $since GMT datetime lower bound.
	 */
	public function generated_since( string $since ): int {
		global $wpdb;

		$table = Installer::table( 'designs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status NOT IN ( 'failed', 'rejected' )",
				$since
			)
		);
	}

	/**
	 * Start of the current local day in GMT, for callers outside this class.
	 */
	public function day_start(): string {
		return $this->day_start_gmt();
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
	 * @param string      $column  One of ip_hash, session_key, user_id.
	 * @param string      $value   Value to match.
	 * @param string|null $since   GMT datetime lower bound, or null for all time.
	 * @param string[]    $exclude Statuses that do not count.
	 */
	private function count_for( string $column, string $value, ?string $since, array $exclude = array() ): int {
		global $wpdb;

		$allowed = array( 'ip_hash', 'session_key', 'user_id' );

		if ( ! in_array( $column, $allowed, true ) ) {
			return 0;
		}

		$table  = Installer::table( 'designs' );
		$params = array( $value );

		// $column is whitelisted above; $table is built from $wpdb->prefix.
		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = %s";

		if ( null !== $since ) {
			$sql     .= ' AND created_at >= %s';
			$params[] = $since;
		}

		if ( array() !== $exclude ) {
			$sql     .= ' AND status NOT IN (' . implode( ', ', array_fill( 0, count( $exclude ), '%s' ) ) . ')';
			$params   = array_merge( $params, array_values( $exclude ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
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
