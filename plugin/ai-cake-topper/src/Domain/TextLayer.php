<?php
/**
 * The text the customer composed, as a bitmap and as a string.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * A composed text layer.
 *
 * D-033 replaced `TextSpec` — a font, a colour and one of five placements —
 * with a bitmap the browser draws. This is what the server keeps of it.
 *
 * ### Why the plain string is stored alongside the image
 *
 * It is never rendered. It exists for two reasons, both from D-033:
 *
 * 1. **Moderation can still read it.** Layers 0 and 1 match words. A bitmap
 *    hides every word in it, so without the string a customer types anything
 *    they like as long as they type it into a canvas.
 * 2. **The order record stays readable.** A shop manager checking what was
 *    ordered should not have to open an image to find out what it says.
 *
 * The string is what the customer *typed*. The bitmap is what will be
 * *printed*. They can disagree — nothing forces the editor to be honest — which
 * is exactly why `LayerInspector` inspects the pixels rather than trusting
 * this.
 *
 * `TextSpec` is gone (D-045). This is now the only text the server keeps.
 */
class TextLayer {

	/**
	 * @param string   $text      What the customer typed, UTF-8 Lithuanian.
	 * @param string[] $colours   The colours they declared, #rrggbb.
	 * @param string   $path      Absolute path to the stored PNG-32.
	 * @param int      $width_px  Layer width — the whole print canvas.
	 * @param int      $height_px Layer height.
	 */
	public function __construct(
		public string $text = '',
		public array $colours = array(),
		public string $path = '',
		public int $width_px = 0,
		public int $height_px = 0
	) {}

	/**
	 * Whether there is a stored bitmap to composite.
	 */
	public function has_bitmap(): bool {
		return '' !== $this->path && is_readable( $this->path );
	}

	/**
	 * Build from the stored JSON.
	 *
	 * @param array<string, mixed> $data Decoded payload.
	 */
	public static function from_array( array $data ): self {
		$colours = array();

		foreach ( (array) ( $data['colours'] ?? array() ) as $colour ) {
			$colour = strtolower( trim( (string) $colour ) );

			if ( 1 === preg_match( '/^#[0-9a-f]{6}$/', $colour ) ) {
				$colours[ $colour ] = $colour;
			}
		}

		return new self(
			(string) ( $data['text'] ?? '' ),
			array_values( $colours ),
			(string) ( $data['path'] ?? '' ),
			max( 0, (int) ( $data['width_px'] ?? 0 ) ),
			max( 0, (int) ( $data['height_px'] ?? 0 ) )
		);
	}

	/**
	 * Read one out of a design row.
	 *
	 * **Rows written before D-045 hold the old `TextSpec` shape**, which has no
	 * `path`. Those read back as a layer with no bitmap rather than as an error:
	 * nothing is composited, so the print is the artwork alone, while the `text`
	 * they do carry still shows the shop manager what was ordered. Refusing them
	 * outright would break reprinting an old order (§12.6) to no purpose.
	 *
	 * @param array<string, mixed> $design Design row.
	 */
	public static function from_design( array $design ): ?self {
		$raw = (string) ( $design['text_payload'] ?? '' );

		if ( '' === $raw ) {
			return null;
		}

		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return null;
		}

		return self::from_array( $data );
	}

	/**
	 * For the designs.text_payload column.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'text'      => $this->text,
			'colours'   => $this->colours,
			'path'      => $this->path,
			'width_px'  => $this->width_px,
			'height_px' => $this->height_px,
		);
	}
}
