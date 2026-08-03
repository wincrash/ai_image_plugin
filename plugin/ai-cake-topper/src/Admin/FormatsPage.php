<?php
/**
 * Every format the wizard offers, drawn as it will actually be imposed.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Admin;

use AiCake\Domain\FormatCatalogue;
use AiCake\Domain\PrintSpec;
use AiCake\Imaging\SheetLayout;
use AiCake\Support\Mm;

defined( 'ABSPATH' ) || exit;

/**
 * The screen that makes derived layouts inspectable (D-038).
 *
 * Ruslan asked to hardcode the arrangements, reasonably: a frozen table is
 * something you can look at and trust. The list of sizes *is* frozen; the
 * arrangement is not, because a frozen layout encodes the usable area
 * implicitly and goes silently wrong the moment a margin changes — the file
 * still looks right and only the printed sheet disagrees.
 *
 * This page is what hardcoding was actually buying. Every offered size, drawn
 * from the same `SheetLayout` call the pipeline uses, on one page. It re-derives
 * instead of going stale, and it is the fastest way to spot a size that fits on
 * paper but not in reality before printing eighteen of them.
 */
class FormatsPage {

	public const SLUG = 'aicake-formats';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	/**
	 * Add the submenu entry.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'aicake-test-provider',
			__( 'Print formats', 'ai-cake-topper' ),
			__( 'Print formats', 'ai-cake-topper' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Draw the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-cake-topper' ), '', array( 'response' => 403 ) );
		}

		$usable_w = SheetLayout::USABLE_WIDTH_MM;
		$usable_h = SheetLayout::USABLE_HEIGHT_MM;
		$options  = FormatCatalogue::options( $usable_w, $usable_h );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Print formats', 'ai-cake-topper' ) . '</h1>';

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: usable width mm, 2: usable height mm, 3: icing shortfall mm */
					__( 'Usable area %1$s × %2$s mm — full A4 less the %3$s mm at the end of the sheet that carries no icing. Nothing is deducted for printer margins. Every layout below is derived from these numbers, not stored.', 'ai-cake-topper' ),
					$this->mm( $usable_w ),
					$this->mm( $usable_h ),
					$this->mm( SheetLayout::ICING_SHORTFALL_MM )
				)
			)
		);

		echo '<style>
			.aicake-formats { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 18px; }
			.aicake-format { background: #fff; border: 1px solid #c3c4c7; padding: 12px; width: 210px; }
			.aicake-format h2 { font-size: 13px; margin: 0 0 6px; }
			.aicake-format dl { margin: 8px 0 0; font-size: 12px; color: #50575e; }
			.aicake-format dt { float: left; clear: left; width: 78px; }
			.aicake-format dd { margin: 0 0 2px 82px; }
			.aicake-format.is-unfit { border-color: #d63638; }
			.aicake-format .warn { color: #d63638; font-weight: 600; }
		</style>';

		echo '<div class="aicake-formats">';

		foreach ( $options as $option ) {
			$this->card( $option, $usable_w, $usable_h );
		}

		echo '</div></div>';
	}

	/**
	 * One format.
	 *
	 * @param array<string, mixed> $option   Catalogue entry.
	 * @param float                $usable_w Usable width.
	 * @param float                $usable_h Usable height.
	 */
	private function card( array $option, float $usable_w, float $usable_h ): void {
		$spec = FormatCatalogue::spec(
			(string) $option['type'],
			(float) $option['diameter_mm'],
			$usable_w,
			$usable_h
		);

		printf(
			'<div class="aicake-format%s"><h2>%s</h2>',
			$option['fits'] ? '' : ' is-unfit',
			esc_html( (string) $option['label'] )
		);

		$this->diagram( $option, $usable_w, $usable_h );

		echo '<dl>';

		$this->row( __( 'Grid', 'ai-cake-topper' ), sprintf( '%d × %d', (int) $option['cols'], (int) $option['rows'] ) );
		$this->row( __( 'Per sheet', 'ai-cake-topper' ), (string) (int) $option['per_sheet'] );

		if ( $spec instanceof PrintSpec ) {
			list( $w_px, $h_px ) = $spec->target_px();

			$this->row( __( 'Piece', 'ai-cake-topper' ), sprintf( '%d × %d px', $w_px, $h_px ) );
			$this->row(
				__( 'Upscale', 'ai-cake-topper' ),
				1 === $spec->upscale_factor() ? __( 'none', 'ai-cake-topper' ) : $spec->upscale_factor() . '×'
			);
		}

		$this->row( __( 'Bleed', 'ai-cake-topper' ), $this->mm( (float) $option['bleed_mm'] ) . ' mm' );

		echo '</dl>';

		if ( ! $option['fits'] ) {
			printf(
				'<p class="warn">%s</p>',
				esc_html__( 'Does not fit — not offered to customers.', 'ai-cake-topper' )
			);
		} elseif ( ! empty( $option['bleed_clipped'] ) ) {
			/*
			 * Advisory, not a fault. The trim line is inside the usable area,
			 * which is what decides whether a piece can be cut at its stated
			 * size; only the outermost pieces lose part of their bleed. Said
			 * here so it is a known property rather than a surprise on a
			 * printed sheet.
			 */
			printf(
				'<p><em>%s</em></p>',
				esc_html__( 'Outer pieces lose some bleed at the sheet edge.', 'ai-cake-topper' )
			);
		}

		echo '</div>';
	}

	/**
	 * The sheet, drawn to scale.
	 *
	 * Full A4 is drawn rather than just the usable area, so the bare icing
	 * strip is visible as a thing that exists rather than as a number that was
	 * already subtracted. A layout that looks correct against the usable area
	 * and wrong against the paper is exactly the mistake this catches.
	 *
	 * @param array<string, mixed> $option   Catalogue entry.
	 * @param float                $usable_w Usable width.
	 * @param float                $usable_h Usable height.
	 */
	private function diagram( array $option, float $usable_w, float $usable_h ): void {
		$paper_w = 210.0;
		$paper_h = 297.0;

		$svg = sprintf(
			'<svg viewBox="0 0 %1$s %2$s" width="184" height="%3$d" role="img" style="background:#fff">',
			$paper_w,
			$paper_h,
			(int) round( 184 * ( $paper_h / $paper_w ) )
		);

		// The paper, and the strip at the end with no icing on it.
		$svg .= sprintf(
			'<rect x="0" y="0" width="%1$s" height="%2$s" fill="none" stroke="#c3c4c7" stroke-width="0.8"/>',
			$paper_w,
			$paper_h
		);

		$svg .= sprintf(
			'<rect x="0" y="%1$s" width="%2$s" height="%3$s" fill="#f0f0f1"/>',
			$usable_h,
			$paper_w,
			$paper_h - $usable_h
		);

		if ( FormatCatalogue::TYPE_SHEET === $option['type'] ) {
			$svg .= sprintf(
				'<rect x="0" y="0" width="%1$s" height="%2$s" fill="#dcdcde" stroke="#1d2327" stroke-width="0.6"/>',
				$usable_w,
				$usable_h
			);
		} else {
			$plan = SheetLayout::plan( (float) $option['diameter_mm'], $usable_w, $usable_h, (float) $option['bleed_mm'] );

			foreach ( (array) $plan['centres_px'] as $centre ) {
				$cx = Mm::to_mm( (int) $centre['x'] );
				$cy = Mm::to_mm( (int) $centre['y'] );

				// Bleed first, then the trim line on top — the trim line is
				// what the customer cuts along (D-033), so it is the solid one.
				$svg .= sprintf(
					'<circle cx="%1$s" cy="%2$s" r="%3$s" fill="#f6f7f7" stroke="#dcdcde" stroke-width="0.4"/>',
					$cx,
					$cy,
					( (float) $option['diameter_mm'] / 2 ) + (float) $option['bleed_mm']
				);

				$svg .= sprintf(
					'<circle cx="%1$s" cy="%2$s" r="%3$s" fill="none" stroke="#1d2327" stroke-width="0.6"/>',
					$cx,
					$cy,
					(float) $option['diameter_mm'] / 2
				);
			}
		}

		$svg .= '</svg>';

		echo wp_kses(
			$svg,
			array(
				'svg'    => array(
					'viewbox' => true,
					'width'   => true,
					'height'  => true,
					'role'    => true,
					'style'   => true,
				),
				'rect'   => array(
					'x'            => true,
					'y'            => true,
					'width'        => true,
					'height'       => true,
					'fill'         => true,
					'stroke'       => true,
					'stroke-width' => true,
				),
				'circle' => array(
					'cx'           => true,
					'cy'           => true,
					'r'            => true,
					'fill'         => true,
					'stroke'       => true,
					'stroke-width' => true,
				),
			)
		);
	}

	/**
	 * One definition row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private function row( string $label, string $value ): void {
		printf( '<dt>%s</dt><dd>%s</dd>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * Trim a trailing .0 from a millimetre figure.
	 *
	 * @param float $value Millimetres.
	 */
	private function mm( float $value ): string {
		return rtrim( rtrim( number_format( $value, 1, '.', '' ), '0' ), '.' );
	}
}
