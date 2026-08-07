<?php
/**
 * D-050: API keys in the database, encrypted, with constants still winning.
 *
 * Run inside the container, against the deployed copy:
 *
 *   docker compose exec -u www-data wordpress \
 *     wp eval-file /var/lib/aicake/settings-check.php --path=/var/www/html
 *
 * No network and no money — this is storage and resolution order only.
 *
 * It writes the real `aicake_secrets` option, because that is what the code
 * reads, and it snapshots and restores it: a shop that has never stored a key
 * must get the option back **absent**, not as an empty array.
 *
 * The testbed's own state is what makes two of these assertions possible, and
 * it is worth knowing which:
 *
 *   - `AICAKE_FAL_KEY` is defined and non-empty (docker-compose reads .env), so
 *     fal is the subject for "a constant wins over anything stored".
 *   - `AICAKE_OPENAI_KEY` is defined but **empty**, which the resolver treats as
 *     absent, so openai is the subject for the whole stored-key path.
 *
 * **Known gap, stated rather than faked.** `AICAKE_IP_SALT` is defined on the
 * testbed, so the derived-salt branch of `Settings::secret()` cannot be reached
 * from here. What is asserted is that the salt is never empty — the failure
 * that branch exists to prevent.
 *
 * @package AiCake
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.NamingConventions.ValidVariableName

use AiCake\Support\SecretStore;
use AiCake\Support\Settings;

$GLOBALS['aicake_pass'] = 0;
$GLOBALS['aicake_fail'] = 0;

/**
 * @param string $label  What is being asserted.
 * @param mixed  $expect Expected value.
 * @param mixed  $actual Actual value.
 */
function aicake_check( string $label, $expect, $actual ): void {
	global $aicake_pass, $aicake_fail;

	if ( $expect === $actual ) {
		printf( "  ok    %-58s %s\n", $label, is_scalar( $actual ) ? (string) $actual : gettype( $actual ) );
		++$aicake_pass;

		return;
	}

	printf( "  FAIL  %-58s expected %s, got %s\n", $label, var_export( $expect, true ), var_export( $actual, true ) );
	++$aicake_fail;
}

echo "\nD-050 — encrypted secret store\n";

/* ---------------------------------------------------------------- snapshot */

$had_none = ( null === get_option( SecretStore::OPTION, null ) );
$snapshot = get_option( SecretStore::OPTION, null );

/* ------------------------------------------------------------ the host can */

echo "\n== host\n";

aicake_check( 'a cipher is available', true, SecretStore::available() );

/*
 * Not "the cipher is sodium". Production has no sodium (host-check, 2026-08-07)
 * so the live shop writes with openssl, and asserting the testbed's preference
 * would pin the check to the one host that does not matter. What is asserted is
 * that the testbed runs *production's* cipher — D-052, the same rule as
 * AICAKE_FORCE_GD.
 */
aicake_check( 'the testbed is forced onto production\'s cipher', 'openssl/aes-256-gcm', SecretStore::cipher() );
printf( "  note  sodium present on this host: %s\n", function_exists( 'sodium_crypto_secretbox' ) ? 'yes' : 'no' );

/*
 * Not an assertion about the testbed so much as a reminder: if this is false on
 * production, the encryption key is in the same database as the ciphertext and
 * the screen says so out loud.
 */
printf( "  note  encryption key from wp-config.php: %s\n", SecretStore::key_is_in_wp_config() ? 'yes' : 'NO' );

/* --------------------------------------------------------- the stored path */

echo "\n== a key entered in the screen\n";

$settings = new Settings();
$store    = $settings->secrets();
$secret   = 'sk-test-' . wp_generate_password( 32, false );

aicake_check( 'unset before anything is stored', 'unset', $settings->secret_source( 'openai' ) );
aicake_check( 'and reads as empty', '', $settings->secret( 'openai' ) );

$store->set( 'openai', $secret );

aicake_check( 'round trips through Settings::secret()', $secret, $settings->secret( 'openai' ) );
aicake_check( 'source is now stored', 'stored', $settings->secret_source( 'openai' ) );

/*
 * The assertion the whole class exists for. Everything else about storage also
 * passes for a plugin that writes the key in plaintext.
 */
$raw = get_option( SecretStore::OPTION, array() );

aicake_check(
	'the plaintext key is NOT in the option',
	false,
	str_contains( wp_json_encode( $raw ) ?: '', $secret )
);

aicake_check( 'the stored value declares its cipher', true, str_starts_with( (string) ( $raw['openai'] ?? '' ), 'o1:' ) );

/*
 * A fresh nonce per write. Without it, two shops with the same key produce the
 * same ciphertext and a leaked pair identifies both.
 */
$first = (string) $raw['openai'];
$store->set( 'openai', $secret );
$second = (string) ( get_option( SecretStore::OPTION, array() )['openai'] ?? '' );

aicake_check( 'the same key encrypts differently each time', false, $first === $second );
aicake_check( 'and still decrypts to the same thing', $secret, ( new Settings() )->secret( 'openai' ) );

