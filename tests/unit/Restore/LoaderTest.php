<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The must-use plugin a swap puts up (Restore\Loader): its code is pinned and named by its SHA-256 in the maintenance
 * file's code; its name sorts before the site's own must-use plugins; it is put only into a directory that is there
 * and never over another file; it is removed by its contents only, wherever it is; and under the command line it does
 * nothing (what it does in a request is shown through a web server: LoaderRequestTest).
 */
final class LoaderTest extends TestCase {

	/** @var string */
	private $root = '';

	/** @var string */
	private $mu = '';

	protected function set_up(): void {
		parent::set_up();
		$this->root = Sandbox::make( 'loader' );
		$this->mu   = $this->root . '/mu-plugins';
		mkdir( $this->mu );
	}

	protected function tear_down(): void {
		if ( '' !== $this->root ) {
			@chmod( $this->mu, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gone in some tests.
			Sandbox::remove( $this->root );
		}
		parent::tear_down();
	}

	public function test_the_code_is_pinned_parses_and_is_named_in_the_maintenance_files_code(): void {
		// A change of CODE: keep the released code in KNOWN, name the new digest in Maintenance::CODE (a new version of
		// that code too, the released one kept in its KNOWN_CODES), then update this digest.
		$this->assertSame( 'e1ba77d7c466b93d01a56c5afe2075cc6abfd9ace30c5f96109986b4b8fb0d63', hash( 'sha256', Loader::CODE ) );
		$this->assertContains( Loader::CODE, Loader::KNOWN );
		$this->assertSame( array_values( array_unique( Loader::KNOWN ) ), Loader::KNOWN );
		$this->assertStringNotContainsString( "\r", Loader::CODE );
		$this->assertLessThanOrEqual( Loader::MAX_READ, strlen( Loader::CODE ) );
		$this->assertSame( Loader::MAX_READ, Deleter::LOADER_MAX_READ );
		token_get_all( Loader::CODE, TOKEN_PARSE ); // Throws when it does not parse.
		// The maintenance file's code lets a request through to flush only by these digests: each known code's, no other.
		$this->assertSame( 1, preg_match( "/in_array\\( hash\\( 'sha256', \\\$wpcheckpoint_loader \\), array\\( ((?:'[0-9a-f]{64}'(?:, )?)+) \\), true \\)/", Maintenance::CODE, $found ) );
		$named = array_map(
			static function ( string $quoted ): string {
				return trim( $quoted, "'" );
			},
			explode( ', ', $found[1] )
		);
		$this->assertSame( Loader::digests(), $named );
		$this->assertStringContainsString( "'/" . Loader::NAME . "'", Maintenance::CODE, 'the code looks for the file under the loader\'s name' );
		$this->assertStringContainsString( ', 0, ' . ( Loader::MAX_READ + 1 ) . ' )', Maintenance::CODE, 'and reads as many bytes of it' );
		$this->assertSame( 1, preg_match( Deleter::LOADER_NAMES, Loader::NAME ) );
	}

	public function test_the_name_sorts_before_the_sites_own_must_use_plugins(): void {
		$names = array( 'zz.php', 'a.php', '000-first.php', '0000-early.php', '0-x.php', 'A.php', '_x.php', '-x.php', Loader::NAME );
		sort( $names ); // As wp_get_mu_plugins() sorts them.
		$this->assertSame( Loader::NAME, $names[0] );
		// The known limit: a name that starts with "!" and sorts before it.
		$names[] = '!a.php';
		sort( $names );
		$this->assertSame( '!a.php', $names[0] );
	}

	public function test_it_is_put_into_a_directory_that_is_there_and_never_makes_one(): void {
		$this->assertSame( '', Loader::put( $this->mu ) );
		$this->assertSame( Loader::CODE, file_get_contents( Loader::path( $this->mu ) ) );
		$this->assertSame( array( Loader::NAME ), array_values( array_diff( scandir( $this->mu ), array( '.', '..' ) ) ), 'no temporary file left' );
		$this->assertSame( '', Loader::put( $this->mu ), 'there already: left' );

		$missing = $this->root . '/none/mu-plugins';
		$this->assertSame( Loader::MISSING, Loader::put( $missing ) );
		$this->assertDirectoryDoesNotExist( $missing, 'never made' );
		$this->assertDirectoryDoesNotExist( dirname( $missing ) );
		$this->assertSame( Loader::MISSING, Loader::put( '' ) );
	}

	public function test_another_file_under_its_name_is_never_written_over(): void {
		foreach ( array( "<?php\n// someone else's\n", Loader::CODE . "\n", substr( Loader::CODE, 0, -1 ), str_repeat( 'x', Loader::MAX_READ + 10 ) ) as $other ) {
			file_put_contents( Loader::path( $this->mu ), $other );
			$this->assertSame( Loader::TAKEN, Loader::put( $this->mu ) );
			$this->assertSame( $other, file_get_contents( Loader::path( $this->mu ) ) );
		}
		Sandbox::remove( Loader::path( $this->mu ) );
		mkdir( Loader::path( $this->mu ) );
		$this->assertSame( Loader::TAKEN, Loader::put( $this->mu ), 'a directory under the name' );
		Sandbox::remove( Loader::path( $this->mu ) );
		$this->assertSame( '', Loader::put( $this->mu ), 'the control: put once the name is free' );
	}

	public function test_a_directory_that_cannot_be_written_into_says_so(): void {
		if ( 'Windows' === PHP_OS_FAMILY || ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) ) {
			$this->markTestSkipped( 'Needs a directory this user cannot write into.' );
		}
		chmod( $this->mu, 0500 );
		$this->assertSame( Loader::UNWRITABLE, Loader::put( $this->mu ) );
		chmod( $this->mu, 0700 );
		$this->assertSame( '', Loader::put( $this->mu ), 'the control: writable again' );
	}

