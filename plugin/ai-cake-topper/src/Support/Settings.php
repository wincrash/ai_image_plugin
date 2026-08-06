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
	 * Constants only. CLAUDE.md is stricter than PLAN.md §16 here: there is no
	 * option fallback, because anything in wp_options is in every database
	 * backup the client ever downloads. A missing key is a configuration error
	 * the settings screen reports, not something to paper over.
	 *
	 * @param string $name One of the SECRETS keys.
	 */
	public function secret( string $name ): string {
		$constant = self::SECRETS[ $name ] ?? null;

		if ( null === $constant || ! defined( $constant ) ) {
			return '';
		}

		return (string) constant( $constant );
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
