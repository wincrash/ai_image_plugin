<?php
/**
 * Collecting designs nobody bought.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Storage;

use AiCake\Installer;
use AiCake\Support\Logger;
use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes expired, unpurchased designs — rows and files (D-061, §12.5).
 *
 * **No cron.** Ruslan asked whether files could expire without a scheduled job,
 * and they can, by the route PHP's own session garbage collection uses: every
 * so often, a request that was already doing something else clears a small
 * batch on its way past. No Action Scheduler, no wp-cron, nothing to register,
 * nothing that breaks quietly when a host disables loopback — and it
 * self-regulates, because a site with no traffic is also a site whose storage
 * is not growing.
 *
 * The cost of that choice is that collection is not punctual. A design is
 * deleted *some time after* it expires rather than on the hour, and on a dead
 * site never. That is the right trade here: this reclaims disk, it does not
 * enforce a promise to anybody.
 *
 * **The database row is the authority, never the filesystem.** Walking
 * directories would find orphans but could not tell an expired design from a
 * paid one, which is the only distinction that actually matters.
 */
class Retention {

	/**
	 * How often a passing request should bother looking, as 1-in-N.
	 *
	 * Low enough that the overwhelming majority of generations pay nothing at
	 * all, high enough that any real traffic sweeps continuously. The sweep is
	 * bounded anyway, so this only decides how smoothly the work is spread.
	 */
	private const ODDS = 4;

	/**
	 * Hard ceiling on the batch, whatever the setting says.
	 *
	 * The setting is a knob for the shop; this is the guard on the knob. A
	 * mistyped 20000 in the settings screen must not turn one customer's
	 * generation into a filesystem job that times out mid-delete.
	 */
	private const MAX_BATCH = 200;

	/**
	 * Every file column on a design row.
	 *
	 * Listed rather than discovered, so adding a file column and forgetting to
	 * collect it is a visible omission in this array rather than an invisible
	 * leak on disk.
	 */
	private const FILE_COLUMNS = array( 'file_master', 'file_preview', 'file_proof', 'file_print' );

	private PrivateStorage $storage;

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param PrivateStorage $storage  Where the files are.
	 * @param Settings       $settings Retention window and batch size.
	 * @param Logger         $logger   Logging.
	 */
	public function __construct( PrivateStorage $storage, Settings $settings, Logger $logger ) {
		$this->storage  = $storage;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Sweep, but usually don't.
	 *
	 * Called from the job runner once a generation has finished — after the
	 * customer's work is done, and on a path that scales with generations
	 * rather than with page views (D-056).
	 *
	 * @return int Designs collected on this pass.
	 */
	public function maybe_sweep(): int {
		if ( wp_rand( 1, self::ODDS ) !== 1 ) {
			return 0;
		}

		return $this->sweep();
	}

	/**
	 * Collect one batch.
	 *
	 * Public and unconditional so the gate can call it directly. A check that
	 * had to lose a dice roll before it could assert anything would be a check
	 * that fails intermittently, which is worse than no check.
	 *
	 * @return int Designs collected.
	 */
	public function sweep(): int {
		$days = (int) $this->settings->get( 'retention_days', 14 );

		// Zero is off, and it is the switch to reach for while diagnosing.
		if ( $days < 1 ) {
			return 0;
		}

		$batch = min( self::MAX_BATCH, max( 1, (int) $this->settings->get( 'retention_batch', 20 ) ) );

		$rows = $this->candidates( $days, $batch );

		if ( array() === $rows ) {
			return 0;
		}

		$collected = 0;

		foreach ( $rows as $row ) {
			if ( $this->collect( $row ) ) {
				++$collected;
			}
		}

		if ( $collected > 0 ) {
			$this->logger->info(
				'Retention collected expired designs.',
				array(
					'designs' => $collected,
					'days'    => $days,
				)
			);
		}

		return $collected;
	}

	/**
	 * Expired designs nobody bought.
	 *
	 * `order_id IS NULL` is the whole safety argument and it is expressed in
	 * SQL rather than in PHP on purpose — a filter applied after the rows come
	 * back can be reordered, short-circuited or forgotten by a later edit. Here
	 * a row belonging to an order is never even selected.
	 *
	 * @param int $days  Retention window.
	 * @param int $batch How many at most.
	 * @return array<int, array<string, mixed>>
	 */
	private function candidates( int $days, int $batch ): array {
		global $wpdb;

		$table = Installer::table( 'designs' );

		$columns = 'id, public_id, ' . implode( ', ', self::FILE_COLUMNS );

		/*
		 * Cut-off computed in PHP against gmdate rather than with MySQL's
		 * NOW(), because every timestamp this plugin writes is UTC and the
		 * database session's time zone is the site's. Mixing the two silently
		 * shifts the window by the UTC offset — three hours in Lithuania in
		 * summer, which is invisible on a 14-day window and wrong nonetheless.
		 */
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$table}
				  WHERE order_id IS NULL
				    AND updated_at < %s
				  ORDER BY updated_at ASC
				  LIMIT %d",
				$cutoff,
				$batch
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete one design's files, then its row.
	 *
	 * **Files first, row last.** If this dies half way, what is left is a row
	 * pointing at files that are gone — which the next sweep will finish, and
	 * which the order screen already survives because a missing master is an
	 * expected state (D-048). The other order would leave files with nothing
	 * naming them: invisible, uncollectable, and permanent.
	 *
	 * @param array<string, mixed> $row Design row.
	 */
	private function collect( array $row ): bool {
		global $wpdb;

		foreach ( self::FILE_COLUMNS as $column ) {
			$path = (string) ( $row[ $column ] ?? '' );

			if ( '' !== $path ) {
				$this->storage->delete( $path );
			}
		}

		$deleted = $wpdb->delete(
			Installer::table( 'designs' ),
			array( 'id' => (int) $row['id'] ),
			array( '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}
}
