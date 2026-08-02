<?php
/**
 * Composition root.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake;

use AiCake\Admin\TestProviderPage;
use AiCake\Domain\DesignRepository;
use AiCake\Domain\JobRepository;
use AiCake\Pipeline\PromptBuilder;
use AiCake\Providers\Image\FalFluxProvider;
use AiCake\Providers\Image\GeminiImageProvider;
use AiCake\Providers\Image\ReplicateProvider;
use AiCake\Providers\ProviderRegistry;
use AiCake\Providers\Text\GeminiTextProvider;
use AiCake\Providers\Upscale\GdUpscaler;
use AiCake\Queue\Dispatcher;
use AiCake\Queue\Runner;
use AiCake\Queue\Scheduler;
use AiCake\Rest\FileEndpoint;
use AiCake\Rest\GenerateEndpoint;
use AiCake\Rest\JobStatusEndpoint;
use AiCake\Rest\RestController;
use AiCake\Rest\SessionEndpoint;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Http;
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

	private Http $http;

	private DesignRepository $designs;

	private ProviderRegistry $providers;

	private JobRepository $jobs;

	private PrivateStorage $storage;

	private PromptBuilder $prompts;

	private Dispatcher $dispatcher;

	private Runner $runner;

	private Scheduler $scheduler;

	private RestController $rest;

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
		$this->http         = new Http( $this->logger );
		$this->designs      = new DesignRepository();
		$this->providers    = $this->build_providers();

		$this->jobs       = new JobRepository();
		$this->storage    = new PrivateStorage( $this->settings, $this->logger );
		$this->prompts    = new PromptBuilder( $this->settings );
		$this->dispatcher = new Dispatcher( $this->logger );

		$this->runner = new Runner(
			$this->jobs,
			$this->designs,
			$this->providers,
			$this->prompts,
			$this->storage,
			$this->budget_guard,
			$this->dispatcher,
			$this->settings,
			$this->logger
		);

		$this->scheduler = new Scheduler( $this->jobs, $this->runner, $this->logger );
		$this->rest      = $this->build_rest();
	}

	/**
	 * Assemble the REST surface.
	 */
	private function build_rest(): RestController {
		return new RestController(
			new SessionEndpoint( $this->identity, $this->rate_limiter ),
			new GenerateEndpoint(
				$this->designs,
				$this->jobs,
				$this->dispatcher,
				$this->rate_limiter,
				$this->budget_guard,
				$this->identity
			),
			new JobStatusEndpoint( $this->jobs, $this->designs, $this->runner, $this->dispatcher, $this->identity ),
			new FileEndpoint( $this->designs, $this->identity, $this->settings )
		);
	}

	/**
	 * Assemble the provider chain.
	 *
	 * Order matters and is a setting, not a constant: Replicate leads because
	 * it is the only image provider that currently runs without credit, and
	 * that will stop being true the moment an account is funded (D-017).
	 */
	private function build_providers(): ProviderRegistry {
		$registry = new ProviderRegistry( $this->settings, $this->logger );

		$registry->add_image_provider( new ReplicateProvider( $this->http, $this->settings, $this->logger ) );
		$registry->add_image_provider( new FalFluxProvider( $this->http, $this->settings ) );
		$registry->add_image_provider( new GeminiImageProvider( $this->http, $this->settings ) );

		$registry->add_text_provider( new GeminiTextProvider( $this->http, $this->settings, $this->logger ) );

		$registry->add_upscaler( new GdUpscaler( $this->logger ) );

		return $registry;
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
		$this->rest->register();
		$this->runner->register();
		$this->scheduler->register();

		add_filter( 'cron_schedules', array( $this->scheduler, 'add_cron_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

		if ( is_admin() ) {
			( new TestProviderPage(
				$this->providers,
				$this->designs,
				$this->budget_guard,
				$this->prompts,
				$this->storage,
				$this->dispatcher,
				$this->settings,
				$this->logger
			) )->register();
		}
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

	/**
	 * Outbound HTTP.
	 */
	public function http(): Http {
		return $this->http;
	}

	/**
	 * Design persistence.
	 */
	public function designs(): DesignRepository {
		return $this->designs;
	}

	/**
	 * The provider chain.
	 */
	public function providers(): ProviderRegistry {
		return $this->providers;
	}

	/**
	 * The work queue.
	 */
	public function jobs(): JobRepository {
		return $this->jobs;
	}

	/**
	 * The worker.
	 */
	public function runner(): Runner {
		return $this->runner;
	}

	/**
	 * Loopback dispatch.
	 */
	public function dispatcher(): Dispatcher {
		return $this->dispatcher;
	}

	/**
	 * The sweeper.
	 */
	public function scheduler(): Scheduler {
		return $this->scheduler;
	}

	/**
	 * Private file storage.
	 */
	public function storage(): PrivateStorage {
		return $this->storage;
	}

	/**
	 * Style suffix application.
	 */
	public function prompts(): PromptBuilder {
		return $this->prompts;
	}
}
