<?php
/**
 * Accept the text layer the browser composed.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\PrintSpec;
use AiCake\Domain\TextLayer;
use AiCake\Imaging\GdEngine;
use AiCake\Imaging\LayerInspector;
use AiCake\Moderation\Moderator;
use AiCake\Pipeline\ProofPipeline;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Logger;
use AiCake\Throttle\IdentityResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /aicake/v1/text-layer -> 200 { ok, ink_px, coverage }
 *
 * D-033 moved text composition into the browser, so this is where a bitmap the
 * customer made enters the system. Everything §10 relies on — the blocklist,
 * the LLM check, the sanitiser — reads *words*, and a bitmap has none. This
 * endpoint is therefore the only thing standing between the print queue and
 * arbitrary artwork, and it is written that way: nothing is stored until every
 * check has passed.
 *
 * The order of the checks is deliberate. Ownership first because it is free
 * and it is the one that stops a stranger touching someone else's design;
 * moderation next because it is free and instant; the pixel scan last because
 * it is the only expensive one (0.44 s on a full A4 layer, measured).
 */
class TextLayerEndpoint {

	/**
	 * Cap on the decoded PNG.
	 *
	 * A sparse 8.3 M-pixel PNG-32 of text compresses to a few hundred KB. Eight
	 * megabytes is room for something pathological but still bounded, and the
	 * cap is applied to the *decoded* bytes so a base64 bomb cannot slip past
	 * it by being small on the wire.
	 */
	private const MAX_BYTES = 8388608;

	/**
	 * Longest string a customer may claim to have typed.
	 */
	private const MAX_TEXT_CHARS = 500;

	/**
	 * Seconds between accepted uploads from one visitor.
	 *
	 * Not the generation allowance — that counts paid images and refusing to
	 * save text because someone used their five generations would be nonsense.
	 * This exists only because the pixel scan is CPU, and a loop posting layers
	 * is the cheap way to occupy a worker (constraint #2).
	 */
	private const MIN_INTERVAL = 2;

	private DesignRepository $designs;

	private IdentityResolver $identity;

	private Moderator $moderator;

	private GdEngine $images;

	private LayerInspector $inspector;

	private PrivateStorage $storage;

	private ProofPipeline $proofs;

	private Logger $logger;

