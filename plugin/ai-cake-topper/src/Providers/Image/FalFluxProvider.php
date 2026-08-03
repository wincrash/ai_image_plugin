<?php
/**
 * fal.ai FLUX image generation.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Providers\Image;

use AiCake\Domain\GenerationRequest;
use AiCake\Domain\GenerationResult;
use AiCake\Providers\ImageProvider;
use AiCake\Support\HttpClient;
use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * fal.ai, the provider PLAN.md §8 picked before anything was measured, and
 * now the funded primary (D-030).
 *
 * Topping the account up turned this on with no code change, which is the
 * whole point of the interface: 992×992 PNG in 4.7 s on the first real call.
 * fal alone covers both Suite A and Suite B when Phase 0 is eventually run
 * (D-017, D-018).
 */
class FalFluxProvider implements ImageProvider {

	private const API = 'https://fal.run/';

	public const DEFAULT_MODEL = 'fal-ai/flux/dev';

	private HttpClient $http;

	private Settings $settings;

	/**
	 * @param HttpClient $http     Transport.
	 * @param Settings   $settings Configuration.
	 */
	public function __construct( HttpClient $http, Settings $settings ) {
		$this->http     = $http;
		$this->settings = $settings;
	}

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return 'fal';
	}

	/**
	 * The configured model.
	 */
	public function model(): string {
		$model = (string) $this->settings->get( 'fal_model', self::DEFAULT_MODEL );

		return '' === $model ? self::DEFAULT_MODEL : $model;
	}

	/**
	 * Whether a key is present. Says nothing about the balance.
	 */
	public function is_configured(): bool {
		return $this->settings->has_secret( 'fal' );
	}

	/**
	 * Aspect ratios accepted, as fal's named sizes map onto them.
	 *
	 * @return string[]
	 */
	public function supported_aspects(): array {
		return array( '1:1', '3:4', '4:3', '9:16', '16:9', '2:3', '3:2' );
	}

	/**
	 * fal accepts explicit width and height, which is one of the reasons
	 * PLAN.md §8 favoured it.
	 */
	public function supports_arbitrary_dimensions(): bool {
		return true;
	}

	/**
	 * Published price per megapixel, in USD.
	 *
	 * @param float $megapixels Output size.
	 */
	public function estimate_cost( float $megapixels ): float {
		$per_mp = array(
			'fal-ai/flux/schnell' => 0.003,
			'fal-ai/flux/dev'     => 0.012,
			'fal-ai/flux-pro'     => 0.03,
		);

		return round( ( $per_mp[ $this->model() ] ?? 0.012 ) * max( 0.25, $megapixels ), 5 );
	}

	/**
	 * Generate one image.
	 *
	 * @param GenerationRequest $request What to draw.
	 */
	public function generate( GenerationRequest $request ): GenerationResult {
		$started = microtime( true );
		$meta    = array(
			'provider' => $this->id(),
			'model'    => $this->model(),
		);

		if ( ! $this->is_configured() ) {
			return GenerationResult::failure( 'not_configured', 'AICAKE_FAL_KEY is not defined.', $meta );
		}

		list( $width, $height ) = $request->dimensions();

		$payload = array(
			'prompt'           => $request->prompt_en,
			'image_size'       => array(
				'width'  => $width,
				'height' => $height,
			),
			'num_images'       => 1,
			'output_format'    => 'webp' === $request->output_format ? 'webp' : 'png',
			'num_inference_steps' => 28,
			'guidance_scale'   => 3.5,
			'enable_safety_checker' => true,
		);

		if ( null !== $request->seed ) {
			$payload['seed'] = $request->seed;
		}

		$response = $this->http->request(
			'POST',
			self::API . $this->model(),
			array(
				'headers' => array(
					'Authorization' => 'Key ' . $this->settings->secret( 'fal' ),
					'Content-Type'  => 'application/json',
				),
				'json'    => $payload,
				'timeout' => 90,
				// Not idempotent: a retry is a second billable image.
				'retries' => 0,
			)
		);

		$meta['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! $response->ok() ) {
			$codes = array(
				401 => 'auth',
				403 => 'billing',
				404 => 'unknown_model',
				422 => 'invalid_input',
				429 => 'rate_limited',
			);

			return GenerationResult::failure( $codes[ $response->status ] ?? 'provider_error', $response->describe(), $meta );
		}

		$body = $response->json();
		$url  = (string) ( $body['images'][0]['url'] ?? '' );

		if ( '' === $url ) {
			$flagged = ! empty( $body['has_nsfw_concepts'][0] );

			return GenerationResult::failure(
				$flagged ? 'content_rejected' : 'empty_output',
				$flagged ? 'fal rejected the prompt on safety grounds.' : 'fal returned no image URL.',
				$meta
			);
		}

		$image = $this->http->request( 'GET', $url, array( 'timeout' => 60 ) );

		if ( ! $image->ok() || '' === $image->body ) {
			return GenerationResult::failure( 'download_failed', 'Could not download the generated image: ' . $image->describe(), $meta );
		}

		return GenerationResult::success(
			$image->body,
			array_merge(
				$meta,
				array(
					'seed'     => (int) ( $body['seed'] ?? 0 ),
					'cost_usd' => $this->estimate_cost( $request->megapixels ),
				)
			)
		);
	}
}
