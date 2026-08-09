<?php
/**
 * Master to print file.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Pipeline;

use AiCake\Domain\PrintFile;
use AiCake\Domain\PrintSpec;
use AiCake\Domain\TextLayer;
use AiCake\Imaging\GdEngine;
use AiCake\Imaging\SheetLayout;
use AiCake\Providers\ProviderRegistry;
use AiCake\Support\Logger;
use AiCake\Support\Mm;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * The post-payment path from PLAN.md §5:
 *
 *   master → upscale (only if short) → shape + bleed → imposition (if copies
 *          > 1) → composite the text layer → flatten → DPI metadata → print.png
 *
 * Three things separate this from `PreviewPipeline`, and each is deliberate:
 *
 * 1. **No watermark.** This is the file that gets printed.
 * 2. **The text layer is composited after imposition, never before.** It
 *    arrives from the browser already at print resolution and sheet sized
 *    (D-033), so it is laid over the finished sheet at 1:1 and never scaled —
 *    baking text into a piece and then imposing gives every cupcake the same
 *    name, which is the whole reason the layer is sheet sized.
 * 3. **Flattened onto white.** On a white icing sheet "no ink" and "white" are
 *    the same output, and some printer drivers turn a transparent corner black
 *    (§9.1.1).
 *
 * It is the memory-hungry part of the plugin — an A4 sheet at 300 DPI is 8.7 M
 * pixels — so intermediates are freed the moment they are finished with rather
 * than at the end of the method (§9.2).
 */
class FulfilPipeline {

	/**
	 * Fallback for a master whose dimensions cannot be read. The real figure
	 * comes off the image itself.
	 */
	private const ASSUMED_NATIVE_PX = 1024;

	/**
	 * Cut-line thickness. Matches `ProofSheet` deliberately — the proof exists
	 * so a printed sheet can be measured against it, and a line of a different
	 * weight would be the first thing to differ.
	 */
	private const CUT_LINE_MM = 0.3;

	private GdEngine $images;

	private ProviderRegistry $providers;

	private Logger $logger;

	/**
	 * @param GdEngine         $images    Pixels.
	 * @param ProviderRegistry $providers For the upscaler.
	 * @param Logger           $logger    Logging.
	 */
	public function __construct(
		GdEngine $images,
		ProviderRegistry $providers,
		Logger $logger
	) {
		$this->images    = $images;
		$this->providers = $providers;
		$this->logger    = $logger;
	}

