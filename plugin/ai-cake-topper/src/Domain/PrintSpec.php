<?php
/**
 * What a given product is physically asking for.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

use AiCake\Imaging\SheetLayout;
use AiCake\Support\Mm;

defined( 'ABSPATH' ) || exit;

/**
 * The print geometry for one design (PLAN.md §4.2).
 *
 * **Geometry comes from the design, not the product** (D-035). There is one AI
 * product; the customer picks a format in the wizard at step 1, before anything
 * is generated, and that choice is recorded on the design row. So the aspect
 * ratio is still fixed before the customer types anything — which is what made
 * the old "one product per size" model worth having — without ten products to
 * keep in sync.
 *
 * Resolution order is **design → variation meta → product meta → default**
 * (§4.2). The three later sources cost nothing and are the escape hatch for a
 * one-off product that needs geometry of its own.
 *
 * Nothing about a 24-up cupcake sheet is special-cased. It is
 * `shape=round, width_mm=45, copies=24`, and the imposition falls out of the
 * geometry (§3.5).
 */
class PrintSpec {

	public const SHAPE_ROUND = 'round';
	public const SHAPE_RECT  = 'rect';

	public const META_PREFIX = '_aicake_';

	/**
	 * @param bool   $enabled     Whether this product takes an AI design.
	 * @param string $shape       round | rect.
	 * @param float  $width_mm    Diameter if round, width if rect.
	 * @param float  $height_mm   Ignored if round.
	 * @param float  $bleed_mm    Bleed per edge.
	 * @param float  $safe_mm     Safe margin inside the trim line.
	 * @param int    $copies      1 for a single topper, more for an N-up sheet.
	 * @param string $sheet       a4 | custom.
	 * @param float  $sheet_w_mm  Usable sheet width.
	 * @param float  $sheet_h_mm  Usable sheet height.
	 * @param int    $dpi         Print resolution.
	 * @param string $style_preset Which house style suffix to use.
	 */
	public function __construct(
		public bool $enabled = false,
		public string $shape = self::SHAPE_ROUND,
		public float $width_mm = 150.0,
		public float $height_mm = 0.0,
		public float $bleed_mm = Mm::BLEED_MM,
		public float $safe_mm = Mm::SAFE_MM,
		public int $copies = 1,
		public string $sheet = 'a4',
		public float $sheet_w_mm = SheetLayout::USABLE_WIDTH_MM,
		public float $sheet_h_mm = SheetLayout::USABLE_HEIGHT_MM,
		public int $dpi = Mm::PRINT_DPI,
		public string $style_preset = 'default'
	) {}

	/**
	 * The spec for a design, falling back to its product.
	 *
	 * This is the resolution order §4.2 specifies, and the only entry point
	 * the pipeline should use: a design that recorded a format is authoritative
	 * about its own geometry, because that is what the customer chose and saw
	 * proofed. Product meta answers only when the design did not.
	 *
	 * An unrecognised format falls through rather than throwing. The catalogue
	 * can legitimately shrink — a size withdrawn from sale — and an old design
	 * must still be reprintable from the product's own geometry (§12.6).
	 *
	 * @param array<string, mixed> $design Design row.
	 */
	public static function for_design( array $design ): self {
		$type = (string) ( $design['format_type'] ?? '' );

		if ( '' !== $type ) {
			$spec = FormatCatalogue::spec( $type, (float) ( $design['format_mm'] ?? 0.0 ) );

			if ( $spec instanceof self ) {
				return $spec;
			}
		}

		return self::for_product(
			(int) ( $design['product_id'] ?? 0 ),
			(int) ( $design['variation_id'] ?? 0 )
		);
	}

