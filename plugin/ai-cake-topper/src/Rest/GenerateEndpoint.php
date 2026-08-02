<?php
/**
 * Queue a generation.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\Job;
use AiCake\Domain\JobRepository;
use AiCake\Queue\Dispatcher;
use AiCake\Throttle\BudgetGuard;
use AiCake\Throttle\IdentityResolver;
use AiCake\Throttle\RateLimiter;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /aicake/v1/generate -> 202 { job_id, design_id, poll_after_ms }
 *
 * Does as little as possible: validate, check the two limits, write two rows,
 * ping the runner, return. Everything expensive — the LLM call and the image
 * generation — happens in another worker.
 *
 * That is the whole reason the job system exists. Blocking one of 4–8 shared
 * workers for 5–15 seconds means a handful of concurrent customers takes the
 * storefront down with them (PLAN.md §6.1).
 */
class GenerateEndpoint {

	/**
	 * Long enough for any real decoration request. The same cap is applied
	 * again in the text provider, because this one can be bypassed by a future
	 * caller and that one cannot.
	 */
	private const MAX_PROMPT_CHARS = 500;

	private DesignRepository $designs;

	private JobRepository $jobs;

	private Dispatcher $dispatcher;

	private RateLimiter $limiter;

	private BudgetGuard $budget;

	private IdentityResolver $identity;

	/**
	 * @param DesignRepository $designs    Designs.
	 * @param JobRepository    $jobs       Queue.
	 * @param Dispatcher       $dispatcher Loopback.
	 * @param RateLimiter      $limiter    Per-identity limits.
	 * @param BudgetGuard      $budget     Spend ceiling.
	 * @param IdentityResolver $identity   Identity.
	 */
	public function __construct(
		DesignRepository $designs,
		JobRepository $jobs,
		Dispatcher $dispatcher,
		RateLimiter $limiter,
		BudgetGuard $budget,
		IdentityResolver $identity
	) {
		$this->designs    = $designs;
		$this->jobs       = $jobs;
		$this->dispatcher = $dispatcher;
		$this->limiter    = $limiter;
		$this->budget     = $budget;
		$this->identity   = $identity;
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$prompt = trim( (string) $request->get_param( 'prompt' ) );
		$aspect = (string) $request->get_param( 'aspect' );

		if ( '' === $prompt ) {
			return new WP_Error(
				'aicake_empty_prompt',
				__( 'Parašykite, ką norite pavaizduoti.', 'ai-cake-topper' ),
				array( 'status' => 400 )
			);
		}

		if ( mb_strlen( $prompt ) > self::MAX_PROMPT_CHARS ) {
			$prompt = mb_substr( $prompt, 0, self::MAX_PROMPT_CHARS );
		}

		// The spend ceiling is checked before the per-visitor limit: if the
		// shop is out of budget, the answer is the same for everyone and there
		// is no reason to consume someone's allowance discovering it.
		$affordable = $this->budget->check( 0.05 );

		if ( is_wp_error( $affordable ) ) {
			return $affordable;
		}

		$allowed = $this->limiter->check();

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// Establish the identity now if the client skipped the session call,
		// otherwise the design is written with an empty session_key and the
		// visitor can never poll their own job.
		$session_key = $this->identity->session_key();

		if ( '' === $session_key ) {
			$session_key = $this->identity->issue_session_key();
		}

		$design_id = $this->designs->create(
			array(
				'session_key'  => $session_key,
				'ip_hash'      => $this->identity->ip_hash(),
				'user_id'      => $this->identity->user_id() ?: null,
				'prompt_raw'   => $prompt,
				'aspect'       => in_array( $aspect, array( '1:1', '2:3', '3:2', '4:5' ), true ) ? $aspect : '1:1',
				'product_id'   => (int) $request->get_param( 'product_id' ) ?: null,
				'variation_id' => (int) $request->get_param( 'variation_id' ) ?: null,
				'status'       => DesignRepository::STATUS_QUEUED,
			)
		);

		if ( 0 === $design_id ) {
			return new WP_Error(
				'aicake_storage_failed',
				__( 'Nepavyko pradėti kūrimo. Bandykite dar kartą.', 'ai-cake-topper' ),
				array( 'status' => 500 )
			);
		}

		$job_id = $this->jobs->create( $design_id, Job::TYPE_PREVIEW );

		if ( 0 === $job_id ) {
			$this->designs->update( $design_id, array( 'status' => DesignRepository::STATUS_FAILED ) );

			return new WP_Error(
				'aicake_queue_failed',
				__( 'Nepavyko pradėti kūrimo. Bandykite dar kartą.', 'ai-cake-topper' ),
				array( 'status' => 500 )
			);
		}

		// Fire and forget. If loopback is blocked on this host, nothing here
		// notices or waits — the polling request picks the job up instead.
		$this->dispatcher->dispatch( $job_id );

		$response = new WP_REST_Response(
			array(
				'job_id'        => $job_id,
				'design_id'     => $design_id,
				'poll_after_ms' => 1500,
			),
			202
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
