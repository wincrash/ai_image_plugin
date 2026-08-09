<?php
/**
 * The customer's own photograph.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\SourceCatalogue;
use AiCake\Imaging\GdEngine;
use AiCake\Pipeline\PreviewPipeline;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Mm;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use AiCake\Throttle\IdentityResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /aicake/v1/upload -> 201 { design, layoutKey, preview }
 *
 * Ruslan's second case: *"user may upload his image, cut circle from it, and use
 * it for circle/cupcake decoration with posiblity add text."*
 *
 * **The browser has already cropped and scaled by the time this runs** (D-056).
 * That reverses a note this project carried for a while — that the browser
 * should send a crop *rectangle* and let the server cut from the original, to
 * avoid throwing away resolution. Measured against the actual numbers, it does
 * not: a phone photograph is 4000 px on its long edge and a ⌀15 cm circle needs
 * 1772, so a client-side crop still has resolution to spare. What the old plan
 * would have cost is real — a 12 megapixel JPEG is about 48 MB decoded in GD,
 * on a host with a 256 MB ceiling, in a customer-facing request.
 *
 * So what arrives here is small. The job of this endpoint is not to do imaging,
 * it is to be **certain that what arrived is an image and nothing else**.
 *
 * The threat is not a rude picture — Ruslan sees every sheet at the printer
 * (D-047, D-060). The threat is a file that is not really an image: a payload
 * hidden in a comment chunk, an SVG with script in it, or a decompression bomb
 * that is 200 KB on the wire and 30000 x 30000 in memory.
 *
 * The order below is deliberate and each step is cheaper than the next:
 * declared type, byte cap, signature, dimensions **before** any decode, then
 * decode, then re-encode and throw the original away.
 */
class UploadEndpoint {

	/**
	 * Cap on the decoded upload.
	 *
	 * The browser sends a cropped, scaled piece — a few hundred KB as JPEG,
	 * a couple of MB as PNG. Six is generous and still bounded, and it is
	 * applied to the **decoded** bytes so a base64 bomb cannot slip past by
	 * being small on the wire.
	 */
	private const MAX_BYTES = 6291456;

	/**
	 * The largest image we will decode, in pixels either way.
	 *
	 * **This is the control that actually protects the site.** A PNG of a few
	 * hundred KB can declare 30000 x 30000 and cost gigabytes the instant GD
	 * touches it — the worker dies, and on a shared host it takes whatever else
	 * was running with it. Everything else on this list protects the customer;
	 * this one protects the shop.
	 *
	 * 6000 is comfortably above any phone camera's long edge and far below
	 * anything that could hurt: 6000 x 6000 is ~144 MB decoded, which is why
	 * the *area* is capped as well.
	 */
	private const MAX_EDGE_PX = 6000;

	/**
	 * The largest image we will decode, in total pixels.
	 *
	 * The edge cap alone allows 6000 x 6000. This is what actually bounds the
	 * memory: 24 megapixels is ~96 MB in GD, which fits under a 256 MB ceiling
	 * with room for the re-encode.
	 */
	private const MAX_PIXELS = 24000000;

