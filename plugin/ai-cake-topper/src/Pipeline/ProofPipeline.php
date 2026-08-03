<?php
/**
 * The image the customer recognises after they leave the wizard.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Pipeline;

use AiCake\Domain\PrintSpec;
use AiCake\Domain\TextLayer;
use AiCake\Imaging\GdEngine;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Logger;
use GdImage;

defined( 'ABSPATH' ) || exit;

/**
 * Artwork laid out per piece, with the customer's text over it, watermarked.
 *
 * ### Why this exists when the browser already draws it
 *
 * Step 4's proof is a capture of the editor's canvas (D-044), which is the
 * right answer *there* — the canvas is already on screen. But the cart, the
 * order screen and the confirmation email have no canvas, and until now they
 * showed `file_preview`: the bare artwork. A customer who spent five minutes
 * placing „Emilija" on twelve cupcakes then saw one plain circle in their cart
 * and had no way to tell whether their text had been kept.
 *
 * ### Why this is not the second renderer D-033 deleted
 *
 * It composites the **same bitmap the browser produced** — it does not draw
 * glyphs, choose fonts, or lay text out. Nothing here can disagree with the
 * editor about where a word sits, because nothing here decides that. The only
 * shared knowledge is piece placement, and that comes from `editor_layout()`,
 * which is the single source both sides already read (D-033).
 *
 * It is the same composite `FulfilPipeline` performs for the printer, at
 * screen size and over a watermarked preview instead of a clean master.
 */
class ProofPipeline {

	/**
	 * Long edge, in pixels.
	 *
	 * A cart thumbnail is displayed at ~100 px and an order screen at a few
	 * hundred. 900 covers both including retina, and keeps a 24-up sheet's
	 * individual names legible enough to check — which is the entire point of
	 * showing it.
	 */
	public const PROOF_PX = 900;

	private GdEngine $images;

	private PrivateStorage $storage;

	private Logger $logger;

	/**
	 * @param GdEngine       $images  Pixels.
	 * @param PrivateStorage $storage Files.
	 * @param Logger         $logger  Logging.
	 */
	public function __construct( GdEngine $images, PrivateStorage $storage, Logger $logger ) {
		$this->images  = $images;
		$this->storage = $storage;
		$this->logger  = $logger;
	}

	/**
	 * Build the proof and write it beside the preview.
	 *
	 * @param string    $preview_path Watermarked artwork for one piece.
	 * @param string    $public_id    Design handle.
	 * @param PrintSpec $spec         The design's geometry.
	 * @param TextLayer $layer        The composed layer.
	 * @return string Path to the proof, or '' on any failure.
	 */
	public function build( string $preview_path, string $public_id, PrintSpec $spec, TextLayer $layer ): string {
		if ( ! is_readable( $preview_path ) ) {
			$this->logger->warning( 'Proof skipped: no preview on disk.', array( 'path' => $preview_path ) );

			return '';
		}

		$layout = $spec->editor_layout();
		$scale  = self::PROOF_PX / max( 1, max( (int) $layout['canvas']['w'], (int) $layout['canvas']['h'] ) );

		$width  = max( 1, (int) round( (int) $layout['canvas']['w'] * $scale ) );
		$height = max( 1, (int) round( (int) $layout['canvas']['h'] * $scale ) );

		$canvas = $this->images->blank( $width, $height, true );

		if ( null === $canvas ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$artwork = $this->images->from_string( (string) file_get_contents( $preview_path ) );

		if ( null === $artwork ) {
			$this->images->free( $canvas );

			return '';
		}

		$this->lay_out_pieces( $canvas, $artwork, $layout, $scale );
		$this->images->free( $artwork );

		$this->overlay_layer( $canvas, $layer, $width, $height );

		$webp = $this->images->to_webp( $canvas, 82 );
		$this->images->free( $canvas );

		if ( '' === $webp ) {
			return '';
		}

		$path = $this->storage->write( $this->storage->session_path( $public_id, 'proof.webp' ), $webp );

		if ( '' === $path ) {
			$this->logger->warning( 'Proof could not be written.', array( 'design' => $public_id ) );

			return '';
		}

		$this->logger->info(
			'Proof built.',
			array(
				'design' => $public_id,
				'size'   => $width . 'x' . $height,
				'pieces' => count( $layout['pieces'] ),
			)
		);

		return $path;
	}

	/**
	 * One copy of the artwork per piece, at the imposed positions.
	 *
	 * `cover()` rather than `resize()`, and the circle mask, for the same
	 * reason the print path uses them: this has to look like the finished
	 * sheet, and a squashed picture in a circle does not.
	 *
	 * @param GdImage              $canvas  Proof canvas, modified in place.
	 * @param GdImage              $artwork The watermarked preview.
	 * @param array<string, mixed> $layout  From `PrintSpec::editor_layout()`.
	 * @param float                $scale   Print pixels → proof pixels.
	 */
	private function lay_out_pieces( GdImage $canvas, GdImage $artwork, array $layout, float $scale ): void {
		foreach ( (array) $layout['pieces'] as $piece ) {
			$piece_w = max( 1, (int) round( (int) $piece['w'] * $scale ) );
			$piece_h = max( 1, (int) round( (int) $piece['h'] * $scale ) );

			$copy = $this->images->cover( $artwork, $piece_w, $piece_h );

			if ( null === $copy ) {
				continue;
			}

			if ( ! empty( $layout['round'] ) ) {
				$this->images->circle_mask( $copy );
			}

			$this->images->paste(
				$canvas,
				$copy,
				(int) round( (int) $piece['cx'] * $scale ),
				(int) round( (int) $piece['cy'] * $scale )
			);

			$this->images->free( $copy );
		}
	}

	/**
	 * The customer's own bitmap, over the top.
	 *
	 * Scaled here, unlike in the print path — where a size mismatch is refused
	 * outright because stretching would put text across a cut line on a real
	 * sheet. Here the whole image is a scaled-down view, so scaling the layer
	 * by the same factor is the only way it can line up. Nothing is printed
	 * from this.
	 *
	 * @param GdImage   $canvas Proof canvas, modified in place.
	 * @param TextLayer $layer  The composed layer.
	 * @param int       $width  Canvas width.
	 * @param int       $height Canvas height.
	 */
	private function overlay_layer( GdImage $canvas, TextLayer $layer, int $width, int $height ): void {
		if ( ! $layer->has_bitmap() ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$image = $this->images->from_string( (string) file_get_contents( $layer->path ) );

		if ( null === $image ) {
			return;
		}

		$scaled = $this->images->resize( $image, $width, $height );
		$this->images->free( $image );

		if ( null === $scaled ) {
			return;
		}

		$this->images->paste( $canvas, $scaled, (int) round( $width / 2 ), (int) round( $height / 2 ) );
		$this->images->free( $scaled );
	}
}
