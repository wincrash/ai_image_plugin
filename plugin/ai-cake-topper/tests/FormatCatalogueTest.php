<?php
/**
 * The catalogue decides what physical size a customer is sold.
 *
 * @package AiCake
 */

declare( strict_types=1 );

use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\PrintSpec;
use AiCake\Imaging\SheetLayout;

/**
 * Tests for FormatCatalogue (D-037, D-038, D-039).
 *
 * The counts asserted here are the ones in `PLAN.md` §3.5, and they are worth
 * asserting precisely because they have moved twice: the ⌀4.0 cm case went
 * 35 → 30 → 35 as the usable area was corrected. Each move was correct at the
 * time and invisible without a test — a hardcoded arrangement would have kept
 * printing the first answer.
 */
class FormatCatalogueTest extends TestCase {

	/**
	 * The whole sheet is the usable area, with no bleed.
	 */
	public function test_sheet_format_is_the_usable_area(): void {
		$option = FormatCatalogue::find( FormatCatalogue::TYPE_SHEET );

		$this->assert_true( is_array( $option ), 'A4 sheet is offered' );
		$this->assert_same( 210.0, (float) $option['width_mm'], 'sheet width is full A4' );
		$this->assert_same( 282.0, (float) $option['height_mm'], 'sheet height is A4 less the icing shortfall' );
		$this->assert_same( 1, (int) $option['per_sheet'], 'one per sheet' );

		/*
		 * Zero bleed is deliberate. The artwork already fills every printable
		 * millimetre, so bleed would ask the printer for ink outside the area
		 * it can reach.
		 */
		$this->assert_same( 0.0, (float) $option['bleed_mm'], 'the whole sheet has no bleed' );
	}

	/**
	 * The usable area is full A4 less the bare icing strip (D-039).
	 */
	public function test_usable_area_matches_the_decision(): void {
		$this->assert_same( 210.0, SheetLayout::USABLE_WIDTH_MM, 'full A4 width, no printer margin' );
		$this->assert_same( 282.0, SheetLayout::USABLE_HEIGHT_MM, '297 less 15 mm of bare icing' );
		$this->assert_same(
			297.0 - SheetLayout::ICING_SHORTFALL_MM,
			SheetLayout::USABLE_HEIGHT_MM,
			'the height is derived from the shortfall, not typed twice'
		);
	}

	/**
	 * 20 cm down to 10 cm, in 1 cm steps (Ruslan, D-038).
	 */
	public function test_circle_sizes_are_the_offered_list(): void {
		$sizes = FormatCatalogue::circle_diameters_mm();

		$this->assert_same( 11, count( $sizes ), 'eleven circle sizes' );
		$this->assert_same( 200.0, $sizes[0], 'largest is 20 cm' );
		$this->assert_same( 100.0, $sizes[ count( $sizes ) - 1 ], 'smallest is 10 cm' );

		foreach ( $sizes as $index => $size ) {
			$this->assert_same( 200.0 - ( $index * 10.0 ), $size, sprintf( 'step %d is 1 cm down', $index ) );
		}
	}

	/**
	 * ⌀20 cm fits, which is the whole of D-039.
	 *
	 * It fits only because no printer margin is deducted: 200 mm of trim plus
	 * 6 mm of bleed against a 210 mm sheet leaves 4 mm. I twice argued it was
	 * impossible, from margins I had assumed rather than measured.
	 */
	public function test_twenty_centimetre_circle_fits(): void {
		$option = FormatCatalogue::find( FormatCatalogue::TYPE_CIRCLE, 200.0 );

		$this->assert_true( is_array( $option ), '20 cm is offered' );
		$this->assert_true( (bool) $option['fits'], '20 cm fits' );
		$this->assert_same( 1, (int) $option['per_sheet'], 'one 20 cm circle per sheet' );
		$this->assert_true( ! $option['bleed_clipped'], '20 cm keeps its full bleed' );
	}

