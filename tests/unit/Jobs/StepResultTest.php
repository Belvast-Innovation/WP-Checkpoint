<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\StepResult;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class StepResultTest extends TestCase {

	public function test_constructors_and_clamping(): void {
		$r = StepResult::progress( array( 'i' => 3 ), 140, 'x' );
		$this->assertSame( StepResult::PROGRESS, $r->kind );
		$this->assertSame( array( 'i' => 3 ), $r->cursor );
		$this->assertSame( 100, $r->percent );
		$this->assertSame( 'x', $r->message );

		$r = StepResult::done( 'finished' );
		$this->assertSame( StepResult::DONE, $r->kind );
		$this->assertSame( 100, $r->percent );
		$this->assertSame( array(), $r->cursor );

		$r = StepResult::wait( 0, array( 'k' => 1 ), 'rate limited' );
		$this->assertSame( StepResult::WAIT, $r->kind );
		$this->assertSame( 1, $r->seconds, 'at least one second' );
		$this->assertSame( array( 'k' => 1 ), $r->cursor );
	}

	public function test_overall_progress_mapping(): void {
		$this->assertSame( 0, Runner::overall( 0, 2, 0 ) );
		$this->assertSame( 25, Runner::overall( 0, 2, 50 ) );
		$this->assertSame( 50, Runner::overall( 1, 2, 0 ) );
		$this->assertSame( 100, Runner::overall( 1, 2, 100 ) );
		$this->assertSame( 33, Runner::overall( 1, 3, 0 ) );
		$this->assertSame( 0, Runner::overall( 0, 0, 50 ), 'no steps' );
		$this->assertSame( 100, Runner::overall( 0, 1, 250 ), 'percent clamped' );
		$this->assertSame( 0, Runner::overall( 0, 1, -5 ), 'percent clamped below' );
	}
}
