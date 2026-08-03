<?php
/**
 * The review queue (§10 layer 3, §14 point 3).
 *
 * @var AiCake\Admin\ReviewQueue $this   The screen.
 * @var WC_Order[]               $orders Orders awaiting a decision.
 * @var string                   $notice Redirect message key.
 *
 * @package AiCake
 */

use AiCake\Admin\ReviewQueue;

defined( 'ABSPATH' ) || exit;

$aicake_notice_text = $this->notice( $notice );
?>
<div class="wrap aicake-review">

	<h1><?php esc_html_e( 'Peržiūra prieš spausdinimą', 'ai-cake-topper' ); ?></h1>

	<?php if ( '' !== $aicake_notice_text ) : ?>
		<div class="notice notice-<?php echo 'missing' === $notice || 'stale' === $notice ? 'warning' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $aicake_notice_text ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( array() === $orders ) : ?>

		<p class="aicake-review__empty">
			<?php esc_html_e( 'Nieko nelaukia. Visi užsakymai peržiūrėti.', 'ai-cake-topper' ); ?>
		</p>

	<?php else : ?>

		<p class="description">
			<?php
			printf(
				/* translators: %d: how many orders are waiting */
				esc_html__( 'Laukia: %d. Seniausi viršuje.', 'ai-cake-topper' ),
				count( $orders )
			);
			?>
			<br />
			<?php
			/*
			 * §14 asks for keyboard shortcuts because this screen gets used
			 * dozens of times a day, and they are worthless if undiscoverable.
			 */
			esc_html_e( 'Klavišai: J / K — kitas ir ankstesnis · A — patvirtinti · R — atmesti', 'ai-cake-topper' );
			?>
		</p>

		<?php foreach ( $orders as $aicake_order ) : ?>
			<?php $aicake_items = $this->items( $aicake_order ); ?>

			<div class="aicake-review__card" tabindex="0"
				data-order="<?php echo esc_attr( (string) $aicake_order->get_id() ); ?>"
				data-approve="<?php echo esc_url( $this->decision_url( $aicake_order->get_id(), 'approve' ) ); ?>">

				<div class="aicake-review__head">
					<h2>
						<a href="<?php echo esc_url( $aicake_order->get_edit_order_url() ); ?>">
							<?php
							printf(
								/* translators: %s: order number */
								esc_html__( 'Užsakymas #%s', 'ai-cake-topper' ),
								esc_html( $aicake_order->get_order_number() )
							);
							?>
						</a>
					</h2>

					<span class="aicake-review__meta">
						<?php
						echo esc_html(
							trim( $aicake_order->get_formatted_billing_full_name() )
						);
						?>
						·
						<?php echo esc_html( $aicake_order->get_date_created() ? $aicake_order->get_date_created()->date_i18n( 'Y-m-d H:i' ) : '' ); ?>
					</span>
				</div>

				<?php foreach ( $aicake_items as $aicake_item ) : ?>
					<?php
					$aicake_design     = $aicake_item['design'];
					$aicake_moderation = $aicake_item['moderation'];
					$aicake_flagged    = array();

					foreach ( (array) ( $aicake_moderation['categories'] ?? array() ) as $aicake_cat => $aicake_on ) {
						if ( $aicake_on ) {
							$aicake_flagged[] = (string) $aicake_cat;
						}
					}
					?>

					<div class="aicake-review__item">

						<div class="aicake-review__image">
							<?php if ( '' !== $aicake_item['image'] ) : ?>
								<?php
								/*
								 * The print file where there is one — the thing
								 * that reaches the printer. Reviewing the
								 * watermarked preview would approve an image
								 * nobody actually looked at.
								 */
								?>
								<a href="<?php echo esc_url( $aicake_item['image'] ); ?>" target="_blank" rel="noreferrer">
									<img src="<?php echo esc_url( $aicake_item['image'] ); ?>" alt="" loading="lazy" />
								</a>
							<?php else : ?>
								<p class="aicake-review__missing"><?php esc_html_e( 'Failo nėra.', 'ai-cake-topper' ); ?></p>
							<?php endif; ?>
						</div>

						<div class="aicake-review__detail">

							<p class="aicake-review__format"><?php echo esc_html( (string) $aicake_item['format'] ); ?></p>

							<?php
							/*
							 * Both languages. The Lithuanian is what the
							 * customer wrote and the English is what the model
							 * was actually asked for — a mistranslation is
							 * itself a reason to look twice, and it is
							 * invisible if only one is shown (§14).
							 */
							?>
							<dl class="aicake-review__prompts">
								<dt><?php esc_html_e( 'Klientas parašė', 'ai-cake-topper' ); ?></dt>
								<dd><?php echo esc_html( (string) $aicake_design['prompt_raw'] ); ?></dd>

								<?php if ( '' !== (string) ( $aicake_design['prompt_en'] ?? '' ) ) : ?>
									<dt><?php esc_html_e( 'Išversta', 'ai-cake-topper' ); ?></dt>
									<dd><?php echo esc_html( (string) $aicake_design['prompt_en'] ); ?></dd>
								<?php endif; ?>
							</dl>

							<p class="aicake-review__verdict">
								<?php
								$aicake_verdict = (string) ( $aicake_moderation['verdict'] ?? '' );
								$aicake_layer   = (string) ( $aicake_moderation['layer'] ?? '' );
								?>
								<span class="aicake-verdict aicake-verdict--<?php echo esc_attr( '' === $aicake_verdict ? 'none' : $aicake_verdict ); ?>">
									<?php echo esc_html( '' === $aicake_verdict ? __( 'nežinoma', 'ai-cake-topper' ) : $aicake_verdict ); ?>
								</span>

								<?php if ( '' !== $aicake_layer ) : ?>
									<span class="aicake-review__layer">
										<?php
										printf(
											/* translators: %s: which moderation layer decided */
											esc_html__( 'sluoksnis: %s', 'ai-cake-topper' ),
											esc_html( $aicake_layer )
										);
										?>
									</span>
								<?php endif; ?>

								<?php if ( array() !== $aicake_flagged ) : ?>
									<span class="aicake-review__flags">
										<?php echo esc_html( implode( ', ', $aicake_flagged ) ); ?>
									</span>
								<?php endif; ?>
							</p>

							<?php if ( array() !== (array) ( $aicake_moderation['reasons'] ?? array() ) ) : ?>
								<p class="aicake-review__reasons">
									<?php echo esc_html( implode( ' · ', array_map( 'strval', (array) $aicake_moderation['reasons'] ) ) ); ?>
								</p>
							<?php endif; ?>

						</div>
					</div>
				<?php endforeach; ?>

				<?php if ( array() === $aicake_items ) : ?>
					<p class="aicake-review__missing">
						<?php esc_html_e( 'Šiame užsakyme nėra AI piešinių. Patvirtinkite, kad jis iškeliautų iš eilės.', 'ai-cake-topper' ); ?>
					</p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aicake-review__actions">
					<input type="hidden" name="action" value="<?php echo esc_attr( ReviewQueue::ACTION ); ?>" />
					<input type="hidden" name="order" value="<?php echo esc_attr( (string) $aicake_order->get_id() ); ?>" />
					<?php wp_nonce_field( ReviewQueue::ACTION . '_' . $aicake_order->get_id() ); ?>

					<label class="aicake-review__reason">
						<span><?php esc_html_e( 'Atmetimo priežastis (matys klientas)', 'ai-cake-topper' ); ?></span>
						<input type="text" name="reason" data-role="reason" maxlength="300"
							placeholder="<?php esc_attr_e( 'pvz. piešinyje matomas žinomas personažas', 'ai-cake-topper' ); ?>" />
					</label>

					<button type="submit" name="decision" value="approve" class="button button-primary">
						<?php esc_html_e( 'Patvirtinti (A)', 'ai-cake-topper' ); ?>
					</button>

					<button type="submit" name="decision" value="reject" class="button aicake-review__reject">
						<?php esc_html_e( 'Atmesti (R)', 'ai-cake-topper' ); ?>
					</button>

					<?php
					/*
					 * Rejection does not move money. Refunding is irreversible
					 * and may be partial, so the decision is recorded and the
					 * customer told, and the refund is issued in WooCommerce's
					 * own form — the tool the shop's bookkeeping already
					 * expects (§10).
					 */
					?>
					<span class="description aicake-review__refund-note">
						<?php esc_html_e( 'Atmetus — pinigus grąžinkite užsakymo lange.', 'ai-cake-topper' ); ?>
					</span>
				</form>

			</div>
		<?php endforeach; ?>

	<?php endif; ?>

</div>
