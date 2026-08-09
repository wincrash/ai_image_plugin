<?php
/**
 * Configuration resolution.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin configuration.
 *
 * Ordinary settings live in one autoloaded option. Secrets do not live in the
 * database at all — see secret().
 */
class Settings {

	public const OPTION = 'aicake_settings';

	/**
	 * Logical secret name => the constant that must define it.
	 *
	 * @var array<string, string>
	 */
	private const SECRETS = array(
		'fal'       => 'AICAKE_FAL_KEY',
		'gemini'    => 'AICAKE_GEMINI_KEY',
		'openai'    => 'AICAKE_OPENAI_KEY',
		'replicate' => 'AICAKE_REPLICATE_KEY',
		'llm'       => 'AICAKE_LLM_KEY',
		'ip_salt'   => 'AICAKE_IP_SALT',
	);

	/**
	 * Cached option array, so a request that checks limits repeatedly hits the
	 * database once.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Encrypted storage for keys entered in the admin screen (D-050).
	 *
	 * Constructed lazily rather than injected: Settings is a configuration
	 * primitive that half the object graph depends on, and the store is its own
	 * storage detail rather than a collaborator anyone else should be handed.
	 */
	private ?SecretStore $store = null;

	/**
	 * The encrypted secret store.
	 */
	public function secrets(): SecretStore {
		if ( null === $this->store ) {
			$this->store = new SecretStore();
		}

		return $this->store;
	}

	/**
	 * Defaults for everything that is not a secret.
	 *
	 * Money is in USD throughout: providers bill in USD and the designs table
	 * stores cost_usd. PLAN.md §11.4 quotes euros — converting for display is a
	 * later concern, and mixing currencies inside the guard would be worse.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			// Throttling — PLAN.md §11.3.
			'free_per_session'     => 5,
			'free_per_user'        => 20,
			'ip_daily_ceiling'     => 30,
			'min_interval_seconds' => 3,

			/*
			 * When the shop last reset everybody's counters. Generations before
			 * this moment do not count against anyone's allowance. Empty means
			 * never — see RateLimiter::since() for why a reset is a timestamp
			 * and not a number.
			 */
			'throttle_epoch'       => '',

			// Budget guard — PLAN.md §11.4.
			'budget_daily_usd'     => 5.0,
			'budget_monthly_usd'   => 50.0,
			'budget_notify_email'  => '',

			/*
			 * Which header carries the real client IP. Trusting a forwarded
			 * header that the host does not actually set lets anyone spoof an
			 * unlimited number of identities, so the default trusts nothing.
			 */
			'trusted_ip_header'    => 'none', // none | cloudflare | x-forwarded-for.

			/*
			 * Moderation layers — PLAN.md §10. On by default, and each one is
			 * switchable on its own because they cost different things: layers
			 * 0 and 1 are free, layer 2 is an API call. A shop that wants to
			 * stop paying for the classifier should not have to give up the
			 * free word list to do it.
			 */
			'moderation_sanity'    => true,
			'moderation_blocklist' => true,
			'moderation_ai'        => true,

			/*
			 * Where a picture may come from — D-054, D-059.
			 *
			 * Each way in is switchable on its own, and a switched-off source
			 * **does not appear in the wizard at all**. Ruslan asked for that
			 * twice, and for two reasons: insurance if a provider misbehaves,
			 * and a rollout lever — ship the editor first, turn AI on later as
			 * its own moment.
			 *
			 * Search is off by default. It is the only one that reaches out to
			 * the open internet for a picture nobody has licensed (D-060), so
			 * the shop turns it on deliberately or not at all.
			 */
			'source_none'          => true,
			'source_upload'        => true,
			'source_ai'            => true,
			'source_search'        => false,

			/*
			 * What each source is **called** in the Fields Factory field that
			 * prices it (D-071).
			 *
			 * These are not decoration. WCFF stores a radio's posted value
			 * verbatim as `user_val`, and that is the string it matches its
			 * price rules against, shows in the cart, writes on the order and
			 * puts in the customer's e-mail. So each of these has to be exactly
			 * one of the choices typed into „Paveikslėlio tipas" — a phrase
			 * that is one space or one letter different prices at base and says
			 * nothing on the order, silently.
			 *
			 * Settings rather than constants for exactly that reason: the shop
			 * owns the wording *and* the money (D-058), and when the two ends
			 * disagree it has to be fixable in wp-admin rather than in a deploy.
			 * The settings screen resolves each one against the field's real
			 * choices and says which ones matched.
			 */
			'source_field_label'   => 'Paveikslėlio tipas',
			'source_value_none'    => 'Tik užrašas',
			'source_value_upload'  => 'Mano nuotrauka',
			'source_value_ai'      => 'Sukurta su AI',
			'source_value_search'  => 'Rasta internete',

			/*
			 * Retention — D-061. Storage grows with every generation, bought or
			 * not, and production is a managed host with no shell.
			 *
			 * Days are counted from the design's last touch, not its creation,
			 * so a customer who comes back to an old design keeps it. Zero
			 * switches collection off entirely, which is the setting to reach
			 * for while diagnosing something rather than editing code.
			 *
			 * The batch is small on purpose: the sweep runs inside a request
			 * that is already doing something else, and a big batch would turn
			 * one unlucky customer's generation into a filesystem job.
			 */
			'retention_days'       => 14,
			'retention_batch'      => 20,

