<?php
/**
 * The wizard shell, and step 1.
 *
 * Overridable from a theme at `ai-cake-topper/wizard.php`.
 *
 * @var array<string, array<int, array<string, mixed>>> $formats Grouped formats.
 * @var WC_Product                                      $product The AI product.
 * @var string[]                                        $chips   Example prompts.
 * @var string                                          $lead    Lead-time note.
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

	<section class="aicake-wizard__step" data-step="2" hidden>

		<h2 class="aicake-wizard__heading"><?php esc_html_e( 'Koks bus piešinys?', 'ai-cake-topper' ); ?></h2>

		<p class="aicake-summary" data-role="summary"></p>

		<div class="aicake-field">
			<label for="aicake-wizard-prompt">
				<?php esc_html_e( 'Aprašykite, ką norite pavaizduoti', 'ai-cake-topper' ); ?>
			</label>

			<textarea id="aicake-wizard-prompt" class="aicake-prompt" rows="3" maxlength="500"
				placeholder="<?php esc_attr_e( 'pvz. linksmas dinozauras su gimtadienio tortu', 'ai-cake-topper' ); ?>"></textarea>

			<span class="aicake-field__note"><span data-role="counter">0</span> / 500</span>

			<?php if ( array() !== $chips ) : ?>
				<?php
				/*
				 * §15: "People do not know what to type; examples raise output
				 * quality more than any prompt engineering."
				 */
				?>
				<div class="aicake-chips">
					<span class="aicake-chips__label"><?php esc_html_e( 'Pavyzdžiai:', 'ai-cake-topper' ); ?></span>
					<?php foreach ( $chips as $chip ) : ?>
						<button type="button" class="aicake-chip" data-role="chip"><?php echo esc_html( $chip ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p class="aicake-terms">
				<?php
				esc_html_e(
					'Negalime kurti žinomų personažų, prekių ženklų ar tikrų žmonių atvaizdų. Tokius užsakymus tenka atšaukti ir grąžinti pinigus.',
					'ai-cake-topper'
				);
				?>
			</p>
		</div>

		<p class="aicake-actions">
			<button type="button" class="button aicake-generate" data-role="generate">
				<span><?php esc_html_e( 'Sukurti piešinį', 'ai-cake-topper' ); ?></span>
				<span class="aicake-remaining" data-role="remaining"></span>
			</button>
		</p>

		<div class="aicake-status" data-role="status" hidden>
			<span class="aicake-spinner" aria-hidden="true"></span>
			<span data-role="status-text"></span>
		</div>

		<div class="aicake-error" data-role="error" role="alert" hidden></div>

		<div class="aicake-stage" data-role="stage" hidden>
			<div class="aicake-preview" data-role="preview-frame">
				<img data-role="preview" alt="<?php esc_attr_e( 'Jūsų piešinio peržiūra', 'ai-cake-topper' ); ?>">
			</div>
		</div>

		<?php
		/*
		 * §15: customers routinely prefer generation #2 after seeing #5, and
		 * losing it is infuriating. The images are already stored, so this
		 * costs nothing.
		 */
		?>
		<div class="aicake-history" data-role="history" hidden>
			<span class="aicake-history__label"><?php esc_html_e( 'Šio apsilankymo piešiniai:', 'ai-cake-topper' ); ?></span>
			<div class="aicake-history__strip" data-role="history-strip"></div>
		</div>

		<p class="aicake-actions">
			<button type="button" class="button aicake-back" data-role="back">
				<?php esc_html_e( 'Atgal', 'ai-cake-topper' ); ?>
			</button>
			<button type="button" class="button aicake-next" data-role="next-2" disabled>
				<?php esc_html_e( 'Toliau', 'ai-cake-topper' ); ?>
			</button>
			<span class="aicake-hint" data-role="hint-2" role="status"></span>
		</p>

		<?php if ( '' !== $lead ) : ?>
			<p class="aicake-field__note"><?php echo esc_html( $lead ); ?></p>
		<?php endif; ?>

	</section>

	<?php
	/*
	 * Steps 3–4 are placeholders until their own commits. They exist now so
	 * the progress rail is honest about how many steps there are — a wizard
	 * that grows extra steps as you go is the thing customers abandon.
	 */
	?>
	<section class="aicake-wizard__step" data-step="3" hidden>
		<h2 class="aicake-wizard__heading"><?php esc_html_e( 'Užrašas', 'ai-cake-topper' ); ?></h2>
		<p><?php esc_html_e( 'Šis žingsnis dar ruošiamas.', 'ai-cake-topper' ); ?></p>
		<p class="aicake-actions">
			<button type="button" class="button aicake-back" data-role="back-3">
				<?php esc_html_e( 'Atgal', 'ai-cake-topper' ); ?>
			</button>
		</p>
	</section>

	<input type="hidden" name="aicake_design" data-role="design" value="">

</div>
