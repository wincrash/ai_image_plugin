<?php
/**
 * A printable A4 proof of one format, at its real physical size.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Imaging;

use AiCake\Domain\FormatCatalogue;
use AiCake\Support\Mm;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * The format screen's diagrams, rendered to something you can hold.
 *
 * D-039: Ruslan validates the geometry by printing every format and checking
 * it, which is the right authority — better than any amount of arithmetic from
 * a spec sheet. This produces the sheet he prints.
 *
 * Two properties make it useful rather than decorative:
 *
 * 1. **Full A4 at 300 DPI with the resolution declared in the file.** Printed
 *    at 100% it is correct by construction. That is the same reason D-033 makes
 *    every print file page-sized: a file that is not page-sized has to be
 *    placed by whoever prints it, and one "fit to page" turns a 150 mm topper
 *    into a 143 mm one. Measuring a proof that lied about its own size would be
 *    worse than not measuring at all.
 * 2. **The bare icing strip is drawn, not subtracted.** The 15 mm at the end
 *    carries no icing, so what matters on the printed sheet is whether the
 *    artwork stops before it — and that is only checkable if the boundary is
 *    visible.
 */
class ProofSheet {

	/**
	 * A4, the paper rather than the usable area.
	 */
	public const PAPER_W_MM = 210.0;
	public const PAPER_H_MM = 297.0;

	private GdEngine $gd;

	private FontCatalogue $fonts;

	/**
	 * @param GdEngine      $gd    Imaging.
	 * @param FontCatalogue $fonts Bundled fonts.
	 */
	public function __construct( GdEngine $gd, FontCatalogue $fonts ) {
		$this->gd    = $gd;
		$this->fonts = $fonts;
	}

	/**
	 * Render one format, returning PNG bytes.
	 *
	 * @param array<string, mixed> $option   Catalogue entry.
	 * @param float                $usable_w Usable width.
	 * @param float                $usable_h Usable height.
	 */
	public function render( array $option, float $usable_w, float $usable_h ): ?string {
		$width  = Mm::to_px( self::PAPER_W_MM );
		$height = Mm::to_px( self::PAPER_H_MM );

		$canvas = $this->gd->blank( $width, $height, false );

		if ( ! $canvas instanceof GdImage ) {
			return null;
		}

		$white = (int) imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $width - 1, $height - 1, $white );

		$black = (int) imagecolorallocate( $canvas, 0, 0, 0 );
		$grey  = (int) imagecolorallocate( $canvas, 170, 170, 170 );

		$this->icing_boundary( $canvas, $usable_h, $width, $height, $grey );

		if ( FormatCatalogue::TYPE_SHEET === $option['type'] ) {
			$this->rectangle( $canvas, $usable_w, $usable_h, $black );
		} else {
			$this->circles( $canvas, $option, $usable_w, $usable_h, $black, $grey );
		}

		$this->caption( $canvas, $option, $usable_w, $usable_h, $black );

		$png = $this->gd->to_png( $canvas, Mm::PRINT_DPI );
		$this->gd->free( $canvas );

