<?php
/**
 * The gate on customer-supplied bitmaps.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Imaging;

use AiCake\Support\Logger;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * Proves an uploaded text layer is text, not artwork.
 *
 * D-033 moved text rendering into the browser, which means the server now
 * accepts a bitmap the customer composed. That is a hole straight through
 * §10: moderation layers 0–2 read the *prompt*, and a bitmap carries none of
 * it. Without this class the endpoint accepts a photograph, a franchise
 * character or a competitor's logo and nothing downstream can tell.
 *
 * The rule is D-033's: **every non-transparent pixel must be close to a colour
 * the customer declared.** Text drawn in three colours occupies three small
 * neighbourhoods of RGB space. A photograph occupies all of it.
 *
 * ### What passes, and why the rule is not literally "near a declared colour"
 *
 * Antialiasing is free: a glyph edge over a transparent background keeps the
 * fill colour and varies only alpha, so those pixels sit exactly on the
 * declared colour. But a *stroke* is one colour composited over another, and
 * its boundary pixels are genuine RGB blends of the two. So the test is
 * distance to the nearest declared colour **or to the segment between any two
 * of them** — the set of colours a compositor can actually produce from the
 * declared palette.
 *
 * ### What that concedes, and what closes it
 *
 * Allowing segments means declaring black and white admits the whole grey
 * ramp, so a greyscale image would satisfy the colour rule. That is the one
 * real hole, and `MAX_COVERAGE` is what closes it: text is sparse and a
 * picture is dense. The two checks are independent, which is the point —
 * defeating one does not defeat the other.
 *
 * The palette cap matters for the same reason. Enough declared colours and the
 * segments between them mesh the cube.
 */
class LayerInspector {

	/**
	 * How many colours a customer may declare.
	 *
	 * Three is a fill, a stroke and a shadow — the whole vocabulary of the
	 * editor. The fourth is slack. Beyond that the segments between them start
	 * to cover enough of RGB space that the colour test stops meaning anything.
	 */
	public const MAX_COLOURS = 4;

	/**
	 * Squared Euclidean radius in RGB around a declared colour, or around the
	 * segment between two.
	 *
	 * 24 per channel. Wide enough to absorb the browser's compositing rounding
	 * and any colour-managed nudge between canvas and PNG; far too narrow to
	 * let a second, undeclared subject through.
	 */
	public const TOLERANCE_SQ = 576;

	/**
	 * The most of the layer text may cover.
	 *
	 * Real text on a sheet runs a few percent. This is not a typographic limit,
	 * it is the density half of the check — see the class docblock.
	 */
	public const MAX_COVERAGE = 0.35;

	/**
	 * Fully transparent, in GD's 0-opaque..127-transparent alpha.
	 */
	private const ALPHA_CLEAR = 127;

	private Logger $logger;

	/**
	 * @param Logger $logger Logging.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Test a layer against the colours its author declared.
	 *
	 * @param GdImage  $layer    The uploaded PNG-32, alpha preserved.
	 * @param string[] $declared Declared colours as #rrggbb.
	 * @return array{ok: bool, reason: string, detail: array<string, mixed>}
	 */
	public function inspect( GdImage $layer, array $declared ): array {
		$palette = $this->palette( $declared );

		if ( array() === $palette ) {
			return $this->fail( 'no_colours', array() );
		}

		if ( count( $palette ) > self::MAX_COLOURS ) {
			return $this->fail( 'too_many_colours', array( 'declared' => count( $palette ) ) );
		}

		$segments = $this->segments( $palette );

		$width  = imagesx( $layer );
		$height = imagesy( $layer );
		$total  = $width * $height;

		if ( $total < 1 ) {
			return $this->fail( 'empty', array() );
		}

		/*
		 * Alpha blending off so imagecolorat returns the stored alpha rather
		 * than a composite. With it on, GD reports what the pixel would look
		 * like drawn onto something — which is not what was uploaded.
		 */
		imagealphablending( $layer, false );

		$ink = 0;

		for ( $y = 0; $y < $height; $y++ ) {
			for ( $x = 0; $x < $width; $x++ ) {
				$packed = imagecolorat( $layer, $x, $y );

				/*
				 * The cheap test first, and it is the one that runs on almost
				 * every pixel. A text layer is overwhelmingly empty, so the
				 * whole cost of this scan is this shift and compare.
				 */
				if ( self::ALPHA_CLEAR === ( $packed >> 24 & 0x7F ) ) {
					continue;
				}

				++$ink;

				if ( ! $this->is_allowed( $packed & 0xFFFFFF, $palette, $segments ) ) {
					/*
					 * Stop at the first offender. A rejected layer needs one
					 * counter-example, not a census, and this is the path a
					 * pasted photograph takes — it should be fast.
					 */
					return $this->fail(
						'off_palette',
						array(
							'x'      => $x,
							'y'      => $y,
							'colour' => sprintf( '#%06x', $packed & 0xFFFFFF ),
						)
					);
				}
			}
		}

		if ( 0 === $ink ) {
			return $this->fail( 'empty', array() );
		}

		$coverage = $ink / $total;

		if ( $coverage > self::MAX_COVERAGE ) {
			return $this->fail(
				'too_dense',
				array(
					'coverage' => round( $coverage, 4 ),
					'ceiling'  => self::MAX_COVERAGE,
				)
			);
		}

		return array(
			'ok'     => true,
			'reason' => '',
			'detail' => array(
				'ink_px'   => $ink,
				'coverage' => round( $coverage, 4 ),
				'colours'  => count( $palette ),
			),
		);
	}

