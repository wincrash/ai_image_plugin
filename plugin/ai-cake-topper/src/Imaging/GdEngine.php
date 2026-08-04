<?php
/**
 * All pixel manipulation.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Imaging;

use AiCake\Support\Logger;
use AiCake\Support\Mm;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * The image engine.
 *
 * PLAN.md §19 sketches an `ImageEngine` interface with GD and Imagick
 * implementations behind it. That was written before D-013/D-015 established
 * that production is a managed platform with GD only and no way to add system
 * packages — so there is no second implementation coming, and an interface
 * with exactly one implementor is indirection that hides the code without
 * abstracting anything. If a genuine second engine ever appears, extracting
 * the interface then is a mechanical refactor.
 *
 * §9.1 is the important reframing: GD is not a degraded fallback we tolerate,
 * GD is the platform, and every feature has to be complete and good-looking on
 * it.
 */
class GdEngine {

	/**
	 * Refuse to allocate beyond this. An A4 sheet at 300 DPI is 8.7 M pixels;
	 * a 4096² intermediate is 16.8 M. Past 40 M we are heading for a fatal
	 * rather than a slow render (§9.2).
	 */
	private const MAX_PIXELS = 40000000;

	private Logger $logger;

	/**
	 * @param Logger $logger Logging.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Decode bytes into an image.
	 *
	 * @param string $bytes Encoded image.
	 */
	public function from_string( string $bytes ): ?GdImage {
		if ( '' === $bytes ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$image = @imagecreatefromstring( $bytes );

		if ( false === $image ) {
			$this->logger->warning( 'Could not decode an image.' );

			return null;
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		return $image;
	}

	/**
	 * A new canvas.
	 *
	 * @param int  $width       Width.
	 * @param int  $height      Height.
	 * @param bool $transparent Transparent, or opaque white.
	 */
	public function blank( int $width, int $height, bool $transparent = true ): ?GdImage {
		if ( $width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS ) {
			$this->logger->error(
				'Refused to allocate a canvas.',
				array(
					'width'  => $width,
					'height' => $height,
				)
			);

			return null;
		}

		$image = imagecreatetruecolor( $width, $height );

		if ( false === $image ) {
			return null;
		}

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$fill = $transparent
			? imagecolorallocatealpha( $image, 0, 0, 0, 127 )
			: imagecolorallocatealpha( $image, 255, 255, 255, 0 );

		if ( false !== $fill ) {
			imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, $fill );
		}

		return $image;
	}

	/**
	 * Resample to an exact size, ignoring aspect ratio.
	 *
	 * @param GdImage $src    Source.
	 * @param int     $width  Target width.
	 * @param int     $height Target height.
	 */
	public function resize( GdImage $src, int $width, int $height ): ?GdImage {
		$out = $this->blank( $width, $height );

		if ( null === $out ) {
			return null;
		}

		imagecopyresampled( $out, $src, 0, 0, 0, 0, $width, $height, imagesx( $src ), imagesy( $src ) );

		return $out;
	}

	/**
	 * Scale to fill the target and centre-crop the overflow.
	 *
	 * This is how both §3.2 and §3.3 are actually satisfied. A4 is 1:1.414 and
	 * no model offers it, so we generate 2:3 and crop the height down. Bleed
	 * works the same way: the image is scaled 3 mm past the trim line rather
	 * than having a border added, so there is real picture in the region that
	 * gets cut.
	 *
	 * @param GdImage $src    Source.
	 * @param int     $width  Target width.
	 * @param int     $height Target height.
	 */
	public function cover( GdImage $src, int $width, int $height ): ?GdImage {
		$src_w = imagesx( $src );
		$src_h = imagesy( $src );

		if ( $src_w < 1 || $src_h < 1 || $width < 1 || $height < 1 ) {
			return null;
		}

		$scale = max( $width / $src_w, $height / $src_h );

		// The source rectangle that, scaled by $scale, exactly fills the target.
		$take_w = (int) round( $width / $scale );
		$take_h = (int) round( $height / $scale );

		$take_w = min( $take_w, $src_w );
		$take_h = min( $take_h, $src_h );

		$src_x = (int) floor( ( $src_w - $take_w ) / 2 );
		$src_y = (int) floor( ( $src_h - $take_h ) / 2 );

		$out = $this->blank( $width, $height );

		if ( null === $out ) {
			return null;
		}

		imagecopyresampled( $out, $src, 0, 0, $src_x, $src_y, $width, $height, $take_w, $take_h );

		return $out;
	}

	/**
	 * Composite one image onto another, centred on a point.
	 *
	 * Imposition (§3.5) is the only caller: N copies of one topper onto a sheet.
	 * The centre, not the corner, is the anchor because that is what
	 * `SheetLayout` computes — a grid of circle centres — and converting to
	 * corners at every call site is where an off-by-half-a-diameter creeps in.
	 *
	 * Alpha blending is turned **on** for the destination during the copy, so a
	 * masked circle's transparent corners let the sheet show through instead of
	 * punching holes in it. It is restored afterwards, because `to_png()`
	 * expects to be able to save the alpha channel.
	 *
	 * @param GdImage $canvas   Destination, modified in place.
	 * @param GdImage $piece    Source.
	 * @param int     $centre_x Where the centre of the piece lands.
	 * @param int     $centre_y Where the centre of the piece lands.
	 */
	public function paste( GdImage $canvas, GdImage $piece, int $centre_x, int $centre_y ): void {
		$width  = imagesx( $piece );
		$height = imagesy( $piece );

		$x = $centre_x - (int) round( $width / 2 );
		$y = $centre_y - (int) round( $height / 2 );

		imagealphablending( $canvas, true );
		imagecopy( $canvas, $piece, $x, $y, 0, 0, $width, $height );
		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );
	}

