<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Residue;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ResidueTest extends TestCase {

	/** @var string */
	private $base;

	protected function set_up(): void {
		$this->base = sys_get_temp_dir() . '/wpcheckpoint-residue-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->base . '/tmp', 0700, true );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
	}

	public function test_work_directory_names_round_trip_and_reject_anything_else(): void {
		$this->assertSame( $this->base . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'job-42', Residue::work_dir( $this->base, 42 ) );
		$this->assertSame( 42, Residue::work_dir_id( 'job-42' ) );
		foreach ( array( 'job-0', 'job-', 'job-42.lock', 'job-4a', 'job--1', 'jobs-42', 'job-042', '' ) as $bad ) {
			$this->assertSame( 0, Residue::work_dir_id( $bad ), $bad );
		}
		$this->assertMatchesRegularExpression( '#/tmp/verify-[0-9a-f]{16}$#', str_replace( '\\', '/', Residue::new_verify_dir( $this->base . '/tmp' ) ) );
	}

	public function test_scan_classifies_everything_under_tmp_and_ignores_the_rest(): void {
		$tmp = $this->base . '/tmp';
		mkdir( $tmp . '/job-7' );
		touch( $tmp . '/job-7/site.part001.wpcheckpoint.zip.partial' );
		mkdir( $tmp . '/job-9' );
		mkdir( $tmp . '/verify-0123456789abcdef' );
		touch( $tmp . '/stray.partial' );
		touch( $tmp . '/stray.cdr' );
		touch( $tmp . '/job-3.lock' );
		touch( $tmp . '/index.php' );
		touch( $tmp . '/.htaccess' );
		touch( $tmp . '/job-11' ); // A file, not a directory.
		mkdir( $tmp . '/other-dir' );
		touch( $tmp . '/.partial' );

		$seen = array();
		foreach ( Residue::scan( $this->base ) as $entry ) {
			$seen[] = $entry['kind'] . ':' . basename( $entry['path'] ) . ':' . $entry['id'];
			$this->assertGreaterThan( 0, $entry['mtime'] );
		}
		sort( $seen );
		$this->assertSame(
			array( 'stray:stray.cdr:0', 'stray:stray.partial:0', 'verify_dir:verify-0123456789abcdef:0', 'work_dir:job-7:7', 'work_dir:job-9:9' ),
			$seen
		);
		$this->assertSame( array(), Residue::scan( $this->base . '/nope' ) );
		$this->assertSame( array(), Residue::scan( '' ) );
	}

	public function test_unowned_entries_expire_by_kind(): void {
		$now    = 1000000000;
		$verify = array( 'kind' => Residue::VERIFY_DIR, 'path' => 'x', 'id' => 0, 'mtime' => $now - Residue::VERIFY_TTL + 1 );
		$this->assertFalse( Residue::is_expired( $verify, $now ) );
		$verify['mtime'] = $now - Residue::VERIFY_TTL;
		$this->assertTrue( Residue::is_expired( $verify, $now ) );
		$stray = array( 'kind' => Residue::STRAY, 'path' => 'x', 'id' => 0, 'mtime' => $now - Residue::VERIFY_TTL );
		$this->assertFalse( Residue::is_expired( $stray, $now ), 'stray files wait seven days, not one' );
		$stray['mtime'] = $now - Residue::STRAY_TTL;
		$this->assertTrue( Residue::is_expired( $stray, $now ) );
		$work = array( 'kind' => Residue::WORK_DIR, 'path' => 'x', 'id' => 5, 'mtime' => 0 );
		$this->assertFalse( Residue::is_expired( $work, $now ), 'work directories follow their job, never the clock' );
	}
}