	/**
	 * "As many as fit", so a small circle yields more than one (D-039).
	 */
	public function test_small_circles_yield_more_than_one(): void {
		$fourteen = FormatCatalogue::find( FormatCatalogue::TYPE_CIRCLE, 140.0 );
		$ten      = FormatCatalogue::find( FormatCatalogue::TYPE_CIRCLE, 100.0 );

		$this->assert_same( 2, (int) $fourteen['per_sheet'], '14 cm yields 2' );

		/*
		 * Four, not the two Ruslan guessed at when he wrote "1 circle, or 2 if
		 * fit" — 2 × 2 fit at 10 cm. This is why the wizard states the count
		 * rather than implying it.
		 */
		$this->assert_same( 4, (int) $ten['per_sheet'], '10 cm yields 4' );
	}

	/**
	 * The §3.5 cupcake table, derived rather than typed.
	 */
	public function test_cupcake_counts_match_the_plan(): void {
		$expected = array(
			40 => 35,
			45 => 24,
			50 => 20,
			60 => 12,
		);

		foreach ( $expected as $diameter => $count ) {
			$option = FormatCatalogue::find( FormatCatalogue::TYPE_CUPCAKE, (float) $diameter );

			$this->assert_true( is_array( $option ), sprintf( '⌀%d mm is offered', $diameter ) );
			$this->assert_same( $count, (int) $option['per_sheet'], sprintf( '⌀%d mm yields %d', $diameter, $count ) );
		}
	}

	/**
	 * Cake pops, derived the same way everything else is (D-072).
	 *
	 * The counts are large — 88 circles on one sheet — and they are asserted
	 * because they are the whole reason the wizard prints a count on every card.
	 * Someone choosing ⌀2,5 cm expecting a handful gets eighty-eight.
	 */
	public function test_popcake_counts_are_derived(): void {
		$expected = array(
			25 => 88,
			30 => 63,
			35 => 48,
		);

		foreach ( $expected as $diameter => $count ) {
			$option = FormatCatalogue::find( FormatCatalogue::TYPE_POPCAKE, (float) $diameter );

			$this->assert_true( is_array( $option ), sprintf( '⌀%d mm is offered', $diameter ) );
			$this->assert_same( $count, (int) $option['per_sheet'], sprintf( '⌀%d mm yields %d', $diameter, $count ) );
		}
	}

	/**
	 * **The boundaries are in the gaps, and every offered size clears them.**
	 *
	 * `type_for_diameter()` is total only while the three round lists do not
	 * touch: cake pops 25–35, cupcakes 40–60, circles 100–200, with the
	 * dividing lines at 37 and 80. A size added into either gap would be
	 * mislabelled silently — one card at a time, on a page that still renders.
	 *
	 * So this asserts the property rather than the constants: every size the
	 * catalogue offers derives back to the type it was built as. Adding ⌀38 mm
	 * to either list turns it red.
	 */
	public function test_every_offered_size_derives_to_its_own_type(): void {
		foreach ( FormatCatalogue::offerable() as $option ) {
			$this->assert_same(
				(string) $option['type'],
				FormatCatalogue::type_for_diameter( (float) $option['diameter_mm'] ),
				sprintf( '%s ⌀%s mm derives to its own type', $option['type'], $option['diameter_mm'] )
			);
		}

		// And the boundaries themselves land where they are meant to, including
		// the one value that is in neither list and must still answer.
		$this->assert_same( FormatCatalogue::TYPE_POPCAKE, FormatCatalogue::type_for_diameter( 37.0 ), 'the pop/cupcake line is 37 mm' );
		$this->assert_same( FormatCatalogue::TYPE_CUPCAKE, FormatCatalogue::type_for_diameter( 38.0 ), 'just above it is a cupcake' );
		$this->assert_same( FormatCatalogue::TYPE_SHEET, FormatCatalogue::type_for_diameter( 0.0 ), 'zero is the whole sheet' );
	}

