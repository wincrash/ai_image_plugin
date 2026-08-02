<?php
/**
 * Replicate image generation.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Providers\Image;

use AiCake\Domain\GenerationRequest;
use AiCake\Domain\GenerationResult;
use AiCake\Providers\ImageProvider;
use AiCake\Support\HttpClient;
use AiCake\Support\HttpResponse;
use AiCake\Support\Logger;
use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Generates through Replicate's predictions API.
 *
 * This is the provider that actually runs during development, because
 * `black-forest-labs/flux-dev` is one of the few models Replicate serves with
 * no credit on the account (D-017). That access is undocumented and can stop
 * at any time, which is exactly why everything goes through the registry's
 * fallback chain rather than calling this class directly.
 *
 * Replicate is asynchronous by design: creating a prediction returns a handle
 * to poll. `Prefer: wait` collapses that into one request, which is what the
 * admin test screen wants. Phase 3's job system wants the polling form, so
 * both are exposed here rather than retrofitted later.
 */
class ReplicateProvider implements ImageProvider {

	private const API = 'https://api.replicate.com/v1';

	public const DEFAULT_MODEL = 'black-forest-labs/flux-dev';

	/**
	 * Seconds to hold the connection open waiting for the prediction.
	 * Replicate caps this at 60; we stay under our own HTTP timeout.
	 */
	private const SYNC_WAIT = 55;

	/**
	 * Ratios flux-dev accepts. Other model families differ — see build_input().
	 *
	 * @var string[]
	 */
	private const ASPECTS = array( '1:1', '16:9', '21:9', '3:2', '2:3', '4:5', '5:4', '3:4', '4:3', '9:16', '9:21' );

