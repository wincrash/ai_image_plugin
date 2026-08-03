<?php
/**
 * Route registration.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the aicake/v1 namespace.
 *
 * PLAN.md §16: every route has a real permission_callback, and nothing that
 * spends money uses __return_true.
 */
class RestController {

	public const NAMESPACE = 'aicake/v1';

	private SessionEndpoint $session;

	private GenerateEndpoint $generate;

	private JobStatusEndpoint $status;

	private FileEndpoint $file;

	private TextLayerEndpoint $text_layer;

	/**
	 * @param SessionEndpoint   $session    Session and nonce.
	 * @param GenerateEndpoint  $generate   Queue a generation.
	 * @param JobStatusEndpoint $status     Polling.
	 * @param FileEndpoint      $file       Delivery.
	 * @param TextLayerEndpoint $text_layer The composed text layer.
	 */
	public function __construct(
		SessionEndpoint $session,
		GenerateEndpoint $generate,
		JobStatusEndpoint $status,
		FileEndpoint $file,
		TextLayerEndpoint $text_layer
	) {
		$this->session    = $session;
		$this->generate   = $generate;
		$this->status     = $status;
		$this->file       = $file;
		$this->text_layer = $text_layer;
	}

	/**
	 * Hook registration.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Declare the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/session',
			array(
				'methods'  => 'GET',
				'callback' => array( $this->session, 'handle' ),
				/*
				 * Public on purpose. It issues a nonce and a session cookie and
				 * spends nothing; requiring a nonce to fetch a nonce is a
				 * circular dependency, and this is the endpoint that exists
				 * precisely because the page HTML cannot be trusted to carry
				 * one (§7).
				 */
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/generate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->generate, 'handle' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'prompt'       => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'aspect'       => array(
						'type'              => 'string',
						'default'           => '1:1',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'product_id'   => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'variation_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/job/(?P<id>\d+)',
			array(
				'methods'  => 'GET',
				'callback' => array( $this->status, 'handle' ),
				/*
				 * No nonce. Polling has to survive the nonce expiring
				 * mid-generation, and the endpoint is protected by an
				 * ownership check against the session cookie instead — which
				 * is the thing that actually matters here.
				 */
				'permission_callback' => '__return_true',
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/text-layer',
			array(
				'methods'  => 'POST',
				'callback' => array( $this->text_layer, 'handle' ),
				/*
				 * Nonced like /generate. It spends no money, but it writes a
				 * file and runs a full-canvas pixel scan, and it is the entry
				 * point for customer-supplied artwork (D-033) — the last thing
				 * that should be callable cross-origin.
				 */
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'design'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'text'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					/*
					 * No sanitize_callback on either of these. The image is
					 * base64 that sanitisation would silently corrupt, and the
					 * palette is validated by LayerInspector — which has to be
					 * the only judge of what a valid palette is, or two places
					 * disagree and the check becomes advisory.
					 */
					'layer'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'colours' => array(
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/file/(?P<public_id>[a-f0-9]{32})/(?P<variant>[a-z]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this->file, 'handle' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'public_id' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'variant'   => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Verify the REST nonce explicitly.
	 *
	 * WordPress only enforces it automatically for cookie-authenticated
	 * requests, and most of our traffic is logged out — so for exactly the
	 * visitors who matter, "WordPress checks it for you" is false.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public function check_nonce( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );

		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		return new WP_Error(
			'aicake_bad_nonce',
			__( 'Sesija pasibaigė. Atnaujinkite puslapį.', 'ai-cake-topper' ),
			array( 'status' => 403 )
		);
	}
}
