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

		<?php
		/*
		 * Where the picture comes from (D-054), asked on the same screen as the
		 * format because Ruslan described the opening page as *"user choose
		 * type, and format"* — one page, two questions, not two steps.
		 *
		 * Built by the browser from `config.sources`, which contains only the
		 * sources this shop has switched on. When exactly one is on, the whole
		 * block is left out: a choice with one option is not a choice (D-059).
		 */
		?>
		<div
			class="aicake-sources"
			role="radiogroup"
			aria-label="<?php esc_attr_e( 'Iš kur paveikslėlis', 'ai-cake-topper' ); ?>"
			data-role="sources"
			hidden
		></div>

		<?php
		/*
		 * One question, not two (D-055). This used to ask for a type — sheet,
		 * circle, cupcake — and then reveal a size list for the type chosen.
		 * Ruslan's read was that those are one thing seen three ways, and the
		 * geometry had been saying so all along.
		 *
		 * The cards are filled in by the browser, from `SheetLayout`'s own plan
		 * shipped in `config.formats`, because each one draws the real
		 * arrangement of the real sheet. Rendering them here would mean
		 * building the same picture twice — and a diagram that is not derived
		 * from `SheetLayout` drifts away from the print the first time a margin
		 * changes (D-038).
		 */
		?>
		<div
			class="aicake-formats"
			role="radiogroup"
			aria-label="<?php esc_attr_e( 'Formatas', 'ai-cake-topper' ); ?>"
			data-role="formats"
		></div>

		<noscript>
			<p><?php esc_html_e( 'Norėdami kurti piešinį, įjunkite JavaScript.', 'ai-cake-topper' ); ?></p>
		</noscript>

		<p class="aicake-field__note" data-role="pieces" role="status"></p>

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

		<?php
		/*
		 * The upload branch (D-054, D-062). Step 2 is the only step that
		 * differs between the four sources, and this is the whole of that
		 * difference for a photograph: pick a file, move it under the hole.
		 *
		 * Hidden by default and revealed by the browser, because which branch
		 * applies is decided by a choice made on step 1 — putting that in PHP
		 * would mean rendering the page again to change it.
		 */
		?>
		<div class="aicake-upload" data-role="upload-branch" hidden>
			<p class="aicake-field">
				<label class="aicake-upload__pick">
					<input type="file" accept="image/*" data-role="upload-file">
					<span><?php esc_html_e( 'Pasirinkite nuotrauką', 'ai-cake-topper' ); ?></span>
				</label>
			</p>

			<div class="aicake-upload__stage" data-role="upload-stage" hidden>
				<canvas data-role="crop-canvas" class="aicake-upload__canvas"></canvas>

				<p class="aicake-field aicake-upload__zoom">
					<label for="aicake-crop-zoom"><?php esc_html_e( 'Priartinimas', 'ai-cake-topper' ); ?></label>
					<input type="range" id="aicake-crop-zoom" data-role="crop-zoom"
						min="100" max="600" step="1" value="100">
					<span class="aicake-field__note" data-role="crop-zoom-value">1,0×</span>
				</p>

				<p class="aicake-field__note">
					<?php esc_html_e( 'Traukite nuotrauką pele arba pirštu, o priartinimu pasirinkite, kurią jos dalį naudoti. Kas matosi lange — tas ir bus atspausdinta.', 'ai-cake-topper' ); ?>
				</p>

				<p class="aicake-upload__warn" data-role="crop-warn" hidden></p>

				<p class="aicake-actions">
					<button type="button" class="button" data-role="upload-save">
						<?php esc_html_e( 'Naudoti šią nuotrauką', 'ai-cake-topper' ); ?>
					</button>
					<span class="aicake-hint" data-role="upload-hint" role="status"></span>
				</p>
			</div>

			<div class="aicake-error" data-role="upload-error" hidden></div>
		</div>

		<?php
		/*
		 * The search branch (D-067). Same shape as the prompt box, because it
		 * is the same act — describing what you want — and the customer should
		 * not have to learn a second interface to do it.
		 */
		?>
		<div class="aicake-search" data-role="search-branch" hidden>
			<p class="aicake-field">
				<label for="aicake-search-query">
					<?php esc_html_e( 'Ko ieškote?', 'ai-cake-topper' ); ?>
				</label>

				<input type="text" id="aicake-search-query" class="aicake-prompt" maxlength="120"
					data-role="search-query"
					placeholder="<?php esc_attr_e( 'pvz. dinozauras', 'ai-cake-topper' ); ?>">
			</p>

			<p class="aicake-actions">
				<button type="button" class="button" data-role="search-run">
					<?php esc_html_e( 'Ieškoti', 'ai-cake-topper' ); ?>
				</button>
				<span class="aicake-hint" data-role="search-hint" role="status"></span>
			</p>

			<div class="aicake-search__results" data-role="search-results"></div>

			<p class="aicake-terms">
				<?php
				esc_html_e(
					'Rodome tik tuos paveikslėlius, kurių licencija leidžia naudoti komerciškai ir keisti.',
					'ai-cake-topper'
				);
				?>
			</p>

			<div class="aicake-error" data-role="search-error" hidden></div>
		</div>

		<div class="aicake-field" data-role="prompt-branch">
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

		<p class="aicake-actions" data-role="generate-actions">
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

	<section class="aicake-wizard__step" data-step="4" hidden>

		<h2 class="aicake-wizard__heading"><?php esc_html_e( 'Peržiūra', 'ai-cake-topper' ); ?></h2>

		<?php
		/*
		 * The proof is a capture of the editor's own canvas, not a second
		 * rendering of the same thing. Two renderers that must agree is exactly
		 * the browser↔GD parity problem D-033 removed, and it would reappear
		 * here in the place the customer is most likely to notice it.
		 *
		 * It is watermarked, because the preview it is drawn from is.
		 */
		?>
		<div class="aicake-proof">
			<img data-role="proof" alt="<?php esc_attr_e( 'Kaip atrodys jūsų užsakymas', 'ai-cake-topper' ); ?>">
		</div>

		<dl class="aicake-review">
			<div class="aicake-review__row">
				<dt><?php esc_html_e( 'Formatas', 'ai-cake-topper' ); ?></dt>
				<dd data-role="review-format"></dd>
			</div>
			<div class="aicake-review__row">
				<dt><?php esc_html_e( 'Lakšto tipas', 'ai-cake-topper' ); ?></dt>
				<dd data-role="review-sheet"></dd>
			</div>
			<div class="aicake-review__row">
				<dt><?php esc_html_e( 'Užrašas', 'ai-cake-topper' ); ?></dt>
				<dd data-role="review-text"></dd>
			</div>
			<div class="aicake-review__row aicake-review__row--total">
				<dt><?php esc_html_e( 'Kaina', 'ai-cake-topper' ); ?></dt>
				<dd data-role="review-price"></dd>
			</div>
		</dl>

		<p class="aicake-terms">
			<?php esc_html_e( 'Karpysite pagal juodą liniją. Užrašas ir piešinys spausdinami tokie, kokie matomi peržiūroje.', 'ai-cake-topper' ); ?>
		</p>

		<?php
		/*
		 * A real WooCommerce add-to-cart form, posting like any other product.
		 *
		 * The sheet type is posted as its Fields Factory field — WCFF then does
		 * the pricing, the cart display, the order meta and the email itself
		 * (D-036). **The AI field is deliberately not posted at all**: whether
		 * AI was used decides €1, so `CartIntegration` derives it server-side
		 * from whether the design really has a generated image. A flag about
		 * whether money was spent cannot be trusted, not even enough to check.
		 *
		 * **It posts to the cart, not to the product permalink** (D-048).
		 * WooCommerce's add-to-cart handler runs on `wp_loaded` wherever the
		 * post lands, so the action decides where the customer ends up — and
		 * the permalink sent them to a bare product page carrying a duplicate
		 * „AI paveikslėlis" radio and none of the work they had just done.
		 */
		?>
		<form class="aicake-cart-form" method="post"
			action="<?php echo esc_url( apply_filters( 'woocommerce_add_to_cart_form_action', wc_get_cart_url() ) ); ?>">

			<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( (string) $product->get_id() ); ?>">
			<input type="hidden" name="quantity" value="1">
			<input type="hidden" name="aicake_design" data-role="design" value="">
			<input type="hidden" name="" data-role="sheet-field" value="">

			<p class="aicake-actions">
				<button type="button" class="button aicake-back" data-role="back-4">
					<?php esc_html_e( 'Atgal', 'ai-cake-topper' ); ?>
				</button>
				<button type="submit" class="button alt aicake-buy" data-role="buy">
					<?php esc_html_e( 'Į krepšelį', 'ai-cake-topper' ); ?>
				</button>
				<span class="aicake-hint" data-role="hint-4" role="status"></span>
			</p>

		</form>

	</section>

</div>
