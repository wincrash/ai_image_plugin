<?php
/**
 * Getting a queued job to actually run.
 *
 * @package AiCake
 */

declare( strict_types=1 );

namespace AiCake\Queue;

use AiCake\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Layer 1 of PLAN.md §6.2: fire a non-blocking loopback request so the work
 * happens in a *different* PHP worker, and the customer's request returns in
 * about 100 ms.
 *
 * We cannot install a worker process — production is plain WordPress on shared
 * hosting — so this is the closest thing available. Some hosts block loopback
 * entirely, which is why layers 2 and 3 exist and why this class also
 * self-tests and records the answer.
 */
class Dispatcher {

	public const RUN_ACTION = 'aicake_run_job';

	public const TEST_ACTION = 'aicake_loopback_test';

	public const LOOPBACK_OPTION = 'aicake_loopback_works';

	private const TEST_REPLY = 'aicake-loopback-ok';

	private Logger $logger;

	/**
	 * @param Logger $logger Logging.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Ask another worker to run this job. Returns immediately.
	 *
	 * @param int $job_id Job to run.
	 */
	public function dispatch( int $job_id ): void {
		/*
		 * On a host where the self-test already established that loopback does
		 * not work, trying anyway costs a connect timeout on every single
		 * generation request and changes nothing — the polling request picks
		 * the job up either way. Skip straight to layer 2.
		 */
		if ( ! $this->loopback_works() ) {
			return;
		}

		$sent = $this->spawn(
			array(
				'action' => self::RUN_ACTION,
				'job'    => $job_id,
				'token'  => $this->token( $job_id ),
			)
		);

		if ( $sent ) {
			return;
		}

		/*
		 * No HTTP retry here on purpose. There are already two layers behind
		 * this one, and wp_remote_post() with a sub-second timeout costs a
		 * full second for the reasons spawn() documents. Paying that to
		 * duplicate a mechanism that is about to run anyway is a bad trade.
		 */
		$this->logger->warning(
			'Job dispatch could not reach the runner; the polling request will run it instead.',
			array( 'job' => $job_id )
		);
	}

	/**
	 * Fire a request and hang up without reading the reply.
	 *
	 * This exists because `wp_remote_post( ..., [ 'blocking' => false,
	 * 'timeout' => 0.01 ] )` — the idiom PLAN.md §6.2 describes, and the one
	 * WordPress core itself uses in spawn_cron() — **does not return
	 * immediately**. WordPress passes the timeout to cURL as a whole number of
	 * seconds, so anything under one second becomes one second, and the call
	 * blocks for that long whether or not the target is reachable. Measured on
	 * the testbed: 1002 ms to a live address, 1002 ms to an unroutable one.
	 *
	 * One second of a worker on every generation request is not acceptable
	 * when the whole site runs on four to eight of them. Writing the request
	 * to a socket and closing it returns in single-digit milliseconds and the
	 * server still processes it, because the runner calls ignore_user_abort().
	 *
	 * @param array<string, mixed> $body Form fields.
	 * @return bool Whether the request was written.
	 */
	private function spawn( array $body ): bool {
		$parts = wp_parse_url( $this->runner_url() );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		$secure = isset( $parts['scheme'] ) && 'https' === $parts['scheme'];
		$host   = (string) $parts['host'];
		$port   = (int) ( $parts['port'] ?? ( $secure ? 443 : 80 ) );
		$path   = (string) ( $parts['path'] ?? '/' );

		if ( ! empty( $parts['query'] ) ) {
			$path .= '?' . $parts['query'];
		}

		$context = stream_context_create(
			array(
				'ssl' => array(
					// We are talking to ourselves; certificate validation here
					// only breaks hosts with an internal-name certificate.
					'verify_peer'      => false,
					'verify_peer_name' => false,
				),
			)
		);

		$errno   = 0;
		$errstr  = '';
		$address = ( $secure ? 'ssl://' : 'tcp://' ) . $host . ':' . $port;

		/*
		 * One second to connect. This is a connection to ourselves: it either
		 * completes in microseconds or it is never going to, and every extra
		 * second of patience is a second of a customer-facing worker.
		 */
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$socket = @stream_socket_client( $address, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $context );

		if ( false === $socket ) {
			$this->logger->debug(
				'Could not open a socket for job dispatch.',
				array(
					'address' => $address,
					'error'   => $errstr,
				)
			);

			return false;
		}

		$payload = http_build_query( $body );

		$request = 'POST ' . $path . " HTTP/1.1\r\n"
			. 'Host: ' . $host . ( 80 === $port || 443 === $port ? '' : ':' . $port ) . "\r\n"
			. "Content-Type: application/x-www-form-urlencoded\r\n"
			. 'Content-Length: ' . strlen( $payload ) . "\r\n"
			. "Connection: Close\r\n"
			. 'User-Agent: ai-cake-topper/' . AICAKE_VERSION . "\r\n"
			. "\r\n"
			. $payload;

		stream_set_timeout( $socket, 2 );
		$written = fwrite( $socket, $request );

		// Deliberately no read. The reply is of no interest and waiting for it
		// is the entire thing being avoided.
		fclose( $socket );

		return false !== $written && strlen( $request ) === $written;
	}

