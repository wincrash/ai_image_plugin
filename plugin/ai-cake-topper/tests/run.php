<?php
/**
 * Minimal test runner.
 *
 * @package AiCake
 */

declare( strict_types=1 );

/*
 * PLAN.md §19 asks for PHPUnit. This runs the same tests with no Composer and
 * no download, which means they can actually be run right now, in the
 * container, by anyone — including a future session that has forgotten the
 * setup. The test classes are deliberately PHPUnit-shaped (test_* methods,
 * assert helpers), so adopting PHPUnit later is a mechanical change rather
 * than a rewrite.
 *
 *   docker compose exec wordpress php wp-content/plugins/ai-cake-topper/tests/run.php
 */

require_once __DIR__ . '/bootstrap.php';

/**
 * Assertions and result tracking.
 */
class TestCase {

	public static int $passed = 0;

	public static int $failed = 0;

	/**
	 * @var string[]
	 */
	public static array $failures = array();

	/**
	 * @param mixed  $expected Expected value.
	 * @param mixed  $actual   Actual value.
	 * @param string $message  What is being asserted.
	 */
	protected function assert_same( $expected, $actual, string $message ): void {
		if ( $expected === $actual ) {
			self::$passed++;

			return;
		}

		self::$failed++;
		self::$failures[] = sprintf(
			'%s: expected %s, got %s',
			$message,
			var_export( $expected, true ),
			var_export( $actual, true )
		);
	}

	/**
	 * @param bool   $condition Condition.
	 * @param string $message   What is being asserted.
	 */
	protected function assert_true( bool $condition, string $message ): void {
		$this->assert_same( true, $condition, $message );
	}

	/**
	 * @param float  $expected Expected value.
	 * @param float  $actual   Actual value.
	 * @param float  $delta    Tolerance.
	 * @param string $message  What is being asserted.
	 */
	protected function assert_close( float $expected, float $actual, float $delta, string $message ): void {
		if ( abs( $expected - $actual ) <= $delta ) {
			self::$passed++;

			return;
		}

		self::$failed++;
		self::$failures[] = sprintf( '%s: expected ~%s, got %s', $message, $expected, $actual );
	}
}

require_once __DIR__ . '/MmTest.php';
require_once __DIR__ . '/SheetLayoutTest.php';
require_once __DIR__ . '/FontCoverageTest.php';
require_once __DIR__ . '/LtNormaliserTest.php';
require_once __DIR__ . '/GdEngineTest.php';

$suites = array( new MmTest(), new SheetLayoutTest(), new FontCoverageTest(), new LtNormaliserTest(), new GdEngineTest() );

foreach ( $suites as $suite ) {
	$name = get_class( $suite );
	printf( "\n%s\n%s\n", $name, str_repeat( '-', strlen( $name ) ) );

	$before_failed = TestCase::$failed;

	foreach ( get_class_methods( $suite ) as $method ) {
		if ( 0 !== strpos( $method, 'test_' ) ) {
			continue;
		}

		$suite->$method();
		printf( "  %s\n", str_replace( '_', ' ', substr( $method, 5 ) ) );
	}

	if ( TestCase::$failed > $before_failed ) {
		printf( "  ^ %d failure(s) in this suite\n", TestCase::$failed - $before_failed );
	}
}

printf( "\n%d assertions passed, %d failed\n", TestCase::$passed, TestCase::$failed );

foreach ( TestCase::$failures as $failure ) {
	printf( "  FAIL  %s\n", $failure );
}

exit( TestCase::$failed > 0 ? 1 : 0 );
