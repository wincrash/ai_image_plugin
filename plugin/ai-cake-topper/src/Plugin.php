<?php
/**
 * Composition root.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake;

use AiCake\Support\Logger;
use AiCake\Support\Settings;
use AiCake\Throttle\BudgetGuard;
use AiCake\Throttle\IdentityResolver;
use AiCake\Throttle\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * The only class that knows how the others are wired.
 *
 * Everything else takes its dependencies in the constructor, which is what
 * lets phases 1–5 be exercised with no WooCommerce loaded at all
 * (PLAN.md §19).
 */
class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;

	private Logger $logger;

	private Capabilities $capabilities;

	private IdentityResolver $identity;

	private RateLimiter $rate_limiter;

	private BudgetGuard $budget_guard;

	/**
	 * Build the object graph. No hooks are registered here.
	 */
	public function __construct() {
		$this->settings     = new Settings();
		$this->logger       = new Logger( $this->settings );
		$this->capabilities = new Capabilities( $this->settings );
		$this->identity     = new IdentityResolver( $this->settings );
		$this->rate_limiter = new RateLimiter( $this->settings, $this->identity );
		$this->budget_guard = new BudgetGuard( $this->settings, $this->logger );
	}

	/**
	 * The booted instance, for code that cannot be constructor-injected
	 * (hook callbacks registered by WordPress itself, mostly).
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		self::$instance = $this;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'admin_notices', array( $this, 'capability_notice' ) );

		$this->capabilities->register();
	}

	/**
	 * Load translations.
	 *
	 * On init, not earlier: since WP 6.7 an early load_plugin_textdomain()
	 * triggers a _doing_it_wrong notice.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'ai-cake-topper', false, dirname( plugin_basename( AICAKE_FILE ) ) . '/languages' );
	}

	/**
	 * Warn an administrator when the host cannot do what the pipeline needs.
	 */
	public function capability_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || $this->capabilities->is_ready() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			sprintf(
				/* translators: %s: link to the Site Health screen */
				wp_kses(
					__( 'AI Cake Topper cannot run on this server yet. See %s for what is missing.', 'ai-cake-topper' ),
					array( 'a' => array( 'href' => array() ) )
				),
				'<a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">' . esc_html__( 'Site Health', 'ai-cake-topper' ) . '</a>'
			)
		);
	}

	/**
	 * Configuration.
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Logging.
	 */
	public function logger(): Logger {
		return $this->logger;
	}

	/**
	 * Host capability detection.
	 */
	public function capabilities(): Capabilities {
		return $this->capabilities;
	}

	/**
	 * Visitor identity.
	 */
	public function identity(): IdentityResolver {
		return $this->identity;
	}

	/**
	 * Per-identity limits.
	 */
	public function rate_limiter(): RateLimiter {
		return $this->rate_limiter;
	}

	/**
	 * Spend ceiling.
	 */
	public function budget_guard(): BudgetGuard {
		return $this->budget_guard;
	}
}
