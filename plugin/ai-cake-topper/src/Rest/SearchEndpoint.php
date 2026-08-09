<?php
/**
 * Finding a picture instead of drawing one.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Rest;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\SourceCatalogue;
use AiCake\Imaging\GdEngine;
use AiCake\Moderation\Moderator;
use AiCake\Pipeline\PreviewPipeline;
use AiCake\Providers\ProviderRegistry;
use AiCake\Providers\Search\OpenverseProvider;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Logger;
use AiCake\Support\Mm;
use AiCake\Support\Settings;
use AiCake\Throttle\IdentityResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * POST /aicake/v1/search      -> 200 { results: [...] }
 * POST /aicake/v1/search-pick -> 201 { design, layoutKey, preview }
 *
 * D-067. Two routes rather than one, because they are two different risks: the
 * first spends a moderation call and a search, the second downloads bytes from
 * a stranger's server and writes them to the shop's disk.
 *
 * **The browser never sends a URL.** It sends back the identifier of a result
 * it was shown, and the address that actually gets fetched comes from asking
 * Openverse about that identifier. A client-supplied URL would make this server
 * fetch whatever it was told to — including addresses inside the host's own
 * network that nothing outside can reach.
 */
class SearchEndpoint {

	/**
	 * Largest picture we will pull down.
	 *
	 * Openverse serves originals, which can be large. Six megabytes is plenty
	 * for anything that will end up on an icing sheet, and the cap is on the
	 * *transfer* so an oversized file costs bandwidth rather than memory.
	 */
	private const MAX_FETCH_BYTES = 6291456;

	/**
	 * The largest image we will decode, matching the upload boundary.
	 */
	private const MAX_EDGE_PX = 6000;

	/**
	 * Total pixels we will decode. See UploadEndpoint — same reasoning.
	 */
	private const MAX_PIXELS = 24000000;

	/**
	 * Seconds between searches from one visitor.
	 *
	 * A search costs a translation call, so it is not free — but it is cheap
	 * enough that making somebody wait to rephrase would be worse than the
	 * spend it saves.
	 */
	private const MIN_INTERVAL = 2;

	private DesignRepository $designs;

	private IdentityResolver $identity;

	private Moderator $moderator;

	private ProviderRegistry $providers;

	private OpenverseProvider $search;

	private GdEngine $images;

	private PrivateStorage $storage;

	private PreviewPipeline $previews;

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param DesignRepository  $designs   Designs.
	 * @param IdentityResolver  $identity  Identity.
	 * @param Moderator         $moderator Layers 0 and 1.
	 * @param ProviderRegistry  $providers For the translation.
	 * @param OpenverseProvider $search    The search itself.
	 * @param GdEngine          $images    Imaging.
	 * @param PrivateStorage    $storage   Files.
	 * @param PreviewPipeline   $previews  Preview building.
	 * @param Settings          $settings  Configuration.
	 * @param Logger            $logger    Logging.
	 */
	public function __construct(
		DesignRepository $designs,
		IdentityResolver $identity,
		Moderator $moderator,
		ProviderRegistry $providers,
		OpenverseProvider $search,
		GdEngine $images,
		PrivateStorage $storage,
		PreviewPipeline $previews,
		Settings $settings,
		Logger $logger
	) {
		$this->designs   = $designs;
		$this->identity  = $identity;
		$this->moderator = $moderator;
		$this->providers = $providers;
		$this->search    = $search;
		$this->images    = $images;
		$this->storage   = $storage;
		$this->previews  = $previews;
		$this->settings  = $settings;
		$this->logger    = $logger;
	}

