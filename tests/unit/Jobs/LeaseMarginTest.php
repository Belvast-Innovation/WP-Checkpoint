<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Support\Thresholds;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The margin between a unit and the lease, as a relation between the
 * constants rather than a number in a comment: the Runner renews the lease
 * when less than half of it is left and checks it before every unit
 * (JobContext::should_stop()), so every unit starts with at least half of
 * the lease ahead of it. A unit may run up to the time budget before the
 * measured-duration guard notices it ran long; three budgets must fit in
 * half a lease, so that a unit running three times its budget still ends
 * inside the lease. Raise the budget or shorten the lease and this fails,
 * instead of the margin quietly going away.
 */
final class LeaseMarginTest extends TestCase {

	public function test_three_unit_budgets_fit_in_half_a_lease(): void {
		$this->assertLessThanOrEqual( intdiv( JobRepository::LOCK_SECONDS, 2 ), Thresholds::BUDGET_MAX_SECONDS * 3 );
	}
}
