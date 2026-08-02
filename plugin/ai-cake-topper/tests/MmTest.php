<?php
/**
 * Tests for the print maths.
 *
 * @package AiCake
 */

declare( strict_types=1 );

use AiCake\Support\Mm;

/**
 * PLAN.md §3 — the numbers everything else derives from.
 */
class MmTest extends TestCase {

	/**
	 * The conversion itself.
	 */
	public function test_millimetres_convert_to_pixels_at_300_dpi(): void {
		// 300 DPI is 11.811 px/mm; one inch is 300 px by definition.
		$this->assert_same( 300, Mm::to_px( 25.4 ), '25.4 mm is one inch' );
		// 1181.10 px, so 1182 — the same round-up rule as everywhere else.
		$this->assert_same( 1182, Mm::to_px( 100.0 ), '100 mm' );
		$this->assert_close( 11.811, Mm::px_per_mm(), 0.001, 'px per mm' );
	}

	/**
	 * Rounding direction is a physical decision, not a numerical one.
	 */
	public function test_pixels_always_round_up(): void {
		// 51 mm is 602.36 px. Rounding down leaves the printed circle a
		// fraction short of the cutter, which shows as a white sliver on the
		// finished topper. Rounding up costs 0.08 mm nobody can see.
		$this->assert_same( 603, Mm::to_px( 51.0 ), '51 mm rounds up, not to 602' );
		$this->assert_same( 2434, Mm::to_px( 206.0 ), '206 mm rounds up' );
		$this->assert_same( 2552, Mm::to_px( 216.0 ), '216 mm rounds up' );
	}

	/**
	 * Bleed goes on both edges. Getting this wrong halves it.
	 */
	public function test_bleed_is_added_to_every_edge(): void {
		$this->assert_close( 206.0, Mm::with_bleed( 200.0 ), 0.001, '200 mm circle + 3 mm bleed is 206, not 203' );
		$this->assert_close( 156.0, Mm::with_bleed( 150.0 ), 0.001, '150 mm circle' );
		$this->assert_close( 51.0, Mm::with_bleed( 45.0 ), 0.001, '45 mm cupcake circle' );
	}

	/**
	 * The §3 SKU table, computed the way §3's own formula says.
	 *
	 * Two cells in that table are one pixel low — 2551 for A4 width and 2433
	 * for the 20 cm round — because they were rounded rather than ceiled. The
	 * formula is stated as ceil() and ceil() is the safe direction, so the
	 * code follows the formula and these are the corrected figures. The
	 * difference is 0.085 mm.
	 */
	public function test_the_sku_table(): void {
		$this->assert_same( 2552, Mm::bled_px( 210.0 ), 'A4 width with bleed' );
		$this->assert_same( 3579, Mm::bled_px( 297.0 ), 'A4 height with bleed' );
		$this->assert_same( 2434, Mm::bled_px( 200.0 ), '20 cm round with bleed' );
		$this->assert_same( 1843, Mm::bled_px( 150.0 ), '15 cm round with bleed' );
		$this->assert_same( 603, Mm::bled_px( 45.0 ), '4.5 cm cupcake with bleed' );
		$this->assert_same( 780, Mm::bled_px( 60.0 ), '6 cm cupcake with bleed' );
	}

	/**
	 * A native generation is 86.7 mm at print resolution (§3).
	 */
	public function test_a_native_generation_is_87_mm(): void {
		$this->assert_close( 86.7, Mm::to_mm( 1024 ), 0.05, '1024 px at 300 DPI' );
	}

	/**
	 * §3.1 — the rule that removes a paid call from most orders.
	 */
	public function test_upscale_is_only_needed_when_short(): void {
		// A 4.5 cm cupcake needs 603 px and the generator gives 1024.
		$this->assert_true( ! Mm::needs_upscale( 1024, 603 ), 'cupcake needs no upscale' );
		$this->assert_same( 1, Mm::upscale_factor( 1024, 603 ), 'and no upscale factor' );

		// 15 cm round needs 1843: 2x gets there.
		$this->assert_true( Mm::needs_upscale( 1024, 1843 ), '15 cm round is short' );
		$this->assert_same( 2, Mm::upscale_factor( 1024, 1843 ), '15 cm round takes 2x' );

		// 20 cm round needs 2434: 2x is not enough, 4x is.
		$this->assert_same( 4, Mm::upscale_factor( 1024, 2434 ), '20 cm round takes 4x' );

		// A4 height needs 3579: still within 4x of 1024.
		$this->assert_same( 4, Mm::upscale_factor( 1024, 3579 ), 'A4 takes 4x' );
	}

	/**
	 * Text must clear the bleed as well as the safe margin, because the bleed
	 * region is cut off entirely (§3.3).
	 */
	public function test_safe_inset_includes_the_bleed(): void {
		// 3 mm bleed + 5 mm safe = 8 mm = 95 px.
		$this->assert_same( 95, Mm::safe_inset_px(), 'safe inset is measured from the bled edge' );
	}

	/**
	 * Nonsense in, zero out — never a negative canvas or a division by zero.
	 */
	public function test_degenerate_input_is_survivable(): void {
		$this->assert_same( 0, Mm::to_px( 0.0 ), 'zero mm' );
		$this->assert_same( 0, Mm::to_px( -5.0 ), 'negative mm' );
		$this->assert_same( 0, Mm::to_px( 100.0, 0 ), 'zero dpi' );
		$this->assert_same( 0.0, Mm::to_mm( -1 ), 'negative px' );
		$this->assert_same( 1, Mm::upscale_factor( 0, 1000 ), 'no source' );
	}
}
