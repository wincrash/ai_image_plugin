<?php
/**
 * The wizard, and its first step: what are we making?
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Frontend;

use AiCake\Domain\FormatCatalogue;
use AiCake\Imaging\FontCatalogue;
use AiCake\Imaging\LayerInspector;
use AiCake\Imaging\SheetLayout;
use AiCake\Rest\RestController;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use AiCake\WooCommerce\FieldsFactory;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * One page, client-side steps (D-034).
 *
 * Not N page loads: constraint #2 is that the PHP worker pool is scarce, and a
 * five-page wizard is five worker hits per customer before anything has been
 * generated. Steps are hash-addressable so the back button behaves.
 *
 * **Step 1 must come first because geometry forces it.** The generation aspect
 * depends on the format — 1:1 for a round topper, 2:3 for a whole sheet (§3.2)
 * — so the format has to be fixed before a prompt is sent. A single crowded
 * product page makes that a hidden dependency; a wizard makes it the first
 * question.
 *
 * What this class does *not* do is price anything (D-036). Every figure it
 * prints comes from WooCommerce or from Fields Factory's own rules, computed
 * server-side for each combination so the running total in the browser is a
 * lookup rather than arithmetic — which is also how it stays correct whether
 * the shop enters prices with or without tax.
 */
class Wizard {

	public const SHORTCODE = 'aicake_wizard';

	/**
	 * Slug of the single AI product (D-035).
	 */
	public const PRODUCT_SLUG = 'ai-paveikslelis';

	public const SHEET_LABEL = 'Lakšto tipas';

	public const AI_LABEL = 'AI paveikslėlis';

	private Settings $settings;

	private FieldsFactory $fields;

	private Logger $logger;

