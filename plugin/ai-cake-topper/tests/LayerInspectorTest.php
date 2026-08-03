<?php
/**
 * Tests for the gate on customer-supplied bitmaps.
 *
 * @package AiCake
 */

declare( strict_types=1 );

use AiCake\Imaging\LayerInspector;
use AiCake\Support\Logger;
use AiCake\Support\Settings;

/**
 * D-033's load-bearing check.
 *
 * This is the only thing standing between the text-layer endpoint and
 * arbitrary artwork. Moderation layers 0–2 read the prompt; a bitmap carries
 * none of it. So the tests that matter here are the *rejections* — an
 * inspector that accepts everything passes any test that only feeds it good
 * input, and would look completely healthy while protecting nothing.
 */
class LayerInspectorTest extends TestCase {

	/**
	 * An inspector with a logger that needs no WordPress.
	 */
	private function inspector(): LayerInspector {
		return new LayerInspector( new Logger( new Settings() ) );
	}

	/**
	 * A transparent canvas of a known size.
	 *
	 * @param int $w Width.
	 * @param int $h Height.
	 */
	private function canvas( int $w = 100, int $h = 100 ): GdImage {
		$image = imagecreatetruecolor( $w, $h );
		imagealphablending( $image, false );
		imagesavealpha( $image, true );
		imagefilledrectangle( $image, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );

		return $image;
	}

	/**
	 * Write one pixel with an explicit alpha.
	 *
	 * @param GdImage $image Target.
	 * @param int     $x     Column.
	 * @param int     $y     Row.
	 * @param int     $r     Red.
	 * @param int     $g     Green.
	 * @param int     $b     Blue.
	 * @param int     $a     GD alpha, 0 opaque .. 127 clear.
	 */
	private function dot( GdImage $image, int $x, int $y, int $r, int $g, int $b, int $a = 0 ): void {
		imagesetpixel( $image, $x, $y, imagecolorallocatealpha( $image, $r, $g, $b, $a ) );
	}

	/**
	 * Ordinary text in one declared colour is accepted.
	 */
	public function test_a_single_colour_layer_passes(): void {
		$layer = $this->canvas();

		for ( $i = 0; $i < 50; $i++ ) {
			$this->dot( $layer, $i, 10, 0xC6, 0x28, 0x28 );
		}

		$verdict = $this->inspector()->inspect( $layer, array( '#c62828' ) );

		$this->assert_true( $verdict['ok'], 'a single declared colour is accepted' );
		$this->assert_same( 50, $verdict['detail']['ink_px'], 'ink is counted' );

		imagedestroy( $layer );
	}

	/**
	 * Antialiasing is free.
	 *
	 * A glyph edge over transparency keeps the fill colour and varies only
	 * alpha. If this ever fails, every real layer is rejected — the check would
	 * be worse than useless, because it would look strict while being unusable.
	 */
	public function test_antialiased_edges_pass(): void {
		$layer = $this->canvas();

		foreach ( array( 0, 20, 60, 100, 126 ) as $i => $alpha ) {
			$this->dot( $layer, $i, 5, 0x37, 0x47, 0x4F, $alpha );
		}

		$verdict = $this->inspector()->inspect( $layer, array( '#37474f' ) );

		$this->assert_true( $verdict['ok'], 'partial alpha at the declared colour passes' );

		imagedestroy( $layer );
	}

	/**
	 * A stroke over a fill blends the two, and those blends are legitimate.
	 */
	public function test_a_blend_between_two_declared_colours_passes(): void {
		$layer = $this->canvas();

		// The grey ramp a black stroke over white text really produces.
		foreach ( array( 0, 64, 128, 192, 255 ) as $i => $level ) {
			$this->dot( $layer, $i, 5, $level, $level, $level );
		}

		$verdict = $this->inspector()->inspect( $layer, array( '#000000', '#ffffff' ) );

		$this->assert_true( $verdict['ok'], 'the segment between black and white passes' );

		imagedestroy( $layer );
	}

	/**
	 * A colour off the palette is refused, and named.
	 *
	 * One pixel. A franchise character is thousands, but the check has to bite
	 * at one or "mostly text with a small logo pasted in" walks through.
	 */
	public function test_one_undeclared_pixel_is_refused(): void {
		$layer = $this->canvas();

		for ( $i = 0; $i < 50; $i++ ) {
			$this->dot( $layer, $i, 10, 0x00, 0x00, 0x00 );
		}

		$this->dot( $layer, 80, 80, 0x00, 0xC0, 0x00 );

		$verdict = $this->inspector()->inspect( $layer, array( '#000000' ) );

		$this->assert_true( ! $verdict['ok'], 'an undeclared green pixel is refused' );
		$this->assert_same( 'off_palette', $verdict['reason'], 'and refused for the right reason' );
		$this->assert_same( '#00c000', $verdict['detail']['colour'], 'the offending colour is reported' );

		imagedestroy( $layer );
	}

