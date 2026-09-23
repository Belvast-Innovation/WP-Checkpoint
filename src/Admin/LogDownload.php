<?php
/**
 * Download of a job's log, cleaned like the log on screen.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Support\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Serves admin-post.php?action=wpcheckpoint_job_log&job={id}: the job's log
 * as a text file, each line through JobPresenter::clean() (secrets redacted,
 * paths and the site's host masked), read through the same gate as the
 * tail on screen. A log is what gets sent to support: the raw file, which
 * only had secrets redacted when it was written, is never served from here.
 */
final class LogDownload {

	const ACTION       = 'wpcheckpoint_job_log';
	const NONCE_ACTION = 'job_log';

	/**
	 * Longest piece of a line cleaned at once.
	 */
	const LINE_BYTES = 1048576;

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
	 * Hook the admin-post action.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'serve' ) );
	}

	/**
	 * URL of a job's log.
	 *
	 * @param int $job Job id.
	 * @return string
	 */
	public static function url( int $job ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'job'      => $job,
				'_wpnonce' => Guard::nonce( self::NONCE_ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Entry point: guard, then stream and stop.
	 *
	 * @return void
	 */
	public function serve(): void {
		Guard::require_admin_post( self::NONCE_ACTION );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by require_admin_post() above.
		$id = isset( $_GET['job'] ) && is_string( $_GET['job'] ) ? absint( $_GET['job'] ) : 0;
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
		$this->handle( $id, array( $this, 'send_header' ), fopen( 'php://output', 'wb' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the client.
		exit;
	}

	/**
	 * Stream the cleaned log. Separated from serve() so tests can inject the header sink and the output.
	 *
	 * @param int      $id     Job id.
	 * @param callable $header Header sink ( string $line, int|null $status ).
	 * @param resource $out    Output stream.
	 * @return int HTTP status sent.
	 */
	public function handle( int $id, callable $header, $out ): int {
		$job  = $id > 0 ? $this->actions->find( $id ) : null;
		$path = null === $job ? '' : $this->presenter->log_file( $job );
		$in   = '' === $path ? false : @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a warning would put the path into the error log.
		$header( 'Content-Type: text/plain; charset=utf-8', false === $in ? 404 : 200 );
		$header( 'X-Content-Type-Options: nosniff', null );
		if ( false === $in ) {
			fwrite( $out, 'Not found.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- response body.
			return 404;
		}
		$header( 'Content-Disposition: attachment; filename="wp-checkpoint-job-' . (int) $id . '.log"', null );
		$extra = array( '{storage}' => $job->storage_path );
		try {
			while ( false !== ( $line = fgets( $in, self::LINE_BYTES ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition,Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- line loop.
				$ends = "\n" === substr( $line, -1 );
				fwrite( $out, $this->presenter->clean( rtrim( $line, "\n" ), $extra ) . ( $ends ? "\n" : '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- response body.
			}
		} finally {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		}
		return 200;
	}

	/**
	 * Header sink used for real requests.
	 *
	 * @param string   $line   Header line.
	 * @param int|null $status Status code to send with it.
	 * @return void
	 */
	public function send_header( string $line, $status ): void {
		if ( headers_sent() ) {
			return;
		}
		if ( null !== $status ) {
			status_header( $status );
		}
		header( $line );
	}
}
