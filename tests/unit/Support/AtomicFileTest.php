<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Support\AtomicFile;
use WPCheckpoint\Support\AtomicWriteFailed;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A file written with AtomicFile is either complete under its name or not
 * there; a process that dies on the way leaves only a temporary file that
 * does not end like the final one, under a name the residue catalogue
 * knows. The irreversible steps are confirmed first.
 */
final class AtomicFileTest extends TestCase {

	const NAME = 'wp-checkpoint-probe-a1b2c3d4e5f6-7-0123456789abcdef.php';

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-atomic-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tear_down(): void {
		foreach ( (array) glob( $this->dir . '/*' ) as $file ) {
			is_dir( (string) $file ) ? rmdir( (string) $file ) : unlink( (string) $file );
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

	private static function dies_at( string $at ): array {
		return array(
			'at' => static function ( string $stage ) use ( $at ): void {
				if ( $at === $stage ) {
					throw new \RuntimeException( 'dies here' );
				}
			},
		);
	}

	public function test_a_file_is_written_whole_under_its_name_and_nothing_else_is_left(): void {
		$confirmed = 0;
		$path      = AtomicFile::write(
			$this->dir,
			self::NAME,
			"<?php\n// Nothing.\n",
			array(
				'confirm' => static function () use ( &$confirmed ): void {
					++$confirmed;
				},
			)
		);
		$this->assertSame( $this->dir . DIRECTORY_SEPARATOR . self::NAME, $path );
		$this->assertSame( "<?php\n// Nothing.\n", file_get_contents( $path ) );
		$this->assertSame( array( self::NAME ), $this->names() );
		$this->assertSame( 1, $confirmed, 'confirmed once, before the rename' );
		// Over an existing file: replaced whole.
		AtomicFile::write( $this->dir, self::NAME, 'second' );
		$this->assertSame( 'second', file_get_contents( $path ) );
		$this->assertSame( array( self::NAME ), $this->names() );
	}

	public function test_a_process_that_dies_before_the_rename_leaves_a_registered_temporary_file_only(): void {
		try {
			AtomicFile::write( $this->dir, self::NAME, '<?php // x', self::dies_at( 'written' ) );
			$this->fail( 'went on' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'dies here', $e->getMessage(), 'the control: it died at that stage' );
		}
		$names = $this->names();
		$this->assertCount( 1, $names, 'the temporary file' );
		$this->assertMatchesRegularExpression( '/\.php\.[a-f0-9]{16}\.tmp\z/', $names[0] );
		$this->assertNotNull( StagingLayout::parse( $names[0] ), 'a name the residue catalogue knows' );
		$this->assertSame( array(), glob( $this->dir . '/*.php' ), 'nothing ends in .php' );
	}

	public function test_a_process_that_dies_after_the_rename_leaves_the_whole_file(): void {
		try {
			AtomicFile::write( $this->dir, self::NAME, '<?php // x', self::dies_at( 'renamed' ) );
			$this->fail( 'went on' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'dies here', $e->getMessage() );
		}
		$this->assertSame( array( self::NAME ), $this->names() );
		$this->assertSame( '<?php // x', file_get_contents( $this->dir . '/' . self::NAME ) );
	}

	public function test_a_refused_confirmation_leaves_neither_file(): void {
		file_put_contents( $this->dir . '/' . self::NAME, 'the one before' );
		try {
			AtomicFile::write(
				$this->dir,
				self::NAME,
				'new',
				array(
					'confirm' => static function (): void {
						throw new \RuntimeException( 'lease lost' );
					},
				)
			);
			$this->fail( 'went on' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'lease lost', $e->getMessage() );
		}
		$this->assertSame( array( self::NAME ), $this->names(), 'no temporary file' );
		$this->assertSame( 'the one before', file_get_contents( $this->dir . '/' . self::NAME ), 'the final file untouched' );
	}

	public function test_a_file_that_does_not_read_back_as_written_is_removed_after_confirming(): void {
		$dir       = $this->dir;
		$confirmed = 0;
		try {
			AtomicFile::write(
				$this->dir,
				self::NAME,
				'<?php // x',
				array(
					'confirm' => static function () use ( &$confirmed ): void {
						++$confirmed;
					},
					'at'      => static function ( string $stage ) use ( $dir ): void {
						if ( 'renamed' === $stage ) {
							file_put_contents( $dir . '/' . self::NAME, '<?php // changed' ); // Something else wrote it meanwhile.
						}
					},
				)
			);
			$this->fail( 'accepted' );
		} catch ( AtomicWriteFailed $e ) {
			$this->assertSame( 'The file did not read back as written.', $e->getMessage() );
		}
		$this->assertSame( 2, $confirmed, 'before the rename, and before removing it' );
		$this->assertSame( array(), $this->names() );
	}

	public function test_a_file_that_cannot_be_moved_into_place_leaves_no_temporary_file(): void {
		mkdir( $this->dir . '/' . self::NAME ); // A directory in the way.
		try {
			AtomicFile::write( $this->dir, self::NAME, 'x' );
			$this->fail( 'written' );
		} catch ( AtomicWriteFailed $e ) {
			$this->assertSame( 'The file could not be moved into place.', $e->getMessage() );
		}
		$this->assertSame( array( self::NAME ), $this->names(), 'only the directory' );
	}

	public function test_a_file_that_cannot_be_created_throws_and_leaves_nothing(): void {
		try {
			AtomicFile::write( $this->dir . '/missing', self::NAME, 'x' );
			$this->fail( 'written' );
		} catch ( AtomicWriteFailed $e ) {
			$this->assertSame( 'The file could not be created.', $e->getMessage() );
			$this->assertStringNotContainsString( $this->dir, $e->getMessage(), 'no path' );
		}
		$this->assertSame( array(), $this->names() );
	}

	public function test_only_names_of_the_catalogue_and_small_contents_are_written(): void {
		foreach ( array( '', 'probe.php', 'a/' . self::NAME, "a\0b" ) as $name ) {
			try {
				AtomicFile::write( $this->dir, $name, 'x' );
				$this->fail( 'written: ' . $name );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Not a file name this plugin writes.', $e->getMessage() );
			}
		}
		try {
			AtomicFile::write( $this->dir, self::NAME, str_repeat( 'x', AtomicFile::MAX_BYTES + 1 ) );
			$this->fail( 'written' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'Too large to write this way.', $e->getMessage() );
		}
		$this->assertSame( array(), $this->names() );
		AtomicFile::write( $this->dir, self::NAME, str_repeat( 'x', AtomicFile::MAX_BYTES ) );
		$this->assertSame( AtomicFile::MAX_BYTES, filesize( $this->dir . '/' . self::NAME ), 'the control: the largest is written' );
	}
}
