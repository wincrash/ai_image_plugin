<?php
/**
 * Tests for the PNG metadata the printer actually reads.
 *
 * @package AiCake
 */

declare( strict_types=1 );

use AiCake\Imaging\GdEngine;
use AiCake\Support\Logger;
use AiCake\Support\Settings;

/**
 * PLAN.md §9.1 — the `pHYs` chunk.
 *
 * This exists because the end-to-end check could not catch the bug it covers.
 * A print file with two contradictory `pHYs` chunks measured 300 DPI when read
 * by our own reader, produced a correct-looking file, passed every assertion —
 * and was malformed, with libpng warning about it and a second resolution of
 * 96 DPI sitting in the file for any decoder that preferred the last chunk.
 */
class GdEngineTest extends TestCase {

	/**
	 * The engine, with a logger that needs no WordPress.
	 */
	private function engine(): GdEngine {
		return new GdEngine( new Logger( new Settings() ) );
	}

	/**
	 * A small PNG straight out of GD, as the pipeline produces them.
	 */
	private function gd_png(): string {
		$image = imagecreatetruecolor( 32, 24 );
		imagefilledrectangle( $image, 0, 0, 31, 23, imagecolorallocate( $image, 200, 100, 50 ) );

		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();

		imagedestroy( $image );

		return $bytes;
	}

	/**
	 * Count occurrences the crude way, on purpose: the test must not share the
	 * chunk walker with the code it is checking.
	 *
	 * @param string $png PNG bytes.
	 */
	private function count_phys( string $png ): int {
		return substr_count( $png, 'pHYs' );
	}

	/**
	 * The whole point of the chunk.
	 */
	public function test_the_declared_resolution_survives_a_round_trip(): void {
		$engine = $this->engine();
		$png    = $engine->inject_phys( $this->gd_png(), 300 );

		$this->assert_same( 300, $engine->read_dpi( $png ), 'a 300 DPI file reports 300' );
		$this->assert_same( 600, $engine->read_dpi( $engine->inject_phys( $this->gd_png(), 600 ) ), '600 DPI' );
	}

	/**
	 * Exactly one, whatever GD wrote first.
	 *
	 * Recent libgd emits its own pHYs declaring the image's default 96 DPI. Two
	 * chunks is a malformed PNG, and the second one says the wrong thing.
	 */
	public function test_there_is_never_more_than_one_phys_chunk(): void {
		$engine = $this->engine();

		$once  = $engine->inject_phys( $this->gd_png(), 300 );
		$twice = $engine->inject_phys( $once, 300 );

		$this->assert_same( 1, $this->count_phys( $once ), 'one injection, one chunk' );
		$this->assert_same( 1, $this->count_phys( $twice ), 'injecting twice does not append a second' );
		$this->assert_same( 300, $engine->read_dpi( $twice ), 'and the surviving chunk is still ours' );
	}

	/**
	 * A re-injection at a different resolution must replace, not shadow.
	 */
	public function test_a_second_injection_overwrites_the_first(): void {
		$engine = $this->engine();

		$png = $engine->inject_phys( $this->gd_png(), 96 );
		$png = $engine->inject_phys( $png, 300 );

		$this->assert_same( 1, $this->count_phys( $png ), 'still one chunk' );
		$this->assert_same( 300, $engine->read_dpi( $png ), 'the newer resolution wins' );
	}

	/**
	 * Rewriting the chunk list must not damage the image.
	 */
	public function test_the_image_still_decodes_afterwards(): void {
		$engine = $this->engine();
		$png    = $engine->inject_phys( $this->gd_png(), 300 );

		$size = getimagesizefromstring( $png );

		$this->assert_true( is_array( $size ), 'the result is still a readable PNG' );
		$this->assert_same( 32, is_array( $size ) ? $size[0] : 0, 'width survived' );
		$this->assert_same( 24, is_array( $size ) ? $size[1] : 0, 'height survived' );
	}

	/**
	 * Nothing to read is 0, not a guess.
	 */
	public function test_a_png_without_the_chunk_reports_nothing(): void {
		$engine = $this->engine();

		// to_png( …, 0 ) is the "do not declare a resolution" path.
		$this->assert_same( 0, $engine->read_dpi( "\x89PNG\r\n\x1a\n" ), 'a header with no chunks' );
		$this->assert_same( 0, $engine->read_dpi( 'not a png at all' ), 'not a PNG' );
	}
}
