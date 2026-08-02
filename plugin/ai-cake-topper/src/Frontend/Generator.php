<?php
/**
 * The generator on the product page.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Frontend;

use AiCake\Domain\PrintSpec;
use AiCake\Imaging\FontCatalogue;
use AiCake\Rest\RestController;
use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the generator and gives it what it needs (PLAN.md §15).
 *
 * Deliberately does **not** print a nonce into the markup. The page will be
 * cached in production and a stale nonce 403s every logged-out generation —
 * the JS fetches one from the uncached session endpoint instead (§7).
 */
class Generator {

	private Settings $settings;

	private FontCatalogue $fonts;

	/**
	 * @param Settings      $settings Configuration.
	 * @param FontCatalogue $fonts    Fonts.
	 */
	public function __construct( Settings $settings, FontCatalogue $fonts ) {
		$this->settings = $settings;
		$this->fonts    = $fonts;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The spec for the product being viewed, or null when it does not take a
	 * generated design.
	 */
	private function current_spec(): ?PrintSpec {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return null;
		}

		$product_id = get_the_ID();

		if ( ! $product_id ) {
			return null;
		}

		$spec = PrintSpec::for_product( (int) $product_id );

		return $spec->enabled ? $spec : null;
	}

	/**
	 * Load assets only on pages that use them.
	 */
	public function enqueue(): void {
		$spec = $this->current_spec();

		if ( null === $spec ) {
			return;
		}

		wp_enqueue_style( 'aicake-generator', AICAKE_URL . 'assets/css/generator.css', array(), $this->asset_version( 'assets/css/generator.css' ) );
		wp_enqueue_script( 'aicake-generator', AICAKE_URL . 'assets/js/generator.js', array(), $this->asset_version( 'assets/js/generator.js' ), true );

		wp_localize_script(
			'aicake-generator',
			'aicakeConfig',
			array(
				'root'      => esc_url_raw( rest_url( RestController::NAMESPACE . '/' ) ),
				'productId' => (int) get_the_ID(),
				'i18n'      => array(
					'remaining'  => __( 'Liko nemokamų bandymų: %d', 'ai-cake-topper' ),
					'noneLeft'   => __( 'Nemokami bandymai išnaudoti', 'ai-cake-topper' ),
					'needPrompt' => __( 'Parašykite, ką norite pavaizduoti.', 'ai-cake-topper' ),
					'failed'     => __( 'Nepavyko sukurti piešinio. Bandykite dar kartą.', 'ai-cake-topper' ),
					'timeout'    => __( 'Užtruko ilgiau nei įprastai. Bandykite dar kartą.', 'ai-cake-topper' ),
					'expired'    => __( 'Sesija pasibaigė. Bandykite dar kartą.', 'ai-cake-topper' ),
					'queued'     => __( 'Eilėje: %d', 'ai-cake-topper' ),
					'reselect'   => __( 'Pasirinkti šį piešinį', 'ai-cake-topper' ),
					/*
					 * Rotating text, because 5–15 s of a bare spinner reads as
					 * broken (§15). The wording tracks the real pipeline
					 * stages, so it is honest rather than decorative.
					 */
					'progress'   => array(
						__( 'Skaitome jūsų aprašymą…', 'ai-cake-topper' ),
						__( 'Verčiame ir tikriname…', 'ai-cake-topper' ),
						__( 'Piešiame…', 'ai-cake-topper' ),
						__( 'Beveik baigta…', 'ai-cake-topper' ),
					),
				),
			)
		);
	}

	/**
	 * Cache-busting version for an asset.
	 *
	 * The plugin version is right in production — assets change when a release
	 * does. During development it is exactly wrong: every CSS edit keeps the
	 * same `?ver=`, the browser serves the old file, and the change appears not
	 * to have worked. With debugging on, use the file's modification time
	 * instead.
	 *
	 * @param string $relative Path within the plugin.
	 */
	private function asset_version( string $relative ): string {
		$debugging = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG );

		if ( ! $debugging ) {
			return AICAKE_VERSION;
		}

		$path = AICAKE_DIR . $relative;

		return is_readable( $path ) ? (string) filemtime( $path ) : AICAKE_VERSION;
	}

	/**
	 * Render the generator above the add-to-cart button.
	 */
	public function render(): void {
		$spec = $this->current_spec();

		if ( null === $spec ) {
			return;
		}

		$data = array(
			'spec'  => $spec->to_frontend(),
			'chips' => $this->example_prompts(),
			'fonts' => $this->fonts->usable(),
			'lead'  => (string) $this->settings->get( 'lead_time_note', __( 'Pagaminame per 2–3 darbo dienas.', 'ai-cake-topper' ) ),
		);

		$this->load_template( 'generator.php', $data );
	}

	/**
	 * Clickable example prompts.
	 *
	 * §15: "People do not know what to type; examples raise output quality
	 * more than any prompt engineering." These are drawn from the real
	 * evaluation set, so they are known to produce good output.
	 *
	 * @return string[]
	 */
	private function example_prompts(): array {
		$stored = $this->settings->get( 'example_prompts', null );

		if ( is_array( $stored ) && array() !== $stored ) {
			return array_map( 'strval', $stored );
		}

		return array(
			__( 'linksmas dinozauras su gimtadienio tortu', 'ai-cake-topper' ),
			__( 'vienaragis su vaivorykšte ir žvaigždutėmis', 'ai-cake-topper' ),
			__( 'meškiukas su spalvotais balionais', 'ai-cake-topper' ),
			__( 'gėlių vainikas su rožėmis', 'ai-cake-topper' ),
		);
	}

	/**
	 * Render a template, letting a theme override it.
	 *
	 * @param string               $file Template filename.
	 * @param array<string, mixed> $data Variables for the template.
	 */
	private function load_template( string $file, array $data ): void {
		$override = locate_template( array( 'ai-cake-topper/' . $file ) );
		$path     = '' !== $override ? $override : AICAKE_DIR . 'templates/' . $file;

		if ( ! is_readable( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $data, EXTR_SKIP );

		include $path;
	}
}
