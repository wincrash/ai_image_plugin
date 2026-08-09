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

	private LayoutEndpoint $layout;

	private DesignEndpoint $design;

	private UploadEndpoint $upload;

	/**
	 * @param SessionEndpoint   $session    Session and nonce.
	 * @param GenerateEndpoint  $generate   Queue a generation.
	 * @param JobStatusEndpoint $status     Polling.
	 * @param FileEndpoint      $file       Delivery.
	 * @param TextLayerEndpoint $text_layer The composed text layer.
	 * @param LayoutEndpoint    $layout     The D-041 layout suggestion.
	 * @param DesignEndpoint    $design     A design with no generated picture.
	 * @param UploadEndpoint    $upload     The customer's own photograph.
	 */
	public function __construct(
		SessionEndpoint $session,
		GenerateEndpoint $generate,
		JobStatusEndpoint $status,
		FileEndpoint $file,
		TextLayerEndpoint $text_layer,
		LayoutEndpoint $layout,
		DesignEndpoint $design,
		UploadEndpoint $upload
	) {
		$this->upload     = $upload;
		$this->session    = $session;
		$this->generate   = $generate;
		$this->status     = $status;
		$this->file       = $file;
		$this->text_layer = $text_layer;
		$this->layout     = $layout;
		$this->design     = $design;
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

		/*
		 * A design with no generated picture (D-054). Same nonce rules as
		 * `/generate` — it writes a row and two files on the shop's disk, and
		 * "it is free" is not the same as "it is unauthenticated".
		 */
		register_rest_route(
			self::NAMESPACE,
			'/design',
			array(
				'methods'             => $this->design->methods(),
				'callback'            => array( $this->design, 'handle' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => $this->design->args(),
			)
		);

		/*
		 * The customer's own photograph (D-062). Nonced like everything else
		 * that writes to the shop's disk — and this one takes bytes from a
		 * stranger, so it is the last endpoint that should be callable
		 * cross-origin.
		 */
		register_rest_route(
			self::NAMESPACE,
			'/upload',
			array(
				'methods'             => $this->upload->methods(),
				'callback'            => array( $this->upload, 'handle' ),
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => $this->upload->args(),
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
					/*
					 * The wizard's format. These worked undeclared, because
					 * get_param() reads unregistered body params too — but an
					 * undeclared arg is sanitised by nobody and appears in no
					 * schema, and this pair decides both the generation aspect
					 * and the print geometry. `FormatCatalogue::find()` is
					 * still the thing that validates them; this is the layer
					 * that says they exist.
					 */
					'format_type'  => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					/*
					 * No sanitize_callback, deliberately: declaring the type is
					 * what casts it, and `floatval` cannot be used here at all
					 * — WP calls a sanitiser with three arguments, which an
					 * internal function refuses in PHP 8. `absint` above gets
					 * away with it only because it is userland.
					 */
					'format_mm'    => array(
						'type'    => 'number',
						'default' => 0,
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
			'/layout',
			array(
				'methods'  => 'POST',
				'callback' => array( $this->layout, 'handle' ),
				/*
				 * Nonced. It spends a fraction of a cent per press rather than
				 * nothing, and it forwards customer text to a third party --
				 * neither belongs on an endpoint anyone can call.
				 */
				'permission_callback' => array( $this, 'check_nonce' ),
				'args'                => array(
					'design' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'text'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
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
