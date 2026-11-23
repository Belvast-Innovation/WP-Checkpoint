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
	 * What a repository holds that a stray git command changes: what git says of its working trees and its
	 * references, and every file of its directory, byte for byte, but the objects and the logs (a command that
	 * only adds an object or a log line changes nothing a later command reads as the repository's state). The
	 * configuration, HEAD and the index are named apart, for a failure to say which.
	 *
	 * @param string   $repo  Repository.
	 * @param string[] $trees Its working trees (the repository's own and the linked ones).
	 * @return array<string, string>
	 */
	private function state( string $repo, array $trees ): array {
		// What git says first: a status may write the index's cached stat data, once.
		$out = array();
		foreach ( $trees as $tree ) {
			$out[ 'status of ' . basename( $tree ) ] = $this->said( $tree, 'status --porcelain --branch' );
		}
		$out['references'] = $this->said( $repo, 'for-each-ref' );
		$out['config']     = (string) file_get_contents( $repo . '/.git/config' );
		$out['HEAD']       = (string) file_get_contents( $repo . '/.git/HEAD' );
		$out['index']      = bin2hex( (string) file_get_contents( $repo . '/.git/index' ) );
		$files             = array();
		$it                = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $repo . '/.git', \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $file ) {
			$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $repo . '/.git/' ) ) );
			if ( 1 !== preg_match( '#\A(?:objects|logs)(?:/|\z)|\Aworktrees/[^/]+/logs(?:/|\z)#', $relative ) ) {
				$files[] = $relative . ' ' . ( $file->isDir() ? 'dir' : hash_file( 'sha256', $file->getPathname() ) );
			}
		}
		sort( $files );
		$out['files'] = implode( "\n", $files );
		return $out;
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
		$this->state( $repo, array( $repo, $tree ) ); // Once before the one compared: the first status settles the indexes.
		$before = $this->state( $repo, array( $repo, $tree ) );
		$this->assertStringContainsString( 'worktrees/tree/index ', $before['files'], 'the control: the linked worktree\'s files are among the ones compared' );
		$this->assertStringContainsString( 'hooks/pre-push ', $before['files'] );

		$push = SandboxGit::run( $tree, 'push --dry-run ' . escapeshellarg( $remote ) . ' HEAD:refs/heads/pushed' );
		$this->assertSame( 0, $push['code'], $push['stdout'] . $push['stderr'] );

		// The control: the hook ran, git had given it the repository by GIT_DIR, and the tests in it ran.
		$this->assertFileExists( $this->sandbox . '/hook.git-dir', 'the hook ran' );
		$this->assertNotSame( 'unset', trim( (string) file_get_contents( $this->sandbox . '/hook.git-dir' ) ), 'git names the repository to its hook by GIT_DIR' );
		$log = (string) file_get_contents( $this->sandbox . '/hook.log' );
		$this->assertMatchesRegularExpression( '/(?:OK \(|Tests: )\d+/', $log, 'the tests ran in the hook' );

		$after = $this->state( $repo, array( $repo, $tree ) );
		foreach ( $before as $what => $was ) {
			$this->assertSame( $was, $after[ $what ], 'the repository\'s ' . $what . ' after the tests ran in its hook' );
		}
		$this->assertSame( '0', trim( (string) file_get_contents( $this->sandbox . '/hook.status' ) ), "the tests passed in the hook:\n" . $log );
	}
}
