<?php
/**
 * A rendered print file and what it took to make it.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

use AiCake\Support\Mm;

defined( 'ABSPATH' ) || exit;

/**
 * The result of the fulfilment pipeline.
 *
 * The bytes alone would do for writing the file, but the rest is what the
 * `.json` sidecar (§12.3) records and what the admin order screen shows. A
 * reprint two years from now is only reproducible if we wrote down what the
 * first print actually was, not what the product configuration says today.
 */
final class PrintFile {

	/**
	 * @param string $bytes          PNG data, with the DPI already declared.
	 * @param int    $width_px       Final width.
	 * @param int    $height_px      Final height.
	 * @param int    $dpi            Resolution declared in the file.
	 * @param int    $copies         Pieces on the sheet, 1 for a single topper.
	 * @param int    $upscale_factor 1 when the master was already big enough.
	 * @param string $upscaler       Which upscaler ran, '' when none did.
	 */
	public function __construct(
		public string $bytes,
		public int $width_px,
		public int $height_px,
		public int $dpi,
		public int $copies = 1,
		public int $upscale_factor = 1,
		public string $upscaler = ''
	) {}

	/**
	 * Physical size, for the admin screen and the log.
	 */
	public function describe(): string {
		return Mm::describe( $this->width_px, $this->height_px, $this->dpi );
	}

	/**
	 * The sidecar's view of this file.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'width_px'       => $this->width_px,
			'height_px'      => $this->height_px,
			'dpi'            => $this->dpi,
			'copies'         => $this->copies,
			'upscale_factor' => $this->upscale_factor,
			'upscaler'       => '' === $this->upscaler ? null : $this->upscaler,
			'physical'       => $this->describe(),
			'bytes'          => strlen( $this->bytes ),
		);
	}
}
