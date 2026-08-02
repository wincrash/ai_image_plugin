<?php
/**
 * Drawing words on a topper.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Imaging;

use AiCake\Domain\TextSpec;
use AiCake\Support\Logger;
use AiCake\Support\Mm;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * The text layer (PLAN.md §9.4).
 *
 * Rendered independently at preview resolution and at print resolution from
 * the same TextSpec, never scaled up from the preview — that is the difference
 * between crisp lettering and the blurry text that makes a print shop look
 * cheap.
 */
class TextRenderer {

	/**
	 * Offsets used to fake a stroke. GD has no outline setting, so the glyph
	 * run is drawn eight times around the position and the fill is drawn on
	 * top (§9.1). Eight is enough to look continuous; four leaves corners.
	 */
	private const OUTLINE_OFFSETS = array(
		array( -1, -1 ), array( 0, -1 ), array( 1, -1 ),
		array( -1, 0 ), array( 1, 0 ),
		array( -1, 1 ), array( 0, 1 ), array( 1, 1 ),
	);

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
	 * Whether text can be drawn at all on this host.
	 *
	 * The entire text layer rests on GD having been compiled with FreeType.
	 * Without it there is no TrueType rendering — GD's built-in bitmap fonts
	 * are tiny and unusable on a cake (§9.1.2).
	 */
	public function is_available(): bool {
		if ( ! function_exists( 'imagettftext' ) || ! function_exists( 'imagettfbbox' ) ) {
			return false;
		}

		$info = function_exists( 'gd_info' ) ? gd_info() : array();

		return ! empty( $info['FreeType Support'] ) && $this->fonts->has_usable_font();
	}

	/**
	 * Draw a text spec onto an image.
	 *
	 * @param GdImage  $canvas Image, modified in place.
	 * @param TextSpec $spec   What to draw.
	 * @param int      $dpi    Resolution, so millimetre sizes mean something.
	 * @param bool     $round  Whether the topper is a circle. Changes how much
	 *                         width the text actually has — see chord_width().
	 * @return bool Whether anything was drawn.
	 */
	public function render( GdImage $canvas, TextSpec $spec, int $dpi = Mm::PRINT_DPI, bool $round = true ): bool {
		if ( $spec->is_empty() ) {
			return false;
		}

		if ( ! $this->is_available() ) {
			$this->logger->error( 'Text was requested but this host cannot render TrueType fonts.' );

			return false;
		}

		$font = $this->fonts->path( $spec->font );

		if ( null === $font ) {
			return false;
		}

		imagealphablending( $canvas, true );

		$drawn = $spec->is_arc()
			? $this->render_arc( $canvas, $spec, $font, $dpi )
			: $this->render_straight( $canvas, $spec, $font, $dpi, $round );

		imagealphablending( $canvas, false );
		imagesavealpha( $canvas, true );

		return $drawn;
	}

