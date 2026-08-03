<?php
/**
 * Making the preview unusable as a product.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Imaging;

use AiCake\Support\Logger;
use AiCake\Support\Settings;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * Watermarking (PLAN.md §9.3).
 *
 * Composited into the pixels server-side, on the derivative only — the clean
 * master is never served under any URL. A CSS overlay or a corner logo would
 * both be defeated by a right-click, which is the entire threat model here:
 * not a determined attacker, but a customer who realises they could just save
 * the picture.
 *
 * Hence diagonal tiling across the whole image rather than a corner mark.
 * Cropping it out means cropping out the design.
 */
class Watermarker {

	/**
	 * Faint enough to judge the artwork through, present enough to ruin it as
	 * a print.
	 *
	 * §9.3 called for 25% and that was measured against nothing — at 25% white
	 * on the white background the house style deliberately produces, the mark
	 * was effectively invisible and the preview was as good as unprotected
	 * (D-032). Overridable with `watermark_opacity`.
	 */
	private const OPACITY = 0.42;

	/**
	 * How far the halo sits behind the mark, as a fraction of type size.
	 * Proportional rather than a fixed 2 px, which vanished on a large preview.
	 */
	private const HALO_RATIO = 0.045;

	private FontCatalogue $fonts;

	private Logger $logger;

	private Settings $settings;

	/**
	 * @param FontCatalogue $fonts    Fonts.
	 * @param Logger        $logger   Logging.
	 * @param Settings      $settings Configuration.
	 */
	public function __construct( FontCatalogue $fonts, Logger $logger, Settings $settings ) {
		$this->fonts    = $fonts;
		$this->logger   = $logger;
		$this->settings = $settings;
	}

	/**
	 * Configured opacity, clamped to something that still lets the artwork be
	 * judged. A watermark nobody can see through is a preview nobody can use.
	 */
	private function opacity(): float {
		$configured = (float) $this->settings->get( 'watermark_opacity', self::OPACITY );

		return max( 0.1, min( 0.75, $configured ) );
	}

	/**
	 * Tile a diagonal watermark across the image.
	 *
	 * @param GdImage $canvas Image, modified in place.
	 * @param string  $text   Watermark text.
	 * @return bool Whether anything was drawn.
	 */
	public function apply( GdImage $canvas, string $text ): bool {
		$text = trim( $text );

		if ( '' === $text ) {
			return false;
		}

		$font = $this->fonts->path( '' );

		if ( null === $font || ! function_exists( 'imagettftext' ) ) {
			// Losing the watermark must not lose the preview, but it does mean
			// serving something more copyable than intended.
			$this->logger->warning( 'Watermark skipped: no usable font on this host.' );

			return false;
		}

		$width  = imagesx( $canvas );
		$height = imagesy( $canvas );

		// Scale with the image so the watermark reads the same at any preview
		// size rather than being enormous on a thumbnail.
		$size = max( 10.0, min( $width, $height ) / 14.0 );

		$box = imagettfbbox( $size, 45, $font, $text );

		if ( false === $box ) {
			return false;
		}

		$xs = array( $box[0], $box[2], $box[4], $box[6] );
		$ys = array( $box[1], $box[3], $box[5], $box[7] );

		$tile_w = (int) max( 40, ( max( $xs ) - min( $xs ) ) * 1.35 );
		$tile_h = (int) max( 40, ( max( $ys ) - min( $ys ) ) * 1.5 );

		imagealphablending( $canvas, true );

		// GD's alpha runs 0 (opaque) to 127 (invisible).
		$opacity = $this->opacity();
		$alpha   = (int) round( 127 - ( 127 * $opacity ) );

		/*
		 * Dark mark, light halo — not the other way round.
		 *
		 * The house style produces flat vector art on a white background, so
		 * the *dark* pass is the one that has to carry the mark; a white one
		 * disappears into exactly the artwork we generate. The halo sits
		 * behind it so the mark still reads if a customer gets a dark subject
		 * (D-032).
		 */
		$halo_alpha = (int) round( 127 - ( 127 * $opacity * 0.55 ) );

		$ink  = imagecolorallocatealpha( $canvas, 30, 30, 30, $alpha );
		$halo = imagecolorallocatealpha( $canvas, 255, 255, 255, $halo_alpha );

		if ( false === $ink || false === $halo ) {
			return false;
		}

		$shift = max( 1, (int) round( $size * self::HALO_RATIO ) );

		/*
		 * Start off-canvas and run past the far edge, so the rotated run does
		 * not leave bare triangles in the corners.
		 */
		for ( $y = -$tile_h; $y < $height + $tile_h; $y += $tile_h ) {
			$offset = 0;

			for ( $x = -$tile_w; $x < $width + $tile_w; $x += $tile_w ) {
				imagettftext( $canvas, $size, 45, $x + $offset + $shift, $y + $shift, $halo, $font, $text );
				imagettftext( $canvas, $size, 45, $x + $offset, $y, $ink, $font, $text );
			}
		}

		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );

		return true;
	}
}