	/**
	 * Make everything outside the inscribed circle transparent.
	 *
	 * The naive implementation tests every pixel: 5.9 M iterations of PHP for
	 * a 2433 px topper, which is slow enough to matter on shared hosting
	 * (§9.1.1).
	 *
	 * Instead, for each row the circle's horizontal span is computed
	 * analytically and the two outside segments are cleared with
	 * `imagefilledrectangle` — about two fills per row, roughly 4 900
	 * operations. Only the ~2 px boundary band is touched per pixel, which is
	 * some 30 k pixels and negligible. The result is visually
	 * indistinguishable from Imagick's mask.
	 *
	 * @param GdImage $image  Image, modified in place.
	 * @param float   $inset  Pixels to pull the circle in from the edge.
	 */
	public function circle_mask( GdImage $image, float $inset = 0.0 ): void {
		$width  = imagesx( $image );
		$height = imagesy( $image );

		imagealphablending( $image, false );
		imagesavealpha( $image, true );

		$clear = imagecolorallocatealpha( $image, 0, 0, 0, 127 );

		if ( false === $clear ) {
			return;
		}

		$cx     = ( $width - 1 ) / 2.0;
		$cy     = ( $height - 1 ) / 2.0;
		$radius = ( min( $width, $height ) / 2.0 ) - max( 0.0, $inset );

		if ( $radius <= 0 ) {
			return;
		}

		for ( $y = 0; $y < $height; $y++ ) {
			$dy = $y + 0.5 - $cy;

			if ( abs( $dy ) >= $radius ) {
				imagefilledrectangle( $image, 0, $y, $width - 1, $y, $clear );

				continue;
			}

			$dx    = sqrt( ( $radius * $radius ) - ( $dy * $dy ) );
			$left  = (int) floor( $cx - $dx );
			$right = (int) ceil( $cx + $dx );

			// Two rectangle fills replace a per-pixel loop across the row.
			if ( $left > 0 ) {
				imagefilledrectangle( $image, 0, $y, min( $left - 1, $width - 1 ), $y, $clear );
			}

			if ( $right < $width - 1 ) {
				imagefilledrectangle( $image, max( 0, $right + 1 ), $y, $width - 1, $y, $clear );
			}

			// Anti-alias only the boundary band, two pixels either side.
			$this->feather_row( $image, $y, $cx, $cy, $radius, $left, $right, $width );
		}
	}

