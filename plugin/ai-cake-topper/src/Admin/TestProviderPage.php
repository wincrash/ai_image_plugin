<?php
/**
 * "Test provider" admin screen.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Admin;

use AiCake\Domain\DesignRepository;
use AiCake\Domain\GenerationRequest;
use AiCake\Domain\PromptAnalysis;
use AiCake\Providers\ProviderRegistry;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use AiCake\Throttle\BudgetGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a real Lithuanian prompt through translation, moderation and generation,
 * and shows what came back with cost and latency.
 *
 * PLAN.md §8.5 is explicit that this screen is how the provider decision
 * actually gets made — on real cake-topper prompts rather than benchmarks. It
 * is cheap to build and it is the whole of Phase 0's contact sheet, except
 * pointed at the models we can currently call.
 */
class TestProviderPage {

	private const SLUG = 'aicake-test-provider';

	private const ACTION = 'aicake_test_provider';

	private const RESULT_TRANSIENT = 'aicake_test_result_';

	private ProviderRegistry $registry;

	private DesignRepository $designs;

	private BudgetGuard $budget;

	private Settings $settings;

	private Logger $logger;

	/**
	 * @param ProviderRegistry $registry Providers.
	 * @param DesignRepository $designs  Persistence.
	 * @param BudgetGuard      $budget   Spend ceiling.
	 * @param Settings         $settings Configuration.
	 * @param Logger           $logger   Logging.
	 */
	public function __construct(
		ProviderRegistry $registry,
		DesignRepository $designs,
		BudgetGuard $budget,
		Settings $settings,
		Logger $logger
	) {
		$this->registry = $registry;
		$this->designs  = $designs;
		$this->budget   = $budget;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Add the menu entry.
	 */
	public function add_menu(): void {
		add_menu_page(
			__( 'AI Cake Topper', 'ai-cake-topper' ),
			__( 'AI Cake Topper', 'ai-cake-topper' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-art',
			58
		);
	}

	/**
	 * Handle the form submission, then redirect so a refresh does not re-run it.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-cake-topper' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$prompt   = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$aspect   = isset( $_POST['aspect'] ) ? sanitize_text_field( wp_unslash( $_POST['aspect'] ) ) : '1:1';
		$generate = isset( $_POST['generate'] );

		$result = $this->run( $prompt, $aspect, $generate );

		set_transient( self::RESULT_TRANSIENT . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Translate, moderate, and optionally generate.
	 *
	 * @param string $prompt_lt Lithuanian prompt.
	 * @param string $aspect    Aspect ratio.
	 * @param bool   $generate  Whether to spend money on an image.
	 * @return array<string, mixed>
	 */
	private function run( string $prompt_lt, string $aspect, bool $generate ): array {
		$out = array(
			'prompt_lt' => $prompt_lt,
			'aspect'    => $aspect,
			'analysis'  => null,
			'image'     => null,
			'attempts'  => array(),
			'notices'   => array(),
			'design_id' => 0,
		);

		if ( '' === trim( $prompt_lt ) ) {
			$out['notices'][] = __( 'Enter a prompt first.', 'ai-cake-topper' );

			return $out;
		}

		$analysis         = $this->registry->analyse( $prompt_lt );
		$out['analysis']  = $this->analysis_to_array( $analysis );
		$design_id        = $this->designs->create(
			array(
				'session_key'  => 'admin-test',
				'ip_hash'      => str_repeat( '0', 64 ),
				'user_id'      => get_current_user_id(),
				'prompt_raw'   => $prompt_lt,
				'prompt_en'    => $analysis->prompt_en,
				'aspect'       => $aspect,
				'status'       => DesignRepository::STATUS_RUNNING,
				'moderation'   => $analysis->to_json(),
				'cost_usd'     => $analysis->cost_usd,
				'provider'     => $analysis->provider,
				'model'        => $analysis->model,
			)
		);
		$out['design_id'] = $design_id;

		if ( ! $analysis->ok() ) {
			$out['notices'][] = sprintf(
				/* translators: %s: error detail from the provider */
				__( 'Moderation call failed: %s', 'ai-cake-topper' ),
				$analysis->error
			);
			$this->designs->update(
				$design_id,
				array(
					'status'        => DesignRepository::STATUS_FAILED,
					'error_code'    => 'moderation_failed',
					'error_message' => $analysis->error,
				)
			);

			return $out;
		}

		if ( $analysis->blocked() ) {
			$this->designs->update( $design_id, array( 'status' => DesignRepository::STATUS_REJECTED ) );

			return $out;
		}

		if ( ! $generate ) {
			$out['notices'][] = __( 'Translation and moderation only — no image was generated.', 'ai-cake-topper' );
			$this->designs->update( $design_id, array( 'status' => DesignRepository::STATUS_DONE ) );

			return $out;
		}

		// The guard applies here too. This screen spends real money the moment
		// a funded provider is in the chain.
		$allowed = $this->budget->check( 0.05 );

		if ( is_wp_error( $allowed ) ) {
			$out['notices'][] = sprintf(
				/* translators: %s: reason generation is unavailable */
				__( 'Budget guard stopped this: %s', 'ai-cake-topper' ),
				$allowed->get_error_message()
			);
			$this->designs->update( $design_id, array( 'status' => DesignRepository::STATUS_FAILED ) );

			return $out;
		}

		$prompt_final = $this->apply_style_suffix( $analysis->prompt_en );

		$result = $this->registry->generate(
			new GenerationRequest( $prompt_final, $aspect )
		);

		$out['attempts'] = $this->registry->last_attempts();

		$this->designs->update( $design_id, array( 'prompt_final' => $prompt_final ) );
		$this->designs->record_result( $design_id, $result );

		if ( ! $result->ok ) {
			$out['notices'][] = sprintf(
				/* translators: 1: error code, 2: error detail */
				__( 'Generation failed (%1$s): %2$s', 'ai-cake-topper' ),
				$result->error_code,
				$result->error
			);

			return $out;
		}

		$path = $this->store_master( $result->bytes, (string) ( $this->designs->find( $design_id )['public_id'] ?? '' ) );

		if ( '' !== $path ) {
			$this->designs->update( $design_id, array( 'file_master' => $path ) );
		}

		$out['image'] = array(
			'data_uri'   => 'data:' . $result->mime . ';base64,' . base64_encode( $this->thumbnail( $result->bytes ) ),
			'width'      => $result->width,
			'height'     => $result->height,
			'bytes'      => strlen( $result->bytes ),
			'provider'   => $result->provider,
			'model'      => $result->model,
			'cost_usd'   => $result->cost_usd,
			'latency_ms' => $result->latency_ms,
			'path'       => $path,
			'prompt_en'  => $prompt_final,
		);

		return $out;
	}

	/**
	 * Append the house style suffix.
	 *
	 * Phrased entirely in the positive. A flux-dev test proved the negative
	 * form is ignored: "no cake or background needed" produced exactly a cake
	 * (STATE.md). Tuning this against real output is a Phase 0 deliverable;
	 * this is a working starting point, not the final wording.
	 *
	 * @param string $prompt_en Translated prompt.
	 */
	private function apply_style_suffix( string $prompt_en ): string {
		$suffix = (string) $this->settings->get(
			'style_suffix',
			'flat vector illustration, thick clean outlines, bright saturated flat colours, '
			. 'centred single subject, isolated on a plain solid white background, '
			. 'simple childrens picture book style, no text'
		);

		return '' === $suffix ? $prompt_en : $prompt_en . ', ' . $suffix;
	}

	/**
	 * Write the clean generation to the sessions zone (PLAN.md §12.2).
	 *
	 * @param string $bytes     Image data.
	 * @param string $public_id Design handle.
	 * @return string Absolute path, or '' on failure.
	 */
	private function store_master( string $bytes, string $public_id ): string {
		if ( '' === $public_id ) {
			return '';
		}

		$dir = $this->settings->storage_dir() . '/sessions/' . gmdate( 'Y/m' );

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			$this->logger->error( 'Could not create the storage directory.', array( 'dir' => $dir ) );

			return '';
		}

		$path = $dir . '/' . $public_id . '-master.png';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $path, $bytes );

		return false === $written ? '' : $path;
	}

