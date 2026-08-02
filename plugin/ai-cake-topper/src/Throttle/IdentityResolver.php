<?php
/**
 * Who is asking — as far as we are willing to know.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Throttle;

use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the composite identity from PLAN.md §11.2.
 *
 * IP alone is weak in Lithuania: mobile carriers use CGNAT, so hundreds of
 * real customers share one address, and IPv6 prefixes rotate. So we key on
 * three things and let the limiter decide which to count against.
 *
 * The raw IP is never stored — only a salted hash.
 */
class IdentityResolver {

	public const COOKIE = 'aicake_session';

	private Settings $settings;

	private ?string $session_key = null;

	/**
	 * @param Settings $settings Configuration.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The client IP, as best we can establish it.
	 *
	 * Only reads a forwarding header when an administrator has said this site
	 * actually sits behind that proxy. Trusting X-Forwarded-For by default
	 * would let anyone mint unlimited identities with a header (PLAN.md §11.2).
	 */
	public function client_ip(): string {
		$header = (string) $this->settings->get( 'trusted_ip_header', 'none' );

		if ( 'cloudflare' === $header ) {
			$ip = $this->server_value( 'HTTP_CF_CONNECTING_IP' );

			if ( '' !== $ip ) {
				return $ip;
			}
		}

		if ( 'x-forwarded-for' === $header ) {
			$forwarded = $this->server_value( 'HTTP_X_FORWARDED_FOR' );

			if ( '' !== $forwarded ) {
				// Left-most is the original client; the rest are proxies.
				foreach ( explode( ',', $forwarded ) as $candidate ) {
					$candidate = trim( $candidate );

					if ( false !== filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
						return $candidate;
					}
				}
			}
		}

		return $this->server_value( 'REMOTE_ADDR' );
	}

	/**
	 * SHA-256 of the IP with the site salt. Changing AICAKE_IP_SALT
	 * deliberately invalidates all existing rate-limit history.
	 */
	public function ip_hash(): string {
		$salt = $this->settings->secret( 'ip_salt' );

		if ( '' === $salt ) {
			// Better a stable site-specific fallback than an unsalted hash of
			// something as guessable as an IP address.
			$salt = (string) wp_salt( 'nonce' );
		}

		return hash( 'sha256', $this->client_ip() . '|' . $salt );
	}

	/**
	 * The session key from the cookie, or '' when the visitor has none yet.
	 */
	public function session_key(): string {
		if ( null !== $this->session_key ) {
			return $this->session_key;
		}

		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';

		// 32 hex characters or nothing — a malformed cookie is not an identity.
		$this->session_key = (bool) preg_match( '/^[a-f0-9]{32}$/', $raw ) ? $raw : '';

		return $this->session_key;
	}

	/**
	 * Issue a session key and set the cookie.
	 *
	 * Must be called before output starts. In practice that means the uncached
	 * REST session endpoint (PLAN.md §7), never a cached page.
	 */
	public function issue_session_key(): string {
		$existing = $this->session_key();

		if ( '' !== $existing ) {
			return $existing;
		}

		$key = bin2hex( random_bytes( 16 ) );

		setcookie(
			self::COOKIE,
			$key,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$this->session_key      = $key;
		$_COOKIE[ self::COOKIE ] = $key;

		return $key;
	}

	/**
	 * Logged-in user, or 0.
	 */
	public function user_id(): int {
		return get_current_user_id();
	}

	/**
	 * Read a $_SERVER string safely.
	 *
	 * @param string $key Key in $_SERVER.
	 */
	private function server_value( string $key ): string {
		if ( ! isset( $_SERVER[ $key ] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
	}
}
