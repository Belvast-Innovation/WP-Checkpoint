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
		$this->sandbox = Sandbox::make( 'it-script' );
		mkdir( $this->sandbox . '/tmp' );
		file_put_contents(
			$this->sandbox . '/phpunit',
			'<?php' . "\n"
			. 'file_put_contents( getenv( "FAKE_RECORD" ), json_encode( array( "cwd" => getcwd(), "argv" => array_slice( $argv, 1 ), "suite" => getenv( "WPCHECKPOINT_TEST_SUITE" ) ) ) );' . "\n"
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
	private function run_script( array $args, array $extra = array() ): array {
		$script = dirname( __DIR__, 3 ) . '/bin/test-integration.sh';
		$env    = array_merge(
			array(
				'PATH'                      => (string) getenv( 'PATH' ),
				'TMPDIR'                    => $this->sandbox . '/tmp',
				'WPCHECKPOINT_TEST_PHPUNIT' => $this->sandbox . '/phpunit',
				'FAKE_RECORD'               => $this->sandbox . '/call.json',
				'FAKE_EXIT'                 => '0',
			),
			$extra
		);
		$pipes   = array();
		$process = proc_open( array_merge( array( 'sh', $script ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $this->sandbox, $env );
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
		$run    = $this->run_script( array( '--log-junit', 'build/junit-integration.xml', '--filter', "a b'c \"d\"", '--log-junit=build/second.xml', '--log-junit=/abs/third.xml' ) );
		$this->assertSame( 0, $run['code'], $run['stderr'] );
		$cwd = (string) $run['call']['cwd']; // Gone by now: not resolved.
		$this->assertSame( ExpectedPath::slashed( $this->sandbox, 'tmp' ), dirname( $cwd ), 'a directory made in the temporary directory' );
		$this->assertStringStartsWith( 'wpcheckpoint-it.', basename( $cwd ) );
		$this->assertSame( 'integration', $run['call']['suite'] );
		$this->assertSame(
			array( '-c', ExpectedPath::slashed( $plugin, 'phpunit.xml.dist' ), '--testsuite', 'integration', '--log-junit', ExpectedPath::slashed( $plugin, 'build/junit-integration.xml' ), '--filter', "a b'c \"d\"", '--log-junit=' . ExpectedPath::slashed( $plugin, 'build/second.xml' ), '--log-junit=/abs/third.xml' ),
			$run['call']['argv'],
			'the configuration in full, a relative junit path in the plugin\'s directory, the rest as given'
		);
		$this->assertSame( array(), $this->work_dirs(), 'the empty directory is removed after the run' );
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
		$this->assertFileExists( $this->sandbox . '/tmp/' . $dirs[0] . '/left.txt' );

		$run = $this->run_script( array(), array( 'FAKE_LEAVE' => '1', 'FAKE_EXIT' => '2' ) );
		$this->assertSame( 2, $run['code'], 'a failing suite keeps its own status' );
	}
}
