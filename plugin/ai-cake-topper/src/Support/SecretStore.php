<?php
/**
 * Encrypted storage for API keys.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Support;

defined( 'ABSPATH' ) || exit;

/**
 * API keys, encrypted at rest, in an option of their own.
 *
 * D-050. Until 2026-08-07 keys could only come from constants in wp-config.php.
 * Ruslan asked for them to be enterable in the admin screen, and on a shop
 * reachable only by FTP that is not just more convenient — rotating a leaked
 * key by editing wp-config.php over FTP, on a live store, with no staging copy,
 * is the riskier of the two.
 *
 * What this protects against and what it does not:
 *
 * - **Protects a database dump.** The backup a shop owner downloads to their
 *   laptop, the copy a developer restores locally, an SQL injection that can
 *   read wp_options. That is the exposure the constants-only rule was actually
 *   about, and it is a real one.
 * - **Does not protect a compromised filesystem.** The key is derived from the
 *   salts in wp-config.php, so anyone who can read that file can derive it.
 *   Encryption that is described as more than it is, is worse than none —
 *   the settings screen says this in as many words.
 *
 * Values are encrypted individually rather than as one blob, so a single
 * unreadable entry costs one key rather than all of them.
 */
class SecretStore {

	/**
	 * Not autoloaded: this is read on the handful of requests that call a
	 * provider, not on every page of the shop.
	 */
	public const OPTION = 'aicake_secrets';

	/**
	 * Marks which cipher wrote a value, so the reader never has to guess and a
	 * later scheme can be added without a migration.
	 */
	private const SCHEME_SODIUM  = 's1:';
	private const SCHEME_OPENSSL = 'o1:';

	/**
	 * Decrypted values, per request.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $cache = null;

	/**
	 * Stored names whose ciphertext would not decrypt.
	 *
	 * Almost always means the site moved and the salts came with a new
	 * wp-config.php. Reported, never guessed at.
	 *
	 * @var string[]
	 */
	private array $unreadable = array();

	/**
	 * Read a stored secret. Empty string when absent or unreadable.
	 *
	 * @param string $name Logical secret name.
	 */
	public function get( string $name ): string {
		$this->load();

		return $this->cache[ $name ] ?? '';
	}

	/**
	 * Store a secret, or remove it when given an empty value.
	 *
	 * @param string $name  Logical secret name.
	 * @param string $value The key. Whitespace is trimmed — a trailing newline
	 *                      pasted from a provider dashboard is the single most
	 *                      common cause of a 401 that looks like a wrong key.
	 */
	public function set( string $name, string $value ): void {
		$value = trim( $value );

		if ( '' === $value ) {
			$this->forget( $name );

			return;
		}

		$stored          = $this->raw();
		$stored[ $name ] = $this->encrypt( $value );

		$this->save( $stored );
	}

	/**
	 * Remove a stored secret.
	 *
	 * @param string $name Logical secret name.
	 */
	public function forget( string $name ): void {
		$stored = $this->raw();

		if ( ! isset( $stored[ $name ] ) ) {
			return;
		}

		unset( $stored[ $name ] );

		$this->save( $stored );
	}

	/**
	 * Whether a readable value is stored for this name.
	 *
	 * @param string $name Logical secret name.
	 */
	public function has( string $name ): bool {
		return '' !== $this->get( $name );
	}

	/**
	 * Names that are stored but will not decrypt.
	 *
	 * @return string[]
	 */
	public function unreadable(): array {
		$this->load();

		return $this->unreadable;
	}