	/**
	 * @param Settings      $settings Configuration.
	 * @param FieldsFactory $fields   Fields Factory reader.
	 * @param Logger        $logger   Logging, for the font catalogue.
	 */
	public function __construct( Settings $settings, FieldsFactory $fields, Logger $logger ) {
		$this->settings = $settings;
		$this->fields   = $fields;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * Render the wizard.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 */
	public function render( $atts = array() ): string {
		unset( $atts );

		$product = $this->product();

		if ( ! $product instanceof WC_Product ) {
			/*
			 * Never a silent empty space. A missing product is a configuration
			 * error the shop owner has to see, and a customer staring at a
			 * blank page reports it as "the site is broken".
			 */
			return current_user_can( 'manage_woocommerce' )
				? '<p class="aicake-wizard-error">' . esc_html__( 'AI produktas nerastas. Patikrinkite nustatymus.', 'ai-cake-topper' ) . '</p>'
				: '';
		}

		$this->enqueue( $product );

		ob_start();

		$template = locate_template( 'ai-cake-topper/wizard.php' );

		if ( '' === $template ) {
			$template = AICAKE_DIR . 'templates/wizard.php';
		}

		$formats = $this->formats();
		$chips   = $this->example_prompts();
		$lead    = (string) $this->settings->get( 'lead_time_note', __( 'Pagaminame per 2–3 darbo dienas.', 'ai-cake-topper' ) );

		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * Clickable example prompts.
	 *
	 * §15: people do not know what to type, and examples raise output quality
	 * more than any prompt engineering. Shared with the product-page generator
	 * through the same setting, so tuning them tunes both.
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
	 * The product the wizard sells.
	 *
	 * Resolved by slug, with a filter, rather than a stored id: an id in
	 * options is the kind of thing that silently points at a deleted post
	 * after a content migration, and this has exactly one product to find.
	 */
	public function product(): ?WC_Product {
		$post = get_page_by_path( self::PRODUCT_SLUG, OBJECT, 'product' );

		$product_id = (int) apply_filters(
			'aicake_wizard_product_id',
			$post instanceof \WP_Post ? $post->ID : 0
		);

		if ( $product_id <= 0 ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Every format, grouped the way the wizard asks about them.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function formats(): array {
		$grouped = array(
			FormatCatalogue::TYPE_SHEET   => array(),
			FormatCatalogue::TYPE_CIRCLE  => array(),
			FormatCatalogue::TYPE_CUPCAKE => array(),
		);

		foreach ( FormatCatalogue::offerable() as $option ) {
			$grouped[ (string) $option['type'] ][] = array(
				'type'      => (string) $option['type'],
				'mm'        => (float) $option['diameter_mm'],
				'label'     => (string) $option['label'],
				'perSheet'  => (int) $option['per_sheet'],
			);
		}

		return $grouped;
	}

	/**
	 * Where every piece sits, for every format on offer.
	 *
	 * D-033: the client must never compute piece positions itself, or text
	 * lands across a gutter and looks right in the editor while being wrong on
	 * paper. So these come from `SheetLayout` through `PrintSpec`, precomputed
	 * for each format the same way prices are — the browser looks the answer up
	 * rather than deriving it.
	 *
	 * All of them ship at once rather than being fetched when the format is
	 * chosen. It is about 100 pieces of six integers in total, which is cheaper
	 * than the round trip and means step 3 opens instantly.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function layouts(): array {
		$layouts = array();

		foreach ( FormatCatalogue::offerable() as $option ) {
			$type = (string) $option['type'];
			$mm   = (float) $option['diameter_mm'];
			$spec = FormatCatalogue::spec( $type, $mm );

			if ( null === $spec ) {
				continue;
			}

			$layouts[ $type . '|' . $mm ] = $spec->editor_layout();
		}

		return $layouts;
	}

	/**
	 * Fonts the editor may offer.
	 *
	 * The Lithuanian coverage gate applies to the browser too, and matters more
	 * there: D-033 puts the glyphs into a bitmap, so `Ąžuolas` rendered as tofu
	 * boxes is baked in and nothing downstream can catch it. `FontCatalogue`
	 * already refuses a font that cannot spell Lithuanian; this is the same list
	 * with URLs attached.
	 *
	 * Self-hosted, never the Google CDN — an EU shop hotlinking it is a GDPR
	 * problem, not just a latency one (D-033).
	 *
	 * @return array<int, array<string, string>>
	 */
	public function fonts(): array {
		$catalogue = new FontCatalogue( $this->logger );
		$fonts     = array();

		foreach ( $catalogue->usable() as $font ) {
			$fonts[] = array(
				'handle' => (string) $font['handle'],
				'label'  => (string) $font['label'],
				'url'    => AICAKE_URL . 'fonts/' . basename( (string) $font['path'] ),
			);
		}

		return $fonts;
	}

	/**
	 * The sheet types and what each adds, read from Fields Factory.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sheet_types(): array {
		$key    = $this->fields->field_key( self::SHEET_LABEL );
		$fields = $this->fields->fields();

		if ( null === $key || ! isset( $fields[ $key ] ) ) {
			return array();
		}

		$types = array();

		/*
		 * WCFF stores choices as `value|label;value|label;`. Parsed rather
		 * than reproduced in a setting of our own, so the wizard offers
		 * exactly what the shop configured — and a sheet type added in the
		 * Fields Factory UI appears here without a deploy.
		 */
		foreach ( explode( ';', (string) ( $fields[ $key ]['choices'] ?? '' ) ) as $choice ) {
			if ( '' === trim( $choice ) ) {
				continue;
			}

			$parts = explode( '|', $choice, 2 );
			$value = trim( $parts[0] );

			if ( '' === $value ) {
				continue;
			}

			$types[] = array(
				'value'     => $value,
				'label'     => trim( $parts[1] ?? $value ),
				'surcharge' => $this->fields->surcharge( self::SHEET_LABEL, $value ),
			);
		}

		return $types;
	}

	/**
	 * Every price the customer can end up at, formatted by WooCommerce.
	 *
	 * Precomputed server-side, one entry per sheet type × AI, because the
	 * browser must never do tax arithmetic: whether these figures include VAT
	 * depends on two shop settings, and a running total that disagrees with
	 * the cart by 21% is worse than no running total.
	 *
	 * @param WC_Product $product The product.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function prices( WC_Product $product ): array {
		$base = (float) $product->get_price();
		$ai   = $this->fields->surcharge( self::AI_LABEL, 'taip' );

		$prices = array();

		foreach ( $this->sheet_types() as $sheet ) {
			foreach ( array( 'ne', 'taip' ) as $with_ai ) {
				$total = $base + (float) $sheet['surcharge'] + ( 'taip' === $with_ai ? $ai : 0.0 );

				$prices[ $sheet['value'] . '|' . $with_ai ] = array(
					'amount' => round( $total, 2 ),
					'html'   => wc_price( wc_get_price_to_display( $product, array( 'price' => $total ) ) ),
				);
			}
		}

		return $prices;
	}

	/**
	 * Load assets and hand the browser its data.
	 *
	 * @param WC_Product $product The product.
	 */
	private function enqueue( WC_Product $product ): void {
		wp_enqueue_style( 'aicake-wizard', AICAKE_URL . 'assets/css/wizard.css', array(), $this->asset_version( 'assets/css/wizard.css' ) );

		// The engine is a dependency rather than a copy: the §6.5 polling
		// contract and D-025's nonce rules exist once, for both the wizard and
		// the product-page generator.
		wp_enqueue_script( 'aicake-generation', AICAKE_URL . 'assets/js/generation.js', array(), $this->asset_version( 'assets/js/generation.js' ), true );
		wp_enqueue_script( 'aicake-editor', AICAKE_URL . 'assets/js/editor.js', array(), $this->asset_version( 'assets/js/editor.js' ), true );
		wp_enqueue_script(
			'aicake-wizard',
			AICAKE_URL . 'assets/js/wizard.js',
			array( 'aicake-generation', 'aicake-editor' ),
			$this->asset_version( 'assets/js/wizard.js' ),
			true
		);

		wp_localize_script(
			'aicake-wizard',
			'aicakeWizard',
			array(
				'root'      => esc_url_raw( rest_url( RestController::NAMESPACE . '/' ) ),
				'productId' => $product->get_id(),
				// Empty for anonymous visitors; this markup is cacheable and a
				// stale nonce 403s every generation (§7, D-025).
				'nonce'     => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
				'formats'   => $this->formats(),
				'sheets'    => $this->sheet_types(),
				'prices'    => $this->prices( $product ),
				'layouts'   => $this->layouts(),
				'fonts'     => $this->fonts(),
				/*
				 * The colour control is a real picker, not a swatch list. The
				 * check the endpoint runs caps how *many* distinct colours a
				 * layer declares, not which ones — four arbitrary colours give
				 * `LayerInspector` exactly the same job as four chosen ones —
				 * so the cap is the whole control and it is sent rather than
				 * duplicated, or the editor builds layers the endpoint refuses.
				 */
				'maxColours' => LayerInspector::MAX_COLOURS,
				'usable'    => array(
					'w' => SheetLayout::USABLE_WIDTH_MM,
					'h' => SheetLayout::USABLE_HEIGHT_MM,
				),
				'i18n'      => array(
					'pieces'     => __( 'Gausite: %d vnt.', 'ai-cake-topper' ),
					'onePiece'   => __( 'Gausite: 1 vnt.', 'ai-cake-topper' ),
					'pickFormat' => __( 'Pasirinkite, ką gaminsime.', 'ai-cake-topper' ),
					'pickSize'   => __( 'Pasirinkite dydį.', 'ai-cake-topper' ),
					'pickDesign' => __( 'Sukurkite piešinį, kad galėtumėte tęsti.', 'ai-cake-topper' ),
					'remaining'  => __( 'Liko nemokamų bandymų: %d', 'ai-cake-topper' ),
					'noneLeft'   => __( 'Nemokami bandymai išnaudoti', 'ai-cake-topper' ),
					'needPrompt' => __( 'Parašykite, ką norite pavaizduoti.', 'ai-cake-topper' ),
					'failed'     => __( 'Nepavyko sukurti piešinio. Bandykite dar kartą.', 'ai-cake-topper' ),
					'timeout'    => __( 'Užtruko ilgiau nei įprastai. Bandykite dar kartą.', 'ai-cake-topper' ),
					'expired'    => __( 'Sesija pasibaigė. Bandykite dar kartą.', 'ai-cake-topper' ),
					// A printed nonce cannot be refreshed without a page load,
					// so a logged-in customer is asked to reload rather than
					// told to retry something that cannot work.
					'reload'     => __( 'Sesija pasibaigė. Atnaujinkite puslapį.', 'ai-cake-topper' ),
					'queued'     => __( 'Eilėje: %d', 'ai-cake-topper' ),
					'reselect'   => __( 'Pasirinkti šį piešinį', 'ai-cake-topper' ),
					/* Step 3 — the text editor. */
					'addLine'    => __( 'Pridėti eilutę', 'ai-cake-topper' ),
					'removeLine' => __( 'Pašalinti', 'ai-cake-topper' ),
					'piece'      => __( 'Gabalėlis %d', 'ai-cake-topper' ),
					'allPieces'  => __( 'Toks pat užrašas ant visų', 'ai-cake-topper' ),
					'noText'     => __( 'Be užrašo', 'ai-cake-topper' ),
					'savingText' => __( 'Išsaugome užrašą…', 'ai-cake-topper' ),
					'textSaved'  => __( 'Užrašas išsaugotas.', 'ai-cake-topper' ),
					'textFailed' => __( 'Nepavyko išsaugoti užrašo. Bandykite dar kartą.', 'ai-cake-topper' ),
					'tooManyColours' => __( 'Per daug spalvų. Galima rinktis iki %d.', 'ai-cake-topper' ),
					'colour'     => __( 'Spalva', 'ai-cake-topper' ),
					'suggesting' => __( 'Kuriame dizainą…', 'ai-cake-topper' ),
					'suggestNone' => __( 'Šįkart pasiūlymo nepavyko sugalvoti. Sukurkite užrašą patys arba bandykite dar kartą.', 'ai-cake-topper' ),
					'suggestNeedsText' => __( 'Pirma parašykite užrašą, tada pasiūlysime dizainą.', 'ai-cake-topper' ),
					'safeZone'   => __( 'Užrašas turi tilpti apskritime — pagal juodą liniją karpysite.', 'ai-cake-topper' ),
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
	 * `AICAKE_VERSION` never changes during development, so with debugging on
	 * the file's modification time is used instead — otherwise every CSS edit
	 * appears not to have worked.
	 *
	 * @param string $relative Path within the plugin.
	 */
	private function asset_version( string $relative ): string {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return AICAKE_VERSION;
		}

		$path = AICAKE_DIR . $relative;
		$time = is_readable( $path ) ? (int) filemtime( $path ) : 0;

		return $time > 0 ? (string) $time : AICAKE_VERSION;
	}
}
