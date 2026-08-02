<?php
/**
 * Polling, and the fallback that makes it work on hosts without loopback.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\Job;
use AiCake\Domain\JobRepository;
use AiCake\Queue\Dispatcher;
use AiCake\Queue\Runner;
use AiCake\Throttle\IdentityResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET /aicake/v1/job/{id} -> { status, progress, preview_url?, error? }
 *
 * Layer 2 of PLAN.md §6.2 lives here. Some hosts block loopback requests
 * outright, and on those hosts a job dispatched by GenerateEndpoint would sit
 * in `queued` forever. So if a job is still queued when someone polls it, the
 * polling request claims and runs it.
 *
 * That does occupy a worker, which is what layer 1 exists to avoid — but a
 * slow site is enormously better than a broken one, and the atomic claim makes
 * racing the loopback harmless.
 *
 * §6.5 requires this to stay cheap: it is one indexed SELECT in the common
 * case and must not bootstrap anything heavy.
 */
class JobStatusEndpoint {

	/**
	 * How long to let layer 1 prove itself before the poller takes over.
	 * PLAN.md §6.2 says about 3 seconds.
	 */
	private const LOOPBACK_GRACE = 3;

	private JobRepository $jobs;

	private DesignRepository $designs;

	private Runner $runner;

	private Dispatcher $dispatcher;

	private IdentityResolver $identity;

	/**
	 * @param JobRepository    $jobs       Queue.
	 * @param DesignRepository $designs    Designs.
	 * @param Runner           $runner     Worker.
	 * @param Dispatcher       $dispatcher Loopback state.
	 * @param IdentityResolver $identity   Identity.
	 */
	public function __construct(
		JobRepository $jobs,
		DesignRepository $designs,
		Runner $runner,
		Dispatcher $dispatcher,
		IdentityResolver $identity
	) {
		$this->jobs       = $jobs;
		$this->designs    = $designs;
		$this->runner     = $runner;
		$this->dispatcher = $dispatcher;
		$this->identity   = $identity;
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$job_id = (int) $request->get_param( 'id' );
		$job    = $this->jobs->find( $job_id );

		if ( null === $job ) {
			return new WP_Error( 'aicake_no_job', __( 'Nerasta.', 'ai-cake-topper' ), array( 'status' => 404 ) );
		}

		$design = $this->designs->find( $job->design_id );

		if ( null === $design || ! $this->owns( $design ) ) {
			// 404 rather than 403: a wrong owner should not be able to learn
			// that a job id exists.
			return new WP_Error( 'aicake_no_job', __( 'Nerasta.', 'ai-cake-topper' ), array( 'status' => 404 ) );
		}

		if ( $this->should_run_inline( $job ) ) {
			$this->runner->run( $job_id );

			$job    = $this->jobs->find( $job_id ) ?? $job;
			$design = $this->designs->find( $job->design_id ) ?? $design;
		}

		$response = new WP_REST_Response( $this->body( $job, $design ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Whether this polling request should do the work itself.
	 *
	 * @param Job $job The job.
	 */
	private function should_run_inline( Job $job ): bool {
		if ( Job::STATUS_QUEUED !== $job->status ) {
			return false;
		}

		// When loopback is known broken there is nothing to wait for.
		if ( ! $this->dispatcher->loopback_works() ) {
			return true;
		}

		return $job->age_seconds() >= self::LOOPBACK_GRACE;
	}

	/**
	 * The polling contract from §6.5.
	 *
	 * @param Job                  $job    The job.
	 * @param array<string, mixed> $design The design row.
	 * @return array<string, mixed>
	 */
	private function body( Job $job, array $design ): array {
		$body = array(
			'status'   => $job->status,
			'progress' => $this->progress( $job ),
		);

		if ( Job::STATUS_QUEUED === $job->status ) {
			$position = $this->jobs->queue_position( $job->id );

			if ( $position > 1 ) {
				$body['queue_position'] = $position;
			}
		}

		if ( Job::STATUS_DONE === $job->status && '' !== (string) $design['file_preview'] ) {
			$body['preview_url'] = rest_url( 'aicake/v1/file/' . $design['public_id'] . '/preview' );
			$body['design_id']   = (int) $design['id'];
			$body['public_id']   = (string) $design['public_id'];
		}

		if ( Job::STATUS_REJECTED === $job->status ) {
			$body['error']      = __( 'Šio aprašymo negalime panaudoti. Pabandykite aprašyti savais žodžiais, be žinomų personažų ar tikrų žmonių.', 'ai-cake-topper' );
			$body['error_code'] = 'moderation_blocked';
		}

		if ( Job::STATUS_FAILED === $job->status ) {
			$body['error']      = __( 'Nepavyko sukurti piešinio. Bandykite dar kartą.', 'ai-cake-topper' );
			// The internal code is safe to expose; the internal *message* is
			// not, since it can carry provider detail.
			$body['error_code'] = (string) $design['error_code'];
		}

		return $body;
	}

	/**
	 * A coarse progress figure, purely so the UI can show movement.
	 *
	 * @param Job $job The job.
	 */
	private function progress( Job $job ): int {
		switch ( $job->status ) {
			case Job::STATUS_QUEUED:
				return 5;
			case Job::STATUS_CLAIMED:
				return 20;
			case Job::STATUS_RUNNING:
				return 60;
			default:
				return 100;
		}
	}

	/**
	 * Whether the caller owns this design.
	 *
	 * Checked on every status request, not just on the first: PLAN.md §16
	 * requires ownership verification on every file request, and a job id is
	 * a sequential integer that anyone can guess.
	 *
	 * @param array<string, mixed> $design The design row.
	 */
	private function owns( array $design ): bool {
		$user_id = $this->identity->user_id();

		if ( 0 !== $user_id && (int) $design['user_id'] === $user_id ) {
			return true;
		}

		$session = $this->identity->session_key();

		return '' !== $session && hash_equals( (string) $design['session_key'], $session );
	}
}
