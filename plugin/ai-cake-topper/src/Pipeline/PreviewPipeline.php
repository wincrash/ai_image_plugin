<?php
/**
 * Master to customer-facing preview.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Pipeline;

use AiCake\Domain\PrintSpec;
use AiCake\Imaging\GdEngine;
use AiCake\Imaging\Watermarker;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * The pre-payment path from PLAN.md §5:
 *
 *   master → shape → watermark → downscale → preview.webp
 *
 * Cheap enough to run synchronously. There is no API call here — the master is
 * already on disk, and everything below is local GD work on an 800 px image.
 *
 * **No text.** The customer's text is composed in the browser over this image
 * and lives as its own layer (D-033), so the preview is the artwork alone —
 * which is also what makes it reusable while they retype.
 *
 * The preview is rendered at the true output aspect and shape, so what the
 * customer approves is what they get.
 */
class PreviewPipeline {

	/**
	 * Big enough to judge, too small to print (§9.3). A 4.5 cm circle needs
	 * 603 px at 300 DPI, so 800 px is already unusable for the largest SKU
	 * and comfortably so for the rest.
	 */
	public const PREVIEW_PX = 800;

	private GdEngine $images;

	private Watermarker $watermarker;

	private PrivateStorage $storage;

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param GdEngine       $images      Pixels.
	 * @param Watermarker    $watermarker Watermark.
	 * @param PrivateStorage $storage     Files.
	 * @param Settings       $settings    Configuration.
	 * @param Logger         $logger      Logging.
	 */
	public function __construct(
		GdEngine $images,
		Watermarker $watermarker,
		PrivateStorage $storage,
		Settings $settings,
		Logger $logger
	) {
		$this->images      = $images;
		$this->watermarker = $watermarker;
		$this->storage     = $storage;
		$this->settings    = $settings;
		$this->logger      = $logger;
	}

	/**
	 * Build a preview and write it next to the master.
	 *
	 * @param string    $master_path    Absolute path to the clean generation.
	 * @param string    $public_id      Design handle.
	 * @param PrintSpec $spec           Product geometry.
	 * @param bool      $master_is_bled Whether the master already carries its bleed
	 *                                  — `SourceCatalogue::master_is_bled()` (D-073).
	 * @return string Path to the preview, or '' on failure.
	 */
	public function build( string $master_path, string $public_id, PrintSpec $spec, bool $master_is_bled = false ): string {
		if ( ! is_readable( $master_path ) ) {
			$this->logger->warning( 'Preview skipped: no master on disk.', array( 'path' => $master_path ) );

			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$bytes  = (string) file_get_contents( $master_path );
		$master = $this->images->from_string( $bytes );

		if ( null === $master ) {
			return '';
		}

		$master = $this->inside_the_cut_line( $master, $spec, $master_is_bled );

		if ( null === $master ) {
			return '';
		}

		list( $target_w, $target_h ) = $this->preview_size( $spec );

		$canvas = $this->images->cover( $master, $target_w, $target_h );
		$this->images->free( $master );

		if ( null === $canvas ) {
			return '';
		}

		// Shaped, so the customer sees what a circular crop does to their
		// picture before paying for it (§15).
		if ( $spec->is_round() ) {
			$this->images->circle_mask( $canvas );
		}

		$this->watermarker->apply( $canvas, $this->watermark_text() );

		$webp = $this->images->to_webp( $canvas, 82 );
		$this->images->free( $canvas );

		if ( '' === $webp ) {
			return '';
		}

		return $this->storage->write(
			$this->storage->session_path( $public_id, 'preview.webp' ),
			$webp
		);
	}

	/**
	 * Throw away anything the blade will (D-073).
	 *
	 * The preview is the picture the customer approves, so it has to show what
	 * is inside the cut line and nothing else. For every source but one the
	 * master already *is* that picture — `FulfilPipeline` invents the bleed
	 * around it — and this is a no-op.
	 *
	 * An uploaded master is the exception: the cropper exports the bled box, so
	 * 3 mm of every edge is picture the customer will never own. Previewing it
	 * shows a ⌀45 mm cupcake as if it were ⌀51 mm and quietly promises 12% more
	 * diameter than arrives.
	 *
	 * Proportional to the master rather than a straight crop to `trim_px()`,
	 * because a master is not guaranteed to be exactly `target_px()` — the
	 * upload endpoint re-encodes what the browser sent, and a device that
	 * exported a pixel short would otherwise crop off-centre.
	 *
	 * @param GdImage   $master         Decoded master; freed if it is replaced.
	 * @param PrintSpec $spec           Product geometry.
	 * @param bool      $master_is_bled Whether the master already carries its bleed.
	 */
	private function inside_the_cut_line( GdImage $master, PrintSpec $spec, bool $master_is_bled ): ?GdImage {
		if ( ! $master_is_bled ) {
			return $master;
		}

		list( $bled_w, $bled_h ) = $spec->target_px();
		list( $trim_w, $trim_h ) = $spec->trim_px();

		if ( $bled_w < 1 || $bled_h < 1 || ( $trim_w >= $bled_w && $trim_h >= $bled_h ) ) {
			return $master;
		}

		$keep = $this->images->crop_centre(
			$master,
			(int) round( imagesx( $master ) * $trim_w / $bled_w ),
			(int) round( imagesy( $master ) * $trim_h / $bled_h )
		);

		if ( null === $keep ) {
			// Better a preview showing 3 mm too much than no preview at all: the
			// customer can still see their picture, and the print file is built
			// from the master rather than from this.
			$this->logger->warning(
				'Could not trim the bleed off the preview; it shows the bled edge.',
				array( 'master' => imagesx( $master ) . 'x' . imagesy( $master ) )
			);

			return $master;
		}

		$this->images->free( $master );

		return $keep;
	}

	/**
	 * Preview dimensions at the product's real aspect ratio.
	 *
	 * @param PrintSpec $spec Product geometry.
	 * @return array{0:int, 1:int}
	 */
	private function preview_size( PrintSpec $spec ): array {
		list( $w_mm, $h_mm ) = $spec->trim_mm();

		if ( $w_mm <= 0 || $h_mm <= 0 ) {
			return array( self::PREVIEW_PX, self::PREVIEW_PX );
		}

		if ( $w_mm >= $h_mm ) {
			return array( self::PREVIEW_PX, max( 1, (int) round( self::PREVIEW_PX * $h_mm / $w_mm ) ) );
		}

		return array( max( 1, (int) round( self::PREVIEW_PX * $w_mm / $h_mm ) ), self::PREVIEW_PX );
	}

	/**
	 * What the watermark says.
	 */
	private function watermark_text(): string {
		$configured = (string) $this->settings->get( 'watermark_text', '' );

		if ( '' !== $configured ) {
			return $configured;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : 'preview';
	}
}
