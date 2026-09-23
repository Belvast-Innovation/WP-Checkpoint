<?php
/**
 * What the empty Backups screen can say about the size of a first backup.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Backups;

use WPCheckpoint\Jobs\EstimateJob;
use WPCheckpoint\Jobs\ExportOptions;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobConflict;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Support\Environment;

defined( 'ABSPATH' ) || exit;

/**
 * The database size is known at once (table statistics). The files are
 * counted by the estimate job, started only from the screen's script (a
 * GET never starts a job) and only when there is no fresh count, no
 * estimate running and no estimate that failed within a day; an estimate
 * that cannot start now (an export or a restore runs) or that fails is not
 * an error: the screen shows the database size alone. A time is only given
 * from this site's own last export of the same scope (Estimate).
 */
final class EstimateStatus {

	/**
	 * Actions.
	 *
	 * @var JobActions
	 */
	private $actions;

	/**
	 * Database size: function(): int|null.
	 *
	 * @var callable
	 */
	private $database_size;

	/**
	 * Clock: function(): int.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param JobActions    $actions       Actions.
	 * @param callable|null $database_size function(): int|null (tests).
	 * @param callable|null $now           Clock (tests).
	 */
	public function __construct( JobActions $actions, $database_size = null, $now = null ) {
		$this->actions       = $actions;
		$this->database_size = is_callable( $database_size ) ? $database_size : array( Environment::class, 'query_database_size' );
		$this->now           = is_callable( $now ) ? $now : 'time';
	}

	/**
	 * The status; with $start, an estimate job is started when one is due.
	 *
	 * @param bool $start Whether to start a job when one is due.
	 * @param int  $owner User starting it.
	 * @return array{state: string, database_bytes: int|null, files: int|null, files_bytes: int|null, computed_at: int, seconds: int|null, job: int}
	 */
	public function status( bool $start, int $owner = 0 ): array {
		$now      = (int) call_user_func( $this->now );
		$database = call_user_func( $this->database_size );
		$database = null === $database ? null : (int) $database;
		$out      = array(
			'state'          => 'none',
			'database_bytes' => $database,
			'files'          => null,
			'files_bytes'    => null,
			'computed_at'    => 0,
			'seconds'        => null,
			'job'            => 0,
		);
		$current  = Estimate::current( $now );
		if ( null !== $current ) {
			$out['state']       = 'ready';
			$out['files']       = $current['files'];
			$out['files_bytes'] = $current['bytes'];
			$out['computed_at'] = $current['computed_at'];
			$out['seconds']     = Estimate::seconds_for( $current['bytes'] + (int) $database, Estimate::scope( ExportOptions::normalize( array() ) ) );
			return $out;
		}
		foreach ( $this->actions->active() as $job ) {
			if ( EstimateJob::ID === $job->type ) {
				$out['state'] = 'running';
				$out['job']   = $job->id;
				return $out;
			}
		}
		foreach ( $this->actions->list_jobs( array( Job::FAILED ), 50 ) as $job ) {
			if ( EstimateJob::ID === $job->type && $now - $job->finished_at < Estimate::FAILED_BACKOFF_SECONDS ) {
				return $out; // Failed lately: the database size is all there is, and no error to show.
			}
		}
		if ( ! $start ) {
			$out['state'] = 'due';
			return $out;
		}
		try {
			$job          = $this->actions->start( EstimateJob::ID, $owner, array() );
			$out['state'] = 'running';
			$out['job']   = $job->id;
		} catch ( JobConflict $e ) {
			unset( $e ); // An export or a restore runs: nothing to estimate for now.
		} catch ( JobsUnavailable $e ) {
			unset( $e );
		}
		return $out;
	}
}
