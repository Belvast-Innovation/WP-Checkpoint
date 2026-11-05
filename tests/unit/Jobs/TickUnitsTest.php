<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\TickUnits;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What TickUnits keeps across ticks (estimates(), read back from the cursor): only kinds it names and times it can
 * use, at most MAX_KINDS, each the slowest seen.
 */
final class TickUnitsTest extends TestCase {

	public function test_it_keeps_the_slowest_of_each_kind(): void {
		$units = new TickUnits( array( 'a.unit' => 2.0 ) );
		$units->took( 'a.unit', 1.0 );
		$units->took( 'b.unit', 0.5 );
		$units->took( 'a.unit', 3.25 );
		$this->assertSame(
			array(
				'b.unit' => 0.5,
				'a.unit' => 3.25,
			),
			$units->estimates()
		);
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
