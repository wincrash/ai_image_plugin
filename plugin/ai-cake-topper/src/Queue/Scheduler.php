<?php
/**
 * The safety net under the queue.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Queue;

use AiCake\Domain\Job;
use AiCake\Domain\JobRepository;
use AiCake\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Layer 3 of PLAN.md §6.2.
 *
 * Loopback can be blocked. A polling browser can be closed mid-generation. A
 * worker can be killed halfway through. This sweep is what makes those
 * recoverable rather than permanent, and on a host where the first two layers
 * both misbehave it is the only thing that runs jobs at all.
 *
 * Prefers Action Scheduler, which ships with WooCommerce and brings retries,
 * logging and an admin UI for free. Falls back to wp-cron so the plugin still
 * degrades gracefully if WooCommerce is deactivated.
 */
class Scheduler {

	public const HOOK = 'aicake_sweep_jobs';

	/**
	 * Every minute. The sweep is two indexed queries when there is nothing to
	 * do, which is almost always.
	 */
	private const INTERVAL = MINUTE_IN_SECONDS;

	/**
	 * A queued job older than this was missed by both loopback and polling.
	 */
	private const QUEUED_GRACE = 45;

	private JobRepository $jobs;

	private Runner $runner;

	private Logger $logger;

	/**
	 * @param JobRepository $jobs   Queue.
	 * @param Runner        $runner Worker.
	 * @param Logger        $logger Logging.
	 */
	public function __construct( JobRepository $jobs, Runner $runner, Logger $logger ) {
		$this->jobs   = $jobs;
		$this->runner = $runner;
		$this->logger = $logger;
	}

	/**
	 * Register the recurring action.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'sweep' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Make sure the sweep is on a schedule, whichever scheduler exists.
	 */
	public function ensure_scheduled(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK ) ) {
				as_schedule_recurring_action( time() + self::INTERVAL, self::INTERVAL, self::HOOK, array(), 'ai-cake-topper' );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + self::INTERVAL, 'aicake_minute', self::HOOK );
		}
	}

	/**
	 * Add the one-minute wp-cron interval used by the fallback path.
	 *
	 * @param array<string, array<string, mixed>> $schedules Registered schedules.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_cron_interval( array $schedules ): array {
		$schedules['aicake_minute'] = array(
			'interval' => self::INTERVAL,
			'display'  => __( 'Every minute (AI Cake Topper)', 'ai-cake-topper' ),
		);

		return $schedules;
	}

	/**
	 * Recover anything the first two dispatch layers missed.
	 */
	public function sweep(): void {
		$this->recover_stale();
		$this->run_stuck_queued();
	}

	/**
	 * Jobs claimed or running long enough that the worker is presumed dead.
	 */
	private function recover_stale(): void {
		foreach ( $this->jobs->stale_claimed() as $job ) {
			if ( $job->can_retry() ) {
				$this->jobs->requeue( $job->id );
				$this->logger->warning(
					'Recovered a job whose worker never finished.',
					array(
						'job'      => $job->id,
						'attempts' => $job->attempts,
					)
				);

				continue;
			}

			$this->jobs->mark_failed( $job->id );
			$this->logger->error(
				'Job exhausted its attempts and was failed by the sweeper.',
				array( 'job' => $job->id )
			);
		}
	}

	/**
	 * Jobs still sitting in the queue after both dispatch layers had a chance.
	 */
	private function run_stuck_queued(): void {
		foreach ( $this->jobs->stuck_queued( self::QUEUED_GRACE ) as $job ) {
			if ( ! $job->can_retry() ) {
				$this->jobs->mark_failed( $job->id );

				continue;
			}

			// The cap is deliberately ignored here. A stuck queue is precisely
			// the situation where the concurrency guard must not also prevent
			// recovery, and the sweep runs one job at a time in cron context
			// rather than in a customer request.
			$outcome = $this->runner->run( $job->id, false );

			$this->logger->info(
				'Sweeper ran a stuck job.',
				array(
					'job'     => $job->id,
					'outcome' => $outcome,
					'age'     => $job->age_seconds(),
				)
			);

			// One per sweep. The next tick is a minute away, and doing the
			// whole backlog inside one cron request is how a sweep becomes the
			// thing that times out.
			break;
		}
	}

	/**
	 * Remove the schedule on deactivation.
	 */
	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
		}

		$timestamp = wp_next_scheduled( self::HOOK );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Job type this sweeper knows how to run.
	 */
	public function handles(): string {
		return Job::TYPE_PREVIEW;
	}
}
