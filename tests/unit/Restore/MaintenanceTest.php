<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\AtomicFile;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The swap's maintenance file: put up whole, refreshed, and taken down only while it holds this restore's
 * contents; anyone else's is never written over or removed. Its temporary names are residue the reaper knows,
 * and the Deleter removes nothing else in that directory.
 */
final class MaintenanceTest extends TestCase {

	/** @var string Stand-in for ABSPATH (a sandbox the plugin may delete in). */
	private $dir = '';

	protected function set_up(): void {
		parent::set_up();
		$this->dir = Sandbox::make( 'maintenance' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	private function ours(): Maintenance {
		return new Maintenance( $this->dir, 'WP Checkpoint restore 0123456789abcdef0123456789abcdef' );
	}

	private function names(): array {
		return array_values( array_diff( (array) scandir( $this->dir ), array( '.', '..' ) ) );
	}

	public function test_this_restores_file_is_put_up_refreshed_and_taken_down(): void {
		$file = $this->ours();
		$this->assertSame( Maintenance::NONE, $file->state() );
		$file->put( 1800000000 );
		$this->assertSame( array( '.maintenance' ), $this->names(), 'only the file: no temporary one left' );
		$this->assertSame( Maintenance::OURS, $file->state() );
		$this->assertSame( "<?php\n\$upgrading = 1800000000; // WP Checkpoint restore 0123456789abcdef0123456789abcdef\n", file_get_contents( $file->path() ) );
		$file->put( 1800000060 );
		$this->assertSame( 1800000060, $file->time_of( (string) file_get_contents( $file->path() ) ), 'refreshed' );
		$this->assertTrue( $file->remove() );
		$this->assertSame( array(), $this->names() );
		$this->assertSame( Maintenance::NONE, $file->state() );
		$this->assertTrue( $file->remove(), 'none there: nothing of this restore\'s is there' );
	}

	public function test_a_new_mark_is_random_and_tells_nothing_else(): void {
		$one = Maintenance::new_mark();
		$this->assertMatchesRegularExpression( '/\AWP Checkpoint restore [0-9a-f]{32}\z/', $one );
		$this->assertNotSame( $one, Maintenance::new_mark() );
	}

	public function test_another_maintenance_file_is_never_written_over_or_removed(): void {
		$file   = $this->ours();
		$others = array(
			'an update'              => "<?php \$upgrading = 1800000000; ?>",
			'another restore'        => ( new Maintenance( $this->dir, Maintenance::new_mark() ) )->contents( 1800000000 ),
			'ours with more in it'   => $file->contents( 1800000000 ) . "\$x = 1;\n",
			'ours with a time later' => str_replace( '1800000000;', '1800000000 ;', $file->contents( 1800000000 ) ),
		);
		foreach ( $others as $what => $contents ) {
			file_put_contents( $this->dir . '/.maintenance', $contents );
			$this->assertSame( Maintenance::OTHER, $file->state(), $what );
			try {
				$file->put( 1800000060 );
				$this->fail( 'written over: ' . $what );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'Another maintenance file', $e->getMessage() );
			}
			$this->assertTrue( $file->remove(), $what );
			$this->assertSame( $contents, file_get_contents( $this->dir . '/.maintenance' ), 'left as it was: ' . $what );
		}
		// The control: the same file with this restore's contents is taken down.
		file_put_contents( $this->dir . '/.maintenance', $file->contents( 1800000000 ) );
		$this->assertSame( Maintenance::OURS, $file->state() );
		$this->assertTrue( $file->remove() );
		$this->assertSame( array(), $this->names() );
	}

	public function test_a_link_or_a_directory_in_its_place_is_someone_elses(): void {
		$file   = $this->ours();
		$target = $this->dir . '/target';
		file_put_contents( $target, $file->contents( 1800000000 ) ); // Even one that reads like ours through the link.
		symlink( $target, $this->dir . '/.maintenance' );
		$this->assertSame( Maintenance::OTHER, $file->state() );
		$this->assertTrue( $file->remove() );
		$this->assertTrue( is_link( $this->dir . '/.maintenance' ), 'the link stays' );
		Sandbox::remove( $this->dir . '/.maintenance' );
		mkdir( $this->dir . '/.maintenance' );
		$this->assertSame( Maintenance::OTHER, $file->state() );
		$this->expectException( DeletionRefused::class );
		Deleter::delete_maintenance_file( $this->dir, '.maintenance' );
	}

	public function test_the_deleter_removes_only_the_maintenance_names_and_only_where_it_may(): void {
		file_put_contents( $this->dir . '/.maintenance.0123456789abcdef.tmp', 'x' );
		file_put_contents( $this->dir . '/wp-config.php', 'x' );
		file_put_contents( $this->dir . '/.maintenance.tmp', 'x' );
		$this->assertTrue( Deleter::delete_maintenance_file( $this->dir, '.maintenance.0123456789abcdef.tmp' ), 'the control: a temporary name goes' );
		$this->assertFileDoesNotExist( $this->dir . '/.maintenance.0123456789abcdef.tmp' );
		foreach ( array( 'wp-config.php', '.maintenance.tmp', '../.maintenance', '' ) as $name ) {
			try {
				Deleter::delete_maintenance_file( $this->dir, $name );
				$this->fail( 'deleted: ' . $name );
			} catch ( DeletionRefused $e ) {
				$this->assertStringContainsString( 'not a maintenance file', $e->getMessage(), $name );
			}
		}
		$this->assertFileExists( $this->dir . '/wp-config.php' );
		$this->assertFileExists( $this->dir . '/.maintenance.tmp' );
		// A directory the plugin may not delete in: refused before anything is looked at (asserted, never tried for real).
		try {
			Deleter::delete_maintenance_file( dirname( ABSPATH ), '.maintenance' );
			$this->fail( 'not refused outside the WordPress directory' );
		} catch ( DeletionRefused $e ) {
			$this->assertStringContainsString( 'not in the WordPress directory', $e->getMessage() );
		}
	}

	public function test_the_maintenance_file_is_written_only_in_the_wordpress_directory_or_a_registered_one(): void {
		$this->expectException( \InvalidArgumentException::class );
		// Refused before anything is created (the name check comes first): asserted, never written for real.
		AtomicFile::write( dirname( ABSPATH ), '.maintenance', 'x' );
	}

	public function test_its_temporary_files_are_residue_the_reaper_knows(): void {
		file_put_contents( $this->dir . '/.maintenance.0123456789abcdef.tmp', 'x' );
		touch( $this->dir . '/.maintenance.0123456789abcdef.tmp', 1800000000 );
		file_put_contents( $this->dir . '/.maintenance', 'x' );
		mkdir( $this->dir . '/.maintenance.fedcba9876543210.tmp' );
		// Upper-case hex is not the form: in a directory of its own (on a file system without case, the same name).
		mkdir( $this->dir . '/upper' );
		file_put_contents( $this->dir . '/upper/.maintenance.0123456789ABCDEF.tmp', 'x' );
		$this->assertSame( array(), Residue::scan_maintenance( $this->dir . '/upper' ) );
		$found = Residue::scan_maintenance( $this->dir );
		$this->assertCount( 1, $found, 'a regular file of the temporary form only' );
		$this->assertSame( Residue::MAINTENANCE_TMP, $found[0]['kind'] );
		$this->assertSame( '.maintenance.0123456789abcdef.tmp', basename( $found[0]['path'] ) );
		$this->assertFalse( Residue::is_expired( $found[0], 1800000000 + Residue::VERIFY_TTL - 1 ) );
		$this->assertTrue( Residue::is_expired( $found[0], 1800000000 + Residue::VERIFY_TTL ) );
	}

	public function test_a_staging_root_is_kept_while_its_stray_directory_holds_anything(): void {
		$root = $this->dir . '/wp-checkpoint-stage-a1b2c3d4e5f6-7-0123456789abcdef0123456789abcdef';
		mkdir( $root );
		$this->assertFalse( Residue::keeps_stray( $root ), 'no stray directory' );
		mkdir( $root . '/stray' );
		$this->assertFalse( Residue::keeps_stray( $root ), 'an empty one' );
		mkdir( $root . '/stray/uploads-0f0f' );
		$this->assertTrue( Residue::keeps_stray( $root ), 'one with an entry' );
	}
}