	private HttpClient $http;

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param HttpClient $http     Transport.
	 * @param Settings   $settings Configuration.
	 * @param Logger     $logger   Logging.
	 */
	public function __construct( HttpClient $http, Settings $settings, Logger $logger ) {
		$this->http     = $http;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Stable identifier.
	 */
	public function id(): string {
		return 'replicate';
	}

	/**
	 * The configured model.
	 */
	public function model(): string {
		$model = (string) $this->settings->get( 'replicate_model', self::DEFAULT_MODEL );

		return '' === $model ? self::DEFAULT_MODEL : $model;
	}

	/**
	 * Whether a token is present.
	 */
	public function is_configured(): bool {
		return $this->settings->has_secret( 'replicate' );
	}

	/**
	 * Aspect ratios accepted.
	 *
	 * @return string[]
	 */
	public function supported_aspects(): array {
		return self::ASPECTS;
	}

	/**
	 * flux-dev takes a ratio plus a megapixel target, not explicit pixels.
	 */
	public function supports_arbitrary_dimensions(): bool {
		return false;
	}

	/**
	 * Published list price per image, in USD.
	 *
	 * Deliberately the list price even while the account is unfunded and these
	 * calls are actually free. The API gives no way to tell whether a given
	 * prediction was billed, over-recording is the safe direction for a spend
	 * guard, and the figure becomes exactly right the moment credit is added.
	 *
	 * @param float $megapixels Output size.
	 */
	public function estimate_cost( float $megapixels ): float {
		$per_image = array(
			'black-forest-labs/flux-dev'      => 0.025,
			'black-forest-labs/flux-schnell'  => 0.003,
			'black-forest-labs/flux-1.1-pro'  => 0.04,
			'black-forest-labs/flux-2-pro'    => 0.04,
		);

		$base = $per_image[ $this->model() ] ?? 0.025;

		return round( $base * max( 1.0, $megapixels ), 5 );
	}

	/**
	 * Generate one image, waiting for the result.
	 *
	 * @param GenerationRequest $request What to draw.
	 */
	public function generate( GenerationRequest $request ): GenerationResult {
		$started = microtime( true );

		if ( ! $this->is_configured() ) {
			return GenerationResult::failure(
				'not_configured',
				sprintf( 'AICAKE_REPLICATE_KEY is not defined (%s).', $this->id() ),
				array( 'provider' => $this->id() )
			);
		}

		$response = $this->http->request(
			'POST',
			self::API . '/models/' . $this->model() . '/predictions',
			array(
				'headers' => $this->headers( array( 'Prefer' => 'wait=' . self::SYNC_WAIT ) ),
				'json'    => array( 'input' => $this->build_input( $request ) ),
				'timeout' => self::SYNC_WAIT + 10,
				// Creating a prediction is not idempotent: a retry can produce
				// a second billable image. Let the registry fall through
				// instead.
				'retries' => 0,
			)
		);

		if ( ! $response->ok() ) {
			return $this->error_result( $response, $started );
		}

		$prediction = $response->json();

		if ( null === $prediction ) {
			return GenerationResult::failure( 'bad_response', 'Replicate returned a non-JSON body.', $this->meta( $started ) );
		}

		// `Prefer: wait` usually returns a finished prediction, but it times
		// out on a slow model and hands back a still-running one.
		if ( ! $this->is_terminal( (string) ( $prediction['status'] ?? '' ) ) ) {
			$prediction = $this->wait_for( $prediction, $started );
		}

		return $this->result_from_prediction( $prediction, $started );
	}

	/**
	 * Create a prediction without waiting. For Phase 3's job system.
	 *
	 * @param GenerationRequest $request What to draw.
	 * @return string Prediction id, or '' on failure.
	 */
	public function start( GenerationRequest $request ): string {
		if ( ! $this->is_configured() ) {
			return '';
		}

		$response = $this->http->request(
			'POST',
			self::API . '/models/' . $this->model() . '/predictions',
			array(
				'headers' => $this->headers(),
				'json'    => array( 'input' => $this->build_input( $request ) ),
				'retries' => 0,
			)
		);

		if ( ! $response->ok() ) {
			$this->logger->error( 'Replicate prediction could not be started.', array( 'detail' => $response->describe() ) );

			return '';
		}

		return (string) ( $response->json()['id'] ?? '' );
	}

	/**
	 * Check a prediction once.
	 *
	 * @param string $prediction_id Handle from start().
	 * @return GenerationResult|null Null while the prediction is still running.
	 */
	public function poll( string $prediction_id ): ?GenerationResult {
		$started = microtime( true );

		$response = $this->http->request(
			'GET',
			self::API . '/predictions/' . rawurlencode( $prediction_id ),
			array( 'headers' => $this->headers() )
		);

		if ( ! $response->ok() ) {
			return $this->error_result( $response, $started );
		}

		$prediction = $response->json();

		if ( null === $prediction ) {
			return GenerationResult::failure( 'bad_response', 'Replicate returned a non-JSON body.', $this->meta( $started ) );
		}

		if ( ! $this->is_terminal( (string) ( $prediction['status'] ?? '' ) ) ) {
			return null;
		}

		return $this->result_from_prediction( $prediction, $started );
	}

	/**
	 * Poll until the prediction finishes or we run out of patience.
	 *
	 * Only reachable from generate(), which is admin-facing. Nothing on the
	 * customer path may block a worker like this — that is what Phase 3 exists
	 * for (PLAN.md §6).
	 *
	 * @param array<string, mixed> $prediction Prediction as first returned.
	 * @param float                $started    microtime when the call began.
	 * @return array<string, mixed>
	 */
	private function wait_for( array $prediction, float $started ): array {
		$url      = (string) ( $prediction['urls']['get'] ?? '' );
		$deadline = microtime( true ) + 60;

		if ( '' === $url ) {
			return $prediction;
		}

		while ( microtime( true ) < $deadline ) {
			sleep( 2 );

			$response = $this->http->request( 'GET', $url, array( 'headers' => $this->headers() ) );

			if ( ! $response->ok() ) {
				break;
			}

			$latest = $response->json();

			if ( null === $latest ) {
				break;
			}

			$prediction = $latest;

			if ( $this->is_terminal( (string) ( $prediction['status'] ?? '' ) ) ) {
				break;
			}
		}

		unset( $started );

		return $prediction;
	}

	/**
	 * Turn a finished prediction into a result, downloading the image.
	 *
	 * @param array<string, mixed> $prediction Terminal prediction.
	 * @param float                $started    microtime when the call began.
	 */
	private function result_from_prediction( array $prediction, float $started ): GenerationResult {
		$status = (string) ( $prediction['status'] ?? '' );

		if ( 'succeeded' !== $status ) {
			$detail = (string) ( $prediction['error'] ?? $status );

			return GenerationResult::failure(
				$this->looks_like_content_refusal( $detail ) ? 'content_rejected' : 'provider_error',
				'' === $detail ? 'Replicate prediction did not succeed.' : $detail,
				$this->meta( $started )
			);
		}

		$output = $prediction['output'] ?? null;
		$url    = is_array( $output ) ? (string) ( $output[0] ?? '' ) : (string) $output;

		if ( '' === $url ) {
			return GenerationResult::failure( 'empty_output', 'Replicate returned no image URL.', $this->meta( $started ) );
		}

		// The provider hands back a short-lived URL. Fetch the bytes now: an
		// expiring URL must never reach the database.
		$image = $this->http->request( 'GET', $url, array( 'timeout' => 60 ) );

		if ( ! $image->ok() || '' === $image->body ) {
			return GenerationResult::failure( 'download_failed', 'Could not download the generated image: ' . $image->describe(), $this->meta( $started ) );
		}

		return GenerationResult::success(
			$image->body,
			array(
				'provider'   => $this->id(),
				'model'      => $this->model(),
				'seed'       => (int) ( $prediction['input']['seed'] ?? 0 ),
				'cost_usd'   => $this->estimate_cost( 1.0 ),
				'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
			)
		);
	}

	/**
	 * Map a transport-level failure onto a stable error code.
	 *
	 * @param HttpResponse $response The failed response.
	 * @param float        $started  microtime when the call began.
	 */
	private function error_result( HttpResponse $response, float $started ): GenerationResult {
		$codes = array(
			401 => 'auth',
			402 => 'billing',
			404 => 'unknown_model',
			422 => 'invalid_input',
			429 => 'rate_limited',
		);

		$code = $codes[ $response->status ] ?? 'provider_error';

		if ( 'billing' === $code ) {
			$this->logger->warning(
				'Replicate refused the call for lack of credit — this model is not in the free set (D-017).',
				array( 'model' => $this->model() )
			);
		}

		return GenerationResult::failure( $code, $response->describe(), $this->meta( $started ) );
	}

	/**
	 * Provider input for one request.
	 *
	 * Shaped for the FLUX family, which is what we run. A different model
	 * family would need its own mapping; that is a deliberate limit rather
	 * than an oversight, and the registry is where a second family would be
	 * introduced.
	 *
	 * @param GenerationRequest $request What to draw.
	 * @return array<string, mixed>
	 */
	private function build_input( GenerationRequest $request ): array {
		$aspect = in_array( $request->aspect, self::ASPECTS, true ) ? $request->aspect : '1:1';

		$input = array(
			'prompt'              => $request->prompt_en,
			'aspect_ratio'        => $aspect,
			'output_format'       => 'webp' === $request->output_format ? 'webp' : 'png',
			'num_outputs'         => 1,
			'megapixels'          => $request->megapixels >= 1.0 ? '1' : '0.25',
			'go_fast'             => true,
			'guidance'            => 3.5,
			'num_inference_steps' => 28,
		);

		if ( null !== $request->seed ) {
			$input['seed'] = $request->seed;
		}

		return $input;
	}

	/**
	 * Request headers.
	 *
	 * @param array<string, string> $extra Additional headers.
	 * @return array<string, string>
	 */
	private function headers( array $extra = array() ): array {
		return array_merge(
			array(
				'Authorization' => 'Bearer ' . $this->settings->secret( 'replicate' ),
				'Content-Type'  => 'application/json',
			),
			$extra
		);
	}

	/**
	 * Whether a prediction status means "no longer changing".
	 *
	 * @param string $status Replicate status.
	 */
	private function is_terminal( string $status ): bool {
		return in_array( $status, array( 'succeeded', 'failed', 'canceled' ), true );
	}

	/**
	 * Whether an error message looks like a safety refusal rather than a fault.
	 *
	 * Worth separating: the registry retries a fault on another provider, but
	 * a refused prompt will be refused everywhere, so falling through just
	 * spends more money to be told the same thing.
	 *
	 * @param string $message Provider error text.
	 */
	private function looks_like_content_refusal( string $message ): bool {
		foreach ( array( 'nsfw', 'safety', 'flagged', 'sensitive' ) as $needle ) {
			if ( false !== stripos( $message, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Common failure metadata.
	 *
	 * @param float $started microtime when the call began.
	 * @return array<string, mixed>
	 */
	private function meta( float $started ): array {
		return array(
			'provider'   => $this->id(),
			'model'      => $this->model(),
			'latency_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);
	}
}
