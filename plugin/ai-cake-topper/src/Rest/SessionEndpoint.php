<?php
/**
 * The uncached endpoint that makes page caching survivable.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Throttle\IdentityResolver;
use AiCake\Throttle\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET /aicake/v1/session
 *
 * This endpoint exists because of PLAN.md §7, which is worth restating because
 * it is the single most likely way to ship a product that appears to work in
 * testing and fails for most real customers:
 *
 * Any production WordPress has page caching. If the nonce is printed into the
 * product page HTML, the cache serves a stale one, and **every generation
 * request from a logged-out visitor fails with 403** — that is most of the
 * traffic, and it looks like a random intermittent bug.
 *
 * So the nonce is never in the cached HTML. The JS fetches it from here on
 * first interaction. This response must never be cached, which is what the
 * no-store headers below are for.
 *
 * It also sets the session cookie, which conveniently means the throttle
 * identity exists before the first generation rather than being created by it.
 */
class SessionEndpoint {

	private IdentityResolver $identity;

	private RateLimiter $limiter;

	/**
	 * @param IdentityResolver $identity Identity.
	 * @param RateLimiter      $limiter  Limits.
	 */
	public function __construct( IdentityResolver $identity, RateLimiter $limiter ) {
		$this->identity = $identity;
		$this->limiter  = $limiter;
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$session_key = $this->identity->issue_session_key();

		$response = new WP_REST_Response(
			array(
				'nonce'                 => wp_create_nonce( 'wp_rest' ),
				'session_key'           => $session_key,
				'remaining_generations' => $this->limiter->remaining(),
				'allowance'             => $this->limiter->allowance(),
				'logged_in'             => 0 !== $this->identity->user_id(),
			)
		);

		// Belt and braces. The header tells well-behaved caches to leave this
		// alone; the path also has to be excluded in any page-cache plugin,
		// which is a deployment note rather than something code can enforce.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}
}
