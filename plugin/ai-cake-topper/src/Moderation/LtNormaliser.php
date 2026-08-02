<?php
/**
 * Making Lithuanian comparable.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Moderation;

defined( 'ABSPATH' ) || exit;

/**
 * Diacritic folding and stem matching for Lithuanian (PLAN.md §10 Layer 1).
 *
 * This class exists because of one specific failure: **a substring match on
 * "Elsa" does not catch "Elsos suknelė".** Lithuanian inflects nouns heavily,
 * so a blocklist of dictionary forms catches almost nothing a real customer
 * types. Character names are usually translated outright too — Spider-Man is
 * `Žmogus-voras`, which declines to `Žmogaus voro`.
 *
 * Pure functions, no WordPress. §19 names this as one of the three classes
 * most likely to be subtly wrong, so it is unit-tested against real
 * declensions rather than invented ones.
 */
final class LtNormaliser {

	/**
	 * How short a stem may get.
	 *
	 * Three, not four, and the reason is `Elsa`: it folds to `elsa`, and
	 * stripping the `-a` leaves `els`. A four-character floor would refuse
	 * that strip, leave `elsa` and `elsos` as different stems, and miss the
	 * single most likely blocked prompt in the shop.
	 *
	 * The cost of three is that short terms are blunter instruments. Matching
	 * is whole-token, never substring, which keeps that in check — `els` only
	 * ever matches a word that stems to exactly `els`.
	 */
	private const MIN_STEM = 3;

	/**
	 * Lithuanian letters to their ASCII equivalents.
	 *
	 * Folding lets a customer who cannot be bothered with diacritics — or who
	 * is deliberately evading — be caught by the same list. `zmogus voras`
	 * hits the same entry as `Žmogus-voras`.
	 */
	private const FOLD = array(
		'ą' => 'a',
		'č' => 'c',
		'ę' => 'e',
		'ė' => 'e',
		'į' => 'i',
		'š' => 's',
		'ų' => 'u',
		'ū' => 'u',
		'ž' => 'z',
		'Ą' => 'a',
		'Č' => 'c',
		'Ę' => 'e',
		'Ė' => 'e',
		'Į' => 'i',
		'Š' => 's',
		'Ų' => 'u',
		'Ū' => 'u',
		'Ž' => 'z',
	);

	/**
	 * Case endings, already folded to ASCII, longest first.
	 *
	 * Not a complete Lithuanian morphology — a real stemmer would need the
	 * declension paradigms and a lexicon. This is the subset that actually
	 * appears when someone asks for a cartoon character, and being incomplete
	 * is acceptable because the LLM layer sits behind it and catches the rest
	 * (D-019). Being *wrong* would not be acceptable, which is what the tests
	 * are for.
	 */
	private const ENDINGS = array(
		'iuose',
		'iams',
		'iais',
		'uose',
		'omis',
		'emis',
		'iais',
		'aus',
		'ams',
		'ais',
		'ose',
		'oms',
		'yje',
		'iai',
		'ies',
		'iam',
		'iui',
		'imi',
		'ius',
		'ioms',
		'as',
		'is',
		'ys',
		'us',
		'os',
		'es',
		'ai',
		'ei',
		'ui',
		'iu',
		'io',
		'ia',
		'ie',
		'om',
		'ms',
		'us',
		'a',
		'o',
		'e',
		'u',
		'i',
		'y',
		's',
	);

	/**
	 * Not instantiable.
	 */
	private function __construct() {}

	/**
	 * Fold diacritics and lower-case.
	 *
	 * @param string $text Input.
	 */
	public static function fold( string $text ): string {
		$folded = strtr( $text, self::FOLD );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $folded, 'UTF-8' ) : strtolower( $folded );
	}

	/**
	 * Split into comparable word tokens.
	 *
	 * Everything that is not a letter or digit becomes a separator, so
	 * `Žmogus-voras` and `žmogus voras` tokenise identically.
	 *
	 * @param string $text Input.
	 * @return string[]
	 */
	public static function tokenise( string $text ): array {
		$folded = self::fold( $text );
		$split  = preg_split( '/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY );

		return false === $split ? array() : $split;
	}

	/**
	 * Strip one case ending, if doing so leaves enough word behind.
	 *
	 * @param string $word A single folded token.
	 */
	public static function stem( string $word ): string {
		$length = strlen( $word );

		if ( $length <= self::MIN_STEM ) {
			return $word;
		}

		foreach ( self::ENDINGS as $ending ) {
			$cut = $length - strlen( $ending );

			if ( $cut < self::MIN_STEM ) {
				continue;
			}

			if ( substr( $word, $cut ) === $ending ) {
				return substr( $word, 0, $cut );
			}
		}

		return $word;
	}

	/**
	 * Tokenise and stem in one step.
	 *
	 * @param string $text Input.
	 * @return string[]
	 */
	public static function stems( string $text ): array {
		return array_map( array( self::class, 'stem' ), self::tokenise( $text ) );
	}

	/**
	 * Whether a phrase appears in a text, comparing stems.
	 *
	 * Matching is **whole-token and contiguous**: the phrase's stems must
	 * appear as an unbroken run in the text's stems. That is what keeps a
	 * blocklist entry from silently banning an innocent word — §10 asks for
	 * word-boundary awareness, and comparing token sequences gives it for free
	 * rather than through fragile regex boundaries.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle   Phrase to find.
	 */
	public static function contains_phrase( string $haystack, string $needle ): bool {
		$text   = self::stems( $haystack );
		$phrase = self::stems( $needle );

		if ( array() === $phrase || count( $phrase ) > count( $text ) ) {
			return false;
		}

		$last = count( $text ) - count( $phrase );

		for ( $offset = 0; $offset <= $last; $offset++ ) {
			if ( array_slice( $text, $offset, count( $phrase ) ) === $phrase ) {
				return true;
			}
		}

		return false;
	}
}
