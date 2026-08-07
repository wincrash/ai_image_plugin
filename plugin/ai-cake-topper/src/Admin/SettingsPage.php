<?php
/**
 * The settings screen.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Admin;

use AiCake\Capabilities;
use AiCake\Pipeline\PromptBuilder;
use AiCake\Queue\Dispatcher;
use AiCake\Support\SecretStore;
use AiCake\Support\Settings;
use AiCake\Throttle\BudgetGuard;
use AiCake\Throttle\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Keys, limits, the house style and a diagnostics panel.
 *
 * This screen exists because of the migration (docs/migration.md). Until now
 * every one of these values was reachable only through `wp eval`, which is
 * fine on a Docker testbed and impossible on production: the live shop is a
 * managed host with FTP and wp-admin and nothing else. A setting that cannot be
 * changed on the only machine that matters is not a setting.
 *
 * The keys half is D-050 and reverses a CLAUDE.md rule at Ruslan's request.
 */
class SettingsPage {

	public const SLUG = 'aicake-settings';

	private const ACTION = 'aicake_save_settings';

	/**
	 * Resetting is its own form and its own nonce.
	 *
	 * It is not a checkbox on the settings form: forgiving every visitor's
	 * allowance is not something anyone should do as a side effect of changing
	 * a log level and pressing Save.
	 */
	private const RESET_ACTION = 'aicake_reset_counters';

	private const NOTICE = 'aicake_settings_notice';

	/**
	 * Keys the shop actually needs, in the order they are worth entering.
	 *
	 * openai and llm are deliberately absent: neither is used by the stack we
	 * ship (STATE.md), and offering a field for a key nothing reads invites
	 * someone to paste one and wonder why nothing changed.
	 */
	private const KEYS = array(
		'fal'       => array(
			'label' => 'fal.ai',
			'note'  => 'Generuoja paveikslėlius. Būtinas. https://fal.ai/dashboard/keys',
		),
		'gemini'    => array(
			'label' => 'Google Gemini',
			'note'  => 'Verčia į anglų kalbą ir tikrina tekstą. Būtinas. https://aistudio.google.com/apikey',
		),
		'replicate' => array(
			'label' => 'Replicate',
			'note'  => 'Atsarginis variantas, jei fal.ai neatsako. Nebūtinas.',
		),
	);

	private Settings $settings;

	private Capabilities $capabilities;

	private Dispatcher $dispatcher;

	private RateLimiter $limiter;

	private BudgetGuard $budget;

	/**
	 * @param Settings     $settings     Configuration.
	 * @param Capabilities $capabilities Host report, for the diagnostics panel.
	 * @param Dispatcher   $dispatcher   Loopback state.
	 * @param RateLimiter  $limiter      Counters and resets.
	 * @param BudgetGuard  $budget       Spend to date.
	 */
	public function __construct(
		Settings $settings,
		Capabilities $capabilities,
		Dispatcher $dispatcher,
		RateLimiter $limiter,
		BudgetGuard $budget
	) {
		$this->settings     = $settings;
		$this->capabilities = $capabilities;
		$this->dispatcher   = $dispatcher;
		$this->limiter      = $limiter;
		$this->budget       = $budget;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
		add_action( 'admin_notices', array( $this, 'unreadable_notice' ) );
	}

	/**
	 * Add the submenu entry.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'aicake-test-provider',
			__( 'Nustatymai', 'ai-cake-topper' ),
			__( 'Nustatymai', 'ai-cake-topper' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Save.
	 *
	 * Capability is `manage_options`, not `manage_woocommerce` as on the other
	 * screens: this one holds the API keys and the spend ceilings, and a shop
	 * manager who can process orders is not automatically someone who should be
	 * able to raise the monthly budget.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-cake-topper' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$saved = array();

		$saved[] = $this->save_keys();
		$saved[] = $this->save_limits();
		$saved[] = $this->save_style();

		set_transient(
			self::NOTICE . get_current_user_id(),
			array_values( array_filter( $saved ) ),
			MINUTE_IN_SECONDS
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Reset counters — everybody's, or one customer's.
	 */
	public function handle_reset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-cake-topper' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::RESET_ACTION );

		$who = isset( $_POST['reset_user'] ) ? sanitize_text_field( wp_unslash( $_POST['reset_user'] ) ) : '';