	/**
	 * Clipped bleed is reported, and does not withdraw a product from sale.
	 *
	 * The 24-up sheet is the shop's highest-volume item and its outer circles
	 * do lose a sliver of bleed. Refusing to offer it would prevent a defect
	 * that needs a bad cut as well, at the cost of the best-selling format.
	 */
	public function test_clipped_bleed_is_advisory_not_disqualifying(): void {
		$option = FormatCatalogue::find( FormatCatalogue::TYPE_CUPCAKE, 45.0 );

		$this->assert_true( (bool) $option['fits'], '24-up still fits' );
		$this->assert_true( (bool) $option['bleed_clipped'], '24-up loses bleed at the edge' );
	}

	/**
	 * Anything not in the catalogue is refused, never clamped.
	 *
	 * Clamping would quietly print a different size than was bought, which is
	 * the one failure mode nobody catches until the cake is decorated.
	 */
	public function test_unknown_formats_are_refused(): void {
		$this->assert_same( null, FormatCatalogue::find( FormatCatalogue::TYPE_CIRCLE, 175.0 ), 'an in-between size is refused' );
		$this->assert_same( null, FormatCatalogue::find( FormatCatalogue::TYPE_CIRCLE, 300.0 ), 'an oversized circle is refused' );
		$this->assert_same( null, FormatCatalogue::find( 'sausainis', 50.0 ), 'an unknown type is refused' );
		$this->assert_same( null, FormatCatalogue::spec( FormatCatalogue::TYPE_CUPCAKE, 47.0 ), 'no spec for an unlisted size' );
	}

	/**
	 * A spec carries the derived count, not a typed one.
	 */
	public function test_spec_takes_its_copies_from_the_geometry(): void {
		$spec = FormatCatalogue::spec( FormatCatalogue::TYPE_CUPCAKE, 45.0 );

		$this->assert_true( $spec instanceof PrintSpec, 'a spec is produced' );
		$this->assert_same( 24, $spec->copies, 'copies is the derived count' );
		$this->assert_same( 24, $spec->computed_copies(), 'and it agrees with the geometry' );
		$this->assert_true( $spec->is_round(), 'cupcakes are round' );
		$this->assert_same( '1:1', $spec->generation_aspect(), 'round generates square' );
	}

	/**
	 * The A4 spec asks the generator for a tall image, not a square one.
	 */
	public function test_sheet_spec_generates_portrait(): void {
		$spec = FormatCatalogue::spec( FormatCatalogue::TYPE_SHEET );

		$this->assert_true( ! $spec->is_round(), 'the whole sheet is a rectangle' );
		$this->assert_same( '2:3', $spec->generation_aspect(), 'A4 generates 2:3 and is cropped (§3.2)' );
	}

	/**
	 * Every offered format actually fits.
	 */
	public function test_nothing_offered_fails_to_fit(): void {
		foreach ( FormatCatalogue::offerable() as $option ) {
			$this->assert_true(
				(int) $option['per_sheet'] >= 1,
				sprintf( '%s yields at least one', $option['label'] )
			);
		}
	}

	/**
	 * A smaller sheet withdraws the sizes that no longer fit.
	 *
	 * This is the property that makes the catalogue safe to hardcode: change
	 * the usable area and the offer changes with it, rather than continuing to
	 * sell something that cannot be printed.
	 */
	public function test_a_smaller_sheet_withdraws_large_sizes(): void {
		$offered = FormatCatalogue::offerable( 120.0, 160.0 );
		$labels  = array();

		foreach ( $offered as $option ) {
			if ( FormatCatalogue::TYPE_CIRCLE === $option['type'] ) {
				$labels[] = (float) $option['diameter_mm'];
			}
		}

		$this->assert_true( ! in_array( 200.0, $labels, true ), '20 cm is withdrawn on a small sheet' );
		$this->assert_true( in_array( 100.0, $labels, true ), '10 cm still fits' );
		$this->assert_same( null, FormatCatalogue::find( FormatCatalogue::TYPE_CIRCLE, 200.0, 120.0, 160.0 ), 'and cannot be chosen' );
	}
}