/* ------------------------------------------------------- autoload and size */

echo "\n== how it is stored\n";

global $wpdb;

$autoload = $wpdb->get_var(
	$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", SecretStore::OPTION )
);

/*
 * Not autoloaded: this is read on the few requests that call a provider, not on
 * every page of a shop with 2500 products.
 */
aicake_check( 'the option is not autoloaded', false, in_array( $autoload, array( 'yes', 'on', 'auto' ), true ) );

/* ------------------------------------------------------ a constant still wins */

echo "\n== a constant still wins\n";

aicake_check( 'AICAKE_FAL_KEY is defined on the testbed', true, defined( 'AICAKE_FAL_KEY' ) && '' !== AICAKE_FAL_KEY );
aicake_check( 'fal reports itself as constant-backed', 'constant', $settings->secret_source( 'fal' ) );

$store->set( 'fal', 'stored-value-that-must-lose' );

// Compared as a boolean, not as values: this helper prints what it got, and
// printing the shop's real fal key into a terminal would be a poor way to
// verify that keys stay out of sight.
aicake_check( 'a stored value does not override the constant', true, AICAKE_FAL_KEY === ( new Settings() )->secret( 'fal' ) );
aicake_check( 'and the source still reads constant', 'constant', ( new Settings() )->secret_source( 'fal' ) );

$store->forget( 'fal' );

/* ----------------------------------------------------- the other cipher */

echo "\n== a value written by the other cipher still opens\n";

/*
 * Writing is forced to openssl, so nothing in this run exercises the sodium
 * read path — and a shop that stored keys on a host with sodium and later moved
 * to one without (or the reverse) depends on it entirely. The value is built
 * here with sodium directly, using the same key derivation the store uses, and
 * handed to the store to open.
 *
 * This is also why key() is plain SHA-256 on every host: if derivation varied
 * with the extensions present, this test could not be written at all.
 */
if ( function_exists( 'sodium_crypto_secretbox' ) ) {
	$key   = hash( 'sha256', 'aicake-secret-v1|' . wp_salt( 'secure_auth' ), true );
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$other = 'sk-written-by-sodium';

	$cross           = get_option( SecretStore::OPTION, array() );
	$cross['openai'] = 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $other, $nonce, $key ) );
	update_option( SecretStore::OPTION, $cross, false );

	aicake_check( 'a sodium-written value decrypts', $other, ( new Settings() )->secret( 'openai' ) );
} else {
	echo "  SKIP  no sodium on this host — production is like this too\n";
}

/* ------------------------------------------------------------- unreadable */

echo "\n== ciphertext that will not decrypt\n";

/*
 * What a site move looks like: the database arrived, wp-config.php was
 * regenerated, and the salts the key is derived from are new.
 */
$broken           = get_option( SecretStore::OPTION, array() );
$broken['openai'] = 's1:' . base64_encode( random_bytes( 60 ) );
update_option( SecretStore::OPTION, $broken, false );

$fresh = new Settings();

aicake_check( 'an undecryptable key reads as empty', '', $fresh->secret( 'openai' ) );
aicake_check( 'and is reported, not silently dropped', array( 'openai' ), $fresh->secrets()->unreadable() );

/*
 * The one that matters most. Someone editing wp_options by hand, or a restore
 * from a pre-D-050 backup, can leave a bare key in there. Honouring it would
 * mean the plugin reads plaintext secrets from the database after all — the
 * exact thing D-002 forbade and D-050 promised not to reintroduce.
 */
$plain           = get_option( SecretStore::OPTION, array() );
$plain['openai'] = 'sk-a-bare-key-someone-pasted-in';
update_option( SecretStore::OPTION, $plain, false );

$fresh = new Settings();

aicake_check( 'an unprefixed plaintext value is NOT honoured', '', $fresh->secret( 'openai' ) );

/* ----------------------------------------------------------------- removal */

echo "\n== removal\n";

$store = ( new Settings() )->secrets();
$store->set( 'openai', $secret );
$store->forget( 'openai' );

aicake_check( 'forgetting a key clears it', '', ( new Settings() )->secret( 'openai' ) );
aicake_check( 'the option is deleted once empty', null, get_option( SecretStore::OPTION, null ) );

/* ------------------------------------------------------------ redaction */

echo "\n== the logger still cannot leak a stored key\n";

$store = ( new Settings() )->secrets();
$store->set( 'openai', $secret );

$settings = new Settings();

aicake_check(
	'configured_secrets() includes the stored key',
	true,
	in_array( $secret, array_values( $settings->configured_secrets() ), true )
);

$store->forget( 'openai' );

/* ---------------------------------------------------------------- ip salt */

echo "\n== the IP salt is never empty\n";

aicake_check( 'ip_salt resolves to something', false, '' === ( new Settings() )->secret( 'ip_salt' ) );

/* -------------------------------------------------- counters and the reset */

echo "\n== generation counters and the reset button\n";

