<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Tests run git, and the scripts of bin/ that run git, only through Tests\Fixtures\SandboxGit, which decides
 * before a command runs that it acts in a sandbox's repository (the reason is in that class). The scan reports
 * every call that starts a program (exec(), shell_exec(), system(), passthru(), proc_open(), popen(),
 * pcntl_exec(), a backtick string) and names git, or one of those scripts, in a string of its arguments.
 *
 * Not seen: a command put together in a variable before the call (as the entry itself starts its commands, which
 * is why it is not reported), a function called by a name in a string or through a wrapper (array_map( 'exec',
 * … ), call_user_func(), a helper that calls exec()), and a program that runs git without the test naming it. The
 * list of scripts is kept by a search for git at the start of a command line of a script: one that runs git only
 * after "if", "then" or "exec", or only with options before the command, is not found and is added by hand. A new script of bin/ that
 * runs git is added to SCRIPTS (the last test here fails until it is).
 */
final class GitUsageTest extends TestCase {

	/**
	 * The scripts of bin/ that run git.
	 */
	const SCRIPTS = array( 'worktree.sh', 'mutate.php' );

	/**
	 * The lines of PHP source on which a program is started with git, or a script that runs git, named in a string.
	 *
	 * @return int[]
	 */
	private static function scan( string $source ): array {
		$tokens  = token_get_all( $source );
		$count   = count( $tokens );
		$lines   = array();
		// Not ".git" (a repository's directory) or a longer word; "/usr/bin/git" is git.
		$pattern = '/(?<![\w.-])git(?:\.exe)?(?![\w.-])|' . implode( '|', array_map( 'preg_quote', self::SCRIPTS ) ) . '/';
		$line    = 1;
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) ) {
				$line = $token[2];
			}
			// PHP 8 reads "\exec" as one token.
			$named  = is_array( $token ) && ( T_STRING === $token[0] || ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $token[0] ) );
			$starts = $named && in_array( strtolower( ltrim( $token[1], '\\' ) ), array( 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec' ), true );
			if ( $starts ) {
				// A call, not a method of that name or a declaration.
				$before = $i - 1;
				while ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_WHITESPACE, T_NS_SEPARATOR ), true ) ) {
					--$before;
				}
				$starts = ! ( $before >= 0 && is_array( $tokens[ $before ] ) && in_array( $tokens[ $before ][0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) );
			}
			if ( ! $starts && '`' !== $token ) {
				continue;
			}
			$at    = $line;
			$depth = 0;
			$found = false;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( '`' === $token ) {
					if ( '`' === $t ) {
						break;
					}
				} elseif ( '(' === $t ) {
					++$depth;
				} elseif ( ')' === $t ) {
					if ( 0 === --$depth ) {
						break;
					}
				}
				if ( is_array( $t ) && in_array( $t[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && 1 === preg_match( $pattern, $t[1] ) ) {
					$found = true;
				}
			}
			if ( $found ) {
				$lines[] = $at;
			}
			if ( '`' === $token ) {
				$i = $j;
			}
		}
		return $lines;
	}

	public function test_the_scan_finds_git_started_in_every_way_and_nothing_else(): void {
		$found = array(
			'exec( \'git -C \' . escapeshellarg( $dir ) . \' status\', $out, $code );',
			'exec( \'cd \' . escapeshellarg( $repo ) . \' && git -c user.name=t init -q 2>&1\', $out );',
			'$out = shell_exec( "git status" );',
			'$out = \shell_exec( \'command -v git\' );',
			'system( "cd $repo && git init" );',
			'passthru( \'git log\' );',
			'$p = proc_open( array( \'git\', \'init\' ), array(), $pipes, $dir );',
			'$p = proc_open( array( PHP_BINARY, $root . \'/bin/mutate.php\', $spec ), array(), $pipes );',
			'$p = proc_open( array( \'bash\', $repo . \'/bin/worktree.sh\' ), array(), $pipes );',
			'$h = popen( \'git.exe status\', \'r\' );',
			'exec( \'/usr/bin/git status\' );',
			'$p = proc_open( array( \'/usr/bin/git\', \'init\' ), array(), $pipes );',
			'exec( \'./git init\' );',
			'$out = `git status`;',
		);
		foreach ( $found as $code ) {
			$this->assertSame( array( 2 ), self::scan( "<?php\n" . $code ), $code );
		}
		$not = array(
			'$run = SandboxGit::run( $repo, \'commit -q -m start\' );',
			'$run = SandboxGit::command( $repo, array( \'git\', \'status\' ) );',
			'// exec( \'git status\' );',
			'$this->assertSame( 0, $code, \'git \' . $args . \' failed\' );',
			'exec( \'tar -xf \' . escapeshellarg( $dir . \'/.git-keep.tar\' ) );',
			'exec( \'ls \' . escapeshellarg( $repo . \'/.git/hooks\' ), $out );',
			'$this->runner->exec( \'git status\' );',
			'$out = shell_exec( \'command -v bash\' );',
			'file_put_contents( $hook, "#!/bin/sh\ngit status\n" );',
			'exec( \'digit --legit\' );',
		);
		foreach ( $not as $code ) {
			$this->assertSame( array(), self::scan( "<?php\n" . $code ), $code );
		}
	}

	public function test_tests_start_git_through_the_sandbox_entry_only(): void {
		$root  = dirname( __DIR__, 3 );
		$found = array();
		$files = 0;
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/tests', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() || __FILE__ === $file->getPathname() ) {
				continue;
			}
			++$files;
			$lines = self::scan( (string) file_get_contents( $file->getPathname() ) );
			if ( array() !== $lines ) {
				$found[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ) ] = count( $lines );
			}
		}
		$this->assertGreaterThan( 300, $files, 'the scan read the tests' );
		$this->assertSame( array(), $found, 'a test starts git, or a script that runs git, itself: use Tests\Fixtures\SandboxGit::run() or ::command()' );
		foreach ( self::SCRIPTS as $script ) {
			$this->assertFileExists( $root . '/bin/' . $script );
		}
	}

	/**
	 * The test classes that run git are run from a hook by GitInHookTest: one that is not on its list would not be
	 * shown to leave the hook's repository alone.
	 */
	public function test_every_test_class_that_runs_git_is_run_from_a_hook(): void {
		$root  = dirname( __DIR__, 3 );
		$using = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/tests', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$name = $file->getBasename( '.php' );
			// But the proof itself (it would start itself) and the entry's own test (which names repositories to the environment on purpose).
			if ( 'php' === $file->getExtension() && 'Test' === substr( $name, -4 ) && ! in_array( $name, array( 'GitInHookTest', 'SandboxGitTest', 'GitUsageTest' ), true ) && false !== strpos( (string) file_get_contents( $file->getPathname() ), 'SandboxGit::' ) ) {
				$using[] = $name;
			}
		}
		sort( $using );
		$listed = explode( '|', GitInHookTest::CLASSES );
		sort( $listed );
		$this->assertSame( $listed, $using );
	}

	/**
	 * The scripts of bin/ that run git are the ones listed: a new one is to be started through the entry too.
	 */
	public function test_the_scripts_that_run_git_are_the_listed_ones(): void {
		$root = dirname( __DIR__, 3 );
		$runs = array();
		foreach ( glob( $root . '/bin/*' ) ?: array() as $file ) {
			$text = (string) file_get_contents( $file );
			// A command line of git: at the start of a line or a command, after "$(" or in a quoted command.
			if ( 1 === preg_match( '/(?:^|[;&|(`\'"]|\$\()\s*git\s+(?:-C\s|[a-z])/m', (string) preg_replace( '/^\s*(?:#|\/\/|\*).*$/m', '', $text ) ) ) {
				$runs[] = basename( $file );
			}
		}
		sort( $runs );
		$listed = self::SCRIPTS;
		sort( $listed );
		$this->assertSame( $listed, $runs );
	}
}
