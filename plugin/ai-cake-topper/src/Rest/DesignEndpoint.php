<?php
/**
 * A design that was never generated.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\PrintSpec;
use AiCake\Domain\SourceCatalogue;
use AiCake\Imaging\GdEngine;
use AiCake\Pipeline\PreviewPipeline;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use AiCake\Throttle\IdentityResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /aicake/v1/design -> 201 { design, layout_key, preview }
 *
 * The way in for a decoration with **no generated picture** — Ruslan's first
 * new case: *"as user i want to have just cupcakes with my only custom text, so
 * here is no ai image at all."*
 *
 * Everything downstream — the editor, the proof, the cart, the print file —
 * needs a design row and a picture to lay text over. So this creates the row
 * and gives it **a plain white master**.
 *
 * That white rectangle is the whole design of this feature. The alternative was
 * to teach `PreviewPipeline`, `ProofPipeline`, `FulfilPipeline`, `Fulfilment`
 * and `OrderArchive` that a master is optional — five places, each with its own
 * "if there is no picture" branch, and each a place where a *real* missing
 * master could start being treated as normal instead of as the failure it is.
 * A blank sheet is not a special case of a picture; it is a picture that
 * happens to be white, and every path already knows what to do with one.
 *
 * Nothing here costs money, so there is no budget check and **no generation
 * allowance is consumed** — metering a free path against the budget that caps
 * the shop's spending tells a customer who wants a name on some cupcakes that
 * they have used up their free pictures. A short per-visitor cooldown does the
 * only job that is actually needed here: stopping a loop from occupying a
 * worker while it writes rows and files.
 */
class DesignEndpoint {

	/**
	 * The white master's long edge, in pixels.
	 *
	 * Small on purpose. `FulfilPipeline` upscales a master that is short of the
	 * print size, and upscaling flat white is the one case where interpolation
	 * cannot lose anything — so paying 8.3 megapixels of memory here, in a
	 * customer-facing request on a 256 MB host, would buy exactly nothing
	 * (D-023, D-056).
	 */
	private const MASTER_LONG_EDGE = 1024;

	/**
	 * Seconds between blank designs from one visitor.
	 *
	 * Short, because a customer changing their mind about the format at step 1
	 * legitimately creates several in a row and must not be made to wait.
	 */
	private const MIN_INTERVAL = 2;

	private DesignRepository $designs;

	private IdentityResolver $identity;

	private GdEngine $images;

	private PrivateStorage $storage;

