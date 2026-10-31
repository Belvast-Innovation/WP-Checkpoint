<?php
/**
 * The wp wpcheckpoint job command.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Cli;

use WP_CLI;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobConflict;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Jobs\StaleJob;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * Runs, inspects, cancels and retries jobs. Only loaded under WP-CLI.
 */
final class JobCommand {

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
	 * Constructor.
	 *
	 * @param JobActions   $actions     Actions.
	 * @param JobPresenter $presenter   Presenter.
	 * @param Directories  $directories Storage directories.
	 */
	public function __construct( JobActions $actions, JobPresenter $presenter, Directories $directories ) {
		$this->actions     = $actions;
		$this->presenter   = $presenter;
		$this->directories = $directories;
	}

	/**
	 * Drive a job until it ends.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * [--wait]
	 * : Keep going through waits and storage back-off instead of exiting.
	 *
	 * ## EXIT CODES
	 *
	 * 0 completed, 1 failed, 2 cancelled, 3 lock lost, 4 left waiting, 5 another driver holds the job,
	 * 6 the job asks a question (see "job answer"); for a backup, 7 it completed but the backup cannot be
	 * confirmed (no longer in backups/, or not identifiable any more).
	 *
	 * A backup is driven as "wp wpcheckpoint export" drives it: its questions
	 * are asked on a terminal, and the backup's file name is printed at the end.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function run( array $args, array $assoc_args ): void {
		Unexpected::guard(
			function () use ( $args, $assoc_args ): void {
				$this->run_body( $args, $assoc_args );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * Show a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void {
		Unexpected::guard(
			function () use ( $args, $assoc_args ): void {
				$this->status_body( $args, $assoc_args );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * List jobs, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Comma-separated statuses (queued, running, paused, completed, failed, cancelled).
	 *
	 * [--limit=<n>]
	 * : Maximum rows.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @subcommand list
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		Unexpected::guard(
			function () use ( $args, $assoc_args ): void {
				$this->list_body( $args, $assoc_args );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * Cancel a job.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function cancel( array $args ): void {
		Unexpected::guard(
			function () use ( $args ): void {
				$this->cancel_body( $args );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * Queue a failed job again, keeping its position.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function retry( array $args ): void {
		Unexpected::guard(
			function () use ( $args ): void {
				$this->retry_body( $args );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * Undo a restore that completed: the site is put back as it was before it, in maintenance mode while that is done.
	 *
	 * Everything written on the site since the restore is dropped. Without --confirm nothing is changed: it says what
	 * would be dropped and gives the confirmation code. A restore can be undone for 7 days after it was made, while no
	 * other job holds the site and no restore was made after it. The restored site is kept for 7 days after the undo,
	 * then removed.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id of the restore.
	 *
	 * [--confirm=<code>]
	 * : The confirmation code the command gives without it.
	 *
	 * ## EXIT CODES
	 *
	 * As "job run" once the undo started (0 when it is undone); 1 when it was refused or not confirmed.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function undo( array $args, array $assoc_args ): void {
		Unexpected::guard(
			function () use ( $args, $assoc_args ): void {
				$this->undo_body( $args, $assoc_args );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * The body of undo(): what would be dropped and the code, or the job claimed and run to its end.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	private function undo_body( array $args, array $assoc_args ): void {
		$id = (int) $args[0];
		try {
			$outcome = $this->actions->undo( $id, (string) ( $assoc_args['confirm'] ?? '' ) );
		} catch ( JobsUnavailable $e ) {
			WP_CLI::error( $this->presenter->clean( $e->getMessage() ) );
		}
		if ( ! $outcome['ok'] ) {
			WP_CLI::error( $this->presenter->clean( $outcome['message'] ) );
		}
		if ( ! $outcome['confirmed'] ) {
			foreach ( $outcome['lines'] as $line ) {
				WP_CLI::line( $this->presenter->clean( $line ) );
			}
			WP_CLI::error( $this->presenter->clean( $outcome['message'] ) );
		}
		WP_CLI::line( $this->presenter->clean( $outcome['message'] ) );
		$code = ( new RunLoop( $this->actions, $this->presenter, 'sleep', array( 'WP_CLI', 'line' ) ) )->run( $id, true );
		WP_CLI::halt( $code );
	}

	/**
	 * Put the site back as it was before a restore that is stuck while it holds the site, from the restore's swap plan
	 * and what is there, in maintenance mode while that is done.
	 *
	 * A swap that was half made is rolled back and the restore cancelled. A swap that was made is reversed as an undo:
	 * everything written on the site since is dropped, and the restore ends undone (the restored site is kept for 7
	 * days). Neither the job's position nor its options are needed. Without --confirm nothing is changed: it says what
	 * it would do and gives the confirmation code.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id of the restore.
	 *
	 * [--confirm=<code>]
	 * : The confirmation code the command gives without it.
	 *
	 * ## EXIT CODES
	 *
	 * As "job run" once the put-back started: 0 when a swap that was made was put back (the restore ended undone), 2
	 * when one half made was (the restore ended cancelled, as a put-back of it does); 1 when it was refused or not
	 * confirmed.
	 *
	 * @subcommand put-back
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function put_back( array $args, array $assoc_args ): void {
		Unexpected::guard(
			function () use ( $args, $assoc_args ): void {
				$this->put_back_body( $args, $assoc_args );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * The body of put_back(): what it would do and the code (or why not, with what can be read), or the job claimed and
	 * run to its end.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	private function put_back_body( array $args, array $assoc_args ): void {
		$id = (int) $args[0];
		try {
			$outcome = $this->actions->put_back( $id, (string) ( $assoc_args['confirm'] ?? '' ) );
		} catch ( JobsUnavailable $e ) {
			WP_CLI::error( $this->presenter->clean( $e->getMessage() ) );
		}
		if ( ! $outcome['confirmed'] ) {
			foreach ( $outcome['lines'] as $line ) {
				WP_CLI::line( $this->presenter->clean( $line ) );
			}
			WP_CLI::error( $this->presenter->clean( $outcome['message'] ) );
		}
		WP_CLI::line( $this->presenter->clean( $outcome['message'] ) );
		$code = ( new RunLoop( $this->actions, $this->presenter, 'sleep', array( 'WP_CLI', 'line' ) ) )->run( $id, true );
		WP_CLI::halt( $code );
	}

	/**
	 * Take over a restore that holds the site changed and is managed by another installation, when it is this site's
	 * as far as can be told (wp wpcheckpoint job status shows the command with its code).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * --confirm=<code>
	 * : The confirmation code wp wpcheckpoint job status shows for this job.
	 *
	 * [--then=<what>]
	 * : While the restore has not recorded its direction: continue (it goes on as after any interruption: a swap cut
	 * off half way is put back first, then it can be retried) or rollback (it puts the site back and is cancelled).
	 * A swap that made every rename before it was interrupted finishes either way, and the restored site stays:
	 * wp wpcheckpoint job status says which applies.
	 * ---
	 * options:
	 *   - continue
	 *   - rollback
	 * ---
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function rebind( array $args, array $assoc_args ): void {
		Unexpected::guard(
			function () use ( $args, $assoc_args ): void {
				$this->report( $this->actions->rebind( (int) $args[0], (string) ( $assoc_args['confirm'] ?? '' ), (string) ( $assoc_args['then'] ?? '' ) ) );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * Take down, from this WordPress directory, the maintenance file of a restore that no longer holds the site
	 * (completed, rolled back, or abandoned) and is not running: only the file that carries the mark the job
	 * recorded. While the restore still holds the site it is refused, and wp wpcheckpoint job status says what to
	 * do instead. The job itself is not changed.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * --confirm=<code>
	 * : The confirmation code wp wpcheckpoint job status shows for this job.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function release( array $args, array $assoc_args ): void {
		Unexpected::guard(
			function () use ( $args, $assoc_args ): void {
				$this->report( $this->actions->release( (int) $args[0], (string) ( $assoc_args['confirm'] ?? '' ) ) );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * Give up for good a restore that is managed by another installation and is not this site's as far as can be
	 * told: its maintenance file is taken down from this WordPress directory, and it never runs again from here. Its
	 * tables are kept, the ones its swap moved aside (named wcpold…) among them, and nothing at the paths its plan
	 * records is touched. If this database is shared with the site the restore was started on, that site's restore
	 * stays half swapped.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * --confirm=<code>
	 * : The confirmation code wp wpcheckpoint job status shows for this job.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function abandon( array $args, array $assoc_args ): void {
		Unexpected::guard(
			function () use ( $args, $assoc_args ): void {
				$this->report( $this->actions->abandon( (int) $args[0], (string) ( $assoc_args['confirm'] ?? '' ) ) );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * Print an outcome of rebind, release or abandon: success, or an error (exit 1).
	 *
	 * @param array{ok: bool, message: string} $outcome Outcome.
	 * @return void
	 */
	private function report( array $outcome ): void {
		$message = $this->presenter->clean( $outcome['message'] );
		if ( $outcome['ok'] ) {
			WP_CLI::success( $message );
			return;
		}
		WP_CLI::error( $message );
	}