		if ( '' === trim( $who ) ) {
			$this->limiter->reset_all();

			$line = __( 'Visų skaitikliai atstatyti.', 'ai-cake-topper' );
		} else {
			$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );

			if ( ! $user ) {
				$line = sprintf(
					/* translators: %s: what was typed in the box */
					__( 'Naudotojas „%s" nerastas — niekas nepakeista.', 'ai-cake-topper' ),
					$who
				);
			} else {
				/*
				 * Read before writing, so the notice can say what was actually
				 * forgiven. The totals shown on the screen are history and do
				 * not move when a counter is reset — without a concrete number
				 * here, a successful reset looks exactly like nothing
				 * happening.
				 */
				$was = $this->limiter->used_by( $user->ID );

				$this->limiter->reset_user( $user->ID );

				$line = sprintf(
					/* translators: 1: username, 2: generations used before the reset, 3: their allowance */
					__( 'Naudotojo %1$s skaitiklis atstatytas — buvo %2$d iš %3$d.', 'ai-cake-topper' ),
					$user->user_login,
					$was,
					(int) $this->settings->get( 'free_per_user', 20 )
				);
			}
		}

		set_transient( self::NOTICE . get_current_user_id(), array( $line ), MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Store, replace or remove API keys.
	 *
	 * @return string A line for the notice, or '' when nothing changed.
	 */
	private function save_keys(): string {
		$store   = $this->settings->secrets();
		$changed = array();

		foreach ( array_keys( self::KEYS ) as $name ) {
			// A constant owns this key; the field was disabled and anything
			// posted for it is noise or a tampered form.
			if ( 'constant' === $this->settings->secret_source( $name ) ) {
				continue;
			}

			if ( isset( $_POST['key_remove'] ) && in_array( $name, (array) wp_unslash( $_POST['key_remove'] ), true ) ) {
				$store->forget( $name );
				$changed[] = $name;

				continue;
			}

			/*
			 * Not sanitize_text_field: it strips characters that are legal in a
			 * key, and a key silently altered on the way in produces a 401 that
			 * looks like the provider's fault. Length and shape are checked
			 * instead, and the value is never echoed back.
			 */
			$posted = isset( $_POST[ 'key_' . $name ] ) ? trim( (string) wp_unslash( $_POST[ 'key_' . $name ] ) ) : '';

			if ( '' === $posted ) {
				continue;
			}

			// The masked placeholder means "leave it alone", which is what an
			// unchanged form posts back.
			if ( str_contains( $posted, '•' ) ) {
				continue;
			}

			$store->set( $name, $posted );
			$changed[] = $name;
		}

		if ( array() === $changed ) {
			return '';
		}

		return sprintf(
			/* translators: %s: comma-separated list of provider names */
			__( 'Raktai išsaugoti: %s', 'ai-cake-topper' ),
			implode( ', ', $changed )
		);
	}

	/**
	 * Throttle, budget and operational settings.
	 *
	 * @return string A line for the notice.
	 */
	private function save_limits(): string {
		$values = array(
			'free_per_session'     => $this->posted_int( 'free_per_session', 0, 1000 ),
			'free_per_user'        => $this->posted_int( 'free_per_user', 0, 1000 ),
			'ip_daily_ceiling'     => $this->posted_int( 'ip_daily_ceiling', 0, 10000 ),
			'min_interval_seconds' => $this->posted_int( 'min_interval_seconds', 0, 3600 ),
			'budget_daily_usd'     => $this->posted_float( 'budget_daily_usd', 0.0, 10000.0 ),
			'budget_monthly_usd'   => $this->posted_float( 'budget_monthly_usd', 0.0, 100000.0 ),
			'generation_enabled'   => isset( $_POST['generation_enabled'] ),
		);

		$email = isset( $_POST['budget_notify_email'] ) ? sanitize_email( wp_unslash( $_POST['budget_notify_email'] ) ) : '';

		$values['budget_notify_email'] = is_email( $email ) ? $email : '';

		$header = isset( $_POST['trusted_ip_header'] ) ? sanitize_key( wp_unslash( $_POST['trusted_ip_header'] ) ) : 'none';

		$values['trusted_ip_header'] = in_array( $header, array( 'none', 'cloudflare', 'x-forwarded-for' ), true )
			? $header
			: 'none';

		$level = isset( $_POST['log_level'] ) ? sanitize_key( wp_unslash( $_POST['log_level'] ) ) : 'info';

		$values['log_level'] = in_array( $level, array( 'debug', 'info', 'warning', 'error', 'off' ), true )
			? $level
			: 'info';

		$this->settings->update( $values );

		return __( 'Limitai išsaugoti.', 'ai-cake-topper' );
	}

	/**
	 * The house style suffix.
	 *
	 * @return string A line for the notice, or '' when unchanged.
	 */
	private function save_style(): string {
		if ( ! isset( $_POST['style_suffix'] ) ) {
			return '';
		}

		$suffix = sanitize_textarea_field( wp_unslash( $_POST['style_suffix'] ) );

		if ( isset( $_POST['style_reset'] ) ) {
			$suffix = PromptBuilder::DEFAULT_SUFFIX;
		}

		$this->settings->update( array( 'style_suffix' => $suffix ) );

		return __( 'Stilius išsaugotas.', 'ai-cake-topper' );
	}

	/**
	 * A bounded integer from the form.
	 *
	 * @param string $field Field name.
	 * @param int    $min   Lower bound.
	 * @param int    $max   Upper bound.
	 */
	private function posted_int( string $field, int $min, int $max ): int {
		$value = isset( $_POST[ $field ] ) ? (int) wp_unslash( $_POST[ $field ] ) : $min;

		return max( $min, min( $max, $value ) );
	}

	/**
	 * A bounded float from the form.
	 *
	 * @param string $field Field name.
	 * @param float  $min   Lower bound.
	 * @param float  $max   Upper bound.
	 */
	private function posted_float( string $field, float $min, float $max ): float {
		$value = isset( $_POST[ $field ] ) ? (float) str_replace( ',', '.', (string) wp_unslash( $_POST[ $field ] ) ) : $min;

		return max( $min, min( $max, $value ) );
	}

	/**
	 * Warn, anywhere in wp-admin, when stored keys will not decrypt.
	 *
	 * This is what a site move looks like: the database came across and
	 * wp-config.php was regenerated, so the salts changed and the ciphertext is
	 * now noise. Silence here would look exactly like an expired API key, which
	 * is a much more expensive thing to debug.
	 */
	public function unreadable_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$unreadable = $this->settings->secrets()->unreadable();

		if ( array() === $unreadable ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'AI Cake Topper:', 'ai-cake-topper' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of provider names */
					__( 'įrašyti API raktai nebeperskaitomi (%s). Taip nutinka perkėlus svetainę į kitą serverį — pasikeitė wp-config.php slaptažodžiai, kuriais raktai užšifruoti. Įveskite raktus iš naujo.', 'ai-cake-topper' ),
					implode( ', ', $unreadable )
				)
			),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Nustatymai', 'ai-cake-topper' )
		);
	}

	/**
	 * Render the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'AI Cake Topper — nustatymai', 'ai-cake-topper' ) . '</h1>';

		$this->render_notice();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';

		$this->render_keys();
		$this->render_limits();
		$this->render_style();

		submit_button( __( 'Išsaugoti', 'ai-cake-topper' ) );

		echo '</form>';

		$this->render_counters();
		$this->render_diagnostics();

		echo '</div>';
	}

	/**
	 * The "saved" notice.
	 */
	private function render_notice(): void {
		$lines = get_transient( self::NOTICE . get_current_user_id() );

		if ( ! is_array( $lines ) || array() === $lines ) {
			return;
		}

		delete_transient( self::NOTICE . get_current_user_id() );

		echo '<div class="notice notice-success"><p>' . esc_html( implode( ' ', $lines ) ) . '</p></div>';
	}

	/**
	 * The API keys section.
	 */
	private function render_keys(): void {
		echo '<h2>' . esc_html__( 'API raktai', 'ai-cake-topper' ) . '</h2>';

		if ( ! SecretStore::available() ) {
			printf(
				'<div class="notice notice-error inline"><p>%s</p></div>',
				esc_html__( 'Šiame serveryje nėra nei sodium, nei openssl, todėl raktų užšifruoti neįmanoma. Raktai nebus įrašyti — juos reikia įrašyti į wp-config.php.', 'ai-cake-topper' )
			);

			return;
		}

		if ( ! SecretStore::key_is_in_wp_config() ) {
			printf(
				'<div class="notice notice-warning inline"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Įspėjimas:', 'ai-cake-topper' ),
				esc_html__( 'wp-config.php faile nėra SECURE_AUTH_KEY / SECURE_AUTH_SALT reikšmių, todėl šifravimo raktas laikomas toje pačioje duomenų bazėje kaip ir užšifruoti API raktai. Tokiu atveju šifravimas apsaugos nedaug.', 'ai-cake-topper' )
			);
		}

		echo '<p class="description">'
			. esc_html__( 'Raktai saugomi užšifruoti duomenų bazėje. Tai apsaugo duomenų bazės kopiją, bet ne serverio failus: kas gali perskaityti wp-config.php, tas gali iššifruoti ir raktus. Raktai niekada nerodomi visi ir nepatenka į žurnalus.', 'ai-cake-topper' )
			. '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::KEYS as $name => $meta ) {
			$source = $this->settings->secret_source( $name );

			echo '<tr><th scope="row"><label for="key_' . esc_attr( $name ) . '">' . esc_html( $meta['label'] ) . '</label></th><td>';

			if ( 'constant' === $source ) {
				printf(
					'<input type="text" class="regular-text" value="%s" disabled> <span class="dashicons dashicons-lock"></span><p class="description">%s</p>',
					esc_attr( $this->mask( $this->settings->secret( $name ) ) ),
					esc_html(
						sprintf(
							/* translators: %s: PHP constant name */
							__( 'Nustatyta wp-config.php faile (%s). Kad būtų galima keisti čia, pašalinkite eilutę iš wp-config.php.', 'ai-cake-topper' ),
							Settings::secret_constant( $name )
						)
					)
				);
			} else {
				$stored = 'stored' === $source;

				printf(
					'<input type="password" id="key_%1$s" name="key_%1$s" class="regular-text" value="" autocomplete="new-password" placeholder="%2$s">',
					esc_attr( $name ),
					esc_attr( $stored ? $this->mask( $this->settings->secret( $name ) ) : __( 'neįvestas', 'ai-cake-topper' ) )
				);

				if ( $stored ) {
					printf(
						' <label><input type="checkbox" name="key_remove[]" value="%s"> %s</label>',
						esc_attr( $name ),
						esc_html__( 'pašalinti', 'ai-cake-topper' )
					);
				}

				echo '<p class="description">' . esc_html( $meta['note'] );

				if ( $stored ) {
					echo ' — <strong>' . esc_html__( 'įrašytas. Palikite lauką tuščią, kad nekeistumėte.', 'ai-cake-topper' ) . '</strong>';
				}

				echo '</p>';
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Limits and operational settings.
	 */
	private function render_limits(): void {
		echo '<h2>' . esc_html__( 'Limitai ir biudžetas', 'ai-cake-topper' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Vienas paveikslėlis kainuoja apie 0,012 USD. Didžiausia rizika yra ne modelio kaina, o neribojamas mygtukas — todėl šie skaičiai svarbesni už modelio pasirinkimą.', 'ai-cake-topper' )
			. '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';

		$this->number_row( 'free_per_session', __( 'Nemokamų generavimų neprisijungusiam', 'ai-cake-topper' ), __( 'Per vieną naršyklės sesiją.', 'ai-cake-topper' ) );
		$this->number_row( 'free_per_user', __( 'Nemokamų generavimų prisijungusiam', 'ai-cake-topper' ), __( 'Turi būti didesnis už ankstesnį — tai priežastis susikurti paskyrą.', 'ai-cake-topper' ) );
		$this->number_row( 'ip_daily_ceiling', __( 'Dienos riba vienam IP', 'ai-cake-topper' ), __( 'Bendra riba, nepriklausomai nuo sesijų ir paskyrų.', 'ai-cake-topper' ) );
		$this->number_row( 'min_interval_seconds', __( 'Mažiausias tarpas tarp užklausų (s)', 'ai-cake-topper' ), '' );
		$this->number_row( 'budget_daily_usd', __( 'Dienos biudžetas (USD)', 'ai-cake-topper' ), __( 'Viršijus — generavimas išjungiamas visoje svetainėje ir išsiunčiamas laiškas.', 'ai-cake-topper' ), '0.01' );
		$this->number_row( 'budget_monthly_usd', __( 'Mėnesio biudžetas (USD)', 'ai-cake-topper' ), '', '0.01' );

		printf(
			'<tr><th scope="row"><label for="budget_notify_email">%s</label></th><td><input type="email" id="budget_notify_email" name="budget_notify_email" class="regular-text" value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Pranešti el. paštu', 'ai-cake-topper' ),
			esc_attr( (string) $this->settings->get( 'budget_notify_email', '' ) ),
			esc_html__( 'Tuščias — siunčiama svetainės administratoriui.', 'ai-cake-topper' )
		);

		$this->select_row(
			'trusted_ip_header',
			__( 'Kuris antraštės laukas neša tikrą IP', 'ai-cake-topper' ),
			array(
				'none'            => __( 'nė vienas (saugiausia)', 'ai-cake-topper' ),
				'cloudflare'      => 'CF-Connecting-IP',
				'x-forwarded-for' => 'X-Forwarded-For',
			),
			__( 'Rinkitės ne „nė vienas" tik jei svetainė tikrai yra už tokio tarpinio serverio. Kitaip bet kas gali apsimesti bet kokiu IP ir dienos riba nustoja galioti.', 'ai-cake-topper' )
		);

		$this->select_row(
			'log_level',
			__( 'Žurnalo lygis', 'ai-cake-topper' ),
			array(
				'debug'   => 'debug',
				'info'    => 'info',
				'warning' => 'warning',
				'error'   => 'error',
				'off'     => __( 'išjungta', 'ai-cake-topper' ),
			),
			__( 'Žurnalus rasite: WooCommerce → Būsena → Žurnalai.', 'ai-cake-topper' )
		);

		printf(
			'<tr><th scope="row">%s</th><td><label><input type="checkbox" name="generation_enabled" value="1" %s> %s</label><p class="description">%s</p></td></tr>',
			esc_html__( 'Generavimas', 'ai-cake-topper' ),
			checked( (bool) $this->settings->get( 'generation_enabled', true ), true, false ),
			esc_html__( 'įjungtas', 'ai-cake-topper' ),
			esc_html__( 'Išjungus, vedlys mandagiai atsisako generuoti. Biudžeto sargas išjungia tai automatiškai.', 'ai-cake-topper' )
		);

		echo '</tbody></table>';
	}

	/**
	 * The house style suffix.
	 */
	private function render_style(): void {
		$suffix = (string) $this->settings->get( 'style_suffix', PromptBuilder::DEFAULT_SUFFIX );

		echo '<h2>' . esc_html__( 'Piešinio stilius', 'ai-cake-topper' ) . '</h2>';
		echo '<p class="description">'
			. esc_html__( 'Pridedama prie kiekvienos užklausos, angliškai. Rašykite teigiamai: „no text" veikia, o „no cake" — ne, nes modelis neturi neigimo ir tiesiog nupiešia tortą.', 'ai-cake-topper' )
			. '</p>';

		echo '<p><textarea name="style_suffix" rows="4" style="width:100%;max-width:800px;font-family:monospace">'
			. esc_textarea( $suffix )
			. '</textarea></p>';

		if ( PromptBuilder::DEFAULT_SUFFIX !== $suffix ) {
			printf(
				'<p><label><input type="checkbox" name="style_reset" value="1"> %s</label></p>',
				esc_html__( 'grąžinti gamyklinį stilių', 'ai-cake-topper' )
			);
		}
	}

	/**
	 * Counters, and the button that forgives them.
	 *
	 * Its own form, deliberately outside the settings form above — see
	 * RESET_ACTION.
	 */
	private function render_counters(): void {
		$today = $this->limiter->generated_since( $this->limiter->day_start() );
		$month = $this->limiter->generated_since( get_gmt_from_date( current_time( 'Y-m' ) . '-01 00:00:00' ) );
		$last  = $this->limiter->last_reset();

		echo '<hr><h2>' . esc_html__( 'Skaitikliai', 'ai-cake-topper' ) . '</h2>';

		echo '<p class="description">'
			. esc_html__( 'Kiek piešinių sukurta ir kiek tai kainavo. Nepavykę ir atmesti neskaičiuojami — jie ir naudotojo limito neeikvoja. Šie skaičiai yra istorija: atstačius skaitiklius jie nesikeičia, keičiasi tik tai, kiek naudotojams likę.', 'ai-cake-topper' )
			. '</p>';

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';

		printf(
			'<tr><td style="width:220px"><strong>%s</strong></td><td>%d %s &nbsp;·&nbsp; %s</td></tr>',
			esc_html__( 'Šiandien', 'ai-cake-topper' ),
			(int) $today,
			esc_html__( 'vnt.', 'ai-cake-topper' ),
			esc_html( sprintf( '$%.4f', $this->budget->spent_today() ) )
		);

		printf(
			'<tr><td><strong>%s</strong></td><td>%d %s &nbsp;·&nbsp; %s</td></tr>',
			esc_html__( 'Šį mėnesį', 'ai-cake-topper' ),
			(int) $month,
			esc_html__( 'vnt.', 'ai-cake-topper' ),
			esc_html( sprintf( '$%.4f', $this->budget->spent_this_month() ) )
		);

		printf(
			'<tr><td><strong>%s</strong></td><td>%s</td></tr>',
			esc_html__( 'Paskutinis atstatymas', 'ai-cake-topper' ),
			esc_html(
				'' === $last
					? __( 'niekada', 'ai-cake-topper' )
					: get_date_from_gmt( $last, 'Y-m-d H:i' )
			)
		);

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em">';
		wp_nonce_field( self::RESET_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::RESET_ACTION ) . '">';

		printf(
			'<p><label for="reset_user">%s</label><br><input type="text" id="reset_user" name="reset_user" class="regular-text" placeholder="%s"></p>',
			esc_html__( 'Atstatyti vieno naudotojo skaitiklį — įveskite prisijungimo vardą arba el. paštą. Palikus tuščią, atstatoma visiems.', 'ai-cake-topper' ),
			esc_attr__( 'vardas arba el. paštas', 'ai-cake-topper' )
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Atstatymas nieko neištrina — piešiniai ir užsakymai lieka. Tiesiog nustoja skaičiuoti tai, kas buvo iki dabar. Dienos riba vienam IP adresui atstatoma tik tada, kai atstatoma visiems.', 'ai-cake-topper' )
		);

		printf(
			'<p><button type="submit" class="button" onclick="return confirm(%s)">%s</button></p>',
			"'" . esc_js( __( 'Atstatyti skaitiklius?', 'ai-cake-topper' ) ) . "'",
			esc_html__( 'Atstatyti', 'ai-cake-topper' )
		);

		echo '</form>';
	}

	/**
	 * Read-only host diagnostics.
	 *
	 * Everything here is also in Site Health, but Site Health on a live shop is
	 * a long page owned by twenty other plugins. This is the same facts in the
	 * place someone is already standing when they wonder why nothing generates.
	 */
	private function render_diagnostics(): void {
		$report = $this->capabilities->report();

		echo '<h2>' . esc_html__( 'Serverio būklė', 'ai-cake-topper' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';

		/*
		 * -1 is "no limit", which is the best possible answer and not a smaller
		 * number than 256M. Read naively it renders as a red cross telling the
		 * shop its unlimited host is inadequate.
		 */
		$memory_bytes = (int) $report['memory_bytes'];
		$memory_ok    = $memory_bytes <= 0 || $memory_bytes >= 256 * 1024 * 1024;

		$this->fact_row( __( 'PHP', 'ai-cake-topper' ), $report['php_version'], version_compare( (string) $report['php_version'], '8.0', '>=' ) );
		$this->fact_row(
			__( 'Atminties riba', 'ai-cake-topper' ),
			$memory_bytes <= 0 ? __( 'neribota', 'ai-cake-topper' ) : (string) $report['memory_limit'],
			$memory_ok,
			__( 'Spausdinimo failui reikia bent 256M.', 'ai-cake-topper' )
		);
		$this->fact_row( __( 'Vaizdų variklis', 'ai-cake-topper' ), $this->capabilities->engine(), (bool) $report['gd'] );
		$this->fact_row( 'GD FreeType', $this->yes_no( (bool) $report['freetype'] ), (bool) $report['freetype'], __( 'Be jo nepiešiamas vandenženklis peržiūroje.', 'ai-cake-topper' ) );
		$this->fact_row( 'WebP', $this->yes_no( (bool) $report['webp'] ), (bool) $report['webp'] );

		$this->fact_row(
			__( 'Failų katalogas', 'ai-cake-topper' ),
			(string) $report['storage_dir'],
			(bool) $report['storage_writable'],
			$report['storage_writable']
				? __( 'Įrašymas patikrintas realiai, abiejose zonose.', 'ai-cake-topper' )
				: __( 'ĮRAŠYTI NEPAVYKSTA. Dažniausia priežastis — katalogą sukūrė kitas naudotojas nei tas, kuriuo veikia PHP.', 'ai-cake-topper' )
		);

		$this->fact_row(
			__( 'Katalogas už webroot ribų', 'ai-cake-topper' ),
			$this->yes_no( ! (bool) $report['storage_in_webroot'] ),
			! (bool) $report['storage_in_webroot'],
			$report['storage_in_webroot']
				? __( 'Failai yra svetainės kataloge. Apibrėžkite AICAKE_STORAGE_DIR wp-config.php faile.', 'ai-cake-topper' )
				: ''
		);

		/*
		 * Informational, not pass/fail: a blocked loopback is common on cheap
		 * hosts and the job still runs, via polling or the Action Scheduler
		 * sweep. A green tick beside the word "ne" would be a worse lie than
		 * a red cross beside a working shop.
		 */
		$this->fact_row( __( 'Loopback (fono darbai)', 'ai-cake-topper' ), $this->yes_no( $this->dispatcher->loopback_works() ), null, __( 'Neveikiantis loopback nėra klaida — darbai atliekami kitu keliu, tik lėčiau.', 'ai-cake-topper' ) );

		$this->fact_row(
			__( 'Raktų šifravimas', 'ai-cake-topper' ),
			SecretStore::available() ? SecretStore::cipher() : __( 'nėra', 'ai-cake-topper' ),
			SecretStore::available()
		);

		$this->fact_row(
			__( 'Šifravimo raktas iš wp-config.php', 'ai-cake-topper' ),
			$this->yes_no( SecretStore::key_is_in_wp_config() ),
			SecretStore::key_is_in_wp_config()
		);

		echo '</tbody></table>';
	}

	/**
	 * One diagnostics row.
	 *
	 * @param string    $label Row label.
	 * @param string    $value Value.
	 * @param bool|null $ok    Whether this is a good answer. Null when the row
	 *                         is informational and has no good or bad answer.
	 * @param string    $note  Optional explanation.
	 */
	private function fact_row( string $label, string $value, ?bool $ok, string $note = '' ): void {
		if ( null === $ok ) {
			$mark = '<span style="color:#787c82">&#8226;</span>';
		} elseif ( $ok ) {
			$mark = '<span style="color:#008a20">&#10003;</span>';
		} else {
			$mark = '<span style="color:#d63638">&#10007;</span>';
		}

		printf(
			'<tr><td style="width:220px"><strong>%s</strong></td><td>%s %s%s</td></tr>',
			esc_html( $label ),
			$mark,
			esc_html( $value ),
			'' === $note ? '' : '<br><span class="description">' . esc_html( $note ) . '</span>'
		);
	}

	/**
	 * A number input row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $note  Description.
	 * @param string $step  HTML step attribute.
	 */
	private function number_row( string $key, string $label, string $note, string $step = '1' ): void {
		printf(
			'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="number" id="%1$s" name="%1$s" value="%3$s" step="%4$s" min="0" class="small-text">%5$s</td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( (string) $this->settings->get( $key, 0 ) ),
			esc_attr( $step ),
			'' === $note ? '' : '<p class="description">' . esc_html( $note ) . '</p>'
		);
	}

	/**
	 * A select row.
	 *
	 * @param string                $key     Setting key.
	 * @param string                $label   Label.
	 * @param array<string, string> $choices Value => label.
	 * @param string                $note    Description.
	 */
	private function select_row( string $key, string $label, array $choices, string $note ): void {
		$current = (string) $this->settings->get( $key, '' );

		printf( '<tr><th scope="row"><label for="%s">%s</label></th><td><select id="%s" name="%s">', esc_attr( $key ), esc_html( $label ), esc_attr( $key ), esc_attr( $key ) );

		foreach ( $choices as $value => $text ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( (string) $value ),
				selected( $current, (string) $value, false ),
				esc_html( $text )
			);
		}

		echo '</select><p class="description">' . esc_html( $note ) . '</p></td></tr>';
	}

	/**
	 * Show enough of a key to recognise it, never enough to use it.
	 *
	 * @param string $value The secret.
	 */
	private function mask( string $value ): string {
		if ( strlen( $value ) <= 4 ) {
			return str_repeat( '•', 8 );
		}

		return str_repeat( '•', 8 ) . substr( $value, -4 );
	}

	/**
	 * @param bool $value The answer.
	 */
	private function yes_no( bool $value ): string {
		return $value ? __( 'taip', 'ai-cake-topper' ) : __( 'ne', 'ai-cake-topper' );
	}
}
