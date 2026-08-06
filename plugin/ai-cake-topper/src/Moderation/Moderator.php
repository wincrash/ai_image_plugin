<?php
/**
 * The moderation layers, in order.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Moderation;

use AiCake\Domain\PromptAnalysis;
use AiCake\Providers\ProviderRegistry;
use AiCake\Support\Logger;
use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md §10: "the ordering matters — each layer is cheaper than the next".
 *
 * The split between the two public methods is not arbitrary. Layers 0 and 1
 * are free and instant, so they run inside the customer's request and give
 * immediate feedback. Layer 2 costs money and about 800 ms, so it runs in the
 * job where nothing is waiting on it (§6.1).
 *
 * Layer 3 — a human looking at the rendered image before it prints — is not
 * here because it is not automatable. It is also no longer software: D-047
 * deleted the review queue, because Ruslan already sees every image when he
 * loads the icing sheet. The control is real, it is just outside this class.
 *
 * All three layers here are switchable (D-049). The shop, not the plugin,
 * decides how much screening it wants.
 */
class Moderator {

	/**
	 * Verdicts are cached by prompt hash (§10). Repeat prompts are common —
	 * customers retry, and popular requests recur across customers — and a
	 * cached verdict is a call not made.
	 */
	private const CACHE_TTL = DAY_IN_SECONDS;

	private Sanitiser $sanitiser;

	private Blocklist $blocklist;

	private ProviderRegistry $providers;

	private Logger $logger;

	private Settings $settings;

	/**
	 * Setting key per layer, for enabled().
	 */
	public const LAYERS = array(
		'sanity'    => 'moderation_sanity',
		'blocklist' => 'moderation_blocklist',
		'ai'        => 'moderation_ai',
	);

	/**
	 * @param Sanitiser        $sanitiser Layer 0.
	 * @param Blocklist        $blocklist Layer 1.
	 * @param ProviderRegistry $providers Layer 2.
	 * @param Logger           $logger    Logging.
	 * @param Settings         $settings  Which layers are switched on.
	 */
	public function __construct(
		Sanitiser $sanitiser,
		Blocklist $blocklist,
		ProviderRegistry $providers,
		Logger $logger,
		Settings $settings
	) {
		$this->sanitiser = $sanitiser;
		$this->blocklist = $blocklist;
		$this->providers = $providers;
		$this->logger    = $logger;
		$this->settings  = $settings;
	}

	/**
	 * Whether a layer is switched on.
	 *
	 * @param string $layer One of the LAYERS keys.
	 */
	public function enabled( string $layer ): bool {
		$key = self::LAYERS[ $layer ] ?? null;

		return null === $key ? false : (bool) $this->settings->get( $key, true );
	}

	/**
	 * Clean a prompt for storage and display.
	 *
	 * @param string $prompt Raw input.
	 */
	public function clean( string $prompt ): string {
		return $this->sanitiser->clean( $prompt );
	}

	/**
	 * Layers 0 and 1. Free, instant, safe to run in a web request.
	 *
	 * @param string $prompt Cleaned prompt.
	 */
	public function pre_check( string $prompt ): Verdict {
		/*
		 * Switching layer 0 off does not let an empty prompt reach a paid
		 * generation: the endpoints refuse '' themselves, because a request
		 * with nothing in it is a broken client rather than a judgement call.
		 * What this setting turns off is the *opinion* — gibberish, no vowels,
		 * a repeated character.
		 */
		if ( $this->enabled( 'sanity' ) ) {
			$sanity = $this->sanitiser->check( $prompt );

			if ( ! $sanity->allowed_through() ) {
				return $sanity;
			}
		}

		if ( ! $this->enabled( 'blocklist' ) ) {
			return Verdict::allowed( 'blocklist' );
		}

		$blocked = $this->blocklist->check( $prompt );

		if ( ! $blocked->allowed_through() ) {
			$this->logger->info(
				'Blocklist refused a prompt.',
				array(
					'reason' => $blocked->reason,
					// The prompt itself is logged against the design row, not
					// here — this line goes to a file that may be shared.
					'length' => mb_strlen( $prompt ),
				)
			);
		}

		return $blocked;
	}