	/**
	 * Horizontal text, auto-fitted and wrapped.
	 *
	 * @param GdImage  $canvas Canvas.
	 * @param TextSpec $spec   Spec.
	 * @param string   $font   Font path.
	 * @param int      $dpi    Resolution.
	 * @param bool     $round  Whether the topper is a circle.
	 */
	private function render_straight( GdImage $canvas, TextSpec $spec, string $font, int $dpi, bool $round ): bool {
		$width  = imagesx( $canvas );
		$height = imagesy( $canvas );
		$inset  = Mm::safe_inset_px( $dpi );

		/*
		 * The safe zone is not decoration. Anything within 5 mm of the trim
		 * line risks being cut off, and text is the one thing a customer will
		 * definitely notice losing (§3.3).
		 */
		$available_w = $width - ( 2 * $inset );
		$available_h = (int) ( ( $height - ( 2 * $inset ) ) * 0.35 );

		if ( $available_w < 20 || $available_h < 10 ) {
			return false;
		}

		/*
		 * On a round topper the full image width is available only across the
		 * middle. Text sitting near the top or bottom has the circle's chord
		 * to work with, which is dramatically narrower — at 80% of the height
		 * a circle is only 80% as wide as it is at the centre, and the text
		 * runs off the edge of the actual product while looking perfectly
		 * fine on the square canvas.
		 *
		 * The width depends on where the block sits, and where it sits depends
		 * on how tall it is, which depends on the width. Two passes converge
		 * on it: fit at full width, see where that lands, then refit against
		 * the chord actually available there.
		 */
		$fitted = $this->fit( $spec, $font, $available_w, $available_h, $dpi );

		if ( array() === $fitted['lines'] ) {
			return false;
		}

		if ( $round ) {
			for ( $pass = 0; $pass < 3; $pass++ ) {
				$block_h = (int) round( $fitted['size'] * 1.35 ) * count( $fitted['lines'] );
				$top     = $this->block_top( $spec->placement, $height, $inset, $block_h, $width, true );

				$chord = $this->chord_width( $width, $height, $top, $top + $block_h, $inset );

				if ( $chord >= $available_w || $chord < 20 ) {
					break;
				}

				$available_w = $chord;
				$refit       = $this->fit( $spec, $font, $available_w, $available_h, $dpi );

				if ( array() === $refit['lines'] ) {
					break;
				}

				$fitted = $refit;
			}
		}

		$line_height = (int) round( $fitted['size'] * 1.35 );
		$block_h     = $line_height * count( $fitted['lines'] );
		$top         = $this->block_top( $spec->placement, $height, $inset, $block_h, $width, $round );

		foreach ( $fitted['lines'] as $index => $line ) {
			$metrics = $this->measure( $fitted['size'], $font, $line );
			$x       = (int) ( ( $width - $metrics['width'] ) / 2 ) - $metrics['left'];
			$y       = $top + ( $index * $line_height ) + (int) round( $fitted['size'] );

			$this->draw( $canvas, $spec, $font, $fitted['size'], 0.0, $x, $y, $line, $dpi );
		}

		return true;
	}

