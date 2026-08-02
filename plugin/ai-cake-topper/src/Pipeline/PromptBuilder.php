<?php
/**
 * The prompt actually sent to the image provider.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Pipeline;

use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Applies the house style suffix.
 *
 * Small enough to inline, kept separate because it is the single highest-value
 * thing to tune later: Phase 0's remaining job is largely "find the wording
 * that makes this print well", and it needs one place to change.
 */
class PromptBuilder {

	/**
	 * Every clause is stated in the positive, and that is not a style
	 * preference — it is a measured requirement.
	 *
	 * A flux-dev test with "no cake or background needed" produced exactly a
	 * cake, photorealistic and dark. Diffusion models do not have a "not"
	 * operator; a negative clause simply adds its own noun to the scene. The
	 * positive form below produced the actual product on the first attempt
	 * (D-019).
	 */
	public const DEFAULT_SUFFIX = 'flat vector illustration, thick clean outlines, '
		. 'bright saturated flat colours, centred single subject, '
		. 'isolated on a plain solid white background, '
		. 'simple childrens picture book style, no text';

	private Settings $settings;

	/**
	 * @param Settings $settings Configuration.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The configured suffix.
	 */
	public function suffix(): string {
		return (string) $this->settings->get( 'style_suffix', self::DEFAULT_SUFFIX );
	}

	/**
	 * Combine a translated prompt with the house style.
	 *
	 * @param string $prompt_en Translated customer prompt.
	 */
	public function build( string $prompt_en ): string {
		$prompt_en = trim( $prompt_en );
		$suffix    = trim( $this->suffix() );

		if ( '' === $suffix ) {
			return $prompt_en;
		}

		if ( '' === $prompt_en ) {
			return $suffix;
		}

		return $prompt_en . ', ' . $suffix;
	}
}
