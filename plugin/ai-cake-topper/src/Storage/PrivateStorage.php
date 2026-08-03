<?php
/**
 * Files that must never be served directly.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Storage;

use AiCake\Support\Logger;
use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The two storage zones from PLAN.md §12.2.
 *
 *   sessions/  ephemeral, hashed, auto-cleaned
 *   orders/    permanent, human-readable, never auto-deleted
 *
 * Extracted here because both the runner and the admin test screen write
 * masters, and a second copy of the path logic is a second place for the
 * layout to drift from the plan.
 */
class PrivateStorage {

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param Settings $settings Configuration.
	 * @param Logger   $logger   Logging.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Absolute path for a file in the ephemeral zone.
	 *
	 * @param string $public_id Design handle.
	 * @param string $suffix    e.g. 'master.png', 'preview.webp'.
	 */
	public function session_path( string $public_id, string $suffix ): string {
		return $this->settings->storage_dir() . '/sessions/' . gmdate( 'Y/m' ) . '/' . $public_id . '-' . $suffix;
	}

	/**
	 * Absolute path for a file in the permanent zone.
	 *
	 * Deliberately human-readable: browse to orders/2026/08/10432/ and the
	 * files are there, named obviously, with no database lookup needed.
	 *
	 * The month comes from the **order**, not from now. A render that is
	 * retried after a month boundary — which is exactly what happens when a
	 * failure is noticed on the 1st — would otherwise scatter one order's
	 * files across two folders, and the folder is the thing a human browses.
	 *
	 * A suffix beginning with a dot is an extension on the item itself, so the
	 * sidecar lands as `item-57.json` rather than `item-57-.json` (§12.2).
	 *
	 * @param int      $order_id   WooCommerce order id.
	 * @param int      $item_id    Order item id.
	 * @param string   $suffix     e.g. 'print.png', or '.json'.
	 * @param int|null $created_ts Order creation time, defaults to now.
	 */
	public function order_path( int $order_id, int $item_id, string $suffix, ?int $created_ts = null ): string {
		$month     = gmdate( 'Y/m', $created_ts ?? time() );
		$separator = str_starts_with( $suffix, '.' ) ? '' : '-';

		return $this->settings->storage_dir() . '/orders/' . $month . '/' . $order_id . '/item-' . $item_id . $separator . $suffix;
	}

	/**
	 * Write bytes, creating the directory.
	 *
	 * @param string $path  Absolute path.
	 * @param string $bytes Contents.
	 * @return string The path on success, '' on failure.
	 */
	public function write( string $path, string $bytes ): string {
		$dir = dirname( $path );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			$this->logger->error( 'Could not create a storage directory.', array( 'dir' => $dir ) );

			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $path, $bytes, LOCK_EX );

		if ( false === $written ) {
			$this->logger->error( 'Could not write a file.', array( 'path' => $path ) );

			return '';
		}

		return $path;
	}

	/**
	 * Store the clean generation. Never served to anyone.
	 *
	 * @param string $public_id Design handle.
	 * @param string $bytes     Image data.
	 * @return string Path, or '' on failure.
	 */
	public function store_master( string $public_id, string $bytes ): string {
		if ( '' === $public_id || '' === $bytes ) {
			return '';
		}

		return $this->write( $this->session_path( $public_id, 'master.png' ), $bytes );
	}

	/**
	 * Remove a file, if it is inside our storage root.
	 *
	 * The containment check is not paranoia: paths come out of the database,
	 * and an unchecked unlink() driven by a database value is how a bug turns
	 * into arbitrary file deletion.
	 *
	 * @param string $path Absolute path.
	 */
	public function delete( string $path ): bool {
		if ( '' === $path || ! file_exists( $path ) ) {
			return false;
		}

		$root = realpath( $this->settings->storage_dir() );
		$real = realpath( $path );

		if ( false === $root || false === $real || ! str_starts_with( $real, $root ) ) {
			$this->logger->warning( 'Refused to delete a file outside the storage root.', array( 'path' => $path ) );

			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		return unlink( $real );
	}
}
