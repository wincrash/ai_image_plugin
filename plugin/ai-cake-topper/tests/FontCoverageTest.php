<?php
/**
 * Tests for Lithuanian font coverage.
 *
 * @package AiCake
 */

declare( strict_types=1 );

use AiCake\Imaging\TtfCmap;

/**
 * PLAN.md §9.4 asks for coverage "verified glyph-by-glyph against the full
 * Lithuanian alphabet at build time by a test that fails if coverage
 * regresses". This is that test.
 *
 * It matters more than it looks. A font that lacks `ė` does not fail loudly —
 * it renders a blank box, and the first anyone knows about it is a customer
 * holding a cake with a rectangle where their child's name should be.
 */
class FontCoverageTest extends TestCase {

	/**
	 * The nine Lithuanian letters that are not plain ASCII, both cases.
	 *
	 * @var string[]
	 */
	private const LITHUANIAN = array(
		'ą', 'č', 'ę', 'ė', 'į', 'š', 'ų', 'ū', 'ž',
		'Ą', 'Č', 'Ę', 'Ė', 'Į', 'Š', 'Ų', 'Ū', 'Ž',
	);

	/**
	 * Every bundled font covers Lithuanian, or it must not ship.
	 */
	public function test_every_bundled_font_covers_lithuanian(): void {
		$fonts = $this->bundled();

		$this->assert_true( array() !== $fonts, 'there is at least one bundled font' );

		foreach ( $fonts as $path ) {
			$missing = TtfCmap::missing( $path, self::LITHUANIAN );

			$this->assert_same(
				array(),
				$missing,
				sprintf( '%s covers Lithuanian (missing: %s)', basename( $path ), implode( ' ', $missing ) )
			);
		}
	}

	/**
	 * The parser reads a real font rather than quietly returning nothing —
	 * which would make the test above pass for the wrong reason.
	 */
	public function test_the_parser_actually_reads_the_font(): void {
		$fonts = $this->bundled();

		if ( array() === $fonts ) {
			return;
		}

		$codepoints = TtfCmap::codepoints( reset( $fonts ) );

		$this->assert_true( is_array( $codepoints ), 'the cmap table parses' );
		$this->assert_true( is_array( $codepoints ) && count( $codepoints ) > 200, 'and yields a real character set' );

		// Spot-check both ends of what we care about.
		$this->assert_true( isset( $codepoints[ 0x0041 ] ), 'contains A' );
		$this->assert_true( isset( $codepoints[ 0x0117 ] ), 'contains ė (U+0117)' );
		$this->assert_true( isset( $codepoints[ 0x016B ] ), 'contains ū (U+016B)' );
		$this->assert_true( isset( $codepoints[ 0x0020 ] ), 'contains a space' );
	}

	/**
	 * Codepoint decoding, since everything above depends on it.
	 */
	public function test_codepoints_are_decoded_correctly(): void {
		$this->assert_same( 0x0041, TtfCmap::codepoint_of( 'A' ), 'A' );
		$this->assert_same( 0x0117, TtfCmap::codepoint_of( 'ė' ), 'ė' );
		$this->assert_same( 0x016B, TtfCmap::codepoint_of( 'ū' ), 'ū' );
		$this->assert_same( 0x017D, TtfCmap::codepoint_of( 'Ž' ), 'Ž' );
		$this->assert_same( 0, TtfCmap::codepoint_of( '' ), 'empty string' );
	}

	/**
	 * A font that cannot be read is reported as covering nothing, not as
	 * covering everything.
	 */
	public function test_an_unreadable_font_fails_closed(): void {
		$missing = TtfCmap::missing( '/nonexistent/font.ttf', self::LITHUANIAN );

		$this->assert_same( count( self::LITHUANIAN ), count( $missing ), 'a missing file reports every character missing' );
		$this->assert_same( null, TtfCmap::codepoints( '/nonexistent/font.ttf' ), 'and yields no codepoints' );
	}

	/**
	 * Bundled font files.
	 *
	 * @return string[]
	 */
	private function bundled(): array {
		$found = glob( AICAKE_FONT_DIR . '/*.ttf' );

		return false === $found ? array() : $found;
	}
}
