<?php
/**
 * The wizard shell, and step 1.
 *
 * Overridable from a theme at `ai-cake-topper/wizard.php`.
 *
 * @var array<string, array<int, array<string, mixed>>> $formats Grouped formats.
 * @var WC_Product                                      $product The AI product.
 *
 * @package AiCake
 */

defined( 'ABSPATH' ) || exit;

/*
 * Steps are rendered server-side and revealed client-side. The alternative —
 * building step markup in JavaScript — costs nothing in workers but loses the
 * content to anything that does not run scripts, including the shop's own
 * search indexing.
 */
?>
<div class="aicake-wizard" data-step="1">

	<ol class="aicake-wizard__progress" aria-label="<?php esc_attr_e( 'Užsakymo žingsniai', 'ai-cake-topper' ); ?>">
		<li class="is-current" data-for="1"><?php esc_html_e( 'Ką gaminsime', 'ai-cake-topper' ); ?></li>
		<li data-for="2"><?php esc_html_e( 'Piešinys', 'ai-cake-topper' ); ?></li>
		<li data-for="3"><?php esc_html_e( 'Užrašas', 'ai-cake-topper' ); ?></li>
		<li data-for="4"><?php esc_html_e( 'Peržiūra', 'ai-cake-topper' ); ?></li>
	</ol>

	<section class="aicake-wizard__step" data-step="1">

		<h2 class="aicake-wizard__heading"><?php esc_html_e( 'Ką gaminsime?', 'ai-cake-topper' ); ?></h2>

		<div class="aicake-formats" role="radiogroup" aria-label="<?php esc_attr_e( 'Formatas', 'ai-cake-topper' ); ?>">
			<?php
			$types = array(
				AiCake\Domain\FormatCatalogue::TYPE_SHEET   => array(
					'title' => __( 'Visas A4 lapas', 'ai-cake-topper' ),
					'note'  => __( 'Vienas didelis paveikslėlis per visą lapą.', 'ai-cake-topper' ),
				),
				AiCake\Domain\FormatCatalogue::TYPE_CIRCLE  => array(
					'title' => __( 'Apvalus paveikslėlis', 'ai-cake-topper' ),
					'note'  => __( 'Tortui. Pasirinkite skersmenį.', 'ai-cake-topper' ),
				),
				AiCake\Domain\FormatCatalogue::TYPE_CUPCAKE => array(
					'title' => __( 'Keksiukams', 'ai-cake-topper' ),
					'note'  => __( 'Daug mažų apskritimų viename lape.', 'ai-cake-topper' ),
				),
			);

			foreach ( $types as $type => $copy ) :
				if ( empty( $formats[ $type ] ) ) {
					continue;
				}
				?>
				<label class="aicake-format-card">
					<input type="radio" name="aicake_format_type" value="<?php echo esc_attr( $type ); ?>">
					<span class="aicake-format-card__title"><?php echo esc_html( $copy['title'] ); ?></span>
					<span class="aicake-format-card__note"><?php echo esc_html( $copy['note'] ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<p class="aicake-field" data-role="size" hidden>
			<label for="aicake-size"><?php esc_html_e( 'Dydis', 'ai-cake-topper' ); ?></label>
			<select id="aicake-size" name="aicake_format_mm"></select>
			<span class="aicake-field__note" data-role="pieces"></span>
		</p>

		<fieldset class="aicake-field aicake-sheets">
			<legend><?php esc_html_e( 'Lakšto tipas', 'ai-cake-topper' ); ?></legend>
			<div data-role="sheets"></div>
		</fieldset>

		<p class="aicake-price">
			<span class="aicake-price__label"><?php esc_html_e( 'Kaina:', 'ai-cake-topper' ); ?></span>
			<span class="aicake-price__value" data-role="price"></span>
			<span class="aicake-price__note" data-role="price-note"></span>
		</p>

		<p class="aicake-actions">
			<button type="button" class="button aicake-next" data-role="next" disabled>
				<?php esc_html_e( 'Toliau', 'ai-cake-topper' ); ?>
			</button>
			<span class="aicake-hint" data-role="hint" role="status"></span>
		</p>

	</section>

	<?php
	/*
	 * Steps 2–4 are placeholders until their own commits. They exist now so
	 * the progress rail is honest about how many steps there are — a wizard
	 * that grows extra steps as you go is the thing customers abandon.
	 */
	?>
	<section class="aicake-wizard__step" data-step="2" hidden>
		<h2 class="aicake-wizard__heading"><?php esc_html_e( 'Piešinys', 'ai-cake-topper' ); ?></h2>
		<p class="aicake-summary" data-role="summary"></p>
		<p><?php esc_html_e( 'Šis žingsnis dar ruošiamas.', 'ai-cake-topper' ); ?></p>
		<p class="aicake-actions">
			<button type="button" class="button aicake-back" data-role="back">
				<?php esc_html_e( 'Atgal', 'ai-cake-topper' ); ?>
			</button>
		</p>
	</section>

</div>
