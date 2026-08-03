<?php
/**
 * Moving a paid design out of reach of every cleanup job.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Storage;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\PrintFile;
use AiCake\Domain\PrintSpec;
use AiCake\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The `sessions/` → `orders/` transition from PLAN.md §12.2.
 *
 * Two zones with opposite lifecycles: `sessions/` is hashed, ephemeral and
 * swept after 30 days; `orders/` is human-readable and never auto-deleted.
 * **When an order is paid the design moves**, and from that moment the
 * retention job cannot reach it. Doing this at fulfilment rather than at
 * checkout means an unpaid order never leaves anything permanent behind.
 *
 * The `.json` sidecar (§12.3) is one `file_put_contents` and buys three
 * things: the order folder is self-describing without the database, a reprint
 * is fully reproducible, and if the database is ever lost the print files are
 * still identifiable.
 */
class OrderArchive {

	private PrivateStorage $storage;

	private DesignRepository $designs;

	private Logger $logger;

	/**
	 * @param PrivateStorage   $storage Files.
	 * @param DesignRepository $designs Designs.
	 * @param Logger           $logger  Logging.
	 */
	public function __construct( PrivateStorage $storage, DesignRepository $designs, Logger $logger ) {
		$this->storage = $storage;
		$this->designs = $designs;
		$this->logger  = $logger;
	}

	/**
	 * Move a design's files into the order folder and write the print file.
	 *
	 * @param array<string, mixed> $design    The design row.
	 * @param PrintFile            $print     The rendered print file.
	 * @param PrintSpec            $spec      Product geometry, for the sidecar.
	 * @param int                  $order_id  WooCommerce order id.
	 * @param int                  $item_id   Order item id.
	 * @param array<string, mixed> $context   Order number, product name, created_ts.
	 * @return string Path to the print file, or '' on failure.
	 */
	public function archive(
		array $design,
		PrintFile $print,
		PrintSpec $spec,
		int $order_id,
		int $item_id,
		array $context = array()
	): string {
		$created = isset( $context['created_ts'] ) ? (int) $context['created_ts'] : null;

		$print_path = $this->storage->write(
			$this->storage->order_path( $order_id, $item_id, 'print.png', $created ),
			$print->bytes
		);

		if ( '' === $print_path ) {
			// Without the print file there is nothing to fulfil, so fail here
			// rather than move the source files and report success.
			return '';
		}

		$master  = $this->relocate( (string) $design['file_master'], $order_id, $item_id, 'master.png', $created );
		$preview = $this->relocate( (string) $design['file_preview'], $order_id, $item_id, 'preview.webp', $created );

		$this->designs->update(
			(int) $design['id'],
			array(
				'file_print'    => $print_path,
				'file_master'   => '' !== $master ? $master : $design['file_master'],
				'file_preview'  => '' !== $preview ? $preview : $design['file_preview'],
				'order_id'      => $order_id,
				'order_item_id' => $item_id,
			)
		);

		$this->write_sidecar( $design, $print, $spec, $order_id, $item_id, $context );

		return $print_path;
	}

	/**
	 * Copy a file into the order folder, then remove the original.
	 *
	 * Copy-then-delete rather than rename(): the two zones can end up on
	 * different filesystems on a host that mounts one of them, and a failed
	 * rename would lose the only copy of a master a customer has paid for.
	 * A leftover source file is swept by retention; a lost master is not
	 * recoverable.
	 *
	 * @param string   $from       Current absolute path.
	 * @param int      $order_id   Order id.
	 * @param int      $item_id    Item id.
	 * @param string   $suffix     Destination suffix.
	 * @param int|null $created_ts Order creation time.
	 * @return string The new path, or '' if there was nothing to move.
	 */
	private function relocate( string $from, int $order_id, int $item_id, string $suffix, ?int $created_ts ): string {
		if ( '' === $from || ! is_readable( $from ) ) {
			return '';
		}

		$to = $this->storage->order_path( $order_id, $item_id, $suffix, $created_ts );

		if ( $from === $to ) {
			// Already archived. A retry must not delete the file it just found.
			return $to;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$bytes = (string) file_get_contents( $from );

		if ( '' === $bytes ) {
			return '';
		}

		$written = $this->storage->write( $to, $bytes );

		if ( '' === $written ) {
			$this->logger->warning( 'Could not archive a file; leaving the original.', array( 'from' => $from ) );

			return '';
		}

		$this->storage->delete( $from );

		return $written;
	}

	/**
	 * The reproduction record described in §12.3.
	 *
	 * @param array<string, mixed> $design   The design row.
	 * @param PrintFile            $print    The rendered file.
	 * @param PrintSpec            $spec     Product geometry.
	 * @param int                  $order_id Order id.
	 * @param int                  $item_id  Item id.
	 * @param array<string, mixed> $context  Order number, product name.
	 */
	private function write_sidecar(
		array $design,
		PrintFile $print,
		PrintSpec $spec,
		int $order_id,
		int $item_id,
		array $context
	): void {
		$record = array(
			'order_id'     => $order_id,
			'order_number' => (string) ( $context['order_number'] ?? $order_id ),
			'item_id'      => $item_id,
			'design_id'    => (string) $design['public_id'],
			'created_at'   => gmdate( 'c' ),
			'product'      => array(
				'id'   => (int) $design['product_id'],
				'name' => (string) ( $context['product_name'] ?? '' ),
			),
			'print_spec'   => array(
				'shape'     => $spec->shape,
				'width_mm'  => $spec->width_mm,
				'height_mm' => $spec->height_mm,
				'bleed_mm'  => $spec->bleed_mm,
				'copies'    => $print->copies,
				'sheet'     => $spec->sheet,
				'dpi'       => $spec->dpi,
			),
			'prompt'       => array(
				'raw_lt' => (string) $design['prompt_raw'],
				'en'     => (string) $design['prompt_en'],
				'final'  => (string) $design['prompt_final'],
			),
			'text'         => $this->decode( (string) $design['text_payload'] ),
			'generation'   => array(
				'provider' => (string) $design['provider'],
				'model'    => (string) $design['model'],
				'seed'     => null === $design['seed'] ? null : (int) $design['seed'],
				'aspect'   => (string) $design['aspect'],
				'cost_usd' => (float) $design['cost_usd'],
			),
			'print_file'   => $print->to_array(),
			'moderation'   => $this->decode( (string) $design['moderation'] ),
		);

		$this->storage->write(
			$this->storage->order_path( $order_id, $item_id, '.json', isset( $context['created_ts'] ) ? (int) $context['created_ts'] : null ),
			(string) wp_json_encode( $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
	}

	/**
	 * A stored JSON column as an array, or null.
	 *
	 * @param string $json Raw column value.
	 * @return array<string, mixed>|null
	 */
	private function decode( string $json ): ?array {
		if ( '' === $json ) {
			return null;
		}

		$value = json_decode( $json, true );

		return is_array( $value ) ? $value : null;
	}
}
