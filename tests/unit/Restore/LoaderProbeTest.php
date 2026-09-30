<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\CannotStage;
use WPCheckpoint\Restore\LoaderProbe;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\AtomicFile;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A file is put into the must-use plugins directory the way the loader will be, and removed; what a death
 * leaves there is harmless.
 */
final class LoaderProbeTest extends TestCase {

	/** @var string */
	private $root;

	/** @var string */
	private $mu;

	/** @var string */
	private $name;

	protected function set_up(): void {
		parent::set_up();
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-lprobe-' . bin2hex( random_bytes( 4 ) );
		$this->mu   = $this->root . '/mu-plugins';
		$this->name = ( new StagingLayout( array_fill_keys( StagingLayout::GROUPS, $this->root ), 'a1b2c3d4e5f6', 7, StagingLayout::new_random() ) )->probe_name( '.php' );
		mkdir( $this->root );
	}

	protected function tear_down(): void {
		@chmod( $this->root, 0700 );
		@chmod( $this->mu, 0700 );
		foreach ( (array) @scandir( $this->mu ) as $entry ) {
			if ( is_string( $entry ) && '.' !== $entry && '..' !== $entry ) {
				@unlink( $this->mu . '/' . $entry );
			}
		}
		@rmdir( $this->mu );
		@rmdir( $this->root );
	}

	private static function noop(): callable {
		return static function (): void {
		};
	}

	public function test_a_directory_it_created_is_removed_again_and_one_that_was_there_stays(): void {
		LoaderProbe::run( $this->mu, $this->name, self::noop() );
		$this->assertDirectoryDoesNotExist( $this->mu, 'created for the probe and empty: removed' );

		mkdir( $this->mu );
		file_put_contents( $this->mu . '/other.php', '<?php' );
		LoaderProbe::run( $this->mu, $this->name, self::noop() );
		$this->assertSame( array( 'other.php' ), array_values( array_diff( scandir( $this->mu ), array( '.', '..' ) ) ), 'only what was there' );
		unlink( $this->mu . '/other.php' );
		LoaderProbe::run( $this->mu, $this->name, self::noop() );
		$this->assertDirectoryExists( $this->mu, 'there before, empty now: kept' );
	}

	public function test_a_refused_confirmation_creates_no_directory(): void {
		try {
			LoaderProbe::run(
				$this->mu,
				$this->name,
				static function (): void {
					throw new \RuntimeException( 'Lease lost.' );
				}
			);
			$this->fail( 'ran' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Lease lost.', $e->getMessage() );
		}
		$this->assertDirectoryDoesNotExist( $this->mu );
	}

	public function test_a_directory_that_cannot_be_created_or_written_is_refused_with_the_reason(): void {
		if ( '\\' === DIRECTORY_SEPARATOR || ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$this->markTestSkipped( 'Permissions do not stop this user here.' );
		}
		chmod( $this->root, 0500 );
		try {
			LoaderProbe::run( $this->mu, $this->name, self::noop() );
			$this->fail( 'ran' );
		} catch ( CannotStage $e ) {
			$this->assertStringContainsString( 'does not exist and cannot be created', $e->getMessage() );
		}
		chmod( $this->root, 0700 );
		mkdir( $this->mu, 0500 );
		try {
			LoaderProbe::run( $this->mu, $this->name, self::noop() );
			$this->fail( 'ran' );
		} catch ( CannotStage $e ) {
			$this->assertStringContainsString( 'A file cannot be put into the must-use plugins directory ' . $this->mu, $e->getMessage() );
		}
		$this->assertSame( array(), array_values( array_diff( scandir( $this->mu ), array( '.', '..' ) ) ) );
	}

	public function test_a_death_before_the_rename_leaves_no_php_file_and_after_it_a_file_that_does_nothing(): void {
		mkdir( $this->mu );
		try {
			AtomicFile::write(
				$this->mu,
				$this->name,
				LoaderProbe::CONTENTS,
				array(
					'at' => static function ( string $stage ): void {
						if ( 'written' === $stage ) {
							throw new \RuntimeException( 'killed' );
						}
					},
				)
			);
			$this->fail( 'written' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'killed', $e->getMessage() );
		}
		$left = array_values( array_diff( scandir( $this->mu ), array( '.', '..' ) ) );
		$this->assertCount( 1, $left, 'the control: the temporary file is there' );
		$this->assertSame( array(), preg_grep( '/\.php\z/', $left ), 'no .php file: WordPress loads nothing' );
		$this->assertNotNull( StagingLayout::parse( $left[0] ), 'a name the reaper knows' );
		unlink( $this->mu . '/' . $left[0] );

		try {
			AtomicFile::write(
				$this->mu,
				$this->name,
				LoaderProbe::CONTENTS,
				array(
					'at' => static function ( string $stage ): void {
						if ( 'renamed' === $stage ) {
							throw new \RuntimeException( 'killed' );
						}
					},
				)
			);
			$this->fail( 'written' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'killed', $e->getMessage() );
		}
		$file = $this->mu . '/' . $this->name;
		$this->assertFileExists( $file );
		// Loaded the way WordPress loads a must-use plugin, in a PHP of its own: no output, nothing defined.
		// Single quotes only inside the script: escapeshellarg() on Windows drops double quotes.
		$script = "ob_start(); \$before = array( get_defined_functions()['user'], get_declared_classes(), get_defined_constants( true )['user'] ?? array() ); include \$argv[1];"
			. " \$after = array( get_defined_functions()['user'], get_declared_classes(), get_defined_constants( true )['user'] ?? array() ); \$out = ob_get_clean();"
			. " echo json_encode( array( 'out' => \$out, 'same' => \$before === \$after ) );";
		$load    = static function ( string $path ) use ( $script ): string {
			return (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d error_reporting=-1 -r ' . escapeshellarg( $script ) . ' ' . escapeshellarg( $path ) . ' 2>&1' );
		};
		$control = $this->root . '/control.php';
		file_put_contents( $control, "<?php\nfunction wpcheckpoint_probe_control() {}\necho 'x';\n" );
		$this->assertSame(
			array(
				'out'  => 'x',
				'same' => false,
			),
			json_decode( $load( $control ), true ),
			'the control: a file that does something is seen doing it'
		);
		unlink( $control );
		$output = $load( $file );
		$this->assertSame(
			array(
				'out'  => '',
				'same' => true,
			),
			json_decode( (string) $output, true ),
			(string) $output
		);
	}
}
