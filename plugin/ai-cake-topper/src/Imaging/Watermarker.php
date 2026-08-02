<?php
/**
 * Making the preview unusable as a product.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Imaging;

use AiCake\Support\Logger;
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
	 * a print. §9.3 calls for about 25%.
	 */
	private const OPACITY = 0.25;

	private FontCatalogue $fonts;

	private Logger $logger;

	/**
	 * @param FontCatalogue $fonts  Fonts.
	 * @param Logger        $logger Logging.
	 */
	public function __construct( FontCatalogue $fonts, Logger $logger ) {
		$this->fonts  = $fonts;
		$this->logger = $logger;
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
		$size = max( 10.0, min( $width, $height ) / 18.0 );

		$box = imagettfbbox( $size, 45, $font, $text );

		if ( false === $box ) {
			return false;
		}

		$xs = array( $box[0], $box[2], $box[4], $box[6] );
		$ys = array( $box[1], $box[3], $box[5], $box[7] );

		$tile_w = (int) max( 40, ( max( $xs ) - min( $xs ) ) * 1.6 );
		$tile_h = (int) max( 40, ( max( $ys ) - min( $ys ) ) * 1.8 );

		imagealphablending( $canvas, true );

		// GD's alpha runs 0 (opaque) to 127 (invisible).
		$alpha = (int) round( 127 - ( 127 * self::OPACITY ) );

		$white = imagecolorallocatealpha( $canvas, 255, 255, 255, $alpha );
		$black = imagecolorallocatealpha( $canvas, 0, 0, 0, min( 127, $alpha + 25 ) );

		if ( false === $white || false === $black ) {
			return false;
		}

		/*
		 * Start off-canvas and run past the far edge, so the rotated run does
		 * not leave bare triangles in the corners.
		 */
		for ( $y = -$tile_h; $y < $height + $tile_h; $y += $tile_h ) {
			$offset = 0;

			for ( $x = -$tile_w; $x < $width + $tile_w; $x += $tile_w ) {
				// A soft dark copy behind the white keeps the mark legible on
				// pale artwork, which is most of it — the house style asks for
				// a white background.
				imagettftext( $canvas, $size, 45, $x + $offset + 2, $y + 2, $black, $font, $text );
				imagettftext( $canvas, $size, 45, $x + $offset, $y, $white, $font, $text );
			}
		}

		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );

		return true;
	}
}
