<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;
use WPCheckpoint\Tests\Fixtures\UncommittedProbe;

/**
 * The job test case's tear-down drops the jobs table, which commits: it rolls the test's transaction back first.
 * What a test wrote and left uncommitted is not on the site for the test after it. Three tests, so that in any order
 * two of them run after another one's tear-down.
 */
final class JobTearDownRollbackTest extends JobTestCase {

	use UncommittedProbe;

	public static function tear_down_after_class(): void {
		parent::tear_down_after_class();
		self::remove_probes(); // What the last test's tear-down committed, were it to commit.
	}

	private function leave(): void {
		$this->see_no_row_and_leave_one();
	}

	public function test_a_row_left_uncommitted_is_gone_for_the_next_test_one(): void {
		$this->leave();
	}

	public function test_a_row_left_uncommitted_is_gone_for_the_next_test_two(): void {
		$this->leave();
	}

	public function test_a_row_left_uncommitted_is_gone_for_the_next_test_three(): void {
		$this->leave();
	}
}
