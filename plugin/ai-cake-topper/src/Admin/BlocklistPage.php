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
use AiCake\Support\Settings;

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

	private Settings $settings;

	/**
	 * @param Moderator $moderator Moderation layers.
	 * @param Settings  $settings  Which layers are switched on.
	 */
	public function __construct( Moderator $moderator, Settings $settings ) {
		$this->moderator = $moderator;
		$this->settings  = $settings;
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

		$layers = array();

		foreach ( Moderator::LAYERS as $layer => $key ) {
			$layers[ $key ] = isset( $_POST['layers'] ) && in_array( $layer, (array) wp_unslash( $_POST['layers'] ), true );
		}

		$this->settings->update( $layers );

		$raw   = isset( $_POST['terms'] ) ? sanitize_textarea_field( wp_unslash( $_POST['terms'] ) ) : '';
		$terms = array_filter( array_map( 'trim', preg_split( '/\R/', $raw ) ?: array() ) );

		$this->moderator->blocklist()->set_custom_terms( $terms );

		/*
		 * Unticked checkboxes post nothing, so what arrives is the terms still
		 * wanted and the removals are whatever is missing. That inversion is
		 * only safe because the form always renders every shipped term — if it
		 * ever paginated them, a save from page one would switch off page two.
		 */
		$keep = isset( $_POST['starter'] )
			? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['starter'] ) )
			: array();

		$this->moderator->blocklist()->set_removed_terms(
			array_values( array_diff( Blocklist::starter_terms(), $keep ) )
		);

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
		$removed   = $blocklist->removed_terms();

		echo '<div class="wrap"><h1>' . esc_html__( 'Moderation', 'ai-cake-topper' ) . '</h1>';

		$this->render_result();

		echo '<p class="description">'
			. esc_html__( 'Three layers run before an image is made. Input sanity and this blocklist are free and instant; an AI classifier runs afterwards and catches what a word list cannot — a character described but never named, for instance. Nothing else screens the artwork, so whatever passes here is what you will see on the sheet at the printer.', 'ai-cake-topper' )
			. '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		$this->render_layers();

		printf(
			'<h2>%s</h2><p><strong>%s</strong> %d %s &nbsp;·&nbsp; <strong>%s</strong> %d</p>',
			esc_html__( 'Blocked terms', 'ai-cake-topper' ),
			esc_html__( 'Built-in:', 'ai-cake-topper' ),
			count( $starter ) - count( $removed ),
			sprintf(
				/* translators: %d: how many built-in terms are switched off */
				esc_html__( 'active (%d switched off)', 'ai-cake-topper' ),
				count( $removed )
			),
			esc_html__( 'yours:', 'ai-cake-topper' ),
			count( $custom )
		);

		echo '<h3>' . esc_html__( 'Your terms', 'ai-cake-topper' ) . '</h3>';
		echo '<p class="description">'
			. esc_html__( 'One per line. Matching ignores Lithuanian case endings and diacritics, so "Elsa" also catches "Elsos" and "elsa". Multi-word terms must appear together and in order.', 'ai-cake-topper' )
			. '</p>';
		echo '<p><textarea name="terms" rows="10" style="width:100%;max-width:640px;font-family:monospace">'
			. esc_textarea( implode( "\n", $custom ) )
			. '</textarea></p>';

		$this->render_starter( $starter, $removed );

		echo '<h2>' . esc_html__( 'Try a prompt', 'ai-cake-topper' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Checks the free layers only — no AI call, no cost. Use it to confirm a new term catches what you meant and nothing else.', 'ai-cake-topper' )
			. '</p>';
		echo '<p><input type="text" name="test" class="regular-text" style="width:100%;max-width:640px" placeholder="'
			. esc_attr__( 'e.g. Elsos suknelė', 'ai-cake-topper' ) . '"></p>';

		submit_button( __( 'Save and test', 'ai-cake-topper' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * The three on/off switches.
	 *
	 * Each layer separately, because they do not cost the same thing. Dropping
	 * the classifier stops the only layer that spends money; dropping the word
	 * list stops the only layer that can refuse an innocent order for a word
	 * that happens to be in it.
	 */
	private function render_layers(): void {
		$rows = array(
			'sanity'    => array(
				__( 'Input sanity', 'ai-cake-topper' ),
				__( 'Refuses gibberish — keyboard mashing, a repeated character, text with almost no vowels. Free. Off means those reach the image provider and are paid for. An empty prompt is still refused either way.', 'ai-cake-topper' ),
			),
			'blocklist' => array(
				__( 'Blocked terms', 'ai-cake-topper' ),
				__( 'The word list below, matched through Lithuanian case endings. Free and instant. Off means franchise names are not caught before the AI classifier sees them.', 'ai-cake-topper' ),
			),
			'ai'        => array(
				__( 'AI classifier', 'ai-cake-topper' ),
				__( 'Catches what a word list cannot — a character described but never named. Costs about $0.0001 and 800 ms per prompt. Off does not save that: the same call translates the prompt into English for the image provider, so it still runs, its verdict is still recorded, and it simply stops refusing anything.', 'ai-cake-topper' ),
			),
		);

		echo '<h2>' . esc_html__( 'Layers', 'ai-cake-topper' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( $rows as $layer => $row ) {
			printf(
				'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="layers[]" value="%s"%s> %s</label><p class="description">%s</p></td></tr>',
				esc_html( $row[0] ),
				esc_attr( $layer ),
				checked( $this->moderator->enabled( $layer ), true, false ),
				esc_html__( 'On', 'ai-cake-topper' ),
				esc_html( $row[1] )
			);
		}

		echo '</tbody></table>';

		if ( ! $this->moderator->enabled( 'sanity' ) && ! $this->moderator->enabled( 'blocklist' ) && ! $this->moderator->enabled( 'ai' ) ) {
			echo '<div class="notice notice-warning inline"><p>'
				. esc_html__( 'All three layers are off. Every prompt is generated and paid for exactly as typed, and the only thing standing between a customer and a printed sheet is you looking at it.', 'ai-cake-topper' )
				. '</p></div>';
		}
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
				: (
					$this->moderator->enabled( 'ai' )
						? esc_html__( 'The AI classifier would still check it before anything is generated.', 'ai-cake-topper' )
						// The whole point of the try-it box is to show what will
						// really happen. Promising a check that is switched off
						// would make this screen lie about its own settings.
						: esc_html__( 'The AI classifier is off, so nothing else would refuse it — this prompt would be generated as typed.', 'ai-cake-topper' )
				)
		);
	}

	/**
	 * The shipped terms, each one switchable.
	 *
	 * Ticked means active. What gets stored is the *unticked* ones, so a
	 * plugin update that adds a built-in term still takes effect — saving an
	 * edited copy of the whole list would freeze it at whatever shipped on the
	 * day it was first touched.
	 *
	 * Collapsed by default: ninety checkboxes above the textarea would bury
	 * the thing this screen is mostly used for.
	 *
	 * @param string[] $starter Built-in terms.
	 * @param string[] $removed The ones switched off.
	 */
	private function render_starter( array $starter, array $removed ): void {
		echo '<h3>' . esc_html__( 'Built-in terms', 'ai-cake-topper' ) . '</h3>';
		echo '<details' . ( array() === $removed ? '' : ' open' ) . '><summary>'
			. esc_html__( 'Show the shipped list — untick to stop blocking one', 'ai-cake-topper' )
			. '</summary>';

		echo '<div style="max-width:900px;column-width:200px;column-gap:24px;margin:12px 0">';

		foreach ( $starter as $term ) {
			printf(
				'<label style="display:block;break-inside:avoid"><input type="checkbox" name="starter[]" value="%s"%s> %s</label>',
				esc_attr( $term ),
				checked( ! in_array( $term, $removed, true ), true, false ),
				esc_html( $term )
			);
		}

		echo '</div>';

		echo '<p class="description">'
			. esc_html__( 'Some obvious franchise names are deliberately absent because they are ordinary Lithuanian words — "Ratai" (Cars) means wheels, "Lokys" means bear. Blocking those would refuse innocent orders.', 'ai-cake-topper' )
			. '</p>';
		echo '<p class="description">'
			. esc_html__( 'Switching one off does not make it legal to print. It means this shop takes the copyright decision itself rather than having the plugin take it.', 'ai-cake-topper' )
			. '</p>';
		echo '</details>';
	}
}