	public function test_the_lease_is_confirmed_right_before_it_goes_in_place(): void {
		$stopped = null;
		try {
			Loader::put(
				$this->mu,
				static function (): void {
					throw new \RuntimeException( 'the lease is gone' );
				}
			);
		} catch ( \RuntimeException $e ) {
			$stopped = $e;
		}
		$this->assertNotNull( $stopped );
		$this->assertSame( array(), array_values( array_diff( scandir( $this->mu ), array( '.', '..' ) ) ), 'nothing put, no temporary file left' );
	}

	public function test_it_is_removed_by_its_contents_only(): void {
		$notes = array();
		$note  = static function ( string $line ) use ( &$notes ): void {
			$notes[] = $line;
		};
		// Ours: removed, wherever the directory is.
		foreach ( array( $this->mu, $this->root . '/old/mu-plugins' ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				mkdir( $dir, 0700, true );
			}
			Loader::put( $dir );
			$this->assertFileExists( Loader::path( $dir ) );
			$this->assertTrue( Loader::remove( $dir, null, $note ) );
			$this->assertFileDoesNotExist( Loader::path( $dir ) );
		}
		$this->assertSame( array(), $notes );
		$this->assertTrue( Loader::remove( $this->mu, null, $note ), 'none there' );
		$this->assertTrue( Loader::remove( $this->root . '/not-there', null, $note ) );
		$this->assertSame( array(), $notes );
		// Someone else's under the name: kept, and said.
		$other = "<?php\n// a site's own must-use plugin under this name\n";
		file_put_contents( Loader::path( $this->mu ), $other );
		$this->assertTrue( Loader::remove( $this->mu, null, $note ), 'none of ours is there' );
		$this->assertSame( $other, file_get_contents( Loader::path( $this->mu ) ) );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'not one this plugin wrote', $notes[0] );
		$this->assertStringNotContainsString( $this->root, $notes[0], 'no path in the note' );
	}

	public function test_the_deleter_deletes_only_the_loader_and_only_by_its_contents(): void {
		$digests = Loader::digests();
		// Another name: refused.
		file_put_contents( $this->mu . '/other.php', Loader::CODE );
		foreach ( array( 'other.php', '!wpcheckpoint-runtime.php.bak', '../mu-plugins/' . Loader::NAME, '' ) as $name ) {
			try {
				Deleter::delete_loader_file( $this->mu, $name, $digests );
				$this->fail( 'not refused: ' . $name );
			} catch ( DeletionRefused $e ) {
				$this->assertFileExists( $this->mu . '/other.php' );
			}
		}
		// Other contents under the name: refused, kept.
		file_put_contents( Loader::path( $this->mu ), 'not the code' );
		try {
			Deleter::delete_loader_file( $this->mu, Loader::NAME, $digests );
			$this->fail( 'other contents were deleted' );
		} catch ( DeletionRefused $e ) {
			$this->assertSame( 'not the code', file_get_contents( Loader::path( $this->mu ) ) );
		}
		// A link under the name, to the code itself: not a regular file, refused; its target stays.
		Sandbox::remove( Loader::path( $this->mu ) );
		file_put_contents( $this->root . '/target.php', Loader::CODE );
		if ( @symlink( $this->root . '/target.php', Loader::path( $this->mu ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- no links here: that part is left out.
			try {
				Deleter::delete_loader_file( $this->mu, Loader::NAME, $digests );
				$this->fail( 'a link was deleted' );
			} catch ( DeletionRefused $e ) {
				$this->assertFileExists( $this->root . '/target.php' );
			}
			$this->assertTrue( Loader::remove( $this->mu ), 'a link is someone else\'s: kept' );
			$this->assertTrue( is_link( Loader::path( $this->mu ) ) );
			Sandbox::remove( Loader::path( $this->mu ) );
		}
		// The code: deleted; the lease confirmed right before, and what it throws stops it.
		file_put_contents( Loader::path( $this->mu ), Loader::CODE );
		try {
			Deleter::delete_loader_file(
				$this->mu,
				Loader::NAME,
				$digests,
				static function (): void {
					throw new \RuntimeException( 'the lease is gone' );
				}
			);
			$this->fail( 'not stopped' );
		} catch ( \RuntimeException $e ) {
			$this->assertFileExists( Loader::path( $this->mu ) );
		}
		$this->assertTrue( Deleter::delete_loader_file( $this->mu, Loader::NAME, $digests ) );
		$this->assertFileDoesNotExist( Loader::path( $this->mu ) );
		$this->assertTrue( Deleter::delete_loader_file( $this->mu, Loader::NAME, $digests ), 'not there: gone' );
		// A temporary name (a write that died before its rename): deleted whatever it holds.
		$temporary = Loader::NAME . '.0123456789abcdef.tmp';
		file_put_contents( $this->mu . '/' . $temporary, substr( Loader::CODE, 0, 100 ) );
		$this->assertTrue( Deleter::delete_loader_file( $this->mu, $temporary, $digests ) );
		$this->assertFileDoesNotExist( $this->mu . '/' . $temporary );
		$this->assertFileExists( $this->mu . '/other.php', 'nothing else went' );
	}

	public function test_under_the_command_line_it_does_nothing_even_while_the_site_is_held(): void {
		// A held data file that names a runtime, and a runtime that says it ran: everything the loader waits for.
		$abspath = $this->root . '/site';
		mkdir( $abspath );
		$file = new Maintenance( $abspath, Maintenance::new_mark() );
		file_put_contents( $abspath . '/' . Maintenance::DATA, $file->data( Maintenance::held(), Maintenance::NO_PASS, str_repeat( 'a', 64 ) ) );
		file_put_contents( $abspath . '/' . Maintenance::RUNTIME, '<?php file_put_contents( __DIR__ . "/ran", "x" ); return "' . str_repeat( 'a', 64 ) . '";' );
		file_put_contents( $abspath . '/' . Maintenance::VISITOR, "<?php exit; ?>\n" );
		touch( $abspath . '/' . Maintenance::VISITOR, time() - 60 );
		Loader::put( $this->mu );
		$child = $this->root . '/child.php';
		file_put_contents( $child, '<?php define( "ABSPATH", ' . var_export( $abspath . '/', true ) . ' ); include ' . var_export( Loader::path( $this->mu ), true ) . '; echo "WENT-ON";' );
		$out = array();
		$rc  = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' 2>&1', $out, $rc );
		$this->assertSame( 0, $rc, implode( "\n", $out ) );
		$this->assertSame( array( 'WENT-ON' ), $out, 'the command line is not held' );
		$this->assertFileDoesNotExist( $abspath . '/ran', 'and runs no runtime' );
		$this->assertSame( 'cli', PHP_SAPI, 'the control: this is the command line (a request is LoaderRequestTest\'s)' );
	}

	public function test_the_must_use_plugins_directory_is_wordpresss_own_default(): void {
		$child = $this->root . '/dir-child.php';
		$class = var_export( dirname( __DIR__, 3 ) . '/src/Restore/Loader.php', true );
		$run   = function ( string $defines ) use ( $child, $class ): string {
			file_put_contents( $child, '<?php define( "ABSPATH", "/srv/site/" ); ' . $defines . ' foreach ( array( "Support/AtomicFile", "Support/AtomicWriteFailed", "Support/Deleter", "Support/DeletionRefused", "Support/HostFunctions", "Support/Paths" ) as $f ) {} require ' . $class . '; echo WPCheckpoint\Restore\Loader::dir( "/srv/site/" );' );
			$out = array();
			$rc  = 1;
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $child ) . ' 2>&1', $out, $rc );
			$this->assertSame( 0, $rc, implode( "\n", $out ) );
			return implode( "\n", $out );
		};
		$this->assertSame( '/srv/site/wp-content/mu-plugins', $run( '' ), 'neither constant: the default of the default' );
		$this->assertSame( '/srv/content/mu-plugins', $run( 'define( "WP_CONTENT_DIR", "/srv/content" );' ) );
		$this->assertSame( '/srv/mu', $run( 'define( "WP_CONTENT_DIR", "/srv/content" ); define( "WPMU_PLUGIN_DIR", "/srv/mu" );' ) );
	}
}
