<?php

namespace WPCheckpoint\Tests\Unit\Support;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The first unit of a tick (or of a bounded call) always runs, whatever the
 * budget says: a loop that checks its budget before doing anything makes no
 * progress on a host where something before it used the time up, and the
 * job is then failed for making no progress, or a reclaim never gets
 * anywhere. Three fixes in a row brought that back (the last one: the time
 * bound of the temporary table reclaim).
 *
 * So every file in src/ that loops on a budget (asks should_stop() or
 * remaining_seconds(), or compares the time since it started with a
 * bound; comments do not count) must be listed here with the test that runs
 * it with no time left and sees it move on, and that test must name the
 * file's class. A new such file without an entry fails, and so does an
 * entry whose file no longer loops on a budget, whose test is gone or does
 * not name the class.
 *
 * What this guards is a budget check put before the first unit. Coverage is
 * per file, not per loop: a file with several loops is listed once, and its
 * test reaches the loops its fixture reaches. A new loop in a listed file
 * needs its own look.
 *
 * EXEMPT holds files that stop before a first unit on purpose, each with the
 * reason; an entry there is a decision, not a gap.
 */
final class FirstUnitRuleTest extends TestCase {

	/**
	 * What counts as looping on a budget.
	 */
	const BUDGET_LOOP = '/->should_stop\(|->remaining_seconds\(|\)\s*-\s*\$started\s*[<>]|microtime\(\s*true\s*\)\s*-\s*(?:\$\w*start\w*|[\w:>-]*started_at\(\))\s*[<>]/';

	/**
	 * Files that stop before a first unit on purpose: file => why.
	 */
	const EXEMPT = array();

	/**
	 * File => the test (file and method) that runs it with no time left.
	 */
	const COVERED = array(
		'src/Jobs/JobActions.php'           => 'tests/integration/LateCronTest.php::test_a_job_whose_cron_requests_all_start_late_moves_on_every_fourth_one',
		'src/Jobs/Runner.php'               => 'tests/integration/NoBudgetLeftTest.php::test_an_export_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/PreflightStep.php'        => 'tests/integration/NoBudgetLeftTest.php::test_an_export_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/FileScanStep.php'         => 'tests/integration/NoBudgetLeftTest.php::test_an_export_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/DatabaseExportStep.php'   => 'tests/integration/NoBudgetLeftTest.php::test_an_export_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/PackStep.php'             => 'tests/integration/NoBudgetLeftTest.php::test_an_export_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/ManifestStep.php'         => 'tests/integration/NoBudgetLeftTest.php::test_an_export_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/StoreStep.php'            => 'tests/integration/NoBudgetLeftTest.php::test_an_export_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/VerifyStep.php'           => 'tests/integration/NoBudgetLeftTest.php::test_a_verify_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/RestoreVerifyStep.php'    => 'tests/integration/NoBudgetLeftTest.php::test_a_restore_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/RestorePreflightStep.php' => 'tests/integration/NoBudgetLeftTest.php::test_a_restore_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/DatabaseImportStep.php'   => 'tests/integration/NoBudgetLeftTest.php::test_a_restore_moves_on_in_every_tick_with_no_time_left',
		'src/Jobs/TempTableDropper.php'     => 'tests/integration/JobRepositoryTest.php::test_the_first_drop_of_a_call_runs_even_when_the_time_is_already_up',
		'src/Support/Schema.php'            => 'tests/integration/JobRepositoryTest.php::test_uninstall_runs_one_call_even_when_its_time_is_already_up',
	);

	private static function root(): string {
		return dirname( __DIR__, 3 );
	}

	/**
	 * The files in src/ that loop on a budget, relative to the plugin root.
	 *
	 * @return string[]
	 */
	private static function budget_loops(): array {
		$root  = self::root();
		$found = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() && 1 === preg_match( self::BUDGET_LOOP, self::code( (string) file_get_contents( $file->getPathname() ) ) ) ) {
				$found[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
			}
		}
		sort( $found );
		return $found;
	}

	/**
	 * PHP source without its comments.
	 */
	private static function code( string $source ): string {
		$out = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}
		return $out;
	}

	public function test_every_budget_loop_has_a_test_that_runs_it_with_no_time_left(): void {
		$found = array_values( array_diff( self::budget_loops(), array_keys( self::EXEMPT ) ) );
		$this->assertSame( array(), array_values( array_diff( $found, array_keys( self::COVERED ) ) ), 'loops on a budget without a no-time-left test: add one and list it in COVERED' );
		$this->assertSame( array(), array_values( array_diff( array_keys( self::COVERED ), $found ) ), 'listed in COVERED but no longer loops on a budget: take it off' );
		$this->assertSame( array(), array_values( array_diff( array_keys( self::EXEMPT ), self::budget_loops() ) ), 'listed in EXEMPT but no longer stops early: take it off' );
		foreach ( self::COVERED as $source => $test ) {
			list( $file, $method ) = explode( '::', $test );
			$this->assertFileExists( self::root() . '/' . $file, $source );
			$text = (string) file_get_contents( self::root() . '/' . $file );
			$this->assertMatchesRegularExpression( '/function ' . preg_quote( $method, '/' ) . '\(/', $text, "{$source}: the test {$test} is gone" );
			$this->assertStringContainsString( basename( $source, '.php' ), $text, "{$source}: {$file} does not name the class it is listed for" );
		}
	}

	public function test_the_scan_finds_what_it_looks_for(): void {
		foreach ( array(
			'if ( $context->should_stop() ) {',
			'while ( $context->remaining_seconds() > 1 ) {',
			'if ( microtime( true ) - $started >= self::LIMIT ) {',
			'while ( (float) call_user_func( $clock ) - $started < self::MAX_SECONDS ) {',
		) as $code ) {
			$this->assertSame( 1, preg_match( self::BUDGET_LOOP, $code ), $code );
		}
		$this->assertSame( 1, preg_match( self::BUDGET_LOOP, 'if ( microtime( true ) - JobActions::started_at() >= self::LATE ) {' ), 'a start from elsewhere' );
		$this->assertSame( 0, preg_match( self::BUDGET_LOOP, 'if ( microtime( true ) - $last >= self::PROGRESS_SECONDS ) {' ), 'a throttle is no budget' );
		foreach ( array(
			'public function should_stop(): bool {',
			'$this->walk[\'seconds\'] += max( 0.0, (float) call_user_func( $this->clock ) - $started );',
			self::code( "<?php\n// if ( \$context->should_stop() ) {\n/** ->remaining_seconds( */\n\$a = 1;" ),
		) as $code ) {
			$this->assertSame( 0, preg_match( self::BUDGET_LOOP, $code ), $code );
		}
		$this->assertContains( 'src/Jobs/PackStep.php', self::budget_loops(), 'the control: a known one is found' );
	}
}
