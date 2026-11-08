<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\MemoryPace;
use WPCheckpoint\Jobs\RuntimeLog;
use WPCheckpoint\Jobs\VisitorFile;
use WPCheckpoint\Jobs\WebPace;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The visitor file keeps the pace of held-up runs across requests as MemoryPace keeps it in one (the same schedule), and
 * its time is never set back by a write (the maintenance file's code reads it as when the runtime may next run); its
 * contents are this restore's or nothing. The runtime log is written only while this restore's data file is there,
 * names the restore first, keeps its newer half past its limit, and leaves another restore's alone.
 */
final class VisitorFileTest extends TestCase {

	const MARK = 'WP Checkpoint restore 0123456789abcdef0123456789abcdef';

	/**
	 * Sandbox.
	 *
	 * @var string
	 */
	private $dir = '';

	protected function set_up(): void {
		parent::set_up();
		$this->dir = Sandbox::make( 'visitor-file' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	private function mtime(): int {
		clearstatcache();
		return (int) filemtime( $this->dir . '/' . Maintenance::VISITOR );
	}

	public function test_the_pace_is_kept_in_the_file_as_in_memory_and_the_file_time_follows_it(): void {
		VisitorFile::create( $this->dir, self::MARK, 'wp-checkpoint/wp-checkpoint.php' );
		$this->assertStringStartsWith( "<?php exit; ?>\n# " . self::MARK . "\n", (string) file_get_contents( $this->dir . '/' . Maintenance::VISITOR ) );
		$file = new VisitorFile( $this->dir, self::MARK );
		$this->assertSame( 'wp-checkpoint/wp-checkpoint.php', $file->plugin() );
		$now = time();
		$this->assertSame( 0, $file->wait( 7, $now ) );
		$memory = new MemoryPace();
		foreach ( WebPace::SCHEDULE as $i => $seconds ) {
			$this->assertSame( $memory->held_up( 7, $now ), $file->held_up( 7, $now ), 'held up ' . ( $i + 1 ) . ' times' );
			$this->assertSame( $seconds, $file->wait( 7, $now ) );
			$this->assertSame( $now + $seconds, $this->mtime(), 'the code waits as long before it runs the runtime again' );
		}
		$this->assertSame( $memory->held_up( 7, $now ), $file->held_up( 7, $now ), 'past the schedule' );
		$this->assertSame( 0, $file->wait( 8, $now ), 'another job has its own' );
		// A new file object (the next request) reads the same.
		$this->assertSame( WebPace::SCHEDULE[ count( WebPace::SCHEDULE ) - 1 ], ( new VisitorFile( $this->dir, self::MARK ) )->wait( 7, $now ) );
		$file->cleared( 7 );
		$this->assertSame( 0, $file->wait( 7, $now ) );
		$this->assertSame( $now + WebPace::SCHEDULE[ count( WebPace::SCHEDULE ) - 1 ], $this->mtime(), 'a write never sets the time back' );
		$this->assertSame( 'wp-checkpoint/wp-checkpoint.php', $file->plugin(), 'the rest is kept' );
	}

	public function test_another_restores_file_or_one_that_cannot_be_read_is_no_pace_and_is_not_written(): void {
		VisitorFile::create( $this->dir, 'WP Checkpoint restore ' . str_repeat( 'f', 32 ), 'p/p.php' );
		$before = (string) file_get_contents( $this->dir . '/' . Maintenance::VISITOR );
		$file   = new VisitorFile( $this->dir, self::MARK );
		$this->assertSame( '', $file->plugin() );
		$this->assertSame( 0, $file->wait( 7, time() ) );
		$file->held_up( 7, time() );
		$this->assertSame( $before, file_get_contents( $this->dir . '/' . Maintenance::VISITOR ), 'another restore\'s: left as it is' );
		file_put_contents( $this->dir . '/' . Maintenance::VISITOR, "<?php exit; ?>\n# " . self::MARK . "\n{\"pace\":{\"7\":{\"held\":2,\"unt" ); // Half made.
		$this->assertSame( 0, $file->wait( 7, time() ), 'half made: no pace, the run is tried' );
		$this->assertSame( 0, ( new VisitorFile( $this->dir . '/none', self::MARK ) )->wait( 7, time() ), 'no file: no pace' );
	}

	public function test_the_runtime_log_is_written_only_while_the_restores_data_file_is_there(): void {
		$aux     = Sandbox::make( 'visitor-file-aux' );
		$php_log = $aux . '/php.log';
		$was     = ini_set( 'error_log', $php_log );
		try {
			$log = new RuntimeLog( $this->dir, self::MARK );
			$this->assertFalse( $log->write( 'before the maintenance file', 1800000000 ) );
			$this->assertFileDoesNotExist( $log->path() );
			$this->assertStringContainsString( 'before the maintenance file', (string) file_get_contents( $php_log ), 'said in PHP\'s log instead' );
			( new Maintenance( $this->dir, self::MARK ) )->hold();
			$this->assertTrue( $log->write( "one\nline", 1800000000 ) );
			$this->assertSame( "<?php exit; ?>\n# " . self::MARK . "\n2027-01-15 08:00:00 one line\n", file_get_contents( $log->path() ) );
			$this->assertSame( self::MARK, Maintenance::web_mark( $log->path() ) );
			$this->assertSame( array( '2027-01-15 08:00:00 one line' ), Maintenance::log_lines( $log->path() ) );
			// Requested, it prints nothing.
			$out = array();
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $log->path() ), $out );
			$this->assertSame( array(), $out );
			// Another restore's log: not written.
			$other = new RuntimeLog( $this->dir, 'WP Checkpoint restore ' . str_repeat( 'f', 32 ) );
			$this->assertFalse( $other->write( 'not mine', 1800000000 ), 'its data file is not there' );
		} finally {
			ini_set( 'error_log', (string) $was );
			Sandbox::remove( $aux );
		}
	}

	public function test_the_runtime_log_keeps_its_newer_half_past_its_limit(): void {
		( new Maintenance( $this->dir, self::MARK ) )->hold();
		$log  = new RuntimeLog( $this->dir, self::MARK );
		$line = str_repeat( 'x', 990 );
		for ( $i = 0; $i < 200; $i++ ) {
			$this->assertTrue( $log->write( $i . ' ' . $line, 1800000000 ) );
			clearstatcache();
			$this->assertLessThanOrEqual( RuntimeLog::MAX_BYTES, filesize( $log->path() ) );
		}
		$lines = Maintenance::log_lines( $log->path() );
		$this->assertStringStartsWith( '2027-01-15 08:00:00 199 ', (string) end( $lines ), 'the newest is kept' );
		$this->assertGreaterThan( 20, count( $lines ) );
		$this->assertStringStartsWith( "<?php exit; ?>\n# " . self::MARK . "\n", (string) file_get_contents( $log->path() ), 'and its header' );
	}
}
