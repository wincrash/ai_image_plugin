<?php
/**
 * Tests for imposition.
 *
 * @package AiCake
 */

declare( strict_types=1 );

use AiCake\Imaging\SheetLayout;
use AiCake\Support\Mm;

/**
 * PLAN.md §3.5 — the per-sheet count is derived, and the derivation had better
 * agree with what the shop is selling.
 */
class SheetLayoutTest extends TestCase {

	/**
	 * The §3.5 table, exactly.
	 *
	 * These counts are load-bearing: they are what the SKUs are named after
	 * and priced on. If this test ever fails, either the geometry changed or
	 * a product listing is now a lie.
	 */
	public function test_the_published_per_sheet_counts(): void {
		$this->assert_same( 35, SheetLayout::per_sheet( 40.0 ), '4.0 cm circles' );
		$this->assert_same( 24, SheetLayout::per_sheet( 45.0 ), '4.5 cm circles — the headline SKU' );
		$this->assert_same( 20, SheetLayout::per_sheet( 50.0 ), '5.0 cm circles' );
		$this->assert_same( 12, SheetLayout::per_sheet( 60.0 ), '6.0 cm circles' );
	}

	/**
	 * The grid shape, not just the total.
	 */
	public function test_the_grid_is_the_expected_shape(): void {
		$plan = SheetLayout::plan( 45.0 );

		$this->assert_same( 4, $plan['cols'], '4.5 cm columns' );
		$this->assert_same( 6, $plan['rows'], '4.5 cm rows' );
		$this->assert_same( 24, $plan['per_sheet'], '4.5 cm total' );

		$plan = SheetLayout::plan( 40.0 );
		$this->assert_same( 5, $plan['cols'], '4.0 cm columns' );
		$this->assert_same( 7, $plan['rows'], '4.0 cm rows' );
	}

	/**
	 * Pitch is the trim diameter, not the bled diameter.
	 *
	 * Adjacent circles are cut at their trim line, so their bleed regions may
	 * overlap — the overlap is cut away. Spacing on the bled diameter instead
	 * would give 200/51 = 3 columns and quietly lose a quarter of every sheet.
	 */
	public function test_bleed_does_not_reduce_the_count(): void {
		$this->assert_same( 4, SheetLayout::plan( 45.0 )['cols'], 'spacing uses the trim diameter' );
		$this->assert_true( SheetLayout::plan( 45.0 )['bled_px'] > SheetLayout::plan( 45.0 )['diameter_px'], 'each circle is still rendered with bleed' );
	}

	/**
	 * Every circle must land inside the printable area.
	 */
	public function test_every_circle_fits_within_the_usable_area(): void {
		foreach ( array( 40.0, 45.0, 50.0, 60.0 ) as $diameter ) {
			$plan   = SheetLayout::plan( $diameter );
			$radius = Mm::to_px( $diameter, Mm::PRINT_DPI ) / 2;

			$worst_left = PHP_INT_MAX;
			$worst_right = 0;

			foreach ( $plan['centres_px'] as $centre ) {
				$worst_left  = min( $worst_left, $centre['x'] - $radius );
				$worst_right = max( $worst_right, $centre['x'] + $radius );
			}

			$this->assert_true( $worst_left >= -1, sprintf( '%.0f mm: no circle runs off the left edge', $diameter ) );
			$this->assert_true(
				$worst_right <= $plan['sheet_w_px'] + 1,
				sprintf( '%.0f mm: no circle runs off the right edge', $diameter )
			);
		}
	}

	/**
	 * Cell count matches the grid, and centres are ordered row by row.
	 */
	public function test_centres_match_the_grid(): void {
		$plan = SheetLayout::plan( 45.0 );

		$this->assert_same( 24, count( $plan['centres_px'] ), 'one centre per circle' );

		// First row shares a y; first column shares an x.
		$this->assert_same( $plan['centres_px'][0]['y'], $plan['centres_px'][3]['y'], 'row 1 is level' );
		$this->assert_same( $plan['centres_px'][0]['x'], $plan['centres_px'][4]['x'], 'column 1 is aligned' );
		$this->assert_true( $plan['centres_px'][4]['y'] > $plan['centres_px'][0]['y'], 'row 2 is below row 1' );
	}

	/**
	 * Slack becomes margins, and the admin is told when it does not.
	 *
	 * The headline 4.5 cm SKU is a good illustration of why this flag exists.
	 * Across the width it has 4 mm of gutter, comfortably more than the 3 mm
	 * bleed. Down the height it has only 2.43 mm — (287 − 6 × 45) ÷ 7 — so the
	 * top and bottom rows *do* get their bleed clipped, which is not obvious
	 * from looking at the sheet. Both axes are checked, and the flag reports
	 * the worse of the two.
	 */
	public function test_slack_becomes_gutters_and_clipping_is_reported(): void {
		$plan = SheetLayout::plan( 45.0 );

		$this->assert_close( 4.0, $plan['gutter_x_mm'], 0.01, '4.5 cm: 20 mm of horizontal slack across 5 gaps' );
		$this->assert_close( 2.43, $plan['gutter_y_mm'], 0.01, '4.5 cm: only 17 mm of vertical slack across 7 gaps' );
		$this->assert_true( $plan['bleed_clipped'], '4.5 cm: the tighter axis wins, so clipping is reported' );

		// 4.0 cm divides the width exactly, leaving no margin at all.
		$tight = SheetLayout::plan( 40.0 );
		$this->assert_close( 0.0, $tight['gutter_x_mm'], 0.001, '4.0 cm divides exactly, no slack' );
		$this->assert_true( $tight['bleed_clipped'], '4.0 cm bleed is clipped, and says so' );

		// A circle with room on both axes reports no clipping.
		$roomy = SheetLayout::plan( 60.0 );
		$this->assert_true( $roomy['gutter_x_mm'] > 3.0 && $roomy['gutter_y_mm'] > 3.0, '6 cm has room on both axes' );
		$this->assert_true( ! $roomy['bleed_clipped'], '6 cm bleed is intact' );
	}

	/**
	 * A circle bigger than the sheet yields nothing rather than a broken grid.
	 */
	public function test_an_impossible_circle_yields_nothing(): void {
		$plan = SheetLayout::plan( 400.0 );

		$this->assert_same( 0, $plan['per_sheet'], 'a 40 cm circle does not fit on A4' );
		$this->assert_same( array(), $plan['centres_px'], 'and produces no cells' );
		$this->assert_same( 0, SheetLayout::per_sheet( 0.0 ), 'zero diameter' );
		$this->assert_same( 0, SheetLayout::per_sheet( -45.0 ), 'negative diameter' );
	}

	/**
	 * The usable area is a setting because it is printer-specific (§3.4).
	 */
	public function test_usable_area_is_configurable(): void {
		// A printer with a wider printable region fits another column.
		$this->assert_same( 5, SheetLayout::plan( 45.0, 230.0, 287.0 )['cols'], 'wider printer, more columns' );

		// 180 mm still divides into four 45 mm circles exactly, with no margin.
		$this->assert_same( 4, SheetLayout::plan( 45.0, 180.0, 287.0 )['cols'], '180 mm fits exactly four' );

		// Below that, a column is genuinely lost.
		$this->assert_same( 3, SheetLayout::plan( 45.0, 170.0, 287.0 )['cols'], 'narrower printer, fewer columns' );
	}
}
