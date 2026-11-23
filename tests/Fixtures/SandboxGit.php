<?php

namespace WPCheckpoint\Tests\Fixtures;

/**
 * git in tests: only in a repository inside a sandbox, and only through this class (GitUsageTest).
 *
 * git takes the repository it acts on from its environment before it looks at the directory it runs in: with
 * GIT_DIR set (as git sets it for every hook it runs) a "git init", "git config" or "git commit" run in a test's
 * sandbox acts on the repository GIT_DIR names. On 2026-11-23 the unit suite, run from a pre-push hook, did that
 * to the repository it was run from. So, as with deleting (Sandbox), where a git command acts is decided here, by
 * positive evidence, before the command runs:
 *
 * - The directory is in a sandbox this process made (Sandbox::root_of(), by real paths; not "under the temporary
 *   directory", which is wherever TMPDIR says).
 * - This process's environment names no repository (GIT_DIR, GIT_WORK_TREE, GIT_INDEX_FILE, GIT_COMMON_DIR):
 *   tests/bootstrap.php removes every GIT_* variable at the start, so one that is there was put there since.
 * - The command gets an environment of its own, not this process's: PATH, a HOME and XDG_CONFIG_HOME in the
 *   sandbox, an empty global configuration (GIT_CONFIG_GLOBAL) and no system one (GIT_CONFIG_NOSYSTEM), no
 *   prompts, and GIT_CEILING_DIRECTORIES at the sandbox, so that no repository at or above it is found from a
 *   directory below it.
 * - Before the command, git itself is asked in that directory and environment where its repository is (git
 *   rev-parse --absolute-git-dir): for "init" there must be none; for anything else the answer must be inside
 *   the sandbox.
 *
 * Anything else is refused with a LogicException and no command is run. What is not judged: where a command's own
 * arguments point (a path given to "worktree add" or "push"); the tests give paths of their sandbox.
 */
final class SandboxGit {

	/**
	 * The variables by which the environment names the repository git acts on.
	 */
	const NAMING = array( 'GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR' );

	/**
	 * The identity commits are made with: given on the command line, written to no configuration.
	 */
	const IDENTITY = '-c user.name=T -c user.email=t@example.invalid';

	/**
	 * Whether git is here to run.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		foreach ( explode( PATH_SEPARATOR, (string) getenv( 'PATH' ) ) as $dir ) {
			foreach ( array( 'git', 'git.exe' ) as $name ) {
				if ( '' !== $dir && is_file( $dir . DIRECTORY_SEPARATOR . $name ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Remove every GIT_* variable from this process's environment (the test bootstrap, before any test): what the
	 * process that started the tests had (a hook's GIT_DIR) is not the tests'.
	 *
	 * @return string[] The names removed.
	 */
	public static function forget_inherited(): array {
		$names = array();
		foreach ( array_merge( array_keys( (array) getenv() ), array_keys( $_ENV ), array_keys( $_SERVER ) ) as $name ) {
			if ( is_string( $name ) && 0 === strpos( $name, 'GIT_' ) ) {
				$names[ $name ] = true;
			}
		}
		foreach ( array_keys( $names ) as $name ) {
			putenv( $name );
			unset( $_ENV[ $name ], $_SERVER[ $name ] );
		}
		return array_keys( $names );
	}

	/**
	 * Run a git command in a directory of a sandbox.
	 *
	 * @param string $dir  The directory to run in.
	 * @param string $args The command and its arguments, as a shell would take them ("commit -q -m start").
	 * @return array{code: int, stdout: string, stderr: string}
	 * @throws \LogicException When the command is refused (see the class); nothing was run.
	 */
	public static function run( string $dir, string $args ): array {
		if ( 1 !== preg_match( '/\A([a-z][a-z-]*)(?:\s|\z)/', $args, $m ) ) {
			throw new \LogicException( 'SandboxGit::run() refused: the arguments start with the git command, without options before it. Nothing was run.' );
		}
		$root  = self::root( $dir );
		$env   = self::environment( $root );
		$found = self::launch( 'git rev-parse --absolute-git-dir', $dir, $env );
		if ( 'init' === $m[1] ) {
			if ( 0 === $found['code'] ) {
				throw new \LogicException( sprintf( 'SandboxGit::run( init ) refused: git finds a repository from %s already (%s). Nothing was run.', $dir, trim( $found['stdout'] ) ) );
			}
		} else {
			$where = 0 === $found['code'] ? realpath( trim( $found['stdout'] ) ) : false;
			if ( false === $where || 0 !== strpos( $where . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR ) ) {
				throw new \LogicException( sprintf( 'SandboxGit::run( %s ) refused: the repository git finds from %s is not inside the sandbox (%s). Nothing was run.', $m[1], $dir, 0 === $found['code'] ? trim( $found['stdout'] ) : 'none found' ) );
			}
		}
		return self::launch( 'git ' . self::IDENTITY . ' ' . $args, $dir, $env );
	}