	/**
	 * Text following the circle edge.
	 *
	 * Imagick would do this with `distortImage(ARC)`, which is smoother — but
	 * production has no Imagick, and arc text is extremely common on round
	 * toppers, so it has to work on GD. It can: rather than warping a rendered
	 * strip, each character is placed individually along the arc at its own
	 * tangent angle, using `imagettfbbox` for the advance width so spacing
	 * stays correct (§9.4).
	 *
	 * Kerning between adjacent pairs is lost. On the short strings this is for
	 * — "Su gimtadieniu", a name — that is invisible.
	 *
	 * @param GdImage  $canvas Canvas.
	 * @param TextSpec $spec   Spec.
	 * @param string   $font   Font path.
	 * @param int      $dpi    Resolution.
	 */
	private function render_arc( GdImage $canvas, TextSpec $spec, string $font, int $dpi ): bool {
		$width  = imagesx( $canvas );
		$height = imagesy( $canvas );
		$inset  = Mm::safe_inset_px( $dpi );

		$cx = ( $width - 1 ) / 2.0;
		$cy = ( $height - 1 ) / 2.0;

		$size = $spec->size_mm > 0
			? $this->mm_to_points( $spec->size_mm, $dpi )
			: $this->mm_to_points( max( 4.0, Mm::to_mm( (int) ( min( $width, $height ) * 0.075 ), $dpi ) ), $dpi );

		$characters = $this->characters( $spec->text );

		if ( array() === $characters ) {
			return false;
		}

		// Sit the baseline inside the safe zone, allowing for glyph height.
		$radius = ( min( $width, $height ) / 2.0 ) - $inset - $size;

		if ( $radius < $size ) {
			return false;
		}

		$is_top = TextSpec::PLACE_ARC_TOP === $spec->placement;

		/*
		 * Arc text needs its own fit rule. A long string at a fixed size does
		 * not overflow a box — it keeps going round the circle, and past about
		 * two-thirds of the circumference it starts colliding with itself and
		 * with whatever is at the opposite pole. Shrink until the run occupies
		 * at most 200°, which keeps a comfortable gap.
		 */
		$max_span = deg2rad( 200.0 );

		for ( $attempt = 0; $attempt < 12; $attempt++ ) {
			$total = 0.0;

			foreach ( $characters as $character ) {
				$total += $this->advance( $size, $font, $character );
			}

			if ( $total / $radius <= $max_span || $size <= 8.0 ) {
				break;
			}

			$size *= 0.9;

			// A smaller glyph sits further out, so the radius grows a little.
			$radius = ( min( $width, $height ) / 2.0 ) - $inset - $size;
		}

		$advances = array();
		$total    = 0.0;

		foreach ( $characters as $character ) {
			$advance    = $this->advance( $size, $font, $character );
			$advances[] = $advance;
			$total     += $advance;
		}

		// Angular width of the whole string, then start half of it back from
		// the top (or bottom) so the run is centred.
		$span  = $total / $radius;
		$angle = ( $is_top ? 0.0 : M_PI ) - ( $is_top ? $span / 2 : -$span / 2 );

		foreach ( $characters as $index => $character ) {
			$advance = $advances[ $index ];
			$step    = $advance / $radius;

			// Rotation is taken from the centre of the glyph, not its left
			// edge, or the run visibly leans.
			$mid = $is_top ? $angle + ( $step / 2 ) : $angle - ( $step / 2 );

			$x = $cx + ( $radius * sin( $angle ) );
			$y = $cy - ( $radius * cos( $angle ) );

			if ( ! $is_top ) {
				// Along the bottom the glyphs face outward, so the baseline
				// runs the other way and each character is flipped.
				$rotation = rad2deg( $mid ) + 180.0;
			} else {
				$rotation = -rad2deg( $mid );
			}

			$this->draw( $canvas, $spec, $font, $size, $rotation, (int) round( $x ), (int) round( $y ), $character, $dpi );

			$angle = $is_top ? $angle + $step : $angle - $step;
		}

		return true;
	}

	/**
	 * How far from the centre line a text block's outer edge may sit, as a
	 * fraction of the radius.
	 *
	 * At the very edge of a circle the available width is zero, so "bottom"
	 * cannot mean the bottom of the safe zone the way it does on a rectangle —
	 * that placement has no room for any text at all. At 0.82 of the radius
	 * the chord is still about 57% of the diameter, which is enough for a name
	 * or a short greeting and is roughly where a human would put it.
	 */
	private const ROUND_EDGE_LIMIT = 0.82;

	/**
	 * Where a text block of a given height sits.
	 *
	 * @param string $placement One of the TextSpec::PLACE_* constants.
	 * @param int    $height    Canvas height.
	 * @param int    $inset     Safe inset.
	 * @param int    $block_h   Block height.
	 * @param int    $width     Canvas width.
	 * @param bool   $round     Whether the topper is a circle.
	 */
	private function block_top( string $placement, int $height, int $inset, int $block_h, int $width, bool $round ): int {
		if ( TextSpec::PLACE_CENTRE === $placement ) {
			return (int) ( ( $height - $block_h ) / 2 );
		}

		$is_top = TextSpec::PLACE_TOP === $placement;

		if ( ! $round ) {
			return $is_top ? $inset : $height - $inset - $block_h;
		}

		$radius = ( min( $width, $height ) / 2.0 ) - $inset;
		$cy     = ( $height - 1 ) / 2.0;
		$limit  = self::ROUND_EDGE_LIMIT * $radius;

		if ( $is_top ) {
			return (int) round( max( (float) $inset, $cy - $limit ) );
		}

		$bottom = min( (float) ( $height - $inset ), $cy + $limit );

		return (int) round( $bottom - $block_h );
	}

