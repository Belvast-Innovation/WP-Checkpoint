<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Uninstaller;

/**
 * The housekeeping that deletes (reclaim and purge, storage switching, uninstall) goes on when the Deleter refuses a
 * path: the refusal is logged and counted as a failure, nothing is deleted, nothing is thrown. Each helper is also
 * shown deleting a path in the temporary directory (its control). The paths refused here are relative ones: nothing
 * could be deleted even if the guard failed (the working directory is not written to).
 */
final class DeleterRefusalHandlingTest extends WP_UnitTestCase {

	/** @var string */
	private $sandbox = '';

	/** @var string */
	private $php_log = '';

	/** @var string|false */
	private $was;

	public function set_up(): void {
		parent::set_up();
		$this->sandbox = sys_get_temp_dir() . '/wpc-refusal-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->sandbox );
		$this->php_log = $this->sandbox . '.log';
		$this->was     = ini_get( 'error_log' );
		ini_set( 'error_log', $this->php_log );
	}

	public function tear_down(): void {
		ini_set( 'error_log', (string) $this->was );
		@unlink( $this->php_log );
		if ( '' !== $this->sandbox && is_dir( $this->sandbox ) ) {
			foreach ( (array) glob( $this->sandbox . '/*' ) as $file ) {
				@unlink( (string) $file );
			}
			@rmdir( $this->sandbox );
		}
		parent::tear_down();
	}

	/**
	 * A private method made callable.
	 */
	private static function method( string $class, string $name ): \ReflectionMethod {
		$method = new \ReflectionMethod( $class, $name );
		$method->setAccessible( true );
		return $method;
	}

	private function file(): string {
		$file = $this->sandbox . '/f-' . bin2hex( random_bytes( 3 ) ) . '.txt';
		file_put_contents( $file, 'x' );
		return $file;
	}

	private function storage_log(): string {
		return (string) @file_get_contents( Plugin::instance()->directories()->base() . '/logs/storage.log' );
	}

	public function test_reclaim_and_purge_log_a_refusal_and_count_it_as_a_failure(): void {
		$delete = self::method( get_class( Plugin::instance()->jobs() ), 'delete_tree' );
		$result = $delete->invoke( Plugin::instance()->jobs(), 'relative', 'relative/target', 0 );
		$this->assertSame( array( 'relative/target' ), $result['failed'] );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertStringContainsString( 'Nothing was deleted: relative/target', $this->storage_log() );

		$file = $this->file();
		$this->assertSame( 1, $delete->invoke( Plugin::instance()->jobs(), $this->sandbox, $file, 0 )['deleted'], 'the control: a path it may delete' );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_switching_the_storage_directory_logs_a_refusal_and_goes_on(): void {
		$empty = self::method( get_class( Plugin::instance()->directories() ), 'empty_directory' );
		$empty->invoke( Plugin::instance()->directories(), 'relative-storage' );
		$this->assertStringContainsString( 'Nothing was deleted: relative-storage', $this->storage_log() );

		$file = $this->file();
		$empty->invoke( Plugin::instance()->directories(), $this->sandbox );
		$this->assertFileDoesNotExist( $file, 'the control: a directory it may empty' );
	}

	public function test_uninstalling_logs_a_refusal_and_counts_it_as_a_failure(): void {
		$tree   = self::method( Uninstaller::class, 'delete_tree' );
		$result = $tree->invoke( null, 'relative', 'relative/target' );
		$this->assertSame( array( 'relative/target' ), $result['failed'] );
		$empty  = self::method( Uninstaller::class, 'empty_directory' );
		$result = $empty->invoke( null, 'relative-storage' );
		$this->assertSame( array( 'relative-storage' ), $result['failed'] );
		$log = (string) file_get_contents( $this->php_log );
		$this->assertStringContainsString( 'Nothing was deleted: relative/target', $log );
		$this->assertStringContainsString( 'Nothing was deleted: relative-storage', $log );

		$file = $this->file();
		$this->assertSame( 1, $tree->invoke( null, $this->sandbox, $file )['deleted'], 'the control: a path it may delete' );
		$file = $this->file();
		$this->assertSame( 1, $empty->invoke( null, $this->sandbox )['deleted'], 'the control: a directory it may empty' );
		$this->assertFileDoesNotExist( $file );
	}
}