	/**
	 * Read the spec for a product, honouring a variation override.
	 *
	 * Resolution order is variation meta → product meta → default (§4.2). The
	 * variation layer exists so that if a sheet type ever does need a different
	 * bleed or DPI, it works without restructuring — not because any sheet type
	 * currently does.
	 *
	 * @param int $product_id   Product id.
	 * @param int $variation_id Variation id, or 0.
	 */
	public static function for_product( int $product_id, int $variation_id = 0 ): self {
		$spec = new self();

		$read = static function ( string $key, $default ) use ( $product_id, $variation_id ) {
			if ( $variation_id > 0 ) {
				$value = get_post_meta( $variation_id, self::META_PREFIX . $key, true );

				if ( '' !== $value && null !== $value ) {
					return $value;
				}
			}

			$value = get_post_meta( $product_id, self::META_PREFIX . $key, true );

			return ( '' === $value || null === $value ) ? $default : $value;
		};

		$spec->enabled      = (bool) $read( 'enabled', false );
		$spec->shape        = self::SHAPE_RECT === $read( 'shape', self::SHAPE_ROUND ) ? self::SHAPE_RECT : self::SHAPE_ROUND;
		$spec->width_mm     = max( 10.0, (float) $read( 'width_mm', 150.0 ) );
		$spec->height_mm    = max( 0.0, (float) $read( 'height_mm', 0.0 ) );
		$spec->bleed_mm     = max( 0.0, (float) $read( 'bleed_mm', Mm::BLEED_MM ) );
		$spec->safe_mm      = max( 0.0, (float) $read( 'safe_mm', Mm::SAFE_MM ) );
		$spec->copies       = max( 1, (int) $read( 'copies', 1 ) );
		$spec->sheet        = (string) $read( 'sheet', 'a4' );
		$spec->sheet_w_mm   = max( 10.0, (float) $read( 'sheet_w_mm', SheetLayout::USABLE_WIDTH_MM ) );
		$spec->sheet_h_mm   = max( 10.0, (float) $read( 'sheet_h_mm', SheetLayout::USABLE_HEIGHT_MM ) );
		$spec->dpi          = max( 72, (int) $read( 'dpi', Mm::PRINT_DPI ) );
		$spec->style_preset = (string) $read( 'style_preset', 'default' );

		if ( self::SHAPE_RECT === $spec->shape && $spec->height_mm <= 0 ) {
			// A rectangle with no height is a misconfiguration; A4 is the only
			// sensible guess and it is what the shop sells.
			$spec->height_mm = 297.0;
		}

		return $spec;
	}

	/**
	 * Whether this is a circle.
	 */
	public function is_round(): bool {
		return self::SHAPE_ROUND === $this->shape;
	}

	/**
	 * Whether this product prints many copies on one sheet.
	 */
	public function is_sheet(): bool {
		return $this->copies > 1;
	}

	/**
	 * The finished size of one piece, in millimetres.
	 *
	 * @return array{0:float, 1:float}
	 */
	public function trim_mm(): array {
		return $this->is_round()
			? array( $this->width_mm, $this->width_mm )
			: array( $this->width_mm, $this->height_mm );
	}

	/**
	 * The size of one piece including bleed, in pixels.
	 *
	 * @return array{0:int, 1:int}
	 */
	public function target_px(): array {
		list( $w, $h ) = $this->trim_mm();

		return array(
			Mm::bled_px( $w, $this->dpi, $this->bleed_mm ),
			Mm::bled_px( $h, $this->dpi, $this->bleed_mm ),
		);
	}

	/**
	 * The aspect ratio to ask the image provider for.
	 *
	 * Round and square both take 1:1. A4 is 1:1.414 and no model offers it, so
	 * 2:3 is generated and centre-cropped (§3.2).
	 */
	public function generation_aspect(): string {
		if ( $this->is_round() ) {
			return '1:1';
		}

		list( $w, $h ) = $this->trim_mm();

		if ( $h <= 0 || $w <= 0 ) {
			return '1:1';
		}

		$ratio = $w / $h;

		if ( $ratio > 1.15 ) {
			return '3:2';
		}

		if ( $ratio < 0.87 ) {
			return '2:3';
		}

		return '1:1';
	}

