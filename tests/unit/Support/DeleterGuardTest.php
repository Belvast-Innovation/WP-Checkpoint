<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;
use WPCheckpoint\Support\OwnerMarker;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What the Deleter refuses to delete, before it touches anything.
 *
 * Every call that could delete works inside a sandbox of its own under the temporary directory, with stand-ins
 * for the WordPress directories (replace_protected()): a guard that fails here, even under a mutation, deletes
 * nothing but the sandbox. The root of the file system and the real WordPress directories are only ever asked
 * about through refusal(), which deletes nothing.
 */
final class DeleterGuardTest extends TestCase {

	/** @var string */
	private $sandbox;

	/** @var string */
	private $cwd;

	/** @var string[] */
	private $protected_before;

	/** @var string[] */
	private $roots_before;

	protected function set_up(): void {
		parent::set_up();
		$this->sandbox = sys_get_temp_dir() . '/wpc-deleter-guard-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->sandbox, 0755, true );
		$this->sandbox = (string) realpath( $this->sandbox );
		$this->assertNotSame( '', $this->sandbox, 'the sandbox was made' );
		$this->assertDirectoryExists( $this->sandbox );
		$this->cwd              = (string) getcwd();
		$this->protected_before = Deleter::replace_protected( array() );
		$this->roots_before     = Deleter::replace_roots( array() );
		Deleter::replace_roots( $this->roots_before );
	}

	protected function tear_down(): void {
		chdir( $this->cwd );
		Deleter::replace_protected( $this->protected_before );
		Deleter::replace_roots( $this->roots_before );
		if ( '' !== $this->sandbox ) {
			self::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * Remove a sandbox tree without the Deleter (the class under test).
	 */
	private static function remove( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::remove( $path . '/' . $entry );
			}
		}
		@rmdir( $path );
	}