			// Operational.
			'log_level'            => 'info', // debug | info | warning | error | off.
			'generation_enabled'   => true,
		);
	}

	/**
	 * Read a non-secret setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Returned when the key is unknown.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}

		return $this->cache[ $key ] ?? $default;
	}

	/**
	 * Persist a set of non-secret settings.
	 *
	 * @param array<string, mixed> $values Values to merge over what is stored.
	 */
	public function update( array $values ): void {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		// Never let a secret be written to the option by accident.
		foreach ( array_keys( self::SECRETS ) as $secret ) {
			unset( $values[ $secret ] );
		}

		update_option( self::OPTION, array_merge( $stored, $values ) );
		$this->cache = null;
	}

	/**
	 * Read a secret.
	 *
	 * Resolution order, and the order is the whole design (D-050):
	 *
	 * 1. **A constant**, if wp-config.php defines one. Still the strongest
	 *    answer — nothing in the database at all — and it is why the testbed
	 *    keeps running off .env unchanged.
	 * 2. **The encrypted store**, written by the settings screen. Production is
	 *    reachable only by FTP, and editing wp-config.php on a live shop to
	 *    rotate a key is the riskier of the two operations.
	 * 3. **Empty**, which every provider reports as "not configured" rather
	 *    than attempting an unauthenticated call.
	 *
	 * A stored value that a constant silently overrode would be the worst of
	 * both, so the screen refuses to edit a secret a constant already provides.
	 *
	 * @param string $name One of the SECRETS keys.
	 */
	public function secret( string $name ): string {
		return $this->resolve( $name )[1];
	}

	/**
	 * Where a secret's value comes from, for the settings screen.
	 *
	 * @param string $name One of the SECRETS keys.
	 * @return string constant | stored | derived | unset
	 */
	public function secret_source( string $name ): string {
		return $this->resolve( $name )[0];
	}

	/**
	 * The one place the resolution order lives.
	 *
	 * Both public methods delegate here rather than each walking the order
	 * themselves. They did, briefly, and falsifying the constant-first branch
	 * turned exactly one assertion red instead of two — which is the same bug
	 * in miniature as the thing it would cause: a screen reporting "set in
	 * wp-config.php" while the code quietly used the stored value. Two copies
	 * of a precedence rule are two chances to disagree about it.
	 *
	 * @param string $name One of the SECRETS keys.
	 * @return array{0: string, 1: string} Source and value.
	 */
	private function resolve( string $name ): array {
		$constant = self::SECRETS[ $name ] ?? null;

		if ( null === $constant ) {
			return array( 'unset', '' );
		}

		if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return array( 'constant', (string) constant( $constant ) );
		}

		$stored = $this->secrets()->get( $name );

		if ( '' !== $stored ) {
			return array( 'stored', $stored );
		}

		/*
		 * The IP salt is not a secret with any value outside this site: it
		 * exists so stored IP hashes cannot be reversed with a rainbow table.
		 * Unconfigured it used to hash with an empty string — the weakest
		 * possible answer, reached by doing nothing. Deriving it from the
		 * site's own salts is strictly better than storing one, because those
		 * live in wp-config.php and so are not in the database dump at all.
		 */
		if ( 'ip_salt' === $name ) {
			return array( 'derived', hash( 'sha256', 'aicake-ip-salt-v1|' . wp_salt( 'nonce' ) ) );
		}

		return array( 'unset', '' );
	}

	/**
	 * Whether a secret is present and non-empty.
	 *
	 * @param string $name One of the SECRETS keys.
	 */
	public function has_secret( string $name ): bool {
		return '' !== $this->secret( $name );
	}

	/**
	 * Every secret that is actually configured, for redaction and diagnostics.
	 *
	 * @return array<string, string> Logical name => value.
	 */
	public function configured_secrets(): array {
		$out = array();

		foreach ( array_keys( self::SECRETS ) as $name ) {
			$value = $this->secret( $name );

			if ( '' !== $value ) {
				$out[ $name ] = $value;
			}
		}

		return $out;
	}

	/**
	 * The constant name behind a secret, for "define this in wp-config.php".
	 *
	 * @param string $name One of the SECRETS keys.
	 */
	public static function secret_constant( string $name ): string {
		return self::SECRETS[ $name ] ?? '';
	}

	/**
	 * All known secret names.
	 *
	 * @return string[]
	 */
	public static function secret_names(): array {
		return array_keys( self::SECRETS );
	}

	/**
	 * Storage root, without a trailing slash.
	 *
	 * Production should define AICAKE_STORAGE_DIR above the webroot
	 * (PLAN.md §12.1). The uploads fallback works, it just leans harder on
	 * unguessable names.
	 */
	public function storage_dir(): string {
		if ( defined( 'AICAKE_STORAGE_DIR' ) && '' !== (string) constant( 'AICAKE_STORAGE_DIR' ) ) {
			return untrailingslashit( (string) constant( 'AICAKE_STORAGE_DIR' ) );
		}

		$uploads = wp_get_upload_dir();

		return untrailingslashit( $uploads['basedir'] ) . '/aicake';
	}

	/**
	 * Whether the storage root sits inside the webroot, where HTTP might reach it.
	 */
	public function storage_is_inside_webroot(): bool {
		$root = realpath( ABSPATH );
		$dir  = realpath( $this->storage_dir() );

		if ( false === $root || false === $dir ) {
			return false;
		}

		return str_starts_with( $dir, $root );
	}
}
