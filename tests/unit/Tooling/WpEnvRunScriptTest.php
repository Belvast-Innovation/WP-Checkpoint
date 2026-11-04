<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * bin/wp-env-run.sh against a stand-in npx in the sandbox: tried again only when wp-env failed before it started the
 * command with the closed connection; any other failure, or one after the command started, reported as it is, once.
 */
final class WpEnvRunScriptTest extends TestCase {

	/** The line wp-env prints when that connection fails, and the error it names. */
	const CLOSED = "✖ Socket closed before the connection was established\nError [ERR_SOCKET_CLOSED_BEFORE_CONNECTION]: Socket closed before the connection was established";

	/** @var string The sandbox; '' before set_up() made it. */
	private $sandbox = '';

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'A bash script for the development machine.' );
		}
		if ( '' === trim( (string) shell_exec( 'command -v bash' ) ) ) {
			$this->markTestSkipped( 'No bash here.' );
		}
		$this->sandbox = Sandbox::make( 'wp-env-run' );
		mkdir( $this->sandbox . '/bin', 0755, true );
		// The stand-in: each call takes the next line of "plan" (what that try does), records its arguments.
		file_put_contents(
			$this->sandbox . '/bin/npx',
			"#!/usr/bin/env bash\n" .
			'dir="$(cd "$(dirname "$0")/.." && pwd)"' . "\n" .
			'n=$(( $(cat "$dir/calls" 2>/dev/null || echo 0) + 1 )); echo "$n" > "$dir/calls"' . "\n" .
			'printf "%s\n" "$*" >> "$dir/args"' . "\n" .
			'case "$(sed -n "${n}p" "$dir/plan")" in' . "\n" .
			"  closed) printf '%s\\n' " . escapeshellarg( self::CLOSED ) . " >&2; exit 1 ;;\n" .
			"  other) echo 'Error: the containers are not running' >&2; exit 1 ;;\n" .
			"  ok) echo \"ℹ Starting 'cmd' on the tests-cli container.\"; echo 'the command ran'; exit 0 ;;\n" .
			"  failed) echo \"ℹ Starting 'cmd' on the tests-cli container.\"; printf '%s\\n' " . escapeshellarg( self::CLOSED ) . "; echo 'the command failed' >&2; exit 3 ;;\n" .
			"esac\n" .
			"echo 'no plan' >&2; exit 99\n"
		);
		chmod( $this->sandbox . '/bin/npx', 0755 );
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * Run the script with a plan of tries.
	 *
	 * @param string[] $plan     What each call of npx does: closed, other, ok, failed.
	 * @param int      $attempts WPCHECKPOINT_WPENV_ATTEMPTS.
	 * @return array{code: int, stdout: string, stderr: string, calls: int, args: string}
	 */
	private function run_script( array $plan, int $attempts = 6 ): array {
		file_put_contents( $this->sandbox . '/plan', implode( "\n", $plan ) . "\n" );
		$pipes   = array();
		$process = proc_open(
			array( 'bash', dirname( __DIR__, 3 ) . '/bin/wp-env-run.sh', 'tests-cli', '--env-cwd=a dir', 'sh', '-c', 'echo "two words"' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->sandbox,
			array(
				'PATH'                             => $this->sandbox . '/bin:' . (string) getenv( 'PATH' ),
				'TMPDIR'                           => $this->sandbox,
				'WPCHECKPOINT_WPENV_ATTEMPTS'      => (string) $attempts,
				'WPCHECKPOINT_WPENV_RETRY_SECONDS' => '0',
			)
		);
		$this->assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$code = proc_close( $process );
		return array(
			'code'   => $code,
			'stdout' => $stdout,
			'stderr' => $stderr,
			'calls'  => (int) @file_get_contents( $this->sandbox . '/calls' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- none when it never ran.
			'args'   => (string) @file_get_contents( $this->sandbox . '/args' ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		);
	}

	/**
	 * What a run said, for a failed assertion.
	 *
	 * @param array{code: int, stdout: string, stderr: string, calls: int, args: string} $run Run.
	 * @return string
	 */
	private static function said( array $run ): string {
		return sprintf( "exit %d, %d calls\nstdout: %s\nstderr: %s", $run['code'], $run['calls'], $run['stdout'], $run['stderr'] );
	}

	public function test_a_command_that_runs_is_run_once_with_its_arguments_as_given(): void {
		$run = $this->run_script( array( 'ok' ) );
		$this->assertSame( 0, $run['code'], self::said( $run ) );
		$this->assertSame( 1, $run['calls'], self::said( $run ) );
		$this->assertSame( "wp-env run tests-cli --env-cwd=a dir sh -c echo \"two words\"\n", $run['args'] );
		$this->assertStringContainsString( 'the command ran', $run['stdout'] );
		$this->assertSame( '', $run['stderr'], self::said( $run ) );
	}

	public function test_a_connection_closed_before_the_command_started_is_tried_again_until_it_starts(): void {
		$run = $this->run_script( array( 'closed', 'closed', 'ok' ) );
		$this->assertSame( 0, $run['code'], self::said( $run ) );
		$this->assertSame( 3, $run['calls'], self::said( $run ) );
		$this->assertStringContainsString( 'try 1 of 6', $run['stderr'] );
		$this->assertStringContainsString( 'try 2 of 6', $run['stderr'] );
		$this->assertStringContainsString( 'ERR_SOCKET_CLOSED_BEFORE_CONNECTION', $run['stdout'], 'what wp-env said is shown' );
		$this->assertStringContainsString( 'the command ran', $run['stdout'] );
	}

	public function test_a_connection_closed_on_every_try_ends_with_69_and_nothing_run(): void {
		$run = $this->run_script( array( 'closed', 'closed', 'closed', 'ok' ), 3 );
		$this->assertSame( 69, $run['code'], self::said( $run ) );
		$this->assertSame( 3, $run['calls'], 'no more than the tries: ' . self::said( $run ) );
		$this->assertStringContainsString( 'never started the command (3 tries', $run['stderr'] );
	}

	public function test_another_failure_before_the_command_started_is_not_tried_again(): void {
		$run = $this->run_script( array( 'other', 'ok' ) );
		$this->assertSame( 1, $run['code'], self::said( $run ) );
		$this->assertSame( 1, $run['calls'], self::said( $run ) );
		$this->assertStringContainsString( 'the containers are not running', $run['stdout'] );
		$this->assertStringNotContainsString( 'try 1 of', $run['stderr'] );
	}

	public function test_a_failure_after_the_command_started_is_not_tried_again_whatever_it_printed(): void {
		$run = $this->run_script( array( 'failed', 'ok' ) );
		$this->assertSame( 3, $run['code'], 'the command\'s own status: ' . self::said( $run ) );
		$this->assertSame( 1, $run['calls'], 'never run twice: ' . self::said( $run ) );
		$this->assertStringContainsString( 'ERR_SOCKET_CLOSED_BEFORE_CONNECTION', $run['stdout'], 'the control: its output named the error' );
		$this->assertStringContainsString( 'the command failed', $run['stdout'] );
	}
}
