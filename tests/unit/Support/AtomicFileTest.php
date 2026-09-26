<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\AtomicFile;
use WPCheckpoint\Support\AtomicWriteFailed;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A file written with AtomicFile is either complete under its name or not
 * there; a process that dies on the way leaves only a temporary file that
 * does not end like the final one.
 */
final class AtomicFileTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-atomic-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tear_down(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( $this->dir );
	}

	/**
	 * The names in the directory.
	 *
	 * @return string[]
	 */
	private function names(): array {
		return array_values( array_diff( (array) scandir( $this->dir ), array( '.', '..' ) ) );
	}

	public function test_a_file_is_written_whole_under_its_name_and_nothing_else_is_left(): void {
		$path = AtomicFile::write( $this->dir, 'probe.php', "<?php\n// Nothing.\n" );
		$this->assertSame( $this->dir . DIRECTORY_SEPARATOR . 'probe.php', $path );
		$this->assertSame( "<?php\n// Nothing.\n", file_get_contents( $path ) );
		$this->assertSame( array( 'probe.php' ), $this->names() );
		// Over an existing file: replaced whole.
		AtomicFile::write( $this->dir, 'probe.php', 'second' );
		$this->assertSame( 'second', file_get_contents( $path ) );
		$this->assertSame( array( 'probe.php' ), $this->names() );
	}

	public function test_a_process_that_dies_before_the_rename_leaves_no_file_of_that_name(): void {
		try {
			AtomicFile::write(
				$this->dir,
				'probe.php',
				'<?php // x',
				static function ( string $stage ): void {
					if ( 'written' === $stage ) {
						throw new \RuntimeException( 'dies here' );
					}
				}
			);
			$this->fail( 'went on' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'dies here', $e->getMessage(), 'the control: it died at that stage' );
		}
		$names = $this->names();
		$this->assertCount( 1, $names, 'the temporary file' );
		$this->assertMatchesRegularExpression( '/\Aprobe\.php\.[a-f0-9]{16}\.tmp\z/', $names[0] );
		$this->assertSame( array(), glob( $this->dir . '/*.php' ), 'nothing ends in .php' );
	}

	public function test_a_process_that_dies_after_the_rename_leaves_the_whole_file(): void {
		try {
			AtomicFile::write(
				$this->dir,
				'probe.php',
				'<?php // x',
				static function ( string $stage ): void {
					if ( 'renamed' === $stage ) {
						throw new \RuntimeException( 'dies here' );
					}
				}
			);
			$this->fail( 'went on' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'dies here', $e->getMessage() );
		}
		$this->assertSame( array( 'probe.php' ), $this->names() );
		$this->assertSame( '<?php // x', file_get_contents( $this->dir . '/probe.php' ) );
	}

	public function test_a_file_that_does_not_read_back_as_written_is_removed(): void {
		$dir = $this->dir;
		try {
			AtomicFile::write(
				$this->dir,
				'probe.php',
				'<?php // x',
				static function ( string $stage ) use ( $dir ): void {
					if ( 'renamed' === $stage ) {
						file_put_contents( $dir . '/probe.php', '<?php // changed' ); // Something else wrote it meanwhile.
					}
				}
			);
			$this->fail( 'accepted' );
		} catch ( AtomicWriteFailed $e ) {
			$this->assertSame( 'The file did not read back as written.', $e->getMessage() );
		}
		$this->assertSame( array(), $this->names() );
	}

	public function test_a_file_that_cannot_be_created_throws_and_leaves_nothing(): void {
		try {
			AtomicFile::write( $this->dir . '/missing', 'probe.php', 'x' );
			$this->fail( 'written' );
		} catch ( AtomicWriteFailed $e ) {
			$this->assertSame( 'The file could not be created.', $e->getMessage() );
			$this->assertStringNotContainsString( $this->dir, $e->getMessage(), 'no path' );
		}
		$this->assertSame( array(), $this->names() );
	}

	public function test_a_name_with_a_separator_is_refused(): void {
		foreach ( array( '', '.', '..', 'a/b.php', 'a\\b.php', "a\0b" ) as $name ) {
			try {
				AtomicFile::write( $this->dir, $name, 'x' );
				$this->fail( 'written: ' . $name );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Not a file name.', $e->getMessage() );
			}
		}
		$this->assertSame( array(), $this->names() );
	}
}
