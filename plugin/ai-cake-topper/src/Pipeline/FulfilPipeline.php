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
use AiCake\Providers\ProviderRegistry;
use AiCake\Support\Logger;
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
	 * @param string         $master_path Absolute path to the clean generation.
	 * @param PrintSpec      $spec        Product geometry.
	 * @param TextLayer|null $layer       D-033 composed layer, or null.
	 * @return PrintFile|null Null on any failure; the caller retries.
	 */
	public function render(
		string $master_path,
		PrintSpec $spec,
		?TextLayer $layer = null
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

		$piece = $this->render_piece( $bytes, $spec );
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
	 * @param string        $bytes Master image data.
	 * @param PrintSpec     $spec  Product geometry.
	 */
	private function render_piece( string $bytes, PrintSpec $spec ): ?GdImage {
		$master = $this->images->from_string( $bytes );

		if ( null === $master ) {
			return null;
		}

		list( $target_w, $target_h ) = $spec->target_px();

		// cover(), not resize(): the image is scaled past the trim line so there
		// is real picture in the region the blade cuts through (§3.3).
		$piece = $this->images->cover( $master, $target_w, $target_h );
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
