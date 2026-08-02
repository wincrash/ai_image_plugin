<?php
/**
 * Editing the blocklist, and trying it out.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Admin;

use AiCake\Domain\PromptAnalysis;
use AiCake\Moderation\Blocklist;
use AiCake\Moderation\Moderator;

defined( 'ABSPATH' ) || exit;

/**
 * PLAN.md §10 Layer 1: "editable from admin, one term per line, no code
 * deploy". The list is expected to grow from real rejected prompts, and a list
 * that needs a developer to change is a list that does not get changed.
 *
 * The try-it box matters as much as the textarea. Adding a blocklist term is
 * easy to get wrong in a way that only shows up as lost sales — too broad and
 * it silently refuses innocent orders. Being able to paste a real prompt and
 * see the verdict turns that from a guess into a check.
 */
class BlocklistPage {

	private const SLUG = 'aicake-moderation';

	private const ACTION = 'aicake_save_blocklist';

	private const NOTICE = 'aicake_blocklist_notice';

	private Moderator $moderator;

	/**
	 * @param Moderator $moderator Moderation layers.
	 */
	public function __construct( Moderator $moderator ) {
		$this->moderator = $moderator;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Add the submenu entry.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'aicake-test-provider',
			__( 'Moderation', 'ai-cake-topper' ),
			__( 'Moderation', 'ai-cake-topper' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Save the list, or run a test prompt through the free layers.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-cake-topper' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$raw   = isset( $_POST['terms'] ) ? sanitize_textarea_field( wp_unslash( $_POST['terms'] ) ) : '';
		$terms = array_filter( array_map( 'trim', preg_split( '/\R/', $raw ) ?: array() ) );

		$this->moderator->blocklist()->set_custom_terms( $terms );

		$test = isset( $_POST['test'] ) ? sanitize_textarea_field( wp_unslash( $_POST['test'] ) ) : '';

		if ( '' !== trim( $test ) ) {
			$clean   = $this->moderator->clean( $test );
			$verdict = $this->moderator->pre_check( $clean );

			set_transient(
				self::NOTICE . get_current_user_id(),
				array(
					'prompt'  => $clean,
					'verdict' => $verdict->verdict,
					'layer'   => $verdict->layer,
					'reason'  => $verdict->reason,
				),
				MINUTE_IN_SECONDS
			);
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$blocklist = $this->moderator->blocklist();
		$custom    = $blocklist->custom_terms();
		$starter   = Blocklist::starter_terms();

		echo '<div class="wrap"><h1>' . esc_html__( 'Moderation', 'ai-cake-topper' ) . '</h1>';

		$this->render_result();

		echo '<p class="description">'
			. esc_html__( 'Three layers run before an image is made. Input sanity and this blocklist are free and instant; an AI classifier runs afterwards and catches what a word list cannot — a character described but never named, for instance. Nothing reaches the printer without a human approving it.', 'ai-cake-topper' )
			. '</p>';

		printf(
			'<p><strong>%s</strong> %d &nbsp;·&nbsp; <strong>%s</strong> %d</p>',
			esc_html__( 'Built-in terms:', 'ai-cake-topper' ),
			count( $starter ),
			esc_html__( 'yours:', 'ai-cake-topper' ),
			count( $custom )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		echo '<h2>' . esc_html__( 'Your terms', 'ai-cake-topper' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'One per line. Matching ignores Lithuanian case endings and diacritics, so "Elsa" also catches "Elsos" and "elsa". Multi-word terms must appear together and in order.', 'ai-cake-topper' )
			. '</p>';
		echo '<p><textarea name="terms" rows="10" style="width:100%;max-width:640px;font-family:monospace">'
			. esc_textarea( implode( "\n", $custom ) )
			. '</textarea></p>';

		echo '<h2>' . esc_html__( 'Try a prompt', 'ai-cake-topper' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Checks the free layers only — no AI call, no cost. Use it to confirm a new term catches what you meant and nothing else.', 'ai-cake-topper' )
			. '</p>';
		echo '<p><input type="text" name="test" class="regular-text" style="width:100%;max-width:640px" placeholder="'
			. esc_attr__( 'e.g. Elsos suknelė', 'ai-cake-topper' ) . '"></p>';

		submit_button( __( 'Save and test', 'ai-cake-topper' ) );
		echo '</form>';

		$this->render_starter( $starter );

		echo '</div>';
	}

	/**
	 * Show the outcome of a test prompt.
	 */
	private function render_result(): void {
		$key    = self::NOTICE . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );

		if ( ! is_array( $result ) ) {
			return;
		}

		$blocked = PromptAnalysis::ALLOW !== $result['verdict'];

		printf(
			'<div class="notice notice-%s"><p><strong>%s</strong> <code>%s</code><br>%s</p></div>',
			$blocked ? 'warning' : 'success',
			$blocked
				? esc_html__( 'Blocked:', 'ai-cake-topper' )
				: esc_html__( 'Passed the free layers:', 'ai-cake-topper' ),
			esc_html( (string) $result['prompt'] ),
			$blocked
				? sprintf(
					/* translators: 1: layer name, 2: machine-readable reason */
					esc_html__( 'Caught by the %1$s layer (%2$s). The customer sees a general message, never the matched term.', 'ai-cake-topper' ),
					esc_html( (string) $result['layer'] ),
					'<code>' . esc_html( (string) $result['reason'] ) . '</code>'
				)
				: esc_html__( 'The AI classifier would still check it before anything is generated.', 'ai-cake-topper' )
		);
	}

	/**
	 * List the shipped terms, collapsed.
	 *
	 * @param string[] $starter Built-in terms.
	 */
	private function render_starter( array $starter ): void {
		echo '<h2>' . esc_html__( 'Built-in terms', 'ai-cake-topper' ) . '</h2>';
		echo '<details><summary>' . esc_html__( 'Show the shipped list', 'ai-cake-topper' ) . '</summary>';
		echo '<p style="max-width:640px"><code>' . esc_html( implode( ' · ', $starter ) ) . '</code></p>';
		echo '<p class="description">'
			. esc_html__( 'Some obvious franchise names are deliberately absent because they are ordinary Lithuanian words — "Ratai" (Cars) means wheels, "Lokys" means bear. Blocking those would refuse innocent orders.', 'ai-cake-topper' )
			. '</p>';
		echo '</details>';
	}
}
