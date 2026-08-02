<?php
/**
 * Image generation contract.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Providers;

use AiCake\Domain\GenerationRequest;
use AiCake\Domain\GenerationResult;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md §8.5. A provider swap is a settings change, not a code change.
 */
interface ImageProvider {

	/**
	 * Generate one image.
	 *
	 * Implementations must not throw for a provider failure — they return a
	 * failed GenerationResult so the registry can fall through to the next
	 * provider as ordinary control flow.
	 *
	 * @param GenerationRequest $request What to draw.
	 */
	public function generate( GenerationRequest $request ): GenerationResult;

	/**
	 * Aspect ratios this provider accepts, as 'w:h' strings.
	 *
	 * @return string[]
	 */
	public function supported_aspects(): array;

	/**
	 * Whether explicit pixel dimensions can be requested, rather than only a
	 * ratio from the list above.
	 */
	public function supports_arbitrary_dimensions(): bool;

	/**
	 * Published price for one image at the given size, in USD.
	 *
	 * @param float $megapixels Output size.
	 */
	public function estimate_cost( float $megapixels ): float;

	/**
	 * Stable identifier, stored in the designs.provider column.
	 */
	public function id(): string;

	/**
	 * The model this instance is configured to call.
	 */
	public function model(): string;

	/**
	 * Whether this provider has everything it needs to be called at all —
	 * principally an API key.
	 */
	public function is_configured(): bool;
}
