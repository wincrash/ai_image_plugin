<?php
/**
 * Claims and executes one job.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Queue;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\GenerationRequest;
use AiCake\Domain\Job;
use AiCake\Domain\JobRepository;
use AiCake\Pipeline\PromptBuilder;
use AiCake\Providers\ProviderRegistry;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use AiCake\Throttle\BudgetGuard;

defined( 'ABSPATH' ) || exit;

/**
 * The worker.
 *
 * Reached three ways — loopback, a polling request that gave up waiting, and
 * the Action Scheduler sweeper — which is exactly why the claim is atomic. All
 * three can arrive at the same job, and only one may execute it.
 */
class Runner {

	/**
	 * Outcomes, returned so callers and tests can tell what happened without
	 * re-reading the row.
	 */
	public const RAN          = 'ran';
	public const NOT_CLAIMED  = 'not_claimed';
	public const AT_CAPACITY  = 'at_capacity';
	public const BAD_TOKEN    = 'bad_token';
	public const NOT_FOUND    = 'not_found';
	public const ALREADY_DONE = 'already_done';

	private JobRepository $jobs;

	private DesignRepository $designs;

	private ProviderRegistry $providers;

	private PromptBuilder $prompts;

	private PrivateStorage $storage;

	private BudgetGuard $budget;

	private Dispatcher $dispatcher;

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param JobRepository    $jobs       Queue.
	 * @param DesignRepository $designs    Designs.
	 * @param ProviderRegistry $providers  Providers.
	 * @param PromptBuilder    $prompts    Style suffix.
	 * @param PrivateStorage   $storage    Files.
	 * @param BudgetGuard      $budget     Spend ceiling.
	 * @param Dispatcher       $dispatcher Loopback, for token verification.
	 * @param Settings         $settings   Configuration.
	 * @param Logger           $logger     Logging.
	 */
	public function __construct(
		JobRepository $jobs,
		DesignRepository $designs,
		ProviderRegistry $providers,
		PromptBuilder $prompts,
		PrivateStorage $storage,
		BudgetGuard $budget,
		Dispatcher $dispatcher,
		Settings $settings,
		Logger $logger
	) {
		$this->jobs       = $jobs;
		$this->designs    = $designs;
		$this->providers  = $providers;
		$this->prompts    = $prompts;
		$this->storage    = $storage;
		$this->budget     = $budget;
		$this->dispatcher = $dispatcher;
		$this->settings   = $settings;
		$this->logger     = $logger;
	}

	/**
	 * Register the loopback endpoints.
	 */
	public function register(): void {
		// nopriv as well as the logged-in variant: a loopback request carries
		// no session cookie, so it always arrives logged out.
		add_action( 'admin_post_nopriv_' . Dispatcher::RUN_ACTION, array( $this, 'handle_request' ) );
		add_action( 'admin_post_' . Dispatcher::RUN_ACTION, array( $this, 'handle_request' ) );

		add_action( 'admin_post_nopriv_' . Dispatcher::TEST_ACTION, array( $this->dispatcher, 'handle_test' ) );
		add_action( 'admin_post_' . Dispatcher::TEST_ACTION, array( $this->dispatcher, 'handle_test' ) );
	}

