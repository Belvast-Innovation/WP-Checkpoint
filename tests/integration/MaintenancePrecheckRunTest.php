<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Leftovers;
use WPCheckpoint\Tests\Fixtures\RealAbspath;
use WP_UnitTestCase;

/**
 * The check before an integration run (tests/Fixtures/maintenance-precheck.php), run as bin/test-integration.sh runs
 * it, on the test site itself: a test's own "someone else's" maintenance file (RealAbspath::fixture(): it carries the
 * mark) is removed, one without the mark is left, and .maintenance left in place stops the run (76). The files are put
 * in the real ABSPATH through RealAbspath (the registered exception); the check confirms the test site and its
 * database itself before it removes anything, as before a run.
 */
final class MaintenancePrecheckRunTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		RealAbspath::claim();
	}

	public function tear_down(): void {
		foreach ( RealAbspath::listing() as $path ) {
			RealAbspath::remove( basename( $path ) );
		}
		parent::tear_down();
	}

	/**
	 * Run the check as the run script does (php, the tests configuration in the environment): exit status, standard
	 * output, standard error (read apart).
	 *
	 * @param array<string, string> $env More environment.
	 * @return array{0: int, 1: string, 2: string}
	 */
	private function precheck( array $env = array() ): array {
		$pipes   = array();
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/maintenance-precheck.php' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array_merge( getenv(), $env )
		);
		$this->assertIsResource( $process );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array( proc_close( $process ), $out, $err );
	}

	/**
	 * The names of the files RealAbspath lists now.
	 *
	 * @return string[]
	 */
	private static function names(): array {
		$out = array_map( 'basename', RealAbspath::listing() );
		sort( $out );
		return $out;
	}

	public function test_before_a_run_a_tests_marked_file_is_removed_and_one_without_the_mark_is_left(): void {
		RealAbspath::write( Maintenance::FILE, RealAbspath::fixture( '<?php $upgrading = ' . ( time() - 3600 ) . '; // an update' ) );
		RealAbspath::write( Maintenance::DATA, "someone else's\n" );
		$this->assertSame( array( Maintenance::FILE, Maintenance::DATA ), self::names(), 'the control: both there' );

		// Listed only: nothing removed, and .maintenance stops the run (the observation of "removed" works).
		list( $status, $out, $err ) = $this->precheck( array( Leftovers::REMOVE_MAINTENANCE => '0' ) );
		$said = "exit {$status}\nstdout: {$out}\nstderr: {$err}";
		$this->assertSame( 76, $status, $said );
		$this->assertStringContainsString( Maintenance::FILE . " (a test's fixture)", $err, $said );
		$this->assertStringContainsString( Maintenance::DATA . " (not this plugin's)", $err, $said );
		$this->assertSame( array( Maintenance::FILE, Maintenance::DATA ), self::names(), 'listed only' );

		list( $status, $out, $err ) = $this->precheck();
		$said = "exit {$status}\nstdout: {$out}\nstderr: {$err}";
		$this->assertSame( 0, $status, 'the run starts: ' . $said );
		$this->assertStringNotContainsString( 'Nothing was removed', $err, 'the test site and its database confirmed: ' . $said );
		$this->assertSame( array( Maintenance::DATA ), self::names(), 'the marked file removed, the one without the mark left: ' . $said );
		$this->assertStringContainsString( 'Left in place:', $err, $said );
		$this->assertSame( "someone else's\n", RealAbspath::contents()[ Maintenance::DATA ] ?? null, 'left as it was' );
	}

	public function test_before_a_run_a_maintenance_file_without_the_mark_is_left_and_stops_the_run(): void {
		RealAbspath::write( Maintenance::FILE, '<?php $upgrading = ' . ( time() - 3600 ) . "; // an update\n" );
		list( $status, $out, $err ) = $this->precheck();
		$said = "exit {$status}\nstdout: {$out}\nstderr: {$err}";
		$this->assertSame( 76, $status, $said );
		$this->assertStringContainsString( Maintenance::FILE . " (not this plugin's)", $err, $said );
		$this->assertStringContainsString( 'The run does not start', $err, $said );
		$this->assertSame( array( Maintenance::FILE ), self::names(), 'left' );
	}
}
