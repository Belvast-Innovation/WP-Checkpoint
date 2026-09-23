<?php
/**
 * Drive an export from the command line.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Cli;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\PreflightStep;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\HostFunctions;

defined( 'ABSPATH' ) || exit;

/**
 * The part of "wp wpcheckpoint export" that does not need WP-CLI: run the
 * job to its end in this process, ask its questions on a terminal or say
 * how to answer them without one, and report the result. Output, errors
 * and input are injected (tests); every text that comes from the job goes
 * through JobPresenter::clean().
 */
final class ExportRun {

	/**
	 * Wrong answers accepted per question before giving up (terminal).
	 */
	const MAX_TRIES = 3;

	/**
	 * Exit code: the job completed, but its backup cannot be confirmed (its
	 * manifest is gone from backups/, or the job completed before this run
	 * and its name can no longer be read).
	 */
	const EXIT_UNCONFIRMED = 7;

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
	 * Storage directories.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Standard output: function( string $line ): void.
	 *
	 * @var callable
	 */
	private $out;

	/**
	 * Standard error: function( string $line ): void.
	 *
	 * @var callable
	 */
	private $err;

	/**
	 * Terminal input, or null when there is no terminal to ask on.
	 *
	 * @var resource|null
	 */
	private $input;

	/**
	 * Sleep for the run loop (tests).
	 *
	 * @var callable|null
	 */
	private $sleep;

	/**
	 * Constructor.
	 *
	 * @param JobActions    $actions     Actions.
	 * @param JobPresenter  $presenter   Presenter.
	 * @param Directories   $directories Storage directories.
	 * @param callable      $out         Standard output.
	 * @param callable      $err         Standard error.
	 * @param resource|null $input       Terminal input (null: none).
	 * @param callable|null $sleep       Sleep (tests).
	 */
	public function __construct( JobActions $actions, JobPresenter $presenter, Directories $directories, callable $out, callable $err, $input = null, $sleep = null ) {
		$this->actions     = $actions;
		$this->presenter   = $presenter;
		$this->directories = $directories;
		$this->out         = $out;
		$this->err         = $err;
		$this->input       = is_resource( $input ) ? $input : null;
		$this->sleep       = $sleep;
	}

