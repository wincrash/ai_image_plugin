<?php
/**
 * D-061 — expired designs are collected, and paid ones never are.
 *
 * Run against the DEPLOYED copy:
 *
 *   wp eval-file /var/lib/aicake/retention-check.php --path=/var/www/html
 *
 * The assertion this file exists for is the second one. Everything else here is
 * tuning — a window that is a day out, a batch that is smaller than intended —
 * and would be noticed and fixed. A design belonging to an order being deleted
 * is a customer who paid and whose picture is gone, discovered when Ruslan
 * opens the order to print it and there is nothing there.
 *
 * So the ordered design is asserted from both ends: its row survives AND its
 * files survive. A sweep that deleted the files and left the row would pass a
 * row-only check and still have destroyed the order.
 *
 * @package AiCake
 */

/*
 * No `declare( strict_types=1 )`. `wp eval-file` eval()s this file, and a
 * declare must be the first statement in a *script* — inside an eval it is a
 * fatal. The other checks in this directory leave it out for the same reason.
 */

use AiCake\Domain\DesignRepository;
use AiCake\Installer;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run me through wp eval-file.\n" );
	exit( 1 );
}

$GLOBALS['aicake_pass'] = 0;
$GLOBALS['aicake_fail'] = 0;

/**
 * Assert.
 *
 * @param string $label  What is being claimed.
 * @param mixed  $expect Expected.
 * @param mixed  $actual Actual.
 */
function aicake_check( string $label, $expect, $actual ): void {
	if ( $expect === $actual ) {
		++$GLOBALS['aicake_pass'];
		printf( "  ok    %-62s %s\n", $label, var_export( $actual, true ) );

		return;
	}

	++$GLOBALS['aicake_fail'];
	printf(
		"  FAIL  %-62s expected %s, got %s\n",
		$label,
		var_export( $expect, true ),
		var_export( $actual, true )
	);
}

$plugin    = AiCake\Plugin::instance();
$designs   = $plugin->designs();
$storage   = $plugin->storage();
$settings  = $plugin->settings();
$retention = $plugin->retention();
$wpdb      = $GLOBALS['wpdb'];
$table     = Installer::table( 'designs' );

$session = 'retention-check-' . bin2hex( random_bytes( 6 ) );

/**
 * A design with real files on disk, aged to order.
 *
 * Files are real rather than mocked because "did the sweep delete the file"
 * cannot be answered against a path that was never written — the check would
 * pass against a sweep that deletes nothing at all.
 *
 * @param DesignRepository $designs  Repository.
 * @param string           $session  Session key.
 * @param int              $age_days How long ago it was last touched.
 * @param int|null         $order_id Order it belongs to, or null.
 * @return array{id:int, files:string[]}
 */
