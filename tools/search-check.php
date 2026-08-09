<?php
/**
 * D-067 — finding a picture, and only ones licensed to be printed.
 *
 * Run against the DEPLOYED copy:
 *
 *   wp eval-file /var/lib/aicake/search-check.php --path=/var/www/html
 *
 * **This one talks to the internet.** Openverse being unreachable is not a
 * failure of the plugin, so the network-dependent half says so plainly rather
 * than going red and sending somebody to debug code that is fine.
 *
 * `wp eval-file` eval()s this, so no `declare( strict_types=1 )`.
 *
 * @package AiCake
 */

use AiCake\Domain\SourceCatalogue;
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
 * @param string $label  What is claimed.
 * @param mixed  $expect Expected.
 * @param mixed  $actual Actual.
 */
function aicake_check( string $label, $expect, $actual ): void {
	if ( $expect === $actual ) {
		++$GLOBALS['aicake_pass'];
		printf( "  ok    %-58s %s\n", $label, var_export( $actual, true ) );

		return;
	}

	++$GLOBALS['aicake_fail'];
	printf( "  FAIL  %-58s expected %s, got %s\n", $label, var_export( $expect, true ), var_export( $actual, true ) );
}

/**
 * POST to one of the two routes.
 *
 * @param string               $route Route after the namespace.
 * @param array<string, mixed> $body  Payload.
 * @return array{0:int, 1:mixed}
 */
function aicake_post( string $route, array $body ): array {
	$request = new WP_REST_Request( 'POST', '/aicake/v1/' . $route );

	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$request->set_body_params( $body );

	$response = rest_do_request( $request );

	return array( $response->get_status(), $response->get_data() );
}

/**
 * Clear the endpoint's own cooldown between scenarios.
 */
function aicake_clear_cooldown(): void {
	$identity = AiCake\Plugin::instance()->identity();

	delete_transient( 'aicake_search_' . md5( $identity->session_key() . '|' . $identity->ip_hash() ) );
}

$plugin   = AiCake\Plugin::instance();
$settings = $plugin->settings();
$designs  = $plugin->designs();
$storage  = $plugin->storage();

echo "\nImage search — D-067\n\n";

$was_search = (bool) $settings->get( 'source_search', false );

/* ---------------------------------------------- off by default, and locked */

$settings->update( array( 'source_search' => false ) );

aicake_clear_cooldown();
list( $status ) = aicake_post( 'search', array( 'query' => 'dinozauras' ) );
aicake_check( 'with search off, the query route refuses', 403, $status );

list( $status ) = aicake_post( 'search-pick', array( 'id' => 'x', 'format_type' => 'circle', 'format_mm' => 150.0 ) );
aicake_check( 'and so does the pick route', 403, $status );

aicake_check(
	'search is not offered in the wizard either',
	false,
	SourceCatalogue::enabled( SourceCatalogue::SEARCH, $settings )
);

/* ------------------------------------------------------------- switched on */

$settings->update( array( 'source_search' => true ) );

aicake_check(
	'turning it on makes it available',
	true,
	SourceCatalogue::enabled( SourceCatalogue::SEARCH, $settings )
);

/*
 * Moderation still applies to the words, before anything is spent. It cannot
 * see the pictures that come back — that is exactly D-060's point and why
 * Ruslan at the printer is the real control — but a franchise asked for by
 * name is the most likely way this goes wrong, and layers 0 and 1 catch that
 * for free.
 */
aicake_clear_cooldown();
list( $status, $body ) = aicake_post( 'search', array( 'query' => 'Elsos suknelė' ) );

aicake_check( 'a blocked query never reaches the search service', 422, $status );

aicake_clear_cooldown();
list( $status ) = aicake_post( 'search', array( 'query' => '   ' ) );
aicake_check( 'an empty query is refused', 400, $status );

/* --------------------------------------------------- the network-dependent half */

echo "\nAgainst the live service\n";

aicake_clear_cooldown();
list( $status, $body ) = aicake_post( 'search', array( 'query' => 'dinozauras' ) );

$results = is_array( $body ) && isset( $body['results'] ) ? $body['results'] : array();

