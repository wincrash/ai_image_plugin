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

/**
 * Where the bundled fonts live.
 */
defined( 'AICAKE_FONT_DIR' ) || define( 'AICAKE_FONT_DIR', __DIR__ . '/../fonts' );
