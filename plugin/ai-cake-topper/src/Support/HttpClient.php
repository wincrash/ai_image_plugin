<?php
/**
 * The transport seam.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one abstraction that keeps provider adapters testable.
 *
 * Adapters depend on this, never on wp_remote_post() directly, so the same
 * adapter runs inside WordPress, from WP-CLI, and against a stub in a unit
 * test. It costs about forty lines and it is the reason Phase 0's harness and
 * the shipped plugin can be the same code (docs/api-evaluation.md §2).
 */
interface HttpClient {

	/**
	 * Perform a request.
	 *
	 * Implementations must never throw for a transport failure — they return
	 * an HttpResponse carrying the error, so a provider outage is ordinary
	 * control flow rather than an exception the pipeline has to catch.
	 *
	 * @param string               $method  GET, POST, ...
	 * @param string               $url     Absolute URL.
	 * @param array<string, mixed> $options headers, body, json, timeout,
	 *                                      retries, max_bytes.
	 */
	public function request( string $method, string $url, array $options = array() ): HttpResponse;
}