	/**
	 * Answer the questions of a paused job, then run it again.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * <answers>
	 * : A JSON object keyed by question id, e.g. '{"unreadable": "continue"}'.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcheckpoint job answer 12 '{"unreadable": "continue", "large_dirs": "include"}'
	 *     wp wpcheckpoint job run 12
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function answer( array $args ): void {
		Unexpected::guard(
			function () use ( $args ): void {
				$this->answer_body( $args );
			},
			array( $this->presenter, 'clean' )
		);
	}

	/**
	 * The body of run(), run through Unexpected::guard().
	 *
	 * @param string[]             $args       Positional arguments (see run()).
	 * @param array<string, mixed> $assoc_args Options (see run()).
	 * @return void
	 */
	private function run_body( array $args, array $assoc_args ): void {
		$id   = (int) $args[0];
		$job  = $this->actions->find( $id );
		$wait = ! empty( $assoc_args['wait'] );
		try {
			if ( null !== $job && ExportJob::ID === $job->type ) {
				$code = ExportRun::terminal( $this->actions, $this->presenter, $this->directories )->run( $id, $wait, false );
			} else {
				$code = ( new RunLoop( $this->actions, $this->presenter, 'sleep', array( 'WP_CLI', 'line' ) ) )->run( $id, $wait );
			}
		} catch ( JobsUnavailable $e ) {
			WP_CLI::error( $this->presenter->clean( $e->getMessage() ) );
		}
		WP_CLI::halt( $code );
	}

