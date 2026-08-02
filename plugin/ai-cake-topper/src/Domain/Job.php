<?php
/**
 * One unit of queued work.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A row from wp_aicake_jobs.
 *
 * States and transitions are PLAN.md §6.3:
 *
 *   queued -> claimed -> running -+-> done
 *      ^                          +-> failed     (terminal)
 *      +--- timeout sweep --------+-> queued     (attempts < 3)
 *                                     rejected   (moderation, terminal, no retry)
 */
class Job {

	public const STATUS_QUEUED   = 'queued';
	public const STATUS_CLAIMED  = 'claimed';
	public const STATUS_RUNNING  = 'running';
	public const STATUS_DONE     = 'done';
	public const STATUS_FAILED   = 'failed';
	public const STATUS_REJECTED = 'rejected';

	public const TYPE_PREVIEW = 'preview';
	public const TYPE_FULFIL  = 'fulfil';

	/**
	 * Three attempts, then it is a real failure rather than bad luck.
	 */
	public const MAX_ATTEMPTS = 3;

	/**
	 * @param int                  $id         Row id.
	 * @param int                  $design_id  Design this job produces.
	 * @param string               $type       preview | fulfil.
	 * @param string               $status     See the constants above.
	 * @param int                  $attempts   How many times it has been claimed.
	 * @param string|null          $claimed_at GMT datetime of the last claim.
	 * @param string|null          $claim_token Token held by the worker that claimed it.
	 * @param array<string, mixed> $payload    Job-specific data.
	 * @param string               $created_at GMT datetime.
	 */
	public function __construct(
		public int $id = 0,
		public int $design_id = 0,
		public string $type = self::TYPE_PREVIEW,
		public string $status = self::STATUS_QUEUED,
		public int $attempts = 0,
		public ?string $claimed_at = null,
		public ?string $claim_token = null,
		public array $payload = array(),
		public string $created_at = ''
	) {}

	/**
	 * Build from a database row.
	 *
	 * @param array<string, mixed> $row Associative row.
	 */
	public static function from_row( array $row ): self {
		$payload = json_decode( (string) ( $row['payload'] ?? '' ), true );

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['design_id'] ?? 0 ),
			(string) ( $row['type'] ?? self::TYPE_PREVIEW ),
			(string) ( $row['status'] ?? self::STATUS_QUEUED ),
			(int) ( $row['attempts'] ?? 0 ),
			isset( $row['claimed_at'] ) ? (string) $row['claimed_at'] : null,
			isset( $row['claim_token'] ) ? (string) $row['claim_token'] : null,
			is_array( $payload ) ? $payload : array(),
			(string) ( $row['created_at'] ?? '' )
		);
	}

	/**
	 * Whether this job will never change again.
	 */
	public function is_terminal(): bool {
		return in_array( $this->status, array( self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_REJECTED ), true );
	}

	/**
	 * Whether a failure should go back on the queue rather than end here.
	 */
	public function can_retry(): bool {
		return $this->attempts < self::MAX_ATTEMPTS;
	}

	/**
	 * Seconds since the job was created.
	 */
	public function age_seconds(): int {
		if ( '' === $this->created_at ) {
			return 0;
		}

		return max( 0, time() - (int) strtotime( $this->created_at . ' UTC' ) );
	}
}