	/**
	 * Whether a native generation is big enough, or needs upscaling first.
	 *
	 * §3.1: this is the check that removes a paid API call from the majority
	 * of orders. A 4.5 cm cupcake circle needs 603 px and a native 1024 px
	 * generation already exceeds it by 70%.
	 *
	 * @param int $native_px Longest edge the generator produces.
	 */
	public function upscale_factor( int $native_px = 1024 ): int {
		list( $w, $h ) = $this->target_px();

		return Mm::upscale_factor( $native_px, max( $w, $h ) );
	}

	/**
	 * The imposition plan, for a sheet product.
	 *
	 * @return array<string, mixed>
	 */
	public function sheet_plan(): array {
		return SheetLayout::plan( $this->width_mm, $this->sheet_w_mm, $this->sheet_h_mm, $this->bleed_mm, $this->dpi );
	}

	/**
	 * How many pieces actually fit, which may differ from what was configured.
	 */
	public function computed_copies(): int {
		if ( ! $this->is_sheet() || ! $this->is_round() ) {
			return $this->copies;
		}

		return (int) $this->sheet_plan()['per_sheet'];
	}

	/**
	 * The one-line summary the product screen shows as the admin types.
	 *
	 * §4.2: "That single line prevents most of the ways this can be
	 * misconfigured." It is also where a mismatch between the configured count
	 * and the geometry becomes visible, rather than surfacing as a sheet with
	 * a row missing.
	 */
	public function summary(): string {
		list( $w_px, $h_px ) = $this->target_px();

		$parts = array();

		if ( $this->is_round() ) {
			$parts[] = sprintf(
				/* translators: 1: diameter in mm, 2: bleed in mm, 3: pixels, 4: dpi */
				__( '⌀%1$s mm + %2$s mm bleed → %3$s px @%4$d DPI', 'ai-cake-topper' ),
				$this->format( $this->width_mm ),
				$this->format( $this->bleed_mm ),
				number_format_i18n( $w_px ),
				$this->dpi
			);
		} else {
			$parts[] = sprintf(
				/* translators: 1: width, 2: height, 3: bleed, 4: width px, 5: height px, 6: dpi */
				__( '%1$s × %2$s mm + %3$s mm bleed → %4$s × %5$s px @%6$d DPI', 'ai-cake-topper' ),
				$this->format( $this->width_mm ),
				$this->format( $this->height_mm ),
				$this->format( $this->bleed_mm ),
				number_format_i18n( $w_px ),
				number_format_i18n( $h_px ),
				$this->dpi
			);
		}

		if ( $this->is_sheet() && $this->is_round() ) {
			$plan = $this->sheet_plan();

			$parts[] = sprintf(
				/* translators: 1: columns, 2: rows, 3: total per sheet */
				__( '%1$d × %2$d = %3$d per sheet', 'ai-cake-topper' ),
				(int) $plan['cols'],
				(int) $plan['rows'],
				(int) $plan['per_sheet']
			);

			if ( (int) $plan['per_sheet'] !== $this->copies ) {
				$parts[] = sprintf(
					/* translators: %d: configured quantity */
					__( '⚠ product says %d — the geometry disagrees', 'ai-cake-topper' ),
					$this->copies
				);
			}
		}

		$factor = $this->upscale_factor();

		$parts[] = 1 === $factor
			? __( 'no upscale needed', 'ai-cake-topper' )
			: sprintf(
				/* translators: %d: upscale factor */
				__( '%d× upscale needed', 'ai-cake-topper' ),
				$factor
			);

		return implode( ' · ', $parts );
	}

	/**
	 * Trim a trailing .0 from a millimetre figure.
	 *
	 * @param float $value Millimetres.
	 */
	private function format( float $value ): string {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
	}

	/**
	 * The subset the frontend needs, for the generator's data attributes.
	 *
	 * @return array<string, mixed>
	 */
	public function to_frontend(): array {
		list( $w_px, $h_px ) = $this->target_px();

		return array(
			'shape'   => $this->shape,
			'aspect'  => $this->generation_aspect(),
			'round'   => $this->is_round(),
			'copies'  => $this->computed_copies(),
			'width'   => $w_px,
			'height'  => $h_px,
			'safe_pc' => $this->safe_percentage(),
		);
	}

