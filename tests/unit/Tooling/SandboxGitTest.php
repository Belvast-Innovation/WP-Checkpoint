<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use WPCheckpoint\Tests\Fixtures\SandboxGit;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * git in tests acts in a sandbox's repository or not at all (SandboxGit): a directory outside every sandbox, a
 * temporary directory moved elsewhere, a repository named by the environment, a directory whose repository is
 * elsewhere and an "init" where a repository is found already are each refused before any command runs, and the
 * repository outside is byte for byte as it was. The global and the system configuration are not the machine's.
 */
final class SandboxGitTest extends TestCase {

	/** @var string The sandbox; '' before set_up() made it. */
	private $sandbox = '';

	/** @var string A directory under the temporary directory that no sandbox holds; '' before set_up() made it. */
	private $outside = '';

	/** @var array<string, string|false> Environment variables to put back. */
	private $env = array();

	protected function set_up(): void {
		parent::set_up();
		if ( ! SandboxGit::available() ) {
			$this->markTestSkipped( 'No git here.' );
		}
		$this->sandbox = Sandbox::make( 'sandbox-git' );
		mkdir( $this->sandbox . '/repo' );
		file_put_contents( $this->sandbox . '/repo/a.txt', 'a' );
		$this->ok( $this->sandbox . '/repo', 'init -q -b main' );
		$this->ok( $this->sandbox . '/repo', 'add a.txt' );
		$this->ok( $this->sandbox . '/repo', 'commit -q -m first' );
		// A repository no sandbox holds: a copy of the sandbox's, in a directory made by hand.
		$this->outside = rtrim( (string) realpath( sys_get_temp_dir() ), '/\\' ) . DIRECTORY_SEPARATOR . 'wpc-outside-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->outside );
		self::copy_tree( $this->sandbox . '/repo', $this->outside . '/repo' );
	}

	protected function tear_down(): void {
		foreach ( $this->env as $name => $value ) {
			putenv( false === $value ? $name : $name . '=' . $value );
		}
		foreach ( array( $this->sandbox, $this->outside ) as $dir ) {
			if ( '' !== $dir ) {
				Sandbox::remove( $dir );
			}
		}
		parent::tear_down();
	}

	private function set_env( string $name, string $value ): void {
		if ( ! array_key_exists( $name, $this->env ) ) {
			$this->env[ $name ] = getenv( $name );
		}
		putenv( $name . '=' . $value );
	}

	private function ok( string $dir, string $args ): string {
		$run = SandboxGit::run( $dir, $args );
		$this->assertSame( 0, $run['code'], 'git ' . $args . ': ' . $run['stdout'] . $run['stderr'] );
		return trim( $run['stdout'] );
	}

	private static function copy_tree( string $from, string $to ): void {
		mkdir( $to );
		foreach ( (array) scandir( $from ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( is_dir( $from . '/' . $entry ) ) {
				self::copy_tree( $from . '/' . $entry, $to . '/' . $entry );
			} else {
				copy( $from . '/' . $entry, $to . '/' . $entry );
			}
		}
	}

	/**
	 * Every file of a directory tree with its content, hashed: byte for byte what is there.
	 */
	private static function bytes( string $dir ): string {
		$out = array();
		$it  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $file ) {
			$out[ substr( $file->getPathname(), strlen( $dir ) ) ] = $file->isDir() ? 'dir' : hash_file( 'sha256', $file->getPathname() );
		}
		ksort( $out );
		return (string) json_encode( $out );
	}

	/**
	 * Run a command that must be refused, and return why.
	 */
	private function refused( string $dir, string $args ): string {
		try {
			SandboxGit::run( $dir, $args );
		} catch ( \LogicException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'git ' . $args . ' was run in ' . $dir );
	}

	public function test_a_command_runs_in_a_sandboxs_repository_with_an_identity_written_nowhere(): void {
		$repo = $this->sandbox . '/repo';
		$this->assertSame( 'T <t@example.invalid>', $this->ok( $repo, 'log -1 --format=%an\\ \\<%ae\\>' ) );
		$this->assertStringNotContainsString( '[user]', (string) file_get_contents( $repo . '/.git/config' ), 'the identity is not in the repository\'s configuration' );
		file_put_contents( $repo . '/src.txt', 'b' );
		mkdir( $repo . '/deep/er', 0755, true );
		$this->ok( $repo . '/deep/er', 'add ../../src.txt' );
		$this->ok( $repo . '/deep/er', 'commit -q -m second' );
		$this->assertSame( '2', $this->ok( $repo, 'rev-list --count HEAD' ), 'from a directory below the repository\'s' );
		$failed = SandboxGit::run( $repo, 'show no-such-revision' );
		$this->assertNotSame( 0, $failed['code'], 'a command git fails is not a refusal: its status and its words come back' );
		$this->assertStringContainsString( 'no-such-revision', $failed['stderr'] );
	}

