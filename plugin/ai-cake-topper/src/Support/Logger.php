<?php
/**
 * Levelled logging with secret redaction.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log when available, error_log otherwise.
 *
 * Everything is passed through redact() first. PLAN.md §16: never log a full
 * API key. Google in particular puts the key in the query string, so a logged
 * request URL leaks it unless it is scrubbed.
 */
class Logger {

	private const SOURCE = 'ai-cake-topper';

	/**
	 * Level name => severity. Higher is more severe.
	 */
	private const LEVELS = array(
		'debug'   => 10,
		'info'    => 20,
		'warning' => 30,
		'error'   => 40,
		'off'     => 99,
	);

	private Settings $settings;

	/**
	 * Secret values to scrub, longest first so a key that contains another
	 * key's prefix still redacts cleanly.
	 *
	 * @var string[]|null
	 */
	private ?array $secrets = null;

	/**
	 * @param Settings $settings Configuration.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra detail, appended as JSON.
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra detail, appended as JSON.
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra detail, appended as JSON.
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra detail, appended as JSON.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param string               $level   One of the LEVELS keys.
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Extra detail, appended as JSON.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		$threshold = self::LEVELS[ (string) $this->settings->get( 'log_level', 'info' ) ] ?? self::LEVELS['info'];
		$severity  = self::LEVELS[ $level ] ?? self::LEVELS['info'];

		if ( $severity < $threshold || self::LEVELS['off'] === $threshold ) {
			return;
		}

		$line = $this->redact( $message );

		if ( array() !== $context ) {
			$encoded = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$line   .= ' ' . $this->redact( false === $encoded ? '[uncodable context]' : $encoded );
		}

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( 'debug' === $level ? 'debug' : $level, $line, array( 'source' => self::SOURCE ) );

			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[%s] %s: %s', self::SOURCE, $level, $line ) );
	}

	/**
	 * Remove anything that looks like a credential.
	 *
	 * Two passes. The exact-value pass is the reliable one — it scrubs the keys
	 * we actually hold. The pattern pass is the safety net for a key we do not
	 * hold, such as one echoed back inside a provider error message.
	 *
	 * @param string $text Text to scrub.
	 */
	public function redact( string $text ): string {
		if ( null === $this->secrets ) {
			$values = array_values( $this->settings->configured_secrets() );

			// Longest first: a short value that is a substring of a longer one
			// would otherwise cut the longer one in half and leave a fragment.
			usort( $values, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

			$this->secrets = array_filter( $values, static fn( string $v ): bool => strlen( $v ) >= 8 );
		}

		foreach ( $this->secrets as $secret ) {
			$text = str_replace( $secret, '[redacted]', $text );
		}

		$patterns = array(
			'/(\bkey=)[^&\s"\']+/i'                                 => '$1[redacted]', // Google puts it in the URL.
			'/(\bauthorization["\']?\s*[:=]\s*["\']?)\S+/i'         => '$1[redacted]',
			'/\b(Bearer|Key|Token)\s+\S{8,}/i'                      => '$1 [redacted]',
			'/\br8_[A-Za-z0-9]{20,}/'                               => '[redacted]', // Replicate.
			'/\bAIza[0-9A-Za-z_\-]{30,}/'                           => '[redacted]', // Google.
			'/\bsk-[A-Za-z0-9_\-]{20,}/'                            => '[redacted]', // OpenAI.
			'/\b[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}:[0-9a-f]{16,}/i' => '[redacted]', // fal.
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$result = preg_replace( $pattern, $replacement, $text );
			$text   = null === $result ? $text : $result;
		}

		return $text;
	}
}