	/**
	 * The driver for a WP-CLI process: standard output and error, and
	 * standard input as the terminal when it is one.
	 *
	 * @param JobActions   $actions     Actions.
	 * @param JobPresenter $presenter   Presenter.
	 * @param Directories  $directories Storage directories.
	 * @return ExportRun
	 */
	public static function terminal( JobActions $actions, JobPresenter $presenter, Directories $directories ): ExportRun {
		$out   = static function ( string $line ): void {
			echo $line, PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- terminal output, already cleaned.
		};
		$err   = static function ( string $line ): void {
			fwrite( STDERR, $line . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.Security.EscapeOutput.OutputNotEscaped -- terminal output, already cleaned.
		};
		$input = defined( 'STDIN' ) && HostFunctions::stream_isatty( STDIN ) ? STDIN : null;
		return new ExportRun( $actions, $presenter, $directories, $out, $err, $input );
	}

	/**
	 * Run the job to its end (or its question). Porcelain prints the backup's
	 * base name on success and nothing else on standard output.
	 *
	 * @param int  $id        Job id.
	 * @param bool $wait      Sleep through waits instead of exiting.
	 * @param bool $porcelain Base name only.
	 * @return int RunLoop exit code.
	 */
	public function run( int $id, bool $wait, bool $porcelain ): int {
		$progress = $porcelain ? $this->err : $this->out;
		$loop     = new RunLoop( $this->actions, $this->presenter, $this->sleep, $progress, false );
		$before   = $this->actions->find( $id );
		$was_done = $before instanceof Job && Job::COMPLETED === $before->status;
		while ( true ) {
			$code = $loop->run( $id, $wait );
			if ( RunLoop::EXIT_PAUSED !== $code ) {
				break;
			}
			$job = $this->actions->find( $id );
			if ( ! $job instanceof Job ) {
				return RunLoop::EXIT_WAITING;
			}
			if ( null === $this->input || $porcelain ) {
				$this->explain_questions( $job );
				return RunLoop::EXIT_PAUSED;
			}
			if ( ! $this->ask( $job ) ) {
				return $this->exit_code_now( $id );
			}
		}
		if ( RunLoop::EXIT_COMPLETED === $code ) {
			return $this->report_backup( $id, $porcelain, $was_done );
		}
		return $code;
	}

	/**
	 * Exit code for the job's state after an answer was not taken: it may
	 * have been cancelled, failed or answered elsewhere in the meantime.
	 *
	 * @param int $id Job id.
	 * @return int
	 */
	private function exit_code_now( int $id ): int {
		$job = $this->actions->find( $id );
		if ( ! $job instanceof Job ) {
			return RunLoop::EXIT_WAITING;
		}
		switch ( $job->status ) {
			case Job::CANCELLED:
				return RunLoop::EXIT_CANCELLED;
			case Job::FAILED:
				return RunLoop::EXIT_FAILED;
			case Job::PAUSED:
				return RunLoop::EXIT_PAUSED;
			default:
				return RunLoop::EXIT_WAITING; // Answered elsewhere: run it again to continue.
		}
	}

	/**
	 * Ask each question on the terminal, then store the answers.
	 *
	 * @param Job $job Paused job.
	 * @return bool False when no valid answer came.
	 */
	private function ask( Job $job ): bool {
		$answers = array();
		foreach ( $this->questions( $job ) as $question ) {
			$this->say( $this->out, $question['text'] );
			$given = null;
			for ( $try = 0; $try < self::MAX_TRIES && null === $given; $try++ ) {
				$this->say( $this->out, sprintf( 'Answer (%s): ', implode( ' / ', $question['choices'] ) ) );
				$line = fgets( $this->input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets -- terminal input.
				if ( false === $line ) {
					break;
				}
				$line = trim( $line );
				if ( in_array( $line, $question['choices'], true ) ) {
					$given = $line;
				}
			}
			if ( null === $given ) {
				$this->explain_questions( $job );
				return false;
			}
			$answers[ $question['id'] ] = $given;
		}
		try {
			$this->actions->answer( $job->id, $answers );
		} catch ( \RuntimeException $e ) {
			$this->say( $this->err, 'The answer was not taken: ' . $this->presenter->clean( $e->getMessage() ) );
			return false;
		} catch ( \LogicException $e ) {
			// Invalid answers, and InvalidTransition: the job was answered, cancelled or failed elsewhere meanwhile.
			$this->say( $this->err, 'The answer was not taken: ' . $this->presenter->clean( $e->getMessage() ) );
			return false;
		}
		return true;
	}

	/**
	 * Without a terminal: the questions with what they are about, a ready
	 * answer command listing every question and its choices, and the command
	 * to continue. Standard error: standard output is the result.
	 *
	 * @param Job $job Paused job.
	 * @return void
	 */
	private function explain_questions( Job $job ): void {
		$template = array();
		foreach ( $this->questions( $job ) as $question ) {
			$this->say( $this->err, $question['text'] );
			$template[ $question['id'] ] = implode( '|', $question['choices'] );
		}
		$this->say( $this->err, 'The backup is waiting for your decision. Choose one value for each question and run:' );
		$this->say( $this->err, sprintf( "  wp wpcheckpoint job answer %d '%s'", $job->id, (string) wp_json_encode( $template, JSON_UNESCAPED_SLASHES ) ) );
		$this->say( $this->err, sprintf( '  wp wpcheckpoint job run %d', $job->id ) );
		$this->say( $this->err, 'Or start the backup again with --yes: unreadable files are left out, large directories are included, and rows over the single-row limit stop the backup.' );
	}

	/**
	 * The paused job's questions with a line of text each (QuestionText),
	 * prefixed with the question id for the answer command.
	 *
	 * @param Job $job Paused job.
	 * @return array<int, array{id: string, choices: string[], text: string}>
	 */
	private function questions( Job $job ): array {
		$out = array();
		foreach ( QuestionText::for_job( $job, $this->directories, array( $this->presenter, 'clean' ) ) as $question ) {
			$out[] = array(
				'id'      => $question['id'],
				'choices' => $question['choices'],
				'text'    => '[' . $question['id'] . '] ' . $question['text'],
			);
		}
		return $out;
	}

	/**
	 * After success: the manifest's file name in backups/ (the user acts on
	 * it) and the warnings the manifest records; with porcelain, the base
	 * name alone on standard output and the warnings on standard error.
	 *
	 * The name comes from plan.json in the work directory, which is residue
	 * once the job is completed: another request's maintenance may have
	 * removed it. The exit code says whether the backup is confirmed: its
	 * manifest is in backups/, or the job completed during this command (the
	 * store step's rename just succeeded) even if its name is gone. A job
	 * that completed before, whose name is gone or whose manifest is no
	 * longer there, is not confirmed (EXIT_UNCONFIRMED).
	 *
	 * @param int  $id        Job id.
	 * @param bool $porcelain Base name only.
	 * @param bool $was_done  Whether the job had completed before this command.
	 * @return int Exit code.
	 */
	private function report_backup( int $id, bool $porcelain, bool $was_done ): int {
		$job   = $this->actions->find( $id );
		$work  = $job instanceof Job ? $this->work_dir( $job ) : '';
		$base  = '';
		$error = 'The job belongs to another storage directory.';
		if ( '' !== $work ) {
			try {
				$base = (string) ExportPlan::read( $work, ExportPlan::PLAN )['base'];
				if ( 1 !== preg_match( PreflightStep::BASE_PATTERN, $base ) ) {
					$base  = '';
					$error = 'The file plan.json of this job does not name a backup; the work directory was changed.';
				}
			} catch ( \RuntimeException $e ) {
				$error = $e->getMessage();
			}
		}
		if ( '' === $base ) {
			if ( $was_done ) {
				$this->say( $this->err, 'The job completed earlier; its backup can no longer be identified: ' . $this->presenter->clean( $error ) );
				return self::EXIT_UNCONFIRMED;
			}
			$this->say( $this->err, 'The backup was written to backups/, but its file name could not be read: ' . $this->presenter->clean( $error ) );
			return RunLoop::EXIT_COMPLETED;
		}
		$path = $this->directories->backups() . DIRECTORY_SEPARATOR . $base . '.manifest.json';
		if ( ! is_file( $path ) ) {
			$this->say( $this->err, 'The job completed, but its backup is no longer in backups/.' );
			return self::EXIT_UNCONFIRMED;
		}
		$this->say( $this->out, $porcelain ? $base : sprintf( 'Backup written: backups/%s.manifest.json', $base ) );
		$size = (int) filesize( $path );
		if ( $size <= 0 || $size > Manifest::MAX_JSON_BYTES ) {
			return RunLoop::EXIT_COMPLETED;
		}
		try {
			$warnings = Manifest::from_json( (string) file_get_contents( $path ) )->warnings(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded by the size check above.
		} catch ( \InvalidArgumentException $e ) {
			return RunLoop::EXIT_COMPLETED;
		}
		foreach ( $warnings as $warning ) {
			$this->say( $porcelain ? $this->err : $this->out, 'Warning: ' . $this->presenter->clean( (string) $warning ) );
		}
		return RunLoop::EXIT_COMPLETED;
	}

	/**
	 * The job's work directory, or '' when it belongs to another storage directory.
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function work_dir( Job $job ): string {
		return QuestionText::work_dir( $job, $this->directories );
	}

	/**
	 * Print a line.
	 *
	 * @param callable $stream Output.
	 * @param string   $line   Line.
	 * @return void
	 */
	private function say( callable $stream, string $line ): void {
		call_user_func( $stream, $line );
	}
}