	/**
	 * @param DesignRepository $designs   Designs.
	 * @param IdentityResolver $identity  Identity.
	 * @param Moderator        $moderator Layers 0 and 1.
	 * @param GdEngine         $images    Decoding and re-encoding.
	 * @param LayerInspector   $inspector The colour gate.
	 * @param PrivateStorage   $storage   Where the layer lands.
	 * @param ProofPipeline    $proofs    The image the cart and the order show.
	 * @param Logger           $logger    Logging.
	 */
	public function __construct(
		DesignRepository $designs,
		IdentityResolver $identity,
		Moderator $moderator,
		GdEngine $images,
		LayerInspector $inspector,
		PrivateStorage $storage,
		ProofPipeline $proofs,
		Logger $logger
	) {
		$this->designs   = $designs;
		$this->identity  = $identity;
		$this->moderator = $moderator;
		$this->images    = $images;
		$this->inspector = $inspector;
		$this->storage   = $storage;
		$this->proofs    = $proofs;
		$this->logger    = $logger;
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$public_id = (string) $request->get_param( 'design' );

		/*
		 * 404, not 403, for both "no such design" and "not yours" — the same
		 * rule the job endpoint follows. A 403 confirms the id exists, which
		 * turns the endpoint into an enumeration oracle.
		 */
		$not_found = new WP_Error(
			'aicake_no_design',
			__( 'Nerasta.', 'ai-cake-topper' ),
			array( 'status' => 404 )
		);

		$design = $this->designs->find_by_public_id( $public_id );

		if ( null === $design || ! $this->owns( $design ) ) {
			return $not_found;
		}

		$cooldown = $this->cooldown();

		if ( is_wp_error( $cooldown ) ) {
			return $cooldown;
		}

		// Layer 0 on the string, exactly as the prompt gets it.
		$text = $this->moderator->clean( (string) $request->get_param( 'text' ) );

		if ( mb_strlen( $text ) > self::MAX_TEXT_CHARS ) {
			$text = mb_substr( $text, 0, self::MAX_TEXT_CHARS );
		}

		if ( '' !== $text ) {
			$verdict = $this->moderator->pre_check( $text );

			if ( ! $verdict->allowed_through() ) {
				/*
				 * Logged rather than written to the design row. §10 wants every
				 * rejection recorded with its layer, and this does that — but
				 * the *design* is not rejected: its image is fine and already
				 * paid for in generation allowance. Only the text is refused,
				 * and the customer retypes it.
				 */
				$this->logger->warning(
					'Text layer refused by moderation.',
					array(
						'design' => $public_id,
						'layer'  => $verdict->layer,
						'text'   => $text,
					)
				);

				return new WP_Error(
					'aicake_rejected',
					'sanity' === $verdict->layer
						? $this->moderator->nonsense_message()
						: $this->moderator->rejection_message(),
					array( 'status' => 422 )
				);
			}
		}

		$colours = $this->colours( $request );
		$bytes   = $this->decode( (string) $request->get_param( 'layer' ) );

		if ( '' === $bytes ) {
			return $this->bad_request( __( 'Nepavyko nuskaityti užrašo. Bandykite dar kartą.', 'ai-cake-topper' ) );
		}

		$layer = $this->images->from_string( $bytes );
		unset( $bytes );

		if ( null === $layer ) {
			return $this->bad_request( __( 'Nepavyko nuskaityti užrašo. Bandykite dar kartą.', 'ai-cake-topper' ) );
		}

		/*
		 * The layer is exactly the print canvas (D-033), and both sides read
		 * that from PrintSpec so they cannot drift. A layer at any other size
		 * would composite at the wrong scale — and it would have looked right
		 * in the editor, which is the failure mode that never gets caught until
		 * a customer has cut up a sheet.
		 */
		$spec = PrintSpec::for_design( $design );

		list( $canvas_w, $canvas_h ) = $spec->canvas_px();

		if ( imagesx( $layer ) !== $canvas_w || imagesy( $layer ) !== $canvas_h ) {
			$this->logger->warning(
				'Text layer is the wrong size.',
				array(
					'design'   => $public_id,
					'got'      => imagesx( $layer ) . 'x' . imagesy( $layer ),
					'expected' => $canvas_w . 'x' . $canvas_h,
				)
			);

			$this->images->free( $layer );

			return $this->bad_request( __( 'Užrašo dydis netinka. Atnaujinkite puslapį ir bandykite dar kartą.', 'ai-cake-topper' ) );
		}

		$verdict = $this->inspector->inspect( $layer, $colours );

		if ( ! $verdict['ok'] ) {
			$this->images->free( $layer );

			/*
			 * An empty layer is logged with the user agent, and the others are
			 * not, because the two mean opposite things.
			 *
			 * `off_palette` and `too_much_ink` are the gate working: someone
			 * pushed artwork through the text endpoint and was refused. Nothing
			 * to investigate.
			 *
			 * `empty` with text alongside it is a *device* failure (D-057) —
			 * the browser was asked for an 8.3 megapixel canvas and silently
			 * returned one that reads as transparent, which is how Safari on
			 * iOS behaves past its area budget. The customer sees their text on
			 * screen and is told it is empty, so they abandon the purchase and
			 * the shop never hears why. The user agent is the whole point: it
			 * turns "the wizard is broken" into a device we can name.
			 *
			 * Only when text was typed. An empty layer with no text is somebody
			 * pressing save on an untouched editor, which is not a fault.
			 */
			/*
			 * Every other refusal is logged with the inspector's own detail —
			 * `off_palette` already knows the exact pixel and colour that
			 * offended it, and `too_much_ink` knows the coverage. None of that
			 * reached anywhere a shop could read it, so the only evidence was
			 * a customer-facing sentence that deliberately says nothing
			 * specific (§10: a precise refusal is a tutorial in getting past
			 * it). That is right for the customer and useless for Ruslan.
			 */
			if ( 'empty' !== $verdict['reason'] ) {
				$this->logger->warning(
					'Text layer refused by the pixel gate.',
					array(
						'design'   => $public_id,
						'reason'   => $verdict['reason'],
						'colours'  => implode( ',', $colours ),
						'detail'   => $verdict['detail'],
					)
				);
			}

			if ( 'empty' === $verdict['reason'] && '' !== $text ) {
				$this->logger->warning(
					'Text layer arrived empty although text was typed — the browser could not build the canvas.',
					array(
						'design' => $public_id,
						'canvas' => $canvas_w . 'x' . $canvas_h,
						'chars'  => mb_strlen( $text ),
						'agent'  => isset( $_SERVER['HTTP_USER_AGENT'] )
							? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
							: '',
					)
				);
			}

			return new WP_Error(
				'aicake_layer_refused',
				$this->refusal_message( $verdict['reason'], '' !== $text ),
				array( 'status' => 422 )
			);
		}

		/*
		 * Re-encoded through GD rather than stored as received. The bytes came
		 * from a stranger; round-tripping them guarantees what lands on disk is
		 * a plain PNG and nothing else, and it settles the DPI at the same time
		 * (D-027).
		 */
		$png = $this->images->to_png( $layer, $spec->dpi );

		$this->images->free( $layer );

		if ( '' === $png ) {
			return $this->bad_request( __( 'Nepavyko išsaugoti užrašo. Bandykite dar kartą.', 'ai-cake-topper' ) );
		}

		$path = $this->storage->write(
			$this->storage->session_path( $public_id, 'text.png' ),
			$png
		);

		if ( '' === $path ) {
			return new WP_Error(
				'aicake_storage_failed',
				__( 'Nepavyko išsaugoti užrašo. Bandykite dar kartą.', 'ai-cake-topper' ),
				array( 'status' => 500 )
			);
		}

		$stored = new TextLayer( $text, $colours, $path, $canvas_w, $canvas_h );

		$encoded = wp_json_encode( $stored->to_array() );

		/*
		 * Build the proof now, while the layer is fresh on disk. This is the
		 * image the cart, the order screen and the confirmation email show —
		 * the artwork laid out per piece with the customer's text over it,
		 * watermarked. Without it they all show the bare preview, and someone
		 * who placed twelve names sees one plain circle in their cart with no
		 * way to tell whether their text survived.
		 *
		 * A failure here is not a failure of the save. The layer is stored and
		 * will print correctly; the cart falls back to the preview, which is
		 * how it looked before this existed.
		 */
		$proof = $this->proofs->build(
			(string) $design['file_preview'],
			(string) $design['public_id'],
			PrintSpec::for_design( $design ),
			$stored
		);

		$this->designs->update(
			(int) $design['id'],
			array(
				'text_payload' => false === $encoded ? null : $encoded,
				'file_proof'   => '' === $proof ? null : $proof,
			)
		);

		$this->mark_upload();

		$response = new WP_REST_Response(
			array(
				'ok'       => true,
				'ink_px'   => $verdict['detail']['ink_px'] ?? 0,
				'coverage' => $verdict['detail']['coverage'] ?? 0,
			),
			200
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Decode the posted image.
	 *
	 * Accepts a data URL because that is what `canvas.toDataURL()` produces,
	 * and bare base64 because a future caller may not use a canvas.
	 *
	 * @param string $raw Posted value.
	 * @return string PNG bytes, or '' if it is not one.
	 */
	private function decode( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}

		if ( str_starts_with( $raw, 'data:' ) ) {
			$comma = strpos( $raw, ',' );

			if ( false === $comma ) {
				return '';
			}

			// Only base64 PNG. A data URL can also carry percent-encoded text,
			// and accepting that means accepting an SVG, which is a script.
			if ( ! str_starts_with( $raw, 'data:image/png;base64,' ) ) {
				return '';
			}

			$raw = substr( $raw, $comma + 1 );
		}

		$bytes = base64_decode( strtr( trim( $raw ), ' ', '+' ), true );

		if ( false === $bytes || '' === $bytes || strlen( $bytes ) > self::MAX_BYTES ) {
			return '';
		}

		// The PNG signature. GD would reject a non-PNG anyway, but refusing it
		// here means never handing arbitrary bytes to the decoder at all.
		if ( ! str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return '';
		}

		return $bytes;
	}

	/**
	 * The declared colours, as posted.
	 *
	 * Left unvalidated here on purpose — `LayerInspector` parses them and
	 * refuses an empty or oversized palette itself, so there is exactly one
	 * place that decides what a valid palette is.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string[]
	 */
	private function colours( WP_REST_Request $request ): array {
		$raw = $request->get_param( 'colours' );

		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		return array_map( 'strval', array_slice( $raw, 0, 16 ) );
	}

	/**
	 * Whether this visitor may act on this design.
	 *
	 * @param array<string, mixed> $design Design row.
	 */
	private function owns( array $design ): bool {
		$user_id = $this->identity->user_id();

		if ( $user_id > 0 && (int) ( $design['user_id'] ?? 0 ) === $user_id ) {
			return true;
		}

		$session = $this->identity->session_key();

		return '' !== $session && hash_equals( (string) $design['session_key'], $session );
	}

	/**
	 * Refuse a visitor who is posting layers in a loop.
	 *
	 * @return true|WP_Error
	 */
	private function cooldown() {
		$key = 'aicake_layer_' . md5( $this->identity->session_key() . '|' . $this->identity->ip_hash() );

		if ( false !== get_transient( $key ) ) {
			return new WP_Error(
				'aicake_too_fast',
				__( 'Palaukite akimirką ir bandykite dar kartą.', 'ai-cake-topper' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Start the cooldown. Only after a layer is accepted, so a rejected upload
	 * does not lock the customer out of fixing it.
	 */
	private function mark_upload(): void {
		$key = 'aicake_layer_' . md5( $this->identity->session_key() . '|' . $this->identity->ip_hash() );

		set_transient( $key, 1, self::MIN_INTERVAL );
	}

	/**
	 * What to tell the customer when the pixels are refused.
	 *
	 * Never names the specific rule. The messages are deliberately about what
	 * to do rather than what was detected — the same principle §10 applies to
	 * blocked prompts, for the same reason: a precise refusal is a tutorial in
	 * getting past it.
	 *
	 * @param string $reason    Machine-readable cause from the inspector.
	 * @param bool   $had_text  Whether the customer actually typed something.
	 */
	private function refusal_message( string $reason, bool $had_text = false ): string {
		if ( 'empty' === $reason ) {
			/*
			 * The same empty bitmap means two different things, and saying the
			 * wrong one costs a sale (D-057).
			 *
			 * No text typed: the customer pressed save on an untouched editor.
			 * „Užrašas tuščias." is exactly right and tells them what to do.
			 *
			 * Text typed: their words are on the screen and the *browser*
			 * failed to draw them — Safari past its canvas budget returns a
			 * transparent canvas without complaining. Telling that customer
			 * their text is empty sends them round a loop with no exit, because
			 * nothing they can change is the problem.
			 *
			 * The browser says this itself when it detects the failure. This is
			 * the same sentence from the other side, for a customer running
			 * cached JavaScript that predates the check.
			 */
			return $had_text
				? __( 'Jūsų telefonas ar naršyklė nepajėgė paruošti užrašo šiam dydžiui. Pabandykite kitu įrenginiu arba pasirinkite mažesnį formatą.', 'ai-cake-topper' )
				: __( 'Užrašas tuščias.', 'ai-cake-topper' );
		}

		return __( 'Užrašo išsaugoti nepavyko. Naudokite tik tekstą ir pasirinktas spalvas.', 'ai-cake-topper' );
	}

	/**
	 * A 400 with a customer-facing message.
	 *
	 * @param string $message What to say.
	 */
	private function bad_request( string $message ): WP_Error {
		return new WP_Error( 'aicake_bad_layer', $message, array( 'status' => 400 ) );
	}
}
