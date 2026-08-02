<?php
/**
 * What the customer wants written on the topper.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A text layer, resolution-independent.
 *
 * Sizes are in millimetres, not pixels, because the same spec is rendered
 * twice — once at preview resolution and once at print resolution — and it is
 * never scaled between them. Upscaling rendered text is what makes cheap print
 * shops look cheap (PLAN.md §9.4).
 */
class TextSpec {

	public const PLACE_TOP    = 'top';
	public const PLACE_CENTRE = 'centre';
	public const PLACE_BOTTOM = 'bottom';
	public const PLACE_ARC_TOP    = 'arc_top';
	public const PLACE_ARC_BOTTOM = 'arc_bottom';

	/**
	 * @param string $text          What to write, UTF-8 Lithuanian.
	 * @param string $font          Font handle from the catalogue.
	 * @param string $colour        Fill colour, #rrggbb.
	 * @param string $outline       Outline colour, #rrggbb, or '' for none.
	 * @param float  $size_mm       Cap height in millimetres. 0 means auto-fit.
	 * @param string $placement     One of the PLACE_* constants.
	 * @param int    $max_lines     How many lines the text may wrap onto.
	 * @param float  $outline_mm    Outline thickness in millimetres.
	 */
	public function __construct(
		public string $text = '',
		public string $font = '',
		public string $colour = '#ffffff',
		public string $outline = '#000000',
		public float $size_mm = 0.0,
		public string $placement = self::PLACE_BOTTOM,
		public int $max_lines = 2,
		public float $outline_mm = 0.6
	) {}

	/**
	 * Whether there is anything to draw.
	 */
	public function is_empty(): bool {
		return '' === trim( $this->text );
	}

	/**
	 * Whether this text follows the circle edge.
	 */
	public function is_arc(): bool {
		return in_array( $this->placement, array( self::PLACE_ARC_TOP, self::PLACE_ARC_BOTTOM ), true );
	}

	/**
	 * Whether an outline is wanted.
	 *
	 * Almost always yes. Generated backgrounds are busy and coloured, and
	 * unoutlined text over them is unreadable at a glance (§9.4).
	 */
	public function has_outline(): bool {
		return '' !== $this->outline && $this->outline_mm > 0;
	}

	/**
	 * Build from stored JSON.
	 *
	 * @param array<string, mixed> $data Decoded payload.
	 */
	public static function from_array( array $data ): self {
		$placements = array(
			self::PLACE_TOP,
			self::PLACE_CENTRE,
			self::PLACE_BOTTOM,
			self::PLACE_ARC_TOP,
			self::PLACE_ARC_BOTTOM,
		);

		$placement = (string) ( $data['placement'] ?? self::PLACE_BOTTOM );

		return new self(
			(string) ( $data['text'] ?? '' ),
			(string) ( $data['font'] ?? '' ),
			self::sanitise_colour( (string) ( $data['colour'] ?? '#ffffff' ), '#ffffff' ),
			self::sanitise_colour( (string) ( $data['outline'] ?? '#000000' ), '' ),
			max( 0.0, (float) ( $data['size_mm'] ?? 0 ) ),
			in_array( $placement, $placements, true ) ? $placement : self::PLACE_BOTTOM,
			max( 1, min( 3, (int) ( $data['max_lines'] ?? 2 ) ) ),
			max( 0.0, (float) ( $data['outline_mm'] ?? 0.6 ) )
		);
	}

	/**
	 * For the designs.text_payload column.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'text'       => $this->text,
			'font'       => $this->font,
			'colour'     => $this->colour,
			'outline'    => $this->outline,
			'size_mm'    => $this->size_mm,
			'placement'  => $this->placement,
			'max_lines'  => $this->max_lines,
			'outline_mm' => $this->outline_mm,
		);
	}

	/**
	 * Force a value into #rrggbb, or a fallback.
	 *
	 * @param string $colour   Candidate.
	 * @param string $fallback Used when the candidate is not a colour.
	 */
	private static function sanitise_colour( string $colour, string $fallback ): string {
		$colour = trim( $colour );

		if ( 1 === preg_match( '/^#[0-9a-fA-F]{6}$/', $colour ) ) {
			return strtolower( $colour );
		}

		// Accept the three-digit form and expand it.
		if ( 1 === preg_match( '/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $colour, $m ) ) {
			return strtolower( '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3] );
		}

		return $fallback;
	}

	/**
	 * The colour as red, green, blue components.
	 *
	 * @param string $hex A #rrggbb colour.
	 * @return array{0:int, 1:int, 2:int}
	 */
	public static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );

		if ( 6 !== strlen( $hex ) ) {
			return array( 0, 0, 0 );
		}

		return array(
			(int) hexdec( substr( $hex, 0, 2 ) ),
			(int) hexdec( substr( $hex, 2, 2 ) ),
			(int) hexdec( substr( $hex, 4, 2 ) ),
		);
	}
}
