<?php
/**
 * What the customer may choose, and what each choice yields.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

use AiCake\Imaging\SheetLayout;

defined( 'ABSPATH' ) || exit;

/**
 * The four format types (PLAN.md §4.1, D-037, D-038, D-039, D-072).
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
 * "As many as fit" is the rule for every round type (D-039), so a ⌀10 cm circle
 * yields 4 rather than 1 and a ⌀2,5 cm cake pop yields 88. The wizard must state
 * the count.
 */
final class FormatCatalogue {

	public const TYPE_SHEET   = 'sheet';
	public const TYPE_CIRCLE  = 'circle';
	public const TYPE_CUPCAKE = 'cupcake';
	public const TYPE_POPCAKE = 'popcake';

	/**
	 * The largest diameter still called a cupcake.
	 *
	 * The dividing line `type_for_diameter()` uses. It sits in the empty gap
	 * between the two offered lists — cupcakes stop at 60 mm, circles start at
	 * 100 mm — so nothing legitimate is near it. `format-check` asserts both
	 * lists stay clear of it, because a size added into that gap would make the
	 * derivation ambiguous and it would fail silently, one label at a time.
	 */
	public const CUPCAKE_MAX_MM = 80.0;

	/**
	 * The largest diameter still called a cake pop (D-072).
	 *
	 * Same rule as `CUPCAKE_MAX_MM`, one list further down: cake pops stop at
	 * 35 mm and cupcakes start at 40 mm, so 37 mm is in the gap and nothing
	 * legitimate is near it. **This is now the second boundary, and adding a
	 * third list means finding a third gap** — the derivation is only total
	 * while every list is separated from its neighbour, which `wizard-check`
	 * asserts for every offered format rather than for the boundaries alone.
	 */
	public const POPCAKE_MAX_MM = 37.0;

	/**
	 * How much bleed this shop sells — **none** (D-074).
	 *
	 * Ruslan's instruction, immediately after D-073 put the picture inside the
	 * cut line: *"set bleed to 0, image should stop at the line."* So it does.
	 * Every format the wizard offers is trim only, the printed circle is the
	 * whole picture, and there is nothing outside the black line at all.
	 *
	 * **The mechanism is untouched and this is the only number.** `Mm::BLEED_MM`
	 * still says what bleed is when a format has it, `GdEngine::bleed_out()` and
	 * D-070's cropper mapping both still work, and `SheetLayout` still reports
	 * clipping. Putting 3.0 here brings all of it back on the next render.
	 *
	 * What it costs is the margin for a crooked cut: with no ink past the trim
	 * line, a cut a millimetre wide leaves a white crescent instead of more
	 * picture. That is the trade Ruslan made, and he owns the printer and the
	 * scissors (D-040).
	 */
	public const BLEED_MM = 0.0;

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
	 * Cake pops — 2,5 / 3 / 3,5 cm (Ruslan, D-072).
	 *
	 * The smallest thing the shop prints, and the first list added since the
	 * type stopped being asked for (D-055). Nothing about them is special: the
	 * same `round_option()` builds them and the counts fall out of
	 * `SheetLayout` — 88, 63 and 48 to a sheet, which is a lot of circles and
	 * exactly why the wizard states the count.
	 *
	 * @return float[]
	 */
	public static function popcake_diameters_mm(): array {
		return array( 25.0, 30.0, 35.0 );
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
		float $bleed_mm = self::BLEED_MM
	): array {
		$options = array( self::sheet_option( $usable_w_mm, $usable_h_mm ) );

		foreach ( self::circle_diameters_mm() as $diameter ) {
			$options[] = self::round_option( self::TYPE_CIRCLE, $diameter, $usable_w_mm, $usable_h_mm, $bleed_mm );
		}

		foreach ( self::cupcake_diameters_mm() as $diameter ) {
			$options[] = self::round_option( self::TYPE_CUPCAKE, $diameter, $usable_w_mm, $usable_h_mm, $bleed_mm );
		}

		foreach ( self::popcake_diameters_mm() as $diameter ) {
			$options[] = self::round_option( self::TYPE_POPCAKE, $diameter, $usable_w_mm, $usable_h_mm, $bleed_mm );
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
		float $bleed_mm = self::BLEED_MM
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
		float $bleed_mm = self::BLEED_MM
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
	 * Which type a diameter belongs to.
	 *
	 * **The customer never chooses this** (D-055). Ruslan's observation was that
	 * A4, circles and cupcakes "really almost the same", and the code already
	 * agreed without saying so: `round_option()` builds circles and cupcakes
	 * through identical arithmetic and they differ in the label alone.
	 *
	 * They are still two constants because a design row written before D-055
	 * carries one of them, and rewriting stored history to tidy a label would
	 * be a migration with nothing to gain. So the type stays — derived here
	 * rather than asked for.
	 *
	 * That derivation is total because the two lists **do not overlap**: 40–60
	 * mm against 100–200 mm. If a size is ever offered in the gap, this stops
	 * being answerable and the caller has to be given the type again — which is
	 * why the boundary is asserted rather than assumed.
	 *
	 * @param float $diameter_mm Trim diameter; zero means the whole sheet.
	 */
	public static function type_for_diameter( float $diameter_mm ): string {
		if ( $diameter_mm <= 0.0 ) {
			return self::TYPE_SHEET;
		}

		if ( $diameter_mm <= self::POPCAKE_MAX_MM ) {
			return self::TYPE_POPCAKE;
		}

		return $diameter_mm <= self::CUPCAKE_MAX_MM ? self::TYPE_CUPCAKE : self::TYPE_CIRCLE;
	}

	/**
	 * The key a format is known by in the browser.
	 *
	 * The wizard ships every layout precomputed (D-033) and the editor looks its
	 * own up by this string, so both ends have to spell it the same way. Built
	 * here rather than concatenated at each end, because the two sources are not
	 * formatted alike: the catalogue holds `45.0` while a design's `format_mm`
	 * comes back from a DECIMAL column as `45.00`. Casting to float first is
	 * what makes those one key instead of two.
	 *
	 * @param string $type        One of the TYPE_ constants.
	 * @param float  $diameter_mm Trim diameter; zero for a whole sheet.
	 */
	public static function layout_key( string $type, float $diameter_mm = 0.0 ): string {
		return $type . '|' . $diameter_mm;
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
		float $bleed_mm = self::BLEED_MM
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

		if ( self::TYPE_POPCAKE === $type ) {
			return sprintf(
				/* translators: 1: diameter in cm, 2: how many fit on one sheet */
				__( 'Cake pop ⌀%1$s cm — %2$d vnt.', 'ai-cake-topper' ),
				$cm,
				$per_sheet
			);
		}

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
