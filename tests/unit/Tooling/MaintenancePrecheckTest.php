<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * tests/Fixtures/maintenance-precheck.php, run as bin/test-integration.sh runs it, on a stand-in WordPress directory
 * named by a tests configuration of the test's own: it says what is there, removes nothing unless asked, and only on
 * wp-env's test site when asked (here the database cannot be confirmed, so nothing is removed), and stops the run
 * while .maintenance is there.
 */
final class MaintenancePrecheckTest extends TestCase {

	/** @var string */
	private $sandbox = '';

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'The integration suite, and this check before it, run in the wp-env container.' );
		}
		$this->sandbox = Sandbox::make( 'maintenance-precheck' );
		mkdir( $this->sandbox . '/site' );
		file_put_contents( $this->sandbox . '/site/wp-settings.php', '<?php' );
		$this->config( $this->sandbox . '/site/' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * A tests configuration naming $abspath, and a database server that is not there.
	 */
	private function config( string $abspath ): void {
		file_put_contents(
			$this->sandbox . '/wp-tests-config.php',
			'<?php' . "\n"
			. 'define( "ABSPATH", ' . var_export( $abspath, true ) . ' );' . "\n"
			. 'define( "DB_NAME", "tests-wordpress" );' . "\n"
			. 'define( "DB_USER", "nobody" );' . "\n"
			. 'define( "DB_PASSWORD", "none" );' . "\n"
			. 'define( "DB_HOST", "127.0.0.1:1" );' . "\n"
		);
	}

	/**
	 * Run the check.
	 *
	 * @param array<string, string> $extra Environment.
	 * @return array{code: int, stderr: string}
	 */
	private function check( array $extra = array() ): array {
		$env     = array_merge(
			array(
				'PATH'                     => (string) getenv( 'PATH' ),
				'WP_PHPUNIT__TESTS_CONFIG' => $this->sandbox . '/wp-tests-config.php',
			),
			$extra
		);
		$pipes   = array();
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__, 2 ) . '/Fixtures/maintenance-precheck.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $this->sandbox, $env );
		$this->assertIsResource( $process );
		stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array(
			'code'   => proc_close( $process ),
			'stderr' => $stderr,
		);
	}

	private function data_file(): string {
		return "<?php exit; ?>\nWP Checkpoint restore " . str_repeat( 'a', 32 ) . "\n" . Maintenance::held() . "\n-\n";
	}

	public function test_nothing_there_is_said_and_the_run_starts(): void {
		$out = $this->check();
		$this->assertSame( 0, $out['code'], $out['stderr'] );
		$this->assertStringContainsString( 'No maintenance file in the test site\'s WordPress directory (' . ExpectedPath::slashed( $this->sandbox, 'site' ) . ')', $out['stderr'] );
	}

	public function test_without_the_tests_configuration_nothing_is_checked(): void {
		file_put_contents( $this->sandbox . '/site/.maintenance', Maintenance::CODE );
		$out = $this->check( array( 'WP_PHPUNIT__TESTS_CONFIG' => $this->sandbox . '/none.php' ) );
		$this->assertSame( 0, $out['code'] );
		$this->assertSame( '', $out['stderr'] );
		$this->assertSame( 76, $this->check()['code'], 'the control: with it, the same file is found' );
	}

	public function test_the_files_are_listed_and_left_and_the_run_starts_without_the_maintenance_file(): void {
		file_put_contents( $this->sandbox . '/site/.wpcheckpoint-maintenance.php', $this->data_file() );
		file_put_contents( $this->sandbox . '/site/.maintenance.0123456789abcdef.tmp', 'partial' );
		file_put_contents( $this->sandbox . '/site/unrelated.txt', 'x' );
		$out = $this->check();
		$this->assertSame( 0, $out['code'], $out['stderr'] );
		$this->assertStringContainsString( ExpectedPath::slashed( $this->sandbox, 'site', '.wpcheckpoint-maintenance.php' ) . ' (this plugin\'s)', $out['stderr'] );
		$this->assertStringContainsString( ExpectedPath::slashed( $this->sandbox, 'site', '.maintenance.0123456789abcdef.tmp' ) . ' (this plugin\'s)', $out['stderr'] );
		$this->assertStringNotContainsString( 'unrelated.txt', $out['stderr'] );
		$this->assertStringContainsString( 'Listed only; nothing was removed', $out['stderr'] );
		clearstatcache();
		$this->assertFileExists( $this->sandbox . '/site/.wpcheckpoint-maintenance.php' );
		$this->assertFileExists( $this->sandbox . '/site/.maintenance.0123456789abcdef.tmp' );
	}

	public function test_a_maintenance_file_is_listed_left_and_stops_the_run(): void {
		file_put_contents( $this->sandbox . '/site/.maintenance', Maintenance::CODE );
		$out = $this->check();
		$this->assertSame( 76, $out['code'] );
		$this->assertStringContainsString( ExpectedPath::slashed( $this->sandbox, 'site', '.maintenance' ) . ' (this plugin\'s)', $out['stderr'] );
		$this->assertStringContainsString( 'The run does not start', $out['stderr'] );
		clearstatcache();
		$this->assertFileExists( $this->sandbox . '/site/.maintenance' );

		file_put_contents( $this->sandbox . '/site/.maintenance', '<?php $upgrading = time();' );
		$out = $this->check();
		$this->assertSame( 76, $out['code'] );
		$this->assertStringContainsString( ExpectedPath::slashed( $this->sandbox, 'site', '.maintenance' ) . ' (not this plugin\'s)', $out['stderr'], 'someone else\'s is said so' );
	}

	public function test_asked_to_remove_it_removes_nothing_where_the_database_is_not_confirmed_as_wp_envs(): void {
		file_put_contents( $this->sandbox . '/site/.maintenance', Maintenance::CODE );
		file_put_contents( $this->sandbox . '/site/.wpcheckpoint-maintenance.php', $this->data_file() );
		$out = $this->check( array( 'WPCHECKPOINT_TEST_MAINTENANCE_REMOVE' => '1' ) );
		$this->assertSame( 76, $out['code'] );
		$this->assertMatchesRegularExpression( '/Nothing was removed: (the tests database could not be reached|mysqli is not available)/', $out['stderr'] );
		$this->assertStringNotContainsString( 'Listed only', $out['stderr'], 'the control: asked to remove' );
		clearstatcache();
		$this->assertFileExists( $this->sandbox . '/site/.maintenance' );
		$this->assertFileExists( $this->sandbox . '/site/.wpcheckpoint-maintenance.php' );
	}

	public function test_the_removal_it_makes_once_its_checks_pass_takes_this_plugins_files_only(): void {
		// What the check runs once ABSPATH and the database are confirmed (RealAbspath::clear() on each listed path),
		// in a process of its own whose ABSPATH is the stand-in directory: the database cannot be confirmed here.
		file_put_contents( $this->sandbox . '/site/.wpcheckpoint-maintenance.php', $this->data_file() );
		file_put_contents( $this->sandbox . '/site/.maintenance.0123456789abcdef.tmp', 'partial' );
		file_put_contents( $this->sandbox . '/site/.maintenance', '<?php $upgrading = time();' );
		file_put_contents( $this->sandbox . '/clear.php', '<?php require ' . var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true ) . '; define( "ABSPATH", ' . var_export( $this->sandbox . '/site/', true ) . ' ); foreach ( \WPCheckpoint\Tests\Fixtures\RealAbspath::listing() as $p ) { \WPCheckpoint\Tests\Fixtures\RealAbspath::clear( $p ); }' );
		$stderr = $this->clear();
		clearstatcache();
		$this->assertFileDoesNotExist( $this->sandbox . '/site/.wpcheckpoint-maintenance.php', 'this plugin\'s data file goes' );
		$this->assertFileDoesNotExist( $this->sandbox . '/site/.maintenance.0123456789abcdef.tmp', 'and a temporary name' );
		$this->assertFileExists( $this->sandbox . '/site/.maintenance', 'someone else\'s stays' );
		$this->assertStringContainsString( 'not this plugin\'s', $stderr, 'and is said' );

		// A link with this plugin's contents is not deleted, nor what it points to; the refusal is said, not thrown.
		file_put_contents( $this->sandbox . '/data.txt', $this->data_file() );
		symlink( $this->sandbox . '/data.txt', $this->sandbox . '/site/.wpcheckpoint-maintenance.php' );
		$stderr = $this->clear();
		clearstatcache();
		$this->assertTrue( is_link( $this->sandbox . '/site/.wpcheckpoint-maintenance.php' ) );
		$this->assertFileExists( $this->sandbox . '/data.txt' );
		$this->assertStringContainsString( 'it is not a regular file', $stderr );
		$this->assertStringNotContainsString( 'Fatal', $stderr );
	}

	/**
	 * Run the removal step (clear.php in the sandbox): its standard error, after asserting it ended normally.
	 */
	private function clear(): string {
		$pipes   = array();
		$process = proc_open( array( PHP_BINARY, $this->sandbox . '/clear.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $this->sandbox, array( 'PATH' => (string) getenv( 'PATH' ) ) );
		$this->assertIsResource( $process );
		stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $stderr );
		return $stderr;
	}

	public function test_asked_to_remove_it_removes_nothing_where_abspath_is_not_a_wordpress_directory(): void {
		mkdir( $this->sandbox . '/bare' );
		file_put_contents( $this->sandbox . '/bare/.maintenance', Maintenance::CODE );
		$this->config( $this->sandbox . '/bare/' );
		$out = $this->check( array( 'WPCHECKPOINT_TEST_MAINTENANCE_REMOVE' => '1' ) );
		$this->assertSame( 76, $out['code'] );
		$this->assertStringContainsString( 'Nothing was removed: ABSPATH is not a WordPress directory.', $out['stderr'] );
		clearstatcache();
		$this->assertFileExists( $this->sandbox . '/bare/.maintenance' );
	}
}