	public function test_a_directory_outside_every_sandbox_is_refused_and_its_repository_is_as_it_was(): void {
		$before = self::bytes( $this->outside );
		foreach ( array( 'commit -q --allow-empty -m stray', 'config user.name Stray', 'init -q', 'status' ) as $args ) {
			$this->assertStringContainsString( 'is not a directory in a sandbox this process made', $this->refused( $this->outside . '/repo', $args ), $args );
		}
		$this->assertStringContainsString( 'is not a directory in a sandbox', $this->refused( dirname( __DIR__, 3 ), 'status' ), 'this repository' );
		$this->assertStringContainsString( 'is not a directory in a sandbox', $this->refused( '', 'status' ), 'no directory' );
		$this->assertStringContainsString( 'is not a directory in a sandbox', $this->refused( $this->sandbox . '/not-there', 'status' ) );
		$this->assertSame( $before, self::bytes( $this->outside ) );
	}

	public function test_a_temporary_directory_set_elsewhere_makes_no_sandbox_of_what_is_there(): void {
		$before = self::bytes( $this->outside );
		// In a process of its own, whose temporary directory is the directory outside (PHP reads TMPDIR as it starts).
		$code = 'require ' . var_export( dirname( __DIR__, 3 ) . '/vendor/autoload.php', true ) . ';'
			. '$out = array( "tmp" => realpath( sys_get_temp_dir() ) );'
			. 'try { \\WPCheckpoint\\Tests\\Fixtures\\SandboxGit::run( $argv[1], "commit -q --allow-empty -m stray" ); $out["ran"] = true; }'
			. 'catch ( \\LogicException $e ) { $out["refused"] = $e->getMessage(); }'
			. 'echo json_encode( $out );';
		$run  = SandboxGit::command( $this->sandbox, array( PHP_BINARY, '-r', $code, $this->outside . '/repo' ), array( 'TMPDIR' => $this->outside ) );
		$this->assertSame( 0, $run['code'], $run['stdout'] . $run['stderr'] );
		$said = (array) json_decode( $run['stdout'], true );
		if ( ( $said['tmp'] ?? '' ) !== $this->outside ) {
			$this->markTestSkipped( 'The temporary directory does not follow TMPDIR here.' );
		}
		$this->assertArrayNotHasKey( 'ran', $said );
		$this->assertStringContainsString( 'is not a directory in a sandbox this process made', (string) ( $said['refused'] ?? '' ), 'under the temporary directory, and in no sandbox' );
		$this->assertSame( $before, self::bytes( $this->outside ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_variables_that_name_a_repository(): array {
		return array(
			'GIT_DIR'        => array( 'GIT_DIR', '/repo/.git' ),
			'GIT_WORK_TREE'  => array( 'GIT_WORK_TREE', '/repo' ),
			'GIT_INDEX_FILE' => array( 'GIT_INDEX_FILE', '/repo/.git/index' ),
			'GIT_COMMON_DIR' => array( 'GIT_COMMON_DIR', '/repo/.git' ),
		);
	}

	/**
	 * @dataProvider data_variables_that_name_a_repository
	 */
	public function test_a_repository_named_by_the_environment_refuses_every_command( string $name, string $where ): void {
		$before = self::bytes( $this->outside );
		$inside = self::bytes( $this->sandbox . '/repo' );
		$this->set_env( $name, $this->outside . $where );
		$this->assertStringContainsString( $name . ' is set in the environment of the tests', $this->refused( $this->sandbox . '/repo', 'commit -q --allow-empty -m stray' ) );
		try {
			SandboxGit::command( $this->sandbox . '/repo', array( 'git', 'commit', '-q', '--allow-empty', '-m', 'stray' ) );
			$this->fail( 'a command was started' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( $name, $e->getMessage() );
		}
		$this->assertSame( $before, self::bytes( $this->outside ), 'the repository the environment names' );
		$this->assertSame( $inside, self::bytes( $this->sandbox . '/repo' ), 'and the sandbox\'s' );
		// The bootstrap's removal takes it away again, and the command runs.
		$this->assertContains( $name, SandboxGit::forget_inherited() );
		$this->assertFalse( getenv( $name ) );
		$this->ok( $this->sandbox . '/repo', 'commit -q --allow-empty -m meant' );
		$this->assertSame( $before, self::bytes( $this->outside ) );
	}

	public function test_a_directory_whose_repository_is_outside_the_sandbox_is_refused(): void {
		$before = self::bytes( $this->outside );
		// A directory of the sandbox that git reads as a working tree of the repository outside (as a linked worktree's is).
		mkdir( $this->sandbox . '/pointing' );
		file_put_contents( $this->sandbox . '/pointing/.git', 'gitdir: ' . $this->outside . "/repo/.git\n" );
		$why = $this->refused( $this->sandbox . '/pointing', 'commit -q --allow-empty -m stray' );
		$this->assertStringContainsString( 'the repository git finds from', $why );
		$this->assertStringContainsString( 'is not inside the sandbox', $why );
		$this->assertSame( $before, self::bytes( $this->outside ) );
		// And a directory of the sandbox with no repository at all: nothing is found, nothing is run.
		mkdir( $this->sandbox . '/plain' );
		$this->assertStringContainsString( 'none found', $this->refused( $this->sandbox . '/plain', 'status' ) );
	}

	public function test_init_is_refused_where_git_finds_a_repository_already(): void {
		$repo   = $this->sandbox . '/repo';
		$inside = self::bytes( $repo );
		mkdir( $repo . '/sub' );
		$inside = self::bytes( $repo );
		$this->assertStringContainsString( 'git finds a repository from', $this->refused( $repo . '/sub', 'init -q' ) );
		$this->assertStringContainsString( 'git finds a repository from', $this->refused( $repo, 'init -q --bare' ) );
		$this->assertSame( $inside, self::bytes( $repo ) );
		// Through a pointer to the repository outside, too.
		$before = self::bytes( $this->outside );
		mkdir( $this->sandbox . '/pointing' );
		file_put_contents( $this->sandbox . '/pointing/.git', 'gitdir: ' . $this->outside . "/repo/.git\n" );
		$this->assertStringContainsString( 'git finds a repository from', $this->refused( $this->sandbox . '/pointing', 'init -q --bare' ) );
		$this->assertSame( $before, self::bytes( $this->outside ) );
	}

	public function test_options_before_the_command_are_refused(): void {
		$before = self::bytes( $this->outside );
		foreach ( array( '-C ' . escapeshellarg( $this->outside . '/repo' ) . ' commit -q --allow-empty -m stray', '--git-dir=' . escapeshellarg( $this->outside . '/repo/.git' ) . ' config user.name Stray', '', ' status' ) as $args ) {
			$this->assertStringContainsString( 'the arguments start with the git command', $this->refused( $this->sandbox . '/repo', $args ), $args );
		}
		$this->assertSame( $before, self::bytes( $this->outside ) );
	}

	public function test_the_global_configuration_is_the_sandboxs_not_the_machines(): void {
		$repo = $this->sandbox . '/repo';
		// A home as a developer's may be: hooks from another place, commits signed with a key that is not there,
		// another identity. None of it reaches a command.
		$home = $this->sandbox . '/a-home';
		mkdir( $home . '/hooks', 0755, true );
		file_put_contents( $home . '/hooks/pre-commit', "#!/bin/sh\necho ran > " . escapeshellarg( $this->sandbox . '/global-hook-ran' ) . "\nexit 1\n" );
		chmod( $home . '/hooks/pre-commit', 0755 );
		file_put_contents( $home . '/.gitconfig', "[core]\n\thooksPath = {$home}/hooks\n[commit]\n\tgpgsign = true\n[user]\n\tname = Somebody\n\temail = somebody@example.invalid\n\tsigningkey = 0000000000000000\n" );
		mkdir( $home . '/xdg/git', 0755, true );
		copy( $home . '/.gitconfig', $home . '/xdg/git/config' );
		$real = self::bytes( $home );
		$this->set_env( 'HOME', $home );
		$this->set_env( 'XDG_CONFIG_HOME', $home . '/xdg' );
		$this->ok( $repo, 'commit -q --allow-empty -m unsigned' );
		$this->assertFileDoesNotExist( $this->sandbox . '/global-hook-ran', 'the hooks of the home are not run' );
		$this->assertSame( 'T N', $this->ok( $repo, 'log -1 --format=%an\\ %G?' ), 'our identity, not signed' );
		// A hook of the repository itself still runs: the control for "no hook ran".
		file_put_contents( $repo . '/.git/hooks/pre-commit', "#!/bin/sh\necho ran > " . escapeshellarg( $this->sandbox . '/own-hook-ran' ) . "\n" );
		chmod( $repo . '/.git/hooks/pre-commit', 0755 );
		$this->ok( $repo, 'commit -q --allow-empty -m hooked' );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this->assertFileExists( $this->sandbox . '/own-hook-ran' );
		}
		// Written "globally", a value lands in the sandbox's file and nowhere in the home.
		$this->ok( $repo, 'config --global wpcheckpoint.probe yes' );
		$this->assertSame( $real, self::bytes( $home ), 'the home is as it was' );
		$this->assertStringContainsString( 'probe = yes', (string) file_get_contents( $this->sandbox . '/.sandbox-git-home/gitconfig' ), 'the control: it was written, to the sandbox' );
		$this->assertSame( 'yes', $this->ok( $repo, 'config --global --get wpcheckpoint.probe' ) );
	}

	public function test_a_command_started_for_a_script_gets_the_same_environment_and_no_repository(): void {
		$repo = $this->sandbox . '/repo';
		$this->set_env( 'GIT_AUTHOR_NAME', 'Inherited' );
		$this->set_env( 'WPCHECKPOINT_NOT_PASSED', 'x' );
		$run = SandboxGit::command( $repo, array( PHP_BINARY, '-r', 'echo json_encode( getenv() );' ), array( 'MUTATION' => 'm' ) );
		$this->assertSame( 0, $run['code'], $run['stderr'] );
		$env = (array) json_decode( $run['stdout'], true );
		$this->assertSame( 'm', $env['MUTATION'] ?? null );
		$this->assertSame( $this->sandbox, $env['GIT_CEILING_DIRECTORIES'] ?? null );
		$this->assertSame( '1', $env['GIT_CONFIG_NOSYSTEM'] ?? null );
		$this->assertSame( ExpectedPath::native( $this->sandbox, '.sandbox-git-home/gitconfig' ), $env['GIT_CONFIG_GLOBAL'] ?? null );
		$this->assertSame( ExpectedPath::native( $this->sandbox, '.sandbox-git-home/xdg' ), $env['XDG_CONFIG_HOME'] ?? null );
		$this->assertSame( '0', $env['GIT_TERMINAL_PROMPT'] ?? null );
		$this->assertSame( (string) getenv( 'PATH' ), $env['PATH'] ?? null );
		$this->assertSame( ExpectedPath::native( $this->sandbox, '.sandbox-git-home' ), $env['HOME'] ?? null );
		$this->assertArrayNotHasKey( 'GIT_AUTHOR_NAME', $env, 'nothing of this process\'s environment but PATH' );
		$this->assertArrayNotHasKey( 'WPCHECKPOINT_NOT_PASSED', $env );
		foreach ( SandboxGit::NAMING as $name ) {
			$this->assertArrayNotHasKey( $name, $env );
			try {
				SandboxGit::command( $repo, array( PHP_BINARY, '-r', 'exit( 0 );' ), array( $name => $this->outside . '/repo/.git' ) );
				$this->fail( $name . ' was given to a command' );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( $name . ' is not given to a command', $e->getMessage() );
			}
		}
		try {
			SandboxGit::command( $this->outside . '/repo', array( PHP_BINARY, '-r', 'exit( 0 );' ) );
			$this->fail( 'a command was started outside every sandbox' );
		} catch ( \LogicException $e ) {
			$this->assertStringContainsString( 'is not a directory in a sandbox', $e->getMessage() );
		}
	}

	public function test_the_sandbox_a_directory_is_in_is_one_this_process_made(): void {
		$this->assertSame( $this->sandbox, Sandbox::root_of( $this->sandbox ) );
		$this->assertSame( $this->sandbox, Sandbox::root_of( $this->sandbox . '/repo/.git' ) );
		$this->assertNull( Sandbox::root_of( $this->outside ) );
		mkdir( $this->sandbox . '-not' );
		$this->assertNull( Sandbox::root_of( $this->sandbox . '-not' ), 'a directory whose name only starts alike' );
		Sandbox::remove( $this->sandbox . '-not' );
		$this->assertNull( Sandbox::root_of( dirname( $this->sandbox ) ), 'the directory the sandbox is in' );
		$this->assertNull( Sandbox::root_of( $this->sandbox . '/repo/a.txt' ), 'a file' );
		$this->assertNull( Sandbox::root_of( '' ) );
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			symlink( $this->outside, $this->sandbox . '/way-out' );
			$this->assertNull( Sandbox::root_of( $this->sandbox . '/way-out/repo' ), 'through a link out of the sandbox: judged by where it is' );
			$this->assertStringContainsString( 'is not a directory in a sandbox', $this->refused( $this->sandbox . '/way-out/repo', 'status' ) );
		}
	}
}
