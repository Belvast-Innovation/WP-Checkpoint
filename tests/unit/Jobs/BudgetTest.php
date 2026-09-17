<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Budget;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class BudgetTest extends TestCase {

	public function test_unknown_limits_use_the_conservative_assumptions(): void {
		$b = Budget::compute( null, null, 20 * 1048576 );
		$this->assertTrue( $b->assumed );
		$this->assertSame( 10, $b->seconds, 'half of the assumed 20 seconds' );
		$this->assertSame( 32 * 1048576, $b->memory_bytes, '64 MB - 20 MB used - 8 MB headroom = 36 MB, capped at 32 MB' );

		$b = Budget::compute( null, null, 40 * 1048576 );
		$this->assertSame( 16 * 1048576, $b->memory_bytes, 'the cap yields to what the limit leaves free' );
		$this->assertSame( 10, Budget::from_probe( null )->seconds );
		$this->assertTrue( Budget::from_probe( array( 'memory_bytes' => 1 ) )->assumed, 'incomplete probe data counts as unknown' );
	}

	public function test_measured_limits(): void {
		$b = Budget::compute( 256 * 1048576, 300, 30 * 1048576 );
		$this->assertFalse( $b->assumed );
		$this->assertSame( 20, $b->seconds );
		$this->assertSame( 32 * 1048576, $b->memory_bytes );

		$b = Budget::compute( 48 * 1048576, 8, 30 * 1048576 );
		$this->assertSame( 5, $b->seconds, 'floor' );
		$this->assertSame( 10 * 1048576, $b->memory_bytes );

		$b = Budget::compute( 40 * 1048576, 30, 40 * 1048576 );
		$this->assertSame( 0, $b->memory_bytes, 'never negative' );

		$b = Budget::compute( -1, 0, 500 * 1048576 );
		$this->assertSame( 20, $b->seconds, 'unlimited time uses the cap' );
		$this->assertSame( 32 * 1048576, $b->memory_bytes, 'unlimited memory uses the growth cap' );

		$b = Budget::from_probe( array( 'memory_bytes' => 134217728, 'max_execution_time' => 30 ), 0 );
		$this->assertFalse( $b->assumed );
		$this->assertSame( 15, $b->seconds );
	}
}
