<?php
/**
 * Host capability detection and Site Health reporting.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake;

use AiCake\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * What this host can actually do.
 *
 * Production is a managed platform we cannot inspect over SSH, so the plugin
 * has to report its own environment. This is the intended answer to the open
 * FreeType question (STATE.md): activate the plugin, read the panel, done —
 * nothing gets uploaded to the live shop.
 */
class Capabilities {

	private Settings $settings;

	/**
	 * @var array<string, mixed>|null
	 */
	private ?array $report = null;

	/**
	 * @param Settings $settings Configuration.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register the Site Health hooks.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
		add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
	}

	/**
	 * Everything we know about the host, computed once per request.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		if ( null !== $this->report ) {
			return $this->report;
		}

		$gd = function_exists( 'gd_info' ) ? gd_info() : array();

		$storage  = $this->settings->storage_dir();
		$writable = $this->can_actually_write( $storage );

		$this->report = array(
			'php_version'       => PHP_VERSION,
			'wp_version'        => get_bloginfo( 'version' ),
			'gd'                => array() !== $gd,
			'gd_version'        => $gd['GD Version'] ?? '',
			'freetype'          => ! empty( $gd['FreeType Support'] ) && function_exists( 'imagettftext' ),
			'webp'              => ! empty( $gd['WebP Support'] ) || ! empty( $gd['WEBP Support'] ),
			'jpeg'              => ! empty( $gd['JPEG Support'] ),
			'png'               => ! empty( $gd['PNG Support'] ),
			'imagick'           => class_exists( 'Imagick' ),
			'force_gd'          => $this->force_gd(),
			'memory_limit'      => ini_get( 'memory_limit' ),
			'memory_bytes'      => wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ),
			'max_execution'     => (int) ini_get( 'max_execution_time' ),
			'storage_dir'       => $storage,
			'storage_writable'  => $writable,
			'storage_in_webroot'=> $this->settings->storage_is_inside_webroot(),
			'storage_defined'   => defined( 'AICAKE_STORAGE_DIR' ),
			'secrets'           => $this->secret_status(),
		);

		return $this->report;
	}

	/**
	 * Whether the web user can genuinely write where files actually go.
	 *
	 * `wp_is_writable()` on the storage root is not enough, and the difference
	 * is not academic — it cost a silently broken generation on the testbed.
	 * Files are written to `sessions/YYYY/MM/`, and those directories are
	 * created by whoever first triggers a write. Activate the plugin through
	 * WP-CLI as root — which is how plenty of managed hosts install things —
	 * and they end up owned by root while PHP runs as the web user. The root
	 * directory looks fine; every write into it fails.
	 *
	 * So this actually creates the dated directory and writes a file, which is
	 * the only check that answers the real question. Cached, because the admin
	 * notice consults it on every page load.
	 *
	 * **Both zones, not just `sessions/`.** `orders/YYYY/MM/` has the identical
	 * failure mode and a worse consequence: the customer has already paid, the
	 * image already exists, and fulfilment dies on the one step that cannot be
	 * skipped. It is also the likelier of the two to be poisoned, because it is
	 * created by whatever first archives an order — including a maintenance or
	 * test run made from the command line as root (D-031).
	 *
	 * The dated directory is what gets probed rather than the zone root: the
	 * per-order folder is created inside it, so its ownership is what decides
	 * whether `mkdir` succeeds.
	 *
	 * @param string $root Storage root.
	 */
	private function can_actually_write( string $root ): bool {
		$cached = get_transient( 'aicake_storage_writable' );

		if ( false !== $cached ) {
			return 'yes' === $cached;
		}

		$ok = $this->probe_zone( $root . '/sessions/' . gmdate( 'Y/m' ) )
			&& $this->probe_zone( $root . '/orders/' . gmdate( 'Y/m' ) );

		set_transient( 'aicake_storage_writable', $ok ? 'yes' : 'no', 5 * MINUTE_IN_SECONDS );

		return $ok;
	}

	/**
	 * Create one dated directory and write a file into it.
	 *
	 * @param string $dir Absolute path to the dated directory.
	 */
	private function probe_zone( string $dir ): bool {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$probe = $dir . '/.aicake-write-test';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		$ok = false !== @file_put_contents( $probe, 'ok' );

		if ( $ok ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $probe );
		}