	private PreviewPipeline $previews;

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param DesignRepository $designs  Designs.
	 * @param IdentityResolver $identity Identity.
	 * @param GdEngine         $images   Imaging.
	 * @param PrivateStorage   $storage  Files.
	 * @param PreviewPipeline  $previews Preview building.
	 * @param Settings         $settings Configuration.
	 * @param Logger           $logger   Logging.
	 */
	public function __construct(
		DesignRepository $designs,
		IdentityResolver $identity,
		GdEngine $images,
		PrivateStorage $storage,
		PreviewPipeline $previews,
		Settings $settings,
		Logger $logger
	) {
		$this->designs  = $designs;
		$this->identity = $identity;
		$this->images   = $images;
		$this->storage  = $storage;
		$this->previews = $previews;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Route arguments.
	 *
	 * @return array<string, mixed>
	 */
	public function args(): array {
		return array(
			'source'      => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'format_type' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			/*
			 * Declared `number` and left without a sanitiser. `floatval`
			 * cannot be one — WordPress calls sanitisers with three arguments
			 * and an internal function refuses them in PHP 8, which is a fatal
			 * rather than a validation failure. The declared type is what
			 * casts. This cost a session once already (D-043).
			 */
			'format_mm'   => array(
				'type'     => 'number',
				'required' => true,
			),
			'product_id'  => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * HTTP methods this route answers.
	 */
	public function methods(): string {
		return WP_REST_Server::CREATABLE;
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$source = (string) $request->get_param( 'source' );

		/*
		 * Only sources that genuinely produce no picture of their own may come
		 * through here. An allow-list rather than a deny-list: adding a fifth
		 * source later must not silently inherit a free, unmoderated way to
		 * make a design row.
		 */
		if ( SourceCatalogue::NONE !== $source ) {
			return new WP_Error(
				'aicake_bad_source',
				__( 'Nerasta.', 'ai-cake-topper' ),
				array( 'status' => 400 )
			);
		}

		// The lock behind the missing card (D-059).
		if ( ! SourceCatalogue::enabled( $source, $this->settings ) ) {
			return new WP_Error(
				'aicake_source_disabled',
				__( 'Šiuo metu ši parinktis išjungta.', 'ai-cake-topper' ),
				array( 'status' => 403 )
			);
		}

		$format = FormatCatalogue::find(
			(string) $request->get_param( 'format_type' ),
			(float) $request->get_param( 'format_mm' )
		);

		if ( null === $format ) {
			return new WP_Error(
				'aicake_bad_format',
				__( 'Šio formato nebesiūlome. Pasirinkite kitą dydį.', 'ai-cake-topper' ),
				array( 'status' => 400 )
			);
		}

		/*
		 * Establish the identity here if the client never called `/session`,
		 * exactly as `/generate` does. Refusing instead would turn a first-time
		 * visitor — who has no cookie yet, because nothing has set one — into
		 * an error, and the design would otherwise be written with an empty
		 * `session_key`, which means its owner can never come back to it.
		 */
		$session_key = $this->identity->session_key();

		if ( '' === $session_key ) {
			$session_key = $this->identity->issue_session_key();
		}

		/*
		 * A cooldown, deliberately **not** the generation allowance.
		 *
		 * `RateLimiter::check()` counts against the free-generations budget —
		 * five a session, twenty logged in — and that budget exists to cap what
		 * the shop *spends*. This endpoint spends nothing. Metering it there
		 * tells a customer who wants cupcakes with a name on them that they
		 * have "used up their free pictures", which is both untrue and the end
		 * of the sale. Measured, not reasoned: the first browser run of this
		 * path came back 429 „Išnaudojote nemokamus piešinius."
		 *
		 * The same argument `TextLayerEndpoint` already makes for its own
		 * cooldown, and the same mechanism — this is about occupying a worker,
		 * so it is bounded by time rather than by budget.
		 */
		$cooldown = $this->cooldown();

		if ( is_wp_error( $cooldown ) ) {
			return $cooldown;
		}

		$spec = FormatCatalogue::spec(
			(string) $format['type'],
			(float) $format['diameter_mm']
		);

		if ( null === $spec ) {
			return new WP_Error(
				'aicake_bad_format',
				__( 'Šio formato nebesiūlome. Pasirinkite kitą dydį.', 'ai-cake-topper' ),
				array( 'status' => 400 )
			);
		}

		$design_id = $this->designs->create(
			array(
				'session_key' => $session_key,
				'ip_hash'     => $this->identity->ip_hash(),
				'user_id'     => $this->identity->user_id() ?: null,
				'source'      => SourceCatalogue::NONE,
				/*
				 * Empty rather than a placeholder sentence. Moderation layers 0
				 * and 1 read this column, and seeding it with words nobody
				 * typed would put text into the rejection log that no customer
				 * is responsible for.
				 */
				'prompt_raw'  => '',
				'aspect'      => $spec->generation_aspect(),
				'product_id'  => (int) $request->get_param( 'product_id' ) ?: null,
				'format_type' => (string) $format['type'],
				'format_mm'   => (float) $format['diameter_mm'],
				'status'      => DesignRepository::STATUS_QUEUED,
			)
		);

		if ( 0 === $design_id ) {
			return new WP_Error(
				'aicake_storage_failed',
				__( 'Nepavyko pradėti. Bandykite dar kartą.', 'ai-cake-topper' ),
				array( 'status' => 500 )
			);
		}

		$row       = (array) $this->designs->find( $design_id );
		$public_id = (string) $row['public_id'];

		$master = $this->white_master( $spec );

		if ( '' === $master ) {
			$this->designs->update(
				$design_id,
				array(
					'status'        => DesignRepository::STATUS_FAILED,
					'error_code'    => 'blank_master',
					'error_message' => 'Could not create the blank sheet.',
				)
			);

			return new WP_Error(
				'aicake_storage_failed',
				__( 'Nepavyko pradėti. Bandykite dar kartą.', 'ai-cake-topper' ),
				array( 'status' => 500 )
			);
		}

		$master_path = $this->storage->store_master( $public_id, $master );

		if ( '' === $master_path ) {
			return new WP_Error(
				'aicake_storage_failed',
				__( 'Nepavyko pradėti. Bandykite dar kartą.', 'ai-cake-topper' ),
				array( 'status' => 500 )
			);
		}

		$preview_path = $this->previews->build( $master_path, $public_id, $spec );

		$this->designs->update(
			$design_id,
			array(
				'file_master'  => $master_path,
				'file_preview' => '' === $preview_path ? null : $preview_path,
				'status'       => DesignRepository::STATUS_DONE,
			)
		);

		$this->mark_created();

		$response = new WP_REST_Response(
			array(
				'design'    => $public_id,
				'status'    => DesignRepository::STATUS_DONE,
				'layoutKey' => FormatCatalogue::layout_key(
					(string) $format['type'],
					(float) $format['diameter_mm']
				),
				'preview'   => '' === $preview_path
					? ''
					: rest_url( RestController::NAMESPACE . '/file/' . $public_id . '/preview' ),
			),
			201
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Refuse a visitor creating blank designs in a loop.
	 *
	 * @return true|WP_Error
	 */
	private function cooldown() {
		if ( false !== get_transient( $this->cooldown_key() ) ) {
			return new WP_Error(
				'aicake_too_fast',
				__( 'Palaukite akimirką ir bandykite dar kartą.', 'ai-cake-topper' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Start the cooldown. Only after a design is actually created, so a refusal
	 * does not lock the customer out of retrying.
	 */
	private function mark_created(): void {
		set_transient( $this->cooldown_key(), 1, self::MIN_INTERVAL );
	}

	/**
	 * Per-visitor cooldown key.
	 */
	private function cooldown_key(): string {
		return 'aicake_blank_' . md5( $this->identity->session_key() . '|' . $this->identity->ip_hash() );
	}

	/**
	 * A blank sheet, as PNG bytes.
	 *
	 * Opaque white rather than transparent. The print is flattened onto white
	 * anyway, and a transparent master would make the *preview* transparent —
	 * which renders as whatever the page behind it happens to be, so the
	 * customer would be laying text over the theme rather than over their
	 * decoration.
	 *
	 * @param PrintSpec $spec The chosen format.
	 * @return string PNG bytes, or '' on failure.
	 */
	private function white_master( PrintSpec $spec ): string {
		list( $ratio_w, $ratio_h ) = $this->aspect_parts( $spec->generation_aspect() );

		$long = self::MASTER_LONG_EDGE;

		if ( $ratio_w >= $ratio_h ) {
			$width  = $long;
			$height = (int) round( $long * $ratio_h / $ratio_w );
		} else {
			$height = $long;
			$width  = (int) round( $long * $ratio_w / $ratio_h );
		}

		$image = $this->images->blank( $width, $height, false );

		if ( null === $image ) {
			$this->logger->error(
				'Could not allocate the blank sheet.',
				array( 'size' => $width . 'x' . $height )
			);

			return '';
		}

		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, imagecolorallocate( $image, 255, 255, 255 ) );

		$png = $this->images->to_png( $image, $spec->dpi );

		$this->images->free( $image );

		return $png;
	}

	/**
	 * "2:3" to a pair of numbers.
	 *
	 * @param string $aspect Aspect string.
	 * @return array{0:float, 1:float}
	 */
	private function aspect_parts( string $aspect ): array {
		$parts = explode( ':', $aspect );

		$w = isset( $parts[0] ) ? (float) $parts[0] : 1.0;
		$h = isset( $parts[1] ) ? (float) $parts[1] : 1.0;

		return array( $w > 0 ? $w : 1.0, $h > 0 ? $h : 1.0 );
	}
}
