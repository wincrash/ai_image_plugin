<?php
/**
 * What an image provider gives back.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The outcome of one generation attempt.
 *
 * Always carries the image bytes, never a provider URL. Replicate returns a
 * URL and Gemini returns base64; normalising here means nothing downstream has
 * to know which, and no expiring provider URL can end up in the database.
 */
class GenerationResult {

	/**
	 * @param bool   $ok         Whether an image was produced.
	 * @param string $bytes      Raw image data.
	 * @param string $mime       Image MIME type.
	 * @param int    $width      Pixels.
	 * @param int    $height     Pixels.
	 * @param string $provider   Provider id that served this.
	 * @param string $model      Model identifier.
	 * @param int    $seed       Seed actually used, 0 when unknown.
	 * @param float  $cost_usd   What we believe this cost.
	 * @param int    $latency_ms Wall clock.
	 * @param string $error      Failure description, '' on success.
	 * @param string $error_code Short machine-readable code.
	 */
	public function __construct(
		public bool $ok = false,
		public string $bytes = '',
		public string $mime = 'image/png',
		public int $width = 0,
		public int $height = 0,
		public string $provider = '',
		public string $model = '',
		public int $seed = 0,
		public float $cost_usd = 0.0,
		public int $latency_ms = 0,
		public string $error = '',
		public string $error_code = ''
	) {}

	/**
	 * A successful generation.
	 *
	 * @param string               $bytes Image data.
	 * @param array<string, mixed> $meta  provider, model, seed, cost_usd, latency_ms, mime.
	 */
	public static function success( string $bytes, array $meta = array() ): self {
		$result = new self(
			true,
			$bytes,
			(string) ( $meta['mime'] ?? 'image/png' ),
			0,
			0,
			(string) ( $meta['provider'] ?? '' ),
			(string) ( $meta['model'] ?? '' ),
			(int) ( $meta['seed'] ?? 0 ),
			(float) ( $meta['cost_usd'] ?? 0.0 ),
			(int) ( $meta['latency_ms'] ?? 0 )
		);

		$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false !== $size ) {
			$result->width  = (int) $size[0];
			$result->height = (int) $size[1];
			$result->mime   = (string) ( $size['mime'] ?? $result->mime );
		}

		return $result;
	}

	/**
	 * A failed generation.
	 *
	 * @param string               $code    Machine-readable code.
	 * @param string               $message Human description.
	 * @param array<string, mixed> $meta    provider, model, latency_ms.
	 */
	public static function failure( string $code, string $message, array $meta = array() ): self {
		return new self(
			false,
			'',
			'',
			0,
			0,
			(string) ( $meta['provider'] ?? '' ),
			(string) ( $meta['model'] ?? '' ),
			0,
			0.0,
			(int) ( $meta['latency_ms'] ?? 0 ),
			$message,
			$code
		);
	}

	/**
	 * Whether the failure is one the registry should fall through on.
	 *
	 * A billing failure is worth trying the next provider for. A prompt the
	 * provider refused is not — every provider will refuse it.
	 */
	public function should_fall_through(): bool {
		return ! $this->ok && 'content_rejected' !== $this->error_code;
	}
}