	/**
	 * Arguments for the query route.
	 *
	 * @return array<string, mixed>
	 */
	public function query_args(): array {
		return array(
			'query' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}

	/**
	 * Arguments for the pick route.
	 *
	 * @return array<string, mixed>
	 */
	public function pick_args(): array {
		return array(
			'id'          => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'format_type' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			),
			// No `floatval` sanitiser — D-043.
			'format_mm'   => array(
				'required' => true,
				'type'     => 'number',
			),
			'product_id'  => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * HTTP methods both routes answer.
	 */
	public function methods(): string {
		return WP_REST_Server::CREATABLE;
	}

	/**
	 * Search.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_query( WP_REST_Request $request ) {
		$refused = $this->available();

		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		$cooldown = $this->cooldown();

		if ( is_wp_error( $cooldown ) ) {
			return $cooldown;
		}

		$query = $this->moderator->clean( (string) $request->get_param( 'query' ) );

		if ( '' === $query ) {
			return new WP_Error(
				'aicake_empty_query',
				$this->moderator->nonsense_message(),
				array( 'status' => 400 )
			);
		}

		/*
		 * Layers 0 and 1 on the words, before anything is spent. They cannot
		 * see the pictures that come back — that is D-060, and it is why Ruslan
		 * at the printer is the real control here — but they can still refuse
		 * somebody asking for a franchise by name, which is the most likely way
		 * this goes wrong.
		 */
		$verdict = $this->moderator->pre_check( $query );

		if ( ! $verdict->allowed_through() ) {
			$this->logger->warning(
				'Image search refused by moderation.',
				array(
					'layer' => $verdict->layer,
					'query' => $query,
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

		$this->mark_searched();

		$results = $this->search->search( $this->to_english( $query ) );

		$response = new WP_REST_Response(
			array( 'results' => $results ),
			200
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Turn a chosen result into a design.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_pick( WP_REST_Request $request ) {
		$refused = $this->available();

		if ( is_wp_error( $refused ) ) {
			return $refused;
		}

		$format = FormatCatalogue::find(
			(string) $request->get_param( 'format_type' ),
			(float) $request->get_param( 'format_mm' )
		);

		$spec = null === $format
			? null
			: FormatCatalogue::spec( (string) $format['type'], (float) $format['diameter_mm'] );

		if ( null === $format || null === $spec ) {
			return new WP_Error(
				'aicake_bad_format',
				__( 'Šio formato nebesiūlome. Pasirinkite kitą dydį.', 'ai-cake-topper' ),
				array( 'status' => 400 )
			);
		}

		// The address comes from Openverse, never from the caller.
		$found = $this->search->find( (string) $request->get_param( 'id' ) );

		if ( null === $found ) {
			return new WP_Error(
				'aicake_not_found',
				__( 'Šio paveikslėlio nebepavyko rasti. Pasirinkite kitą.', 'ai-cake-topper' ),
				array( 'status' => 404 )
			);
		}

		$bytes = $this->search->fetch( (string) $found['url'], self::MAX_FETCH_BYTES );

		if ( '' === $bytes ) {
			return new WP_Error(
				'aicake_not_found',
				__( 'Šio paveikslėlio nebepavyko atsisiųsti. Pasirinkite kitą.', 'ai-cake-topper' ),
				array( 'status' => 502 )
			);
		}

		$png = $this->safe_png( $bytes );

		unset( $bytes );

		if ( '' === $png ) {
			return new WP_Error(
				'aicake_not_an_image',
				__( 'Šio paveikslėlio panaudoti nepavyko. Pasirinkite kitą.', 'ai-cake-topper' ),
				array( 'status' => 422 )
			);
		}

		$session_key = $this->identity->session_key();

		if ( '' === $session_key ) {
			$session_key = $this->identity->issue_session_key();
		}

		$design_id = $this->designs->create(
			array(
				'session_key' => $session_key,
				'ip_hash'     => $this->identity->ip_hash(),
				'user_id'     => $this->identity->user_id() ?: null,
				'source'      => SourceCatalogue::SEARCH,
				/*
				 * What the customer asked for, kept as the prompt. Unlike an
				 * upload there genuinely is a phrase here, and moderation
				 * already read it — storing it means a shop manager looking at
				 * an order can see what was searched for.
				 */
				'prompt_raw'  => $this->moderator->clean( (string) $request->get_param( 'query' ) ),
				'aspect'      => $spec->generation_aspect(),
				'product_id'  => (int) $request->get_param( 'product_id' ) ?: null,
				'format_type' => (string) $format['type'],
				'format_mm'   => (float) $format['diameter_mm'],
				'status'      => DesignRepository::STATUS_QUEUED,
				/*
				 * The licence and the creator, kept with the design.
				 * Attribution is Ruslan's decision rather than a default this
				 * code should invent (D-067) — but whatever he decides, the
				 * facts have to still be here when he decides it, and going
				 * back to find them later means hoping the result is still
				 * there.
				 */
				'moderation'  => (string) wp_json_encode(
					array(
						'search' => array(
							'id'      => $found['id'],
							'licence' => $found['licence'],
							'creator' => $found['creator'],
							'source'  => $found['source'],
							'title'   => $found['title'],
						),
					)
				),
			)
		);

		if ( 0 === $design_id ) {
			return $this->failed();
		}

		$row       = (array) $this->designs->find( $design_id );
		$public_id = (string) $row['public_id'];

		$master_path = $this->storage->store_master( $public_id, $png );

		unset( $png );

		if ( '' === $master_path ) {
			return $this->failed();
		}

		// A found photograph has nothing around it, so the master is the picture
		// and the bleed is invented at print time (D-073).
		$preview_path = $this->previews->build(
			$master_path,
			$public_id,
			$spec,
			SourceCatalogue::master_is_bled( SourceCatalogue::SEARCH )
		);

		$this->designs->update(
			$design_id,
			array(
				'file_master'  => $master_path,
				'file_preview' => '' === $preview_path ? null : $preview_path,
				'status'       => DesignRepository::STATUS_DONE,
			)
		);

		$this->logger->info(
			'Found picture accepted.',
			array(
				'design'  => $public_id,
				'licence' => $found['licence'],
				'source'  => $found['source'],
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
	 * Lithuanian in, English out.
	 *
	 * Openverse indexes English. Sending „linksmas dinozauras" would find
	 * nothing and look like a broken feature rather than a language mismatch.
	 *
	 * The translation is the same one the generation path already makes — the
	 * text provider's `analyse()` translates and moderates in one call (D-049),
	 * so this costs nothing new. On failure the Lithuanian is used as-is: a
	 * search that finds little is better than a search that refuses.
	 *
	 * @param string $query_lt The customer's words.
	 */
	private function to_english( string $query_lt ): string {
		$provider = $this->providers->text_provider();

		if ( null === $provider || ! $provider->is_configured() ) {
			return $query_lt;
		}

		$analysis = $provider->analyse( $query_lt );

		$english = trim( (string) $analysis->prompt_en );

		return '' === $english ? $query_lt : $english;
	}

	/**
	 * Decode, check, re-encode — the same boundary uploads pass (D-062).
	 *
	 * A picture from the open internet is exactly as untrusted as one from a
	 * customer's phone, and arguably more so: nobody chose it deliberately.
	 *
	 * @param string $bytes Raw download.
	 * @return string PNG bytes, or '' if it is not usable.
	 */
	private function safe_png( string $bytes ): string {
		// Dimensions from the header, before anything decodes it.
		$info = @getimagesizefromstring( $bytes );

		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return '';
		}

		if ( ! in_array( (int) ( $info[2] ?? 0 ), array( IMAGETYPE_PNG, IMAGETYPE_JPEG ), true ) ) {
			return '';
		}

		$width  = (int) $info[0];
		$height = (int) $info[1];

		if ( $width > self::MAX_EDGE_PX || $height > self::MAX_EDGE_PX
			|| ( $width * $height ) > self::MAX_PIXELS ) {
			$this->logger->warning(
				'Found picture refused: implausible dimensions.',
				array(
					'w' => $width,
					'h' => $height,
				)
			);

			return '';
		}

		$image = $this->images->from_string( $bytes );

		if ( null === $image ) {
			return '';
		}

		$png = $this->images->to_png( $image, Mm::PRINT_DPI );

		$this->images->free( $image );

		return $png;
	}

	/**
	 * Is search switched on? (D-059)
	 *
	 * @return true|WP_Error
	 */
	private function available() {
		if ( ! SourceCatalogue::enabled( SourceCatalogue::SEARCH, $this->settings ) ) {
			return new WP_Error(
				'aicake_source_disabled',
				__( 'Šiuo metu paieška išjungta.', 'ai-cake-topper' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Something on our side went wrong.
	 */
	private function failed(): WP_Error {
		return new WP_Error(
			'aicake_storage_failed',
			__( 'Nepavyko išsaugoti paveikslėlio. Bandykite dar kartą.', 'ai-cake-topper' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Refuse a visitor searching in a loop.
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
	 * Start the cooldown.
	 */
	private function mark_searched(): void {
		set_transient( $this->cooldown_key(), 1, self::MIN_INTERVAL );
	}

	/**
	 * Per-visitor cooldown key.
	 */
	private function cooldown_key(): string {
		return 'aicake_search_' . md5( $this->identity->session_key() . '|' . $this->identity->ip_hash() );
	}
}
