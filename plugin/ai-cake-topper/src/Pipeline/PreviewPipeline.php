<?php
/**
 * Master to customer-facing preview.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Pipeline;

use AiCake\Domain\PrintSpec;
use AiCake\Domain\TextSpec;
use AiCake\Imaging\GdEngine;
use AiCake\Imaging\TextRenderer;
use AiCake\Imaging\Watermarker;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Logger;
use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The pre-payment path from PLAN.md §5:
 *
 *   master → shape → text (preview res) → watermark → downscale → preview.webp
 *
 * Cheap enough to run synchronously, which matters because the customer
 * changes the text and expects to see it. There is no API call here — the
 * master is already on disk, and everything below is local GD work on an
 * 800 px image.
 *
 * The preview is rendered at the true output aspect and shape, so what the
 * customer approves is what they get.
 */
class PreviewPipeline {

	/**
	 * Big enough to judge, too small to print (§9.3). A 4.5 cm circle needs
	 * 603 px at 300 DPI, so 800 px is already unusable for the largest SKU
	 * and comfortably so for the rest.
	 */
	public const PREVIEW_PX = 800;

	/**
	 * The resolution the preview pretends to be, so a text size given in
	 * millimetres lands in the right place relative to the artwork.
	 */
	private const PREVIEW_DPI = 96;

	private GdEngine $images;

	private TextRenderer $text;

	private Watermarker $watermarker;

	private PrivateStorage $storage;

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param GdEngine       $images      Pixels.
	 * @param TextRenderer   $text        Text layer.
	 * @param Watermarker    $watermarker Watermark.
	 * @param PrivateStorage $storage     Files.
	 * @param Settings       $settings    Configuration.
	 * @param Logger         $logger      Logging.
	 */
	public function __construct(
		GdEngine $images,
		TextRenderer $text,
		Watermarker $watermarker,
		PrivateStorage $storage,
		Settings $settings,
		Logger $logger
	) {
		$this->images      = $images;
		$this->text        = $text;
		$this->watermarker = $watermarker;
		$this->storage     = $storage;
		$this->settings    = $settings;
		$this->logger      = $logger;
	}

	/**
	 * Build a preview and write it next to the master.
	 *
	 * @param string        $master_path Absolute path to the clean generation.
	 * @param string        $public_id   Design handle.
	 * @param PrintSpec     $spec        Product geometry.
	 * @param TextSpec|null $text        Text layer, or null for none.
	 * @return string Path to the preview, or '' on failure.
	 */
	public function build( string $master_path, string $public_id, PrintSpec $spec, ?TextSpec $text = null ): string {
		if ( ! is_readable( $master_path ) ) {
			$this->logger->warning( 'Preview skipped: no master on disk.', array( 'path' => $master_path ) );

			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$bytes  = (string) file_get_contents( $master_path );
		$master = $this->images->from_string( $bytes );

		if ( null === $master ) {
			return '';
		}

		list( $target_w, $target_h ) = $this->preview_size( $spec );

		$canvas = $this->images->cover( $master, $target_w, $target_h );
		$this->images->free( $master );

		if ( null === $canvas ) {
			return '';
		}

		// The shape is applied before the text, so text near the edge is
		// clipped by the circle exactly as it will be on the finished piece.
		if ( $spec->is_round() ) {
			$this->images->circle_mask( $canvas );
		}

		if ( null !== $text && ! $text->is_empty() ) {
			$this->text->render( $canvas, $text, self::PREVIEW_DPI, $spec->is_round() );
		}

		$this->watermarker->apply( $canvas, $this->watermark_text() );

		$webp = $this->images->to_webp( $canvas, 82 );
		$this->images->free( $canvas );

		if ( '' === $webp ) {
			return '';
		}

		return $this->storage->write(
			$this->storage->session_path( $public_id, 'preview.webp' ),
			$webp
		);
	}

	/**
	 * Preview dimensions at the product's real aspect ratio.
	 *
	 * @param PrintSpec $spec Product geometry.
	 * @return array{0:int, 1:int}
	 */
	private function preview_size( PrintSpec $spec ): array {
		list( $w_mm, $h_mm ) = $spec->trim_mm();

		if ( $w_mm <= 0 || $h_mm <= 0 ) {
			return array( self::PREVIEW_PX, self::PREVIEW_PX );
		}

		if ( $w_mm >= $h_mm ) {
			return array( self::PREVIEW_PX, max( 1, (int) round( self::PREVIEW_PX * $h_mm / $w_mm ) ) );
		}

		return array( max( 1, (int) round( self::PREVIEW_PX * $w_mm / $h_mm ) ), self::PREVIEW_PX );
	}

	/**
	 * What the watermark says.
	 */
	private function watermark_text(): string {
		$configured = (string) $this->settings->get( 'watermark_text', '' );

		if ( '' !== $configured ) {
			return $configured;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : 'preview';
	}
}
