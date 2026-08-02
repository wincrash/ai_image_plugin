<?php
/**
 * Upscaling contract.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md §8.5.
 *
 * Works on bytes rather than paths, because the local GD implementation and a
 * remote paid upscaler have nothing else in common — the remote one has to
 * upload anyway, and the local one should not be forced to round-trip through
 * a temporary file it does not need.
 */
interface UpscaleProvider {

	/**
	 * Enlarge an image.
	 *
	 * @param string $bytes  Source image data.
	 * @param int    $factor Multiplier, e.g. 4 for 4x.
	 * @return string Enlarged image data, or '' on failure.
	 */
	public function upscale( string $bytes, int $factor ): string;

	/**
	 * Largest factor this provider will do in one pass.
	 */
	public function max_factor(): int;

	/**
	 * Published price, in USD, for upscaling an image of this size.
	 *
	 * @param float $megapixels Size of the *source* image.
	 */
	public function estimate_cost( float $megapixels ): float;

	/**
	 * Stable identifier.
	 */
	public function id(): string;

	/**
	 * Whether this provider can be called at all.
	 */
	public function is_configured(): bool;
}
