<?php
/**
 * The generator, on a product page.
 *
 * Overridable from a theme at yourtheme/ai-cake-topper/generator.php.
 *
 * @package AiCake
 *
 * @var array<string, mixed> $spec  Frontend print spec.
 * @var string[]             $chips Example prompts.
 * @var string               $lead  Lead-time note.
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="aicake" data-aicake data-spec="<?php echo esc_attr( (string) wp_json_encode( $spec ) ); ?>">

	<h3 class="aicake__title"><?php esc_html_e( 'Sukurkite savo piešinį', 'ai-cake-topper' ); ?></h3>

	<div class="aicake__field">
		<label class="aicake__label" for="aicake-prompt">
			<?php esc_html_e( 'Aprašykite, ką norite pavaizduoti', 'ai-cake-topper' ); ?>
		</label>

		<textarea id="aicake-prompt" class="aicake__prompt" rows="3" maxlength="500"
			placeholder="<?php esc_attr_e( 'pvz. linksmas dinozauras su gimtadienio tortu', 'ai-cake-topper' ); ?>"></textarea>

		<div class="aicake__meta">
			<span class="aicake__counter" data-aicake-counter>0 / 500</span>
		</div>

		<?php if ( array() !== $chips ) : ?>
			<div class="aicake__chips">
				<?php
				/*
				 * §15: "People do not know what to type; examples raise output
				 * quality more than any prompt engineering."
				 */
				?>
				<span class="aicake__chips-label"><?php esc_html_e( 'Pavyzdžiai:', 'ai-cake-topper' ); ?></span>
				<?php foreach ( $chips as $chip ) : ?>
					<button type="button" class="aicake__chip" data-aicake-chip><?php echo esc_html( $chip ); ?></button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<p class="aicake__terms">
			<?php
			esc_html_e(
				'Negalime kurti žinomų personažų, prekių ženklų ar tikrų žmonių atvaizdų. Tokius užsakymus tenka atšaukti ir grąžinti pinigus.',
				'ai-cake-topper'
			);
			?>
		</p>
	</div>

	<?php
	/*
	 * No text controls. D-033 moved the text layer into the browser, where it is
	 * composed over the preview and uploaded as a bitmap — and that editor lives
	 * in the wizard, which is how this product is bought (D-034). What stood here
	 * was the old server-rendered path: one string, one placement, one colour,
	 * drawn by GD after the fact.
	 */
	?>

	<button type="button" class="aicake__generate button alt" data-aicake-generate>
		<span data-aicake-generate-label><?php esc_html_e( 'Sukurti piešinį', 'ai-cake-topper' ); ?></span>
		<span class="aicake__remaining" data-aicake-remaining></span>
	</button>

	<div class="aicake__status" data-aicake-status hidden>
		<span class="aicake__spinner" aria-hidden="true"></span>
		<span data-aicake-status-text></span>
	</div>

	<div class="aicake__error" data-aicake-error hidden role="alert"></div>

	<div class="aicake__stage" data-aicake-stage hidden>
		<div class="aicake__preview <?php echo empty( $spec['round'] ) ? 'is-rect' : 'is-round'; ?>"
			style="--aicake-safe: <?php echo esc_attr( (string) $spec['safe_pc'] ); ?>%">
			<img data-aicake-preview alt="<?php esc_attr_e( 'Jūsų piešinio peržiūra', 'ai-cake-topper' ); ?>">
			<span class="aicake__safe" aria-hidden="true"></span>
		</div>
		<p class="aicake__safe-note"><?php esc_html_e( 'Punktyrinė linija — saugi zona. Už jos esantis turinys gali būti nupjautas.', 'ai-cake-topper' ); ?></p>
	</div>

	<?php
	/*
	 * §15: customers routinely prefer generation #2 after seeing #5, and
	 * losing it is infuriating. The images are already stored, so this costs
	 * nothing.
	 */
	?>
	<div class="aicake__history" data-aicake-history hidden>
		<span class="aicake__history-label"><?php esc_html_e( 'Šio apsilankymo piešiniai:', 'ai-cake-topper' ); ?></span>
		<div class="aicake__history-strip" data-aicake-history-strip></div>
	</div>

	<input type="hidden" name="aicake_design" data-aicake-design value="">

	<?php if ( '' !== $lead ) : ?>
		<p class="aicake__lead"><?php echo esc_html( $lead ); ?></p>
	<?php endif; ?>
</div>