	/**
	 * A directory of the sandbox with a file in it; the file's path.
	 */
	private function made( string $relative ): string {
		$dir = $this->sandbox . '/' . $relative;
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0755, true );
		}
		file_put_contents( $dir . '/keep.txt', 'keep' );
		return $dir . '/keep.txt';
	}

	/**
	 * Both entry points refuse the path, and the file is still there.
	 */
	private function assertRefused( string $path, string $keep, string $because ): void {
		foreach ( array( 'empty_directory', 'delete_tree' ) as $entry ) {
			try {
				if ( 'empty_directory' === $entry ) {
					Deleter::empty_directory( $path );
				} else {
					Deleter::delete_tree( $this->sandbox, $path );
				}
				$this->fail( "{$entry}( '{$path}' ) was not refused" );
			} catch ( DeletionRefused $e ) {
				$this->assertStringContainsString( 'Nothing was deleted', $e->getMessage() );
				$this->assertStringContainsString( $because, $e->getMessage() );
			}
			clearstatcache();
			$this->assertFileExists( $keep, "{$entry}( '{$path}' ) deleted nothing" );
		}
	}

	public function test_an_empty_path_is_refused_and_the_working_directory_is_left_alone(): void {
		$keep = $this->made( 'cwd' );
		chdir( $this->sandbox . '/cwd' );
		foreach ( array( '', ' ', '/' === DIRECTORY_SEPARATOR ? "\t" : ' ' ) as $path ) {
			$this->assertRefused( $path, $keep, 'the path is empty' );
		}
		chdir( $this->cwd );
		Deleter::empty_directory( $this->sandbox . '/cwd' );
		$this->assertFileDoesNotExist( $keep, 'the control: the same directory, named, is emptied' );
	}

	public function test_a_relative_path_is_refused(): void {
		$keep = $this->made( 'rel' );
		chdir( $this->sandbox );
		foreach ( array( 'rel', './rel', '.', 'rel/..' ) as $path ) {
			$this->assertRefused( $path, $keep, 'the path is relative' );
		}
		chdir( $this->cwd );
		// What is absolute on which platform, decided the same way on every platform (so a Linux run checks Windows).
		foreach ( array( 'C:\\Users\\x\\AppData\\Local\\Temp', 'c:/x', '\\\\server\\share\\x' ) as $windows ) {
			$this->assertTrue( Deleter::absolute_on( $windows, true ), $windows . ' on Windows' );
			$this->assertFalse( Deleter::absolute_on( $windows, false ), $windows . ' on POSIX: backslashes are ordinary characters' );
		}
		$this->assertTrue( Deleter::absolute_on( '/x', false ) );
		$this->assertTrue( Deleter::absolute_on( '/x', true ) );
		$this->assertFalse( Deleter::absolute_on( 'C:x', true ), 'relative to the current directory of drive C:' );
		Deleter::delete_tree( $this->sandbox, $this->sandbox . '/rel' );
		$this->assertFileDoesNotExist( $keep, 'the control: the same directory, named absolutely, is deleted' );
	}

	public function test_the_root_of_the_file_system_is_refused(): void {
		// Asked through refusal() only: nothing here ever tries to delete it.
		$this->assertStringContainsString( 'root of the file system', Deleter::refusal( '/' ) );
		$this->assertStringContainsString( 'root of the file system', Deleter::refusal( '//' ) );
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->assertStringContainsString( 'root of the file system', Deleter::refusal( 'C:\\' ) );
		}
		$this->assertSame( '', Deleter::refusal( $this->sandbox ), 'the control: a directory in the registered temporary directory may be deleted' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function protected_dirs(): array {
		return array(
			'ABSPATH'              => array( 'site/abspath' ),
			'wp-content'           => array( 'site/abspath/wp-content' ),
			'the plugins directory' => array( 'site/abspath/wp-content/plugins' ),
			'this plugin'          => array( 'site/abspath/wp-content/plugins/wp-checkpoint' ),
		);
	}

	/**
	 * @dataProvider protected_dirs
	 */
	public function test_a_protected_directory_and_every_directory_above_it_are_refused( string $relative ): void {
		$keep = $this->made( $relative );
		Deleter::replace_protected( array( $this->sandbox . '/' . $relative ) );
		$dir = $this->sandbox . '/' . $relative;
		for ( $at = $dir; strlen( $at ) >= strlen( $this->sandbox ); $at = dirname( $at ) ) {
			$this->assertRefused( $at, $keep, 'a protected directory' );
		}
		$this->assertStringContainsString( 'a protected directory', Deleter::refusal( dirname( $this->sandbox ) ), 'the temporary directory holds it too' );
		$sibling = $this->made( $relative . '-sibling' );
		Deleter::delete_tree( $this->sandbox, dirname( $sibling ) );
		$this->assertFileDoesNotExist( $sibling, 'the control: a directory next to it is deleted' );
	}

	public function test_the_real_wordpress_directories_and_what_holds_them_are_refused(): void {
		// Asked through refusal() only. In the unit suite ABSPATH is inside the repository (the bootstrap).
		$this->assertStringContainsString( 'ABSPATH', Deleter::refusal( ABSPATH ) );
		$this->assertStringContainsString( 'ABSPATH', Deleter::refusal( dirname( ABSPATH ) ) );
		$this->assertStringContainsString( 'ABSPATH', Deleter::refusal( dirname( __DIR__, 3 ) ), 'the repository' );
		$this->assertStringContainsString( 'ABSPATH', Deleter::refusal( rtrim( ABSPATH, '/' ) . '/../..' ), 'spelt another way' );
	}

	public function test_a_path_outside_every_directory_the_plugin_may_delete_in_is_refused(): void {
		$keep = $this->made( 'outside' );
		Deleter::replace_roots( array() ); // Not even the temporary directory.
		$this->assertRefused( $this->sandbox . '/outside', $keep, 'outside every directory' );

		// A storage directory of the plugin under a name of its own (a custom one): its sub-directories and its own files.
		$storage = $this->made( 'store/tmp/job-1' );
		$loose   = $this->made( 'store' );
		file_put_contents( $this->sandbox . '/store/' . OwnerMarker::FILENAME, 'marker' );
		$this->assertRefused( $loose, $loose, 'outside every directory' );
		$this->assertRefused( $this->sandbox . '/store', $loose, 'outside every directory' );
		Deleter::delete_tree( $this->sandbox, dirname( $storage ) );
		$this->assertFileDoesNotExist( $storage, 'the control: in a sub-directory of a storage directory' );
		Deleter::delete_tree( $this->sandbox, $this->sandbox . '/store/' . OwnerMarker::FILENAME );
		$this->assertFileDoesNotExist( $this->sandbox . '/store/' . OwnerMarker::FILENAME, 'the control: its own file' );

		// A storage directory under the plugin's own name: the whole of it.
		$named = $this->made( 'wp-checkpoint-a1b2c3d4e5f6' );
		file_put_contents( dirname( $named ) . '/' . OwnerMarker::FILENAME, 'marker' );
		Deleter::empty_directory( dirname( $named ) );
		$this->assertFileDoesNotExist( $named, 'the control: a storage directory of its own name' );

		// A marker in a directory that must never be deleted opens nothing.
		$site = $this->made( 'site/abspath/wp-content/tmp' );
		file_put_contents( $this->sandbox . '/site/abspath/wp-content/' . OwnerMarker::FILENAME, 'marker' );
		Deleter::replace_protected( array( $this->sandbox . '/site/abspath/wp-content' ) );
		$this->assertRefused( dirname( $site ), $site, 'outside every directory' );
		Deleter::replace_protected( array() );

		// A probe of a restore (its name).
		$probe = $this->made( 'wp-checkpoint-probe-a1b2c3d4e5f6-7-' . str_repeat( 'cd', 8 ) );
		Deleter::delete_tree( $this->sandbox, dirname( $probe ) );
		$this->assertFileDoesNotExist( $probe, 'the control: a probe' );

		// A staging root of a restore (its name).
		$staged = $this->made( 'wp-checkpoint-stage-a1b2c3d4e5f6-7-' . str_repeat( 'ab', 16 ) . '/plugins' );
		Deleter::delete_tree( $this->sandbox, dirname( $staged ) );
		$this->assertFileDoesNotExist( $staged, 'the control: in a staging root' );

		// A registered directory.
		Deleter::allow( $this->sandbox . '/outside' );
		Deleter::empty_directory( $this->sandbox . '/outside' );
		$this->assertFileDoesNotExist( $keep, 'the control: registered' );
	}

	public function test_only_a_directory_that_may_hold_deletions_can_be_registered(): void {
		$keep = $this->made( 'site/abspath' );
		Deleter::replace_protected( array( dirname( $keep ) ) );
		foreach ( array(
			''                        => 'empty',
			'relative'                => 'relative',
			'/'                       => 'root of the file system',
			dirname( $keep )          => 'a protected directory',
			$this->sandbox . '/site'  => 'a protected directory',
		) as $root => $why ) {
			try {
				Deleter::allow( (string) $root );
				$this->fail( "'{$root}' was registered" );
			} catch ( DeletionRefused $e ) {
				$this->assertStringContainsString( $why, $e->getMessage() );
			}
		}
		$before = Deleter::replace_roots( array() );
		Deleter::allow( $this->sandbox . '/elsewhere' );
		Deleter::allow( $this->sandbox . '/shared', false );
		$this->assertSame(
			array(
				$this->sandbox . DIRECTORY_SEPARATOR . 'elsewhere' => true,
				$this->sandbox . DIRECTORY_SEPARATOR . 'shared'    => false,
			),
			Deleter::replace_roots( $before ),
			'the control: plain directories are registered, resolved'
		);
	}

	public function test_a_directory_registered_for_what_is_inside_it_is_never_deleted_itself(): void {
		$keep = $this->made( 'shared/in' );
		Deleter::replace_roots( array() );
		Deleter::allow( $this->sandbox . '/shared', false );
		$this->assertRefused( $this->sandbox . '/shared', $keep, 'outside every directory' );
		Deleter::delete_tree( $this->sandbox, dirname( $keep ) );
		$this->assertFileDoesNotExist( $keep, 'the control: what is inside it is deleted' );
		// The temporary directory, as the bootstrap registers it.
		Deleter::replace_roots( $this->roots_before );
		$this->assertStringContainsString( 'outside every directory', Deleter::refusal( sys_get_temp_dir() ) );
		$this->assertSame( '', Deleter::refusal( $this->sandbox ), 'the control: inside it' );
	}

	public function test_a_link_is_deleted_as_itself_and_what_it_points_at_stays(): void {
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'Symbolic links need privileges on Windows; junctions are covered by DeleterTest.' );
		}
		$keep = $this->made( 'site/abspath' );
		Deleter::replace_protected( array( dirname( $keep ) ) );
		mkdir( $this->sandbox . '/work' );
		symlink( dirname( $keep ), $this->sandbox . '/work/link' );
		Deleter::delete_tree( $this->sandbox, $this->sandbox . '/work/link' );
		$this->assertFalse( is_link( $this->sandbox . '/work/link' ), 'the link is gone' );
		$this->assertFileExists( $keep, 'what it pointed at, a protected directory, is untouched' );
		symlink( dirname( $keep ), $this->sandbox . '/work/link2' );
		try {
			Deleter::empty_directory( $this->sandbox . '/work/link2' );
		} catch ( DeletionRefused $e ) {
			unset( $e ); // Refused, or failed as a link below: either way nothing goes.
		}
		$this->assertFileExists( $keep, 'emptying through a link empties nothing' );
	}
}
