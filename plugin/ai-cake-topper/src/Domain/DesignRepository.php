<?php
/**
 * Persistence for generated designs.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

use AiCake\Installer;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes wp_aicake_designs.
 *
 * Introduced in Phase 2 rather than Phase 3 for one reason: the budget guard
 * sums cost_usd out of this table, so until something writes to it the guard
 * built in Phase 1 protects nothing. Phase 3 extends this with the job-side
 * state transitions.
 */
class DesignRepository {

	/**
	 * Statuses from PLAN.md §6.3, plus the pre-job states this phase needs.
	 */
	public const STATUS_QUEUED   = 'queued';
	public const STATUS_RUNNING  = 'running';
	public const STATUS_DONE     = 'done';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Insert a row and return its id.
	 *
	 * @param array<string, mixed> $data Column values. created_at, updated_at
	 *                                   and public_id are filled in when absent.
	 * @return int Insert id, or 0 on failure.
	 */
	public function create( array $data ): int {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		$row = array_merge(
			array(
				'public_id'   => $this->new_public_id(),
				'session_key' => '',
				'ip_hash'     => '',
				'prompt_raw'  => '',
				'status'      => self::STATUS_QUEUED,
				'cost_usd'    => 0,
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			$this->filter_columns( $data )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( Installer::table( 'designs' ), $row );

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Update a row.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Column values.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$row               = $this->filter_columns( $data );
		$row['updated_at'] = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->update( Installer::table( 'designs' ), $row, array( 'id' => $id ) );
	}

	/**
	 * Fetch one row by id.
	 *
	 * @param int $id Row id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		$table = Installer::table( 'designs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch one row by its external handle.
	 *
	 * @param string $public_id Unguessable external id.
	 * @return array<string, mixed>|null
	 */
	public function find_by_public_id( string $public_id ): ?array {
		global $wpdb;

		$table = Installer::table( 'designs' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s", $public_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Record the outcome of a generation against an existing row.
	 *
	 * @param int              $id     Row id.
	 * @param GenerationResult $result What the provider returned.
	 */
	public function record_result( int $id, GenerationResult $result ): bool {
		$data = array(
			'provider'   => $result->provider,
			'model'      => $result->model,
			'cost_usd'   => $result->cost_usd,
			'status'     => $result->ok ? self::STATUS_DONE : self::STATUS_FAILED,
		);

		if ( 0 !== $result->seed ) {
			$data['seed'] = $result->seed;
		}

		if ( ! $result->ok ) {
			$data['error_code']    = $result->error_code;
			$data['error_message'] = $result->error;
		}

		return $this->update( $id, $data );
	}

	/**
	 * A 32-character unguessable handle, unique against the table.
	 */
	public function new_public_id(): string {
		global $wpdb;

		$table = Installer::table( 'designs' );

		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$candidate = bin2hex( random_bytes( 16 ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE public_id = %s", $candidate ) );

			if ( null === $exists ) {
				return $candidate;
			}
		}

		// 128 bits colliding five times running is not a thing that happens,
		// but returning something is better than looping forever.
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Drop anything that is not a real column, so a caller cannot invent one.
	 *
	 * @param array<string, mixed> $data Candidate values.
	 * @return array<string, mixed>
	 */
	private function filter_columns( array $data ): array {
		$columns = array(
			'public_id',
			'session_key',
			'ip_hash',
			'user_id',
			'product_id',
			'variation_id',
			'format_type',
			'format_mm',
			'prompt_raw',
			'prompt_en',
			'prompt_final',
			'text_payload',
			'provider',
			'model',
			'seed',
			'aspect',
			'status',
			'moderation',
			'file_master',
			'file_preview',
			'file_print',
			'order_id',
			'order_item_id',
			'cost_usd',
			'error_code',
			'error_message',
			'created_at',
			'updated_at',
		);

		return array_intersect_key( $data, array_flip( $columns ) );
	}
}