	/**
	 * Seconds between accepted uploads from one visitor.
	 */
	private const MIN_INTERVAL = 3;

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
			'format_type' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			// No `floatval` sanitiser — see DesignEndpoint::args() and D-043.
			'format_mm'   => array(
				'type'     => 'number',
				'required' => true,
			),
			/*
			 * Unsanitised on purpose: it is base64, and any of WordPress's
			 * string sanitisers would corrupt it silently rather than reject
			 * it. `decode()` is the only thing that judges this value.
			 */
			'image'       => array(
				'type'     => 'string',
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
		// The lock behind the missing card (D-059).
		if ( ! SourceCatalogue::enabled( SourceCatalogue::UPLOAD, $this->settings ) ) {
			return new WP_Error(
				'aicake_source_disabled',
				__( 'Šiuo metu nuotraukų įkėlimas išjungtas.', 'ai-cake-topper' ),
				array( 'status' => 403 )
			);
		}

		$cooldown = $this->cooldown();

		if ( is_wp_error( $cooldown ) ) {
			return $cooldown;
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

		$bytes = $this->decode( (string) $request->get_param( 'image' ) );

		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$safe = $this->reencode( $bytes );

		unset( $bytes );

		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		// Identity, exactly as /generate and /design establish it.
		$session_key = $this->identity->session_key();

		if ( '' === $session_key ) {
			$session_key = $this->identity->issue_session_key();
		}

		$design_id = $this->designs->create(
			array(
				'session_key' => $session_key,
				'ip_hash'     => $this->identity->ip_hash(),
				'user_id'     => $this->identity->user_id() ?: null,
				'source'      => SourceCatalogue::UPLOAD,
				/*
				 * No prompt, and none invented. Moderation layers 0 and 1 read
				 * this column; putting words here that no customer typed would
				 * write fiction into the rejection log. That there is nothing
				 * to read is exactly D-060's point — an uploaded photograph is
				 * unmoderatable by software, and Ruslan at the printer is the
				 * control.
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
			return $this->failed();
		}

		$row       = (array) $this->designs->find( $design_id );
		$public_id = (string) $row['public_id'];

		$master_path = $this->storage->store_master( $public_id, $safe );

		unset( $safe );

		if ( '' === $master_path ) {
			return $this->failed();
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

		$this->mark_uploaded();

		$this->logger->info(
			'Photograph accepted.',
			array(
				'design' => $public_id,
				'format' => $format['type'] . '|' . $format['diameter_mm'],
			)
		);

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
	 * Base64 in, raw bytes out — or a refusal.
	 *
	 * @param string $raw Posted value.
	 * @return string|WP_Error
	 */
	private function decode( string $raw ) {
		$raw = trim( $raw );

		if ( '' === $raw ) {
			return $this->rejected();
		}

		if ( str_starts_with( $raw, 'data:' ) ) {
			/*
			 * PNG and JPEG only, and named explicitly rather than parsed out of
			 * the header. A data URL can carry percent-encoded text, and
			 * accepting that means accepting `image/svg+xml` — which is a
			 * document with script and external entities in it, not a bitmap.
			 */
			$ok = str_starts_with( $raw, 'data:image/png;base64,' )
				|| str_starts_with( $raw, 'data:image/jpeg;base64,' );

			if ( ! $ok ) {
				return $this->rejected();
			}

			$comma = strpos( $raw, ',' );

			if ( false === $comma ) {
				return $this->rejected();
			}

			$raw = substr( $raw, $comma + 1 );
		}

		$bytes = base64_decode( strtr( $raw, ' ', '+' ), true );

		if ( false === $bytes || '' === $bytes ) {
			return $this->rejected();
		}

		if ( strlen( $bytes ) > self::MAX_BYTES ) {
			return new WP_Error(
				'aicake_too_big',
				__( 'Nuotrauka per didelė. Pasirinkite mažesnę.', 'ai-cake-topper' ),
				array( 'status' => 413 )
			);
		}

		// The signature, before anything else looks at it.
		$png  = str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" );
		$jpeg = str_starts_with( $bytes, "\xFF\xD8\xFF" );

		if ( ! $png && ! $jpeg ) {
			return $this->rejected();
		}

		return $bytes;
	}

	/**
	 * Make it provably an image, and nothing else.
	 *
	 * @param string $bytes Raw upload.
	 * @return string|WP_Error PNG bytes.
	 */
	private function reencode( string $bytes ) {
		/*
		 * Dimensions BEFORE the decode. `getimagesizefromstring()` reads the
		 * header only, so this costs nothing — and it is the difference
		 * between refusing a decompression bomb and being killed by one. A
		 * 200 KB PNG can declare 30000 x 30000, and by the time GD has been
		 * asked for it the worker is already gone.
		 */
		$info = @getimagesizefromstring( $bytes );

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return $this->rejected();
		}

		$width  = (int) $info[0];
		$height = (int) $info[1];

		if ( $width > self::MAX_EDGE_PX || $height > self::MAX_EDGE_PX
			|| ( $width * $height ) > self::MAX_PIXELS ) {
			$this->logger->warning(
				'Upload refused: implausible dimensions.',
				array(
					'w' => $width,
					'h' => $height,
				)
			);

			return new WP_Error(
				'aicake_too_big',
				__( 'Nuotrauka per didelė. Pasirinkite mažesnę.', 'ai-cake-topper' ),
				array( 'status' => 413 )
			);
		}

		// And the declared type, from the bytes rather than from the caller.
		if ( ! in_array( (int) ( $info[2] ?? 0 ), array( IMAGETYPE_PNG, IMAGETYPE_JPEG ), true ) ) {
			return $this->rejected();
		}

		$image = $this->images->from_string( $bytes );

		if ( null === $image ) {
			return $this->rejected();
		}

		/*
		 * Re-encoded, and the original thrown away. This is the whole defence:
		 * decoding to pixels and writing them back out leaves EXIF, ICC
		 * profiles, embedded payloads and anything hiding in a comment chunk
		 * behind. Validation asks whether a file *looks* like an image;
		 * re-encoding *makes* it one.
		 */
		$png = $this->images->to_png( $image, Mm::PRINT_DPI );

		$this->images->free( $image );

		if ( '' === $png ) {
			return $this->failed();
		}

		return $png;
	}

	/**
	 * "That is not a picture."
	 *
	 * One message for every shape of refusal, deliberately. The specific
	 * reasons are in the log; telling a caller which check tripped is a
	 * tutorial in getting past it, the same principle §10 applies to prompts.
	 */
	private function rejected(): WP_Error {
		return new WP_Error(
			'aicake_not_an_image',
			__( 'Nepavyko nuskaityti nuotraukos. Pasirinkite JPG arba PNG failą.', 'ai-cake-topper' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Something on our side went wrong.
	 */
	private function failed(): WP_Error {
		return new WP_Error(
			'aicake_storage_failed',
			__( 'Nepavyko išsaugoti nuotraukos. Bandykite dar kartą.', 'ai-cake-topper' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Refuse a visitor uploading in a loop.
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
	 * Start the cooldown, only once an upload has been accepted.
	 */
	private function mark_uploaded(): void {
		set_transient( $this->cooldown_key(), 1, self::MIN_INTERVAL );
	}

	/**
	 * Per-visitor cooldown key.
	 */
	private function cooldown_key(): string {
		return 'aicake_upload_' . md5( $this->identity->session_key() . '|' . $this->identity->ip_hash() );
	}
}
