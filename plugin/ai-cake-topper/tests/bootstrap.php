<?php
/**
 * Test bootstrap.
 *
 * @package AiCake
 */

declare( strict_types=1 );

/*
 * Mm and SheetLayout are pure — no WordPress functions, no state — but they
 * still carry the standard ABSPATH guard, because a plugin file reachable over
 * HTTP must not execute standalone. Defining it is all that is needed to
 * exercise them outside WordPress, which is the point of keeping them pure
 * (PLAN.md §19).
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../src/Support/Mm.php';
require_once __DIR__ . '/../src/Imaging/SheetLayout.php';
require_once __DIR__ . '/../src/Imaging/TtfCmap.php';
require_once __DIR__ . '/../src/Moderation/LtNormaliser.php';

/*
 * GdEngine is not pure — it needs the GD extension — but the two methods worth
 * testing here, `inject_phys` and `read_dpi`, are byte manipulation that never
 * touches WordPress. Settings only reaches for `get_option()` inside `get()`,
 * which neither of them calls, so a real Logger constructs fine standalone.
 */
require_once __DIR__ . '/../src/Support/Settings.php';
require_once __DIR__ . '/../src/Support/Logger.php';
require_once __DIR__ . '/../src/Imaging/GdEngine.php';

/*
 * LayerInspector is the same shape — GD plus arithmetic, no WordPress — and it
 * is the check D-033 calls non-optional, so it is worth exercising where a
 * failure is a one-second test run rather than a deployment.
 *
 * It does log its refusals, though, and Logger reads its level through
 * Settings. Hence the stub below: without it the suite dies on the first
 * *rejection*, which is to say on every test that actually matters.
 */
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Option name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) { // phpcs:ignore
		return $default;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data  Data.
	 * @param int   $flags Encoding flags.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $flags = 0 ) { // phpcs:ignore
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

require_once __DIR__ . '/../src/Imaging/LayerInspector.php';

/*
 * The layout suggester talks to Gemini through the HttpClient seam, which is
 * exactly what makes it testable with a canned reply and no network. It does
 * check for a key first, though — a suggester with no key correctly does
 * nothing, and without this constant every assertion below would pass by
 * testing that.
 */
defined( 'AICAKE_GEMINI_KEY' ) || define( 'AICAKE_GEMINI_KEY', 'test-key-never-sent-anywhere' );

require_once __DIR__ . '/../src/Support/HttpResponse.php';
require_once __DIR__ . '/../src/Support/HttpClient.php';
require_once __DIR__ . '/../src/Pipeline/LayoutSuggester.php';

/*
 * FormatCatalogue and PrintSpec are pure arithmetic over Mm and SheetLayout,
 * but they build customer-facing labels, so they reach for `__()`. Stubbing it
 * keeps the catalogue testable without WordPress — and the catalogue is worth
 * testing outside WordPress, because it is the thing that decides what physical
 * size a customer is sold.
 *
 * `PrintSpec::for_product()` does touch `get_post_meta()`. Nothing here calls
 * it; the format path never reads product meta, which is the point of D-035.
 */
if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text.
	 * @param string $domain Text domain.
	 */
	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}
}

if ( ! function_exists( 'number_format_i18n' ) ) {
	/**
	 * @param float $number   Number.
	 * @param int   $decimals Decimals.
	 */
	function number_format_i18n( $number, int $decimals = 0 ): string { // phpcs:ignore
		return number_format( (float) $number, $decimals );
	}
}

require_once __DIR__ . '/../src/Domain/PrintSpec.php';
require_once __DIR__ . '/../src/Domain/FormatCatalogue.php';

/**
 * Where the bundled fonts live.
 */
defined( 'AICAKE_FONT_DIR' ) || define( 'AICAKE_FONT_DIR', __DIR__ . '/../fonts' );
