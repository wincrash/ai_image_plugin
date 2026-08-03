<?php
/**
 * Composition root.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake;

use AiCake\Admin\BlocklistPage;
use AiCake\Admin\FormatsPage;
use AiCake\Admin\OrderScreen;
use AiCake\Admin\ReviewQueue;
use AiCake\Admin\TestProviderPage;
use AiCake\Domain\DesignRepository;
use AiCake\Domain\JobRepository;
use AiCake\Frontend\Generator;
use AiCake\Frontend\Wizard;
use AiCake\Imaging\FontCatalogue;
use AiCake\Imaging\GdEngine;
use AiCake\Imaging\LayerInspector;
use AiCake\Imaging\Watermarker;
use AiCake\Moderation\Blocklist;
use AiCake\Moderation\Moderator;
use AiCake\Moderation\Sanitiser;
use AiCake\Pipeline\FulfilPipeline;
use AiCake\Pipeline\LayoutSuggester;
use AiCake\Pipeline\PreviewPipeline;
use AiCake\Pipeline\ProofPipeline;
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
use AiCake\Rest\LayoutEndpoint;
use AiCake\Rest\RestController;
use AiCake\Rest\TextLayerEndpoint;
use AiCake\Rest\SessionEndpoint;
use AiCake\Storage\OrderArchive;
use AiCake\Storage\PrivateStorage;
use AiCake\Support\Http;
use AiCake\Support\Logger;
use AiCake\Support\Settings;
use AiCake\Throttle\BudgetGuard;
use AiCake\WooCommerce\CartIntegration;
use AiCake\WooCommerce\FieldsFactory;
use AiCake\WooCommerce\Fulfilment;
use AiCake\WooCommerce\OrderStatuses;
use AiCake\WooCommerce\ProductFields;
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

	private GdEngine $images;

	private FontCatalogue $fonts;

	private Watermarker $watermarker;

	private Moderator $moderator;

	private PreviewPipeline $previews;

	private ProofPipeline $proofs;

	private FulfilPipeline $prints;

	private OrderArchive $archive;

	private Fulfilment $fulfilment;

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

		$this->images      = new GdEngine( $this->logger );
		$this->fonts       = new FontCatalogue( $this->logger );
		$this->watermarker = new Watermarker( $this->fonts, $this->logger, $this->settings );

		$this->moderator = new Moderator(
			new Sanitiser(),
			new Blocklist(),
			$this->providers,
			$this->logger
		);

		$this->proofs = new ProofPipeline( $this->images, $this->storage, $this->logger );

		$this->previews = new PreviewPipeline(
			$this->images,
			$this->watermarker,
			$this->storage,
			$this->settings,
			$this->logger
		);

		$this->runner = new Runner(
			$this->jobs,
			$this->designs,
			$this->providers,
			$this->moderator,
			$this->prompts,
			$this->previews,
			$this->storage,
			$this->budget_guard,
			$this->dispatcher,
			$this->settings,
			$this->logger
		);

		$this->prints  = new FulfilPipeline( $this->images, $this->providers, $this->logger );
		$this->archive = new OrderArchive( $this->storage, $this->designs, $this->logger );

		$this->fulfilment = new Fulfilment(
			$this->designs,
			$this->prints,
			$this->archive,
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
				$this->identity,
				$this->moderator
			),
			new JobStatusEndpoint( $this->jobs, $this->designs, $this->runner, $this->dispatcher, $this->identity ),
			new FileEndpoint( $this->designs, $this->identity, $this->settings ),
			new TextLayerEndpoint(
				$this->designs,
				$this->identity,
				$this->moderator,
				$this->images,
				new LayerInspector( $this->logger ),
				$this->storage,
				$this->proofs,
				$this->logger
			),
			new LayoutEndpoint(
				$this->designs,
				$this->identity,
				$this->moderator,
				new LayoutSuggester( $this->http, $this->settings, $this->logger ),
				$this->fonts
			)
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

		/*
		 * WooCommerce integration is registered unconditionally rather than
		 * behind is_admin(): the cart runs on the frontend, the product screen
		 * in the admin, and add-to-cart validation has to be present for both
		 * an AJAX add and a full page post.
		 */
		if ( class_exists( 'WooCommerce' ) ) {
			( new ProductFields() )->register();
			( new CartIntegration( $this->designs, $this->identity, new FieldsFactory() ) )->register();
			( new Generator( $this->settings ) )->register();
			( new Wizard( $this->settings, new FieldsFactory(), $this->logger ) )->register();

			/*
			 * Statuses and fulfilment are registered on the frontend too. The
			 * status transition that starts a render is fired by the payment
			 * gateway's callback, which is not an admin request, and a status
			 * registered only in wp-admin renders as a blank label everywhere
			 * else — including in the customer's own order emails.
			 */
			( new OrderStatuses() )->register();
			$this->fulfilment->register();
		}

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

			( new BlocklistPage( $this->moderator ) )->register();
			( new FormatsPage( $this->images, $this->fonts ) )->register();

			if ( class_exists( 'WooCommerce' ) ) {
				( new OrderScreen( $this->designs, $this->fulfilment ) )->register();

				// §10 layer 3. The only moderation layer that sees the image,
				// and the screen the shop actually works from every day.
				( new ReviewQueue( $this->designs, $this->logger ) )->register();
			}
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

	/**
	 * Pixel manipulation.
	 */
	public function images(): GdEngine {
		return $this->images;
	}

	/**
	 * Bundled fonts and their coverage.
	 */
	public function fonts(): FontCatalogue {
		return $this->fonts;
	}

	/**
	 * Preview watermarking.
	 */
	public function watermarker(): Watermarker {
		return $this->watermarker;
	}

	/**
	 * The moderation layers.
	 */
	public function moderator(): Moderator {
		return $this->moderator;
	}

	/**
	 * Master to customer-facing preview.
	 */
	public function previews(): PreviewPipeline {
		return $this->previews;
	}

	/**
	 * Master to print file.
	 */
	public function prints(): FulfilPipeline {
		return $this->prints;
	}

	/**
	 * The permanent order zone.
	 */
	public function archive(): OrderArchive {
		return $this->archive;
	}

	/**
	 * Post-payment rendering.
	 */
	public function fulfilment(): Fulfilment {
		return $this->fulfilment;
	}
}
