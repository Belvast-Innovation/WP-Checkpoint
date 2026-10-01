<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * bin/build-dist.sh builds the package from the paths it lists and nothing else from the repository, then checks the
 * built tree with the same list: only those paths at the top, and nowhere a symbolic link, a vendor directory or a
 * name beginning with a dot. Each case runs a copy of the script in a repository of its own in the sandbox.
 */
final class BuildDistTest extends TestCase {

	/** @var string The sandbox; '' before set_up() made it. */
	private $sandbox = '';

	/** @var string The repository in the sandbox. */
	private $repo = '';

	protected function set_up(): void {
		parent::set_up();
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'A bash script; the package is built on Linux (CI) and in the wp-env container.' );
		}
		foreach ( array( 'bash', 'rsync', 'find' ) as $tool ) {
			if ( '' === trim( (string) shell_exec( 'command -v ' . $tool ) ) ) {
				$this->markTestSkipped( 'No ' . $tool . ' here; the build needs it.' );
			}
		}
		$this->sandbox = Sandbox::make( 'build-dist' );
		$this->repo    = $this->sandbox . '/repo';
		foreach ( array( 'bin', 'src/Jobs', 'assets/admin' ) as $dir ) {
			mkdir( $this->repo . '/' . $dir, 0755, true );
		}
		copy( dirname( __DIR__, 3 ) . '/bin/build-dist.sh', $this->repo . '/bin/build-dist.sh' );
		foreach ( array( 'wp-checkpoint.php', 'uninstall.php', 'src/Plugin.php', 'src/Jobs/Job.php' ) as $file ) {
			file_put_contents( $this->repo . '/' . $file, '<?php // ' . $file );
		}
		file_put_contents( $this->repo . '/readme.txt', '=== WP Checkpoint ===' );
		file_put_contents( $this->repo . '/LICENSE', 'GPL' );
		file_put_contents( $this->repo . '/assets/admin/admin.css', 'body {}' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * Run the script (from the sandbox, not from the repository).
	 *
	 * @param string[] $args Arguments.
	 * @return array{code: int, stderr: string}
	 */
	private function run_script( array $args = array() ): array {
		$pipes   = array();
		$process = proc_open( array_merge( array( 'bash', $this->repo . '/bin/build-dist.sh' ), $args ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $this->sandbox, array( 'PATH' => (string) getenv( 'PATH' ) ) );
		$this->assertIsResource( $process );
		stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		return array(
			'code'   => proc_close( $process ),
			'stderr' => $stderr,
		);
	}

	/** The built package. */
	private function package(): string {
		return $this->repo . '/build/wp-checkpoint';
	}

	/**
	 * Every entry of the package, relative, links not followed.
	 *
	 * @return string[]
	 */
	private function listing(): array {
		$found = array();
		$walk  = function ( string $dir, string $prefix ) use ( &$walk, &$found ): void {
			foreach ( (array) scandir( $dir ) as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$found[] = $prefix . $name;
				if ( is_dir( $dir . '/' . $name ) && ! is_link( $dir . '/' . $name ) ) {
					$walk( $dir . '/' . $name, $prefix . $name . '/' );
				}
			}
		};
		$walk( $this->package(), '' );
		sort( $found );
		return $found;
	}

	/** What a package of the repository set up above holds. */
	private const SHIPPED = array( 'LICENSE', 'assets', 'assets/admin', 'assets/admin/admin.css', 'readme.txt', 'src', 'src/Jobs', 'src/Jobs/Job.php', 'src/Plugin.php', 'uninstall.php', 'wp-checkpoint.php' );

	public function test_only_the_listed_paths_are_shipped_whatever_else_the_repository_holds(): void {
		$built = $this->run_script();
		$this->assertSame( 0, $built['code'], 'the control: a repository of the listed paths only builds: ' . $built['stderr'] );
		$this->assertSame( self::SHIPPED, $this->listing() );

		// Beside them: what a working copy holds that is not the plugin, a dot directory among it.
		foreach ( array( '.claude', 'extra', 'tests', 'node_modules/pkg', 'vendor/composer', '.git', 'languages' ) as $dir ) {
			mkdir( $this->repo . '/' . $dir, 0755, true );
			file_put_contents( $this->repo . '/' . $dir . '/file', 'x' );
		}
		file_put_contents( $this->repo . '/.distignore', '/tests' );
		file_put_contents( $this->repo . '/composer.json', '{}' );
		$built = $this->run_script();
		$this->assertSame( 0, $built['code'], $built['stderr'] );
		$this->assertSame( self::SHIPPED, $this->listing(), 'none of it shipped' );
	}

	public function test_the_check_refuses_a_package_with_a_path_the_list_does_not_name(): void {
		$this->assertSame( 0, $this->run_script()['code'] );
		$checked = $this->run_script( array( '--check', $this->package() ) );
		$this->assertSame( 0, $checked['code'], 'the control: the package as built passes: ' . $checked['stderr'] );
		foreach ( array( '.claude', 'extra' ) as $dir ) {
			mkdir( $this->package() . '/' . $dir );
			file_put_contents( $this->package() . '/' . $dir . '/file', 'x' );
		}
		$checked = $this->run_script( array( '--check', $this->package() ) );
		$this->assertSame( 1, $checked['code'] );
		$this->assertStringContainsString( 'Not allowed in the package: .claude (not one of the paths the plugin ships)', $checked['stderr'] );
		$this->assertStringContainsString( 'Not allowed in the package: extra (not one of the paths the plugin ships)', $checked['stderr'] );
	}

	/**
	 * A shipped directory is copied as it is: what is in it that may not be shipped stops the build.
	 *
	 * @return array<string, array{0: callable, 1: string}>
	 */
	public function inside_a_shipped_directory(): array {
		return array(
			'a dot directory'                  => array(
				static function ( string $repo ): void {
					mkdir( $repo . '/src/.claude' );
					file_put_contents( $repo . '/src/.claude/scheduled_tasks.lock', 'x' );
				},
				'src/.claude (a name beginning with a dot)',
			),
			'a dot file'                       => array(
				static function ( string $repo ): void {
					file_put_contents( $repo . '/assets/.DS_Store', 'x' );
				},
				'assets/.DS_Store (a name beginning with a dot)',
			),
			'a vendor directory'               => array(
				static function ( string $repo ): void {
					mkdir( $repo . '/src/vendor/composer', 0755, true );
					file_put_contents( $repo . '/src/vendor/autoload.php', '<?php' );
				},
				'src/vendor (a vendor directory',
			),
			'a symbolic link'                  => array(
				static function ( string $repo ): void {
					mkdir( dirname( $repo ) . '/elsewhere' );
					symlink( dirname( $repo ) . '/elsewhere', $repo . '/src/Linked' );
				},
				'src/Linked (a symbolic link)',
			),
			'a listed path that is a link'     => array(
				static function ( string $repo ): void {
					rename( $repo . '/assets', dirname( $repo ) . '/assets' );
					symlink( dirname( $repo ) . '/assets', $repo . '/assets' );
				},
				'assets (a symbolic link)',
			),
		);
	}

	/**
	 * @dataProvider inside_a_shipped_directory
	 */
	public function test_what_may_not_be_shipped_inside_a_shipped_directory_stops_the_build( callable $add, string $reported ): void {
		$built = $this->run_script();
		$this->assertSame( 0, $built['code'], 'the control: without it, the package builds: ' . $built['stderr'] );
		$add( $this->repo );
		$built = $this->run_script();
		$this->assertSame( 1, $built['code'] );
		$this->assertStringContainsString( 'Not allowed in the package: ' . $reported, $built['stderr'] );
		$this->assertStringContainsString( 'is not one to ship', $built['stderr'] );
	}

	public function test_a_listed_path_missing_from_the_repository_stops_the_build(): void {
		$this->assertSame( 0, $this->run_script()['code'], 'the control: with it, the package builds' );
		Sandbox::remove( $this->repo . '/LICENSE' );
		$built = $this->run_script();
		$this->assertSame( 1, $built['code'] );
		$this->assertStringContainsString( 'Not in the repository, so the package cannot be built: LICENSE', $built['stderr'] );
	}
}