		return '' === $png ? null : $png;
	}

	/**
	 * A filename that says what it is without being opened.
	 *
	 * @param array<string, mixed> $option Catalogue entry.
	 */
	public function filename( array $option ): string {
		if ( FormatCatalogue::TYPE_SHEET === $option['type'] ) {
			return 'aicake-proof-a4.png';
		}

		return sprintf(
			'aicake-proof-%s-%dmm-x%d.png',
			(string) $option['type'],
			(int) round( (float) $option['diameter_mm'] ),
			(int) $option['per_sheet']
		);
	}

	/**
	 * Where the icing stops.
	 *
	 * Drawn as a dashed line with the dead strip lightly hatched, because a
	 * solid rule reads as part of the artwork and a plain empty band reads as
	 * nothing at all.
	 *
	 * @param GdImage $canvas   Canvas.
	 * @param float   $usable_h Usable height.
	 * @param int     $width    Canvas width in px.
	 * @param int     $height   Canvas height in px.
	 * @param int     $grey     Colour.
	 */
	private function icing_boundary( GdImage $canvas, float $usable_h, int $width, int $height, int $grey ): void {
		$y = Mm::to_px( $usable_h );

		if ( $y >= $height ) {
			return;
		}

		imagesetthickness( $canvas, max( 1, Mm::to_px( 0.3 ) ) );

		for ( $x = 0; $x < $width; $x += Mm::to_px( 4.0 ) ) {
			imageline( $canvas, $x, $y, min( $width - 1, $x + Mm::to_px( 2.0 ) ), $y, $grey );
		}

		imagesetthickness( $canvas, 1 );

		// Hatch the dead strip so it cannot be mistaken for usable white.
		for ( $x = -$height; $x < $width; $x += Mm::to_px( 5.0 ) ) {
			imageline( $canvas, $x, $y, $x + ( $height - $y ), $height - 1, $grey );
		}
	}

	/**
	 * The whole usable area as one rectangle.
	 *
	 * @param GdImage $canvas   Canvas.
	 * @param float   $usable_w Usable width.
	 * @param float   $usable_h Usable height.
	 * @param int     $black    Colour.
	 */
	private function rectangle( GdImage $canvas, float $usable_w, float $usable_h, int $black ): void {
		imagesetthickness( $canvas, max( 1, Mm::to_px( 0.3 ) ) );

		imagerectangle(
			$canvas,
			0,
			0,
			Mm::to_px( $usable_w ) - 1,
			Mm::to_px( $usable_h ) - 1,
			$black
		);

		imagesetthickness( $canvas, 1 );
	}

	/**
	 * Every piece, at its derived position.
	 *
	 * The trim line is solid black at 0.3 mm — the same line D-033 specifies on
	 * the real print, because the customer cuts the sheet. The bleed ring is
	 * grey, so a piece whose bleed runs off the sheet is visible on the proof
	 * rather than only on a ruined print run.
	 *
	 * @param GdImage              $canvas   Canvas.
	 * @param array<string, mixed> $option   Catalogue entry.
	 * @param float                $usable_w Usable width.
	 * @param float                $usable_h Usable height.
	 * @param int                  $black    Trim colour.
	 * @param int                  $grey     Bleed colour.
	 */
	private function circles(
		GdImage $canvas,
		array $option,
		float $usable_w,
		float $usable_h,
		int $black,
		int $grey
	): void {
		$plan = SheetLayout::plan(
			(float) $option['diameter_mm'],
			$usable_w,
			$usable_h,
			(float) $option['bleed_mm']
		);

		$trim  = Mm::to_px( (float) $option['diameter_mm'] );
		$bled  = Mm::to_px( Mm::with_bleed( (float) $option['diameter_mm'], (float) $option['bleed_mm'] ) );
		$thick = max( 1, Mm::to_px( 0.3 ) );

		foreach ( (array) $plan['centres_px'] as $centre ) {
			$x = (int) $centre['x'];
			$y = (int) $centre['y'];

			if ( $bled > $trim ) {
				imagesetthickness( $canvas, 1 );
				imageellipse( $canvas, $x, $y, $bled, $bled, $grey );
			}

			imagesetthickness( $canvas, $thick );
			imageellipse( $canvas, $x, $y, $trim, $trim, $black );
		}

		imagesetthickness( $canvas, 1 );
	}

	/**
	 * What this sheet is, printed on the sheet.
	 *
	 * In the dead strip on purpose: it carries no icing, so on a real icing
	 * sheet the caption cannot end up on the product. On the plain paper this
	 * will first be printed on, it is simply the label that stops sixteen
	 * proofs becoming sixteen anonymous pages of circles.
	 *
	 * @param GdImage              $canvas   Canvas.
	 * @param array<string, mixed> $option   Catalogue entry.
	 * @param float                $usable_w Usable width.
	 * @param float                $usable_h Usable height.
	 * @param int                  $black    Colour.
	 */
	private function caption( GdImage $canvas, array $option, float $usable_w, float $usable_h, int $black ): void {
		/*
		 * The piece size and the sheet size are different numbers and both
		 * matter when measuring. Reading the piece size out of `width_mm` and
		 * the sheet size out of the *same* field printed "usable 45 x 282 mm"
		 * on the 24-up proof — a caption that is wrong about the thing the
		 * sheet exists to verify.
		 */
		$piece_mm = $option['diameter_mm'] > 0 ? (float) $option['diameter_mm'] : (float) $option['width_mm'];

		$text = sprintf(
			'%s  |  %d x %d = %d  |  piece %s mm + %s mm bleed  |  usable %s x %s mm',
			(string) $option['label'],
			(int) $option['cols'],
			(int) $option['rows'],
			(int) $option['per_sheet'],
			$this->mm( $piece_mm ),
			$this->mm( (float) $option['bleed_mm'] ),
			$this->mm( $usable_w ),
			$this->mm( $usable_h )
		);

		// `sanitize_key()` on the filename, so DejaVuSans.ttf is `dejavusans`.
		$font = $this->fonts->path( 'dejavusans' );
		$y    = Mm::to_px( $usable_h + 6.0 );

		if ( null !== $font && function_exists( 'imagettftext' ) ) {
			imagettftext( $canvas, 26.0, 0, Mm::to_px( 5.0 ), $y, $black, $font, $text );

			return;
		}

		/*
		 * No FreeType is survivable here in a way it is not for a product:
		 * this is a measuring aid, and an unlabelled proof still measures
		 * correctly. GD's built-in font is tiny at 300 DPI but legible.
		 */
		imagestring( $canvas, 5, Mm::to_px( 5.0 ), $y, $text, $black );
	}

	/**
	 * Trim a trailing .0 from a millimetre figure.
	 *
	 * @param float $value Millimetres.
	 */
	private function mm( float $value ): string {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
	}
}