	/**
	 * Render the file that goes to the printer.
	 *
	 * @param string         $master_path    Absolute path to the clean generation.
	 * @param PrintSpec      $spec           Product geometry.
	 * @param TextLayer|null $layer          D-033 composed layer, or null.
	 * @param bool           $master_is_bled Whether the master already carries its
	 *                                       bleed — `SourceCatalogue::master_is_bled()`
	 *                                       is the only thing that knows (D-073).
	 * @return PrintFile|null Null on any failure; the caller retries.
	 */
	public function render(
		string $master_path,
		PrintSpec $spec,
		?TextLayer $layer = null,
		bool $master_is_bled = false
	): ?PrintFile {
		if ( ! is_readable( $master_path ) ) {
			$this->logger->error( 'Print render aborted: no master on disk.', array( 'path' => $master_path ) );

			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$bytes = (string) file_get_contents( $master_path );

		if ( '' === $bytes ) {
			return null;
		}

		list( $bytes, $factor, $upscaler ) = $this->maybe_upscale( $bytes, $spec );

		$piece = $this->render_piece( $bytes, $spec, $master_is_bled );
		unset( $bytes );

		if ( null === $piece ) {
			return null;
		}

		$canvas = $spec->is_sheet() && $spec->is_round()
			? $this->impose( $piece, $spec )
			: $this->images->flatten_on_white( $piece );

		$this->images->free( $piece );

		if ( null === $canvas ) {
			return null;
		}

		$this->composite_layer( $canvas, $layer );

		$canvas = $this->mount_on_page( $canvas, $spec );

		/*
		 * **After the mount, and that moved for a reason** (D-074). With no
		 * bleed the artwork canvas *is* the trim circle, so a line drawn on it
		 * runs along its own outermost pixel and GD clips the far side of every
		 * ring away — a cut line with a gap in it, 0.085 mm wide and invisible
		 * on screen. On the page there is paper to draw on.
		 *
		 * It also puts the line over the text rather than under it, which is the
		 * right way round: the line is what the shop cuts by, and a letter
		 * allowed right up to the trim (D-042) must not be able to erase it.
		 */
		$this->draw_cut_lines( $canvas, $spec );

		$png = $this->images->to_png( $canvas, $spec->dpi );

		$result = new PrintFile(
			$png,
			imagesx( $canvas ),
			imagesy( $canvas ),
			$spec->dpi,
			$spec->is_sheet() ? $spec->computed_copies() : 1,
			$factor,
			$upscaler
		);

		$this->images->free( $canvas );

		if ( '' === $result->bytes ) {
			return null;
		}

		$this->logger->info(
			'Print file rendered.',
			array(
				'size'    => $result->describe(),
				'copies'  => $result->copies,
				'upscale' => $factor,
			)
		);

		return $result;
	}

	/**
	 * The line the customer cuts along — D-033, built at D-048.
	 *
	 * D-033 specified this and it was never implemented: the editor drew a cut
	 * line on screen and `ProofSheet` drew one on the admin proofs, so the two
	 * things the shop looked at both had it and **the only file that reaches the
	 * printer did not**. Ruslan found it the way it was always going to be
	 * found — by printing a sheet of cupcakes with no circles on it.
	 *
	 * Solid black at 0.3 mm, the same as `ProofSheet`. Ink on the trim line is
	 * correct: the blade goes through it, so it ends up on neither piece.
	 *
	 * Round pieces only. A rectangular A4 sheet is trimmed at the sheet edge —
	 * there is nothing to draw, and a border printed at the very edge would be
	 * clipped by the printer's own margins rather than guiding anything.
	 *
	 * The centres come from `sheet_plan()`, which is what `impose()` pasted
	 * against — see `cut_centres()`.
	 *
	 * @param GdImage   $canvas The mounted page.
	 * @param PrintSpec $spec   Product geometry.
	 */
	private function draw_cut_lines( GdImage $canvas, PrintSpec $spec ): void {
		if ( ! $spec->is_round() ) {
			return;
		}

		$black = imagecolorallocate( $canvas, 0, 0, 0 );

		if ( false === $black ) {
			return;
		}

		$diameter = Mm::to_px( $spec->width_mm, $spec->dpi );
		$thick    = max( 1, Mm::to_px( self::CUT_LINE_MM, $spec->dpi ) );

		foreach ( $this->cut_centres( $canvas, $spec ) as $centre ) {
			// `GdEngine::ring()`, not `imageellipse()` — GD ignores
			// `imagesetthickness()` for ellipses and would draw a 0.085 mm
			// hairline while reporting success.
			$this->images->ring( $canvas, (int) $centre['x'], (int) $centre['y'], $diameter, $black, $thick );
		}
	}

	/**
	 * Where the rings go, in the coordinates of the file being written.
	 *
	 * One list for both cases, now that the lines are drawn on the page: a sheet
	 * rings every cell, a single piece rings the first one, and `page_anchor()`
	 * mounted that piece at exactly that coordinate. Two derivations of the same
	 * point is how the printed line and the printed artwork drift apart by one
	 * rounding step (D-038).
	 *
	 * The fallback is for the one case where the plan is not page coordinates —
	 * `mount_on_page()` could not allocate the page, said so at error level, and
	 * handed back the bare artwork. Ringing plan coordinates on that would put
	 * the line off the file entirely; its own centre is right for it.
	 *
	 * @param GdImage   $canvas What is about to be written.
	 * @param PrintSpec $spec   Product geometry.
	 * @return array<int, array{x:int, y:int}>
	 */
	private function cut_centres( GdImage $canvas, PrintSpec $spec ): array {
		$centres = (array) ( $spec->sheet_plan()['centres_px'] ?? array() );

		if ( ! $spec->is_sheet() ) {
			$centres = array_slice( $centres, 0, 1 );
		}

		if ( empty( $centres ) || imagesx( $canvas ) < Mm::to_px( SheetLayout::PAPER_W_MM, $spec->dpi ) ) {
			return array(
				array(
					'x' => (int) round( imagesx( $canvas ) / 2 ),
					'y' => (int) round( imagesy( $canvas ) / 2 ),
				),
			);
		}

		return $centres;
	}

	/**
	 * Put the finished artwork on a page (D-070).
	 *
	 * **Everything above this composes the artwork; this makes it a sheet of
	 * paper.** Without it the file is only the usable area — 210 × 282 mm for a
	 * cupcake sheet, and a bare 156 mm square for a single ⌀15 cm topper — and
	 * whoever prints it has to decide where on the A4 that goes. "Actual size"
	 * centres it and shifts every circle 7.5 mm against the proof; "fit to page"
	 * scales it by 5.3% and a ⌀45 mm cupcake comes out 47.4 mm. Both look
	 * perfectly correct on screen, which is why this survived to a printed sheet.
	 *
	 * `ProofSheet` has always been full A4 with the usable area at the top-left
	 * and the bare icing strip drawn at the bottom, and D-040 confirmed on paper
	 * that the strip lands at the right end. So the page origin is settled, and
	 * mounting here at the same origin makes the order file **overlay the proof
	 * exactly** — which is what makes cutting by the printed line reliable.
	 *
	 * Done last, after the cut lines and the text layer, deliberately. The layer
	 * is authored at `PrintSpec::canvas_px()` and `composite_layer()` refuses
	 * anything else; enlarging the canvas earlier would make every text layer
	 * the wrong size for it. Mounting a finished canvas changes no geometry that
	 * anything else knows about — the editor, the endpoints and the stored
	 * layers all still work in usable-area coordinates.
	 *
	 * @param GdImage   $canvas Finished artwork, freed if it is replaced.
	 * @param PrintSpec $spec   Product geometry.
	 */
	private function mount_on_page( GdImage $canvas, PrintSpec $spec ): GdImage {
		$page_w = Mm::to_px( SheetLayout::PAPER_W_MM, $spec->dpi );
		$page_h = Mm::to_px( SheetLayout::PAPER_H_MM, $spec->dpi );

		if ( imagesx( $canvas ) >= $page_w && imagesy( $canvas ) >= $page_h ) {
			return $canvas;
		}

		$page = $this->images->blank( $page_w, $page_h, false );

		if ( null === $page ) {
			// Better a file that has to be placed by hand than no file at all on
			// an order somebody has paid for. Loud, because the printed result
			// is wrong in a way that only a ruler catches.
			$this->logger->error(
				'Could not allocate the print page; the file is not page-sized.',
				array( 'page' => $page_w . 'x' . $page_h )
			);

			return $canvas;
		}

		list( $centre_x, $centre_y ) = $this->page_anchor( $canvas, $spec );

		$this->images->paste( $page, $canvas, $centre_x, $centre_y );
		$this->images->free( $canvas );

		return $page;
	}

	/**
	 * Where on the page the artwork's centre goes.
	 *
	 * Two cases, and both are "the same place the proof draws it".
	 *
	 * An imposed sheet, or the whole-A4 format, *is* the usable area: it sits at
	 * the page's top-left, so its centre is half its own size. A single piece is
	 * one bled circle with nothing around it, so it goes at the centre
	 * `SheetLayout` computed for it — the same coordinate `ProofSheet` rings.
	 * Deriving it from the plan rather than centring the piece on the page is
	 * what keeps a ⌀15 cm order and a ⌀15 cm proof superimposable.
	 *
	 * @param GdImage   $canvas Finished artwork.
	 * @param PrintSpec $spec   Product geometry.
	 * @return array{0:int, 1:int}
	 */
	private function page_anchor( GdImage $canvas, PrintSpec $spec ): array {
		$own_centre = array(
			(int) round( imagesx( $canvas ) / 2 ),
			(int) round( imagesy( $canvas ) / 2 ),
		);

		if ( ! $spec->is_round() || $spec->is_sheet() ) {
			return $own_centre;
		}

		$centres = (array) ( $spec->sheet_plan()['centres_px'] ?? array() );

		if ( empty( $centres ) ) {
			return $own_centre;
		}

		return array( (int) $centres[0]['x'], (int) $centres[0]['y'] );
	}

	/**
	 * Draw the customer's composed text over the finished canvas.
	 *
	 * **After imposition, not before** (D-033). Text baked into a piece and
	 * then imposed gives every cupcake the same name; a sheet-sized layer laid
	 * over the imposed canvas gives twelve cupcakes twelve names, which is the
	 * whole reason the layer is sheet sized.
	 *
	 * Never scaled. The layer is authored at exactly `PrintSpec::canvas_px()`
	 * and the endpoint refuses anything else, so a mismatch here is not a
	 * rounding difference to paper over — it means the geometry moved after the
	 * layer was made, and stretching it would put text across a cut line while
	 * still producing a plausible-looking file. Refuse, and say so loudly.
	 *
	 * @param GdImage        $canvas The flattened print canvas, modified in place.
	 * @param TextLayer|null $layer  The stored layer, or null.
	 */
	private function composite_layer( GdImage $canvas, ?TextLayer $layer ): void {
		if ( null === $layer || ! $layer->has_bitmap() ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$image = $this->images->from_string( (string) file_get_contents( $layer->path ) );

		if ( null === $image ) {
			$this->logger->error( 'Text layer would not decode.', array( 'path' => $layer->path ) );

			return;
		}

		$canvas_w = imagesx( $canvas );
		$canvas_h = imagesy( $canvas );

		if ( imagesx( $image ) !== $canvas_w || imagesy( $image ) !== $canvas_h ) {
			$this->logger->error(
				'Text layer does not match the print canvas and was not composited.',
				array(
					'layer'  => imagesx( $image ) . 'x' . imagesy( $image ),
					'canvas' => $canvas_w . 'x' . $canvas_h,
				)
			);

			$this->images->free( $image );

			return;
		}

		$this->images->paste( $canvas, $image, (int) round( $canvas_w / 2 ), (int) round( $canvas_h / 2 ) );
		$this->images->free( $image );

		$this->logger->info( 'Text layer composited.', array( 'size' => $canvas_w . 'x' . $canvas_h ) );
	}

	/**
	 * One finished piece: bled and shaped, still with its alpha.
	 *
	 * **Which of the two bleed routes is right depends on the master** (D-073),
	 * and both end at the same size — the difference is what lands inside the
	 * cut line.
	 *
	 * A cropped upload already *is* the bled piece: the customer framed the trim
	 * circle and the 3 mm around it is real photograph (D-070), so `cover()`
	 * here is a no-op that preserves that mapping exactly.
	 *
	 * A generation, a found photograph or a blank sheet has nothing outside the
	 * artwork. `cover()`ing those to the bled box scales the picture up until the
	 * blade takes a ring off it — 12% of the diameter on a cupcake — and the ring
	 * it takes is part of what the customer approved in the preview. Those get
	 * `bleed_out()`: the picture at trim size, the bleed invented around it.
	 *
	 * @param string    $bytes          Master image data.
	 * @param PrintSpec $spec           Product geometry.
	 * @param bool      $master_is_bled Whether the master already carries its bleed.
	 */
	private function render_piece( string $bytes, PrintSpec $spec, bool $master_is_bled ): ?GdImage {
		$master = $this->images->from_string( $bytes );

		if ( null === $master ) {
			return null;
		}

		list( $target_w, $target_h ) = $spec->target_px();
		list( $trim_w, $trim_h )     = $spec->trim_px();

		$piece = $master_is_bled
			? $this->images->cover( $master, $target_w, $target_h )
			: $this->images->bleed_out( $master, $trim_w, $trim_h, $target_w, $target_h );

		$this->images->free( $master );

		if ( null === $piece ) {
			return null;
		}

		// The circle is the artwork's shape, not the text's. Text is composited
		// over the imposed sheet later and is constrained to the cut line in
		// the browser (D-042), so nothing here clips it.
		if ( $spec->is_round() ) {
			$this->images->circle_mask( $piece );
		}

		return $piece;
	}

	/**
	 * Lay N copies of one piece out on a sheet (§3.5).
	 *
	 * The count comes from the geometry, never from the configured `copies` —
	 * a product saying 24 when 4.5 cm circles yield 20 must print 20 correct
	 * circles, not 24 overlapping ones. The product screen already warns about
	 * the mismatch; this is where refusing to honour it actually matters.
	 *
	 * @param GdImage   $piece One finished topper.
	 * @param PrintSpec $spec  Product geometry.
	 */
	private function impose( GdImage $piece, PrintSpec $spec ): ?GdImage {
		$plan = $spec->sheet_plan();

		if ( empty( $plan['centres_px'] ) ) {
			$this->logger->error( 'Imposition produced no cells.', array( 'diameter_mm' => $spec->width_mm ) );

			return null;
		}

		// Opaque white from the start: this is the sheet, and every gutter
		// between the circles is unprinted area.
		$sheet = $this->images->blank( (int) $plan['sheet_w_px'], (int) $plan['sheet_h_px'], false );

		if ( null === $sheet ) {
			return null;
		}

		foreach ( $plan['centres_px'] as $centre ) {
			$this->images->paste( $sheet, $piece, (int) $centre['x'], (int) $centre['y'] );
		}

		if ( ! empty( $plan['bleed_clipped'] ) ) {
			// Not an error — the bleed is cut away anyway — but the shop should
			// know its margin for a crooked cut is gone (§3.5).
			$this->logger->warning(
				'Sheet bleed extends past the printable area.',
				array( 'diameter_mm' => $spec->width_mm )
			);
		}

		return $sheet;
	}

	/**
	 * Upscale only when the master is genuinely too small (§3.1).
	 *
	 * This check removes a paid API call from the majority of orders: a 4.5 cm
	 * cupcake circle needs 603 px and a native 1024 px generation already
	 * exceeds it by 70%.
	 *
	 * @param string    $bytes Master image data.
	 * @param PrintSpec $spec  Product geometry.
	 * @return array{0:string, 1:int, 2:string} Bytes, factor applied, upscaler id.
	 */
	private function maybe_upscale( string $bytes, PrintSpec $spec ): array {
		$factor = $spec->upscale_factor( $this->native_px( $bytes ) );

		if ( $factor < 2 ) {
			return array( $bytes, 1, '' );
		}

		$upscaler = $this->providers->upscaler();

		if ( null === $upscaler ) {
			// GD bicubic is always available, so reaching here means the whole
			// registry is misconfigured. Print from the master rather than not
			// printing: a soft image is a complaint, a missing order is worse.
			$this->logger->warning( 'No upscaler available; printing from the master.' );

			return array( $bytes, 1, '' );
		}

		$factor  = min( $factor, $upscaler->max_factor() );
		$scaled  = $factor >= 2 ? $upscaler->upscale( $bytes, $factor ) : '';

		if ( '' === $scaled ) {
			$this->logger->warning( 'Upscale failed; printing from the master.', array( 'factor' => $factor ) );

			return array( $bytes, 1, '' );
		}

		return array( $scaled, $factor, $upscaler->id() );
	}

	/**
	 * The master's longest edge, so the upscale decision uses the real image
	 * rather than an assumption about what the provider returns.
	 *
	 * @param string $bytes Image data.
	 */
	private function native_px( string $bytes ): int {
		$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt master is handled by the caller; this only picks a fallback.

		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) ) {
			return self::ASSUMED_NATIVE_PX;
		}

		return max( (int) $size[0], (int) $size[1] );
	}
}
