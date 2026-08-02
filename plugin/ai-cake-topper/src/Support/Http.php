<?php
/**
 * WordPress implementation of the transport.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Support;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * wp_remote_request() with the guarantees PLAN.md §16 asks for: explicit
 * timeouts, TLS verification on, a response size cap, bounded retries, and
 * never a full API key in a log line.
 */
class Http implements HttpClient {

	/**
	 * Total seconds for a request. Providers that generate images are slow;
	 * 60 s matches the outbound budget in §16.
	 */
	private const DEFAULT_TIMEOUT = 60;

	private const DEFAULT_RETRIES = 2;

	/**
	 * Refuse to buffer more than this. An image response is a few MB; anything
	 * far past that is a misconfigured endpoint, not a picture.
	 */
	private const DEFAULT_MAX_BYTES = 32 * MB_IN_BYTES;

	private Logger $logger;

	/**
	 * @param Logger $logger Logging, which also does the redaction.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param string               $method  HTTP method.
	 * @param string               $url     Absolute URL.
	 * @param array<string, mixed> $options headers, body, json, timeout, retries, max_bytes.
	 */
	public function request( string $method, string $url, array $options = array() ): HttpResponse {
		$headers   = (array) ( $options['headers'] ?? array() );
		$timeout   = (int) ( $options['timeout'] ?? self::DEFAULT_TIMEOUT );
		$retries   = max( 0, (int) ( $options['retries'] ?? self::DEFAULT_RETRIES ) );
		$max_bytes = (int) ( $options['max_bytes'] ?? self::DEFAULT_MAX_BYTES );
		$body      = $options['body'] ?? null;

		if ( isset( $options['json'] ) ) {
			$body                    = wp_json_encode( $options['json'] );
			$headers['Content-Type'] = 'application/json';
		}

		$args = array(
			'method'              => strtoupper( $method ),
			'headers'             => $headers,
			'timeout'             => $timeout,
			'redirection'         => 3,
			'sslverify'           => true,
			'limit_response_size' => $max_bytes,
			'user-agent'          => 'ai-cake-topper/' . AICAKE_VERSION . '; ' . home_url( '/' ),
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$started  = microtime( true );
		$attempt  = 0;
		$response = new HttpResponse();

		while ( $attempt <= $retries ) {
			++$attempt;

			$raw = wp_remote_request( $url, $args );

			$response = $raw instanceof WP_Error
				? new HttpResponse( 0, '', array(), $raw->get_error_message() )
				: new HttpResponse(
					(int) wp_remote_retrieve_response_code( $raw ),
					(string) wp_remote_retrieve_body( $raw ),
					$this->normalise_headers( $raw )
				);

			$response->attempts   = $attempt;
			$response->latency_ms = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( ! $response->retryable() || $attempt > $retries ) {
				break;
			}

			$this->logger->warning(
				'Retrying request.',
				array(
					'url'     => $this->safe_url( $url ),
					'attempt' => $attempt,
					'status'  => $response->status,
					'error'   => $response->error,
				)
			);

			/*
			 * Exponential backoff, and honour Retry-After when the provider
			 * says how long to wait. Capped so a worker is never held for more
			 * than a few seconds — the worker pool is the scarce resource.
			 */
			$wait = min( 4, 2 ** ( $attempt - 1 ) );

			if ( isset( $response->headers['retry-after'] ) ) {
				$suggested = (int) $response->headers['retry-after'];

				if ( $suggested > 0 ) {
					$wait = min( 5, $suggested );
				}
			}

			sleep( $wait );
		}

		$this->logger->debug(
			'HTTP request finished.',
			array(
				'url'      => $this->safe_url( $url ),
				'method'   => $args['method'],
				'status'   => $response->status,
				'ms'       => $response->latency_ms,
				'attempts' => $response->attempts,
				'bytes'    => strlen( $response->body ),
			)
		);

		return $response;
	}

	/**
	 * Lower-case header names, flatten multi-value headers to the first value.
	 *
	 * @param array<string, mixed>|\WP_HTTP_Requests_Response $raw Response from wp_remote_request().
	 * @return array<string, string>
	 */
	private function normalise_headers( $raw ): array {
		$headers = wp_remote_retrieve_headers( $raw );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		$out = array();

		foreach ( (array) $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}

		return $out;
	}

	/**
	 * A URL safe to log. Google passes the API key as a query parameter, so
	 * logging a raw request URL would leak it.
	 *
	 * @param string $url URL to sanitise.
	 */
	private function safe_url( string $url ): string {
		return $this->logger->redact( $url );
	}
}
