<?php
/**
 * The outcome of one moderation layer.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Moderation;

use AiCake\Domain\PromptAnalysis;

defined( 'ABSPATH' ) || exit;

/**
 * A result from Layer 0 or Layer 1.
 *
 * Layer 2 returns a PromptAnalysis instead, because it also carries the
 * translation and a cost. This is the cheap layers' equivalent: same three
 * verdicts, no money involved.
 */
class Verdict {

	/**
	 * @param string              $verdict    allow | review | block.
	 * @param string              $layer      Which layer decided, for the rejection log.
	 * @param string              $reason     Machine-readable reason.
	 * @param array<string, bool> $categories Category flags, matching PromptAnalysis.
	 */
	public function __construct(
		public string $verdict = PromptAnalysis::ALLOW,
		public string $layer = '',
		public string $reason = '',
		public array $categories = array()
	) {}

	/**
	 * Nothing wrong here.
	 *
	 * @param string $layer Which layer passed it.
	 */
	public static function allowed( string $layer ): self {
		return new self( PromptAnalysis::ALLOW, $layer );
	}

	/**
	 * Refuse outright.
	 *
	 * @param string              $layer      Which layer caught it.
	 * @param string              $reason     Machine-readable reason.
	 * @param array<string, bool> $categories Category flags.
	 */
	public static function blocked( string $layer, string $reason, array $categories = array() ): self {
		return new self( PromptAnalysis::BLOCK, $layer, $reason, $categories );
	}

	/**
	 * Let it through, but make a human look before it prints.
	 *
	 * @param string              $layer      Which layer flagged it.
	 * @param string              $reason     Machine-readable reason.
	 * @param array<string, bool> $categories Category flags.
	 */
	public static function review( string $layer, string $reason, array $categories = array() ): self {
		return new self( PromptAnalysis::REVIEW, $layer, $reason, $categories );
	}

	/**
	 * Whether this passed cleanly.
	 */
	public function allowed_through(): bool {
		return PromptAnalysis::ALLOW === $this->verdict;
	}

	/**
	 * Whether this was refused.
	 */
	public function is_blocked(): bool {
		return PromptAnalysis::BLOCK === $this->verdict;
	}

	/**
	 * JSON for the designs.moderation column.
	 *
	 * Deliberately the same shape as PromptAnalysis::to_json(), so the review
	 * queue reads one format regardless of which layer decided.
	 */
	public function to_json(): string {
		$categories = array();

		foreach ( PromptAnalysis::CATEGORIES as $name ) {
			$categories[ $name ] = ! empty( $this->categories[ $name ] );
		}

		$encoded = wp_json_encode(
			array(
				'verdict'    => $this->verdict,
				'reasons'    => '' === $this->reason ? array() : array( $this->reason ),
				'categories' => $categories,
				'confidence' => 1.0,
				'provider'   => '',
				'model'      => '',
				'layer'      => $this->layer,
			),
			JSON_UNESCAPED_UNICODE
		);

		return false === $encoded ? '{}' : $encoded;
	}
}
