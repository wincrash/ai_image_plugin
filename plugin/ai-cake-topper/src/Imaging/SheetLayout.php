<?php
/**
 * How many circles fit on a sheet, and exactly where they go.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Imaging;

use AiCake\Support\Mm;

defined( 'ABSPATH' ) || exit;

/**
 * Imposition (PLAN.md §3.5).
 *
 * The per-sheet count is **derived, never typed in**. The shop sets a circle
 * diameter and a usable print area; this reports "that yields 24 per sheet"
 * and the product is priced from it. A hand-entered count that disagrees with
 * the geometry produces sheets with a row missing.
 *
 * Pure functions, no WordPress. One of the three classes §19 marks as most
 * likely to be subtly wrong, so it is unit-tested.
 */
final class SheetLayout {

	/**
	 * Usable print area, not paper size (§3.4).
	 *
	 * Edible printers cannot reach the sheet edge. These are the defaults for
	 * an A4 sheet on a Canon TS-series with edible cartridges; the real
	 * printer is unknown, so it is an admin setting and getting it wrong
	 * ruins whole sheets.
	 */
	public const USABLE_WIDTH_MM  = 200.0;
	public const USABLE_HEIGHT_MM = 287.0;

	/**
	 * Not instantiable.
	 */
	private function __construct() {}

	/**
	 * Work out the grid for a given circle on a given sheet.
	 *
	 * @param float $diameter_mm Finished (trim) circle diameter.
	 * @param float $usable_w_mm Usable print width.
	 * @param float $usable_h_mm Usable print height.
	 * @param float $bleed_mm    Bleed per edge.
	 * @param int   $dpi         Resolution for the pixel figures.
	 * @return array<string, mixed>
	 */
	public static function plan(
		float $diameter_mm,
		float $usable_w_mm = self::USABLE_WIDTH_MM,
		float $usable_h_mm = self::USABLE_HEIGHT_MM,
		float $bleed_mm = Mm::BLEED_MM,
		int $dpi = Mm::PRINT_DPI
	): array {
		if ( $diameter_mm <= 0 || $usable_w_mm <= 0 || $usable_h_mm <= 0 ) {
			return self::empty_plan();
		}

		/*
		 * The pitch is the TRIM diameter, not the bled diameter, and that is
		 * not a rounding shortcut. Adjacent circles are cut at their trim
		 * line, so their bleed regions are allowed to overlap each other —
		 * the overlap is cut away. Dividing by the bled diameter instead
		 * would lose a whole column on most sizes: 200 / 51 is 3, not 4.
		 */
		$cols = (int) floor( $usable_w_mm / $diameter_mm );
		$rows = (int) floor( $usable_h_mm / $diameter_mm );

		if ( $cols < 1 || $rows < 1 ) {
			return self::empty_plan();
		}

		return array(
			'cols'         => $cols,
			'rows'         => $rows,
			'per_sheet'    => $cols * $rows,
			'diameter_mm'  => $diameter_mm,
			'bled_mm'      => Mm::with_bleed( $diameter_mm, $bleed_mm ),
			'diameter_px'  => Mm::to_px( $diameter_mm, $dpi ),
			'bled_px'      => Mm::bled_px( $diameter_mm, $dpi, $bleed_mm ),
			'sheet_w_px'   => Mm::to_px( $usable_w_mm, $dpi ),
			'sheet_h_px'   => Mm::to_px( $usable_h_mm, $dpi ),
			'gutter_x_mm'  => self::gutter( $usable_w_mm, $diameter_mm, $cols ),
			'gutter_y_mm'  => self::gutter( $usable_h_mm, $diameter_mm, $rows ),
			'centres_px'   => self::centres_px( $cols, $rows, $diameter_mm, $usable_w_mm, $usable_h_mm, $dpi ),
			'bleed_clipped' => self::bleed_is_clipped( $usable_w_mm, $diameter_mm, $cols, $bleed_mm )
				|| self::bleed_is_clipped( $usable_h_mm, $diameter_mm, $rows, $bleed_mm ),
		);
	}

