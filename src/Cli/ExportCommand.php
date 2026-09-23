<?php
/**
 * WP-CLI: wp wpcheckpoint export.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Cli;

use WP_CLI;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\ExportOptions;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Support\Directories;

defined( 'ABSPATH' ) || exit;

/**
 * Back up this site into the plugin's backups directory.
 */
final class ExportCommand {

	/**
	 * Repository.
	 *
	 * @var JobRepository
	 */
	private $repository;

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
	 * @param JobRepository $repository  Repository.
	 * @param JobActions    $actions     Actions.
	 * @param JobPresenter  $presenter   Presenter.
	 * @param Directories   $directories Storage directories.
	 */
	public function __construct( JobRepository $repository, JobActions $actions, JobPresenter $presenter, Directories $directories ) {
		$this->repository  = $repository;
		$this->actions     = $actions;
		$this->presenter   = $presenter;
		$this->directories = $directories;
	}

	/**
	 * Back up the site: database and files, or one of them.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Do not ask: keep going without unreadable files, include large directories, stop on rows larger than the single-row limit.
	 *
	 * [--database-only]
	 * : Only the database.
	 *
	 * [--files-only]
	 * : Only the files.
	 *
	 * [--exclude=<patterns>]
	 * : Comma-separated path patterns to leave out, relative to the site (wp-content/cache/*).
	 *
	 * [--exclude-table=<tables>]
	 * : Comma-separated tables to leave out.
	 *
	 * [--include-table=<tables>]
	 * : Comma-separated tables to include although they look like another WordPress installation in the same database.
	 *
	 * [--wait]
	 * : Wait through retries instead of exiting.
	 *
	 * [--porcelain]
	 * : Print only the backup's base name on success.
	 *
	 * ## EXIT CODES
	 *
	 * 0 backup written, 1 failed, 2 cancelled, 3 taken over by another process, 4 waiting (run again, or use --wait), 5 another process is running it, 6 waiting for your decision (the answer command is printed).
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpcheckpoint export --yes
	 *     wp wpcheckpoint export --database-only --porcelain
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 * @return void
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		try {
			$options = ExportOptions::normalize( self::options( $assoc_args ) );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $this->presenter->clean( $e->getMessage() ) ); // Exits.
		}
		$porcelain = ! empty( $assoc_args['porcelain'] );
		try {
			$job = $this->repository->create( ExportJob::ID, get_current_user_id(), array(), $options );
		} catch ( JobsUnavailable $e ) {
			WP_CLI::error( $this->presenter->clean( $e->getMessage() ) ); // Exits.
		}
		if ( ! $porcelain ) {
			WP_CLI::line( sprintf( 'Backup job %d started.', $job->id ) );
		}
		$run = ExportRun::terminal( $this->actions, $this->presenter, $this->directories );
		WP_CLI::halt( $run->run( $job->id, ! empty( $assoc_args['wait'] ), $porcelain ) );
	}

	/**
	 * Command-line flags as export options (ExportOptions validates them).
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When both --database-only and --files-only are given.
	 */
	public static function options( array $assoc_args ): array {
		$options = array();
		if ( ! empty( $assoc_args['database-only'] ) && ! empty( $assoc_args['files-only'] ) ) {
			throw new \InvalidArgumentException( 'Choose either --database-only or --files-only, not both.' );
		}
		if ( ! empty( $assoc_args['database-only'] ) ) {
			$options['contents'] = array( 'files' => array() );
		}
		if ( ! empty( $assoc_args['files-only'] ) ) {
			$options['contents'] = array( 'database' => false );
		}
		foreach ( array(
			'exclude'       => 'exclusions',
			'exclude-table' => 'exclude_tables',
			'include-table' => 'include_tables',
		) as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$options[ $key ] = array_values(
					array_filter(
						array_map( 'trim', explode( ',', (string) $assoc_args[ $flag ] ) ),
						static function ( string $item ): bool {
							return '' !== $item;
						}
					)
				);
			}
		}
		if ( ! empty( $assoc_args['yes'] ) ) {
			$options['policy'] = ExportOptions::UNATTENDED;
		}
		return $options;
	}
}
