<?php
/**
 * Ask for a layout suggestion.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\PrintSpec;
use AiCake\Imaging\FontCatalogue;
use AiCake\Moderation\Moderator;
use AiCake\Pipeline\LayoutSuggester;
use AiCake\Throttle\IdentityResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /aicake/v1/layout -> 200 { lines, outline, outline_colour, font }
 *
 * D-041: the model lays the text out, the browser draws it, the customer moves
 * it. Nothing is rendered here and nothing is stored — the suggestion is a
 * proposal the editor loads into its canvas, and the layer only reaches the
 * server later through `/text-layer`, where every check still applies.
 *
 * **A failure here is not an error worth showing.** The button is optional and
 * the editor works without it, so an unconfigured key, a refused call or a
 * useless answer all return 200 with no lines. The alternative is a red
 * message about a feature the customer did not ask for.
 */
class LayoutEndpoint {

	/**
	 * Seconds between suggestions for one visitor.
	 *
	 * The call is cheap — a fraction of a cent against $0.012 for an image —
	 * but it is still an outbound request per press, and a held-down button
	 * should not become a bill.
	 */
	private const MIN_INTERVAL = 3;

	private DesignRepository $designs;

	private IdentityResolver $identity;

	private Moderator $moderator;

	private LayoutSuggester $suggester;

	private FontCatalogue $fonts;

	/**
	 * @param DesignRepository $designs   Designs.
	 * @param IdentityResolver $identity  Identity.
	 * @param Moderator        $moderator Layers 0 and 1.
	 * @param LayoutSuggester  $suggester The design director.
	 * @param FontCatalogue    $fonts     Fonts that can spell Lithuanian.
	 */
	public function __construct(
		DesignRepository $designs,
		IdentityResolver $identity,
		Moderator $moderator,
		LayoutSuggester $suggester,
		FontCatalogue $fonts
	) {
		$this->designs   = $designs;
		$this->identity  = $identity;
		$this->moderator = $moderator;
		$this->suggester = $suggester;
		$this->fonts     = $fonts;
	}

	/**
	 * Handle the request.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$public_id = (string) $request->get_param( 'design' );
		$design    = $this->designs->find_by_public_id( $public_id );

		// 404 for missing and for not-yours alike, as everywhere else.
		if ( null === $design || ! $this->owns( $design ) ) {
			return new WP_Error(
				'aicake_no_design',
				__( 'Nerasta.', 'ai-cake-topper' ),
				array( 'status' => 404 )
			);
		}

		$key = 'aicake_layout_' . md5( $this->identity->session_key() . '|' . $this->identity->ip_hash() );

		if ( false !== get_transient( $key ) ) {
			return new WP_Error(
				'aicake_too_fast',
				__( 'Palaukite akimirką ir bandykite dar kartą.', 'ai-cake-topper' ),
				array( 'status' => 429 )
			);
		}

		$text = $this->moderator->clean( (string) $request->get_param( 'text' ) );

		if ( '' === $text ) {
			return $this->nothing();
		}

		/*
		 * Layers 0 and 1 before the call, not after. The text is about to be
		 * sent to a third party, and §10's job is to stop that for a prompt
		 * we would refuse anyway — there is no reason to hand it over first.
		 */
		if ( ! $this->moderator->pre_check( $text )->allowed_through() ) {
			return new WP_Error(
				'aicake_rejected',
				$this->moderator->rejection_message(),
				array( 'status' => 422 )
			);
		}

		set_transient( $key, 1, self::MIN_INTERVAL );

		$handles = array_keys( $this->fonts->usable() );

		if ( array() === $handles ) {
			return $this->nothing();
		}

		$spec = PrintSpec::for_design( $design );

		$suggestion = $this->suggester->suggest(
			$text,
			// The picture is context for the colour choice: white text reads
			// over a dark drawing and disappears over a pale one.
			(string) ( $design['prompt_raw'] ?? '' ),
			$handles,
			$spec->is_round()
		);

		if ( array() === $suggestion ) {
			return $this->nothing();
		}

		$response = new WP_REST_Response( $suggestion, 200 );

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * A successful "no suggestion".
	 */
	private function nothing(): WP_REST_Response {
		$response = new WP_REST_Response( array( 'lines' => array() ), 200 );

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
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
}