	/**
	 * Whether the encryption key comes from wp-config.php rather than the
	 * database.
	 *
	 * wp_salt() falls back to options in wp_options when the salt constants are
	 * absent. If it does, the key and the ciphertext live in the same table and
	 * this whole class is decoration. That is worth saying out loud on the
	 * screen rather than in a comment nobody reads.
	 */
	public static function key_is_in_wp_config(): bool {
		foreach ( array( 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT' ) as $constant ) {
			if ( ! defined( $constant ) ) {
				return false;
			}

			$value = (string) constant( $constant );

			// What a freshly downloaded wp-config-sample.php still contains.
			if ( '' === $value || str_contains( $value, 'put your unique phrase here' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Which cipher this host will use. For the diagnostics panel.
	 */
	public static function cipher(): string {
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			return 'sodium/xsalsa20-poly1305';
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			return 'openssl/aes-256-gcm';
		}

		return '';
	}

	/**
	 * Whether this host can encrypt at all.
	 */
	public static function available(): bool {
		return '' !== self::cipher();
	}

	/**
	 * Decrypt everything once per request.
	 */
	private function load(): void {
		if ( null !== $this->cache ) {
			return;
		}

		$this->cache      = array();
		$this->unreadable = array();

		foreach ( $this->raw() as $name => $ciphertext ) {
			$plain = $this->decrypt( (string) $ciphertext );

			if ( null === $plain ) {
				$this->unreadable[] = (string) $name;

				continue;
			}

			$this->cache[ (string) $name ] = $plain;
		}
	}

	/**
	 * The stored ciphertexts.
	 *
	 * @return array<string, string>
	 */
	private function raw(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persist and drop the decrypted cache.
	 *
	 * @param array<string, string> $stored Name => ciphertext.
	 */
	private function save( array $stored ): void {
		if ( array() === $stored ) {
			delete_option( self::OPTION );
		} else {
			update_option( self::OPTION, $stored, false );
		}

		$this->cache = null;
	}

	/**
	 * The encryption key, derived from the site's own salts.
	 *
	 * Derived rather than stored: a key sitting next to its own ciphertext
	 * protects nothing, and there is nowhere else in a plain WordPress install
	 * to put one.
	 */
	private function key(): string {
		$material = 'aicake-secret-v1|' . wp_salt( 'secure_auth' );

		if ( function_exists( 'sodium_crypto_generichash' ) ) {
			return sodium_crypto_generichash( $material, '', 32 );
		}

		return hash( 'sha256', $material, true );
	}

	/**
	 * @param string $value Plaintext.
	 * @return string Scheme-prefixed, base64-encoded ciphertext.
	 */
	private function encrypt( string $value ): string {
		$key = $this->key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $value, $nonce, $key );

			$out = self::SCHEME_SODIUM . base64_encode( $nonce . $cipher );

			if ( function_exists( 'sodium_memzero' ) ) {
				sodium_memzero( $key );
			}

			return $out;
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';

			$cipher = openssl_encrypt( $value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

			if ( false === $cipher ) {
				return '';
			}

			return self::SCHEME_OPENSSL . base64_encode( $iv . $tag . $cipher );
		}

		/*
		 * No cipher on this host. Storing the key in plaintext would be the one
		 * outcome D-050 promised not to produce, so nothing is stored and the
		 * screen refuses to accept a key at all.
		 */
		return '';
	}

	/**
	 * @param string $stored Scheme-prefixed ciphertext.
	 * @return string|null Null when it will not decrypt.
	 */
	private function decrypt( string $stored ): ?string {
		$key = $this->key();

		if ( str_starts_with( $stored, self::SCHEME_SODIUM ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return null;
			}

			$raw = base64_decode( substr( $stored, strlen( self::SCHEME_SODIUM ) ), true );

			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return null;
			}

			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				$key
			);

			return false === $plain ? null : $plain;
		}

		if ( str_starts_with( $stored, self::SCHEME_OPENSSL ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return null;
			}

			$raw = base64_decode( substr( $stored, strlen( self::SCHEME_OPENSSL ) ), true );

			if ( false === $raw || strlen( $raw ) <= 28 ) {
				return null;
			}

			$plain = openssl_decrypt(
				substr( $raw, 28 ),
				'aes-256-gcm',
				$key,
				OPENSSL_RAW_DATA,
				substr( $raw, 0, 12 ),
				substr( $raw, 12, 16 )
			);

			return false === $plain ? null : $plain;
		}

		// An unrecognised scheme is a value we must not guess at.
		return null;
	}
}
