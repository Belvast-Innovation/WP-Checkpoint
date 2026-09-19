<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\BudgetExhausted;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class BudgetTest extends TestCase {

	public function test_unknown_limits_fall_back_to_the_current_process_not_to_a_fixed_number(): void {
		$here = array( 'memory_bytes' => 256 * 1048576, 'max_execution_time' => 30 );
		$b    = Budget::compute( null, null, 60 * 1048576, $here );
		$this->assertTrue( $b->assumed, 'still marked as a fallback: the probe result is the authority when it exists' );
		$this->assertSame( 15, $b->seconds, 'half of the process limit' );
		$this->assertSame( 32 * 1048576, $b->memory_bytes, '256 MB - 60 MB used - 8 MB headroom leaves far more than the cap' );

		$b = Budget::compute( null, null, 60 * 1048576, array( 'memory_bytes' => 80 * 1048576, 'max_execution_time' => 0 ) );
		$this->assertSame( 12 * 1048576, $b->memory_bytes, 'the cap yields to what the limit leaves free' );
		$this->assertSame( 20, $b->seconds, 'unlimited time uses the cap' );

		$this->assertSame( 15, Budget::from_probe( null, 0, $here )->seconds );
		$this->assertTrue( Budget::from_probe( array( 'memory_bytes' => 1 ), 0, $here )->assumed, 'incomplete probe data counts as unknown' );
		$this->assertSame( 32 * 1048576, Budget::compute( null, null, 500 * 1048576, array( 'memory_bytes' => -1, 'max_execution_time' => 0 ) )->memory_bytes, 'an unlimited process uses the growth cap' );

		// Without an injected fallback the process's own ini values are read.
		$live = Budget::current_runtime();
		$this->assertSame( Budget::ini_bytes( (string) ini_get( 'memory_limit' ) ), $live['memory_bytes'] );
		$this->assertSame( (int) ini_get( 'max_execution_time' ), $live['max_execution_time'] );
	}

	public function test_a_process_that_already_uses_more_than_the_limit_allows_is_an_error_not_a_zero_budget(): void {
		// The phpunit case that exposed it: a fixed 64 MB assumption against a process at 58 MB gave a budget of 0,
		// every tick stopped at once and the job failed after three empty ticks with no reason a user could act on.
		try {
			Budget::compute( null, null, 58 * 1048576, array( 'memory_bytes' => 64 * 1048576, 'max_execution_time' => 20 ) );
			$this->fail( 'a budget below the floor must not be returned as zero' );
		} catch ( BudgetExhausted $e ) {
			$this->assertStringContainsString( 'memory limit of this server (64 MB)', $e->getMessage() );
			$this->assertStringContainsString( '58 MB already in use', $e->getMessage() );
			$this->assertStringContainsString( 'raise memory_limit', $e->getMessage() );
		}
		try {
			Budget::compute( 40 * 1048576, 30, 40 * 1048576 );
			$this->fail( 'the same with a measured limit' );
		} catch ( BudgetExhausted $e ) {
			$this->addToAssertionCount( 1 );
		}
		try {
			Budget::compute( 256 * 1048576, 3, 0 );
			$this->fail( 'an execution time below the minimum step time is an error too' );
		} catch ( BudgetExhausted $e ) {
			$this->assertStringContainsString( '3 seconds', $e->getMessage() );
			$this->assertStringContainsString( 'raise max_execution_time', $e->getMessage() );
		}
		// Exactly at the floor is fine.
		$this->assertSame( 4 * 1048576, Budget::compute( 52 * 1048576, 30, 40 * 1048576 )->memory_bytes );
		$this->assertSame( 5, Budget::compute( 256 * 1048576, 5, 0 )->seconds );
	}

	public function test_ini_sizes_parse_like_php(): void {
		$this->assertSame( -1, Budget::ini_bytes( '-1' ) );
		$this->assertSame( 268435456, Budget::ini_bytes( '256M' ) );
		$this->assertSame( 268435456, Budget::ini_bytes( ' 256m ' ) );
		$this->assertSame( 1073741824, Budget::ini_bytes( '1G' ) );
		$this->assertSame( 524288, Budget::ini_bytes( '512K' ) );
		$this->assertSame( 134217728, Budget::ini_bytes( '134217728' ) );
		$this->assertSame( 0, Budget::ini_bytes( 'lots' ) );
		$this->assertSame( 0, Budget::ini_bytes( '' ) );
	}

	public function test_measured_limits(): void {
		$b = Budget::compute( 256 * 1048576, 300, 30 * 1048576 );
		$this->assertFalse( $b->assumed );
		$this->assertSame( 20, $b->seconds );
		$this->assertSame( 32 * 1048576, $b->memory_bytes );

		$b = Budget::compute( 48 * 1048576, 8, 30 * 1048576 );
		$this->assertSame( 5, $b->seconds, 'floor' );
		$this->assertSame( 10 * 1048576, $b->memory_bytes );

		$b = Budget::compute( -1, 0, 500 * 1048576 );
		$this->assertSame( 20, $b->seconds, 'unlimited time uses the cap' );
		$this->assertSame( 32 * 1048576, $b->memory_bytes, 'unlimited memory uses the growth cap' );

		$b = Budget::from_probe( array( 'memory_bytes' => 134217728, 'max_execution_time' => 30 ), 0 );
		$this->assertFalse( $b->assumed );
		$this->assertSame( 15, $b->seconds );
	}
}