	/**
	 * The body of status(), run through Unexpected::guard().
	 *
	 * @param string[]             $args       Positional arguments (see status()).
	 * @param array<string, mixed> $assoc_args Options (see status()).
	 * @return void
	 */
	private function status_body( array $args, array $assoc_args ): void {
		$job = $this->actions->find( (int) $args[0] );
		if ( null === $job ) {
			WP_CLI::error( 'No such job.' );
		}
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$layout = self::status_layout( $this->presenter->present( $job ), $format );
		\WP_CLI\Utils\format_items( $format, $layout['rows'], array( 'field', 'value' ) );
		foreach ( $layout['after'] as $field => $text ) {
			WP_CLI::line( '' );
			WP_CLI::line( $field . ':' );
			foreach ( explode( "\n", $text ) as $line ) {
				WP_CLI::line( '  ' . $line );
			}
		}
		$two = $this->actions->holding_warning();
		if ( '' !== $two ) {
			WP_CLI::warning( $two );
		}
	}

	/**
	 * What job status puts in its table and what after it: as a table, a value of more than one line (what another
	 * installation's job leaves to be done from here, a log, an error with its detail) is not put in a cell; the cell
	 * says it follows, and the value is printed once after the table, under its field's name. Any other format has
	 * every value in its row (JSON, CSV and YAML hold more than one line).
	 *
	 * @param array<string, mixed> $data   JobPresenter::present().
	 * @param string               $format The --format given ("table" when not given: WP-CLI fills it from the docblock).
	 * @return array{rows: array<int, array{field: string, value: string}>, after: array<string, string>}
	 */
	public static function status_layout( array $data, string $format ): array {
		$rows  = array();
		$after = array();
		foreach ( $data as $field => $value ) {
			$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
			if ( 'table' === $format && false !== strpos( $text, "\n" ) ) {
				$after[ (string) $field ] = $text;
				$text                     = '(below the table)';
			}
			$rows[] = array(
				'field' => (string) $field,
				'value' => $text,
			);
		}
		return array(
			'rows'  => $rows,
			'after' => $after,
		);
	}

	/**
	 * The job a command prints after its table, when it is a job that the warning every command says first would name
	 * too (Plugin::half_swapped_warnings()): job status of a job, as a table. 0 for any other command.
	 *
	 * @param mixed[]              $args       The command's words, as WP-CLI's before_run_command hook passes them.
	 * @param array<string, mixed> $assoc_args Its options, as the hook passes them (before the docblock's defaults).
	 * @return int
	 */
	public static function shown_after_table( array $args, array $assoc_args ): int {
		if ( array( 'wpcheckpoint', 'job', 'status' ) !== array_slice( array_map( 'strval', $args ), 0, 3 ) || ! isset( $args[3] ) || 1 !== preg_match( '/\A[1-9][0-9]*\z/', (string) $args[3] ) ) {
			return 0;
		}
		return 'table' === (string) ( $assoc_args['format'] ?? 'table' ) ? (int) $args[3] : 0;
	}

