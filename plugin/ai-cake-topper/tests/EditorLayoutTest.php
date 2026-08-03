<?php
/**
 * Tests for the geometry the browser text editor is handed.
 *
 * @package AiCake
 */

declare( strict_types=1 );

use AiCake\Domain\FormatCatalogue;
use AiCake\Imaging\SheetLayout;
use AiCake\Support\Mm;

/**
 * D-033: the client must never compute piece positions itself.
 *
 * Which makes this the contract between the editor and the print file. Its
 * failure mode is the nasty one — a layout that disagrees looks *correct in
 * the editor* and comes out wrong on paper, so nobody finds it until a
 * customer has cut up a sheet with names across the gutters.
 */
class EditorLayoutTest extends TestCase {

	/**
	 * The canvas is the print file, for a multi-up sheet.
	 */
	public function test_a_cupcake_sheet_canvas_is_the_imposed_sheet(): void {
		$spec = FormatCatalogue::spec( FormatCatalogue::TYPE_CUPCAKE, 45.0 );
		$plan = SheetLayout::plan( 45.0 );

		list( $w, $h ) = $spec->canvas_px();

		$this->assert_same( (int) $plan['sheet_w_px'], $w, 'canvas width is the sheet' );
		$this->assert_same( (int) $plan['sheet_h_px'], $h, 'canvas height is the sheet' );
	}

	/**
	 * Every piece is offered, at the positions imposition will actually use.
	 *
	 * Compared against SheetLayout directly rather than against typed numbers:
	 * a frozen coordinate here would go red for the right reason after a
	 * geometry change and then get "fixed" by pasting in whatever the code
	 * produced, which asserts nothing.
	 */
	public function test_cupcake_pieces_match_the_imposition(): void {
		$spec   = FormatCatalogue::spec( FormatCatalogue::TYPE_CUPCAKE, 45.0 );
		$layout = $spec->editor_layout();
		$plan   = SheetLayout::plan( 45.0 );

		$this->assert_same( 24, count( $layout['pieces'] ), 'one editable piece per cupcake' );
		$this->assert_same( (int) $plan['per_sheet'], count( $layout['pieces'] ), 'and that is the imposed count' );

		$centres = $plan['centres_px'];

		foreach ( $layout['pieces'] as $i => $piece ) {
			$this->assert_same( (int) $centres[ $i ]['x'], $piece['cx'], sprintf( 'piece %d x', $i ) );
			$this->assert_same( (int) $centres[ $i ]['y'], $piece['cy'], sprintf( 'piece %d y', $i ) );
		}
	}

	/**
	 * A single topper is one piece in the middle of its own canvas.
	 *
	 * The centre is canvas/2, not trim/2 — the canvas includes bleed on every
	 * edge, and halving the trim instead puts every name 3 mm off centre.
	 */
	public function test_a_single_circle_is_centred_on_its_canvas(): void {
		$spec   = FormatCatalogue::spec( FormatCatalogue::TYPE_CIRCLE, 150.0 );
		$layout = $spec->editor_layout();

		list( $w, $h ) = $spec->canvas_px();

		$this->assert_same( 1, count( $layout['pieces'] ), 'one piece' );
		$this->assert_same( (int) round( $w / 2 ), $layout['pieces'][0]['cx'], 'centred horizontally' );
		$this->assert_same( (int) round( $h / 2 ), $layout['pieces'][0]['cy'], 'centred vertically' );
		$this->assert_true( $layout['round'], 'and it is round' );
	}

	/**
	 * The three boxes, and which one the editor actually obeys.
	 *
	 * D-042 moved the editor's limit out to the trim line. `limit_*` is a
	 * separate field rather than an alias for `w` precisely so that moving it
	 * again moves the editor and `tools/layer-check.php` together — if this
	 * test ever reads `w` directly it stops protecting that.
	 */
	public function test_the_editor_limit_is_the_trim_line(): void {
		$spec   = FormatCatalogue::spec( FormatCatalogue::TYPE_CIRCLE, 150.0 );
		$layout = $spec->editor_layout();
		$piece  = $layout['pieces'][0];

		$this->assert_same( Mm::to_px( 150.0 ), $piece['w'], 'the piece box is the trim diameter' );
		$this->assert_same( $piece['w'], $piece['limit_w'], 'and the editor limit is the trim line (D-042)' );
		$this->assert_same( $piece['h'], $piece['limit_h'], 'on both axes' );
	}

	/**
	 * The safe box still exists, and is still smaller. Advisory now, but it is
	 * what `layer-check` reports clearance against.
	 */
	public function test_the_safe_box_is_still_reported(): void {
		$spec   = FormatCatalogue::spec( FormatCatalogue::TYPE_CIRCLE, 150.0 );
		$piece  = $spec->editor_layout()['pieces'][0];

		$expected = Mm::to_px( 150.0 ) - ( 2 * Mm::to_px( Mm::SAFE_MM ) );

		$this->assert_same( $expected, $piece['safe_w'], 'the safe box is trim less the safe margin' );
		$this->assert_true( $piece['safe_w'] < $piece['limit_w'], 'and it is strictly inside the limit' );
	}

	/**
	 * The whole-sheet format is one rectangular piece.
	 */
	public function test_the_a4_sheet_is_a_single_rect_piece(): void {
		$spec   = FormatCatalogue::spec( FormatCatalogue::TYPE_SHEET, 0.0 );
		$layout = $spec->editor_layout();

		$this->assert_same( 1, count( $layout['pieces'] ), 'one piece' );
		$this->assert_true( ! $layout['round'], 'and it is not round' );
		$this->assert_same( Mm::PRINT_DPI, $layout['dpi'], 'at print resolution' );
	}
}
