<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\TickUnits;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What TickUnits keeps across ticks (estimates(), read back from the cursor): only kinds it names and times it can
 * use, at most MAX_KINDS, each its slowest in the last tick that ran one.
 */
final class TickUnitsTest extends TestCase {

	public function test_it_keeps_each_kinds_slowest_of_the_last_tick_that_ran_one(): void {
		$units = new TickUnits(
			array(
				'a.unit' => 9.0,
				'c.unit' => 4.0,
			)
		);
		$units->took( 'a.unit', 1.0 );
		$units->took( 'b.unit', 0.5 );
		$units->took( 'a.unit', 2.25 );
		$this->assertSame(
			array(
				'c.unit' => 4.0,
				'a.unit' => 2.25,
				'b.unit' => 0.5,
			),
			$units->estimates(),
			'a kind this tick ran: its slowest of this tick, not the 9 seconds kept (one slow unit weighs on the next tick only); a kind it did not run: as kept'
		);
	}

	public function test_a_tick_whose_units_of_a_kind_cost_nothing_keeps_what_was_kept(): void {
		$units = new TickUnits( array( 'pack.unit' => 12.0 ) );
		$units->took( 'pack.unit', 0.0 ); // A file boundary, a seal: told as costing nothing (PackStep).
		$units->took( 'new.unit', 0.0 );
		$this->assertSame(
			array(
				'pack.unit' => 12.0,
				'new.unit'  => 0.0,
			),
			$units->estimates(),
			'nothing measured this tick but nothing: the 12 seconds kept stay; a kind with nothing kept is measured at 0'
		);
		$units->took( 'pack.unit', 3.0 );
		$this->assertSame( 3.0, $units->estimates()['pack.unit'], 'measured this tick: replaced' );
	}

	public function test_what_it_reads_back_is_only_what_it_can_use(): void {
		$units = new TickUnits(
			array(
				'good.kind'  => 1.5,
				'Bad Kind'   => 1.0,
				'neg.kind'   => -1.0,
				'text.kind'  => '2',
				'inf.kind'   => INF,
				'int.kind'   => 3,
				7            => 1.0,
			)
		);
		$this->assertSame(
			array(
				'good.kind' => 1.5,
				'int.kind'  => 3.0,
			),
			$units->estimates()
		);
		$this->assertSame( array(), ( new TickUnits( 'not an array' ) )->estimates() );
		$units->took( 'Bad Kind', 9.0 );
		$units->took( 'nan.kind', NAN );
		$this->assertSame( array( 'good.kind', 'int.kind' ), array_keys( $units->estimates() ), 'a kind it cannot name, a time it cannot use: not kept' );
	}

	public function test_it_keeps_at_most_max_kinds_the_latest_last(): void {
		$units = new TickUnits();
		for ( $i = 0; $i < TickUnits::MAX_KINDS + 3; $i++ ) {
			$units->took( 'k' . $i, 1.0 );
		}
		$kept = array_keys( $units->estimates() );
		$this->assertCount( TickUnits::MAX_KINDS, $kept );
		$this->assertSame( 'k3', $kept[0], 'the oldest went' );
		$this->assertSame( 'k' . ( TickUnits::MAX_KINDS + 2 ), end( $kept ) );
	}
}
