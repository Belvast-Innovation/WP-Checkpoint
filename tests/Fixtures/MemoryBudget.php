<?php

namespace WPCheckpoint\Tests\Fixtures;

/**
 * A memory bound a test can rely on: the process's memory limit is set, for
 * the time of a call, to what the process holds now plus the bound, and PHP
 * stops with a fatal error the moment the call needs more. It holds on every
 * PHP version and whatever ran before in the same process. A measurement
 * with memory_get_peak_usage() does neither: before PHP 8.2 the peak cannot
 * be reset, and an earlier test's peak hides the one being measured (the
 * assertion then passes whatever the call uses) or, subtracted from the
 * current usage, fails a call that used nothing.
 */
final class MemoryBudget {

	/**
	 * Run $call with at most $bytes more memory than the process holds now.
	 *
	 * @param int      $bytes Bound.
	 * @param callable $call  function(): mixed.
	 * @return mixed What $call returns.
	 */
	public static function within( int $bytes, callable $call ) {
		$limit = (string) ini_get( 'memory_limit' );
		gc_collect_cycles();
		if ( false === ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + $bytes ) ) ) {
			throw new \RuntimeException( 'The memory limit cannot be set here.' );
		}
		try {
			return $call();
		} finally {
			ini_set( 'memory_limit', $limit );
		}
	}
}
