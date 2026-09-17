<?php
/**
 * Time and memory budget of one job step.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Thresholds;

/**
 * Computed from the task runtime limits measured by the loopback probe
 * (T004). When those are unknown, conservative assumptions apply: 64 MB of
 * memory and 20 seconds of execution time, from which the usual rule
 * derives the step budget (half the time limit clamped to 5-20 seconds,
 * memory growth capped at 32 MB and at what the limit leaves free).
 */
final class Budget {

	const ASSUMED_MEMORY_BYTES = 67108864; // 64 MB
	const ASSUMED_TIME_SECONDS = 20;
	const MEMORY_GROWTH_CAP    = 33554432; // 32 MB
	const MEMORY_HEADROOM      = 8388608;  // 8 MB kept free below the limit

	/**
	 * Seconds one step may run.
	 *
	 * @var int
	 */
	public $seconds;

	/**
	 * Bytes one step may add to the current usage.
	 *
	 * @var int
	 */
	public $memory_bytes;

	/**
	 * True when the runtime limits were unknown and assumptions were used.
	 *
	 * @var bool
	 */
	public $assumed;

	/**
	 * Constructor.
	 *
	 * @param int  $seconds      Time budget.
	 * @param int  $memory_bytes Memory growth budget.
	 * @param bool $assumed      Whether assumptions were used.
	 */
	public function __construct( int $seconds, int $memory_bytes, bool $assumed ) {
		$this->seconds      = $seconds;
		$this->memory_bytes = $memory_bytes;
		$this->assumed      = $assumed;
	}

	/**
	 * Budget for measured (or assumed) runtime limits.
	 *
	 * @param int|null $memory_limit_bytes memory_limit of the task runtime in bytes (-1 unlimited), null when unknown.
	 * @param int|null $max_execution_time max_execution_time in seconds (0 unlimited), null when unknown.
	 * @param int      $current_usage      Current memory usage in bytes.
	 * @return Budget
	 */
	public static function compute( $memory_limit_bytes, $max_execution_time, int $current_usage = 0 ): Budget {
		$assumed = null === $memory_limit_bytes || null === $max_execution_time;
		if ( null === $memory_limit_bytes ) {
			$memory_limit_bytes = self::ASSUMED_MEMORY_BYTES;
		}
		if ( null === $max_execution_time ) {
			$max_execution_time = self::ASSUMED_TIME_SECONDS;
		}

		$seconds = Thresholds::time_budget( (int) $max_execution_time );

		$memory = self::MEMORY_GROWTH_CAP;
		if ( $memory_limit_bytes > 0 ) {
			$free   = (int) $memory_limit_bytes - $current_usage - self::MEMORY_HEADROOM;
			$memory = max( 0, min( self::MEMORY_GROWTH_CAP, $free ) );
		}

		return new Budget( $seconds, $memory, $assumed );
	}

	/**
	 * Budget from a loopback probe result as cached by Environment.
	 *
	 * @param array<string, mixed>|null $runtime  The "runtime" array of the probe, or null.
	 * @param int                       $current_usage Current memory usage in bytes.
	 * @return Budget
	 */
	public static function from_probe( $runtime, int $current_usage = 0 ): Budget {
		$memory = null;
		$time   = null;
		if ( is_array( $runtime ) && isset( $runtime['memory_bytes'], $runtime['max_execution_time'] ) ) {
			$memory = (int) $runtime['memory_bytes'];
			$time   = (int) $runtime['max_execution_time'];
		}
		return self::compute( $memory, $time, $current_usage );
	}
}
