<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * bin/worktree.sh in a repository of its own in the sandbox: vendor/ copied (never a link), the docs links made again,
 * every one of them ignored, the worktree clean; an existing path or branch refused.
 */
final class WorktreeScriptTest extends TestCase {

	/** @var string The sandbox; '' before set_up() made it. */
	private $sandbox = '';

	/** @var string The repository in the sandbox. */
	private $repo = '';

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'A bash script for the development machine.' );
		}
		foreach ( array( 'bash', 'git' ) as $tool ) {
			if ( '' === trim( (string) shell_exec( 'command -v ' . $tool ) ) ) {
				$this->markTestSkipped( 'No ' . $tool . ' here.' );
			}
		}
		$this->sandbox = Sandbox::make( 'worktree' );
		$this->repo    = $this->sandbox . '/repo';
		mkdir( $this->repo . '/bin', 0755, true );
		mkdir( $this->repo . '/vendor/composer', 0755, true );
		mkdir( $this->sandbox . '/docs-repo/docs', 0755, true );
		copy( dirname( __DIR__, 3 ) . '/bin/worktree.sh', $this->repo . '/bin/worktree.sh' );
		file_put_contents( $this->repo . '/.gitignore', "/vendor/\n/docs/\n/CLAUDE.md\n" );
		file_put_contents( $this->repo . '/vendor/composer/autoload_real.php', '<?php // vendor' );
		file_put_contents( $this->sandbox . '/docs-repo/CLAUDE.md', '# notes' );
		symlink( $this->sandbox . '/docs-repo/docs', $this->repo . '/docs' );
		symlink( $this->sandbox . '/docs-repo/CLAUDE.md', $this->repo . '/CLAUDE.md' );
		foreach ( array( 'init -q -b main', 'config user.email t@example.com', 'config user.name T', 'add .gitignore bin', 'commit -q -m start' ) as $command ) {
			$this->git( $this->repo, $command );
		}
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	private function git( string $dir, string $args ): string {
		$output = array();
		$code   = 0;
		exec( 'git -C ' . escapeshellarg( $dir ) . ' ' . $args . ' 2>&1', $output, $code );
		$this->assertSame( 0, $code, 'git ' . $args . ': ' . implode( "\n", $output ) );
		return implode( "\n", $output );
	}

	/**
	 * Run the script from the repository.
	 *
	 * @param string[] $args Arguments.
	 * @return array{code: int, stdout: string, stderr: string}
	 */
	private function run_script( array $args ): array {
		$pipes   = array();
		$process = proc_open( array_merge( array( 'bash', $this->repo . '/bin/worktree.sh' ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $this->repo, array( 'PATH' => (string) getenv( 'PATH' ), 'HOME' => $this->sandbox ) );
		$this->assertIsResource( $process );
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array(
			'code'   => proc_close( $process ),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}

	public function test_a_worktree_gets_a_copy_of_vendor_the_docs_links_and_a_clean_status(): void {
		$tree = $this->sandbox . '/tree';
		$run  = $this->run_script( array( $tree, 'wip/try' ) );
		$this->assertSame( 0, $run['code'], $run['stdout'] . $run['stderr'] );
		$this->assertSame( 'wip/try', $this->git( $tree, 'rev-parse --abbrev-ref HEAD' ) );
		$this->assertFalse( is_link( $tree . '/vendor' ), 'vendor/ is not a link' );
		$this->assertTrue( is_dir( $tree . '/vendor' ) );
		$this->assertSame( '<?php // vendor', file_get_contents( $tree . '/vendor/composer/autoload_real.php' ), 'its files copied' );
		$this->assertTrue( is_link( $this->repo . '/vendor' ) === false, 'the control: the main copy\'s vendor/ is a directory' );
		foreach ( array( 'docs', 'CLAUDE.md' ) as $name ) {
			$this->assertTrue( is_link( $tree . '/' . $name ), $name . ' is a link' );
			$this->assertSame( readlink( $this->repo . '/' . $name ), readlink( $tree . '/' . $name ), $name . ' points where the main copy\'s does' );
		}
		$this->assertSame( '', $this->git( $tree, 'status --porcelain' ), 'clean' );
		$exclude = (string) file_get_contents( $this->repo . '/.git/info/exclude' );
		$this->assertStringContainsString( "/docs\n", $exclude, 'a link named docs is not matched by /docs/: excluded' );
		$this->assertStringNotContainsString( "/vendor\n", $exclude, 'a copied vendor/ is ignored by /vendor/ already' );
		$this->assertSame( '', $this->git( $this->repo, 'status --porcelain' ), 'the main copy stays clean' );
	}

	public function test_an_existing_path_or_branch_is_refused(): void {
		$tree = $this->sandbox . '/tree';
		$this->assertSame( 0, $this->run_script( array( $tree, 'wip/one' ) )['code'], 'the control' );
		$again = $this->run_script( array( $tree, 'wip/two' ) );
		$this->assertSame( 1, $again['code'] );
		$this->assertStringContainsString( 'is there already', $again['stderr'] );
		$same = $this->run_script( array( $this->sandbox . '/other', 'wip/one' ) );
		$this->assertSame( 1, $same['code'] );
		$this->assertStringContainsString( 'the branch wip/one is there already', $same['stderr'] );
		$this->assertFalse( file_exists( $this->sandbox . '/other' ), 'nothing made' );
	}
}