	/**
	 * Run a command that may call git (a script of bin/) in a directory of a sandbox, in the environment git
	 * commands get here.
	 *
	 * @param string                $dir     The directory to run in.
	 * @param string[]              $command The program and its arguments.
	 * @param array<string, string> $more    More environment variables (none that names a repository).
	 * @return array{code: int, stdout: string, stderr: string}
	 * @throws \LogicException When refused; nothing was run.
	 */
	public static function command( string $dir, array $command, array $more = array() ): array {
		$root = self::root( $dir );
		foreach ( array_keys( $more ) as $name ) {
			if ( 0 === strpos( (string) $name, 'GIT_' ) ) {
				throw new \LogicException( 'SandboxGit::command() refused: ' . $name . ' is not given to a command. Nothing was run.' );
			}
		}
		return self::launch( $command, $dir, $more + self::environment( $root ) );
	}

	/**
	 * The sandbox a directory is in, when this process's environment names no repository.
	 *
	 * @param string $dir Directory.
	 * @return string The sandbox's real path.
	 * @throws \LogicException When the directory is in no sandbox, or the environment names a repository.
	 */
	private static function root( string $dir ): string {
		foreach ( self::NAMING as $name ) {
			if ( false !== getenv( $name ) ) {
				throw new \LogicException( 'SandboxGit refused: ' . $name . ' is set in the environment of the tests, which names a repository for git whatever directory it runs in. Nothing was run.' );
			}
		}
		$root = Sandbox::root_of( $dir );
		if ( null === $root ) {
			throw new \LogicException( sprintf( 'SandboxGit refused: %s is not a directory in a sandbox this process made (Sandbox::make()). Nothing was run.', var_export( $dir, true ) ) );
		}
		return $root;
	}

	/**
	 * The environment of a command: nothing of this process's but PATH (and what Windows needs to start a program).
	 *
	 * @param string $root The sandbox.
	 * @return array<string, string>
	 * @throws \RuntimeException When the home in the sandbox cannot be made.
	 */
	private static function environment( string $root ): array {
		$home   = $root . DIRECTORY_SEPARATOR . '.sandbox-git-home';
		$global = $home . DIRECTORY_SEPARATOR . 'gitconfig';
		if ( ! is_dir( $home ) && ! mkdir( $home, 0700 ) && ! is_dir( $home ) ) {
			throw new \RuntimeException( 'The home for git in the sandbox could not be made.' );
		}
		if ( ! is_file( $global ) && false === file_put_contents( $global, '' ) ) {
			throw new \RuntimeException( 'The global configuration for git in the sandbox could not be made.' );
		}
		$env = array(
			'PATH'                    => (string) getenv( 'PATH' ),
			'HOME'                    => $home,
			'XDG_CONFIG_HOME'         => $home . DIRECTORY_SEPARATOR . 'xdg',
			'GIT_CONFIG_GLOBAL'       => $global,
			'GIT_CONFIG_NOSYSTEM'     => '1',
			'GIT_CEILING_DIRECTORIES' => $root,
			'GIT_TERMINAL_PROMPT'     => '0',
		);
		foreach ( array( 'SYSTEMROOT', 'SystemRoot', 'TEMP', 'TMP', 'PATHEXT', 'COMSPEC' ) as $name ) {
			if ( 'Windows' === PHP_OS_FAMILY && false !== getenv( $name ) ) {
				$env[ $name ] = (string) getenv( $name );
			}
		}
		return $env;
	}

	/**
	 * Start a program and read what it says, its two outputs apart.
	 *
	 * @param string|string[]       $command Command.
	 * @param string                $dir     Working directory.
	 * @param array<string, string> $env     Environment.
	 * @return array{code: int, stdout: string, stderr: string}
	 * @throws \RuntimeException When the program cannot be started.
	 */
	private static function launch( $command, string $dir, array $env ): array {
		$pipes   = array();
		$out     = tempnam( $env['HOME'], 'out' );
		$err     = tempnam( $env['HOME'], 'err' );
		// Files, not pipes: a program that fills one pipe while the other is read would wait for ever.
		$process = proc_open(
			$command,
			array(
				0 => array( 'file', 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null', 'r' ),
				1 => array( 'file', (string) $out, 'w' ),
				2 => array( 'file', (string) $err, 'w' ),
			),
			$pipes,
			$dir,
			$env
		);
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'The command could not be started.' );
		}
		$code = proc_close( $process );
		return array(
			'code'   => $code,
			'stdout' => (string) file_get_contents( (string) $out ),
			'stderr' => (string) file_get_contents( (string) $err ),
		);
	}
}
