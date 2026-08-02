<?php
/**
 * Layer 0 — input sanity.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Moderation;

defined( 'ABSPATH' ) || exit;

/**
 * The free layer that runs first (PLAN.md §10 Layer 0).
 *
 * Length cap, control characters, and obvious nonsense. Every prompt this
 * rejects is one that would otherwise have cost an LLM call to discover was
 * meaningless.
 *
 * Deliberately conservative. A false positive here is a customer being told
 * their perfectly reasonable request is invalid, which is worse than paying a
 * hundredth of a cent to have the LLM say so politely.
 */
class Sanitiser {

	/**
	 * Long enough for any real decoration request. §10's figure.
	 */
	public const MAX_LENGTH = 500;

	/**
	 * Below this there is nothing to draw.
	 */
	public const MIN_LETTERS = 3;

	/**
	 * Clean a prompt without judging it.
	 *
	 * @param string $prompt Raw input.
	 */
	public function clean( string $prompt ): string {
		// Control characters, except that tabs and newlines become spaces.
		$clean = preg_replace( '/[\r\n\t]+/u', ' ', $prompt );
		$clean = preg_replace( '/[\x00-\x1F\x7F]/u', '', (string) $clean );
		$clean = preg_replace( '/\s+/u', ' ', (string) $clean );
		$clean = trim( (string) $clean );

		if ( mb_strlen( $clean ) > self::MAX_LENGTH ) {
			$clean = mb_substr( $clean, 0, self::MAX_LENGTH );
		}

		return $clean;
	}

	/**
	 * Judge a cleaned prompt.
	 *
	 * @param string $prompt Cleaned input.
	 */
	public function check( string $prompt ): Verdict {
		if ( '' === $prompt ) {
			return Verdict::blocked( 'sanity', 'empty' );
		}

		$letters = preg_match_all( '/\p{L}/u', $prompt );

		if ( false === $letters || $letters < self::MIN_LETTERS ) {
			return Verdict::blocked( 'sanity', 'too_few_letters' );
		}

		/*
		 * A run of the same character — "aaaaaaaa", "........" — is someone
		 * testing the box rather than ordering a cake.
		 */
		if ( 1 === preg_match( '/^(.)\1+$/u', $prompt ) ) {
			return Verdict::blocked( 'sanity', 'repeated_character' );
		}

		/*
		 * Keyboard mashing has almost no vowels. Lithuanian is vowel-rich, so
		 * a threshold of one in eight is generous — "Sveiki" is 50% vowels and
		 * even a consonant-heavy word like "skrandis" is 25%.
		 */
		$vowels = preg_match_all( '/[aąeęėiįyouųū]/iu', $prompt );

		if ( false !== $vowels && $letters >= 8 && $vowels < ( $letters / 8 ) ) {
			return Verdict::blocked( 'sanity', 'no_vowels' );
		}

		return Verdict::allowed( 'sanity' );
	}
}