	/**
	 * Whether one colour is reachable from the declared palette.
	 *
	 * @param int                             $rgb      Packed 0xRRGGBB.
	 * @param array<int, array{0:int,1:int,2:int}> $palette  Declared colours.
	 * @param array<int, array{0:array{0:int,1:int,2:int}, 1:array{0:int,1:int,2:int}}> $segments Pairs.
	 */
	private function is_allowed( int $rgb, array $palette, array $segments ): bool {
		$r = $rgb >> 16 & 0xFF;
		$g = $rgb >> 8 & 0xFF;
		$b = $rgb & 0xFF;

		foreach ( $palette as $colour ) {
			$dr = $r - $colour[0];
			$dg = $g - $colour[1];
			$db = $b - $colour[2];

			if ( ( $dr * $dr ) + ( $dg * $dg ) + ( $db * $db ) <= self::TOLERANCE_SQ ) {
				return true;
			}
		}

		foreach ( $segments as $segment ) {
			if ( $this->near_segment( $r, $g, $b, $segment[0], $segment[1] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Distance from a colour to the line segment between two others.
	 *
	 * Where a stroke meets its fill the compositor writes points along exactly
	 * this segment, so these are colours the declared palette really can make.
	 *
	 * @param int                   $r    Red.
	 * @param int                   $g    Green.
	 * @param int                   $b    Blue.
	 * @param array{0:int,1:int,2:int} $from One end.
	 * @param array{0:int,1:int,2:int} $to   The other.
	 */
	private function near_segment( int $r, int $g, int $b, array $from, array $to ): bool {
		$vr = $to[0] - $from[0];
		$vg = $to[1] - $from[1];
		$vb = $to[2] - $from[2];

		$len_sq = ( $vr * $vr ) + ( $vg * $vg ) + ( $vb * $vb );

		if ( 0 === $len_sq ) {
			return false;
		}

		$t = ( ( ( $r - $from[0] ) * $vr ) + ( ( $g - $from[1] ) * $vg ) + ( ( $b - $from[2] ) * $vb ) ) / $len_sq;

		// Clamp to the segment: past either end is the endpoint's own job.
		$t = max( 0.0, min( 1.0, $t ) );

		$dr = $r - ( $from[0] + ( $t * $vr ) );
		$dg = $g - ( $from[1] + ( $t * $vg ) );
		$db = $b - ( $from[2] + ( $t * $vb ) );

		return ( ( $dr * $dr ) + ( $dg * $dg ) + ( $db * $db ) ) <= self::TOLERANCE_SQ;
	}

	/**
	 * Declared colours, parsed and deduplicated.
	 *
	 * @param string[] $declared Candidates as #rrggbb.
	 * @return array<int, array{0:int,1:int,2:int}>
	 */
	private function palette( array $declared ): array {
		$seen = array();

		foreach ( $declared as $colour ) {
			$colour = strtolower( trim( (string) $colour ) );

			if ( 1 !== preg_match( '/^#[0-9a-f]{6}$/', $colour ) ) {
				continue;
			}

			$seen[ $colour ] = array(
				(int) hexdec( substr( $colour, 1, 2 ) ),
				(int) hexdec( substr( $colour, 3, 2 ) ),
				(int) hexdec( substr( $colour, 5, 2 ) ),
			);
		}

		return array_values( $seen );
	}

	/**
	 * Every unordered pair of declared colours.
	 *
	 * @param array<int, array{0:int,1:int,2:int}> $palette Declared colours.
	 * @return array<int, array{0:array{0:int,1:int,2:int}, 1:array{0:int,1:int,2:int}}>
	 */
	private function segments( array $palette ): array {
		$pairs = array();
		$count = count( $palette );

		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$pairs[] = array( $palette[ $i ], $palette[ $j ] );
			}
		}

		return $pairs;
	}

	/**
	 * A rejection, logged.
	 *
	 * @param string               $reason Machine-readable cause.
	 * @param array<string, mixed> $detail Context.
	 * @return array{ok: bool, reason: string, detail: array<string, mixed>}
	 */
	private function fail( string $reason, array $detail ): array {
		$this->logger->warning(
			'Text layer refused.',
			array_merge( array( 'reason' => $reason ), $detail )
		);

		return array(
			'ok'     => false,
			'reason' => $reason,
			'detail' => $detail,
		);
	}
}