	/**
	 * Soften the circle edge on one row.
	 *
	 * @param GdImage $image  Image.
	 * @param int     $y      Row.
	 * @param float   $cx     Centre x.
	 * @param float   $cy     Centre y.
	 * @param float   $radius Radius.
	 * @param int     $left   Leftmost inside pixel.
	 * @param int     $right  Rightmost inside pixel.
	 * @param int     $width  Image width.
	 */
	private function feather_row( GdImage $image, int $y, float $cx, float $cy, float $radius, int $left, int $right, int $width ): void {
		$bands = array(
			range( max( 0, $left - 1 ), min( $width - 1, $left + 1 ) ),
			range( max( 0, $right - 1 ), min( $width - 1, $right + 1 ) ),
		);

		foreach ( $bands as $band ) {
			foreach ( $band as $x ) {
				$dx       = $x + 0.5 - $cx;
				$dy       = $y + 0.5 - $cy;
				$distance = sqrt( ( $dx * $dx ) + ( $dy * $dy ) );

				// Coverage falls from 1 to 0 across one pixel at the edge.
				$coverage = $radius + 0.5 - $distance;

				if ( $coverage >= 1.0 ) {
					continue;
				}

				$rgba  = imagecolorat( $image, $x, $y );
				$alpha = ( $rgba >> 24 ) & 0x7F;

				if ( $coverage <= 0.0 ) {
					$new_alpha = 127;
				} else {
					// Combine with whatever transparency the pixel already had.
					$new_alpha = (int) round( 127 - ( ( 127 - $alpha ) * $coverage ) );
				}

				if ( $new_alpha === $alpha ) {
					continue;
				}

				$colour = imagecolorallocatealpha(
					$image,
					( $rgba >> 16 ) & 0xFF,
					( $rgba >> 8 ) & 0xFF,
					$rgba & 0xFF,
					min( 127, max( 0, $new_alpha ) )
				);

				if ( false !== $colour ) {
					imagesetpixel( $image, $x, $y, $colour );
				}
			}
		}
	}

	/**
	 * A circle outline of a real, measurable thickness.
	 *
	 * > **`imagesetthickness()` does not apply to `imageellipse()`.** GD honours
	 * > it for lines, rectangles and polygons and silently ignores it for
	 * > ellipses, so `imagesetthickness( 4 ); imageellipse( … )` draws a **1
	 * > pixel** hairline and reports no error.
	 *
	 * That is 0.085 mm at 300 DPI where 0.3 mm was asked for — a line too thin
	 * to cut along by hand, and thin enough that an inkjet may render it faint
	 * or drop it altogether. It went unnoticed because a hairline circle is
	 * clearly visible on screen: you have to *measure* the file, or print one,
	 * to see that it is wrong. Ruslan found the missing line by printing;
	 * nobody would have found the thin one that way.
	 *
	 * Drawn as `$thickness` concentric one-pixel ellipses, which is exact and
	 * needs no anti-aliasing — a cut line wants a crisp edge anyway.
	 *
	 * @param GdImage $canvas    Target.
	 * @param int     $centre_x  Centre x.
	 * @param int     $centre_y  Centre y.
	 * @param int     $diameter  Outer diameter in pixels.
	 * @param int     $colour    Allocated colour.
	 * @param int     $thickness Thickness in pixels, at least 1.
	 */
	public function ring( GdImage $canvas, int $centre_x, int $centre_y, int $diameter, int $colour, int $thickness = 1 ): void {
		$thickness = max( 1, $thickness );

		// Grown inward from the nominal diameter. The trim circle is where the
		// blade goes, so the outer edge of the ink is the line to follow and
		// the piece keeps its stated size rather than losing half a thickness.
		for ( $i = 0; $i < $thickness; $i++ ) {
			$d = $diameter - ( $i * 2 );

			if ( $d < 1 ) {
				break;
			}

			imageellipse( $canvas, $centre_x, $centre_y, $d, $d, $colour );
		}
	}

