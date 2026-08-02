<?php
/**
 * Translation and moderation contract.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Providers;

use AiCake\Domain\PromptAnalysis;

defined( 'ABSPATH' ) || exit;

/**
 * Translation and moderation in one call (PLAN.md §8.5, §10 Layer 2).
 *
 * Combining them halves latency and call count, and lets the classifier see
 * the original Lithuanian rather than a lossy translation of it.
 */
interface TextProvider {

	/**
	 * Translate a Lithuanian prompt to English and classify it.
	 *
	 * Must fail closed: a transport error, a malformed response or an
	 * unparseable verdict all come back as PromptAnalysis::BLOCK, never allow.
	 *
	 * @param string $prompt_lt The customer's text, exactly as typed.
	 */
	public function analyse( string $prompt_lt ): PromptAnalysis;

	/**
	 * Published price for one analysis call, in USD.
	 */
	public function estimate_cost(): float;

	/**
	 * Stable identifier.
	 */
	public function id(): string;

	/**
	 * The model this instance is configured to call.
	 */
	public function model(): string;

	/**
	 * Whether this provider can be called at all.
	 */
	public function is_configured(): bool;
}