	/**
	 * The dimensions of the finished print file.
	 *
	 * The same branch `FulfilPipeline` takes — an imposed sheet for a multi-up
	 * round format, a single bled piece otherwise. It is pulled out here because
	 * D-033's text layer is *exactly* the size of the print file, so the browser
	 * needs this number and the two must never be derived separately. A layer
	 * that disagrees with the canvas composites at the wrong scale and every
	 * name lands off its piece.
	 *
	 * D-033 also decides the print canvas eventually becomes A4 for everything.
	 * When that lands, this method changes and the editor follows it for free.
	 *
	 * @return array{0:int, 1:int}
	 */
	public function canvas_px(): array {
		if ( $this->is_sheet() && $this->is_round() ) {
			$plan = $this->sheet_plan();

			return array( (int) $plan['sheet_w_px'], (int) $plan['sheet_h_px'] );
		}

		return $this->target_px();
	}

	/**
	 * Where every piece sits on the canvas, for the browser text editor.
	 *
	 * D-033: "The client must never compute piece positions itself. SheetLayout
	 * derives them server-side and the editor consumes them, or text lands
	 * across a gutter — and it would look right in the editor and wrong on the
	 * print."
	 *
	 * Coordinates are print-file pixels. The editor scales them to whatever it
	 * displays at; it does not re-derive them.
	 *
	 * `safe_w` / `safe_h` are the box text may occupy — trim less the safe
	 * margin, and less the bleed, because the bleed is cut away entirely. The
	 * editor enforces it as a constraint rather than drawing it as a guide
	 * (D-033), since the customer cuts by hand and a name 2 mm inside the trim
	 * gets clipped.
	 *
	 * @return array<string, mixed>
	 */
	public function editor_layout(): array {
		list( $canvas_w, $canvas_h ) = $this->canvas_px();
		list( $trim_w_mm, $trim_h_mm ) = $this->trim_mm();

		$trim_w = Mm::to_px( $trim_w_mm, $this->dpi );
		$trim_h = Mm::to_px( $trim_h_mm, $this->dpi );
		$safe   = Mm::to_px( $this->safe_mm, $this->dpi );

		if ( $this->is_sheet() && $this->is_round() ) {
			$plan    = $this->sheet_plan();
			$centres = $plan['centres_px'];
		} else {
			// A single piece fills its own canvas, so its centre is the middle
			// of it — bleed included, which is why this is not trim/2.
			$centres = array(
				array(
					'x' => (int) round( $canvas_w / 2 ),
					'y' => (int) round( $canvas_h / 2 ),
				),
			);
		}

		$pieces = array();

		foreach ( $centres as $centre ) {
			$pieces[] = array(
				'cx'     => (int) $centre['x'],
				'cy'     => (int) $centre['y'],
				'w'      => $trim_w,
				'h'      => $trim_h,
				'safe_w' => max( 0, $trim_w - ( 2 * $safe ) ),
				'safe_h' => max( 0, $trim_h - ( 2 * $safe ) ),
			);
		}

		return array(
			'canvas' => array(
				'w' => $canvas_w,
				'h' => $canvas_h,
			),
			'dpi'    => $this->dpi,
			'round'  => $this->is_round(),
			'pieces' => $pieces,
		);
	}

	/**
	 * The safe zone as a percentage inset, for drawing the guide in CSS.
	 */
	public function safe_percentage(): float {
		list( $w, $h ) = $this->trim_mm();
		$shortest      = min( $w, $h );

		if ( $shortest <= 0 ) {
			return 0.0;
		}

		return round( ( ( $this->bleed_mm + $this->safe_mm ) / ( $shortest + ( 2 * $this->bleed_mm ) ) ) * 100, 2 );
	}
}
