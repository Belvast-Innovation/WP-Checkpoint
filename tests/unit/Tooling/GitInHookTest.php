<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\Sandbox;
use WPCheckpoint\Tests\Fixtures\SandboxGit;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The tests that run git, started from a hook of another repository, leave that repository as it was.
 *
 * git runs a hook with GIT_DIR naming the repository the hook is of, and a test's "git init" or "git commit" in
 * its own sandbox then acts on that repository (2026-11-23: the unit suite, run from a pre-push hook, set
 * core.bare, wrote an identity and made commits in the repository it was run from). Here the repository is one
 * in a sandbox: its pre-push hook runs those tests of this working copy, and its configuration, references and
 * index are compared before and after, byte for byte. The push is made from a linked worktree of it: there git
 * sets GIT_DIR for the hook (from the repository's own directory it sets none, and git finds the repository from
 * the directory a command runs in).
 */
final class GitInHookTest extends TestCase {

	/**
	 * The test classes that run git, or a script that does.
	 */
	const CLASSES = 'MutateToolTest|WorktreeScriptTest';

	/** @var string The sandbox; '' before set_up() made it. */
	private $sandbox = '';

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY || ! SandboxGit::available() ) {
			$this->markTestSkipped( 'Needs git and a POSIX shell.' );
		}
		$this->sandbox = Sandbox::make( 'git-in-hook' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	private function git( string $dir, string $args ): string {
		$run = SandboxGit::run( $dir, $args );
		$this->assertSame( 0, $run['code'], 'git ' . $args . ': ' . $run['stdout'] . $run['stderr'] );
		return trim( $run['stdout'] );
	}

	/**
	 * What git says, with its exit status: compared, not asserted (a repository that was changed may refuse).
	 */
	private function said( string $dir, string $args ): string {
		$run = SandboxGit::run( $dir, $args );
		return 'exit ' . $run['code'] . "\n" . $run['stdout'] . $run['stderr'];
	}

	/**
	 * What a repository holds that a stray git command changes: its configuration, where every reference points,
	 * its index, and the names of everything in its directory but the objects and the logs.
	 *
	 * @return array<string, string>
	 */
	private function state( string $repo ): array {
		$names = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $repo . '/.git', \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $file ) {
			$relative = substr( $file->getPathname(), strlen( $repo . '/.git/' ) );
			if ( 0 !== strpos( $relative, 'objects' ) && 0 !== strpos( $relative, 'logs' ) ) {
				$names[] = $relative;
			}
		}
		sort( $names );
		return array(
			'config'     => (string) file_get_contents( $repo . '/.git/config' ),
			'HEAD'       => (string) file_get_contents( $repo . '/.git/HEAD' ),
			'references' => $this->said( $repo, 'for-each-ref' ),
			'index'      => bin2hex( (string) file_get_contents( $repo . '/.git/index' ) ),
			'names'      => implode( "\n", $names ),
			'status'     => $this->said( $repo, 'status --porcelain' ),
		);
	}

	public function test_the_tests_that_run_git_leave_the_repository_whose_hook_started_them_as_it_was(): void {
		$repo   = $this->sandbox . '/outer';
		$remote = $this->sandbox . '/remote.git';
		mkdir( $repo );
		mkdir( $remote );
		file_put_contents( $repo . '/kept.txt', 'the repository the hook is of' );
		$this->git( $repo, 'init -q -b main' );
		$this->git( $repo, 'add kept.txt' );
		$this->git( $repo, 'commit -q -m first' );
		$this->git( $remote, 'init -q --bare' );
		$tree = $this->sandbox . '/tree';
		$this->git( $repo, 'worktree add -q -b pushed ' . escapeshellarg( $tree ) );
		$plugin = dirname( __DIR__, 3 );
		// The hook: the tests of this working copy that run git, in the environment git gives a hook.
		file_put_contents(
			$repo . '/.git/hooks/pre-push',
			"#!/bin/sh\n"
			. 'echo "${GIT_DIR:-unset}" > ' . escapeshellarg( $this->sandbox . '/hook.git-dir' ) . "\n"
			. 'cd ' . escapeshellarg( $plugin ) . ' && WPCHECKPOINT_TEST_SUITE=unit ' . escapeshellarg( PHP_BINARY ) . ' vendor/bin/phpunit --testsuite unit --do-not-cache-result --filter ' . escapeshellarg( self::CLASSES ) . ' > ' . escapeshellarg( $this->sandbox . '/hook.log' ) . " 2>&1\n"
			. 'echo $? > ' . escapeshellarg( $this->sandbox . '/hook.status' ) . "\n"
			. "exit 0\n"
		);
		chmod( $repo . '/.git/hooks/pre-push', 0755 );
		$before = $this->state( $repo );
		$linked = (string) file_get_contents( $repo . '/.git/worktrees/tree/index' );

		$push = SandboxGit::run( $tree, 'push --dry-run ' . escapeshellarg( $remote ) . ' HEAD:refs/heads/pushed' );
		$this->assertSame( 0, $push['code'], $push['stdout'] . $push['stderr'] );

		// The control: the hook ran, git had given it the repository by GIT_DIR, and the tests in it ran.
		$this->assertFileExists( $this->sandbox . '/hook.git-dir', 'the hook ran' );
		$this->assertNotSame( 'unset', trim( (string) file_get_contents( $this->sandbox . '/hook.git-dir' ) ), 'git names the repository to its hook by GIT_DIR' );
		$log = (string) file_get_contents( $this->sandbox . '/hook.log' );
		$this->assertMatchesRegularExpression( '/(?:OK \(|Tests: )\d+/', $log, 'the tests ran in the hook' );

		$after = $this->state( $repo );
		foreach ( $before as $what => $was ) {
			$this->assertSame( $was, $after[ $what ], 'the repository\'s ' . $what . ' after the tests ran in its hook' );
		}
		$this->assertSame( bin2hex( $linked ), bin2hex( (string) file_get_contents( $repo . '/.git/worktrees/tree/index' ) ), 'the index of the worktree the push was made from' );
		$this->assertSame( '0', trim( (string) file_get_contents( $this->sandbox . '/hook.status' ) ), "the tests passed in the hook:\n" . $log );
	}
}