	/**
	 * Composite onto opaque white.
	 *
	 * For the print file, always. On a white icing sheet "no ink" and "white"
	 * are the same output, and some printer drivers mishandle alpha in PNGs —
	 * a transparent corner can come out black (§9.1.1). Alpha is kept only for
	 * the on-screen preview, where the round shape has to read as round.
	 *
	 * @param GdImage $src Source.
	 */
	public function flatten_on_white( GdImage $src ): ?GdImage {
		$width  = imagesx( $src );
		$height = imagesy( $src );

		$out = $this->blank( $width, $height, false );

		if ( null === $out ) {
			return null;
		}

		imagealphablending( $out, true );
		imagecopy( $out, $src, 0, 0, 0, 0, $width, $height );
		imagealphablending( $out, false );
		imagesavealpha( $out, true );

		return $out;
	}

	/**
	 * Encode as PNG, declaring the print resolution.
	 *
	 * @param GdImage $image Image.
	 * @param int     $dpi   Resolution to record, 0 to omit.
	 */
	public function to_png( GdImage $image, int $dpi = Mm::PRINT_DPI ): string {
		imagesavealpha( $image, true );

		ob_start();
		imagepng( $image, null, 6 );
		$bytes = (string) ob_get_clean();

		return $dpi > 0 ? $this->inject_phys( $bytes, $dpi ) : $bytes;
	}

	/**
	 * Encode as WebP, for previews (§9.3).
	 *
	 * @param GdImage $image   Image.
	 * @param int     $quality 0–100.
	 */
	public function to_webp( GdImage $image, int $quality = 82 ): string {
		if ( ! function_exists( 'imagewebp' ) ) {
			return $this->to_png( $image, 0 );
		}

		imagesavealpha( $image, true );

		ob_start();
		imagewebp( $image, null, $quality );

		return (string) ob_get_clean();
	}

	/**
	 * Write the physical resolution into the PNG.
	 *
	 * GD does not let you set a meaningful DPI, so a print file would arrive at
	 * the printer claiming 72 or 96 DPI and be scaled to something like four
	 * times its intended size. The `pHYs` chunk fixes that (§9.1).
	 *
	 * Any existing `pHYs` is **removed first**, and that is not tidiness. Recent
	 * libgd writes its own chunk declaring the image's default 96 DPI, so
	 * appending ours produced a PNG with two contradictory resolutions —
	 * malformed, warned about by libpng, and read as 96 by any decoder that
	 * takes the last chunk rather than the first. That is precisely the
	 * wrong-size print this method exists to prevent, hiding inside the fix
	 * for it.
	 *
	 * @param string $png PNG bytes.
	 * @param int    $dpi Resolution.
	 */
	public function inject_phys( string $png, int $dpi ): string {
		$signature = "\x89PNG\r\n\x1a\n";

		if ( 0 !== strpos( $png, $signature ) ) {
			return $png;
		}

		$png = $this->without_chunk( $png, 'pHYs' );

		// Pixels per metre, which is the only unit the chunk supports.
		$ppm = (int) round( $dpi / 0.0254 );

		$data  = pack( 'NN', $ppm, $ppm ) . "\x01";
		$chunk = pack( 'N', strlen( $data ) ) . 'pHYs' . $data . pack( 'N', crc32( 'pHYs' . $data ) );

		/*
		 * IHDR is always the first chunk and pHYs must precede IDAT, so
		 * inserting immediately after IHDR is both legal and simple. IHDR is
		 * a fixed 25 bytes: 4 length + 4 type + 13 data + 4 CRC.
		 */
		$after_ihdr = strlen( $signature ) + 25;

		if ( strlen( $png ) < $after_ihdr ) {
			return $png;
		}

		return substr( $png, 0, $after_ihdr ) . $chunk . substr( $png, $after_ihdr );
	}

