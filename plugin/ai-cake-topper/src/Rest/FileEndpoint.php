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
 *    generation. The mapping is a hard-coded whitelist rather than a column
 *    name taken from the URL.
 * 2. **Ownership is verified on every request**, not just the first.
 *
 * The `print` variant is the shop's own download (§12.4 point 3) and carries a
 * capability requirement rather than an ownership one. It is deliberately a
 * separate entry with its own gate: making `owns()` return true for shop
 * managers would have been one line, but it would also have handed them the
 * master the moment a third variant was added.
 */
class FileEndpoint {

	/**
	 * Variant name in the URL => the column it may read and the capability it
	 * demands ('' meaning ownership is enough). Deliberately excludes
	 * file_master, which is never servable to anyone.
	 */
	private const VARIANTS = array(
		'preview' => array( 'file_preview', '' ),
		/*
		 * The preview with the customer's own text over it (D-045). Same
		 * exposure as the preview — it is watermarked, and it is their own
		 * words on their own picture — and it is what the cart shows.
		 */
		'proof'   => array( 'file_proof', '' ),
		'print'   => array( 'file_print', 'manage_woocommerce' ),
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

		list( $column, $capability ) = self::VARIANTS[ $variant ];

		// 404 rather than 403 for a missing capability too: a shop's print
		// files are not something to confirm the existence of.
		if ( '' !== $capability && ! current_user_can( $capability ) ) {
			return $not_found;
		}

		$design = $this->designs->find_by_public_id( $public_id );

		if ( null === $design ) {
			return $not_found;
		}

		if ( '' === $capability && ! $this->owns( $design ) ) {
			return $not_found;
		}

		$path = (string) $design[ $column ];

		if ( '' === $path || ! is_readable( $path ) || ! $this->inside_storage( $path ) ) {
			return $not_found;
		}

		$type = wp_check_filetype( $path );
		$mime = $type['type'] ? $type['type'] : 'application/octet-stream';

		// Not a WP_REST_Response: this is binary, and buffering a few MB
		// through the REST serialiser to no benefit is how a worker runs out
		// of memory.
		if ( ! headers_sent() ) {
			// A print file is something the shop saves and sends to a printer,
			// so it downloads. A preview is something the customer looks at.
			$disposition = 'print' === $variant ? 'attachment' : 'inline';

			header( 'Content-Type: ' . $mime );
			header( 'Content-Length: ' . (string) filesize( $path ) );
			header( 'Content-Disposition: ' . $disposition . '; filename="' . basename( $path ) . '"' );
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
