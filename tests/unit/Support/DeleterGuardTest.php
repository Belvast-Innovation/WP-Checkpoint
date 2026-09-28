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
		$this->sandbox          = (string) realpath( $this->sandbox );
		$this->cwd              = (string) getcwd();
		$this->protected_before = Deleter::replace_protected( array() );
		$this->roots_before     = Deleter::replace_roots( array() );
		Deleter::replace_roots( $this->roots_before );
	}

	protected function tear_down(): void {
		chdir( $this->cwd );
		Deleter::replace_protected( $this->protected_before );
		Deleter::replace_roots( $this->roots_before );
		self::remove( $this->sandbox );
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
		Deleter::delete_tree( $this->sandbox, $this->sandbox . '/rel' );
		$this->assertFileDoesNotExist( $keep, 'the control: the same directory, named absolutely, is deleted' );
	}

	public function test_the_root_of_the_file_system_is_refused(): void {
		// Asked through refusal() only: nothing here ever tries to delete it.
		$this->assertStringContainsString( 'root of the file system', Deleter::refusal( '/' ) );
		$this->assertStringContainsString( 'root of the file system', Deleter::refusal( '//' ) );
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

		// A storage directory of the plugin (its owner marker).
		$storage = $this->made( 'store' );
		file_put_contents( $this->sandbox . '/store/' . OwnerMarker::FILENAME, 'marker' );
		Deleter::delete_tree( $this->sandbox, $storage );
		$this->assertFileDoesNotExist( $storage, 'the control: in a storage directory' );

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
		$this->assertSame( array( $this->sandbox . '/elsewhere' ), Deleter::replace_roots( $before ), 'the control: a plain directory is registered, resolved' );
	}
}
