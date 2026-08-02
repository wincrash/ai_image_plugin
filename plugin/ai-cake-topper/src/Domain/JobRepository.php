<?php
/**
 * Persistence for the work queue.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

use AiCake\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wp_aicake_jobs.
 *
 * The interesting method is claim(). Everything else is bookkeeping.
 */
class JobRepository {

	/**
	 * A job claimed but not finished within this many seconds is presumed dead
	 * — the worker was killed, the host timed out, PHP fatalled. The sweeper
	 * puts it back.
	 */
	public const STALE_AFTER = 180;

	/**
	 * Queue a job.
	 *
	 * @param int                  $design_id Design this job produces.
	 * @param string               $type      preview | fulfil.
	 * @param array<string, mixed> $payload   Job-specific data.
	 * @return int Job id, or 0 on failure.
	 */
	public function create( int $design_id, string $type = Job::TYPE_PREVIEW, array $payload = array() ): int {
		global $wpdb;

		$encoded = wp_json_encode( $payload );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Installer::table( 'jobs' ),
			array(
				'design_id'  => $design_id,
				'type'       => $type,
				'status'     => Job::STATUS_QUEUED,
				'attempts'   => 0,
				'payload'    => false === $encoded ? '{}' : $encoded,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Take ownership of a queued job.
	 *
	 * This is the whole point of the table. Loopback dispatch and
	 * poll-triggered execution genuinely race — on a host where loopback is
	 * slow but working, both can reach the same job within milliseconds. The
	 * claim is therefore a single conditional UPDATE whose WHERE clause
	 * includes the current status, and the caller believes the returned row
	 * count, not a prior SELECT (PLAN.md §6.3).
	 *
	 * Doing this as SELECT-then-UPDATE would let two workers generate the same
	 * image, and a duplicate run costs real money.
	 *
	 * @param int    $job_id Job to claim.
	 * @param string $token  Token identifying this worker.
	 * @return bool True when this caller won the claim.
	 */
	public function claim( int $job_id, string $token ): bool {
		global $wpdb;

		$table = Installer::table( 'jobs' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$table}
				SET status = %s, claim_token = %s, claimed_at = %s, attempts = attempts + 1
				WHERE id = %d AND status = %s",
			Job::STATUS_CLAIMED,
			$token,
			gmdate( 'Y-m-d H:i:s' ),
			$job_id,
			Job::STATUS_QUEUED
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return 1 === (int) $wpdb->query( $sql );
	}

	/**
	 * How many jobs are currently occupying a worker.
	 *
	 * Backed by the (status, created_at) index, so the concurrency guard in
	 * §6.4 costs one cheap indexed count.
	 */
	public function in_flight(): int {
		global $wpdb;

		$table = Installer::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE status IN (%s, %s)",
				Job::STATUS_CLAIMED,
				Job::STATUS_RUNNING
			)
		);
	}

	/**
	 * Where a queued job sits in line, 1-based. 0 when it is not queued.
	 *
	 * @param int $job_id Job id.
	 */
	public function queue_position( int $job_id ): int {
		global $wpdb;

		$table = Installer::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} j
					WHERE j.status = %s
					AND j.id <= (SELECT id FROM {$table} WHERE id = %d AND status = %s)",
				Job::STATUS_QUEUED,
				$job_id,
				Job::STATUS_QUEUED
			)
		);
	}

	/**
	 * Move a claimed job into running.
	 *
	 * @param int $job_id Job id.
	 */
	public function mark_running( int $job_id ): bool {
		return $this->set_status( $job_id, Job::STATUS_RUNNING );
	}

	/**
	 * Terminal success.
	 *
	 * @param int $job_id Job id.
	 */
	public function mark_done( int $job_id ): bool {
		return $this->set_status( $job_id, Job::STATUS_DONE );
	}

	/**
	 * Terminal failure.
	 *
	 * @param int $job_id Job id.
	 */
	public function mark_failed( int $job_id ): bool {
		return $this->set_status( $job_id, Job::STATUS_FAILED );
	}

	/**
	 * Terminal rejection. Never retried — moderation will say the same thing.
	 *
	 * @param int $job_id Job id.
	 */
	public function mark_rejected( int $job_id ): bool {
		return $this->set_status( $job_id, Job::STATUS_REJECTED );
	}

	/**
	 * Put a job back on the queue, releasing the claim.
	 *
	 * @param int $job_id Job id.
	 */
	public function requeue( int $job_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			Installer::table( 'jobs' ),
			array(
				'status'      => Job::STATUS_QUEUED,
				'claim_token' => null,
				'claimed_at'  => null,
			),
			array( 'id' => $job_id )
		);
	}

	/**
	 * One job by id.
	 *
	 * @param int $job_id Job id.
	 */
	public function find( int $job_id ): ?Job {
		global $wpdb;

		$table = Installer::table( 'jobs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id ), ARRAY_A );

		return is_array( $row ) ? Job::from_row( $row ) : null;
	}

	/**
	 * Jobs that have been sitting in the queue too long for the primary and
	 * secondary dispatch to have picked them up.
	 *
	 * @param int $older_than_seconds Age threshold.
	 * @param int $limit              Maximum to return.
	 * @return Job[]
	 */
	public function stuck_queued( int $older_than_seconds, int $limit = 20 ): array {
		global $wpdb;

		$table  = Installer::table( 'jobs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $older_than_seconds ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE status = %s AND created_at <= %s ORDER BY id ASC LIMIT %d",
				Job::STATUS_QUEUED,
				$cutoff,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( Job::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Jobs claimed or running long enough that the worker is presumed dead.
	 *
	 * @param int $limit Maximum to return.
	 * @return Job[]
	 */
	public function stale_claimed( int $limit = 20 ): array {
		global $wpdb;

		$table  = Installer::table( 'jobs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::STALE_AFTER );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE status IN (%s, %s) AND claimed_at <= %s ORDER BY id ASC LIMIT %d",
				Job::STATUS_CLAIMED,
				Job::STATUS_RUNNING,
				$cutoff,
				$limit
			),
			ARRAY_A
		);

		return array_map( array( Job::class, 'from_row' ), (array) $rows );
	}

	/**
	 * Delete finished jobs older than a cutoff, so the table stays small.
	 *
	 * @param int $older_than_days Age in days.
	 * @return int Rows removed.
	 */
	public function purge_finished( int $older_than_days = 7 ): int {
		global $wpdb;

		$table  = Installer::table( 'jobs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $older_than_days ) * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE status IN (%s, %s, %s) AND created_at <= %s",
				Job::STATUS_DONE,
				Job::STATUS_FAILED,
				Job::STATUS_REJECTED,
				$cutoff
			)
		);
	}

	/**
	 * Set a status, touching nothing else.
	 *
	 * @param int    $job_id Job id.
	 * @param string $status New status.
	 */
	private function set_status( int $job_id, string $status ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update(
			Installer::table( 'jobs' ),
			array( 'status' => $status ),
			array( 'id' => $job_id )
		);
	}
}
