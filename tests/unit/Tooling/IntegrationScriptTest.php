<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * bin/test-integration.sh runs the integration suite from a directory made for the run, not from the plugin's
 * directory (which wp-env mounts from the repository), with the configuration named in full; a relative --log-junit
 * still lands in the plugin's directory. PHPUnit is replaced by a script that records how it was called.
 */
final class IntegrationScriptTest extends TestCase {

	/** @var string */
	private $sandbox = '';

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'A POSIX shell script; the integration suite runs in the wp-env container.' );
		}
		if ( '' === trim( (string) shell_exec( 'command -v flock' ) ) ) {
			$this->markTestSkipped( 'No flock here; the script needs it (util-linux, or BusyBox\'s flock in the wp-env container).' );
		}
		$this->sandbox = Sandbox::make( 'it-script' );
		mkdir( $this->sandbox . '/tmp' );
		file_put_contents(
			$this->sandbox . '/phpunit',
			'<?php' . "\n"
			. 'if ( getenv( "FAKE_PID" ) ) { file_put_contents( getenv( "FAKE_PID" ), (string) getmypid() ); }' . "\n"
			. 'if ( getenv( "FAKE_WAIT" ) ) { $t = time(); while ( ! file_exists( getenv( "FAKE_WAIT" ) ) && time() - $t < 30 ) { usleep( 20000 ); } }' . "\n"
			. 'if ( getenv( "FAKE_TEMP" ) ) { mkdir( getenv( "TMPDIR" ) . "/wpc-left-in-temp" ); file_put_contents( getenv( "TMPDIR" ) . "/wpc-left-in-temp/x", "x" ); }' . "\n"
			. 'file_put_contents( getenv( "FAKE_RECORD" ), json_encode( array( "cwd" => getcwd(), "argv" => array_slice( $argv, 1 ), "suite" => getenv( "WPCHECKPOINT_TEST_SUITE" ), "tmpdir" => getenv( "TMPDIR" ), "run_tmp" => getenv( "WPCHECKPOINT_TEST_RUN_TMP" ), "left_in_temp" => is_file( getenv( "TMPDIR" ) . "/wpc-left-in-temp/x" ) ) ) );' . "\n"
			. 'if ( getenv( "FAKE_LEAVE" ) ) { file_put_contents( "left.txt", "x" ); }' . "\n"
			. 'exit( (int) getenv( "FAKE_EXIT" ) );' . "\n"
		);
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * Run the script from the sandbox with the given arguments.
	 *
	 * @param string[]             $args  Arguments.
	 * @param array<string,string> $extra Environment.
	 * @return array{code: int, stderr: string, call: array<string, mixed>}
	 */
	private function run_script( array $args, array $extra = array(), bool $relative = false, string $script = '' ): array {
		if ( '' === $script ) {
			$script = $relative ? 'bin/test-integration.sh' : dirname( __DIR__, 3 ) . '/bin/test-integration.sh';
		}
		$env    = array_merge(
			array(
				'PATH'                      => (string) getenv( 'PATH' ),
				'TMPDIR'                    => $this->sandbox . '/tmp',
				'WPCHECKPOINT_TEST_PHPUNIT' => $this->sandbox . '/phpunit',
				'FAKE_RECORD'               => $this->sandbox . '/call.json',
				'FAKE_EXIT'                 => '0',
				'WPCHECKPOINT_TEST_LOCK'    => $this->sandbox . '/run.lock',
			),
			$extra
		);
		$pipes   = array();
		$process = proc_open( array_merge( array( 'sh', $script ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $relative ? dirname( __DIR__, 3 ) : $this->sandbox, $env );
		$this->assertIsResource( $process );
		stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		$call = json_decode( (string) file_get_contents( $this->sandbox . '/call.json' ), true );
		$this->assertIsArray( $call, 'PHPUnit was called: ' . $stderr );
		return array(
			'code'   => $code,
			'stderr' => $stderr,
			'call'   => $call,
		);
	}

	/**
	 * The directories the script made for its runs and left.
	 *
	 * @return string[]
	 */
	private function work_dirs(): array {
		return array_values( array_filter( (array) scandir( $this->sandbox . '/tmp' ), static function ( $name ): bool {
			return 0 === strpos( (string) $name, 'wpcheckpoint-it.' );
		} ) );
	}

	public function test_the_suite_runs_from_a_directory_of_its_own_with_the_configuration_in_full(): void {
		$plugin = (string) realpath( dirname( __DIR__, 3 ) );
		$run    = $this->run_script( array( '--log-junit', 'build/junit-integration.xml', '--filter', "a b'c \"d\"", '--log-junit=build/second.xml', '--log-junit=/abs/third.xml', '--testdox-text=build/dox.txt', '--group', 'tests', 'tests/integration', 'no/such/path', '--bootstrap=tests/bootstrap.php', '--whitelist', 'build/not-there' ) );
		$this->assertSame( 0, $run['code'], $run['stderr'] );
		$cwd = (string) $run['call']['cwd']; // Gone by now: not resolved.
		$this->assertSame( 'cwd', basename( $cwd ), 'a working directory made for the run' );
		$this->assertSame( ExpectedPath::slashed( $this->sandbox, 'tmp' ), dirname( $cwd, 2 ), 'in the temporary directory' );
		$this->assertStringStartsWith( 'wpcheckpoint-it.', basename( dirname( $cwd ) ) );
		$this->assertSame( ExpectedPath::slashed( dirname( $cwd ), 'tmp' ), $run['call']['tmpdir'], 'and a temporary directory of its own' );
		$this->assertSame( $run['call']['tmpdir'], $run['call']['run_tmp'], 'named for the leftover check' );
		$this->assertSame( 'integration', $run['call']['suite'] );
		$this->assertSame(
			array( '-c', ExpectedPath::slashed( $plugin, 'phpunit.xml.dist' ), '--testsuite', 'integration', '--log-junit', ExpectedPath::slashed( $plugin, 'build/junit-integration.xml' ), '--filter', "a b'c \"d\"", '--log-junit=' . ExpectedPath::slashed( $plugin, 'build/second.xml' ), '--log-junit=/abs/third.xml', '--testdox-text=' . ExpectedPath::slashed( $plugin, 'build/dox.txt' ), '--group', 'tests', ExpectedPath::slashed( $plugin, 'tests/integration' ), 'no/such/path', '--bootstrap=' . ExpectedPath::slashed( $plugin, 'tests/bootstrap.php' ), '--whitelist', ExpectedPath::slashed( $plugin, 'build/not-there' ) ),
			$run['call']['argv'],
			'the configuration in full; output paths and an existing test path from the plugin\'s directory; option values (a group named like a directory) and the rest as given'
		);
		$this->assertSame( array(), $this->work_dirs(), 'the empty directory is removed after the run' );
	}

	public function test_the_runs_temporary_directory_goes_with_it_and_fails_nothing(): void {
		$run = $this->run_script( array(), array( 'FAKE_TEMP' => '1' ) );
		$this->assertSame( 0, $run['code'], 'the leftover check, not the script, judges what tests leave there: ' . $run['stderr'] );
		$this->assertTrue( $run['call']['left_in_temp'], 'the control: the suite left an entry in its temporary directory' );
		$this->assertSame( array(), $this->work_dirs(), 'removed, with what was in its temporary directory' );
	}

	public function test_the_plugins_directory_is_found_whatever_cdpath_says(): void {
		// Called as the npm scripts do, by a relative path from the plugin's directory, with a CDPATH under which
		// "bin/.." would be another directory. (Nothing is written from here: the run goes to its own directory.)
		mkdir( $this->sandbox . '/bin' );
		$run = $this->run_script( array(), array( 'CDPATH' => $this->sandbox ), true );
		$this->assertSame( 0, $run['code'], $run['stderr'] );
		$this->assertSame( ExpectedPath::slashed( (string) realpath( dirname( __DIR__, 3 ) ), 'phpunit.xml.dist' ), $run['call']['argv'][1] );
		$this->assertSame( ExpectedPath::slashed( $this->sandbox, 'tmp' ), dirname( (string) $run['call']['cwd'], 2 ) );
	}

	public function test_a_local_phpunit_xml_is_the_configuration_when_there_is_one(): void {
		// A copy of the script in a plugin directory of its own: nothing is written to the repository.
		mkdir( $this->sandbox . '/plugin/bin', 0755, true );
		copy( dirname( __DIR__, 3 ) . '/bin/test-integration.sh', $this->sandbox . '/plugin/bin/test-integration.sh' );
		file_put_contents( $this->sandbox . '/plugin/phpunit.xml.dist', '<phpunit/>' );
		$script = $this->sandbox . '/plugin/bin/test-integration.sh';
		$this->assertSame( ExpectedPath::slashed( $this->sandbox, 'plugin/phpunit.xml.dist' ), $this->run_script( array(), array(), false, $script )['call']['argv'][1], 'the control: the distributed one' );
		file_put_contents( $this->sandbox . '/plugin/phpunit.xml', '<phpunit/>' );
		$this->assertSame( ExpectedPath::slashed( $this->sandbox, 'plugin/phpunit.xml' ), $this->run_script( array(), array(), false, $script )['call']['argv'][1] );
	}

	/**
	 * Start the script without waiting for it.
	 *
	 * @param array<string,string> $extra Environment.
	 * @return array{0: resource, 1: array<int, resource>, 2: int} The process, its pipes, its ID.
	 */
	private function start_script( array $extra ): array {
		$env     = array_merge(
			array(
				'PATH'                      => (string) getenv( 'PATH' ),
				'TMPDIR'                    => $this->sandbox . '/tmp',
				'WPCHECKPOINT_TEST_PHPUNIT' => $this->sandbox . '/phpunit',
				'FAKE_EXIT'                 => '0',
				'WPCHECKPOINT_TEST_LOCK'    => $this->sandbox . '/run.lock',
			),
			$extra
		);
		$pipes   = array();
		$process = proc_open( array( 'sh', dirname( __DIR__, 3 ) . '/bin/test-integration.sh' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $this->sandbox, $env );
		$this->assertIsResource( $process );
		return array( $process, $pipes, (int) proc_get_status( $process )['pid'] );
	}

	/**
	 * Wait for a started script to end.
	 *
	 * @param array{0: resource, 1: array<int, resource>, 2: int} $run From start_script().
	 * @return array{code: int, stderr: string}
	 */
	private static function finish( array $run ): array {
		stream_get_contents( $run[1][1] );
		$stderr = (string) stream_get_contents( $run[1][2] );
		fclose( $run[1][1] );
		fclose( $run[1][2] );
		return array(
			'code'   => proc_close( $run[0] ),
			'stderr' => $stderr,
		);
	}

	public function test_a_second_run_is_refused_while_the_first_is_running_and_the_first_is_named(): void {
		$lock  = $this->sandbox . '/run.lock';
		$go    = $this->sandbox . '/go';
		$first = $this->start_script( array( 'FAKE_WAIT' => $go, 'FAKE_RECORD' => $this->sandbox . '/first.json' ) );
		for ( $i = 0; $i < 500 && ! is_file( $lock ); $i++ ) {
			usleep( 20000 );
		}
		try {
			$this->assertFileExists( $lock, 'the first run holds the lock' );
			$this->assertSame( (string) $first[2], trim( (string) strtok( (string) file_get_contents( $lock ), "\n" ) ), 'by its process ID' );

			$second = self::finish( $this->start_script( array( 'FAKE_RECORD' => $this->sandbox . '/second.json' ) ) );
			$this->assertSame( 75, $second['code'], $second['stderr'] );
			$this->assertStringContainsString( 'Another integration run is in progress: process ' . $first[2] . ', started ', $second['stderr'] );
			$this->assertFileDoesNotExist( $this->sandbox . '/second.json', 'the second run never started PHPUnit' );
		} finally {
			touch( $go );
			$done = self::finish( $first );
		}
		$this->assertSame( 0, $done['code'], $done['stderr'] );
		$this->assertFileExists( $this->sandbox . '/first.json', 'the control: a run that starts PHPUnit is seen to' );
		$this->assertSame( '', (string) file_get_contents( $lock ), 'released when the run ends: its name taken out' );

		$third = $this->run_script( array() );
		$this->assertSame( 0, $third['code'], 'and the next run goes ahead: ' . $third['stderr'] );
	}

	public function test_a_lock_whose_run_is_gone_is_taken_over(): void {
		$lock = $this->sandbox . '/run.lock';
		// A process that has ended: its ID names no process now.
		$ended = proc_open( array( 'sh', '-c', 'exit 0' ), array(), $pipes );
		$this->assertIsResource( $ended );
		$gone = (int) proc_get_status( $ended )['pid'];
		proc_close( $ended );
		// A live process that is not an integration run (this test's own): its ID, reused, does not hold the lock.
		foreach ( array( $gone => 'an ended process', getmypid() => 'a process that is no integration run' ) as $pid => $what ) {
			file_put_contents( $lock, $pid . "\n2026-01-01 00:00:00 UTC\n" );
			$run = $this->run_script( array() );
			$this->assertSame( 0, $run['code'], $what . ': ' . $run['stderr'] );
			$this->assertStringContainsString( 'Taking over the lock of an integration run that ended without releasing it: process ' . $pid . ', started 2026-01-01 00:00:00 UTC', $run['stderr'], $what );
			$this->assertSame( '', (string) file_get_contents( $lock ), $what . ': released at the end' );
		}
	}

	/**
	 * Wait until a condition holds, at most five seconds.
	 */
	private static function until( callable $condition ): bool {
		for ( $i = 0; $i < 250; $i++ ) {
			clearstatcache();
			if ( $condition() ) {
				return true;
			}
			usleep( 20000 );
		}
		return false;
	}

	public function test_a_run_whose_shell_was_killed_keeps_the_lock_while_its_phpunit_runs(): void {
		$lock = $this->sandbox . '/run.lock';
		$go   = $this->sandbox . '/go';
		$pid  = $this->sandbox . '/phpunit.pid';
		$run  = $this->start_script( array( 'FAKE_WAIT' => $go, 'FAKE_PID' => $pid, 'FAKE_RECORD' => $this->sandbox . '/first.json' ) );
		try {
			$this->assertTrue( self::until( static function () use ( $pid ): bool {
				return is_file( $pid ) && '' !== (string) file_get_contents( $pid );
			} ), 'its PHPUnit started' );
			proc_terminate( $run[0], 9 ); // The shell only, as a timeout's SIGKILL would: its PHPUnit goes on.
			$this->assertTrue( self::until( static function () use ( $run ): bool {
				return ! proc_get_status( $run[0] )['running'];
			} ), 'the shell is gone' );
			$this->assertTrue( posix_kill( (int) file_get_contents( $pid ), 0 ), 'the control: its PHPUnit still runs' );

			$second = self::finish( $this->start_script( array( 'FAKE_RECORD' => $this->sandbox . '/second.json' ) ) );
			$this->assertSame( 75, $second['code'], 'refused while the PHPUnit runs: ' . $second['stderr'] );
			$this->assertStringContainsString( 'Another integration run is in progress: process ' . $run[2] . ',', $second['stderr'] );
			$this->assertFileDoesNotExist( $this->sandbox . '/second.json' );
		} finally {
			touch( $go );
			self::finish( $run );
		}
		$this->assertTrue( self::until( static function () use ( $pid ): bool {
			return ! posix_kill( (int) file_get_contents( $pid ), 0 );
		} ), 'its PHPUnit ended' );
		$third = $this->run_script( array() );
		$this->assertSame( 0, $third['code'], $third['stderr'] );
		$this->assertStringContainsString( 'Taking over the lock of an integration run that ended without releasing it: process ' . $run[2] . ',', $third['stderr'], 'the killed run is named' );
	}

	public function test_an_interrupted_run_releases_the_lock_and_cleans_up(): void {
		$lock = $this->sandbox . '/run.lock';
		$go   = $this->sandbox . '/go';
		$pid  = $this->sandbox . '/phpunit.pid';
		$run  = $this->start_script( array( 'FAKE_WAIT' => $go, 'FAKE_PID' => $pid, 'FAKE_RECORD' => $this->sandbox . '/first.json' ) );
		$this->assertTrue( self::until( static function () use ( $pid ): bool {
			return is_file( $pid ) && '' !== (string) file_get_contents( $pid );
		} ), 'its PHPUnit started' );
		$this->assertCount( 1, $this->work_dirs(), 'the control: the run has its directory' );
		proc_terminate( $run[0], 2 ); // SIGINT to the shell: it ends once its PHPUnit has.
		touch( $go );
		$done = self::finish( $run );
		$this->assertSame( 130, $done['code'], $done['stderr'] );
		$this->assertSame( array(), $this->work_dirs(), 'its directory is cleaned up' );
		$this->assertSame( '', (string) file_get_contents( $lock ), 'and the lock released' );
	}

	public function test_the_suites_status_is_the_scripts(): void {
		$run = $this->run_script( array(), array( 'FAKE_EXIT' => '3' ) );
		$this->assertSame( 3, $run['code'] );
		$this->assertSame( array(), $this->work_dirs() );
	}

	public function test_what_the_suite_leaves_in_its_directory_is_kept_and_fails_the_run(): void {
		$run = $this->run_script( array(), array( 'FAKE_LEAVE' => '1' ) );
		$this->assertSame( 1, $run['code'], 'a passing suite that wrote to a relative path fails' );
		$this->assertStringContainsString( 'left files in its working directory', $run['stderr'] );
		$dirs = $this->work_dirs();
		$this->assertCount( 1, $dirs, 'kept to be looked at' );
		$this->assertFileExists( $this->sandbox . '/tmp/' . $dirs[0] . '/cwd/left.txt' );

		$run = $this->run_script( array(), array( 'FAKE_LEAVE' => '1', 'FAKE_EXIT' => '2' ) );
		$this->assertSame( 2, $run['code'], 'a failing suite keeps its own status' );
	}
}
