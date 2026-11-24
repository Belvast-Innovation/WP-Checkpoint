<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\Sandbox;
use WPCheckpoint\Tests\Fixtures\SandboxGit;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * bin/hooks/pre-push, installed by bin/install-hooks.sh: nothing is pushed that the gate has not passed, and the
 * repository the hook is of is left as it was.
 *
 * The repository is one in a sandbox, with a linked worktree and a bare repository to push to. Its bin/ holds this
 * working copy's hook and installer, and a gate of its own: one that notes each run and what it was given of git's
 * environment, and ends with the status the test wrote for it.
 */
final class PrePushHookTest extends TestCase {

	/** @var string The sandbox; '' before set_up() made it. */
	private $sandbox = '';

	/** @var string The repository's own working tree. */
	private $repo = '';

	/** @var string Its linked worktree. */
	private $tree = '';

	/** @var string The repository pushed to. */
	private $remote = '';

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY || ! SandboxGit::available() ) {
			$this->markTestSkipped( 'Needs git and a POSIX shell.' );
		}
		$this->sandbox = Sandbox::make( 'pre-push' );
		$this->repo    = $this->sandbox . '/outer';
		$this->tree    = $this->sandbox . '/tree';
		$this->remote  = $this->sandbox . '/remote.git';
		$plugin        = dirname( __DIR__, 3 );
		mkdir( $this->repo . '/bin/hooks', 0777, true );
		mkdir( $this->remote );
		copy( $plugin . '/bin/hooks/pre-push', $this->repo . '/bin/hooks/pre-push' );
		copy( $plugin . '/bin/install-hooks.sh', $this->repo . '/bin/install-hooks.sh' );
		file_put_contents(
			$this->repo . '/bin/gate.sh',
			"#!/usr/bin/env bash\n"
			. '{ echo "ran in $(pwd)"; env | grep "^GIT_"; echo "PATH=${PATH:+set}"; } >> ' . escapeshellarg( $this->sandbox . '/gate.ran' ) . "\n"
			. 'exit "$(cat ' . escapeshellarg( $this->sandbox . '/gate.status' ) . " 2>/dev/null || echo 0)\"\n"
		);
		chmod( $this->repo . '/bin/gate.sh', 0755 );
		chmod( $this->repo . '/bin/hooks/pre-push', 0755 );
		$this->git( $this->repo, 'init -q -b main' );
		$this->git( $this->repo, 'add bin' );
		$this->git( $this->repo, 'commit -q -m first' );
		$this->git( $this->remote, 'init -q --bare' );
		$this->git( $this->repo, 'worktree add -q -b pushed ' . escapeshellarg( $this->tree ) );
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
	 * Run the installer of a working tree.
	 *
	 * @param string   $dir  Working tree.
	 * @param string[] $args Arguments.
	 * @return array{code: int, stdout: string, stderr: string}
	 */
	private function installer( string $dir, array $args = array() ): array {
		return SandboxGit::command( $dir, array_merge( array( 'bash', $dir . '/bin/install-hooks.sh' ), $args ) );
	}

	private function install(): void {
		$run = $this->installer( $this->repo );
		$this->assertSame( 0, $run['code'], $run['stdout'] . $run['stderr'] );
	}

	/**
	 * Push, without sending anything unless told to.
	 *
	 * @param string $from    Working tree.
	 * @param string $refspec What to push.
	 * @param bool   $really  Whether the push is made (else a dry run: the hook runs all the same).
	 * @return array{code: int, stdout: string, stderr: string}
	 */
	private function push( string $from, string $refspec, bool $really = false ): array {
		return SandboxGit::run( $from, 'push ' . ( $really ? '' : '--dry-run ' ) . escapeshellarg( $this->remote ) . ' ' . $refspec );
	}

	/**
	 * How often the gate ran.
	 */
	private function runs(): int {
		$noted = $this->sandbox . '/gate.ran';
		return is_file( $noted ) ? substr_count( (string) file_get_contents( $noted ), 'ran in ' ) : 0;
	}

	/**
	 * The trees the hook noted as passed.
	 *
	 * @return string[]
	 */
	private function passed(): array {
		$noted = $this->repo . '/.git/wpcheckpoint-gate-passed';
		return is_file( $noted ) ? array_values( array_filter( explode( "\n", (string) file_get_contents( $noted ) ) ) ) : array();
	}

	/**
	 * What the repository holds that a stray git command changes: what git says of its working trees and
	 * references, and every file of its directory but the objects, the logs and the hook's own note of passed trees.
	 *
	 * @return array<string, string>
	 */
	private function state(): array {
		$out = array();
		foreach ( array( $this->repo, $this->tree ) as $tree ) {
			$said                                    = SandboxGit::run( $tree, 'status --porcelain --branch' );
			$out[ 'status of ' . basename( $tree ) ] = 'exit ' . $said['code'] . "\n" . $said['stdout'] . $said['stderr'];
		}
		$said              = SandboxGit::run( $this->repo, 'for-each-ref' );
		$out['references'] = 'exit ' . $said['code'] . "\n" . $said['stdout'] . $said['stderr'];
		$git               = $this->repo . '/.git';
		$out['config']     = (string) file_get_contents( $git . '/config' );
		$files             = array();
		$it                = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $git, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $file ) {
			$relative = substr( $file->getPathname(), strlen( $git ) + 1 );
			if ( 1 !== preg_match( '#\A(?:objects|logs)(?:/|\z)|\Aworktrees/[^/]+/logs(?:/|\z)|\Awpcheckpoint-gate-passed\z#', $relative ) ) {
				$files[] = $relative . ' ' . ( $file->isDir() ? 'dir' : hash_file( 'sha256', $file->getPathname() ) );
			}
		}
		sort( $files );
		$out['files'] = implode( "\n", $files );
		return $out;
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function ways_to_push(): array {
		return array(
			'from the repository\'s own directory' => array( 'outer', 'HEAD:refs/heads/main' ),
			'from a linked worktree'               => array( 'tree', 'HEAD:refs/heads/pushed' ),
		);
	}

	/**
	 * @dataProvider ways_to_push
	 */
	public function test_a_tree_the_gate_passes_is_pushed_and_noted_and_not_checked_again( string $where, string $refspec ): void {
		$from = $this->sandbox . '/' . $where;
		$this->install();
		$this->state(); // Once before the one compared: the first status settles the indexes.
		$before = $this->state();
		$this->assertStringContainsString( 'worktrees/tree/index ', $before['files'], 'the control: the linked worktree\'s files are among the ones compared' );
		$this->assertStringContainsString( 'hooks/pre-push ', $before['files'], 'the hook is installed' );

		$first = $this->push( $from, $refspec );
		$this->assertSame( 0, $first['code'], $first['stdout'] . $first['stderr'] );
		$this->assertSame( 1, $this->runs(), 'the gate ran' );
		$noted = (string) file_get_contents( $this->sandbox . '/gate.ran' );
		$this->assertStringContainsString( 'ran in ' . realpath( $from ) . "\n", $noted, 'in the working tree pushed from' );
		$this->assertStringContainsString( "PATH=set\n", $noted, 'the control: the gate notes what it is given' );
		$this->assertStringNotContainsString( 'GIT_', $noted, 'nothing of git\'s environment reaches the gate' );
		$this->assertSame( array( $this->git( $from, 'rev-parse HEAD^{tree}' ) ), $this->passed(), 'the tree is noted as passed' );

		$again = $this->push( $from, $refspec );
		$this->assertSame( 0, $again['code'], $again['stdout'] . $again['stderr'] );
		$this->assertStringContainsString( 'the gate has passed this tree', $again['stderr'] );
		$this->assertSame( 1, $this->runs(), 'the same tree is not checked again' );
		$this->assertCount( 1, $this->passed() );

		$after = $this->state();
		foreach ( $before as $what => $was ) {
			$this->assertSame( $was, $after[ $what ], 'the repository\'s ' . $what . ' after its hook ran' );
		}
	}

	public function test_a_working_tree_that_is_not_clean_is_not_pushed(): void {
		$this->install();
		file_put_contents( $this->tree . '/left.txt', 'not committed' );
		$refused = $this->push( $this->tree, 'HEAD:refs/heads/pushed' );
		$this->assertNotSame( 0, $refused['code'], $refused['stdout'] . $refused['stderr'] );
		$this->assertStringContainsString( 'the working tree is not clean', $refused['stderr'] );
		$this->assertSame( 0, $this->runs(), 'the gate was not run' );
		$this->assertSame( array(), $this->passed() );
		// The control: committed, the same push runs the gate and is let through.
		$this->git( $this->tree, 'add left.txt' );
		$this->git( $this->tree, 'commit -q -m second' );
		$let = $this->push( $this->tree, 'HEAD:refs/heads/pushed' );
		$this->assertSame( 0, $let['code'], $let['stdout'] . $let['stderr'] );
		$this->assertSame( 1, $this->runs() );
	}

	public function test_a_commit_that_is_not_the_one_checked_out_is_not_pushed(): void {
		$this->install();
		file_put_contents( $this->tree . '/more.txt', 'a commit main does not have' );
		$this->git( $this->tree, 'add more.txt' );
		$this->git( $this->tree, 'commit -q -m second' );
		$refused = $this->push( $this->tree, 'main:refs/heads/main' );
		$this->assertNotSame( 0, $refused['code'], $refused['stdout'] . $refused['stderr'] );
		$this->assertStringContainsString( 'not the commit checked out here', $refused['stderr'] );
		$this->assertSame( 0, $this->runs(), 'the gate was not run: it would have checked another tree than the one pushed' );
		// The control: the commit checked out is pushed from the same place.
		$let = $this->push( $this->tree, 'HEAD:refs/heads/pushed' );
		$this->assertSame( 0, $let['code'], $let['stdout'] . $let['stderr'] );
		$this->assertSame( 1, $this->runs() );
	}

	public function test_a_gate_that_fails_stops_the_push_and_the_tree_is_not_noted(): void {
		$this->install();
		file_put_contents( $this->sandbox . '/gate.status', '3' );
		$refused = $this->push( $this->tree, 'HEAD:refs/heads/pushed', true );
		$this->assertNotSame( 0, $refused['code'], $refused['stdout'] . $refused['stderr'] );
		$this->assertStringContainsString( 'the gate failed (status 3); nothing was pushed', $refused['stderr'] );
		$this->assertSame( 1, $this->runs(), 'the control: the gate ran' );
		$this->assertSame( array(), $this->passed(), 'a tree that failed is not noted as passed' );
		$this->assertSame( '', $this->git( $this->remote, 'for-each-ref' ), 'nothing reached the repository pushed to' );
		// The control: the same push with a gate that passes; the gate runs again and the commit arrives.
		file_put_contents( $this->sandbox . '/gate.status', '0' );
		$let = $this->push( $this->tree, 'HEAD:refs/heads/pushed', true );
		$this->assertSame( 0, $let['code'], $let['stdout'] . $let['stderr'] );
		$this->assertSame( 2, $this->runs() );
		$this->assertStringContainsString( 'refs/heads/pushed', $this->git( $this->remote, 'for-each-ref' ) );
	}

	public function test_a_push_that_only_deletes_is_let_through_without_the_gate(): void {
		$this->install();
		$made = $this->push( $this->tree, 'HEAD:refs/heads/gone', true );
		$this->assertSame( 0, $made['code'], $made['stdout'] . $made['stderr'] );
		$this->assertSame( 1, $this->runs(), 'the control: a push that sends a commit runs the gate' );
		// Neither clean nor passing now: a push that sent a commit would be refused.
		file_put_contents( $this->sandbox . '/gate.status', '3' );
		file_put_contents( $this->tree . '/left.txt', 'not committed' );
		$deleted = $this->push( $this->tree, ':refs/heads/gone', true );
		$this->assertSame( 0, $deleted['code'], $deleted['stdout'] . $deleted['stderr'] );
		$this->assertSame( 1, $this->runs() );
		$this->assertSame( '', $this->git( $this->remote, 'for-each-ref' ), 'the reference is gone' );
	}

	public function test_the_installer_serves_every_working_tree_and_says_whether_the_hook_is_the_one_of_now(): void {
		$installed = $this->repo . '/.git/hooks/pre-push';
		$source    = dirname( __DIR__, 3 ) . '/bin/hooks/pre-push';
		foreach ( array( $this->repo, $this->tree ) as $from ) {
			$this->assertSame( 1, $this->installer( $from, array( '--check' ) )['code'], 'not installed yet, as seen from ' . basename( $from ) );
		}
		// Installed from the linked worktree: into the repository's common directory, not the worktree's own.
		$run = $this->installer( $this->tree );
		$this->assertSame( 0, $run['code'], $run['stdout'] . $run['stderr'] );
		$this->assertFileEquals( $source, $installed );
		$this->assertTrue( is_executable( $installed ) );
		foreach ( array( $this->repo, $this->tree ) as $from ) {
			$check = $this->installer( $from, array( '--check' ) );
			$this->assertSame( 0, $check['code'], 'installed, as seen from ' . basename( $from ) );
			$this->assertSame( '', $check['stdout'] . $check['stderr'], 'the check says nothing' );
		}
		// An older hook of this plugin is replaced.
		file_put_contents( $installed, (string) file_get_contents( $source ) . "# an older version\n" );
		$this->assertSame( 1, $this->installer( $this->repo, array( '--check' ) )['code'], 'a hook that differs is not the one of now' );
		$run = $this->installer( $this->repo );
		$this->assertSame( 0, $run['code'], $run['stdout'] . $run['stderr'] );
		$this->assertFileEquals( $source, $installed );
		// A hook that is there without the executable bit is not one git runs.
		chmod( $installed, 0644 );
		$this->assertSame( 1, $this->installer( $this->repo, array( '--check' ) )['code'] );
		$run = $this->installer( $this->repo );
		$this->assertSame( 0, $run['code'], $run['stdout'] . $run['stderr'] );
		$this->assertTrue( is_executable( $installed ) );
		// Somebody else's hook is left as it is.
		$other = "#!/bin/sh\n# somebody else's\nexit 0\n";
		file_put_contents( $installed, $other );
		$run = $this->installer( $this->repo );
		$this->assertSame( 1, $run['code'], $run['stdout'] . $run['stderr'] );
		$this->assertStringContainsString( 'is not this plugin\'s hook', $run['stderr'] );
		$this->assertSame( $other, file_get_contents( $installed ) );
		// Not a working copy: neither installed nor "missing".
		$apart = $this->sandbox . '/apart';
		mkdir( $apart . '/bin/hooks', 0777, true );
		copy( $source, $apart . '/bin/hooks/pre-push' );
		copy( dirname( __DIR__, 3 ) . '/bin/install-hooks.sh', $apart . '/bin/install-hooks.sh' );
		$this->assertSame( 2, $this->installer( $apart, array( '--check' ) )['code'] );
		$this->assertSame( 2, $this->installer( $apart )['code'] );
	}

	public function test_the_gate_says_in_one_line_when_the_hook_is_missing_and_runs_all_the_same(): void {
		copy( dirname( __DIR__, 3 ) . '/bin/gate.sh', $this->repo . '/bin/real-gate.sh' );
		$gate = function (): array {
			return SandboxGit::command(
				$this->repo,
				array( 'bash', $this->repo . '/bin/real-gate.sh', 'echo step-ran' ),
				array( 'WPCHECKPOINT_TEST_OUTPUT' => $this->sandbox . '/out' )
			);
		};
		$hint = 'the pre-push hook is not installed, or is not bin/hooks/pre-push as it is now; run bin/install-hooks.sh';
		$run  = $gate();
		$this->assertSame( 0, $run['code'], 'only a hint: ' . $run['stdout'] . $run['stderr'] );
		$this->assertStringContainsString( $hint, $run['stderr'] );
		$this->assertStringContainsString( 'step 1 passed', $run['stdout'] );
		$this->install();
		$run = $gate();
		$this->assertSame( 0, $run['code'], $run['stdout'] . $run['stderr'] );
		$this->assertSame( '', $run['stderr'], 'installed: nothing is said' );
		$this->assertStringContainsString( 'step 1 passed', $run['stdout'], 'the control: the gate ran' );
	}
}