		return $ok;
	}

	/**
	 * Which provider keys are configured. Never the values.
	 *
	 * @return array<string, bool>
	 */
	private function secret_status(): array {
		$out = array();

		foreach ( Settings::secret_names() as $name ) {
			$out[ $name ] = $this->settings->has_secret( $name );
		}

		return $out;
	}

	/**
	 * Whether to ignore Imagick even when present.
	 *
	 * Defaults on. Production has GD only (D-013/D-015), and the testbed has
	 * Imagick, so without this we would develop against an engine the customer
	 * never runs. Set AICAKE_FORCE_GD to false to compare the two deliberately.
	 */
	public function force_gd(): bool {
		return ! defined( 'AICAKE_FORCE_GD' ) || (bool) constant( 'AICAKE_FORCE_GD' );
	}

	/**
	 * The engine that will actually be used.
	 */
	public function engine(): string {
		if ( ! $this->force_gd() && class_exists( 'Imagick' ) ) {
			return 'imagick';
		}

		return 'gd';
	}

	/**
	 * Whether the host can do everything the pipeline needs.
	 */
	public function is_ready(): bool {
		$r = $this->report();

		return $r['gd'] && $r['freetype'] && $r['png'] && $r['storage_writable'];
	}

	/**
	 * Add our Site Health tests.
	 *
	 * @param array<string, mixed> $tests Registered tests.
	 * @return array<string, mixed>
	 */
	public function add_tests( array $tests ): array {
		$tests['direct']['aicake_imaging'] = array(
			'label' => __( 'AI Cake Topper: image rendering', 'ai-cake-topper' ),
			'test'  => array( $this, 'test_imaging' ),
		);

		$tests['direct']['aicake_storage'] = array(
			'label' => __( 'AI Cake Topper: file storage', 'ai-cake-topper' ),
			'test'  => array( $this, 'test_storage' ),
		);

		return $tests;
	}

	/**
	 * GD, FreeType and memory — the three that decide whether we can render.
	 *
	 * @return array<string, mixed>
	 */
	public function test_imaging(): array {
		$r      = $this->report();
		$result = array(
			'label'       => __( 'Image rendering is fully supported', 'ai-cake-topper' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'AI Cake Topper', 'ai-cake-topper' ),
				'color' => 'blue',
			),
			'description' => '',
			'test'        => 'aicake_imaging',
		);

		$rows = array(
			__( 'GD', 'ai-cake-topper' )              => $r['gd'] ? $r['gd_version'] : __( 'missing', 'ai-cake-topper' ),
			__( 'FreeType (text)', 'ai-cake-topper' ) => $r['freetype'] ? __( 'yes', 'ai-cake-topper' ) : __( 'MISSING', 'ai-cake-topper' ),
			__( 'PNG', 'ai-cake-topper' )             => $r['png'] ? __( 'yes', 'ai-cake-topper' ) : __( 'no', 'ai-cake-topper' ),
			__( 'WebP', 'ai-cake-topper' )            => $r['webp'] ? __( 'yes', 'ai-cake-topper' ) : __( 'no', 'ai-cake-topper' ),
			__( 'Engine in use', 'ai-cake-topper' )   => $this->engine(),
			__( 'Memory limit', 'ai-cake-topper' )    => (string) $r['memory_limit'],
		);

		$list = '';
		foreach ( $rows as $label => $value ) {
			$list .= sprintf( '<li><strong>%s:</strong> %s</li>', esc_html( (string) $label ), esc_html( (string) $value ) );
		}

		$notes = array();

		if ( ! $r['gd'] ) {
			$result['status'] = 'critical';
			$result['label']  = __( 'GD is not available — no image can be produced', 'ai-cake-topper' );
			$notes[]          = __( 'The GD extension is required. Without it the plugin cannot render anything.', 'ai-cake-topper' );
		} elseif ( ! $r['freetype'] ) {
			$result['status'] = 'critical';
			$result['label']  = __( 'GD has no FreeType support — text cannot be rendered', 'ai-cake-topper' );
			$notes[]          = __( 'The text layer draws with imagettftext(), which needs FreeType. Images without text would still work; anything with a name or greeting on it would not.', 'ai-cake-topper' );
		}

		// An A4 sheet at 300 DPI is 8.7 M pixels, roughly 35 MB per copy in memory (PLAN.md §9.2).
		if ( $r['memory_bytes'] > 0 && $r['memory_bytes'] < 256 * MB_IN_BYTES ) {
			$result['status'] = 'critical' === $result['status'] ? 'critical' : 'recommended';
			$notes[]          = __( 'A4 print files at 300 DPI need roughly 256 MB. Below that, generating a full sheet is likely to fail with an out-of-memory error.', 'ai-cake-topper' );
		}

		if ( $r['imagick'] && $r['force_gd'] ) {
			$notes[] = __( 'Imagick is installed but deliberately unused: production has GD only, so development runs on the same path the customer will.', 'ai-cake-topper' );
		}

		$result['description'] = '<ul>' . $list . '</ul>'
			. ( array() === $notes ? '' : '<p>' . implode( '</p><p>', array_map( 'esc_html', $notes ) ) . '</p>' );

		return $result;
	}

	/**
	 * Storage root exists, is writable, and ideally is not reachable over HTTP.
	 *
	 * @return array<string, mixed>
	 */
	public function test_storage(): array {
		$r      = $this->report();
		$result = array(
			'label'       => __( 'File storage is configured correctly', 'ai-cake-topper' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'AI Cake Topper', 'ai-cake-topper' ),
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p><code>%s</code></p>',
				esc_html( (string) $r['storage_dir'] )
			),
			'test'        => 'aicake_storage',
		);

		$notes = array();

		if ( ! $r['storage_writable'] ) {
			$result['status'] = 'critical';
			$result['label']  = __( 'Generated images cannot be saved', 'ai-cake-topper' );
			$notes[]          = __( 'A test write failed. In the sessions folder that means every generation is paid for and then thrown away; in the orders folder it means a paid order cannot be turned into a print file.', 'ai-cake-topper' );
			$notes[]          = sprintf(
				/* translators: %s: the storage path */
				__( 'The usual cause is ownership: if the plugin was activated — or a maintenance command run — over WP-CLI as root, the dated folders under %s belong to root while PHP runs as the web user. Making them owned by the web user fixes it.', 'ai-cake-topper' ),
				$r['storage_dir']
			);
		} elseif ( $r['storage_in_webroot'] ) {
			$result['status'] = 'recommended';
			$result['label']  = __( 'Storage sits inside the web root', 'ai-cake-topper' );
			$notes[]          = __( 'Files are protected by an .htaccess rule and unguessable names, which is adequate but not ideal. Defining AICAKE_STORAGE_DIR in wp-config.php as a path above public_html puts them out of HTTP reach entirely.', 'ai-cake-topper' );
		}

		if ( array() !== $notes ) {
			$result['description'] .= '<p>' . implode( '</p><p>', array_map( 'esc_html', $notes ) ) . '</p>';
		}

		return $result;
	}

	/**
	 * Add a full dump to Site Health → Info, which is the copyable one.
	 *
	 * @param array<string, mixed> $info Debug sections.
	 * @return array<string, mixed>
	 */
	public function add_debug_information( array $info ): array {
		$r      = $this->report();
		$fields = array();

		$booleans = array(
			'gd'               => __( 'GD', 'ai-cake-topper' ),
			'freetype'         => __( 'GD FreeType', 'ai-cake-topper' ),
			'webp'             => __( 'GD WebP', 'ai-cake-topper' ),
			'png'              => __( 'GD PNG', 'ai-cake-topper' ),
			'imagick'          => __( 'Imagick present', 'ai-cake-topper' ),
			'force_gd'         => __( 'Forcing GD', 'ai-cake-topper' ),
			'storage_writable' => __( 'Storage writable', 'ai-cake-topper' ),
			'storage_defined'  => __( 'AICAKE_STORAGE_DIR defined', 'ai-cake-topper' ),
		);

		foreach ( $booleans as $key => $label ) {
			$fields[ $key ] = array(
				'label' => $label,
				'value' => $r[ $key ] ? __( 'Yes', 'ai-cake-topper' ) : __( 'No', 'ai-cake-topper' ),
			);
		}

		$fields['gd_version']   = array(
			'label' => __( 'GD version', 'ai-cake-topper' ),
			'value' => (string) $r['gd_version'],
		);
		$fields['engine']       = array(
			'label' => __( 'Image engine', 'ai-cake-topper' ),
			'value' => $this->engine(),
		);
		$fields['memory_limit'] = array(
			'label' => __( 'Memory limit', 'ai-cake-topper' ),
			'value' => (string) $r['memory_limit'],
		);
		$fields['storage_dir']  = array(
			'label'   => __( 'Storage directory', 'ai-cake-topper' ),
			'value'   => (string) $r['storage_dir'],
			'private' => true,
		);

		// Presence only — never the key itself.
		foreach ( $r['secrets'] as $name => $present ) {
			$fields[ 'key_' . $name ] = array(
				'label' => sprintf(
					/* translators: %s: provider name, e.g. gemini */
					__( 'Key: %s', 'ai-cake-topper' ),
					$name
				),
				'value' => $present
					? __( 'defined', 'ai-cake-topper' )
					: sprintf(
						/* translators: %s: PHP constant name */
						__( 'not defined (%s)', 'ai-cake-topper' ),
						Settings::secret_constant( (string) $name )
					),
			);
		}

		$info['ai-cake-topper'] = array(
			'label'       => __( 'AI Cake Topper', 'ai-cake-topper' ),
			'description' => __( 'What this host can do, and which provider keys are configured.', 'ai-cake-topper' ),
			'fields'      => $fields,
		);

		return $info;
	}
}
