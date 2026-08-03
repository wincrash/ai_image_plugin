<?php
/**
 * What the customer may choose, and what each choice yields.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

use AiCake\Imaging\SheetLayout;
use AiCake\Support\Mm;

defined( 'ABSPATH' ) || exit;

/**
 * The three format types (PLAN.md §4.1, D-037, D-038, D-039).
 *
 * Format is a property of the **design**, not of the product. There is one AI
 * product; what shape and size it comes out as is chosen in the wizard and
 * recorded on the design row (D-035). Price does not vary with format, because
 * every format is one A4 sheet and costs the same to make.
 *
 * **The list of offered sizes is hardcoded; the arrangement is not** (D-038).
 * Ruslan asked for hardcoded layouts too. The list is the safe half to freeze —
 * a combobox of known sizes, no free numeric input. The arrangement is the
 * unsafe half, because a frozen layout encodes the usable area implicitly, and
 * when a margin changes the file still looks right and only the printed sheet
 * disagrees. That is not hypothetical: the ⌀4.0 cm case moved from 35 per sheet
 * to 30 and back to 35 inside one afternoon as the usable area was corrected
 * twice. `SheetLayout` re-derives; a table would have lied.
 *
 * "As many as fit" is the rule for both circles and cupcakes (D-039), so a
 * ⌀10 cm circle yields 4 rather than 1. The wizard must state the count.
 */
final class FormatCatalogue {

	public const TYPE_SHEET   = 'sheet';
	public const TYPE_CIRCLE  = 'circle';
	public const TYPE_CUPCAKE = 'cupcake';

	/**
	 * Single circles: 20 cm down to 10 cm in 1 cm steps (Ruslan, D-038).
	 *
	 * ⌀20 cm is the declared maximum and it fits with 4 mm to spare — but only
	 * because there are no printer margins (D-039). Anything larger is not
	 * offered, and `fits()` is the guard that says so rather than a comment.
	 *
	 * @return float[]
	 */
	public static function circle_diameters_mm(): array {
		return array( 200.0, 190.0, 180.0, 170.0, 160.0, 150.0, 140.0, 130.0, 120.0, 110.0, 100.0 );
	}

	/**
	 * Cupcake sizes, the ones §3.5 tabulates.
	 *
	 * @return float[]
	 */
	public static function cupcake_diameters_mm(): array {
		return array( 40.0, 45.0, 50.0, 60.0 );
	}

