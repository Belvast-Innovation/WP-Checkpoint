<?php

namespace WPCheckpoint\Tests\Fixtures;

/**
 * A memory bound a test can rely on: the process's memory limit is lowered,
 * for the time of a call, to what the process holds now plus the bound (or
 * left where it is when it is lower already), and PHP stops with a fatal
 * error when the call needs more. The limit is compared with the memory PHP
 * has taken from the system, which grows in 2 MiB blocks; a small allocation
 * can still use free room in a block taken before the call, so the bound is
 * exact for large allocations (the ones these tests are about) and may let a
 * few small ones through. It does not depend on the PHP version or on what
 * ran before in the process, as a memory_get_peak_usage() measurement does:
 * before PHP 8.2 the peak cannot be reset, and an earlier test's peak hides
 * the one being measured (the assertion then passes whatever the call uses)
 * or, subtracted from the current usage, fails a call that used nothing.
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
		gc_mem_caches();
		$bound = memory_get_usage( true ) + $bytes;
		$outer = self::bytes( $limit );
		if ( $outer > 0 && $outer < $bound ) {
			$bound = $outer; // A stricter limit set around the test stays.
		}
		if ( false === ini_set( 'memory_limit', (string) $bound ) ) {
			throw new \RuntimeException( 'The memory limit cannot be set here.' );
		}
		try {
			return $call();
		} finally {
			ini_set( 'memory_limit', $limit );
		}
	}

	/**
	 * A memory_limit value in bytes, or -1 for none.
	 *
	 * @param string $value As ini_get() gives it ("128M", "-1", "134217728").
	 * @return int
	 */
	private static function bytes( string $value ): int {
		if ( 1 !== preg_match( '/\A\s*(-?\d+)\s*([kmg]?)\s*\z/i', $value, $m ) || (int) $m[1] < 0 ) {
			return -1;
		}
		$units = array(
			''  => 1,
			'k' => 1024,
			'm' => 1048576,
			'g' => 1073741824,
		);
		return (int) $m[1] * $units[ strtolower( $m[2] ) ];
	}
}