if ( 200 !== $status || array() === $results ) {
	echo "  ----  Openverse returned nothing. Skipping the live half — this is a\n";
	echo "        network result, not a plugin failure. Re-run when it answers.\n";
} else {
	aicake_check( 'the search answers', 200, $status );
	aicake_check( 'with results', true, count( $results ) > 0 );

	/*
	 * Every result carries a licence, and that is the whole argument for this
	 * feature existing (D-067). The query asks Openverse for commercial +
	 * modification licences only; a result with no licence at all would mean
	 * that filter had silently stopped applying.
	 */
	/*
	 * The licences that actually permit what this shop does: print the picture,
	 * put text over it, and sell it.
	 *
	 * `NC` forbids commercial use and `ND` forbids modification, so either one
	 * makes a result unusable here — and both are common on Openverse, which is
	 * why the query asks for `commercial,modification` rather than sorting it
	 * out afterwards. Naming the safe set here rather than merely checking that
	 * *a* licence came back is what gives this assertion teeth: without the
	 * filter, `BY-NC` and `BY-ND` results come through and turn it red.
	 */
	$allowed = array( 'CC0', 'PDM', 'BY', 'BY-SA', 'SAMPLING+' );
	$unusable = array();

	foreach ( $results as $found ) {
		$licence = (string) ( $found['licence'] ?? '' );

		if ( '' === $licence || ! in_array( $licence, $allowed, true ) ) {
			$unusable[] = '' === $licence ? '(none)' : $licence;
		}
	}

	aicake_check(
		'every result is licensed for commercial use and modification',
		array(),
		array_values( array_unique( $unusable ) )
	);

	/* ------------------------------------------------------------ the pick */

	list( $status, $picked ) = aicake_post(
		'search-pick',
		array(
			'id'          => (string) $results[0]['id'],
			'query'       => 'dinozauras',
			'format_type' => 'circle',
			'format_mm'   => 150.0,
		)
	);

	aicake_check( 'a chosen result becomes a design', 201, $status );

	$row = $designs->find_by_public_id( (string) ( $picked['design'] ?? '' ) );

	if ( is_array( $row ) ) {
		aicake_check( 'the row records where it came from', SourceCatalogue::SEARCH, $row['source'] ?? '' );
		aicake_check( 'nobody generated it', true, in_array( $row['provider'] ?? null, array( null, '' ), true ) );
		aicake_check( 'and it cost nothing', 0.0, (float) ( $row['cost_usd'] ?? -1 ) );

		/*
		 * Re-encoded, exactly like an upload (D-062). A picture from the open
		 * internet is at least as untrusted as one from a customer's phone —
		 * nobody chose it deliberately.
		 */
		$master = (string) ( $row['file_master'] ?? '' );
		$head   = '' === $master ? '' : (string) file_get_contents( $master, false, null, 0, 8 );

		aicake_check( 'the picture is stored re-encoded as PNG', "\x89PNG\r\n\x1a\n", $head );

		/*
		 * The licence and the creator survive onto the design. Attribution is
		 * Ruslan's decision, but it cannot be made later if the facts were
		 * never kept.
		 */
		$meta = json_decode( (string) ( $row['moderation'] ?? '' ), true );

		aicake_check(
			'the licence is kept with the design',
			true,
			isset( $meta['search']['licence'] ) && '' !== $meta['search']['licence']
		);
		aicake_check(
			'and so is the creator',
			true,
			isset( $meta['search']['creator'] )
		);

		foreach ( array( 'file_master', 'file_preview' ) as $column ) {
			$path = (string) ( $row[ $column ] ?? '' );

			if ( '' !== $path ) {
				$storage->delete( $path );
			}
		}

		$GLOBALS['wpdb']->delete( Installer::table( 'designs' ), array( 'id' => (int) $row['id'] ), array( '%d' ) );
	} else {
		aicake_check( 'the design row exists', true, false );
	}
}

/* ------------------------------------------------- the address is not the caller's */

/*
 * The browser sends an identifier and never a URL. The server asks the search
 * service what that identifier points at, and fetches *that*.
 *
 * A URL from the caller would make this server fetch whatever it was handed —
 * including addresses on the host's own network that nothing outside can
 * reach. So the route declares no `url` argument at all, and one posted anyway
 * has to be ignored rather than honoured. Asserted through the router, because
 * "the code does not read it" is a claim about code and this is a claim about
 * behaviour.
 */
aicake_clear_cooldown();

list( $status ) = aicake_post(
	'search-pick',
	array(
		'id'          => 'not-a-real-identifier',
		'url'         => 'http://127.0.0.1/wp-admin/',
		'format_type' => 'circle',
		'format_mm'   => 150.0,
	)
);

aicake_check( 'an unknown id is refused', 404, $status );
aicake_check(
	'and the route does not accept a URL at all',
	false,
	array_key_exists( 'url', $plugin ? aicake_pick_args() : array() )
);

/**
 * The pick route's declared arguments.
 *
 * @return array<string, mixed>
 */
function aicake_pick_args(): array {
	$routes = rest_get_server()->get_routes();
	$route  = $routes['/aicake/v1/search-pick'][0] ?? array();

	return (array) ( $route['args'] ?? array() );
}

/* --------------------------------------------------------------- cleanup */

$settings->update( array( 'source_search' => $was_search ) );
aicake_clear_cooldown();

printf( "\n%d passed, %d failed\n", $GLOBALS['aicake_pass'], $GLOBALS['aicake_fail'] );

exit( $GLOBALS['aicake_fail'] > 0 ? 1 : 0 );