	/**
	 * Every choice the wizard offers, with its derived count.
	 *
	 * @param float $usable_w_mm Usable width.
	 * @param float $usable_h_mm Usable height.
	 * @param float $bleed_mm    Bleed per edge.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function options(
		float $usable_w_mm = SheetLayout::USABLE_WIDTH_MM,
		float $usable_h_mm = SheetLayout::USABLE_HEIGHT_MM,
		float $bleed_mm = Mm::BLEED_MM
	): array {
		$options = array( self::sheet_option( $usable_w_mm, $usable_h_mm ) );

		foreach ( self::circle_diameters_mm() as $diameter ) {
			$options[] = self::round_option( self::TYPE_CIRCLE, $diameter, $usable_w_mm, $usable_h_mm, $bleed_mm );
		}

		foreach ( self::cupcake_diameters_mm() as $diameter ) {
			$options[] = self::round_option( self::TYPE_CUPCAKE, $diameter, $usable_w_mm, $usable_h_mm, $bleed_mm );
		}

		return $options;
	}

	/**
	 * Only the choices that actually fit the page.
	 *
	 * Ruslan's rule is "the design must fit the page and the physical size
	 * must be exact" (D-037). This is where that becomes enforceable at the
	 * point of choosing rather than at the point of printing — a size that
	 * does not fit is never offered.
	 *
	 * @param float $usable_w_mm Usable width.
	 * @param float $usable_h_mm Usable height.
	 * @param float $bleed_mm    Bleed per edge.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function offerable(
		float $usable_w_mm = SheetLayout::USABLE_WIDTH_MM,
		float $usable_h_mm = SheetLayout::USABLE_HEIGHT_MM,
		float $bleed_mm = Mm::BLEED_MM
	): array {
		return array_values(
			array_filter(
				self::options( $usable_w_mm, $usable_h_mm, $bleed_mm ),
				static fn( array $option ): bool => (bool) $option['fits']
			)
		);
	}

	/**
	 * Look one up, or null if it is not something we offer.
	 *
	 * Everything arriving from a browser goes through here. A format the
	 * catalogue does not contain is refused rather than clamped, because
	 * clamping would quietly print a different size than the customer bought.
	 *
	 * @param string $type        One of the TYPE_ constants.
	 * @param float  $diameter_mm Trim diameter; ignored for a whole sheet.
	 * @param float  $usable_w_mm Usable width.
	 * @param float  $usable_h_mm Usable height.
	 * @param float  $bleed_mm    Bleed per edge.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find(
		string $type,
		float $diameter_mm = 0.0,
		float $usable_w_mm = SheetLayout::USABLE_WIDTH_MM,
		float $usable_h_mm = SheetLayout::USABLE_HEIGHT_MM,
		float $bleed_mm = Mm::BLEED_MM
	): ?array {
		foreach ( self::offerable( $usable_w_mm, $usable_h_mm, $bleed_mm ) as $option ) {
			if ( $option['type'] !== $type ) {
				continue;
			}

			if ( self::TYPE_SHEET === $type ) {
				return $option;
			}

			// Millimetre tolerance: the value round-trips through a form and
			// a DECIMAL column, so exact float equality is not available.
			if ( abs( (float) $option['diameter_mm'] - $diameter_mm ) < 0.05 ) {
				return $option;
			}
		}

		return null;
	}

	/**
	 * The print spec for a chosen format.
	 *
	 * @param string $type        One of the TYPE_ constants.
	 * @param float  $diameter_mm Trim diameter; ignored for a whole sheet.
	 * @param float  $usable_w_mm Usable width.
	 * @param float  $usable_h_mm Usable height.
	 * @param float  $bleed_mm    Bleed per edge.
	 */
	public static function spec(
		string $type,
		float $diameter_mm = 0.0,
		float $usable_w_mm = SheetLayout::USABLE_WIDTH_MM,
		float $usable_h_mm = SheetLayout::USABLE_HEIGHT_MM,
		float $bleed_mm = Mm::BLEED_MM
	): ?PrintSpec {
		$option = self::find( $type, $diameter_mm, $usable_w_mm, $usable_h_mm, $bleed_mm );

		if ( null === $option ) {
			return null;
		}

		$spec             = new PrintSpec();
		$spec->enabled    = true;
		$spec->shape      = (string) $option['shape'];
		$spec->width_mm   = (float) $option['width_mm'];
		$spec->height_mm  = (float) $option['height_mm'];
		$spec->bleed_mm   = (float) $option['bleed_mm'];
		$spec->copies     = (int) $option['per_sheet'];
		$spec->sheet_w_mm = $usable_w_mm;
		$spec->sheet_h_mm = $usable_h_mm;

		return $spec;
	}

	/**
	 * The whole usable area as one rectangle.
	 *
	 * Bleed is zero, and that is not an oversight. The artwork already fills
	 * every printable millimetre, so there is nowhere for bleed to extend to —
	 * asking for 3 mm outside the usable area asks the printer for ink it
	 * cannot lay down.
	 *
	 * @param float $usable_w_mm Usable width.
	 * @param float $usable_h_mm Usable height.
	 *
	 * @return array<string, mixed>
	 */
	private static function sheet_option( float $usable_w_mm, float $usable_h_mm ): array {
		return array(
			'type'          => self::TYPE_SHEET,
			'shape'         => PrintSpec::SHAPE_RECT,
			'diameter_mm'   => 0.0,
			'width_mm'      => $usable_w_mm,
			'height_mm'     => $usable_h_mm,
			'bleed_mm'      => 0.0,
			'cols'          => 1,
			'rows'          => 1,
			'per_sheet'     => 1,
			'fits'          => $usable_w_mm > 0 && $usable_h_mm > 0,
			'bleed_clipped' => false,
			'label'         => __( 'A4 — visas lapas', 'ai-cake-topper' ),
		);
	}

