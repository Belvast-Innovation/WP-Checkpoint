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
		$data = $this->presenter->present( $job );
		$rows = array();
		foreach ( $data as $key => $value ) {
			$rows[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}
		\WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table', $rows, array( 'field', 'value' ) );
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
		WP_CLI::success( \WPCheckpoint\Rest\JobsController::cancel_message( $outcome['reason'] ) );
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
		} catch ( InvalidTransition $e ) {
			$current = $this->actions->find( (int) $args[0] );
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