	/**
	 * The body of list_(), run through Unexpected::guard().
	 *
	 * @param string[]             $args       Positional arguments (see list_()).
	 * @param array<string, mixed> $assoc_args Options (see list_()).
	 * @return void
	 */
	private function list_body( array $args, array $assoc_args ): void {
		$statuses = isset( $assoc_args['status'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['status'] ) ) : array();
		$rows     = array();
		foreach ( $this->actions->list_jobs( $statuses, isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50 ) as $job ) {
			$rows[] = $this->presenter->present( $job, false );
		}
		\WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table', $rows, array( 'id', 'type', 'status', 'step', 'progress', 'message', 'attempts', 'updated_at' ) );
		$two = $this->actions->holding_warning();
		if ( '' !== $two ) {
			WP_CLI::warning( $two );
		}
	}

	/**
	 * The body of cancel(), run through Unexpected::guard().
	 *
	 * @param string[] $args       Positional arguments (see cancel()).
	 * @return void
	 */
	private function cancel_body( array $args ): void {
		try {
			$outcome = $this->actions->cancel( (int) $args[0] );
		} catch ( InvalidTransition $e ) {
			WP_CLI::error( 'This job is already finished.' );
		} catch ( StaleJob $e ) {
			WP_CLI::error( 'The job changed meanwhile; try again.' );
		}
		if ( null === $outcome ) {
			WP_CLI::error( 'No such job.' );
		}
		$verdict = self::cancel_verdict( $outcome['reason'] );
		if ( $verdict['error'] ) {
			WP_CLI::error( $verdict['message'] );
		}
		WP_CLI::success( $verdict['message'] );
	}

	/**
	 * What the cancel command says for an outcome (JobActions::cancel()): an error for every reason that refuses
	 * (JobActions::CANCEL_REFUSED: the restored site is in place, another installation manages the job, it was
	 * abandoned, or an undo that changed the site or has ended); otherwise what was done or requested.
	 *
	 * @param string $reason The outcome's reason.
	 * @return array{error: bool, message: string}
	 */
	public static function cancel_verdict( string $reason ): array {
		return array(
			'error'   => in_array( $reason, \WPCheckpoint\Jobs\JobActions::CANCEL_REFUSED, true ),
			'message' => \WPCheckpoint\Rest\JobsController::cancel_message( $reason ),
		);
	}

	/**
	 * The body of retry(), run through Unexpected::guard().
	 *
	 * @param string[] $args       Positional arguments (see retry()).
	 * @return void
	 */
	private function retry_body( array $args ): void {
		try {
			$job = $this->actions->retry( (int) $args[0] );
		} catch ( JobsUnavailable $e ) {
			WP_CLI::error( $this->presenter->clean( $e->getMessage() ) );
		} catch ( JobConflict $e ) {
			WP_CLI::error( $this->presenter->clean( $e->getMessage() ) );
		} catch ( InvalidTransition $e ) {
			$current = $this->actions->find( (int) $args[0] );
			if ( null !== $current && Job::UNDONE === $current->status ) {
				WP_CLI::error( JobPresenter::undone_note( $current->id ) );
			}
			if ( null !== $current && Job::REASON_ABANDONED === $current->failure_reason ) {
				WP_CLI::error( JobPresenter::abandoned_note( $this->actions->abandoned_here( $current ) ) );
			}
			if ( null !== $current && Job::FAILED === $current->status && ! $current->can_retry() ) {
				WP_CLI::error( JobPresenter::retry_note() );
			}
			WP_CLI::error( 'Only a failed job can be retried.' );
		} catch ( StaleJob $e ) {
			WP_CLI::error( 'The job changed meanwhile; try again.' );
		}
		if ( null === $job ) {
			WP_CLI::error( 'No such job.' );
		}
		WP_CLI::success( sprintf( 'Job %d is %s again.', $job->id, Job::QUEUED ) );
	}

	/**
	 * The body of answer(), run through Unexpected::guard().
	 *
	 * @param string[] $args       Positional arguments (see answer()).
	 * @return void
	 */
	private function answer_body( array $args ): void {
		$answers = json_decode( isset( $args[1] ) ? (string) $args[1] : '', true );
		if ( ! is_array( $answers ) || array() === $answers ) {
			WP_CLI::error( 'Answers must be a non-empty JSON object keyed by question id.' );
		}
		try {
			$job = $this->actions->answer( (int) $args[0], $answers );
		} catch ( JobsUnavailable $e ) {
			WP_CLI::error( $this->presenter->clean( $e->getMessage() ) );
		} catch ( InvalidTransition $e ) {
			WP_CLI::error( 'This job is not waiting for an answer.' );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( 'Answers must not contain credentials.' );
		} catch ( StaleJob $e ) {
			WP_CLI::error( 'The job changed meanwhile; try again.' );
		}
		if ( null === $job ) {
			WP_CLI::error( 'No such job.' );
		}
		WP_CLI::success( sprintf( 'Job %d has its answers; run it again to continue.', $job->id ) );
	}
}
