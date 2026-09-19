<?php
/**
 * Time and memory budget of one job step.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Thresholds;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- BudgetExhausted messages contain numbers and fixed text only; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Computed from the task runtime limits measured by the loopback probe
 * (T004): the loopback request that runs the ticks may have other limits
 * than the request at hand. When the probe is missing (first run, cache
 * flushed, probe failed) the limits of the current process stand in;
 * they are never lower than what this process already uses, which a
 * fixed assumption was (64 MB, while a busy admin request sits at
 * 60-80 MB, so the budget came out as zero and every tick stopped at
 * once). The usual rule then derives the step budget: half the time
 * limit clamped to 5-20 seconds, memory growth capped at 32 MB and at
 * what the limit leaves free. A budget below the floors is an error
 * (BudgetExhausted) rather than a silent zero.
 */
final class Budget {

	const MEMORY_GROWTH_CAP = 33554432; // 32 MB
	const MEMORY_HEADROOM   = 8388608;  // 8 MB kept free below the limit
	const MEMORY_FLOOR      = 4194304;  // Below this a step cannot do one unit of work.

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
	 * Budget for measured runtime limits, falling back to the current
	 * process for each limit that is unknown.
	 *
	 * @param int|null                $memory_limit_bytes memory_limit of the task runtime in bytes (-1 unlimited), null when unknown.
	 * @param int|null                $max_execution_time max_execution_time in seconds (0 unlimited), null when unknown.
	 * @param int                     $current_usage      Current memory usage in bytes.
	 * @param array<string, int>|null $fallback           memory_bytes and max_execution_time of the current process (null: read ini).
	 * @return Budget
	 * @throws BudgetExhausted When the limits leave less than MEMORY_FLOOR or less than the minimum step time.
	 */
	public static function compute( $memory_limit_bytes, $max_execution_time, int $current_usage = 0, $fallback = null ): Budget {
		$assumed = null === $memory_limit_bytes || null === $max_execution_time;
		if ( $assumed ) {
			$fallback = is_array( $fallback ) ? $fallback : self::current_runtime();
			if ( null === $memory_limit_bytes ) {
				$memory_limit_bytes = (int) $fallback['memory_bytes'];
			}
			if ( null === $max_execution_time ) {
				$max_execution_time = (int) $fallback['max_execution_time'];
			}
		}

		if ( $max_execution_time > 0 && $max_execution_time < Thresholds::BUDGET_MIN_SECONDS ) {
			throw new BudgetExhausted( sprintf( 'The execution time limit of this server (%d seconds) is below the %d seconds a backup step needs; raise max_execution_time.', (int) $max_execution_time, Thresholds::BUDGET_MIN_SECONDS ) );
		}
		$seconds = Thresholds::time_budget( (int) $max_execution_time );

		$memory = self::MEMORY_GROWTH_CAP;
		if ( $memory_limit_bytes > 0 ) {
			$free   = (int) $memory_limit_bytes - $current_usage - self::MEMORY_HEADROOM;
			$memory = min( self::MEMORY_GROWTH_CAP, $free );
			if ( $memory < self::MEMORY_FLOOR ) {
				throw new BudgetExhausted( sprintf( 'The memory limit of this server (%d MB) leaves less than %d MB for a backup step after the %d MB already in use; raise memory_limit.', (int) round( $memory_limit_bytes / 1048576 ), (int) ( self::MEMORY_FLOOR / 1048576 ), (int) round( $current_usage / 1048576 ) ) );
			}
		}

		return new Budget( $seconds, $memory, $assumed );
	}

	/**
	 * The limits of the current process, as the fallback for a missing probe.
	 *
	 * @return array{memory_bytes: int, max_execution_time: int}
	 */
	public static function current_runtime(): array {
		return array(
			'memory_bytes'       => self::ini_bytes( (string) ini_get( 'memory_limit' ) ),
			'max_execution_time' => (int) ini_get( 'max_execution_time' ),
		);
	}

	/**
	 * An ini size ("256M", "1G", "-1") in bytes, without WordPress.
	 *
	 * @param string $value Ini value.
	 * @return int Bytes; -1 for unlimited; 0 when unparsable.
	 */
	public static function ini_bytes( string $value ): int {
		$value = trim( $value );
		if ( '-1' === $value ) {
			return -1;
		}
		if ( 1 !== preg_match( '/\A(\d+)\s*([kmg]?)\z/i', $value, $m ) ) {
			return 0;
		}
		$bytes = (int) $m[1];
		switch ( strtolower( $m[2] ) ) {
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
		}
		return $bytes;
	}

	/**
	 * Budget from a loopback probe result as cached by Environment.
	 *
	 * @param array<string, mixed>|null $runtime       The "runtime" array of the probe, or null.
	 * @param int                       $current_usage Current memory usage in bytes.
	 * @param array<string, int>|null   $fallback      Current-process limits (null: read ini).
	 * @return Budget
	 * @throws BudgetExhausted When the limits leave no room for a step.
	 */
	public static function from_probe( $runtime, int $current_usage = 0, $fallback = null ): Budget {
		$memory = null;
		$time   = null;
		if ( is_array( $runtime ) && isset( $runtime['memory_bytes'], $runtime['max_execution_time'] ) ) {
			$memory = (int) $runtime['memory_bytes'];
			$time   = (int) $runtime['max_execution_time'];
		}
		return self::compute( $memory, $time, $current_usage, $fallback );
	}
}