	/**
	 * Declaring two colours does not admit a third that merely sits nearby.
	 *
	 * Red is not on the black–white segment. This is the test that would fail
	 * if the segment tolerance were ever widened into a general amnesty.
	 */
	public function test_a_colour_off_the_segment_is_refused(): void {
		$layer = $this->canvas();

		$this->dot( $layer, 5, 5, 0xFF, 0x00, 0x00 );

		$verdict = $this->inspector()->inspect( $layer, array( '#000000', '#ffffff' ) );

		$this->assert_same( 'off_palette', $verdict['reason'], 'red is not between black and white' );

		imagedestroy( $layer );
	}

	/**
	 * A photograph is refused.
	 *
	 * Deterministic pseudo-noise rather than a real JPEG, because the test must
	 * not depend on a fixture file — but it is the same thing as far as the
	 * check is concerned: colours scattered through the cube.
	 */
	public function test_a_picture_is_refused(): void {
		$layer = $this->canvas();

		for ( $y = 0; $y < 100; $y++ ) {
			for ( $x = 0; $x < 100; $x++ ) {
				$this->dot( $layer, $x, $y, ( $x * 7 ) % 256, ( $y * 13 ) % 256, ( $x * $y ) % 256 );
			}
		}

		$verdict = $this->inspector()->inspect( $layer, array( '#000000', '#ffffff' ) );

		$this->assert_true( ! $verdict['ok'], 'a picture is refused' );

		imagedestroy( $layer );
	}

	/**
	 * The hole the coverage ceiling exists to close.
	 *
	 * Black and white declared means the whole grey ramp satisfies the colour
	 * rule, so a *greyscale* image passes that half of the check entirely. It
	 * is density that catches it. This test is the reason `MAX_COVERAGE`
	 * exists, and deleting the ceiling must turn it red.
	 */
	public function test_a_dense_greyscale_image_is_refused_on_coverage(): void {
		$layer = $this->canvas();

		for ( $y = 0; $y < 100; $y++ ) {
			for ( $x = 0; $x < 100; $x++ ) {
				$level = ( ( $x * 3 ) + ( $y * 5 ) ) % 256;
				$this->dot( $layer, $x, $y, $level, $level, $level );
			}
		}

		$verdict = $this->inspector()->inspect( $layer, array( '#000000', '#ffffff' ) );

		$this->assert_true( ! $verdict['ok'], 'a greyscale picture is refused' );
		$this->assert_same( 'too_dense', $verdict['reason'], 'and it is density that catches it, not colour' );

		imagedestroy( $layer );
	}

	/**
	 * Enough declared colours and the segments between them mesh the cube.
	 */
	public function test_the_palette_is_capped(): void {
		$layer = $this->canvas();
		$this->dot( $layer, 5, 5, 0x00, 0x00, 0x00 );

		$declared = array( '#000000', '#ffffff', '#ff0000', '#00ff00', '#0000ff' );

		$verdict = $this->inspector()->inspect( $layer, $declared );

		$this->assert_same( 'too_many_colours', $verdict['reason'], 'five declared colours is too many' );
		$this->assert_same( 5, $verdict['detail']['declared'], 'and the count is reported' );

		imagedestroy( $layer );
	}

	/**
	 * Nothing declared, or nothing drawn, is not a pass.
	 *
	 * An empty palette must never mean "no constraints".
	 */
	public function test_empty_input_is_not_a_pass(): void {
		$layer = $this->canvas();
		$this->dot( $layer, 5, 5, 0x00, 0x00, 0x00 );

		$verdict = $this->inspector()->inspect( $layer, array() );
		$this->assert_same( 'no_colours', $verdict['reason'], 'no declared colours is a refusal' );

		$verdict = $this->inspector()->inspect( $layer, array( 'not-a-colour', '#zzzzzz' ) );
		$this->assert_same( 'no_colours', $verdict['reason'], 'unparseable colours are not colours' );

		imagedestroy( $layer );

		$blank   = $this->canvas();
		$verdict = $this->inspector()->inspect( $blank, array( '#000000' ) );
		$this->assert_same( 'empty', $verdict['reason'], 'a layer with no ink is a refusal' );

		imagedestroy( $blank );
	}

	/**
	 * Duplicates do not consume the palette budget.
	 */
	public function test_duplicate_declarations_collapse(): void {
		$layer = $this->canvas();
		$this->dot( $layer, 5, 5, 0x00, 0x00, 0x00 );

		$verdict = $this->inspector()->inspect(
			$layer,
			array( '#000000', '#000000', '#FFFFFF', '#ffffff', '#000000' )
		);

		$this->assert_true( $verdict['ok'], 'five declarations of two colours is two colours' );
		$this->assert_same( 2, $verdict['detail']['colours'], 'and it counts two' );

		imagedestroy( $layer );
	}
}
