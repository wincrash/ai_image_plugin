<?php
/**
 * Local bicubic upscaling.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Providers\Upscale;

use AiCake\Providers\UpscaleProvider;
use AiCake\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * GD bicubic. Free, local, and the only upscaler production is guaranteed to
 * have (D-013/D-015).
 *
 * PLAN.md §8 treats a paid upscaler as the primary and this as the fallback.
 * In practice this is what gets measured first: Suite B is explicitly judged
 * against GD bicubic rather than Imagick Lanczos, because benchmarking Lanczos
 * would benchmark a fallback production will never get. If flat illustration
 * survives 4× bicubic at 300 DPI, the paid upscaler is an upgrade rather than
 * a dependency.
 */
class GdUpscaler implements UpscaleProvider {

	/**
	 * Beyond 4× bicubic stops adding anything but blur and memory pressure.
	 */
	private const MAX_FACTOR = 4;

	/**
	 * Refuse to allocate a canvas larger than this many pixels. An A4 sheet at
	 * 300 DPI is 8.7 M, so this leaves headroom without inviting a fatal.
	 */
	private const MAX_PIXELS = 40000000;

	private Logger $logger;

	/**
	 * @param Logger $logger Logging.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return 'gd-bicubic';
	}

	/**
	 * Always available — GD is a hard requirement of the plugin.
	 */
	public function is_configured(): bool {
		return function_exists( 'imagescale' ) && function_exists( 'imagecreatefromstring' );
	}

	/**
	 * Largest single-pass factor.
	 */
	public function max_factor(): int {
		return self::MAX_FACTOR;
	}

	/**
	 * Free. That is the entire point of it.
	 *
	 * @param float $megapixels Source size, unused.
	 */
	public function estimate_cost( float $megapixels ): float {
		unset( $megapixels );

		return 0.0;
	}

	/**
	 * Enlarge an image.
	 *
	 * @param string $bytes  Source image data.
	 * @param int    $factor Multiplier.
	 * @return string PNG data, or '' on failure.
	 */
	public function upscale( string $bytes, int $factor ): string {
		if ( ! $this->is_configured() || '' === $bytes ) {
			return '';
		}

		$factor = max( 1, min( self::MAX_FACTOR, $factor ) );

		if ( 1 === $factor ) {
			return $bytes;
		}

		$source = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $source ) {
			$this->logger->warning( 'Upscale failed: the source is not a decodable image.' );

			return '';
		}

		$width  = imagesx( $source ) * $factor;
		$height = imagesy( $source ) * $factor;

		if ( $width * $height > self::MAX_PIXELS ) {
			imagedestroy( $source );
			$this->logger->error(
				'Upscale refused: the result would be too large to allocate.',
				array(
					'width'  => $width,
					'height' => $height,
				)
			);

			return '';
		}

		// Preserve transparency: toppers are shaped, so the corners outside a
		// circle mask must stay transparent rather than turn black.
		imagealphablending( $source, false );
		imagesavealpha( $source, true );

		$scaled = imagescale( $source, $width, $height, IMG_BICUBIC );
		imagedestroy( $source );

		if ( false === $scaled ) {
			$this->logger->warning( 'Upscale failed inside imagescale().' );

			return '';
		}

		imagealphablending( $scaled, false );
		imagesavealpha( $scaled, true );

		ob_start();
		imagepng( $scaled, null, 6 );
		$out = (string) ob_get_clean();

		imagedestroy( $scaled );

		return $out;
	}
}
