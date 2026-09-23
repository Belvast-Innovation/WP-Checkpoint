<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Support\Thresholds;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The fallback cron interval as relations between constants, so that a
 * change to the budget, the lease or the interval fails here instead of
 * quietly breaking the reasoning in the Loopback docblock.
 */
final class LoopbackIntervalTest extends TestCase {

	/**
	 * WP_CRON_LOCK_TIMEOUT's default: WP-Cron starts at most one run per this many seconds.
	 */
	const WP_CRON_LOCK_TIMEOUT = 60;

	public function test_a_shorter_interval_would_not_run_sooner(): void {
		$this->assertGreaterThanOrEqual( self::WP_CRON_LOCK_TIMEOUT, Loopback::FALLBACK_SECONDS );
	}

	public function test_a_live_chain_ticks_at_least_three_times_per_interval(): void {
		// So the event seldom lands on a tick in progress; when it does, it gets "busy" and moves on.
		$this->assertGreaterThanOrEqual( 3 * Thresholds::BUDGET_MAX_SECONDS, Loopback::FALLBACK_SECONDS );
	}

	public function test_after_a_killed_tick_the_wait_past_its_lease_is_shorter_than_a_lease(): void {
		// Every event that finds the lease held moves itself on by one interval, so the first event after the
		// lease runs out comes at most one interval later: less than waiting out a second lease.
		$this->assertLessThan( JobRepository::LOCK_SECONDS, Loopback::FALLBACK_SECONDS );
	}
}
