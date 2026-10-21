<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\AtomicFile;
use WPCheckpoint\Support\AtomicWriteFailed;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\DeletionRefused;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The swap's maintenance file: the same code in .maintenance for every restore, the restore's mark, time and pass
 * hash in a data file next to it; put up whole (the data file first), refreshed, and taken down (.maintenance first)
 * only while they are this restore's; anyone else's is never written over or removed. The single file #82 wrote is
 * still read. The code lets a request through by the same rule as passes(). Their temporary names and an unread data
 * file are residue the reaper knows, and the Deleter removes nothing else in that directory.
 */
final class MaintenanceTest extends TestCase {

	const MARK = 'WP Checkpoint restore 0123456789abcdef0123456789abcdef';

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
		return new Maintenance( $this->dir, self::MARK );
	}

	private function names(): array {
		$names = array_values( array_diff( (array) scandir( $this->dir ), array( '.', '..' ) ) );
		sort( $names );
		return $names;
	}

	private function code(): string {
		return (string) file_get_contents( $this->dir . '/.maintenance' );
	}

	private function data(): string {
		return (string) file_get_contents( $this->dir . '/.wpcheckpoint-maintenance.php' );
	}

	public function test_this_restores_file_is_put_up_refreshed_and_taken_down(): void {
		$file = $this->ours();
		$this->assertSame( Maintenance::NONE, $file->state() );
		$file->put( 1800000000 );
		$this->assertSame( array( '.maintenance', '.wpcheckpoint-maintenance.php' ), $this->names(), 'the two files: no temporary one left' );
		$this->assertSame( Maintenance::OURS, $file->state() );
		$this->assertSame( Maintenance::CODE, $this->code(), 'the same code for every restore' );
		$this->assertSame( "<?php exit; ?>\n" . self::MARK . "\n1800000000\n-\n", $this->data() );
		$this->assertSame( 1800000000, $file->time() );
		$file->put( 1800000060 );
		$this->assertSame( 1800000060, $file->time(), 'refreshed' );
		$this->assertSame( Maintenance::CODE, $this->code() );
		$this->assertTrue( $file->remove() );
		$this->assertSame( array(), $this->names() );
		$this->assertSame( Maintenance::NONE, $file->state() );
		$this->assertTrue( $file->remove(), 'none there: nothing of this restore\'s is there' );
	}

	public function test_the_data_file_prints_nothing_when_it_is_requested(): void {
		$this->ours()->hold( null, str_repeat( 'ab', 32 ) );
		$out = array();
		$rc  = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $this->dir . '/.wpcheckpoint-maintenance.php' ), $out, $rc );
		$this->assertSame( 0, $rc );
		$this->assertSame( array(), $out, 'nothing of the mark, the time or the hash' );
		$this->assertStringContainsString( self::MARK, $this->data(), 'the control: they are in it' );
	}

	public function test_a_new_mark_is_random_and_tells_nothing_else(): void {
		$one = Maintenance::new_mark();
		$this->assertMatchesRegularExpression( '/\AWP Checkpoint restore [0-9a-f]{32}\z/', $one );
		$this->assertNotSame( $one, Maintenance::new_mark() );
	}

	public function test_a_held_file_never_lapses_and_is_still_this_restores(): void {
		$file = $this->ours();
		$file->put( 1800000000 );
		$this->assertFalse( $file->is_held(), 'the control: a file with the time is not held' );
		$this->assertFalse( Maintenance::held_in( $this->dir ), 'the control: one with the time is not held' );
		$file->hold();
		$this->assertTrue( $file->is_held() );
		$this->assertSame( Maintenance::OURS, $file->state() );
		$this->assertSame( Maintenance::held(), $file->time() );
		$this->assertTrue( Maintenance::held_in( $this->dir ), 'a held file of this plugin is there' );
		$this->assertSame( self::MARK, Maintenance::mark_in( $this->dir ) );
		$this->assertTrue( $file->remove() );
		$this->assertFalse( Maintenance::held_in( $this->dir ) );
		$this->assertSame( '', Maintenance::mark_in( $this->dir ) );
		file_put_contents( $this->dir . '/.maintenance', "<?php\n\$upgrading = " . Maintenance::held() . "; // an update\n" );
		$this->assertFalse( Maintenance::held_in( $this->dir ), 'someone else\'s, whatever its time' );
	}

	public function test_the_code_with_no_data_file_it_reads_blocks_the_site_and_is_taken_down_by_the_restore(): void {
		$file = $this->ours();
		foreach ( array(
			'no data file'          => null,
			'a data file garbled'   => "<?php exit; ?>\n" . self::MARK . "\n1800000000\n",
			'a data file truncated' => "<?php exit; ?>\nWP Check",
		) as $what => $data ) {
			file_put_contents( $this->dir . '/.maintenance', Maintenance::CODE );
			if ( null !== $data ) {
				file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $data );
			}
			$this->assertSame( Maintenance::BARE, $file->state(), $what );
			$this->assertTrue( Maintenance::held_in( $this->dir ), $what . ': it blocks every request, and nothing lets it lapse' );
			$this->assertSame( '', Maintenance::mark_in( $this->dir ), $what . ': it names no restore' );
			$this->assertTrue( Maintenance::code_in( $this->dir ), $what );
			$this->assertNull( $file->time(), $what );
			$this->assertTrue( $file->remove(), $what );
			$this->assertSame( array(), $this->names(), $what . ': the code, and a data file of no form, are gone' );
		}
		// A restore that holds the site writes its data file next to it.
		file_put_contents( $this->dir . '/.maintenance', Maintenance::CODE );
		$file->hold();
		$this->assertSame( Maintenance::OURS, $file->state() );
		$this->assertTrue( $file->is_held() );
	}

	public function test_the_single_file_82_wrote_is_read_held_and_taken_down_and_a_put_writes_the_two_files(): void {
		$file = $this->ours();
		file_put_contents( $this->dir . '/.maintenance', $file->legacy( Maintenance::held() ) );
		$this->assertSame( Maintenance::OURS, $file->state() );
		$this->assertTrue( $file->is_held() );
		$this->assertTrue( Maintenance::held_in( $this->dir ) );
		$this->assertSame( self::MARK, Maintenance::mark_in( $this->dir ) );
		$this->assertFalse( Maintenance::code_in( $this->dir ), 'not the code: it reads no data file' );
		$this->assertSame( array( '.maintenance' ), $this->names(), 'read, not rewritten' );
		$file->put( 1800000000 );
		$this->assertSame( Maintenance::CODE, $this->code() );
		$this->assertSame( 1800000000, $file->time() );
		$this->assertTrue( $file->remove() );
		file_put_contents( $this->dir . '/.maintenance', $file->legacy( 1800000000 ) );
		$this->assertFalse( $file->is_held(), 'the control: with the time it is not held' );
		$this->assertFalse( Maintenance::held_in( $this->dir ) );
		$this->assertTrue( $file->remove() );
		$this->assertSame( array(), $this->names() );
	}

	public function test_a_run_that_died_between_the_two_files_leaves_a_data_file_that_blocks_nothing_and_goes(): void {
		$file = $this->ours();
		// Put up: the data file first, the code never written (the run died between).
		file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $file->data( Maintenance::held() ) );
		$this->assertSame( Maintenance::NONE, $file->state(), 'no .maintenance: the site is not blocked' );
		$this->assertFalse( Maintenance::held_in( $this->dir ) );
		$this->assertFalse( Maintenance::code_in( $this->dir ) );
		$this->assertSame( self::MARK, Maintenance::data_mark( $this->dir ) );
		$this->assertTrue( $file->remove(), 'the data file goes' );
		$this->assertSame( array(), $this->names() );
		// Again, and the next put goes on from it.
		file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $file->data( 1800000000 ) );
		$file->hold();
		$this->assertTrue( $file->is_held() );
		// Taken down: .maintenance first, the data file never deleted (the run died between).
		Sandbox::remove( $this->dir . '/.maintenance' );
		$this->assertSame( Maintenance::NONE, $file->state() );
		$this->assertTrue( $file->remove() );
		$this->assertSame( array(), $this->names() );
	}

	public function test_a_put_or_a_removal_stopped_between_the_two_files_never_leaves_the_code_without_its_data(): void {
		$file = $this->ours();
		$calls = 0;
		$second = static function () use ( &$calls ): void {
			if ( 2 === ++$calls ) {
				throw new \RuntimeException( 'stopped between the two files' );
			}
		};
		try {
			$file->hold( $second );
			$this->fail( 'not stopped' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'stopped between the two files', $e->getMessage() );
		}
		$this->assertSame( array( '.wpcheckpoint-maintenance.php' ), $this->names(), 'put up: the data file first, nothing reads it yet' );
		$this->assertFalse( Maintenance::held_in( $this->dir ), 'the site is not blocked' );
		$file->hold();
		$this->assertTrue( $file->is_held(), 'the control: put up whole' );
		$calls = 0;
		try {
			$file->remove( $second );
			$this->fail( 'not stopped' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'stopped between the two files', $e->getMessage() );
		}
		$this->assertSame( array( '.wpcheckpoint-maintenance.php' ), $this->names(), 'taken down: the code first, its data file after' );
		$this->assertSame( Maintenance::NONE, $file->state() );
		$this->assertTrue( $file->remove(), 'and the rest goes the next time' );
		$this->assertSame( array(), $this->names() );
	}

	public function test_the_held_time_fits_the_integer_of_a_32_and_a_64_bit_php(): void {
		$this->assertSame( 4102444800, Maintenance::held_for( 8 ) );
		$this->assertSame( 2147483647, Maintenance::held_for( 4 ), 'the largest 32-bit integer: 2038' );
		foreach ( array( 4, 8 ) as $size ) {
			$this->assertIsInt( Maintenance::held_for( $size ) );
			$this->assertGreaterThan( time() + 10 * 365 * 86400, Maintenance::held_for( $size ), 'far beyond WordPress\'s ten minutes' );
		}
		$this->assertSame( Maintenance::held_for( PHP_INT_SIZE ), Maintenance::held() );
	}

	public function test_the_lease_is_checked_right_before_each_file_is_put_or_deleted_and_stops_it(): void {
		$file = $this->ours();
		$seen = array();
		try {
			$file->put(
				1800000000,
				function () use ( &$seen ): void {
					$seen[] = $this->names();
					throw new \RuntimeException( 'the lease is gone' );
				}
			);
			$this->fail( 'put up without the lease' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'the lease is gone', $e->getMessage() );
		}
		$this->assertCount( 1, $seen, 'asked once, before the first file' );
		$this->assertSame( array(), $this->names(), 'nothing put up, no temporary file left' );
		$asked = 0;
		$count = static function () use ( &$asked ): void {
			++$asked;
		};
		$file->put( 1800000000, $count );
		$this->assertSame( 2, $asked, 'the control: asked before each of the two files' );
		$seen = array();
		try {
			$file->remove(
				function () use ( &$seen, $file ): void {
					$seen[] = file_exists( $file->path() );
					throw new \RuntimeException( 'the lease is gone' );
				}
			);
			$this->fail( 'removed without the lease' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'the lease is gone', $e->getMessage() );
		}
		$this->assertSame( array( true ), $seen, 'asked once, with the file still there' );
		$this->assertSame( array( '.maintenance', '.wpcheckpoint-maintenance.php' ), $this->names(), 'and both stay' );
		$asked = 0;
		$this->assertTrue( $file->remove( $count ) );
		$this->assertSame( 2, $asked, 'the control: asked before each deletion' );
		$this->assertSame( array(), $this->names() );
	}

	public function test_another_maintenance_file_is_never_written_over_or_removed(): void {
		$file    = $this->ours();
		$another = new Maintenance( $this->dir, Maintenance::new_mark() );
		$others  = array(
			'an update'                          => array( "<?php \$upgrading = 1800000000; ?>", null ),
			'another restore\'s single file'     => array( $another->legacy( 1800000000 ), null ),
			'another restore\'s'                 => array( Maintenance::CODE, $another->data( 1800000000 ) ),
			'ours with more in it'               => array( $file->legacy( 1800000000 ) . "\$x = 1;\n", null ),
			'the code with more in it'           => array( Maintenance::CODE . "\n", $file->data( 1800000000 ) ),
			'ours with a time written otherwise' => array( str_replace( '1800000000;', '1800000000 ;', $file->legacy( 1800000000 ) ), null ),
		);
		foreach ( $others as $what => list( $code, $data ) ) {
			file_put_contents( $this->dir . '/.maintenance', $code );
			if ( null !== $data ) {
				file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $data );
			}
			$this->assertSame( Maintenance::OTHER, $file->state(), $what );
			try {
				$file->put( 1800000060 );
				$this->fail( 'written over: ' . $what );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'Another maintenance file', $e->getMessage() );
			}
			$this->assertTrue( $file->remove(), $what );
			$this->assertSame( $code, $this->code(), 'left as it was: ' . $what );
			if ( 'the code with more in it' === $what ) {
				// This restore's data file, read by nothing (.maintenance is not the code): it goes.
				$this->assertFileDoesNotExist( $this->dir . '/.wpcheckpoint-maintenance.php', $what );
			} elseif ( null !== $data ) {
				$this->assertSame( $data, $this->data(), 'another restore\'s data file left as it was: ' . $what );
				Sandbox::remove( $this->dir . '/.wpcheckpoint-maintenance.php' );
			}
			Sandbox::remove( $this->dir . '/.maintenance' );
		}
		// The control: the same files with this restore's contents are taken down.
		file_put_contents( $this->dir . '/.maintenance', Maintenance::CODE );
		file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $file->data( 1800000000 ) );
		$this->assertSame( Maintenance::OURS, $file->state() );
		$this->assertTrue( $file->remove() );
		$this->assertSame( array(), $this->names() );
	}

	public function test_a_link_or_a_directory_in_place_of_either_file_is_someone_elses(): void {
		$file   = $this->ours();
		$target = $this->dir . '/target';
		file_put_contents( $target, $file->legacy( 1800000000 ) ); // Even one that reads like ours through the link.
		symlink( $target, $this->dir . '/.maintenance' );
		$this->assertSame( Maintenance::OTHER, $file->state() );
		$this->assertTrue( $file->remove() );
		$this->assertTrue( is_link( $this->dir . '/.maintenance' ), 'the link stays' );
		Sandbox::remove( $this->dir . '/.maintenance' );
		file_put_contents( $this->dir . '/.maintenance', Maintenance::CODE );
		file_put_contents( $target, $file->data( 1800000000 ) );
		symlink( $target, $this->dir . '/.wpcheckpoint-maintenance.php' );
		$this->assertSame( Maintenance::OTHER, $file->state(), 'a link in place of the data file' );
		$this->assertTrue( $file->remove() );
		$this->assertTrue( is_link( $this->dir . '/.wpcheckpoint-maintenance.php' ), 'that link stays' );
		$this->assertSame( Maintenance::CODE, $this->code() );
		Sandbox::remove( $this->dir . '/.maintenance' );
		mkdir( $this->dir . '/.maintenance' );
		$this->assertSame( Maintenance::OTHER, $file->state() );
		$this->expectException( DeletionRefused::class );
		Deleter::delete_maintenance_file( $this->dir, '.maintenance' );
	}

	/**
	 * What the code in .maintenance does for a request: run in a PHP of its own (it may define DISABLE_WP_CRON).
	 *
	 * @param string|null $header X-WPCheckpoint-Pass, or null for none.
	 * @param bool        $cron   Whether DISABLE_WP_CRON is defined (false) before.
	 * @return array{upgrading: int, cron: mixed}
	 */
	private function request( $header, bool $cron = false ): array {
		$runner = $this->dir . '/runner.txt';
		file_put_contents(
			$runner,
			'<?php
' . ( null === $header ? '' : '$_SERVER["HTTP_X_WPCHECKPOINT_PASS"] = ' . var_export( $header, true ) . ";\n" ) . ( $cron ? "define( 'DISABLE_WP_CRON', false );\n" : '' ) . '$run = static function () {
	$upgrading = null;
	require ' . var_export( $this->dir . '/.maintenance', true ) . ';
	return $upgrading;
};
echo json_encode( array( "upgrading" => $run(), "cron" => defined( "DISABLE_WP_CRON" ) ? DISABLE_WP_CRON : null ) );
'
		);
		$out = array();
		$rc  = 1;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $runner ) . ' 2>/dev/null', $out, $rc );
		Sandbox::remove( $runner );
		$this->assertSame( 0, $rc, implode( "\n", $out ) );
		return json_decode( implode( '', $out ), true );
	}

	public function test_the_code_lets_a_request_through_by_the_same_rule_as_passes_and_runs_no_cron_in_it(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'Runs PHP with a POSIX redirection.' );
		}
		$key  = str_repeat( '0f', 32 );
		$file = $this->ours();
		$seen = array();
		foreach ( array(
			'the pass'              => array( 'pass', $key, true ),
			'no header'             => array( 'pass', null, false ),
			'an empty header'       => array( 'pass', '', false ),
			'another key'           => array( 'pass', str_repeat( 'f0', 32 ), false ),
			'the key upper-case'    => array( 'pass', strtoupper( $key ), false ),
			'the key and a newline' => array( 'pass', $key . "\n", false ),
			'the key cut short'     => array( 'pass', substr( $key, 1 ), false ),
			'its hash'              => array( 'pass', hash( 'sha256', $key ), false ),
			'no pass held'          => array( 'none', $key, false ),
			'a lapsing file'        => array( 'lapse', $key, false ),
			'no data file'          => array( 'nodata', $key, false ),
			'a data file garbled'   => array( 'garbled', $key, false ),
		) as $what => list( $data, $header, $through ) ) {
			Sandbox::remove( $this->dir . '/.wpcheckpoint-maintenance.php' );
			file_put_contents( $this->dir . '/.maintenance', Maintenance::CODE );
			if ( 'pass' === $data ) {
				file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $file->data( Maintenance::held(), hash( 'sha256', $key ) ) );
			} elseif ( 'none' === $data ) {
				file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $file->data( Maintenance::held() ) );
			} elseif ( 'lapse' === $data ) {
				file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $file->data( 1800000000 ) );
			} elseif ( 'garbled' === $data ) {
				file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', substr( $file->data( Maintenance::held(), hash( 'sha256', $key ) ), 0, -1 ) );
			}
			$before = time();
			$run    = $this->request( $header );
			$this->assertSame( $through, Maintenance::passes( $this->dir, (string) $header ), $what . ': passes()' );
			if ( $through ) {
				$this->assertSame( 0, $run['upgrading'], $what . ': let through (a time long past)' );
				$this->assertTrue( $run['cron'], $what . ': no cron in it' );
			} else {
				$this->assertNull( $run['cron'], $what . ': DISABLE_WP_CRON left alone' );
				if ( 'lapse' === $data ) {
					$this->assertSame( 1800000000, $run['upgrading'], $what . ': the data file\'s time' );
				} elseif ( in_array( $data, array( 'nodata', 'garbled' ), true ) ) {
					$this->assertGreaterThanOrEqual( $before, $run['upgrading'], $what . ': blocked (the time now)' );
				} else {
					$this->assertSame( Maintenance::held(), $run['upgrading'], $what . ': held' );
				}
			}
			$seen[ $through ? 'through' : 'blocked' ] = true;
		}
		$this->assertCount( 2, $seen, 'both outcomes were seen' );
		// DISABLE_WP_CRON defined already: left as it is, and no error.
		file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $file->data( Maintenance::held(), hash( 'sha256', $key ) ) );
		$run = $this->request( $key, true );
		$this->assertSame( 0, $run['upgrading'] );
		$this->assertFalse( $run['cron'] );
		// Not the code: passes() lets nothing through, whatever the data file says.
		file_put_contents( $this->dir . '/.maintenance', $file->legacy( Maintenance::held() ) );
		$this->assertFalse( Maintenance::passes( $this->dir, $key ) );
	}

	public function test_the_code_is_checked_before_it_is_put_in_place(): void {
		Maintenance::check_code( $this->write_code( Maintenance::CODE ) );
		try {
			Maintenance::check_code( $this->write_code( "<?php\n\$upgrading = ;\n" ) );
			$this->fail( 'code that does not parse was let through' );
		} catch ( AtomicWriteFailed $e ) {
			$this->assertStringContainsString( 'does not parse', $e->getMessage() );
		}
		// The check stops AtomicFile before the rename: nothing is put in place, no temporary file stays.
		try {
			AtomicFile::write(
				$this->dir,
				'.maintenance',
				"<?php\n\$upgrading = ;\n",
				array( 'check' => array( Maintenance::class, 'check_code' ) )
			);
			$this->fail( 'put in place' );
		} catch ( AtomicWriteFailed $e ) {
			$this->assertStringContainsString( 'does not parse', $e->getMessage() );
		}
		$this->assertSame( array( 'checked.txt' ), $this->names() );
		AtomicFile::write( $this->dir, '.maintenance', Maintenance::CODE, array( 'check' => array( Maintenance::class, 'check_code' ) ) );
		$this->assertSame( Maintenance::CODE, $this->code(), 'the control: the code is put in place' );
	}

	private function write_code( string $code ): string {
		file_put_contents( $this->dir . '/checked.txt', $code );
		return $this->dir . '/checked.txt';
	}

	public function test_the_deleter_removes_only_the_maintenance_names_and_only_where_it_may(): void {
		foreach ( array( '.maintenance.0123456789abcdef.tmp', '.wpcheckpoint-maintenance.php', '.wpcheckpoint-maintenance.php.0123456789abcdef.tmp' ) as $name ) {
			file_put_contents( $this->dir . '/' . $name, 'x' );
			$this->assertTrue( Deleter::delete_maintenance_file( $this->dir, $name ), 'the control: it goes: ' . $name );
			$this->assertFileDoesNotExist( $this->dir . '/' . $name );
		}
		file_put_contents( $this->dir . '/wp-config.php', 'x' );
		file_put_contents( $this->dir . '/.maintenance.tmp', 'x' );
		foreach ( array( 'wp-config.php', '.maintenance.tmp', '../.maintenance', '', 'wpcheckpoint-maintenance.php', '.wpcheckpoint-maintenance.php.tmp' ) as $name ) {
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
		foreach ( array( '.maintenance', '.wpcheckpoint-maintenance.php' ) as $name ) {
			try {
				Deleter::delete_maintenance_file( dirname( ABSPATH ), $name );
				$this->fail( 'not refused outside the WordPress directory: ' . $name );
			} catch ( DeletionRefused $e ) {
				$this->assertStringContainsString( 'not in the WordPress directory', $e->getMessage() );
			}
		}
	}

	public function test_the_maintenance_files_are_written_only_in_the_wordpress_directory_or_a_registered_one(): void {
		foreach ( array( '.maintenance', '.wpcheckpoint-maintenance.php' ) as $name ) {
			try {
				// Refused before anything is created (the name check comes first): asserted, never written for real.
				AtomicFile::write( dirname( ABSPATH ), $name, 'x' );
				$this->fail( 'written: ' . $name );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Not a file name this plugin writes.', $e->getMessage() );
			}
		}
		AtomicFile::write( $this->dir, '.wpcheckpoint-maintenance.php', 'x' );
		$this->assertSame( array( '.wpcheckpoint-maintenance.php' ), $this->names(), 'the control: written in a registered directory' );
	}

	public function test_their_temporary_files_and_the_data_file_are_residue_the_reaper_knows(): void {
		foreach ( array( '.maintenance.0123456789abcdef.tmp', '.wpcheckpoint-maintenance.php.0123456789abcdef.tmp', '.wpcheckpoint-maintenance.php' ) as $name ) {
			file_put_contents( $this->dir . '/' . $name, 'x' );
			touch( $this->dir . '/' . $name, 1800000000 );
		}
		file_put_contents( $this->dir . '/.maintenance', 'x' );
		mkdir( $this->dir . '/.maintenance.fedcba9876543210.tmp' );
		// Upper-case hex is not the form: in a directory of its own (on a file system without case, the same name).
		mkdir( $this->dir . '/upper' );
		file_put_contents( $this->dir . '/upper/.maintenance.0123456789ABCDEF.tmp', 'x' );
		file_put_contents( $this->dir . '/upper/wpcheckpoint-maintenance.php', 'x' );
		$this->assertSame( array(), Residue::scan_maintenance( $this->dir . '/upper' ) );
		$found = array();
		foreach ( Residue::scan_maintenance( $this->dir ) as $entry ) {
			$found[ basename( $entry['path'] ) ] = $entry['kind'];
			$this->assertFalse( Residue::is_expired( $entry, 1800000000 + Residue::VERIFY_TTL - 1 ) );
			$this->assertTrue( Residue::is_expired( $entry, 1800000000 + Residue::VERIFY_TTL ) );
		}
		ksort( $found );
		$this->assertSame(
			array(
				'.maintenance.0123456789abcdef.tmp'                  => Residue::MAINTENANCE_TMP,
				'.wpcheckpoint-maintenance.php'                      => Residue::MAINTENANCE_DATA,
				'.wpcheckpoint-maintenance.php.0123456789abcdef.tmp' => Residue::MAINTENANCE_TMP,
			),
			$found,
			'regular files of the temporary forms, and the data file'
		);
	}

	public function test_whether_the_code_reads_a_data_file_is_told_by_positive_evidence(): void {
		$this->assertFalse( Maintenance::code_in( $this->dir ), 'none there' );
		file_put_contents( $this->dir . '/.maintenance', '<?php $upgrading = 1;' );
		$this->assertFalse( Maintenance::code_in( $this->dir ), 'someone else\'s' );
		file_put_contents( $this->dir . '/.maintenance', Maintenance::CODE );
		$this->assertTrue( Maintenance::code_in( $this->dir ), 'the control: the code' );
		$this->assertSame( '', Maintenance::data_mark( $this->dir ) );
		file_put_contents( $this->dir . '/.wpcheckpoint-maintenance.php', $this->ours()->data( 1 ) );
		$this->assertSame( self::MARK, Maintenance::data_mark( $this->dir ) );
		if ( 'Windows' !== PHP_OS_FAMILY && function_exists( 'posix_geteuid' ) && 0 !== posix_geteuid() ) {
			chmod( $this->dir, 0300 ); // Not listable: lstat still tells the file is there; reading it does not fail.
			chmod( $this->dir . '/.maintenance', 0000 );
			$this->assertNull( Maintenance::code_in( $this->dir ), 'not readable: it cannot be told' );
			chmod( $this->dir . '/.maintenance', 0644 );
			chmod( $this->dir, 0755 );
		}
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
