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

	<section class="aicake-wizard__step" data-step="3" hidden>

		<h2 class="aicake-wizard__heading"><?php esc_html_e( 'Užrašas', 'ai-cake-topper' ); ?></h2>

		<?php
		/*
		 * D-033: the text is composed here, in the browser, over the
		 * watermarked preview. No PHP worker is touched while editing, and
		 * what eventually crosses the wire is a transparent PNG the size of
		 * the whole print file plus the plain string.
		 */
		?>
		<p class="aicake-field__note"><?php esc_html_e( 'Užrašas nebūtinas — galite tęsti ir be jo.', 'ai-cake-topper' ); ?></p>

		<div class="aicake-editor">
			<div class="aicake-editor__stage">
				<canvas data-role="editor-canvas" class="aicake-editor__canvas"></canvas>
			</div>

			<div class="aicake-editor__controls">

				<?php
				/*
				 * Only shown for a multi-piece format. D-033's headline case is
				 * twelve cupcakes with twelve different names, which is
				 * impossible if text is baked in before imposition — and
				 * meaningless on a single topper.
				 */
				?>
				<p class="aicake-field" data-role="pieces-field" hidden>
					<label>
						<input type="checkbox" data-role="same-for-all" checked>
						<?php esc_html_e( 'Toks pat užrašas ant visų', 'ai-cake-topper' ); ?>
					</label>
					<span class="aicake-piece-picker" data-role="piece-picker" hidden></span>
				</p>

				<div class="aicake-lines" data-role="lines"></div>

				<p class="aicake-actions">
					<button type="button" class="button aicake-add-line" data-role="add-line">
						<?php esc_html_e( 'Pridėti eilutę', 'ai-cake-topper' ); ?>
					</button>

					<?php
					/*
					 * D-041. The model proposes a layout and the canvas draws
					 * it; everything stays draggable afterwards. Optional by
					 * design — the editor works with this button unpressed and
					 * with the text API down.
					 */
					?>
					<button type="button" class="button aicake-suggest" data-role="suggest">
						<?php esc_html_e( 'Pasiūlyk dizainą', 'ai-cake-topper' ); ?>
					</button>
				</p>

				<?php
				/*
				 * A listbox rather than a <select>, because the whole point is
				 * to show each font in its own face. Styling <option> is not
				 * reliable across browsers and does nothing at all on most
				 * mobile ones, where the OS draws the list.
				 */
				?>
				<div class="aicake-field aicake-fontpicker" data-role="fontpicker">
					<label id="aicake-font-label"><?php esc_html_e( 'Šriftas', 'ai-cake-topper' ); ?></label>

					<button type="button" class="aicake-fontpicker__button" data-role="font-button"
						aria-haspopup="listbox" aria-expanded="false" aria-labelledby="aicake-font-label"></button>

					<ul class="aicake-fontpicker__list" data-role="font-list" role="listbox"
						aria-labelledby="aicake-font-label" hidden></ul>
				</div>

				<p class="aicake-field">
					<label>
						<input type="checkbox" data-role="outline" checked>
						<?php esc_html_e( 'Kontūras (geriau matosi ant piešinio)', 'ai-cake-topper' ); ?>
					</label>
					<input type="color" data-role="outline-colour" value="#000000"
						aria-label="<?php esc_attr_e( 'Kontūro spalva', 'ai-cake-topper' ); ?>">
				</p>

				<p class="aicake-field__note"><?php esc_html_e( 'Užrašas turi tilpti apskritime — pagal juodą liniją karpysite.', 'ai-cake-topper' ); ?></p>

			</div>
		</div>

		<div class="aicake-error" data-role="error-3" role="alert" hidden></div>

		<p class="aicake-actions">
			<button type="button" class="button aicake-back" data-role="back-3">
				<?php esc_html_e( 'Atgal', 'ai-cake-topper' ); ?>
			</button>
			<button type="button" class="button aicake-next" data-role="next-3">
				<?php esc_html_e( 'Toliau', 'ai-cake-topper' ); ?>
			</button>
			<span class="aicake-hint" data-role="hint-3" role="status"></span>
		</p>

	</section>

	<?php
	/*
	 * Step 4 is a placeholder until its own commit. It exists now so the
	 * progress rail is honest about how many steps there are — a wizard that
	 * grows extra steps as you go is the thing customers abandon.
	 */
	?>
	<section class="aicake-wizard__step" data-step="4" hidden>
		<h2 class="aicake-wizard__heading"><?php esc_html_e( 'Peržiūra', 'ai-cake-topper' ); ?></h2>
		<p><?php esc_html_e( 'Šis žingsnis dar ruošiamas.', 'ai-cake-topper' ); ?></p>
		<p class="aicake-actions">
			<button type="button" class="button aicake-back" data-role="back-4">
				<?php esc_html_e( 'Atgal', 'ai-cake-topper' ); ?>
			</button>
		</p>
	</section>

	<input type="hidden" name="aicake_design" data-role="design" value="">

</div>