	/**
	 * The narrowest the circle gets across a horizontal band.
	 *
	 * Half-chord at a distance d from the centre is sqrt(r² − d²). The
	 * constraining edge of a text block is whichever of its top or bottom sits
	 * further from the centre line, so both are measured and the smaller
	 * chord wins.
	 *
	 * @param int $width  Canvas width.
	 * @param int $height Canvas height.
	 * @param int $top    Block top.
	 * @param int $bottom Block bottom.
	 * @param int $inset  Safe inset to keep clear inside the circle.
	 */
	private function chord_width( int $width, int $height, int $top, int $bottom, int $inset ): int {
		$radius = ( min( $width, $height ) / 2.0 ) - $inset;

		if ( $radius <= 0 ) {
			return 0;
		}

		$cy      = ( $height - 1 ) / 2.0;
		$furthest = max( abs( $top - $cy ), abs( $bottom - $cy ) );

		if ( $furthest >= $radius ) {
			return 0;
		}

		return (int) floor( 2 * sqrt( ( $radius * $radius ) - ( $furthest * $furthest ) ) );
	}

	/**
	 * Find a size and line break-up that fits the available box.
	 *
	 * Shrinks rather than overflowing, and wraps rather than shrinking to
	 * nothing — the priority order in §9.4 is never overflow, then wrap, then
	 * shrink.
	 *
	 * @param TextSpec $spec        Spec.
	 * @param string   $font        Font path.
	 * @param int      $available_w Width budget.
	 * @param int      $available_h Height budget.
	 * @param int      $dpi         Resolution.
	 * @return array{size:float, lines:string[]}
	 */
	private function fit( TextSpec $spec, string $font, int $available_w, int $available_h, int $dpi ): array {
		$text = trim( preg_replace( '/\s+/u', ' ', $spec->text ) ?? $spec->text );

		if ( '' === $text ) {
			return array(
				'size'  => 0.0,
				'lines' => array(),
			);
		}

		// A requested size is honoured if it fits, and reduced if it does not.
		$start = $spec->size_mm > 0
			? $this->mm_to_points( $spec->size_mm, $dpi )
			: $available_h * 0.8;

		for ( $size = $start; $size >= 6.0; $size *= 0.94 ) {
			$lines = $this->wrap( $text, $size, $font, $available_w, $spec->max_lines );

			if ( array() === $lines ) {
				continue;
			}

			$block = $size * 1.35 * count( $lines );

			if ( $block <= $available_h ) {
				return array(
					'size'  => $size,
					'lines' => $lines,
				);
			}
		}

		// Nothing fitted cleanly; draw one hard-truncated line rather than
		// silently dropping the customer's text.
		return array(
			'size'  => 6.0,
			'lines' => array( $text ),
		);
	}

	/**
	 * Greedy word wrap.
	 *
	 * @param string $text    Text.
	 * @param float  $size    Font size.
	 * @param string $font    Font path.
	 * @param int    $max_w   Width budget.
	 * @param int    $max_lines Maximum lines allowed.
	 * @return string[] Empty when it cannot be made to fit.
	 */
	private function wrap( string $text, float $size, string $font, int $max_w, int $max_lines ): array {
		$words = explode( ' ', $text );
		$lines = array();
		$line  = '';

		foreach ( $words as $word ) {
			$candidate = '' === $line ? $word : $line . ' ' . $word;

			if ( $this->measure( $size, $font, $candidate )['width'] <= $max_w ) {
				$line = $candidate;

				continue;
			}

			if ( '' !== $line ) {
				$lines[] = $line;
			}

			// A single word wider than the box means this size cannot work.
			if ( $this->measure( $size, $font, $word )['width'] > $max_w ) {
				return array();
			}

			$line = $word;

			if ( count( $lines ) >= $max_lines ) {
				return array();
			}
		}

		if ( '' !== $line ) {
			$lines[] = $line;
		}

		return count( $lines ) > $max_lines ? array() : $lines;
	}