function aicake_fixture( DesignRepository $designs, string $session, int $age_days, ?int $order_id ): array {
	$storage = AiCake\Plugin::instance()->storage();

	$id = $designs->create(
		array(
			'session_key' => $session,
			'ip_hash'     => str_repeat( 'a', 64 ),
			'prompt_raw'  => 'retention fixture',
			'status'      => 'done',
		)
	);

	$public_id = (string) $designs->find( $id )['public_id'];

	$files = array();
	$set   = array();

	foreach ( array( 'file_master' => 'master.png', 'file_preview' => 'preview.webp' ) as $column => $suffix ) {
		$path = $storage->write( $storage->session_path( $public_id, $suffix ), 'not really an image, but really a file' );

		$files[]        = $path;
		$set[ $column ] = $path;
	}

	$set['order_id'] = $order_id;

	$designs->update( $id, $set );

	/*
	 * Aged with a direct write, because `DesignRepository::update()` stamps
	 * `updated_at` to now on every single call — which is precisely the
	 * mechanism that makes expiry slide, and it therefore cannot be the tool
	 * used to fabricate an old row. Going through it here silently produced
	 * three fresh designs and a sweep that correctly collected nothing.
	 */
	$stamp = gmdate( 'Y-m-d H:i:s', time() - ( $age_days * DAY_IN_SECONDS ) );

	$GLOBALS['wpdb']->update(
		Installer::table( 'designs' ),
		array(
			'created_at' => $stamp,
			'updated_at' => $stamp,
		),
		array( 'id' => $id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	return array(
		'id'    => $id,
		'files' => $files,
	);
}

/**
 * Is the row still there?
 *
 * @param int $id Design id.
 */
function aicake_row_exists( int $id ): bool {
	return null !== AiCake\Plugin::instance()->designs()->find( $id );
}

/**
 * Are all of a fixture's files still on disk?
 *
 * @param string[] $files Paths.
 */
function aicake_files_exist( array $files ): bool {
	foreach ( $files as $path ) {
		if ( '' === $path || ! file_exists( $path ) ) {
			return false;
		}
	}

	return true;
}

echo "\nRetention — D-061\n\n";

$was_days  = (int) $settings->get( 'retention_days', 14 );
$was_batch = (int) $settings->get( 'retention_batch', 20 );

$settings->update(
	array(
		'retention_days'  => 14,
		'retention_batch' => 20,
	)
);

/* ------------------------------------------------------- what it collects */

$paid    = aicake_fixture( $designs, $session, 400, 999999 );
$expired = aicake_fixture( $designs, $session, 400, null );
$fresh   = aicake_fixture( $designs, $session, 1, null );

aicake_check( 'the fixtures start on disk', true, aicake_files_exist(
	array_merge( $paid['files'], $expired['files'], $fresh['files'] )
) );

$collected = $retention->sweep();

aicake_check( 'the sweep collected exactly the one expired unpaid design', 1, $collected );

/* ------------------------------------------- the assertion that matters most */

aicake_check( 'a design belonging to an order still has its row', true, aicake_row_exists( $paid['id'] ) );
aicake_check( 'a design belonging to an order still has its files', true, aicake_files_exist( $paid['files'] ) );

/* ----------------------------------------------------- and the rest of it */

aicake_check( 'the expired unpaid row is gone', false, aicake_row_exists( $expired['id'] ) );
aicake_check( 'the expired unpaid files are gone', false, aicake_files_exist( $expired['files'] ) );

aicake_check( 'a design inside the window keeps its row', true, aicake_row_exists( $fresh['id'] ) );
aicake_check( 'a design inside the window keeps its files', true, aicake_files_exist( $fresh['files'] ) );

/* --------------------------------------------------------- expiry slides */

/*
 * A design older than the window but touched recently is NOT a candidate.
 * Ruslan asked for sliding expiry; without it, a customer who comes back to a
 * design they made three weeks ago loses it while they are looking at it.
 */
$revisited = aicake_fixture( $designs, $session, 400, null );

// Any ordinary update touches it — that is the whole mechanism.
$designs->update( $revisited['id'], array( 'status' => 'done' ) );

$retention->sweep();

aicake_check( 'an old design touched today survives — expiry slides', true, aicake_row_exists( $revisited['id'] ) );

/* ------------------------------------------------------------- the batch */

/*
 * The batch bound is what keeps this off the critical path. Without it, the
 * first sweep after a quiet month is one customer's generation paying for a
 * month of accumulated deletion.
 */
$settings->update( array( 'retention_batch' => 2 ) );

$many = array();

for ( $i = 0; $i < 5; $i++ ) {
	$many[] = aicake_fixture( $designs, $session, 400, null );
}

aicake_check( 'the batch is honoured, not exceeded', 2, $retention->sweep() );

/* ------------------------------------------------------------- switch off */

$settings->update( array( 'retention_days' => 0 ) );

aicake_check( 'zero days switches collection off entirely', 0, $retention->sweep() );

/* --------------------------------------------------------------- cleanup */

$settings->update(
	array(
		'retention_days'  => $was_days,
		'retention_batch' => $was_batch,
	)
);

$leftovers = array_merge( array( $paid, $fresh, $revisited ), $many );

foreach ( $leftovers as $row ) {
	foreach ( $row['files'] as $path ) {
		if ( '' !== $path ) {
			$storage->delete( $path );
		}
	}

	$wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
}

printf( "\n%d passed, %d failed\n", $GLOBALS['aicake_pass'], $GLOBALS['aicake_fail'] );

exit( $GLOBALS['aicake_fail'] > 0 ? 1 : 0 );