	/**
	 * Just the count, for the admin "this yields N per sheet" readout.
	 *
	 * @param float $diameter_mm Finished circle diameter.
	 * @param float $usable_w_mm Usable print width.
	 * @param float $usable_h_mm Usable print height.
	 */
	public static function per_sheet(
		float $diameter_mm,
		float $usable_w_mm = self::USABLE_WIDTH_MM,
		float $usable_h_mm = self::USABLE_HEIGHT_MM
	): int {
		$plan = self::plan( $diameter_mm, $usable_w_mm, $usable_h_mm );

		return (int) $plan['per_sheet'];
	}

	/**
	 * Even spacing: equal margins and equal gutters.
	 *
	 * §3.5 says leftover slack is distributed evenly. Spreading it across
	 * cols+1 gaps rather than cols-1 means the outer circles also gain a
	 * margin, which pulls their bleed back inside the printable area instead
	 * of letting the printer clip it.
	 *
	 * @param float $usable_mm   Usable dimension.
	 * @param float $diameter_mm Trim diameter.
	 * @param int   $count       Circles along this axis.
	 */
	private static function gutter( float $usable_mm, float $diameter_mm, int $count ): float {
		$slack = $usable_mm - ( $count * $diameter_mm );

		return $slack <= 0 ? 0.0 : $slack / ( $count + 1 );
	}

	/**
	 * Centre of every cell, in pixels.
	 *
	 * @param int   $cols        Columns.
	 * @param int   $rows        Rows.
	 * @param float $diameter_mm Trim diameter.
	 * @param float $usable_w_mm Usable width.
	 * @param float $usable_h_mm Usable height.
	 * @param int   $dpi         Resolution.
	 * @return array<int, array{x:int, y:int}>
	 */
	private static function centres_px(
		int $cols,
		int $rows,
		float $diameter_mm,
		float $usable_w_mm,
		float $usable_h_mm,
		int $dpi
	): array {
		$gutter_x = self::gutter( $usable_w_mm, $diameter_mm, $cols );
		$gutter_y = self::gutter( $usable_h_mm, $diameter_mm, $rows );

		$per_mm   = Mm::px_per_mm( $dpi );
		$centres  = array();

		for ( $row = 0; $row < $rows; $row++ ) {
			for ( $col = 0; $col < $cols; $col++ ) {
				$x_mm = $gutter_x + ( $col * ( $diameter_mm + $gutter_x ) ) + ( $diameter_mm / 2 );
				$y_mm = $gutter_y + ( $row * ( $diameter_mm + $gutter_y ) ) + ( $diameter_mm / 2 );

				$centres[] = array(
					'x' => (int) round( $x_mm * $per_mm ),
					'y' => (int) round( $y_mm * $per_mm ),
				);
			}
		}

		return $centres;
	}

	/**
	 * Whether the outermost circles' bleed extends past the printable area.
	 *
	 * Not an error — the bleed is cut away regardless, so a clipped bleed only
	 * matters if the cut is off. Worth surfacing in the admin so a shop
	 * choosing between 4.0 cm (35 per sheet, bleed clipped) and 4.5 cm (24 per
	 * sheet, bleed intact) is making that trade knowingly.
	 *
	 * @param float $usable_mm   Usable dimension.
	 * @param float $diameter_mm Trim diameter.
	 * @param int   $count       Circles along this axis.
	 * @param float $bleed_mm    Bleed per edge.
	 */
	private static function bleed_is_clipped( float $usable_mm, float $diameter_mm, int $count, float $bleed_mm ): bool {
		$gutter = self::gutter( $usable_mm, $diameter_mm, $count );

		return $gutter < $bleed_mm;
	}

	/**
	 * Nothing fits.
	 *
	 * @return array<string, mixed>
	 */
	private static function empty_plan(): array {
		return array(
			'cols'          => 0,
			'rows'          => 0,
			'per_sheet'     => 0,
			'diameter_mm'   => 0.0,
			'bled_mm'       => 0.0,
			'diameter_px'   => 0,
			'bled_px'       => 0,
			'sheet_w_px'    => 0,
			'sheet_h_px'    => 0,
			'gutter_x_mm'   => 0.0,
			'gutter_y_mm'   => 0.0,
			'centres_px'    => array(),
			'bleed_clipped' => false,
		);
	}
}