	/**
	 * Entry point for the loopback request.
	 */
	public function handle_request(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authenticated by HMAC below; a loopback request has no nonce.
		$job_id = isset( $_POST['job'] ) ? (int) $_POST['job'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		// The caller hung up the moment the request was sent, so nothing here
		// should stop because the connection is gone.
		ignore_user_abort( true );

		if ( ! $this->dispatcher->verify( $job_id, $token ) ) {
			status_header( 403 );
			exit;
		}

		$this->run( $job_id );

		status_header( 200 );
		exit;
	}

	/**
	 * Claim and execute a job.
	 *
	 * @param int  $job_id       Job to run.
	 * @param bool $respect_cap  Whether the concurrency cap applies. The
	 *                           sweeper passes false: a stuck queue is exactly
	 *                           when the cap must not also block recovery.
	 * @return string One of the outcome constants.
	 */
	public function run( int $job_id, bool $respect_cap = true ): string {
		$job = $this->jobs->find( $job_id );

		if ( null === $job ) {
			return self::NOT_FOUND;
		}

		if ( $job->is_terminal() ) {
			return self::ALREADY_DONE;
		}

		if ( $respect_cap && $this->jobs->in_flight() >= $this->concurrency_cap() ) {
			// Leave it queued. The sweeper or a later poll will pick it up,
			// and the customer sees a queue position rather than an error.
			return self::AT_CAPACITY;
		}

		$token = bin2hex( random_bytes( 16 ) );

		if ( ! $this->jobs->claim( $job_id, $token ) ) {
			// Someone else won. This is the normal, expected outcome of the
			// race between loopback and poll-triggered execution.
			return self::NOT_CLAIMED;
		}

		$this->raise_limits();
		$this->jobs->mark_running( $job_id );

		try {
			$this->execute( $this->jobs->find( $job_id ) ?? $job );
		} catch ( \Throwable $e ) {
			// A provider adapter should never throw, but a fatal here would
			// otherwise leave the job wedged in `running` until the sweeper.
			$this->logger->error(
				'Job threw an exception.',
				array(
					'job'    => $job_id,
					'detail' => $e->getMessage(),
				)
			);
			$this->fail( $job_id, $job->design_id, 'exception', $e->getMessage() );
		}

		return self::RAN;
	}

	/**
	 * Do the actual work of a preview job.
	 *
	 * @param Job $job The claimed job.
	 */
	private function execute( Job $job ): void {
		$design = $this->designs->find( $job->design_id );

		if ( null === $design ) {
			$this->jobs->mark_failed( $job->id );

			return;
		}

		$prompt_lt = (string) $design['prompt_raw'];

		// Moderation runs here rather than in the REST handler so that
		// POST /generate stays a database write plus a loopback ping. An
		// 800 ms LLM call in the request path would occupy a customer-facing
		// worker for no good reason (PLAN.md §6.1).
		$analysis = $this->providers->analyse( $prompt_lt );

		$this->designs->update(
			$job->design_id,
			array(
				'prompt_en'  => $analysis->prompt_en,
				'moderation' => $analysis->to_json(),
				'cost_usd'   => (float) $design['cost_usd'] + $analysis->cost_usd,
			)
		);

		if ( ! $analysis->ok() ) {
			$this->retry_or_fail( $job, 'moderation_failed', $analysis->error );

			return;
		}

		if ( $analysis->blocked() ) {
			// Terminal, and never retried — the classifier will say the same
			// thing next time, and re-asking costs money.
			$this->designs->update(
				$job->design_id,
				array(
					'status'        => DesignRepository::STATUS_REJECTED,
					'error_code'    => 'moderation_blocked',
					'error_message' => implode( ', ', $analysis->reasons ),
				)
			);
			$this->jobs->mark_rejected( $job->id );

			return;
		}

		$allowed = $this->budget->check( 0.05 );

		if ( is_wp_error( $allowed ) ) {
			$this->retry_or_fail( $job, 'budget_blocked', $allowed->get_error_message() );

			return;
		}

		$prompt_final = $this->prompts->build( $analysis->prompt_en );
		$aspect       = (string) ( $design['aspect'] ?: '1:1' );

		$result = $this->providers->generate( new GenerationRequest( $prompt_final, $aspect ) );

		$this->designs->update( $job->design_id, array( 'prompt_final' => $prompt_final ) );

		if ( ! $result->ok ) {
			$this->designs->update(
				$job->design_id,
				array(
					'provider'      => $result->provider,
					'model'         => $result->model,
					'error_code'    => $result->error_code,
					'error_message' => $result->error,
				)
			);
			$this->retry_or_fail( $job, $result->error_code, $result->error );

			return;
		}

		$path = $this->storage->store_master( (string) $design['public_id'], $result->bytes );

		if ( '' === $path ) {
			/*
			 * The image exists but we could not keep it. Marking this `done`
			 * would hand the customer a design row pointing at nothing, and
			 * the polling contract would report success with no preview — a
			 * silent failure that looks like a frontend bug.
			 *
			 * Retrying is right: the usual cause is a directory permission or
			 * a full disk, both of which can be fixed while the job waits.
			 * The cost is already spent either way.
			 */
			$this->designs->update(
				$job->design_id,
				array(
					'provider' => $result->provider,
					'model'    => $result->model,
					'cost_usd' => (float) $design['cost_usd'] + $analysis->cost_usd + $result->cost_usd,
				)
			);
			$this->retry_or_fail( $job, 'storage_failed', 'The generated image could not be written to the storage directory.' );

			return;
		}

		$this->designs->update(
			$job->design_id,
			array(
				'provider'    => $result->provider,
				'model'       => $result->model,
				'seed'        => $result->seed,
				'file_master' => $path,
				/*
				 * Phase 3 has no shaping, watermark or downscale — those are
				 * Phase 4. Pointing the preview at the master keeps the polling
				 * contract honest in the meantime; the WatermarkStage replaces
				 * this, and no customer-facing route serves file_preview yet.
				 */
				'file_preview' => $path,
				'cost_usd'     => (float) $design['cost_usd'] + $analysis->cost_usd + $result->cost_usd,
				/*
				 * `review` is not a design status. The preview is produced
				 * either way — that is the entire point of the third verdict —
				 * and the flag that makes a human look lives in the moderation
				 * JSON, which the Phase 8 review queue reads.
				 */
				'status'       => DesignRepository::STATUS_DONE,
			)
		);

		$this->jobs->mark_done( $job->id );

		$this->logger->info(
			'Preview generated.',
			array(
				'design'   => $job->design_id,
				'provider' => $result->provider,
				'ms'       => $result->latency_ms,
				'review'   => $analysis->needs_review(),
			)
		);
	}

	/**
	 * Put a failed job back on the queue, or end it.
	 *
	 * @param Job    $job     The job.
	 * @param string $code    Machine-readable code.
	 * @param string $message Detail.
	 */
	private function retry_or_fail( Job $job, string $code, string $message ): void {
		if ( $job->can_retry() ) {
			$this->jobs->requeue( $job->id );
			$this->logger->warning(
				'Job failed; requeued.',
				array(
					'job'      => $job->id,
					'attempts' => $job->attempts,
					'code'     => $code,
				)
			);

			return;
		}

		$this->fail( $job->id, $job->design_id, $code, $message );
	}

	/**
	 * Terminal failure on both the job and its design.
	 *
	 * @param int    $job_id    Job id.
	 * @param int    $design_id Design id.
	 * @param string $code      Machine-readable code.
	 * @param string $message   Detail.
	 */
	private function fail( int $job_id, int $design_id, string $code, string $message ): void {
		$this->jobs->mark_failed( $job_id );

		if ( 0 !== $design_id ) {
			$this->designs->update(
				$design_id,
				array(
					'status'        => DesignRepository::STATUS_FAILED,
					'error_code'    => $code,
					'error_message' => $message,
				)
			);
		}
	}

	/**
	 * The in-flight ceiling (PLAN.md §6.4).
	 */
	public function concurrency_cap(): int {
		return max( 1, (int) $this->settings->get( 'concurrency_cap', 3 ) );
	}

	/**
	 * Give the job room to finish without taking the host down.
	 */
	private function raise_limits(): void {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@set_time_limit( 120 );
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}
	}
}
