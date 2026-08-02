<?php
/**
 * Gemini image generation.
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
 * Google's image models.
 *
 * Written to the interface but **not currently callable**: image generation is
 * explicitly zero on Google's free tier — the API answers `429 …
 * free_tier_requests, limit: 0` — while the text models on the same key are
 * free. The models exist on the key; they are billing-gated, not missing
 * (D-017). Enabling pay-as-you-go on the Google account turns this on with no
 * code change.
 */
class GeminiImageProvider implements ImageProvider {

	private const API = 'https://generativelanguage.googleapis.com/v1beta/models/';

	public const DEFAULT_MODEL = 'gemini-3.1-flash-image';

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
		return 'gemini-image';
	}

	/**
	 * The configured model.
	 */
	public function model(): string {
		$model = (string) $this->settings->get( 'gemini_image_model', self::DEFAULT_MODEL );

		return '' === $model ? self::DEFAULT_MODEL : $model;
	}

	/**
	 * Whether a key is present. Says nothing about whether billing allows it.
	 */
	public function is_configured(): bool {
		return $this->settings->has_secret( 'gemini' );
	}

	/**
	 * Aspect ratios accepted.
	 *
	 * @return string[]
	 */
	public function supported_aspects(): array {
		return array( '1:1', '3:4', '4:3', '9:16', '16:9', '2:3', '3:2' );
	}

	/**
	 * Ratio only.
	 */
	public function supports_arbitrary_dimensions(): bool {
		return false;
	}

	/**
	 * Published price per 1K image, in USD.
	 *
	 * @param float $megapixels Output size.
	 */
	public function estimate_cost( float $megapixels ): float {
		$per_image = array(
			'gemini-3.1-flash-image'      => 0.067,
			'gemini-3.1-flash-lite-image' => 0.0336,
		);

		return round( ( $per_image[ $this->model() ] ?? 0.067 ) * max( 1.0, $megapixels ), 5 );
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
			return GenerationResult::failure( 'not_configured', 'AICAKE_GEMINI_KEY is not defined.', $meta );
		}

		$aspect = in_array( $request->aspect, $this->supported_aspects(), true ) ? $request->aspect : '1:1';

		$response = $this->http->request(
			'POST',
			self::API . rawurlencode( $this->model() ) . ':generateContent?key=' . rawurlencode( $this->settings->secret( 'gemini' ) ),
			array(
				'json'    => array(
					'contents'         => array(
						array(
							'role'  => 'user',
							'parts' => array( array( 'text' => $request->prompt_en ) ),
						),
					),
					'generationConfig' => array(
						'responseModalities' => array( 'IMAGE' ),
						'imageConfig'        => array( 'aspectRatio' => $aspect ),
					),
				),
				'timeout' => 90,
				'retries' => 0,
			)
		);

		$meta['latency_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( ! $response->ok() ) {
			$code = 429 === $response->status ? 'quota' : ( 403 === $response->status ? 'billing' : 'provider_error' );

			return GenerationResult::failure( $code, $response->describe(), $meta );
		}

		$body = $response->json();

		if ( null === $body ) {
			return GenerationResult::failure( 'bad_response', 'Gemini returned a non-JSON body.', $meta );
		}

		$encoded = '';

		foreach ( (array) ( $body['candidates'][0]['content']['parts'] ?? array() ) as $part ) {
			if ( isset( $part['inlineData']['data'] ) ) {
				$encoded = (string) $part['inlineData']['data'];
				break;
			}
		}

		if ( '' === $encoded ) {
			$reason = (string) ( $body['candidates'][0]['finishReason'] ?? 'no image part' );

			return GenerationResult::failure(
				in_array( $reason, array( 'SAFETY', 'PROHIBITED_CONTENT' ), true ) ? 'content_rejected' : 'empty_output',
				'Gemini returned no image (' . $reason . ').',
				$meta
			);
		}

		$bytes = base64_decode( $encoded, true );

		if ( false === $bytes || '' === $bytes ) {
			return GenerationResult::failure( 'bad_response', 'Gemini returned undecodable image data.', $meta );
		}

		return GenerationResult::success(
			$bytes,
			array_merge(
				$meta,
				array( 'cost_usd' => $this->estimate_cost( $request->megapixels ) )
			)
		);
	}
}
