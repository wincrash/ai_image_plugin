<?php
/**
 * Schema and filesystem installation.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake;

use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables and the storage root, and upgrades them when the schema moves.
 */
class Installer {

	/**
	 * Bumped whenever the SQL below changes. Separate from the plugin version
	 * so a plugin release with no schema change costs nothing on upgrade.
	 */
	public const SCHEMA_VERSION = 4;

	public const SCHEMA_OPTION = 'aicake_schema_version';

	/**
	 * Runs on activation.
	 */
	public static function activate(): void {
		self::install_tables();
		self::create_storage();

		add_option( 'aicake_installed_at', gmdate( 'Y-m-d H:i:s' ) );
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );

		// Seed defaults so the settings screen has something to show.
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}

		/*
		 * Find out at activation whether this host allows loopback requests,
		 * rather than discovering it from a customer whose generation hung
		 * (PLAN.md §6.2). Deferred to shutdown because activation already
		 * holds a worker and this makes an HTTP call to ourselves.
		 */
		add_action(
			'shutdown',
			static function (): void {
				( new \AiCake\Queue\Dispatcher( new \AiCake\Support\Logger( new Settings() ) ) )->test_loopback();
			}
		);
	}

	/**
	 * Runs on deactivation. Deliberately keeps every table and file — teardown
	 * belongs in uninstall.php, behind an explicit confirmation (PLAN.md §16).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'aicake_cleanup' );
		\AiCake\Queue\Scheduler::unschedule();
	}

	/**
	 * Applies the schema when it is behind. Cheap enough to call on every load.
	 */
	public static function maybe_upgrade(): void {
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) === self::SCHEMA_VERSION ) {
			return;
		}

		self::install_tables();
		self::create_storage();
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
	}

	/**
	 * Fully-qualified name of one of our tables.
	 *
	 * @param string $name Bare name, e.g. 'designs'.
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'aicake_' . $name;
	}

	/**
	 * dbDelta the two tables from PLAN.md §4.4.
	 */
	private static function install_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$designs = self::table( 'designs' );
		$jobs    = self::table( 'jobs' );

		/*
		 * The plan specifies JSON columns. They are declared LONGTEXT here on
		 * purpose: dbDelta cannot compare a JSON column against its own
		 * definition, so it emits an ALTER on every single page load. MariaDB
		 * aliases JSON to LONGTEXT anyway, so nothing is actually lost.
		 *
		 * `order_id` / `order_item_id` duplicate a link that also lives in
		 * order item meta, and they earn it: retention (§12.5) must never
		 * delete a design belonging to an order, and answering "does an order
		 * reference this design?" from the WooCommerce side means a query per
		 * candidate row every time the cleanup job runs.
		 */
		$sql = array();

		$sql[] = "CREATE TABLE {$designs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(32) NOT NULL,
			session_key VARCHAR(64) NOT NULL,
			ip_hash CHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			product_id BIGINT UNSIGNED DEFAULT NULL,
			variation_id BIGINT UNSIGNED DEFAULT NULL,
			format_type VARCHAR(16) DEFAULT NULL,
			format_mm DECIMAL(6,2) DEFAULT NULL,
			prompt_raw TEXT NOT NULL,
			prompt_en TEXT DEFAULT NULL,
			prompt_final TEXT DEFAULT NULL,
			text_payload LONGTEXT DEFAULT NULL,
			provider VARCHAR(40) DEFAULT NULL,
			model VARCHAR(80) DEFAULT NULL,
			seed BIGINT DEFAULT NULL,
			aspect VARCHAR(12) DEFAULT NULL,
			status VARCHAR(20) NOT NULL,
			moderation LONGTEXT DEFAULT NULL,
			file_master VARCHAR(255) DEFAULT NULL,
			file_preview VARCHAR(255) DEFAULT NULL,
			file_proof VARCHAR(255) DEFAULT NULL,
			file_print VARCHAR(255) DEFAULT NULL,
			order_id BIGINT UNSIGNED DEFAULT NULL,
			order_item_id BIGINT UNSIGNED DEFAULT NULL,
			cost_usd DECIMAL(10,5) NOT NULL DEFAULT 0,
			error_code VARCHAR(40) DEFAULT NULL,
			error_message TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY ip_created (ip_hash, created_at),
			KEY session_created (session_key, created_at),
			KEY user_created (user_id, created_at),
			KEY status_created (status, created_at),
			KEY created_cost (created_at, cost_usd),
			KEY order_id (order_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$jobs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			design_id BIGINT UNSIGNED NOT NULL,
			type VARCHAR(24) NOT NULL,
			status VARCHAR(16) NOT NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			claimed_at DATETIME DEFAULT NULL,
			claim_token CHAR(32) DEFAULT NULL,
			payload LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status_created (status, created_at),
			KEY design_id (design_id)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Create the storage root and its two zones, and block HTTP access to them.
	 */
	public static function create_storage(): void {
		$settings = new Settings();
		$root     = $settings->storage_dir();

		foreach ( array( $root, $root . '/sessions', $root . '/orders' ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}

		if ( ! is_dir( $root ) ) {
			return;
		}

		/*
		 * Only meaningful when the root is inside the webroot. When it is
		 * outside — which production should do — HTTP cannot reach it at all
		 * and these files are harmless clutter that costs nothing.
		 */
		self::write_if_absent(
			$root . '/.htaccess',
			"# Managed by AI Cake Topper. Generated files must never be served directly.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
		);

		self::write_if_absent( $root . '/index.php', "<?php\n// Silence is golden.\n" );
	}

	/**
	 * Write a file only when it does not already exist, so a hand-edit survives.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents File contents.
	 */
	private static function write_if_absent( string $path, string $contents ): void {
		if ( file_exists( $path ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		@file_put_contents( $path, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