	/**
	 * Where to send loopback requests.
	 *
	 * Normally admin_url(), but that is the *public* address, and a server
	 * cannot always reach itself there. Behind a reverse proxy, with
	 * split-horizon DNS, or inside a container whose published address is on a
	 * network it has no route back through, `admin_url()` times out even
	 * though the host is perfectly capable of loopback. The testbed is exactly
	 * this case: WP_HOME is the Docker host's Tailscale address, which the
	 * container cannot reach.
	 *
	 * Overriding this with an internal address is usually the whole fix, so it
	 * is worth having rather than falling back to the slower path forever:
	 *
	 *   define( 'AICAKE_LOOPBACK_URL', 'http://127.0.0.1/wp-admin/admin-post.php' );
	 */
	public function runner_url(): string {
		$url = admin_url( 'admin-post.php' );

		if ( defined( 'AICAKE_LOOPBACK_URL' ) && '' !== (string) constant( 'AICAKE_LOOPBACK_URL' ) ) {
			$url = (string) constant( 'AICAKE_LOOPBACK_URL' );
		}

		/**
		 * Filters the URL used for loopback dispatch.
		 *
		 * @param string $url Absolute URL to admin-post.php.
		 */
		return (string) apply_filters( 'aicake_loopback_url', $url );
	}

	/**
	 * A token proving a run request came from us.
	 *
	 * The runner endpoint has to be reachable without a login, because a
	 * loopback request carries no session. An HMAC over the job id keeps it
	 * from being a free "spend money" button. Even a forged call is bounded:
	 * the atomic claim means it can only run a job that was going to run
	 * anyway, exactly once.
	 *
	 * @param int $job_id Job id.
	 */
	public function token( int $job_id ): string {
		return hash_hmac( 'sha256', 'aicake-job|' . $job_id, wp_salt( 'auth' ) );
	}

	/**
	 * Constant-time token check.
	 *
	 * @param int    $job_id Job id.
	 * @param string $token  Supplied token.
	 */
	public function verify( int $job_id, string $token ): bool {
		return hash_equals( $this->token( $job_id ), $token );
	}

	/**
	 * Whether loopback is known to work on this host.
	 *
	 * Defaults to true when never tested: assuming it works and being wrong
	 * costs one slow poll, whereas assuming it is broken and being wrong makes
	 * every generation occupy a customer-facing worker.
	 */
	public function loopback_works(): bool {
		$stored = get_option( self::LOOPBACK_OPTION, null );

		if ( null === $stored || ! is_array( $stored ) ) {
			return true;
		}

		return ! empty( $stored['works'] );
	}

	/**
	 * When the loopback test last ran, as a GMT datetime, or '' if never.
	 */
	public function last_tested(): string {
		$stored = get_option( self::LOOPBACK_OPTION, null );

		return is_array( $stored ) ? (string) ( $stored['tested_at'] ?? '' ) : '';
	}

	/**
	 * Actually try a loopback request and remember the answer.
	 *
	 * Blocking, unlike dispatch() — the point is to find out.
	 */
	public function test_loopback(): bool {
		/*
		 * Tests the spawn path, not merely reachability. A blocking request
		 * proves the endpoint answers; it does not prove that a socket written
		 * and immediately closed still gets processed, which is what dispatch()
		 * actually does. Those are different questions and only the second one
		 * matters.
		 *
		 * So: spawn a probe, then watch for the side effect it leaves behind.
		 */
		$probe = bin2hex( random_bytes( 8 ) );
		$sent  = $this->spawn(
			array(
				'action' => self::TEST_ACTION,
				'probe'  => $probe,
				'token'  => $this->token( 0 ),
			)
		);

		$works  = false;
		$detail = $sent ? 'no response within the deadline' : 'could not open a socket';

		if ( $sent ) {
			$deadline = microtime( true ) + 5;

			while ( microtime( true ) < $deadline ) {
				usleep( 200000 );

				if ( self::TEST_REPLY === get_transient( 'aicake_probe_' . $probe ) ) {
					$works  = true;
					$detail = 'probe answered';
					delete_transient( 'aicake_probe_' . $probe );
					break;
				}
			}
		}

		update_option(
			self::LOOPBACK_OPTION,
			array(
				'works'     => $works,
				'tested_at' => gmdate( 'Y-m-d H:i:s' ),
				'detail'    => $detail,
			),
			false
		);

		$this->logger->info(
			$works
				? 'Loopback works; jobs will run in a separate worker.'
				: 'Loopback is blocked on this host; falling back to poll-triggered execution.',
			array( 'detail' => $detail )
		);

		return $works;
	}

	/**
	 * Answer the self-test.
	 */
	public function handle_test(): void {
		// The spawning request has already hung up, so the reply body is not
		// what proves anything — the transient is. Leave the marker where the
		// caller is watching for it.
		ignore_user_abort( true );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- a loopback request carries no nonce; the probe value is opaque and sets only a short-lived marker.
		$probe = isset( $_POST['probe'] ) ? sanitize_key( wp_unslash( $_POST['probe'] ) ) : '';

		if ( '' !== $probe ) {
			set_transient( 'aicake_probe_' . $probe, self::TEST_REPLY, MINUTE_IN_SECONDS );
		}

		echo esc_html( self::TEST_REPLY );
		exit;
	}
}
