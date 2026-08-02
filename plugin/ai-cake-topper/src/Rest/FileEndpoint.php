<?php
/**
 * Capability-checked file delivery.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Domain\DesignRepository;
use AiCake\Support\Settings;
use AiCake\Throttle\IdentityResolver;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * GET /aicake/v1/file/{public_id}/{variant}
 *
 * The storage root is outside the webroot, so this is the only way a generated
 * file reaches a browser. Two rules, both from PLAN.md §12.4 and §16:
 *
 * 1. **The master is never served.** It is the clean, unwatermarked, full-size
 *    generation. Only the preview variant is reachable here, and the mapping
 *    is a hard-coded whitelist rather than a column name taken from the URL.
 * 2. **Ownership is verified on every request**, not just the first.
 */
class FileEndpoint {

	/**
	 * Variant name in the URL => column it may read. Deliberately excludes
	 * file_master and file_print.
	 */
	private const VARIANTS = array(
		'preview' => 'file_preview',
	);

	private DesignRepository $designs;

	private IdentityResolver $identity;

	private Settings $settings;

	/**
	 * @param DesignRepository $designs  Designs.
	 * @param IdentityResolver $identity Identity.
	 * @param Settings         $settings Configuration.
	 */
	public function __construct( DesignRepository $designs, IdentityResolver $identity, Settings $settings ) {
		$this->designs  = $designs;
		$this->identity = $identity;
		$this->settings = $settings;
	}

	/**
	 * Stream the file, or fail.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_Error|void Exits on success.
	 */
	public function handle( WP_REST_Request $request ) {
		$public_id = (string) $request->get_param( 'public_id' );
		$variant   = (string) $request->get_param( 'variant' );

		$not_found = new WP_Error( 'aicake_no_file', __( 'Nerasta.', 'ai-cake-topper' ), array( 'status' => 404 ) );

		if ( ! isset( self::VARIANTS[ $variant ] ) ) {
			return $not_found;
		}

		$design = $this->designs->find_by_public_id( $public_id );

		if ( null === $design || ! $this->owns( $design ) ) {
			return $not_found;
		}

		$path = (string) $design[ self::VARIANTS[ $variant ] ];

		if ( '' === $path || ! is_readable( $path ) || ! $this->inside_storage( $path ) ) {
			return $not_found;
		}

		$type = wp_check_filetype( $path );
		$mime = $type['type'] ? $type['type'] : 'application/octet-stream';

		// Not a WP_REST_Response: this is binary, and buffering a few MB
		// through the REST serialiser to no benefit is how a worker runs out
		// of memory.
		if ( ! headers_sent() ) {
			header( 'Content-Type: ' . $mime );
			header( 'Content-Length: ' . (string) filesize( $path ) );
			header( 'Content-Disposition: inline; filename="' . basename( $path ) . '"' );
			header( 'X-Content-Type-Options: nosniff' );
			// Private, not public: this is one customer's design.
			header( 'Cache-Control: private, max-age=300' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $path );
		exit;
	}

	/**
	 * Whether the caller owns this design.
	 *
	 * @param array<string, mixed> $design The design row.
	 */
	private function owns( array $design ): bool {
		$user_id = $this->identity->user_id();

		if ( 0 !== $user_id && (int) $design['user_id'] === $user_id ) {
			return true;
		}

		// A shop manager can see any design — that is the review queue.
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$session = $this->identity->session_key();

		return '' !== $session && hash_equals( (string) $design['session_key'], $session );
	}

	/**
	 * Refuse to read anything outside the storage root, whatever the database
	 * says. A path column plus readfile() is a directory-traversal primitive
	 * if it is ever trusted blindly.
	 *
	 * @param string $path Absolute path.
	 */
	private function inside_storage( string $path ): bool {
		$root = realpath( $this->settings->storage_dir() );
		$real = realpath( $path );

		return false !== $root && false !== $real && str_starts_with( $real, $root );
	}
}
