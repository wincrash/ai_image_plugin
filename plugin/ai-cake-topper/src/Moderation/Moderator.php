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
 * here because it is not automatable. It is the review queue, and it is
 * non-negotiable.
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

	/**
	 * @param Sanitiser        $sanitiser Layer 0.
	 * @param Blocklist        $blocklist Layer 1.
	 * @param ProviderRegistry $providers Layer 2.
	 * @param Logger           $logger    Logging.
	 */
	public function __construct(
		Sanitiser $sanitiser,
		Blocklist $blocklist,
		ProviderRegistry $providers,
		Logger $logger
	) {
		$this->sanitiser = $sanitiser;
		$this->blocklist = $blocklist;
		$this->providers = $providers;
		$this->logger    = $logger;
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
		$sanity = $this->sanitiser->check( $prompt );

		if ( ! $sanity->allowed_through() ) {
			return $sanity;
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
	 * @param string $prompt Cleaned prompt that already passed pre_check().
	 */
	public function analyse( string $prompt ): PromptAnalysis {
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
