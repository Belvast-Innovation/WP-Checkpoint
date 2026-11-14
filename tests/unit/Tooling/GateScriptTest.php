<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * bin/gate.sh, the checks a commit waits on: a step fails when any command of its pipeline fails, the first failed
 * step ends the gate with its status, and the steps after it are not run.
 */
final class GateScriptTest extends TestCase {

	/** @var string */
	private $sandbox = '';

	protected function setUp(): void {
		parent::setUp();
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'A bash script.' );
		}
		$this->sandbox = Sandbox::make( 'gate' );
	}

	protected function tearDown(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tearDown();
	}

	/**
	 * Run the gate with steps.
	 *
	 * @param string[] $steps Steps.
	 * @return array{0: int, 1: string, 2: string} Its status, standard output and error output.
	 */
	private function gate( array $steps ): array {
		$pipes   = array();
		$process = proc_open(
			array_merge( array( 'bash', dirname( __DIR__, 3 ) . '/bin/gate.sh' ), $steps ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array(
				'WPCHECKPOINT_TEST_OUTPUT' => $this->sandbox . '/out',
				'PATH'                     => (string) getenv( 'PATH' ),
				'MARK'                     => $this->sandbox . '/ran',
			)
		);
		$this->assertIsResource( $process );
		$out = (string) stream_get_contents( $pipes[1] );
		$err = (string) stream_get_contents( $pipes[2] );
		return array( proc_close( $process ), $out, $err );
	}

	public function test_steps_that_pass_let_the_gate_pass_and_each_is_run(): void {
		list( $status, $out, $err ) = $this->gate( array( 'echo one >> "$MARK"', 'echo two | cat >> "$MARK"' ) );
		$this->assertSame( 0, $status, $out . $err );
		$this->assertSame( "one\ntwo\n", file_get_contents( $this->sandbox . '/ran' ), 'the control: both steps ran, in order' );
		$this->assertStringContainsString( 'step 2 passed', $out );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function failing_steps(): array {
		return array(
			'a command that fails'                       => array( 'exit 3', 3 ),
			'the first command of a pipeline fails'      => array( 'sh -c "echo said; exit 2" | tail -n 1', 2 ),
			'a command in the middle of a pipeline fails' => array( 'echo said | sh -c "cat; exit 4" | tail -n 1', 4 ),
			'the last command of a pipeline fails'       => array( 'echo said | sh -c "cat; exit 5"', 5 ),
		);
	}

	/**
	 * @dataProvider failing_steps
	 */
	public function test_a_step_fails_when_any_command_of_its_pipeline_fails_and_the_steps_after_it_are_not_run( string $step, int $with ): void {
		list( $status, $out, $err ) = $this->gate( array( 'echo before >> "$MARK"', $step, 'echo after >> "$MARK"' ) );
		$this->assertSame( $with, $status, 'the gate ends with the failed step\'s status: ' . $out . $err );
		$this->assertStringContainsString( 'step 2 failed with status ' . $with, $err );
		$this->assertSame( "before\n", file_get_contents( $this->sandbox . '/ran' ), 'the step before ran (the control); the step after did not' );
		// As it is used: nothing after "&&" runs.
		$pipes   = array();
		$process = proc_open(
			array( 'bash', '-c', 'bash "$0" "$1" && echo committed >> "$MARK"', dirname( __DIR__, 3 ) . '/bin/gate.sh', $step ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			null,
			array(
				'WPCHECKPOINT_TEST_OUTPUT' => $this->sandbox . '/out',
				'PATH'                     => (string) getenv( 'PATH' ),
				'MARK'                     => $this->sandbox . '/ran',
			)
		);
		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		$this->assertSame( $with, proc_close( $process ) );
		$this->assertSame( "before\n", file_get_contents( $this->sandbox . '/ran' ), 'what follows the gate is not run' );
	}

	public function test_without_pipefail_the_same_pipeline_would_pass(): void {
		// The control of the whole test: the shell's own rule lets a pipeline pass whose middle command failed.
		exec( 'bash -c \'echo said | sh -c "cat; exit 4" | tail -n 1\' 2>&1', $lines, $status );
		$this->assertSame( 0, $status, 'without pipefail the failure is not seen: it is the gate that sees it' );
	}
}