$user = get_user_by( 'login', 'testuser' );

if ( ! $user ) {
	echo "  SKIP  no testuser account on this install\n";
} else {
	$settings = new Settings();
	$limiter  = new \AiCake\Throttle\RateLimiter( $settings, new \AiCake\Throttle\IdentityResolver( $settings ) );

	$epoch_had  = $settings->get( 'throttle_epoch', '' );
	$meta_had   = (string) get_user_meta( $user->ID, \AiCake\Throttle\RateLimiter::USER_EPOCH_META, true );
	$designs    = \AiCake\Installer::table( 'designs' );
	$marker     = 'settings-check-' . wp_generate_password( 8, false );
	$insert_row = static function () use ( $wpdb, $designs, $user, $marker ): void {
		$wpdb->insert(
			$designs,
			array(
				'public_id'   => substr( md5( uniqid( '', true ) ), 0, 32 ),
				'session_key' => 'settings-check',
				'ip_hash'     => str_repeat( '0', 64 ),
				'user_id'     => $user->ID,
				'prompt_raw'  => $marker,
				'status'      => 'done',
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	};

	// Clean slate, so a previous run or a day of browser testing cannot decide
	// the result.
	delete_user_meta( $user->ID, \AiCake\Throttle\RateLimiter::USER_EPOCH_META );
	$settings->update( array( 'throttle_epoch' => '' ) );

	$before = $limiter->used_by( $user->ID );

	$insert_row();
	$insert_row();
	$insert_row();

	aicake_check( 'three generations count against the allowance', $before + 3, $limiter->used_by( $user->ID ) );

	$limiter->reset_user( $user->ID );

	aicake_check( 'resetting one customer clears their count', 0, $limiter->used_by( $user->ID ) );

	/*
	 * The reset forgives what happened before it, and nothing after. A reset
	 * that permanently exempted a customer would be a very expensive way to
	 * answer one support email.
	 */
	sleep( 1 );
	$insert_row();

	aicake_check( 'and the next generation counts again', 1, $limiter->used_by( $user->ID ) );

	/*
	 * The displayed totals are history and must NOT move — the screen says so,
	 * and if they did move the shop would have no way of knowing what it spent.
	 */
	$total_before = $limiter->generated_since( $limiter->day_start() );

	$limiter->reset_all();

	aicake_check( 'resetting everybody does not rewrite history', $total_before, $limiter->generated_since( $limiter->day_start() ) );
	aicake_check( 'but it does clear the allowance count', 0, $limiter->used_by( $user->ID ) );
	aicake_check( 'and the reset time is recorded', false, '' === $limiter->last_reset() );

	/*
	 * The assertions above all go through used_by(), which is what the admin
	 * screen displays. That is not the code that stops a customer generating —
	 * used() is, and it answers for whoever is making the request. Asserting
	 * only the screen would pass for a reset that visibly worked and changed
	 * nothing for the customer who rang up about it.
	 *
	 * So: become that customer, and ask the limiter the question it is actually
	 * asked during a generation.
	 */
	$was_current = get_current_user_id();
	wp_set_current_user( $user->ID );

	$live = new \AiCake\Throttle\RateLimiter( new Settings(), new \AiCake\Throttle\IdentityResolver( new Settings() ) );

	aicake_check( 'the customer\'s own count is cleared too', 0, $live->used() );

	sleep( 1 );
	$insert_row();

	aicake_check( 'and their next generation counts against them', 1, $live->used() );
	aicake_check( 'remaining() agrees with the allowance', $live->allowance() - 1, $live->remaining() );

	wp_set_current_user( $was_current );

	// Nothing here may survive the run: these are fake rows in the real table.
	$wpdb->delete( $designs, array( 'prompt_raw' => $marker ) );

	if ( '' === $meta_had ) {
		delete_user_meta( $user->ID, \AiCake\Throttle\RateLimiter::USER_EPOCH_META );
	} else {
		update_user_meta( $user->ID, \AiCake\Throttle\RateLimiter::USER_EPOCH_META, $meta_had );
	}

	$settings->update( array( 'throttle_epoch' => $epoch_had ) );

	aicake_check( 'the fake design rows are gone', 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$designs} WHERE prompt_raw = %s", $marker ) ) );
	aicake_check( 'the shop\'s own reset time is put back', $epoch_had, ( new Settings() )->get( 'throttle_epoch', '' ) );
}

/* ----------------------------------------------------------------- restore */

echo "\n== restore\n";

if ( $had_none ) {
	delete_option( SecretStore::OPTION );
} else {
	update_option( SecretStore::OPTION, $snapshot, false );
}

aicake_check( 'the shop\'s own store is put back', $snapshot, get_option( SecretStore::OPTION, null ) );

printf( "\n%d passed, %d failed\n", $GLOBALS['aicake_pass'], $GLOBALS['aicake_fail'] );

if ( $GLOBALS['aicake_fail'] > 0 ) {
	exit( 1 );
}
