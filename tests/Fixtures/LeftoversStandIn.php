<?php

namespace WPCheckpoint\Tests\Fixtures;

use PHPUnit\Framework\TestCase;

/**
 * A stand-in test the leftover check reports a class's or the run's failure through (Leftovers), named after it:
 * never run, only started, failed and ended, so result printers and logs record the failure like a test's.
 */
final class LeftoversStandIn extends TestCase {

	/**
	 * Never run.
	 *
	 * @return void
	 */
	public function test_stand_in(): void {
		$this->markTestSkipped( 'A stand-in for reporting; never run.' );
	}
}
