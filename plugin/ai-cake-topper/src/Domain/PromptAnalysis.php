<?php
/**
 * Translation and moderation verdict for one prompt.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The result of the combined translate + moderate call (PLAN.md §10 Layer 2).
 *
 * Three verdicts, not two. `review` still generates a preview but flags the
 * order for mandatory human attention — it avoids frustrating false-positive
 * rejections on innocent prompts while still catching ambiguous ones.
 */
class PromptAnalysis {

	public const ALLOW  = 'allow';
	public const REVIEW = 'review';
	public const BLOCK  = 'block';

	/**
	 * The six category flags from §10, all defaulting to false.
	 */
	public const CATEGORIES = array(
		'copyright_character',
		'real_person',
		'brand_logo',
		'sexual',
		'violence',
		'hate_symbol',
	);

	/**
	 * @param string        $prompt_en   English translation.
	 * @param string        $verdict     allow | review | block.
	 * @param string[]      $reasons     Why, for the rejection log.
	 * @param array<string, bool> $categories Category flags.
	 * @param float         $confidence  0..1.
	 * @param string        $provider    Provider id.
	 * @param string        $model       Model identifier.
	 * @param float         $cost_usd    What we believe this cost.
	 * @param int           $latency_ms  Wall clock.
	 * @param string        $error       Failure description, '' when the call worked.
	 */
	public function __construct(
		public string $prompt_en = '',
		public string $verdict = self::BLOCK,
		public array $reasons = array(),
		public array $categories = array(),
		public float $confidence = 0.0,
		public string $provider = '',
		public string $model = '',
		public float $cost_usd = 0.0,
		public int $latency_ms = 0,
		public string $error = ''
	) {}

	/**
	 * Build from a decoded provider response.
	 *
	 * Anything unexpected collapses to `block`: PLAN.md §10 requires that a
	 * malformed response never becomes an `allow`.
	 *
	 * @param array<string, mixed> $data Decoded JSON.
	 * @param array<string, mixed> $meta provider, model, cost_usd, latency_ms.
	 */
	public static function from_response( array $data, array $meta = array() ): self {
		$verdict = (string) ( $data['verdict'] ?? '' );

		if ( ! in_array( $verdict, array( self::ALLOW, self::REVIEW, self::BLOCK ), true ) ) {
			$verdict = self::BLOCK;
		}

		$prompt_en = trim( (string) ( $data['prompt_en'] ?? '' ) );

		// A verdict of allow with no translation is not usable — there is
		// nothing to send to the image provider.
		if ( self::ALLOW === $verdict && '' === $prompt_en ) {
			$verdict = self::BLOCK;
		}

		$categories = array();

		foreach ( self::CATEGORIES as $name ) {
			$categories[ $name ] = ! empty( $data['categories'][ $name ] );
		}

		$reasons = array();

		foreach ( (array) ( $data['reasons'] ?? array() ) as $reason ) {
			if ( is_scalar( $reason ) ) {
				$reasons[] = (string) $reason;
			}
		}

		return new self(
			$prompt_en,
			$verdict,
			$reasons,
			$categories,
			(float) ( $data['confidence'] ?? 0.0 ),
			(string) ( $meta['provider'] ?? '' ),
			(string) ( $meta['model'] ?? '' ),
			(float) ( $meta['cost_usd'] ?? 0.0 ),
			(int) ( $meta['latency_ms'] ?? 0 )
		);
	}

	/**
	 * A failed call. Fails closed — never an allow.
	 *
	 * @param string               $message Failure description.
	 * @param array<string, mixed> $meta    provider, model, latency_ms.
	 */
	public static function failed( string $message, array $meta = array() ): self {
		$analysis = new self(
			'',
			self::BLOCK,
			array( $message ),
			array_fill_keys( self::CATEGORIES, false ),
			0.0,
			(string) ( $meta['provider'] ?? '' ),
			(string) ( $meta['model'] ?? '' ),
			(float) ( $meta['cost_usd'] ?? 0.0 ),
			(int) ( $meta['latency_ms'] ?? 0 )
		);

		$analysis->error = $message;

		return $analysis;
	}

	/**
	 * Whether the call itself succeeded, regardless of the verdict.
	 */
	public function ok(): bool {
		return '' === $this->error;
	}

	/**
	 * Safe to generate without human attention.
	 */
	public function allowed(): bool {
		return self::ALLOW === $this->verdict;
	}

	/**
	 * Generate the preview, but flag the order for a human.
	 */
	public function needs_review(): bool {
		return self::REVIEW === $this->verdict;
	}

	/**
	 * Refuse outright.
	 */
	public function blocked(): bool {
		return self::BLOCK === $this->verdict;
	}

	/**
	 * Categories that came back true, for the rejection log.
	 *
	 * @return string[]
	 */
	public function flagged(): array {
		return array_keys( array_filter( $this->categories ) );
	}

	/**
	 * JSON for the designs.moderation column.
	 */
	public function to_json(): string {
		$encoded = wp_json_encode(
			array(
				'verdict'    => $this->verdict,
				'reasons'    => $this->reasons,
				'categories' => $this->categories,
				'confidence' => $this->confidence,
				'provider'   => $this->provider,
				'model'      => $this->model,
				'layer'      => 'llm',
			),
			JSON_UNESCAPED_UNICODE
		);

		return false === $encoded ? '{}' : $encoded;
	}
}