	/**
	 * One circle size, with its derived grid.
	 *
	 * @param string $type        Circle or cupcake.
	 * @param float  $diameter_mm Trim diameter.
	 * @param float  $usable_w_mm Usable width.
	 * @param float  $usable_h_mm Usable height.
	 * @param float  $bleed_mm    Bleed per edge.
	 *
	 * @return array<string, mixed>
	 */
	private static function round_option(
		string $type,
		float $diameter_mm,
		float $usable_w_mm,
		float $usable_h_mm,
		float $bleed_mm
	): array {
		$plan = SheetLayout::plan( $diameter_mm, $usable_w_mm, $usable_h_mm, $bleed_mm );

		$per_sheet = (int) $plan['per_sheet'];

		/*
		 * Fitting is about the **trim** line, not the bleed. `cols`/`rows` are
		 * floor divisions on the trim diameter, so a non-zero count already
		 * means every finished piece lies inside the usable area — which is
		 * Ruslan's rule exactly (D-037): it must fit, and it must be the size
		 * it says.
		 *
		 * Clipped bleed is reported separately and is **not** disqualifying.
		 * The 24-up cupcake sheet the shop sells today has 1.7 mm of gutter
		 * against 3 mm of bleed, so the outermost circles lose a sliver of
		 * bleed at the sheet edge. Treating that as "does not fit" would
		 * withdraw the highest-volume product from sale to prevent a defect
		 * that only appears if the cut is also off, and in the one direction
		 * where there is nothing to cut away anyway.
		 */
		return array(
			'type'          => $type,
			'shape'         => PrintSpec::SHAPE_ROUND,
			'diameter_mm'   => $diameter_mm,
			'width_mm'      => $diameter_mm,
			'height_mm'     => 0.0,
			'bleed_mm'      => $bleed_mm,
			'cols'          => (int) $plan['cols'],
			'rows'          => (int) $plan['rows'],
			'per_sheet'     => $per_sheet,
			'fits'          => $per_sheet >= 1,
			'bleed_clipped' => (bool) $plan['bleed_clipped'],
			'label'         => self::label( $type, $diameter_mm, $per_sheet ),
		);
	}

	/**
	 * What the customer reads in the combobox.
	 *
	 * The count is always shown, because "as many as fit" is invisible
	 * otherwise — someone choosing ⌀10 cm expecting one topper and receiving
	 * four has been surprised, even pleasantly.
	 *
	 * @param string $type        Circle or cupcake.
	 * @param float  $diameter_mm Trim diameter.
	 * @param int    $per_sheet   Derived count.
	 */
	private static function label( string $type, float $diameter_mm, int $per_sheet ): string {
		$cm = rtrim( rtrim( number_format( $diameter_mm / 10, 1, ',', '' ), '0' ), ',' );

		return self::TYPE_CUPCAKE === $type
			? sprintf(
				/* translators: 1: diameter in cm, 2: how many fit on one sheet */
				__( 'Keksiukams ⌀%1$s cm — %2$d vnt.', 'ai-cake-topper' ),
				$cm,
				$per_sheet
			)
			: sprintf(
				/* translators: 1: diameter in cm, 2: how many fit on one sheet */
				__( 'Apvalus ⌀%1$s cm — %2$d vnt.', 'ai-cake-topper' ),
				$cm,
				$per_sheet
			);
	}
}
