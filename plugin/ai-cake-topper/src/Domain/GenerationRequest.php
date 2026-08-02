<?php
/**
 * What we ask an image provider for.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A single image generation request.
 *
 * Holds the English prompt, not the Lithuanian one: translation happens in the
 * TextProvider before we get here, and the image provider never sees the
 * customer's original text.
 */
class GenerationRequest {

	/**
	 * @param string      $prompt_en     Final English prompt, style suffix already applied.
	 * @param string      $aspect        '1:1', '2:3', '3:2' — see PLAN.md §3.2.
	 * @param string      $output_format 'png' or 'webp'. PNG for anything heading to print.
	 * @param int|null    $seed          Fixed seed for reproducibility, or null for random.
	 * @param string|null $model         Overrides the provider's configured default.
	 * @param float       $megapixels    Target size. 1 MP is 1024×1024.
	 */
	public function __construct(
		public string $prompt_en,
		public string $aspect = '1:1',
		public string $output_format = 'png',
		public ?int $seed = null,
		public ?string $model = null,
		public float $megapixels = 1.0
	) {}

	/**
	 * Width and height for a provider that needs explicit pixels rather than
	 * a ratio string.
	 *
	 * @return array{0:int, 1:int}
	 */
	public function dimensions(): array {
		$pixels = max( 0.25, $this->megapixels ) * 1000000;

		list( $w_ratio, $h_ratio ) = $this->ratio();

		// Solve w*h = pixels with w/h fixed, then round to a multiple of 32 —
		// diffusion models want dimensions divisible by 8, and 32 is safe
		// across every provider we have looked at.
		$scale  = sqrt( $pixels / ( $w_ratio * $h_ratio ) );
		$width  = (int) ( round( $w_ratio * $scale / 32 ) * 32 );
		$height = (int) ( round( $h_ratio * $scale / 32 ) * 32 );

		return array( max( 256, $width ), max( 256, $height ) );
	}

	/**
	 * The aspect as two numbers.
	 *
	 * @return array{0:float, 1:float}
	 */
	public function ratio(): array {
		$parts = explode( ':', $this->aspect );

		$w = isset( $parts[0] ) ? (float) $parts[0] : 1.0;
		$h = isset( $parts[1] ) ? (float) $parts[1] : 1.0;

		if ( $w <= 0 || $h <= 0 ) {
			return array( 1.0, 1.0 );
		}

		return array( $w, $h );
	}
}
