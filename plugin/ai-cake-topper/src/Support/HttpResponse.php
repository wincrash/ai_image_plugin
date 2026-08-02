<?php
/**
 * One HTTP response.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Support;

defined( 'ABSPATH' ) || exit;

/**
 * A transport-level result.
 *
 * Carries no WordPress types, so provider adapters can be exercised outside
 * WordPress entirely (PLAN.md §19, docs/api-evaluation.md §2).
 */
class HttpResponse {

	/**
	 * @param int                   $status     HTTP status, or 0 when the request never completed.
	 * @param string                $body       Raw response body.
	 * @param array<string, string> $headers    Response headers, lower-cased keys.
	 * @param string                $error      Transport error message, '' when none.
	 * @param int                   $latency_ms Wall-clock duration.
	 * @param int                   $attempts   How many times the request was actually sent.
	 */
	public function __construct(
		public int $status = 0,
		public string $body = '',
		public array $headers = array(),
		public string $error = '',
		public int $latency_ms = 0,
		public int $attempts = 1
	) {}

	/**
	 * A 2xx response that actually arrived.
	 */
	public function ok(): bool {
		return '' === $this->error && $this->status >= 200 && $this->status < 300;
	}

	/**
	 * Whether retrying could plausibly help: transport failure, 429, or 5xx.
	 *
	 * 4xx other than 429 is our fault — a bad key, a bad model name, invalid
	 * input — and retrying just spends the same error again more slowly.
	 */
	public function retryable(): bool {
		if ( '' !== $this->error ) {
			return true;
		}

		return 429 === $this->status || $this->status >= 500;
	}

	/**
	 * Decode the body as JSON.
	 *
	 * @return array<string, mixed>|null Null when the body is not a JSON object.
	 */
	public function json(): ?array {
		$decoded = json_decode( $this->body, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * A short description suitable for an error column or a log line.
	 */
	public function describe(): string {
		if ( '' !== $this->error ) {
			return $this->error;
		}

		$detail = '';
		$json   = $this->json();

		if ( null !== $json ) {
			// Providers disagree about where the message lives.
			foreach ( array( 'detail', 'error', 'message', 'title' ) as $key ) {
				if ( isset( $json[ $key ] ) ) {
					$detail = is_array( $json[ $key ] )
						? (string) ( $json[ $key ]['message'] ?? wp_json_encode( $json[ $key ] ) )
						: (string) $json[ $key ];
					break;
				}
			}
		}

		if ( '' === $detail ) {
			$detail = substr( $this->body, 0, 200 );
		}

		return sprintf( 'HTTP %d: %s', $this->status, $detail );
	}
}
