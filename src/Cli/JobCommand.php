<?php
/**
 * The wp wpcheckpoint job command.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Cli;

use WP_CLI;
use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Jobs\StaleJob;

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
	 * Constructor.
	 *
	 * @param JobActions   $actions   Actions.
	 * @param JobPresenter $presenter Presenter.
	 */
	public function __construct( JobActions $actions, JobPresenter $presenter ) {
		$this->actions   = $actions;
		$this->presenter = $presenter;
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
	 * 0 completed, 1 failed, 2 cancelled, 3 lock lost, 4 left waiting, 5 another driver holds the job.
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function run( array $args, array $assoc_args ): void {
		$loop = new RunLoop( $this->actions, $this->presenter, 'sleep', array( 'WP_CLI', 'line' ) );
		try {
			$code = $loop->run( (int) $args[0], ! empty( $assoc_args['wait'] ) );
		} catch ( JobsUnavailable $e ) {
			WP_CLI::error( $this->presenter->clean( $e->getMessage() ) );
		}
		WP_CLI::halt( $code );
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
		$statuses = isset( $assoc_args['status'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['status'] ) ) : array();
		$rows     = array();
		foreach ( $this->actions->list_jobs( $statuses, isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 50 ) as $job ) {
			$rows[] = $this->presenter->present( $job, false );
		}
		\WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table', $rows, array( 'id', 'type', 'status', 'step', 'progress', 'message', 'attempts', 'updated_at' ) );
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
}