	/**
	 * Layer 2. Costs money and latency, so only the job calls it.
	 *
	 * Switching this layer off does **not** skip the call. The same request
	 * translates the prompt into English (§10 Layer 2 is "translate and
	 * classify" in one call), and the image providers need that translation —
	 * flux draws Lithuanian badly. So off means *the verdict stops being
	 * binding*: it is still asked for, still recorded on the design row for
	 * whoever looks later, and simply no longer refuses anything.
	 *
	 * @param string $prompt Cleaned prompt that already passed pre_check().
	 */
	public function analyse( string $prompt ): PromptAnalysis {
		$analysis = $this->analyse_raw( $prompt );

		if ( $this->enabled( 'ai' ) || ! $analysis->ok() ) {
			return $analysis;
		}

		if ( ! $analysis->blocked() ) {
			return $analysis;
		}

		$overridden          = clone $analysis;
		$overridden->verdict = PromptAnalysis::ALLOW;
		$overridden->reasons = array_merge( $analysis->reasons, array( 'ai_layer_disabled' ) );

		/*
		 * The classifier is told it may leave prompt_en empty when it blocks,
		 * and Google's own safety filter returns nothing at all. Overriding
		 * the verdict without a translation would send an empty prompt to a
		 * provider that charges $0.012 for it, so fall back to the Lithuanian.
		 * The picture will be worse. That is the cost of the switch, and it is
		 * logged so nobody has to guess why.
		 */
		if ( '' === $overridden->prompt_en ) {
			$overridden->prompt_en = $prompt;

			$this->logger->warning(
				'AI moderation is off and the classifier returned no translation; generating from the Lithuanian prompt.',
				array( 'reasons' => $analysis->reasons )
			);
		}

		return $overridden;
	}

	/**
	 * The layer 2 call itself, verdict untouched.
	 *
	 * @param string $prompt Cleaned prompt.
	 */
	private function analyse_raw( string $prompt ): PromptAnalysis {
		$key    = 'aicake_mod_' . md5( LtNormaliser::fold( $prompt ) );
		$cached = get_transient( $key );

		if ( is_array( $cached ) ) {
			$analysis = PromptAnalysis::from_response(
				$cached,
				array(
					'provider' => (string) ( $cached['provider'] ?? '' ),
					'model'    => (string) ( $cached['model'] ?? '' ),
					// A cached verdict cost nothing this time round.
					'cost_usd' => 0.0,
				)
			);

			return $analysis;
		}

		$analysis = $this->providers->analyse( $prompt );

		// Only cache a real answer. Caching a transport failure would turn one
		// bad minute into a bad day, and the plugin fails closed.
		if ( $analysis->ok() ) {
			set_transient(
				$key,
				array(
					'prompt_en'  => $analysis->prompt_en,
					'verdict'    => $analysis->verdict,
					'reasons'    => $analysis->reasons,
					'categories' => $analysis->categories,
					'confidence' => $analysis->confidence,
					'provider'   => $analysis->provider,
					'model'      => $analysis->model,
				),
				self::CACHE_TTL
			);
		}

		return $analysis;
	}

	/**
	 * What to tell the customer when a prompt is refused.
	 *
	 * Never names the matched term or the layer. Telling someone exactly which
	 * word tripped the filter is an invitation to work around it, and the
	 * phrasing has to leave a route forward rather than sounding like an
	 * accusation.
	 */
	public function rejection_message(): string {
		return __(
			'Šio aprašymo negalime panaudoti. Pabandykite aprašyti savais žodžiais, be žinomų personažų, prekių ženklų ar tikrų žmonių.',
			'ai-cake-topper'
		);
	}

	/**
	 * What to tell the customer when the prompt made no sense.
	 */
	public function nonsense_message(): string {
		return __( 'Parašykite, ką norite pavaizduoti ant torto.', 'ai-cake-topper' );
	}

	/**
	 * The blocklist, for the admin screen.
	 */
	public function blocklist(): Blocklist {
		return $this->blocklist;
	}
}