	/**
	 * The payload of the first chunk of a given type, or null.
	 *
	 * Walks the chunk list for the same reason `without_chunk()` does: a
	 * `strpos` for the type can land inside compressed image data and report a
	 * resolution read out of pixel noise.
	 *
	 * @param string $png  PNG bytes.
	 * @param string $type Four-character chunk type.
	 */
	private function chunk_data( string $png, string $type ): ?string {
		$length = strlen( $png );
		$offset = 8;

		while ( $offset + 8 <= $length ) {
			$header = unpack( 'Nsize', substr( $png, $offset, 4 ) );

			if ( ! is_array( $header ) ) {
				return null;
			}

			$size  = (int) $header['size'];
			$total = 12 + $size;
			$found = substr( $png, $offset + 4, 4 );

			if ( $total < 12 || $offset + $total > $length ) {
				return null;
			}

			if ( $found === $type ) {
				return substr( $png, $offset + 8, $size );
			}

			if ( 'IEND' === $found ) {
				return null;
			}

			$offset += $total;
		}

		return null;
	}

	/**
	 * A PNG with every chunk of one type removed.
	 *
	 * Walks the chunk list rather than searching for the type as a substring:
	 * the four type bytes can occur inside compressed image data, and a
	 * `str_replace` over a PNG is a corrupted file waiting to happen.
	 *
	 * @param string $png  PNG bytes.
	 * @param string $type Four-character chunk type.
	 */
	private function without_chunk( string $png, string $type ): string {
		$length = strlen( $png );
		$offset = 8; // Past the signature.
		$out    = substr( $png, 0, $offset );

		while ( $offset + 8 <= $length ) {
			$header = unpack( 'Nsize', substr( $png, $offset, 4 ) );

			if ( ! is_array( $header ) ) {
				break;
			}

			// 4 length + 4 type + data + 4 CRC.
			$total = 12 + (int) $header['size'];
			$found = substr( $png, $offset + 4, 4 );

			// Truncated or nonsense length: stop parsing and keep the tail
			// verbatim rather than inventing a repair.
			if ( $total < 12 || $offset + $total > $length ) {
				break;
			}

			if ( $found !== $type ) {
				$out .= substr( $png, $offset, $total );
			}

			$offset += $total;

			if ( 'IEND' === $found ) {
				break;
			}
		}

		return $out . substr( $png, $offset );
	}

	/**
	 * Read back the DPI a PNG declares. Used by the tests and the admin panel.
	 *
	 * @param string $png PNG bytes.
	 * @return int Reported DPI, or 0 when the chunk is absent.
	 */
	public function read_dpi( string $png ): int {
		$data = $this->chunk_data( $png, 'pHYs' );

		if ( null === $data || 9 !== strlen( $data ) ) {
			return 0;
		}

		$parts = unpack( 'Nx/Ny/Cunit', $data );

		if ( ! is_array( $parts ) || 1 !== (int) $parts['unit'] ) {
			return 0;
		}

		return (int) round( $parts['x'] * 0.0254 );
	}

	/**
	 * Free images explicitly.
	 *
	 * PHP's collector will not reclaim these in time on the fulfilment path,
	 * where intermediates are tens of megabytes each (§9.2).
	 *
	 * @param GdImage|null ...$images Images to destroy.
	 */
	public function free( ?GdImage ...$images ): void {
		foreach ( $images as $image ) {
			if ( $image instanceof GdImage ) {
				imagedestroy( $image );
			}
		}
	}
}
