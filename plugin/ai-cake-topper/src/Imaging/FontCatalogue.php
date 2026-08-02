<?php
/**
 * The fonts we are willing to print with.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Imaging;

use AiCake\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Bundled fonts and their Lithuanian coverage (PLAN.md §9.4).
 *
 * The rule this class exists to enforce: **a font is not offered to a customer
 * unless every Lithuanian character is present in its cmap table.** Most
 * decorative display fonts omit `ė` and `ū` — precisely the fonts that look
 * best on a cake — and a missing glyph is a blank box on a printed, paid-for,
 * non-refundable product.
 *
 * Fonts are bundled, never fetched from a CDN: production has no external
 * services, and Google Fonts hotlinking is a GDPR problem in the EU besides.
 */
class FontCatalogue {

	/**
	 * The characters every offered font must contain.
	 *
	 * The nine Lithuanian letters that are not plain ASCII, in both cases.
	 * ASCII itself is checked too — a display font missing digits would break
	 * "70 metų jubiliejus" just as badly.
	 */
	public const LITHUANIAN = array(
		'ą', 'č', 'ę', 'ė', 'į', 'š', 'ų', 'ū', 'ž',
		'Ą', 'Č', 'Ę', 'Ė', 'Į', 'Š', 'Ų', 'Ū', 'Ž',
	);

	/**
	 * A cached coverage verdict lasts this long. Fonts do not change on a live
	 * site, but a new one can be dropped in, and re-reading four ~700 KB files
	 * on every page load would be silly.
	 */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	private Logger $logger;

	private string $directory;

	/**
	 * @param Logger      $logger    Logging.
	 * @param string|null $directory Font directory. Defaults to the bundled one.
	 */
	public function __construct( Logger $logger, ?string $directory = null ) {
		$this->logger    = $logger;
		$this->directory = untrailingslashit( $directory ?? AICAKE_DIR . 'fonts' );
	}

	/**
	 * Fonts that are safe to offer, keyed by handle.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function usable(): array {
		return array_filter(
			$this->all(),
			static fn( array $font ): bool => $font['usable']
		);
	}

	/**
	 * Every font found, whether usable or not.
	 *
	 * The unusable ones are deliberately still listed: an administrator who
	 * drops in a beautiful script font needs to be told *why* it is not on
	 * offer, not left wondering where it went.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$cached = get_transient( 'aicake_font_catalogue' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$catalogue = array();

		foreach ( $this->files() as $path ) {
			$handle = sanitize_key( pathinfo( $path, PATHINFO_FILENAME ) );
			$missing = TtfCmap::missing( $path, self::LITHUANIAN );

			$catalogue[ $handle ] = array(
				'handle'  => $handle,
				'label'   => $this->label( $path ),
				'path'    => $path,
				'usable'  => array() === $missing,
				'missing' => $missing,
			);

			if ( array() !== $missing ) {
				$this->logger->warning(
					'A bundled font is missing Lithuanian characters and will not be offered.',
					array(
						'font'    => $handle,
						'missing' => implode( ' ', $missing ),
					)
				);
			}
		}

		set_transient( 'aicake_font_catalogue', $catalogue, self::CACHE_TTL );

		return $catalogue;
	}

	/**
	 * Path for a font handle, or the default when it is unknown or unusable.
	 *
	 * Always returns something printable if any font at all is usable, because
	 * falling back to a working font is better than rendering nothing.
	 *
	 * @param string $handle Font handle.
	 */
	public function path( string $handle ): ?string {
		$catalogue = $this->all();

		if ( isset( $catalogue[ $handle ] ) && $catalogue[ $handle ]['usable'] ) {
			return (string) $catalogue[ $handle ]['path'];
		}

		$usable = $this->usable();

		if ( array() === $usable ) {
			return null;
		}

		$first = reset( $usable );

		if ( '' !== $handle ) {
			$this->logger->warning(
				'Requested font is unavailable; falling back.',
				array(
					'requested' => $handle,
					'using'     => $first['handle'],
				)
			);
		}

		return (string) $first['path'];
	}

	/**
	 * Whether anything can be printed at all.
	 */
	public function has_usable_font(): bool {
		return array() !== $this->usable();
	}

	/**
	 * Drop the cached verdicts, after adding or removing a font.
	 */
	public function flush(): void {
		delete_transient( 'aicake_font_catalogue' );
	}

	/**
	 * TrueType files in the font directory.
	 *
	 * @return string[]
	 */
	private function files(): array {
		if ( ! is_dir( $this->directory ) ) {
			return array();
		}

		$found = glob( $this->directory . '/*.{ttf,TTF,otf,OTF}', GLOB_BRACE );

		return false === $found ? array() : $found;
	}

	/**
	 * A readable name from the filename.
	 *
	 * The font's own `name` table would be nicer, but it is a second parser
	 * for a cosmetic gain — and the bundled filenames are already the names.
	 *
	 * @param string $path Font path.
	 */
	private function label( string $path ): string {
		$name = pathinfo( $path, PATHINFO_FILENAME );

		// "DejaVuSans-Bold" -> "DejaVu Sans Bold"
		$name = str_replace( array( '-', '_' ), ' ', $name );
		$name = preg_replace( '/(?<=[a-z])(?=[A-Z])/', ' ', $name );

		return trim( (string) $name );
	}
}