	/**
	 * Downscale for inline display, so the admin page is not a 2 MB data URI.
	 *
	 * @param string $bytes Full-size image.
	 */
	private function thumbnail( string $bytes ): string {
		$image = @imagecreatefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $image ) {
			return $bytes;
		}

		$scaled = imagescale( $image, 480 );
		imagedestroy( $image );

		if ( false === $scaled ) {
			return $bytes;
		}

		ob_start();
		imagepng( $scaled, null, 6 );
		$out = (string) ob_get_clean();

		imagedestroy( $scaled );

		return $out;
	}

	/**
	 * Flatten an analysis for the transient.
	 *
	 * @param PromptAnalysis $analysis The verdict.
	 * @return array<string, mixed>
	 */
	private function analysis_to_array( PromptAnalysis $analysis ): array {
		return array(
			'prompt_en'  => $analysis->prompt_en,
			'verdict'    => $analysis->verdict,
			'reasons'    => $analysis->reasons,
			'flagged'    => $analysis->flagged(),
			'confidence' => $analysis->confidence,
			'provider'   => $analysis->provider,
			'model'      => $analysis->model,
			'cost_usd'   => $analysis->cost_usd,
			'latency_ms' => $analysis->latency_ms,
			'error'      => $analysis->error,
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$key    = self::RESULT_TRANSIENT . get_current_user_id();
		$result = get_transient( $key );
		delete_transient( $key );

		$prompt = is_array( $result ) ? (string) $result['prompt_lt'] : 'linksmas dinozauras su gimtadienio tortu';

		echo '<div class="wrap"><h1>' . esc_html__( 'Test provider', 'ai-cake-topper' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Runs a real Lithuanian prompt through translation, moderation and image generation. This is how the provider decision gets made.', 'ai-cake-topper' ) . '</p>';

		$this->render_provider_status();
		$this->render_form( $prompt, is_array( $result ) ? (string) $result['aspect'] : '1:1' );

		if ( is_array( $result ) ) {
			$this->render_result( $result );
		}

		echo '</div>';
	}

	/**
	 * Which providers are configured, and today's spend.
	 */
	private function render_provider_status(): void {
		echo '<h2>' . esc_html__( 'Providers', 'ai-cake-topper' ) . '</h2><table class="widefat striped" style="max-width:820px"><thead><tr>';
		echo '<th>' . esc_html__( 'Role', 'ai-cake-topper' ) . '</th><th>' . esc_html__( 'Provider', 'ai-cake-topper' ) . '</th>';
		echo '<th>' . esc_html__( 'Model', 'ai-cake-topper' ) . '</th><th>' . esc_html__( 'Key', 'ai-cake-topper' ) . '</th></tr></thead><tbody>';

		foreach ( $this->registry->image_providers() as $index => $provider ) {
			printf(
				'<tr><td>%s</td><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>',
				esc_html( 0 === $index ? __( 'Image (primary)', 'ai-cake-topper' ) : __( 'Image (fallback)', 'ai-cake-topper' ) ),
				esc_html( $provider->id() ),
				esc_html( $provider->model() ),
				$provider->is_configured()
					? esc_html__( 'defined', 'ai-cake-topper' )
					: '<strong>' . esc_html__( 'missing', 'ai-cake-topper' ) . '</strong>'
			);
		}

		$text = $this->registry->text_provider();
		printf(
			'<tr><td>%s</td><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>',
			esc_html__( 'Translate + moderate', 'ai-cake-topper' ),
			esc_html( null === $text ? '—' : $text->id() ),
			esc_html( null === $text ? '—' : $text->model() ),
			null === $text ? '<strong>' . esc_html__( 'missing', 'ai-cake-topper' ) . '</strong>' : esc_html__( 'defined', 'ai-cake-topper' )
		);

		$upscaler = $this->registry->upscaler();
		printf(
			'<tr><td>%s</td><td><code>%s</code></td><td>—</td><td>%s</td></tr>',
			esc_html__( 'Upscale', 'ai-cake-topper' ),
			esc_html( null === $upscaler ? '—' : $upscaler->id() ),
			null === $upscaler ? '<strong>' . esc_html__( 'missing', 'ai-cake-topper' ) . '</strong>' : esc_html__( 'available', 'ai-cake-topper' )
		);

		echo '</tbody></table>';

		printf(
			'<p><strong>%s</strong> $%s &nbsp;·&nbsp; <strong>%s</strong> $%s</p>',
			esc_html__( 'Spent today:', 'ai-cake-topper' ),
			esc_html( number_format( $this->budget->spent_today(), 4 ) ),
			esc_html__( 'this month:', 'ai-cake-topper' ),
			esc_html( number_format( $this->budget->spent_this_month(), 4 ) )
		);
	}

	/**
	 * The prompt form.
	 *
	 * @param string $prompt Prefilled prompt.
	 * @param string $aspect Selected aspect.
	 */
	private function render_form( string $prompt, string $aspect ): void {
		echo '<h2>' . esc_html__( 'Prompt', 'ai-cake-topper' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		echo '<p><textarea name="prompt" rows="3" style="width:100%;max-width:820px" placeholder="'
			. esc_attr__( 'Lithuanian, as a customer would type it', 'ai-cake-topper' ) . '">'
			. esc_textarea( $prompt ) . '</textarea></p>';

		echo '<p><label>' . esc_html__( 'Aspect', 'ai-cake-topper' ) . ' <select name="aspect">';
		foreach ( array( '1:1', '2:3', '3:2', '4:5' ) as $option ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option ),
				selected( $aspect, $option, false ),
				esc_html( $option )
			);
		}
		echo '</select></label></p>';

		echo '<p><label><input type="checkbox" name="generate" value="1" checked> '
			. esc_html__( 'Generate an image (costs money once a provider is funded)', 'ai-cake-topper' )
			. '</label></p>';

		submit_button( __( 'Run', 'ai-cake-topper' ) );
		echo '</form>';
	}

	/**
	 * Show what came back.
	 *
	 * @param array<string, mixed> $result Stored run result.
	 */
	private function render_result( array $result ): void {
		foreach ( (array) $result['notices'] as $notice ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( (string) $notice ) . '</p></div>';
		}

		$analysis = $result['analysis'];

		if ( is_array( $analysis ) ) {
			$colours = array(
				PromptAnalysis::ALLOW  => '#008a20',
				PromptAnalysis::REVIEW => '#bd8600',
				PromptAnalysis::BLOCK  => '#d63638',
			);

			echo '<h2>' . esc_html__( 'Translation and moderation', 'ai-cake-topper' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:820px"><tbody>';

			printf(
				'<tr><th style="width:180px">%s</th><td><span style="color:%s;font-weight:600">%s</span> (%s %s)</td></tr>',
				esc_html__( 'Verdict', 'ai-cake-topper' ),
				esc_attr( $colours[ $analysis['verdict'] ] ?? '#1d2327' ),
				esc_html( strtoupper( (string) $analysis['verdict'] ) ),
				esc_html__( 'confidence', 'ai-cake-topper' ),
				esc_html( number_format( (float) $analysis['confidence'], 2 ) )
			);

			printf(
				'<tr><th>%s</th><td><em>%s</em></td></tr>',
				esc_html__( 'English', 'ai-cake-topper' ),
				esc_html( (string) $analysis['prompt_en'] )
			);

			if ( array() !== (array) $analysis['flagged'] ) {
				printf(
					'<tr><th>%s</th><td><code>%s</code></td></tr>',
					esc_html__( 'Categories', 'ai-cake-topper' ),
					esc_html( implode( ', ', (array) $analysis['flagged'] ) )
				);
			}

			if ( array() !== (array) $analysis['reasons'] ) {
				printf(
					'<tr><th>%s</th><td><code>%s</code></td></tr>',
					esc_html__( 'Reasons', 'ai-cake-topper' ),
					esc_html( implode( ', ', (array) $analysis['reasons'] ) )
				);
			}

			printf(
				'<tr><th>%s</th><td>%s / <code>%s</code> · $%s · %d ms</td></tr>',
				esc_html__( 'Served by', 'ai-cake-topper' ),
				esc_html( (string) $analysis['provider'] ),
				esc_html( (string) $analysis['model'] ),
				esc_html( number_format( (float) $analysis['cost_usd'], 6 ) ),
				(int) $analysis['latency_ms']
			);

			echo '</tbody></table>';
		}

		if ( array() !== (array) $result['attempts'] ) {
			echo '<h2>' . esc_html__( 'Provider chain', 'ai-cake-topper' ) . '</h2>';
			echo '<table class="widefat striped" style="max-width:820px"><thead><tr><th>'
				. esc_html__( 'Provider', 'ai-cake-topper' ) . '</th><th>' . esc_html__( 'Model', 'ai-cake-topper' )
				. '</th><th>' . esc_html__( 'Result', 'ai-cake-topper' ) . '</th><th>' . esc_html__( 'ms', 'ai-cake-topper' )
				. '</th></tr></thead><tbody>';

			foreach ( (array) $result['attempts'] as $attempt ) {
				printf(
					'<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td><td>%d</td></tr>',
					esc_html( (string) $attempt['provider'] ),
					esc_html( (string) $attempt['model'] ),
					$attempt['ok']
						? '<span style="color:#008a20">' . esc_html__( 'ok', 'ai-cake-topper' ) . '</span>'
						: '<span style="color:#d63638">' . esc_html( (string) $attempt['code'] ) . '</span> — ' . esc_html( (string) $attempt['error'] ),
					(int) $attempt['ms']
				);
			}

			echo '</tbody></table>';
		}

		$image = $result['image'];

		if ( is_array( $image ) ) {
			echo '<h2>' . esc_html__( 'Result', 'ai-cake-topper' ) . '</h2>';
			printf(
				'<p><img src="%s" alt="" style="max-width:480px;height:auto;border:1px solid #c3c4c7;background:#fff"></p>',
				esc_attr( (string) $image['data_uri'] )
			);
			printf(
				'<p>%s / <code>%s</code> · %d×%d · %s KB · $%s · %d ms</p>',
				esc_html( (string) $image['provider'] ),
				esc_html( (string) $image['model'] ),
				(int) $image['width'],
				(int) $image['height'],
				esc_html( number_format( $image['bytes'] / 1024, 1 ) ),
				esc_html( number_format( (float) $image['cost_usd'], 5 ) ),
				(int) $image['latency_ms']
			);
			printf(
				'<p class="description">%s <code>%s</code></p>',
				esc_html__( 'Full-size master saved to', 'ai-cake-topper' ),
				esc_html( (string) $image['path'] )
			);
			printf(
				'<p class="description">%s <em>%s</em></p>',
				esc_html__( 'Prompt actually sent:', 'ai-cake-topper' ),
				esc_html( (string) $image['prompt_en'] )
			);
		}
	}
}
