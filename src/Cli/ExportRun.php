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
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Paths;

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
		$input = function_exists( 'stream_isatty' ) && defined( 'STDIN' ) && stream_isatty( STDIN ) ? STDIN : null;
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
				return RunLoop::EXIT_PAUSED;
			}
		}
		if ( RunLoop::EXIT_COMPLETED === $code ) {
			$this->report_backup( $id, $porcelain );
		}
		return $code;
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
			$this->say( $this->err, $this->presenter->clean( $e->getMessage() ) );
			return false;
		} catch ( \InvalidArgumentException $e ) {
			$this->say( $this->err, $this->presenter->clean( $e->getMessage() ) );
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
	 * The paused job's questions with a line of text each, built from the
	 * review's findings (archive paths relative to the site and table names;
	 * no host, no server path), cleaned.
	 *
	 * @param Job $job Paused job.
	 * @return array<int, array{id: string, choices: string[], text: string}>
	 */
	private function questions( Job $job ): array {
		$findings = array();
		$work     = $this->work_dir( $job );
		if ( '' !== $work && ExportPlan::exists( $work, ExportPlan::REVIEW ) ) {
			try {
				$review   = ExportPlan::read( $work, ExportPlan::REVIEW );
				$findings = isset( $review['findings'] ) && is_array( $review['findings'] ) ? $review['findings'] : array();
			} catch ( \RuntimeException $e ) {
				$findings = array(); // Gone or changed since: each question is still listed, with a generic line.
			}
		}
		$out = array();
		foreach ( $job->questions as $question ) {
			$id      = (string) $question['id'];
			$choices = array_map( 'strval', (array) $question['choices'] );
			$out[]   = array(
				'id'      => $id,
				'choices' => $choices,
				'text'    => $this->presenter->clean( sprintf( '[%s] %s', $id, self::describe( $id, $question, $findings ) ) ),
			);
		}
		return $out;
	}

	/**
	 * What a question is about, in a line.
	 *
	 * @param string               $id       Question id.
	 * @param array<string, mixed> $question Question.
	 * @param array<string, mixed> $findings Review findings.
	 * @return string
	 */
	private static function describe( string $id, array $question, array $findings ): string {
		$count = (int) ( $question['count'] ?? 0 );
		if ( 'unreadable' === $id ) {
			$listed = isset( $findings['unreadable']['listed'] ) ? array_slice( (array) $findings['unreadable']['listed'], 0, 5 ) : array();
			return sprintf( '%d files cannot be read and would not be in the backup%s. Continue without them, or stop?', $count, array() === $listed ? '' : ' (for example ' . implode( ', ', $listed ) . ')' );
		}
		if ( 1 === preg_match( '/\Alarge_dir_(\d+)\z/', $id, $m ) && isset( $findings['heavy'][ (int) $m[1] ] ) ) {
			$dir = $findings['heavy'][ (int) $m[1] ];
			return sprintf( 'Directory %s holds %d MB (development files, not usually needed to restore the site). Include it, or leave it out?', (string) $dir['p'], (int) ( (int) $dir['bytes'] / 1048576 ) );
		}
		if ( 'large_dirs_more' === $id ) {
			return sprintf( '%d more large directories (listed in the job log). Include them, or leave them out?', $count );
		}
		if ( 1 === preg_match( '/\Aoversize_(\d+)\z/', $id, $m ) && isset( $findings['oversize'][ (int) $m[1] ] ) ) {
			$table = $findings['oversize'][ (int) $m[1] ];
			$rows  = null === $table['count'] ? 'may have rows' : sprintf( 'has %d rows', (int) $table['count'] );
			return sprintf( 'Table %s %s larger than the single-row limit of %d bytes. Leave those rows out, or stop?', (string) $table['table'], $rows, (int) $table['limit'] );
		}
		if ( 'oversize_more' === $id ) {
			return sprintf( '%d more tables have rows larger than the single-row limit (listed in the job log). Leave those rows out, or stop?', $count );
		}
		return sprintf( 'A decision is needed (%s).', (string) ( $question['kind'] ?? $id ) );
	}

	/**
	 * After success: the manifest's file name in backups/ (the user acts on
	 * it) and the warnings the manifest records; with porcelain, the base
	 * name alone. The work directory still holds plan.json at this point.
	 *
	 * @param int  $id        Job id.
	 * @param bool $porcelain Base name only.
	 * @return void
	 */
	private function report_backup( int $id, bool $porcelain ): void {
		$job = $this->actions->find( $id );
		if ( ! $job instanceof Job ) {
			return;
		}
		$work  = $this->work_dir( $job );
		$base  = '';
		$error = 'The job belongs to another storage directory.';
		if ( '' !== $work ) {
			try {
				$base = (string) ExportPlan::read( $work, ExportPlan::PLAN )['base'];
			} catch ( \RuntimeException $e ) {
				$error = $e->getMessage();
			}
		}
		if ( '' === $base ) {
			// A completed job's work directory is residue: another request's maintenance may have removed it already.
			$this->say( $this->err, 'The backup was written to backups/, but its file name could not be read: ' . $this->presenter->clean( $error ) );
			return;
		}
		if ( $porcelain ) {
			$this->say( $this->out, $base );
			return;
		}
		$this->say( $this->out, sprintf( 'Backup written: backups/%s.manifest.json', $base ) );
		$path = $this->directories->backups() . DIRECTORY_SEPARATOR . $base . '.manifest.json';
		$size = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( $size <= 0 || $size > Manifest::MAX_JSON_BYTES ) {
			return;
		}
		try {
			$warnings = Manifest::from_json( (string) file_get_contents( $path ) )->warnings(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded by the size check above.
		} catch ( \InvalidArgumentException $e ) {
			return;
		}
		foreach ( $warnings as $warning ) {
			$this->say( $this->out, 'Warning: ' . $this->presenter->clean( (string) $warning ) );
		}
	}

	/**
	 * The job's work directory, or '' when the job's storage is not the
	 * current storage directory (another directory choice or installation:
	 * never read).
	 *
	 * @param Job $job Job.
	 * @return string
	 */
	private function work_dir( Job $job ): string {
		if ( ! Paths::same( $job->storage_path, $this->directories->base(), Paths::is_windows() ) ) {
			return '';
		}
		return Residue::work_dir( $job->storage_path, $job->id );
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
