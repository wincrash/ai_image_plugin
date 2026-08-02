<?php
/**
 * Which characters a font actually contains.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Imaging;

defined( 'ABSPATH' ) || exit;

/**
 * A minimal TrueType `cmap` reader.
 *
 * PLAN.md §9.4 is emphatic that font coverage is verified against the cmap
 * table rather than by looking at rendered output, and the reason is specific
 * to this product: most decorative display fonts — exactly the ones that look
 * good on a cake — omit `ė` and `ū`. A missing glyph renders as a blank box,
 * and a blank box on a printed cake is not refundable.
 *
 * Rendering and measuring ink would be the obvious alternative, but it cannot
 * distinguish "this font has no ė" from "this font draws ė very lightly", and
 * it needs FreeType to be working before it can tell you anything. Reading the
 * table answers the question directly and works even where text rendering does
 * not.
 *
 * Only formats 4 and 12 are implemented, which between them cover every
 * modern font. Formats 0, 2 and 6 belong to fonts old enough that Lithuanian
 * coverage would be the least of the problems.
 */
final class TtfCmap {

	/**
	 * Not instantiable.
	 */
	private function __construct() {}

	/**
	 * Every Unicode codepoint the font maps to a glyph.
	 *
	 * @param string $path Absolute path to a .ttf or .otf file.
	 * @return array<int, bool>|null Codepoint => true, or null if unreadable.
	 */
	public static function codepoints( string $path ): ?array {
		if ( ! is_readable( $path ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		$font = file_get_contents( $path );

		if ( false === $font || strlen( $font ) < 12 ) {
			return null;
		}

		$table = self::find_table( $font, 'cmap' );

		if ( null === $table ) {
			return null;
		}

		return self::read_cmap( $font, $table );
	}

	/**
	 * Which of the given characters the font lacks.
	 *
	 * @param string   $path       Font path.
	 * @param string[] $characters UTF-8 characters to check.
	 * @return string[] The missing ones. Empty means full coverage.
	 */
	public static function missing( string $path, array $characters ): array {
		$available = self::codepoints( $path );

		if ( null === $available ) {
			// Unreadable is not the same as incomplete, but for the caller's
			// purposes an unusable font fails either way.
			return $characters;
		}

		$missing = array();

		foreach ( $characters as $character ) {
			$codepoint = self::codepoint_of( $character );

			if ( 0 !== $codepoint && ! isset( $available[ $codepoint ] ) ) {
				$missing[] = $character;
			}
		}

		return $missing;
	}

	/**
	 * The Unicode codepoint of a single UTF-8 character.
	 *
	 * @param string $character One character.
	 */
	public static function codepoint_of( string $character ): int {
		if ( '' === $character ) {
			return 0;
		}

		$converted = mb_convert_encoding( $character, 'UTF-32BE', 'UTF-8' );

		if ( '' === $converted || strlen( $converted ) < 4 ) {
			return 0;
		}

		$parts = unpack( 'N', substr( $converted, 0, 4 ) );

		return is_array( $parts ) ? (int) $parts[1] : 0;
	}

	/**
	 * Locate a table in the sfnt directory.
	 *
	 * @param string $font Font bytes.
	 * @param string $tag  Four-character table tag.
	 * @return array{offset:int, length:int}|null
	 */
	private static function find_table( string $font, string $tag ): ?array {
		$header = unpack( 'Nversion/nnumTables', substr( $font, 0, 6 ) );

		if ( ! is_array( $header ) ) {
			return null;
		}

		$count = (int) $header['numTables'];

		// Sanity: a font with a thousand tables is not a font.
		if ( $count < 1 || $count > 512 ) {
			return null;
		}

		for ( $i = 0; $i < $count; $i++ ) {
			$entry = substr( $font, 12 + ( $i * 16 ), 16 );

			if ( strlen( $entry ) < 16 ) {
				return null;
			}

			if ( substr( $entry, 0, 4 ) !== $tag ) {
				continue;
			}

			$parts = unpack( 'Nchecksum/Noffset/Nlength', substr( $entry, 4, 12 ) );

			if ( ! is_array( $parts ) ) {
				return null;
			}

			return array(
				'offset' => (int) $parts['offset'],
				'length' => (int) $parts['length'],
			);
		}

		return null;
	}

	/**
	 * Walk the cmap's encoding records and read the best subtable.
	 *
	 * @param string                        $font  Font bytes.
	 * @param array{offset:int, length:int} $table cmap table location.
	 * @return array<int, bool>|null
	 */
	private static function read_cmap( string $font, array $table ): ?array {
		$base   = $table['offset'];
		$header = unpack( 'nversion/nnumTables', substr( $font, $base, 4 ) );

		if ( ! is_array( $header ) ) {
			return null;
		}

		$best      = null;
		$best_rank = -1;

		for ( $i = 0; $i < (int) $header['numTables']; $i++ ) {
			$record = unpack(
				'nplatform/nencoding/Noffset',
				substr( $font, $base + 4 + ( $i * 8 ), 8 )
			);

			if ( ! is_array( $record ) ) {
				continue;
			}

			$rank = self::rank( (int) $record['platform'], (int) $record['encoding'] );

			if ( $rank > $best_rank ) {
				$best_rank = $rank;
				$best      = $base + (int) $record['offset'];
			}
		}

		if ( null === $best ) {
			return null;
		}

		$format = unpack( 'nformat', substr( $font, $best, 2 ) );

		if ( ! is_array( $format ) ) {
			return null;
		}

		if ( 12 === (int) $format['format'] ) {
			return self::read_format_12( $font, $best );
		}

		if ( 4 === (int) $format['format'] ) {
			return self::read_format_4( $font, $best );
		}

		return null;
	}

	/**
	 * Prefer full Unicode subtables over BMP-only ones.
	 *
	 * @param int $platform Platform id.
	 * @param int $encoding Encoding id.
	 */
	private static function rank( int $platform, int $encoding ): int {
		// Windows UCS-4, then Windows BMP, then any Unicode platform record.
		if ( 3 === $platform && 10 === $encoding ) {
			return 4;
		}

		if ( 3 === $platform && 1 === $encoding ) {
			return 3;
		}

		if ( 0 === $platform ) {
			return 2;
		}

		return 0;
	}

	/**
	 * Format 4: segment mapping to delta values. The BMP workhorse.
	 *
	 * @param string $font   Font bytes.
	 * @param int    $offset Subtable offset.
	 * @return array<int, bool>|null
	 */
	private static function read_format_4( string $font, int $offset ): ?array {
		$header = unpack( 'nformat/nlength/nlanguage/nsegCountX2', substr( $font, $offset, 8 ) );

		if ( ! is_array( $header ) ) {
			return null;
		}

		$segments = (int) ( $header['segCountX2'] / 2 );

		if ( $segments < 1 ) {
			return null;
		}

		$ends    = $offset + 14;
		$starts  = $ends + ( $segments * 2 ) + 2;
		$deltas  = $starts + ( $segments * 2 );
		$ranges  = $deltas + ( $segments * 2 );
		$mapping = array();

		for ( $i = 0; $i < $segments; $i++ ) {
			$end   = self::uint16( $font, $ends + ( $i * 2 ) );
			$start = self::uint16( $font, $starts + ( $i * 2 ) );
			$delta = self::uint16( $font, $deltas + ( $i * 2 ) );
			$range = self::uint16( $font, $ranges + ( $i * 2 ) );

			// 0xFFFF is the mandatory terminating segment.
			if ( 0xFFFF === $start ) {
				continue;
			}

			for ( $code = $start; $code <= $end && $code <= 0xFFFF; $code++ ) {
				if ( 0 === $range ) {
					$glyph = ( $code + $delta ) & 0xFFFF;
				} else {
					$index = $ranges + ( $i * 2 ) + $range + ( ( $code - $start ) * 2 );
					$glyph = self::uint16( $font, $index );

					if ( 0 !== $glyph ) {
						$glyph = ( $glyph + $delta ) & 0xFFFF;
					}
				}

				if ( 0 !== $glyph ) {
					$mapping[ $code ] = true;
				}
			}
		}

		return $mapping;
	}

	/**
	 * Format 12: segmented coverage, for fonts reaching beyond the BMP.
	 *
	 * @param string $font   Font bytes.
	 * @param int    $offset Subtable offset.
	 * @return array<int, bool>|null
	 */
	private static function read_format_12( string $font, int $offset ): ?array {
		$header = unpack( 'nformat/nreserved/Nlength/Nlanguage/NnumGroups', substr( $font, $offset, 16 ) );

		if ( ! is_array( $header ) ) {
			return null;
		}

		$groups  = (int) $header['numGroups'];
		$mapping = array();

		if ( $groups < 1 || $groups > 100000 ) {
			return null;
		}

		for ( $i = 0; $i < $groups; $i++ ) {
			$group = unpack(
				'NstartChar/NendChar/NstartGlyph',
				substr( $font, $offset + 16 + ( $i * 12 ), 12 )
			);

			if ( ! is_array( $group ) ) {
				continue;
			}

			$start = (int) $group['startChar'];
			$end   = (int) $group['endChar'];

			// Guard against a corrupt range claiming the whole codespace.
			if ( $end - $start > 0x10FFFF ) {
				continue;
			}

			for ( $code = $start; $code <= $end; $code++ ) {
				$mapping[ $code ] = true;
			}
		}

		return $mapping;
	}

	/**
	 * Read a big-endian unsigned 16-bit value.
	 *
	 * @param string $font   Font bytes.
	 * @param int    $offset Byte offset.
	 */
	private static function uint16( string $font, int $offset ): int {
		$bytes = substr( $font, $offset, 2 );

		if ( 2 !== strlen( $bytes ) ) {
			return 0;
		}

		$parts = unpack( 'n', $bytes );

		return is_array( $parts ) ? (int) $parts[1] : 0;
	}
}
