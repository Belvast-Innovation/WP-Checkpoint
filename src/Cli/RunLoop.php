<?php
/**
 * Drives a job to its end inside one WP-CLI process.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Cli;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\TickResult;

defined( 'ABSPATH' ) || exit;

/**
 * Ticks in a loop. Every tick starts a fresh budget (the process is long
 * lived). Sleeping and printing are injected so the loop is testable.
 */
final class RunLoop {

	const EXIT_COMPLETED = 0;
	const EXIT_FAILED    = 1;
	const EXIT_CANCELLED = 2;
	const EXIT_LOST      = 3;
	const EXIT_WAITING   = 4; // Left waiting / blocked without --wait, paused, or missing.
	const EXIT_BUSY      = 5; // Another driver kept the lock.

	const MAX_BUSY = 60;

	/**
	 * Actions.
	 *
	 * @var JobActions
	 */
	private $actions;

	/**
	 * Presenter.
	 *
	 * @var JobPresenter
	 */
	private $presenter;

	/**
	 * Sleep: function( int $seconds ): void.
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Output: function( string $line ): void.
	 *
	 * @var callable
	 */
	private $out;

	/**
	 * Constructor.
	 *
	 * @param JobActions    $actions   Actions.
	 * @param JobPresenter  $presenter Presenter.
	 * @param callable|null $sleep     Sleep function (tests).
	 * @param callable|null $out       Output function (tests).
	 */
	public function __construct( JobActions $actions, JobPresenter $presenter, $sleep = null, $out = null ) {
		$this->actions   = $actions;
		$this->presenter = $presenter;
		$this->sleep     = is_callable( $sleep ) ? $sleep : 'sleep';
		$this->out       = is_callable( $out ) ? $out : static function ( string $line ): void {
			echo $line, PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- terminal output, already redacted and masked.
		};
	}

	/**
	 * Run until the job ends or the loop must give up.
	 *
	 * @param int  $id   Job id.
	 * @param bool $wait Sleep through waiting and blocked results instead of exiting.
	 * @return int Exit code (EXIT_* constants).
	 */
	public function run( int $id, bool $wait ): int {
		$busy = 0;
		while ( true ) {
			// This loop is the follow-up: no self-request and no cron event per tick. When it stops before the
			// job is finished, one follow-up hands the job back to the other drivers.
			$result = $this->actions->tick( $id, microtime( true ), false );
			$this->report( $result );

			switch ( $result->status ) {
				case TickResult::MORE:
					$busy = 0;
					continue 2;
				case TickResult::BUSY:
					++$busy;
					if ( $busy > self::MAX_BUSY ) {
						$this->say( 'Another driver holds this job; giving up after ' . self::MAX_BUSY . ' attempts.' );
						$this->actions->follow_up( $result );
						return self::EXIT_BUSY;
					}
					call_user_func( $this->sleep, max( 1, $result->retry_after ) );
					continue 2;
				case TickResult::WAITING:
				case TickResult::BLOCKED:
					$busy = 0;
					if ( ! $wait ) {
						$this->say( 'The job is waiting (' . $result->retry_after . ' s). Run again with --wait to keep going.' );
						$this->actions->follow_up( $result );
						return self::EXIT_WAITING;
					}
					call_user_func( $this->sleep, max( 1, $result->retry_after ) );
					continue 2;
				case TickResult::COMPLETED:
				case TickResult::FAILED:
				case TickResult::FINISHED:
				case TickResult::LOST:
					// Terminal for this driver: the follow-up clears the job's cron event and token.
					$this->actions->follow_up( $result );
					if ( TickResult::COMPLETED === $result->status ) {
						return self::EXIT_COMPLETED;
					}
					if ( TickResult::FAILED === $result->status ) {
						return self::EXIT_FAILED;
					}
					if ( TickResult::LOST === $result->status ) {
						return null !== $result->job && Job::CANCELLED === $result->job->status ? self::EXIT_CANCELLED : self::EXIT_LOST;
					}
					return $this->exit_code_for( $result->job );
				case TickResult::MISSING:
				default:
					$this->say( 'No such job.' );
					return self::EXIT_WAITING;
			}
		}
	}

	/**
	 * Exit code for a job that was already finished when the loop started.
	 *
	 * @param Job|null $job Job.
	 * @return int
	 */
	private function exit_code_for( $job ): int {
		if ( ! $job instanceof Job ) {
			return self::EXIT_WAITING;
		}
		switch ( $job->status ) {
			case Job::COMPLETED:
				return self::EXIT_COMPLETED;
			case Job::FAILED:
				return self::EXIT_FAILED;
			case Job::CANCELLED:
				return self::EXIT_CANCELLED;
			default:
				return self::EXIT_WAITING;
		}
	}

	/**
	 * One line per tick.
	 *
	 * @param TickResult $result Result.
	 * @return void
	 */
	private function report( TickResult $result ): void {
		if ( null === $result->job ) {
			$this->say( $result->status );
			return;
		}
		$job  = $this->presenter->present( $result->job, false );
		$line = sprintf( '%-10s %3d%%  %s', $result->status, $job['progress'], trim( $job['step_label'] . '  ' . $job['message'] ) );
		if ( '' !== $job['last_error'] && Job::FAILED === $job['status'] ) {
			$line .= '  error: ' . $job['last_error'];
		} elseif ( '' !== $result->message && $result->message !== $result->job->progress_message ) {
			$line .= '  ' . $this->presenter->clean( $result->message );
		}
		$this->say( $line );
	}

	/**
	 * Print a line.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private function say( string $line ): void {
		call_user_func( $this->out, $line );
	}
}
