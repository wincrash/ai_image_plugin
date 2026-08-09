<?php
/**
 * Millimetres, pixels, and the arithmetic between them.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The §3 print maths.
 *
 * Pure functions, no WordPress, no state — deliberately, because this is one
 * of the three places PLAN.md §19 singles out as most likely to be subtly
 * wrong, and being wrong here means physically ruined icing sheets rather than
 * a stack trace.
 *
 * Everything derives from one conversion: 300 DPI is 11.811 px/mm.
 */
final class Mm {

	/**
	 * Print resolution. Not configurable — edible printers are 300 DPI devices
	 * and every table in §3 is computed at it.
	 */
	public const PRINT_DPI = 300;

	/**
	 * Millimetres per inch.
	 */
	public const MM_PER_INCH = 25.4;

	/**
	 * Bleed outside the trim line: the image extends past the cut so a
	 * slightly-off cut leaves no white sliver (§3.3).
	 *
	 * > **This is the arithmetic's default, not this shop's bleed.**
	 * > `FormatCatalogue::BLEED_MM` is what the shop actually sells, and it is
	 * > **zero** (D-074) — every offered format is trim only. Nothing in the
	 * > running system reaches this constant: every real caller is handed a
	 * > bleed by the spec it is working on. It stays 3 mm so the functions below
	 * > keep being testable as arithmetic, and so a format that wants bleed has
	 * > a sensible number to want.
	 */
	public const BLEED_MM = 3.0;

	/**
	 * Safe zone inside the trim line. Nothing important — especially text —
	 * goes within this of the edge (§3.3).
	 */
	public const SAFE_MM = 5.0;

	/**
	 * Not instantiable.
	 */
	private function __construct() {}

	/**
	 * Millimetres to pixels.
	 *
	 * Rounds up, always. A pixel short is a visible white edge on the cut
	 * line; a pixel over is invisible.
	 *
	 * @param float $mm  Millimetres.
	 * @param int   $dpi Resolution.
	 */
	public static function to_px( float $mm, int $dpi = self::PRINT_DPI ): int {
		if ( $mm <= 0 || $dpi <= 0 ) {
			return 0;
		}

		return (int) ceil( $mm * $dpi / self::MM_PER_INCH );
	}

	/**
	 * Pixels back to millimetres. Not rounded — callers are measuring, not
	 * allocating.
	 *
	 * @param int $px  Pixels.
	 * @param int $dpi Resolution.
	 */
	public static function to_mm( int $px, int $dpi = self::PRINT_DPI ): float {
		if ( $px <= 0 || $dpi <= 0 ) {
			return 0.0;
		}

		return $px * self::MM_PER_INCH / $dpi;
	}

	/**
	 * Pixels per millimetre at a given resolution.
	 *
	 * @param int $dpi Resolution.
	 */
	public static function px_per_mm( int $dpi = self::PRINT_DPI ): float {
		return $dpi / self::MM_PER_INCH;
	}

	/**
	 * A trim dimension plus bleed on both sides.
	 *
	 * Bleed is added to *each* edge, so a 200 mm circle with 3 mm bleed is
	 * 206 mm across, not 203 — the single most obvious way to get §3 wrong.
	 *
	 * @param float $trim_mm  Finished size.
	 * @param float $bleed_mm Bleed per edge.
	 */
	public static function with_bleed( float $trim_mm, float $bleed_mm = self::BLEED_MM ): float {
		return $trim_mm + ( 2 * $bleed_mm );
	}

	/**
	 * The size in pixels of a trim dimension once bleed is included.
	 *
	 * @param float $trim_mm  Finished size.
	 * @param int   $dpi      Resolution.
	 * @param float $bleed_mm Bleed per edge.
	 */
	public static function bled_px( float $trim_mm, int $dpi = self::PRINT_DPI, float $bleed_mm = self::BLEED_MM ): int {
		return self::to_px( self::with_bleed( $trim_mm, $bleed_mm ), $dpi );
	}

	/**
	 * The safe-zone inset in pixels, measured from the *bled* edge.
	 *
	 * Text must clear the bleed as well as the safe margin, because the bleed
	 * region is cut away entirely.
	 *
	 * @param int   $dpi      Resolution.
	 * @param float $bleed_mm Bleed per edge.
	 * @param float $safe_mm  Safe margin inside the trim line.
	 */
	public static function safe_inset_px( int $dpi = self::PRINT_DPI, float $bleed_mm = self::BLEED_MM, float $safe_mm = self::SAFE_MM ): int {
		return self::to_px( $bleed_mm + $safe_mm, $dpi );
	}

	/**
	 * Whether a source image is large enough for a target, or needs upscaling.
	 *
	 * This is the §3.1 decision that removes a paid API call from most orders:
	 * a 4.5 cm cupcake circle needs 603 px and a native 1024 px generation
	 * already exceeds it by 70%.
	 *
	 * @param int $source_px Longest edge available.
	 * @param int $target_px Longest edge required.
	 */
	public static function needs_upscale( int $source_px, int $target_px ): bool {
		return $source_px < $target_px;
	}

	/**
	 * The smallest whole upscale factor that reaches a target.
	 *
	 * Whole factors only: the paid upscalers work in 2× and 4× steps, and the
	 * local GD path is cleaner at integer multiples. Returns 1 when the source
	 * is already big enough.
	 *
	 * @param int $source_px Longest edge available.
	 * @param int $target_px Longest edge required.
	 * @param int $max       Largest factor any upscaler offers.
	 */
	public static function upscale_factor( int $source_px, int $target_px, int $max = 4 ): int {
		if ( $source_px <= 0 || $target_px <= $source_px ) {
			return 1;
		}

		foreach ( array( 2, 4, 8 ) as $factor ) {
			if ( $factor > $max ) {
				break;
			}

			if ( $source_px * $factor >= $target_px ) {
				return $factor;
			}
		}

		return $max;
	}

	/**
	 * Physical size of an image at a resolution, as "86.7 × 86.7 mm".
	 *
	 * @param int $width_px  Width.
	 * @param int $height_px Height.
	 * @param int $dpi       Resolution.
	 */
	public static function describe( int $width_px, int $height_px, int $dpi = self::PRINT_DPI ): string {
		return sprintf(
			'%.1f × %.1f mm',
			self::to_mm( $width_px, $dpi ),
			self::to_mm( $height_px, $dpi )
		);
	}
}
