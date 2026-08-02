<?php
/**
 * Tests for Lithuanian normalisation and stemming.
 *
 * @package AiCake
 */

declare( strict_types=1 );

use AiCake\Moderation\LtNormaliser;

/**
 * PLAN.md §10 Layer 1, and §19's warning that this is one of the three places
 * most likely to be subtly wrong.
 *
 * Every case below is a real inflected form, not an invented one. The whole
 * class exists because a substring match on "Elsa" misses "Elsos suknelė",
 * which is how a customer would actually write it.
 */
class LtNormaliserTest extends TestCase {

	/**
	 * Diacritics fold to ASCII, so an evasive or lazy spelling still matches.
	 */
	public function test_diacritics_fold(): void {
		$this->assert_same( 'aceeisuuz', LtNormaliser::fold( 'ąčęėįšųūž' ), 'lower case' );
		$this->assert_same( 'aceeisuuz', LtNormaliser::fold( 'ĄČĘĖĮŠŲŪŽ' ), 'upper case folds and lowers' );
		$this->assert_same( 'zmogus', LtNormaliser::fold( 'Žmogus' ), 'Žmogus' );
		$this->assert_same( 'ledo salis', LtNormaliser::fold( 'Ledo šalis' ), 'Ledo šalis' );
	}

	/**
	 * Punctuation is a separator, so hyphenation cannot be used to slip past.
	 */
	public function test_tokenising_ignores_punctuation(): void {
		$this->assert_same( array( 'zmogus', 'voras' ), LtNormaliser::tokenise( 'Žmogus-voras' ), 'hyphenated' );
		$this->assert_same( array( 'zmogus', 'voras' ), LtNormaliser::tokenise( 'žmogus  voras!' ), 'spaced and punctuated' );
		$this->assert_same( array(), LtNormaliser::tokenise( '  ...  ' ), 'punctuation only' );
	}

	/**
	 * The case that motivates the entire class.
	 */
	public function test_elsa_declines_to_one_stem(): void {
		$base = LtNormaliser::stem( 'elsa' );

		foreach ( array( 'elsa', 'elsos', 'elsai', 'elsą', 'elsa' ) as $form ) {
			$folded = LtNormaliser::fold( $form );
			$this->assert_same( $base, LtNormaliser::stem( $folded ), "'{$form}' stems the same as 'elsa'" );
		}
	}

	/**
	 * Spider-Man is translated in Lithuanian, and the translation declines.
	 */
	public function test_translated_names_decline_too(): void {
		$this->assert_same(
			LtNormaliser::stem( 'zmogus' ),
			LtNormaliser::stem( 'zmogaus' ),
			'žmogus / žmogaus'
		);
		$this->assert_same( LtNormaliser::stem( 'voras' ), LtNormaliser::stem( 'voro' ), 'voras / voro' );
		$this->assert_same( LtNormaliser::stem( 'sunyciai' ), LtNormaliser::stem( 'sunyciu' ), 'šunyčiai / šunyčių' );
		$this->assert_same( LtNormaliser::stem( 'patruliai' ), LtNormaliser::stem( 'patruliu' ), 'patruliai / patrulių' );
	}

	/**
	 * The two §3.2 cases a naive matcher fails on.
	 */
	public function test_the_genitive_cases_are_caught(): void {
		$this->assert_true(
			LtNormaliser::contains_phrase( 'Elsos suknelė', 'Elsa' ),
			'"Elsos suknelė" contains "Elsa"'
		);
		$this->assert_true(
			LtNormaliser::contains_phrase( 'Žmogaus voro tinklas', 'Žmogus-voras' ),
			'"Žmogaus voro tinklas" contains "Žmogus-voras"'
		);
		$this->assert_true(
			LtNormaliser::contains_phrase( 'noriu torto su šunyčiais patruliais', 'šunyčiai patruliai' ),
			'instrumental plural still matches'
		);
	}

	/**
	 * Matching is whole-token, so a blocklist entry cannot ban a word that
	 * merely contains it.
	 */
	public function test_matching_is_not_substring(): void {
		$this->assert_true(
			! LtNormaliser::contains_phrase( 'elsass', 'Elsa' ),
			'a longer word is not a match'
		);
		$this->assert_true(
			! LtNormaliser::contains_phrase( 'katinas', 'kat' ),
			'a prefix is not a match'
		);
	}

	/**
	 * A multi-word phrase must appear contiguously, in order.
	 */
	public function test_phrases_must_be_contiguous(): void {
		$this->assert_true(
			! LtNormaliser::contains_phrase( 'žmogus prie voro tinklo', 'Žmogus-voras' ),
			'words separated by another word do not match'
		);
		$this->assert_true(
			! LtNormaliser::contains_phrase( 'voras žmogus', 'Žmogus-voras' ),
			'reversed order does not match'
		);
	}

	/**
	 * The false-positive check, which matters as much as any catch.
	 *
	 * An over-eager filter kills conversion silently: the customer sees a
	 * refusal, assumes the shop is broken, and leaves.
	 */
	public function test_innocent_prompts_are_untouched(): void {
		$innocent = array(
			'linksmas dinozauras su gimtadienio tortu',
			'gėlių vainikas su rožėmis ir bijūnais',
			'meškiukas su spalvotais balionais',
			'raudonas lenktyninis automobilis',
			'jūros gyvūnai, delfinas ir jūrų žvaigždė',
		);

		$terms = array( 'Elsa', 'Žmogus-voras', 'šunyčiai patruliai', 'Barbė', 'Pokemonas' );

		foreach ( $innocent as $prompt ) {
			foreach ( $terms as $term ) {
				$this->assert_true(
					! LtNormaliser::contains_phrase( $prompt, $term ),
					sprintf( '"%s" is not blocked by "%s"', $prompt, $term )
				);
			}
		}
	}

	/**
	 * A stem never shrinks past the floor, or short names would collide with
	 * everything.
	 */
	public function test_stems_have_a_floor(): void {
		$this->assert_same( 'kas', LtNormaliser::stem( 'kas' ), 'a three-letter word is left alone' );
		$this->assert_same( 'is', LtNormaliser::stem( 'is' ), 'a two-letter word is left alone' );
		$this->assert_true( strlen( LtNormaliser::stem( 'namas' ) ) >= 3, 'stems stay at least three characters' );
	}

	/**
	 * Words with no recognised ending survive intact.
	 */
	public function test_foreign_names_are_left_alone(): void {
		$this->assert_same( 'sonic', LtNormaliser::stem( 'sonic' ), 'Sonic has no Lithuanian ending' );
		$this->assert_same( 'pokemon', LtNormaliser::stem( 'pokemon' ), 'Pokemon' );
		$this->assert_same( 'minecraft', LtNormaliser::stem( 'minecraft' ), 'Minecraft' );
	}

	/**
	 * Empty and degenerate input does not explode.
	 */
	public function test_degenerate_input(): void {
		$this->assert_same( array(), LtNormaliser::stems( '' ), 'empty string' );
		$this->assert_true( ! LtNormaliser::contains_phrase( '', 'Elsa' ), 'empty haystack' );
		$this->assert_true( ! LtNormaliser::contains_phrase( 'Elsos suknelė', '' ), 'empty needle matches nothing' );
	}
}
