<?php
/**
 * Queue a generation.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\Job;
use AiCake\Domain\JobRepository;
use AiCake\Domain\TextSpec;
use AiCake\Moderation\Moderator;
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

	private Moderator $moderator;

	/**
	 * @param DesignRepository $designs    Designs.
	 * @param JobRepository    $jobs       Queue.
	 * @param Dispatcher       $dispatcher Loopback.
	 * @param RateLimiter      $limiter    Per-identity limits.
	 * @param BudgetGuard      $budget     Spend ceiling.
	 * @param IdentityResolver $identity   Identity.
	 * @param Moderator        $moderator  Moderation layers 0 and 1.
	 */
	public function __construct(
		DesignRepository $designs,
		JobRepository $jobs,
		Dispatcher $dispatcher,
		RateLimiter $limiter,
		BudgetGuard $budget,
		IdentityResolver $identity,
		Moderator $moderator
	) {
		$this->designs    = $designs;
		$this->jobs       = $jobs;
		$this->dispatcher = $dispatcher;
		$this->limiter    = $limiter;
		$this->budget     = $budget;
		$this->identity   = $identity;
		$this->moderator  = $moderator;
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$aspect = (string) $request->get_param( 'aspect' );

		// Layer 0: strip control characters, collapse whitespace, cap length.
		$prompt = $this->moderator->clean( (string) $request->get_param( 'prompt' ) );

		if ( '' === $prompt ) {
			return new WP_Error(
				'aicake_empty_prompt',
				$this->moderator->nonsense_message(),
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

		/*
		 * The format the wizard chose at step 1, validated against the
		 * catalogue rather than taken at its word — an unlisted size would
		 * otherwise be generated, paid for, and then unprintable (D-038).
		 */
		$format = FormatCatalogue::find(
			(string) $request->get_param( 'format_type' ),
			(float) $request->get_param( 'format_mm' )
		);

		if ( null !== $format ) {
			/*
			 * And the aspect comes from the format, never from the client.
			 * They are not independent: a round topper needs 1:1 and a whole
			 * sheet needs 2:3 (§3.2). A posted aspect that disagrees produces
			 * a generation that is cropped wrong, at our expense, and looks
			 * like a bad model rather than a bad request.
			 */
			$spec   = FormatCatalogue::spec( (string) $format['type'], (float) $format['diameter_mm'] );
			$aspect = null === $spec ? $aspect : $spec->generation_aspect();
		}

		$common = array(
			'session_key'  => $session_key,
			'ip_hash'      => $this->identity->ip_hash(),
			'user_id'      => $this->identity->user_id() ?: null,
			'prompt_raw'   => $prompt,
			'aspect'       => in_array( $aspect, array( '1:1', '2:3', '3:2', '4:5' ), true ) ? $aspect : '1:1',
			'product_id'   => (int) $request->get_param( 'product_id' ) ?: null,
			'variation_id' => (int) $request->get_param( 'variation_id' ) ?: null,
			'format_type'  => null === $format ? null : (string) $format['type'],
			'format_mm'    => null === $format ? null : (float) $format['diameter_mm'],
			'text_payload' => $this->text_payload( $request ),
		);

		/*
		 * Layers 0 and 1, before anything is queued. Free and instant, so the
		 * customer finds out now rather than after fifteen seconds of watching
		 * a progress bar (§10).
		 *
		 * The rejection is still written to the designs table. §10 requires
		 * every rejection to be logged with its prompt and the layer that
		 * caught it, because that is the data the blocklist grows from — and
		 * a refusal that leaves no trace is a refusal nobody can review.
		 */
		$verdict = $this->moderator->pre_check( $prompt );

		if ( ! $verdict->allowed_through() ) {
			$this->designs->create(
				array_merge(
					$common,
					array(
						'status'        => DesignRepository::STATUS_REJECTED,
						'moderation'    => $verdict->to_json(),
						'error_code'    => 'moderation_' . $verdict->layer,
						'error_message' => $verdict->reason,
					)
				)
			);

			return new WP_Error(
				'aicake_rejected',
				'sanity' === $verdict->layer
					? $this->moderator->nonsense_message()
					: $this->moderator->rejection_message(),
				array( 'status' => 422 )
			);
		}

		$design_id = $this->designs->create(
			array_merge( $common, array( 'status' => DesignRepository::STATUS_QUEUED ) )
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

	/**
	 * The text layer, if the customer asked for one.
	 *
	 * Stored as JSON on the design rather than applied here — the preview
	 * pipeline renders it, and the print path renders it again at print
	 * resolution from this same spec. Text is never scaled up from a preview
	 * (PLAN.md §9.4).
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string|null JSON, or null when there is no text.
	 */
	private function text_payload( WP_REST_Request $request ): ?string {
		$raw = $request->get_param( 'text' );

		if ( ! is_array( $raw ) || '' === trim( (string) ( $raw['text'] ?? '' ) ) ) {
			return null;
		}

		$spec = TextSpec::from_array(
			array(
				'text'      => sanitize_text_field( (string) $raw['text'] ),
				'font'      => sanitize_key( (string) ( $raw['font'] ?? '' ) ),
				'colour'    => (string) ( $raw['colour'] ?? '#ffffff' ),
				'placement' => sanitize_key( (string) ( $raw['placement'] ?? TextSpec::PLACE_BOTTOM ) ),
			)
		);

		$encoded = wp_json_encode( $spec->to_array() );

		return false === $encoded ? null : $encoded;
	}
}
