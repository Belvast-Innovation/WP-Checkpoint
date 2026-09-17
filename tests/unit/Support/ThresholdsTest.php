<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Check;
use WPCheckpoint\Support\Thresholds;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ThresholdsTest extends TestCase {

	public function test_memory_thresholds(): void {
		$this->assertSame( Check::OK, Thresholds::memory_status( -1 ), 'unlimited' );
		$this->assertSame( Check::ERROR, Thresholds::memory_status( 32 * 1048576 ) );
		$this->assertSame( Check::ERROR, Thresholds::memory_status( 64 * 1048576 - 1 ) );
		$this->assertSame( Check::WARNING, Thresholds::memory_status( 64 * 1048576 ) );
		$this->assertSame( Check::WARNING, Thresholds::memory_status( 128 * 1048576 - 1 ) );
		$this->assertSame( Check::OK, Thresholds::memory_status( 128 * 1048576 ) );
		$this->assertSame( Check::OK, Thresholds::memory_status( 512 * 1048576 ) );
	}

	public function test_execution_time_thresholds_and_budget(): void {
		$this->assertSame( Check::OK, Thresholds::time_status( 0 ) );
		$this->assertSame( Check::WARNING, Thresholds::time_status( 19 ) );
		$this->assertSame( Check::OK, Thresholds::time_status( 20 ) );
		$this->assertSame( Check::OK, Thresholds::time_status( 300 ) );

		$this->assertSame( 20, Thresholds::time_budget( 0 ), 'unlimited uses the cap' );
		$this->assertSame( 20, Thresholds::time_budget( 300 ) );
		$this->assertSame( 20, Thresholds::time_budget( 40 ) );
		$this->assertSame( 15, Thresholds::time_budget( 30 ) );
		$this->assertSame( 5, Thresholds::time_budget( 10 ) );
		$this->assertSame( 5, Thresholds::time_budget( 3 ), 'never below the floor' );
	}

	public function test_disk_thresholds_and_unknown_values(): void {
		foreach ( array( false, null, 0, -1, '123', INF, NAN, array() ) as $bad ) {
			$this->assertFalse( Thresholds::is_disk_value( $bad ) );
			$this->assertSame( Check::INFO, Thresholds::disk_status( $bad ) );
		}
		$this->assertSame( Check::ERROR, Thresholds::disk_status( 100 * 1048576 ) );
		$this->assertSame( Check::WARNING, Thresholds::disk_status( 500 * 1048576 ) );
		$this->assertSame( Check::OK, Thresholds::disk_status( 5 * 1073741824 ) );
		$this->assertSame( Check::OK, Thresholds::disk_status( 5.0 * 1073741824 ), 'float from disk_free_space' );
	}

	public function test_database_flavour_and_minimums(): void {
		$this->assertSame( array( 'flavor' => 'MySQL', 'version' => '8.0.36', 'status' => Check::OK ), Thresholds::database( '8.0.36' ) );
		$this->assertSame( array( 'flavor' => 'MySQL', 'version' => '5.6.51', 'status' => Check::WARNING ), Thresholds::database( '5.6.51-log' ) );
		$this->assertSame( array( 'flavor' => 'MariaDB', 'version' => '10.6.12', 'status' => Check::OK ), Thresholds::database( '5.5.5-10.6.12-MariaDB-0ubuntu0.22.04.1' ) );
		$this->assertSame( array( 'flavor' => 'MariaDB', 'version' => '10.3.39', 'status' => Check::WARNING ), Thresholds::database( '10.3.39-MariaDB' ) );
		$this->assertSame( Check::INFO, Thresholds::database( 'unknown' )['status'] );
	}

	public function test_loopback_outcomes(): void {
		$this->assertSame( Check::OK, Thresholds::loopback_status( 'reachable' ) );
		$this->assertSame( Check::WARNING, Thresholds::loopback_status( 'http_auth' ) );
		$this->assertSame( Check::WARNING, Thresholds::loopback_status( 'blocked' ) );
		$this->assertSame( Check::WARNING, Thresholds::loopback_status( 'redirected' ) );
		$this->assertSame( Check::ERROR, Thresholds::loopback_status( 'altered' ) );
		$this->assertSame( Check::ERROR, Thresholds::loopback_status( 'unreachable' ) );
	}

	public function test_check_summary_and_unknown_status_falls_back_to_info(): void {
		$checks = array(
			new Check( 'a', 'g', 'A', '1', Check::OK ),
			new Check( 'b', 'g', 'B', '2', Check::WARNING ),
			new Check( 'c', 'g', 'C', '3', 'bogus' ),
		);
		$this->assertSame( Check::INFO, $checks[2]->status );
		$this->assertSame( array( 'ok' => 1, 'warning' => 1, 'error' => 0, 'info' => 1 ), Check::summarize( $checks ) );
	}
}
