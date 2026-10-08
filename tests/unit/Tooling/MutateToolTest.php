<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The mutation tool (bin/mutate.php), in a repository of its own in a sandbox, with a stand-in for the tests that
 * records what it saw and fails when it sees the mutated text: it refuses a working tree that is not clean (and leaves
 * it as it is), runs each mutation with its file changed, writes the file back byte for byte, tells caught from
 * survived, and does not run a mutation whose text is not found once or that does not pass php -l.
 */
final class MutateToolTest extends TestCase {

	/** @var string The sandbox; '' before set_up() made it. */
	private $sandbox = '';

	/** @var string The repository in the sandbox. */
	private $repo = '';

	/** The file the mutations change, with no newline at its end and a CRLF inside (written back as it was). */
	const ORIGINAL = "<?php\r\nreturn 1; // one";

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY || '' === trim( (string) shell_exec( 'command -v git' ) ) ) {
			$this->markTestSkipped( 'Needs git and a POSIX shell.' );
		}
		$this->sandbox = Sandbox::make( 'mutate-tool' );
		$this->repo    = $this->sandbox . '/repo';
		mkdir( $this->repo . '/src', 0755, true );
		file_put_contents( $this->repo . '/src/a.php', self::ORIGINAL );
		file_put_contents( $this->repo . '/.gitignore', "/build/\n" );
		// The stand-in for the tests: records what src/a.php held while it ran, fails when it holds "return 2".
		file_put_contents(
			$this->repo . '/check.php',
			'<?php
$name = (string) getenv( "MUTATION" );
@mkdir( "build" );
file_put_contents( "build/seen-" . $name . ".txt", file_get_contents( "src/a.php" ) );
if ( "nolog" === $name ) { exit( 1 ); }
$fail = false !== strpos( file_get_contents( "src/a.php" ), "return 2" );
file_put_contents( $argv[1], "<?xml version=\"1.0\"?><testsuites><testsuite><testcase name=\"test_a\">" . ( $fail ? "<failure>test_a\nreturns two</failure>" : "" ) . "</testcase></testsuite></testsuites>" );
exit( $fail ? 1 : 0 );
'
		);
		$this->git( 'init -q' );
		$this->git( 'add -A' );
		$this->git( 'commit -q -m start' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	private function git( string $args ): string {
		$out = array();
		$rc  = 0;
		exec( 'cd ' . escapeshellarg( $this->repo ) . ' && git -c user.name=t -c user.email=t@example.invalid ' . $args . ' 2>&1', $out, $rc );
		$this->assertSame( 0, $rc, 'git ' . $args . ': ' . implode( "\n", $out ) );
		return implode( "\n", $out );
	}

	/**
	 * Run the tool on a spec of these mutations.
	 *
	 * @param array<int, array<string, string>> $mutations Mutations.
	 * @return array{code: int, out: string}
	 */
	private function mutate( array $mutations ): array {
		$spec = $this->sandbox . '/spec.json';
		file_put_contents(
			$spec,
			(string) json_encode(
				array(
					'command'   => escapeshellarg( PHP_BINARY ) . ' check.php {junit}',
					'mutations' => $mutations,
				)
			)
		);
		$pipes   = array();
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__, 3 ) . '/bin/mutate.php', $spec ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $this->repo . '/src', array( 'PATH' => (string) getenv( 'PATH' ), 'HOME' => $this->sandbox ) );
		$this->assertIsResource( $process );
		$out = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array(
			'code' => proc_close( $process ),
			'out'  => $out,
		);
	}

	private function seen( string $name ): ?string {
		$path = $this->repo . '/build/seen-' . $name . '.txt';
		return is_file( $path ) ? (string) file_get_contents( $path ) : null;
	}

	public function test_it_runs_each_mutation_on_the_changed_file_tells_caught_from_survived_and_writes_the_file_back(): void {
		$run = $this->mutate(
			array(
				array(
					'name' => 'two',
					'file' => 'src/a.php',
					'old'  => 'return 1;',
					'new'  => 'return 2;',
				),
				array(
					'name' => 'comment',
					'file' => 'src/a.php',
					'old'  => '// one',
					'new'  => '// uno',
				),
			)
		);
		$this->assertSame( 1, $run['code'], $run['out'] );
		$this->assertStringContainsString( "two: caught:\n    test_a: returns two", $run['out'] );
		$this->assertStringContainsString( 'comment: SURVIVED', $run['out'] );
		$this->assertSame( "<?php\r\nreturn 2; // one", $this->seen( 'two' ), 'the tests ran on the mutated file' );
		$this->assertSame( "<?php\r\nreturn 1; // uno", $this->seen( 'comment' ) );
		$this->assertSame( self::ORIGINAL, file_get_contents( $this->repo . '/src/a.php' ), 'written back byte for byte' );
		$this->assertSame( '', $this->git( 'status --porcelain' ) );
		$this->assertStringContainsString( 'The working tree is as it was at the start.', $run['out'] );
	}

	public function test_all_caught_is_a_success(): void {
		$run = $this->mutate(
			array(
				array(
					'name' => 'two',
					'file' => 'src/a.php',
					'old'  => 'return 1;',
					'new'  => 'return 2;',
				),
			)
		);
		$this->assertSame( 0, $run['code'], $run['out'] );
		$this->assertSame( self::ORIGINAL, file_get_contents( $this->repo . '/src/a.php' ) );
	}

	public function test_it_refuses_a_tree_that_is_not_clean_and_leaves_it_as_it_is(): void {
		$uncommitted = "<?php\nreturn 1; // work not committed yet";
		file_put_contents( $this->repo . '/src/a.php', $uncommitted );
		$run = $this->mutate(
			array(
				array(
					'name' => 'two',
					'file' => 'src/a.php',
					'old'  => 'return 1;',
					'new'  => 'return 2;',
				),
			)
		);
		$this->assertSame( 2, $run['code'], $run['out'] );
		$this->assertStringContainsString( 'The working tree is not clean', $run['out'] );
		$this->assertSame( $uncommitted, file_get_contents( $this->repo . '/src/a.php' ), 'the uncommitted work is still there' );
		$this->assertNull( $this->seen( 'two' ), 'nothing ran' );
	}

	public function test_a_mutation_not_found_once_or_failing_php_l_is_not_run_and_one_with_no_log_says_so(): void {
		$run = $this->mutate(
			array(
				array(
					'name' => 'missing',
					'file' => 'src/a.php',
					'old'  => 'return 3;',
					'new'  => 'return 4;',
				),
				array(
					'name' => 'broken',
					'file' => 'src/a.php',
					'old'  => 'return 1;',
					'new'  => 'return 1',
				),
				array(
					'name' => 'nolog',
					'file' => 'src/a.php',
					'old'  => 'return 1;',
					'new'  => 'return 2;',
				),
			)
		);
		$this->assertSame( 1, $run['code'], $run['out'] );
		$this->assertStringContainsString( 'missing: not run: its text is found 0 times, not once', $run['out'] );
		$this->assertStringContainsString( 'broken: not run: php -l fails', $run['out'] );
		$this->assertNull( $this->seen( 'missing' ) );
		$this->assertNull( $this->seen( 'broken' ) );
		$this->assertStringContainsString( 'nolog: no result: the command left no JUnit log', $run['out'] );
		$this->assertNotNull( $this->seen( 'nolog' ), 'the control: the stand-in ran for a mutation that was run' );
		$this->assertSame( self::ORIGINAL, file_get_contents( $this->repo . '/src/a.php' ) );
		$this->assertSame( '', $this->git( 'status --porcelain' ) );
	}
}
