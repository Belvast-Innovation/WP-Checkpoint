<?php
/**
 * The pace of runs without WordPress, kept in the process.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * WebPace kept in this object only (it lasts as long as the process): what WebRun uses unless it is given another.
 * Where the pace is kept across requests is the entries' (b2). Pure PHP.
 */
final class MemoryPace implements WebPace {

	/**
	 * Job id => array{held: int, until: int}.
	 *
	 * @var array<int, array{held: int, until: int}>
	 */
	private $jobs = array();

	/**
	 * Seconds before a run may try the job again (0: now).
	 *
	 * @param int $job_id Job id.
	 * @param int $now    Unix time.
	 * @return int
	 */
	public function wait( int $job_id, int $now ): int {
		return isset( $this->jobs[ $job_id ] ) ? max( 0, $this->jobs[ $job_id ]['until'] - $now ) : 0;
	}

	/**
	 * A run was held up on the job: one more, and the seconds before the next may try.
	 *
	 * @param int $job_id Job id.
	 * @param int $now    Unix time.
	 * @return int
	 */
	public function held_up( int $job_id, int $now ): int {
		$held                  = ( $this->jobs[ $job_id ]['held'] ?? 0 ) + 1;
		$seconds               = self::seconds( $held );
		$this->jobs[ $job_id ] = array(
			'held'  => $held,
			'until' => $now + $seconds,
		);
		return $seconds;
	}

	/**
	 * A run got through: the pace starts over.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public function cleared( int $job_id ): void {
		unset( $this->jobs[ $job_id ] );
	}

	/**
	 * The wait after the n-th held-up run in a row (WebPace::SCHEDULE).
	 *
	 * @param int $held How many in a row (1 for the first).
	 * @return int
	 */
	public static function seconds( int $held ): int {
		return WebPace::SCHEDULE[ min( max( 1, $held ), count( WebPace::SCHEDULE ) ) - 1 ];
	}
}