	/**
	 * Draw one run, with its outline underneath.
	 *
	 * @param GdImage  $canvas   Canvas.
	 * @param TextSpec $spec     Spec.
	 * @param string   $font     Font path.
	 * @param float    $size     Font size.
	 * @param float    $rotation Degrees, counter-clockwise.
	 * @param int      $x        Baseline origin x.
	 * @param int      $y        Baseline origin y.
	 * @param string   $text     Text to draw.
	 * @param int      $dpi      Resolution.
	 */
	private function draw( GdImage $canvas, TextSpec $spec, string $font, float $size, float $rotation, int $x, int $y, string $text, int $dpi ): void {
		if ( $spec->has_outline() ) {
			list( $r, $g, $b ) = TextSpec::rgb( $spec->outline );
			$outline_colour    = imagecolorallocate( $canvas, $r, $g, $b );

			// Thickness scales with resolution, so a 0.6 mm outline is 0.6 mm
			// on the print file and on the preview alike.
			$thickness = max( 1, (int) round( Mm::to_px( $spec->outline_mm, $dpi ) / 2 ) );

			if ( false !== $outline_colour ) {
				foreach ( self::OUTLINE_OFFSETS as $offset ) {
					imagettftext(
						$canvas,
						$size,
						$rotation,
						$x + ( $offset[0] * $thickness ),
						$y + ( $offset[1] * $thickness ),
						$outline_colour,
						$font,
						$text
					);
				}
			}
		}

		list( $r, $g, $b ) = TextSpec::rgb( $spec->colour );
		$fill              = imagecolorallocate( $canvas, $r, $g, $b );

		if ( false !== $fill ) {
			imagettftext( $canvas, $size, $rotation, $x, $y, $fill, $font, $text );
		}
	}

	/**
	 * Bounding box of a run.
	 *
	 * @param float  $size Font size.
	 * @param string $font Font path.
	 * @param string $text Text.
	 * @return array{width:int, height:int, left:int}
	 */
	private function measure( float $size, string $font, string $text ): array {
		$box = imagettfbbox( $size, 0, $font, $text );

		if ( false === $box ) {
			return array(
				'width'  => 0,
				'height' => 0,
				'left'   => 0,
			);
		}

		$xs = array( $box[0], $box[2], $box[4], $box[6] );
		$ys = array( $box[1], $box[3], $box[5], $box[7] );

		return array(
			'width'  => (int) ( max( $xs ) - min( $xs ) ),
			'height' => (int) ( max( $ys ) - min( $ys ) ),
			'left'   => (int) min( $xs ),
		);
	}

	/**
	 * How far the pen moves after drawing one character.
	 *
	 * @param float  $size      Font size.
	 * @param string $font      Font path.
	 * @param string $character One character.
	 */
	private function advance( float $size, string $font, string $character ): float {
		// A space measures as zero width in a bbox, so fall back to the width
		// of a digit, which is close enough and never zero.
		if ( ' ' === $character ) {
			return $this->measure( $size, $font, '0' )['width'] * 0.5;
		}

		$width = $this->measure( $size, $font, $character )['width'];

		return $width > 0 ? (float) $width : $size * 0.5;
	}

	/**
	 * Split UTF-8 into characters.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private function characters( string $text ): array {
		$split = preg_split( '//u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );

		return false === $split ? array() : $split;
	}

	/**
	 * Millimetres of cap height to the size number GD wants.
	 *
	 * GD treats the size argument as points at 72 per inch regardless of the
	 * image's intended resolution, so the conversion has to be explicit or
	 * text comes out the same pixel size on a preview and a print file.
	 *
	 * @param float $mm  Desired height.
	 * @param int   $dpi Resolution.
	 */
	private function mm_to_points( float $mm, int $dpi ): float {
		return max( 6.0, ( $mm / Mm::MM_PER_INCH ) * $dpi * 0.75 );
	}
}
