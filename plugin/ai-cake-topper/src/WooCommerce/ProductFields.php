<?php
/**
 * The "AI Topper" tab on the product screen.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\WooCommerce;

use AiCake\Domain\PrintSpec;
use AiCake\Imaging\SheetLayout;
use AiCake\Support\Mm;

defined( 'ABSPATH' ) || exit;

/**
 * Print geometry, per product (PLAN.md §4.2).
 *
 * The live summary is the point of this screen. §4.2: "That single line
 * prevents most of the ways this can be misconfigured." It is computed on the
 * server by the same PrintSpec the pipeline uses, over AJAX — reimplementing
 * the print maths in JavaScript would give two answers that drift, and the
 * one the admin reads would be the one that is not printing anything.
 */
class ProductFields {

	private const AJAX_ACTION = 'aicake_print_summary';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_summary' ) );
	}

	/**
	 * Add the tab.
	 *
	 * @param array<string, mixed> $tabs Registered tabs.
	 * @return array<string, mixed>
	 */
	public function add_tab( array $tabs ): array {
		$tabs['aicake'] = array(
			'label'    => __( 'AI Topper', 'ai-cake-topper' ),
			'target'   => 'aicake_product_data',
			'class'    => array(),
			'priority' => 65,
		);

		return $tabs;
	}

	/**
	 * The fields.
	 */
	public function render_panel(): void {
		global $post;

		$spec = PrintSpec::for_product( (int) $post->ID );

		echo '<div id="aicake_product_data" class="panel woocommerce_options_panel hidden">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_aicake_enabled',
				'label'       => __( 'AI design', 'ai-cake-topper' ),
				'description' => __( 'Let customers generate the artwork for this product.', 'ai-cake-topper' ),
				'value'       => $spec->enabled ? 'yes' : 'no',
			)
		);

		echo '<div class="options_group aicake-geometry">';

		woocommerce_wp_select(
			array(
				'id'      => '_aicake_shape',
				'label'   => __( 'Shape', 'ai-cake-topper' ),
				'options' => array(
					PrintSpec::SHAPE_ROUND => __( 'Round', 'ai-cake-topper' ),
					PrintSpec::SHAPE_RECT  => __( 'Rectangular', 'ai-cake-topper' ),
				),
				'value'   => $spec->shape,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_aicake_width_mm',
				'label'             => __( 'Diameter / width (mm)', 'ai-cake-topper' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.5',
					'min'  => '10',
				),
				'value'             => (string) $spec->width_mm,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_aicake_height_mm',
				'label'             => __( 'Height (mm)', 'ai-cake-topper' ),
				'description'       => __( 'Rectangular products only.', 'ai-cake-topper' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.5',
					'min'  => '0',
				),
				'value'             => (string) $spec->height_mm,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_aicake_copies',
				'label'             => __( 'Pieces per sheet', 'ai-cake-topper' ),
				'description'       => __( '1 for a single topper. For cupcake sheets the geometry decides the real number — check the summary below.', 'ai-cake-topper' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '1',
				),
				'value'             => (string) $spec->copies,
			)
		);

		echo '</div><div class="options_group aicake-geometry">';

		woocommerce_wp_text_input(
			array(
				'id'                => '_aicake_bleed_mm',
				'label'             => __( 'Bleed (mm)', 'ai-cake-topper' ),
				'description'       => __( 'Image extends this far past the cut line, so a slightly off cut leaves no white edge.', 'ai-cake-topper' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.5',
					'min'  => '0',
				),
				'value'             => (string) $spec->bleed_mm,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_aicake_safe_mm',
				'label'             => __( 'Safe margin (mm)', 'ai-cake-topper' ),
				'description'       => __( 'Nothing important, especially text, goes within this of the cut line.', 'ai-cake-topper' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '0.5',
					'min'  => '0',
				),
				'value'             => (string) $spec->safe_mm,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_aicake_sheet_w_mm',
				'label'             => __( 'Usable sheet width (mm)', 'ai-cake-topper' ),
				'description'       => __( 'The printable area, not the paper size — edible printers cannot reach the edge. Getting this wrong ruins whole sheets.', 'ai-cake-topper' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '1' ),
				'value'             => (string) $spec->sheet_w_mm,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_aicake_sheet_h_mm',
				'label'             => __( 'Usable sheet height (mm)', 'ai-cake-topper' ),
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '1' ),
				'value'             => (string) $spec->sheet_h_mm,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_aicake_dpi',
				'label'             => __( 'Print resolution (DPI)', 'ai-cake-topper' ),
				'type'              => 'number',
				'custom_attributes' => array( 'step' => '1' ),
				'value'             => (string) $spec->dpi,
			)
		);

		echo '</div>';

		printf(
			'<div class="options_group"><p style="padding:0 12px"><strong>%s</strong></p>'
			. '<p id="aicake-summary" style="padding:0 12px;font-family:monospace">%s</p></div>',
			esc_html__( 'Computed', 'ai-cake-topper' ),
			esc_html( $spec->summary() )
		);

		$this->render_common_sizes();
		$this->render_script();

		echo '</div>';
	}

	/**
	 * A reference table, so the admin can see what the shop actually sells
	 * without doing the arithmetic.
	 */
	private function render_common_sizes(): void {
		echo '<div class="options_group"><p style="padding:0 12px"><strong>'
			. esc_html__( 'Common sizes', 'ai-cake-topper' ) . '</strong></p>';
		echo '<table class="widefat striped" style="margin:0 12px 12px;width:auto"><thead><tr>'
			. '<th>' . esc_html__( 'Circle', 'ai-cake-topper' ) . '</th>'
			. '<th>' . esc_html__( 'Grid', 'ai-cake-topper' ) . '</th>'
			. '<th>' . esc_html__( 'Per A4 sheet', 'ai-cake-topper' ) . '</th></tr></thead><tbody>';

		foreach ( array( 40.0, 45.0, 50.0, 60.0 ) as $diameter ) {
			$plan = SheetLayout::plan( $diameter );

			printf(
				'<tr><td>%s mm</td><td>%d × %d</td><td><strong>%d</strong></td></tr>',
				esc_html( (string) (int) $diameter ),
				(int) $plan['cols'],
				(int) $plan['rows'],
				(int) $plan['per_sheet']
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Recompute the summary as the admin types.
	 */
	private function render_script(): void {
		$nonce = wp_create_nonce( self::AJAX_ACTION );

		?>
		<script>
		( function () {
			var panel = document.getElementById( 'aicake_product_data' );
			if ( ! panel ) { return; }

			var out = document.getElementById( 'aicake-summary' );
			var keys = [ '_aicake_shape', '_aicake_width_mm', '_aicake_height_mm', '_aicake_copies',
				'_aicake_bleed_mm', '_aicake_safe_mm', '_aicake_sheet_w_mm', '_aicake_sheet_h_mm', '_aicake_dpi' ];
			var timer = null;

			function refresh() {
				var body = new FormData();
				body.append( 'action', '<?php echo esc_js( self::AJAX_ACTION ); ?>' );
				body.append( '_wpnonce', '<?php echo esc_js( $nonce ); ?>' );

				keys.forEach( function ( key ) {
					var field = panel.querySelector( '[name="' + key + '"]' );
					body.append( key, field ? field.value : '' );
				} );

				fetch( ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( data ) {
						if ( data && data.success ) { out.textContent = data.data.summary; }
					} )
					.catch( function () { /* leave the last good value in place */ } );
			}

			keys.forEach( function ( key ) {
				var field = panel.querySelector( '[name="' + key + '"]' );
				if ( ! field ) { return; }
				field.addEventListener( 'input', function () {
					clearTimeout( timer );
					timer = setTimeout( refresh, 250 );
				} );
				field.addEventListener( 'change', refresh );
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Recompute the summary from unsaved field values.
	 */
	public function ajax_summary(): void {
		if ( ! current_user_can( 'edit_products' ) || ! check_ajax_referer( self::AJAX_ACTION, '_wpnonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'ai-cake-topper' ) ), 403 );
		}

		$spec = new PrintSpec(
			true,
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
			PrintSpec::SHAPE_RECT === ( $_POST['_aicake_shape'] ?? '' ) ? PrintSpec::SHAPE_RECT : PrintSpec::SHAPE_ROUND,
			max( 10.0, (float) ( $_POST['_aicake_width_mm'] ?? 150 ) ),
			max( 0.0, (float) ( $_POST['_aicake_height_mm'] ?? 0 ) ),
			max( 0.0, (float) ( $_POST['_aicake_bleed_mm'] ?? Mm::BLEED_MM ) ),
			max( 0.0, (float) ( $_POST['_aicake_safe_mm'] ?? Mm::SAFE_MM ) ),
			max( 1, (int) ( $_POST['_aicake_copies'] ?? 1 ) ),
			'a4',
			max( 10.0, (float) ( $_POST['_aicake_sheet_w_mm'] ?? SheetLayout::USABLE_WIDTH_MM ) ),
			max( 10.0, (float) ( $_POST['_aicake_sheet_h_mm'] ?? SheetLayout::USABLE_HEIGHT_MM ) ),
			max( 72, (int) ( $_POST['_aicake_dpi'] ?? Mm::PRINT_DPI ) )
			// phpcs:enable
		);

		if ( PrintSpec::SHAPE_RECT === $spec->shape && $spec->height_mm <= 0 ) {
			$spec->height_mm = 297.0;
		}

		wp_send_json_success( array( 'summary' => $spec->summary() ) );
	}

	/**
	 * Persist the fields.
	 *
	 * @param int $product_id Product being saved.
	 */
	public function save( int $product_id ): void {
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}

		// WooCommerce has already verified its own nonce by this point.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		update_post_meta( $product_id, '_aicake_enabled', isset( $_POST['_aicake_enabled'] ) ? 1 : 0 );

		$numbers = array(
			'_aicake_width_mm'   => 150.0,
			'_aicake_height_mm'  => 0.0,
			'_aicake_bleed_mm'   => Mm::BLEED_MM,
			'_aicake_safe_mm'    => Mm::SAFE_MM,
			'_aicake_sheet_w_mm' => SheetLayout::USABLE_WIDTH_MM,
			'_aicake_sheet_h_mm' => SheetLayout::USABLE_HEIGHT_MM,
		);

		foreach ( $numbers as $key => $default ) {
			$value = isset( $_POST[ $key ] ) ? (float) wp_unslash( $_POST[ $key ] ) : $default;
			update_post_meta( $product_id, $key, max( 0.0, $value ) );
		}

		update_post_meta( $product_id, '_aicake_copies', max( 1, (int) ( $_POST['_aicake_copies'] ?? 1 ) ) );
		update_post_meta( $product_id, '_aicake_dpi', max( 72, (int) ( $_POST['_aicake_dpi'] ?? Mm::PRINT_DPI ) ) );

		$shape = isset( $_POST['_aicake_shape'] ) ? sanitize_key( wp_unslash( $_POST['_aicake_shape'] ) ) : PrintSpec::SHAPE_ROUND;
		update_post_meta( $product_id, '_aicake_shape', PrintSpec::SHAPE_RECT === $shape ? PrintSpec::SHAPE_RECT : PrintSpec::SHAPE_ROUND );
		// phpcs:enable
	}
}
