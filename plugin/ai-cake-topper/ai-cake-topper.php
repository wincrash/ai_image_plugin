<?php
/**
 * Plugin Name:          AI Cake Topper
 * Description:          Customers describe a decoration in Lithuanian, an AI generates it, and it is printed on edible icing sheets.
 * Version:              0.1.0
 * Requires at least:    6.4
 * Requires PHP:         8.0
 * Requires Plugins:     woocommerce
 * Author:               Ruslan Pacevič
 * Text Domain:          ai-cake-topper
 * Domain Path:          /languages
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 8.0
 * WC tested up to:      10.9
 *
 * @package AiCake
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const AICAKE_VERSION = '0.1.0';
const AICAKE_MIN_PHP = '8.0';

define( 'AICAKE_FILE', __FILE__ );
define( 'AICAKE_DIR', plugin_dir_path( __FILE__ ) );
define( 'AICAKE_URL', plugin_dir_url( __FILE__ ) );

/**
 * The host is a managed platform we do not control, so the PHP version is not
 * a given. Fail with a readable notice rather than a fatal on some later call.
 */
if ( version_compare( PHP_VERSION, AICAKE_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: running PHP version */
						__( 'AI Cake Topper requires PHP %1$s or newer. This server runs PHP %2$s.', 'ai-cake-topper' ),
						AICAKE_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);

	return;
}

/**
 * Hand-rolled PSR-4-ish autoloader: AiCake\Foo\Bar -> src/Foo/Bar.php.
 *
 * No Composer at runtime (CLAUDE.md), so deployment stays "upload the folder".
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'AiCake\\' ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', substr( $class_name, strlen( 'AiCake\\' ) ) );
		$path     = AICAKE_DIR . 'src/' . $relative . '.php';

		// realpath() keeps a crafted class name from escaping src/.
		$real = realpath( $path );
		$root = realpath( AICAKE_DIR . 'src' );

		if ( false !== $real && false !== $root && str_starts_with( $real, $root ) ) {
			require_once $real;
		}
	}
);

/**
 * HPOS. Declared before WooCommerce initialises or the declaration is ignored
 * and the store shows an incompatibility warning (PLAN.md §13.1).
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', AICAKE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', AICAKE_FILE, false );
		}
	}
);

register_activation_hook( AICAKE_FILE, array( \AiCake\Installer::class, 'activate' ) );
register_deactivation_hook( AICAKE_FILE, array( \AiCake\Installer::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		( new \AiCake\Plugin() )->boot();
	}
);
