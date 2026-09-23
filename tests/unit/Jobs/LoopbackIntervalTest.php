<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\Loopback;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The one hard relation behind the fallback cron interval (the rest is a
 * cost trade-off, described in the Loopback docblock): shorter than the
 * lease, so changing either fails here instead of quietly breaking it.
 */
final class LoopbackIntervalTest extends TestCase {

	public function test_after_a_killed_tick_the_wait_past_its_lease_is_shorter_than_a_lease(): void {
		// Every event that finds the lease held moves itself on by one interval, so the first event after the
		// lease runs out comes at most one interval later: less than waiting out a second lease.
		$this->assertLessThan( JobRepository::LOCK_SECONDS, Loopback::FALLBACK_SECONDS );
	}
}
