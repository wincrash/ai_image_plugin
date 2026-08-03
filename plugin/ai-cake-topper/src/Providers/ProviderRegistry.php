<?php
/**
 * Provider resolution and fallback.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Providers;

use AiCake\Domain\GenerationRequest;
use AiCake\Domain\GenerationResult;
use AiCake\Domain\PromptAnalysis;
use AiCake\Support\Logger;
use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the primary → fallback chain from settings (PLAN.md §8.5).
 *
 * On timeout, 5xx or a malformed response it falls through to the next
 * provider and records which one actually served the request, so cost and
 * quality can be compared later from the designs table rather than from
 * anyone's memory.
 *
 * This is also what makes free Replicate access safe to build on: when it
 * stops working, the chain moves to whichever provider is funded, and nothing
 * above this class has to know it happened (D-017).
 */
class ProviderRegistry {

	/**
	 * fal first: it is the funded account, and the only one whose access we
	 * are entitled to rely on. Replicate sits behind it as a fallback rather
	 * than a dependency — its free tier is undocumented and already withdrew
	 * once mid-session (D-017, D-022, D-030). Reordering this is a settings
	 * change.
	 *
	 * @var string[]
	 */
	private const DEFAULT_IMAGE_ORDER = array( 'fal', 'replicate', 'gemini-image' );

	private Settings $settings;

	private Logger $logger;

	/**
	 * @var array<string, ImageProvider>
	 */
	private array $image_providers = array();

	/**
	 * @var array<string, TextProvider>
	 */
	private array $text_providers = array();

	/**
	 * @var array<string, UpscaleProvider>
	 */
	private array $upscalers = array();

	/**
	 * What the last generate() call actually tried, for the admin screen.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $last_attempts = array();

	/**
	 * @param Settings $settings Configuration.
	 * @param Logger   $logger   Logging.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * @param ImageProvider $provider Image generator.
	 */
	public function add_image_provider( ImageProvider $provider ): void {
		$this->image_providers[ $provider->id() ] = $provider;
	}

	/**
	 * @param TextProvider $provider Translator and classifier.
	 */
	public function add_text_provider( TextProvider $provider ): void {
		$this->text_providers[ $provider->id() ] = $provider;
	}

	/**
	 * @param UpscaleProvider $provider Upscaler.
	 */
	public function add_upscaler( UpscaleProvider $provider ): void {
		$this->upscalers[ $provider->id() ] = $provider;
	}

	/**
	 * Every registered image provider, in configured order.
	 *
	 * @return ImageProvider[]
	 */
	public function image_providers(): array {
		$order = (array) $this->settings->get( 'image_provider_order', self::DEFAULT_IMAGE_ORDER );
		$out   = array();

		foreach ( $order as $id ) {
			if ( isset( $this->image_providers[ $id ] ) ) {
				$out[] = $this->image_providers[ $id ];
			}
		}

		// Anything registered but not named in the order still belongs at the
		// end, so adding a provider does not silently do nothing.
		foreach ( $this->image_providers as $id => $provider ) {
			if ( ! in_array( $id, $order, true ) ) {
				$out[] = $provider;
			}
		}

		return $out;
	}

	/**
	 * One image provider by id.
	 *
	 * @param string $id Provider identifier.
	 */
	public function image_provider( string $id ): ?ImageProvider {
		return $this->image_providers[ $id ] ?? null;
	}

	/**
	 * The translator and classifier.
	 */
	public function text_provider(): ?TextProvider {
		$preferred = (string) $this->settings->get( 'text_provider', 'gemini-text' );

		if ( isset( $this->text_providers[ $preferred ] ) && $this->text_providers[ $preferred ]->is_configured() ) {
			return $this->text_providers[ $preferred ];
		}

		foreach ( $this->text_providers as $provider ) {
			if ( $provider->is_configured() ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * The upscaler.
	 *
	 * Defaults to GD, which is free and is the only one production is
	 * guaranteed to have (D-015).
	 */
	public function upscaler(): ?UpscaleProvider {
		$preferred = (string) $this->settings->get( 'upscaler', 'gd-bicubic' );

		if ( isset( $this->upscalers[ $preferred ] ) && $this->upscalers[ $preferred ]->is_configured() ) {
			return $this->upscalers[ $preferred ];
		}

		foreach ( $this->upscalers as $provider ) {
			if ( $provider->is_configured() ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Generate, walking the chain until one provider succeeds.
	 *
	 * @param GenerationRequest $request What to draw.
	 */
	public function generate( GenerationRequest $request ): GenerationResult {
		$this->last_attempts = array();

		$providers = array_filter(
			$this->image_providers(),
			static fn( ImageProvider $p ): bool => $p->is_configured()
		);

		if ( array() === $providers ) {
			return GenerationResult::failure( 'no_provider', 'No image provider is configured.' );
		}

		$last = null;

		foreach ( $providers as $provider ) {
			$result = $provider->generate( $request );

			$this->last_attempts[] = array(
				'provider' => $provider->id(),
				'model'    => $provider->model(),
				'ok'       => $result->ok,
				'code'     => $result->error_code,
				'error'    => $result->error,
				'ms'       => $result->latency_ms,
			);

			if ( $result->ok ) {
				if ( null !== $last ) {
					$this->logger->info(
						'Image served by a fallback provider.',
						array(
							'served_by' => $provider->id(),
							'attempts'  => count( $this->last_attempts ),
						)
					);
				}

				return $result;
			}

			$last = $result;

			// A refused prompt will be refused everywhere. Falling through
			// would spend more money to be told the same thing.
			if ( ! $result->should_fall_through() ) {
				break;
			}

			$this->logger->warning(
				'Image provider failed; trying the next one.',
				array(
					'provider' => $provider->id(),
					'code'     => $result->error_code,
					'detail'   => $result->error,
				)
			);
		}

		return $last ?? GenerationResult::failure( 'no_provider', 'No image provider produced a result.' );
	}

	/**
	 * Translate and classify, failing closed when nothing is configured.
	 *
	 * @param string $prompt_lt The customer's text.
	 */
	public function analyse( string $prompt_lt ): PromptAnalysis {
		$provider = $this->text_provider();

		if ( null === $provider ) {
			return PromptAnalysis::failed( 'No translation or moderation provider is configured.' );
		}

		return $provider->analyse( $prompt_lt );
	}

	/**
	 * What the last generate() call tried, in order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function last_attempts(): array {
		return $this->last_attempts;
	}
}
