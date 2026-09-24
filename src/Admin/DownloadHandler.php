<?php
/**
 * Authenticated, streamed download of backups and logs.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Admin;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobConflicts;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\FileStreamer;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Paths;
use WPCheckpoint\Support\HostFunctions;

defined( 'ABSPATH' ) || exit;

/**
 * Serves admin-post.php?action=wpcheckpoint_download&file=backups/x.wpcheckpoint.zip
 *
 * The file parameter is relative to the storage base directory. After
 * realpath() the result must lie inside backups/ or logs/ and carry an
 * allowed extension; everything else is a 404. A file of a backup that is
 * being restored is refused with a 409 until the restore has ended.
 */
final class DownloadHandler {

	const ACTION       = 'wpcheckpoint_download';
	const NONCE_ACTION = 'download';

	/**
	 * Downloadable file name suffixes (lowercase).
	 *
	 * @var string[]
	 */
	const ALLOWED_SUFFIXES = array( '.wpcheckpoint.zip', '.wpcheckpoint.tar', '.manifest.json', '.log' );

	/**
	 * Storage directories.
	 *
	 * @var Directories
	 */
	private $directories;

	/**
	 * Returns the queued, running and paused jobs.
	 *
	 * @var callable(): Job[]
	 */
	private $active;

	/**
	 * Constructor.
	 *
	 * @param Directories $directories Storage directories.
	 * @param callable    $active      Returns the queued, running and paused jobs.
	 */
	public function __construct( Directories $directories, callable $active ) {
		$this->directories = $directories;
		$this->active      = $active;
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
	 * URL to download a file relative to the storage base.
	 *
	 * @param string $relative Relative path, e.g. "logs/job-1-abcd.log".
	 * @return string
	 */
	public static function url( string $relative ): string {
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'file'     => $relative,
				'_wpnonce' => Guard::nonce( self::NONCE_ACTION ),
			),
			Page::post_url()
		);
	}

	/**
	 * Entry point: guard, then stream and stop.
	 *
	 * @return void
	 */
	public function serve(): void {
		Guard::require_admin_post( self::NONCE_ACTION );

		$file = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified by require_admin_post() above.
		if ( isset( $_GET['file'] ) && is_string( $_GET['file'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
			$file = sanitize_text_field( wp_unslash( $_GET['file'] ) );
		}
		$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$range    = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : null;
		$if_range = isset( $_SERVER['HTTP_IF_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_RANGE'] ) ) : null;

		$this->prepare_output();
		$this->handle( $file, $method, $range, $if_range, array( $this, 'send_header' ), fopen( 'php://output', 'wb' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming to the client.
		exit;
	}

	/**
	 * Resolve and stream. Separated from serve() so tests can inject the
	 * header sink and output stream.
	 *
	 * @param string      $file     Requested relative path.
	 * @param string      $method   HTTP method.
	 * @param string|null $range    Range header.
	 * @param string|null $if_range If-Range header.
	 * @param callable    $header   Header sink ( string $line, int|null $status ).
	 * @param resource    $out      Output stream.
	 * @return int HTTP status that was sent.
	 */
	public function handle( string $file, string $method, $range, $if_range, callable $header, $out ): int {
		$real = $this->resolve( $file );
		if ( '' === $real ) {
			$header( 'Content-Type: text/plain; charset=utf-8', 404 );
			$header( 'X-Content-Type-Options: nosniff', null );
			fwrite( $out, 'Not found.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- response body.
			return 404;
		}
		$reason = $this->restoring( $real );
		if ( '' !== $reason ) {
			$header( 'Content-Type: text/plain; charset=utf-8', 409 );
			$header( 'X-Content-Type-Options: nosniff', null );
			fwrite( $out, $reason ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- response body, fixed text with a job number.
			return 409;
		}

		$plan = FileStreamer::plan( (int) filesize( $real ), $range, FileStreamer::etag( $real ), $if_range );
		FileStreamer::stream( $real, basename( $real ), $plan, $method, $header, $out );
		return $plan['status'];
	}

	/**
	 * Map a relative request path to a real file inside backups/ or logs/.
	 *
	 * @param string $file Requested relative path.
	 * @return string Real path or empty string.
	 */
	public function resolve( string $file ): string {
		if ( '' === $file || false !== strpos( $file, "\0" ) ) {
			return '';
		}
		$base = $this->directories->base();
		if ( '' === $base ) {
			return '';
		}

		$candidate = $base . DIRECTORY_SEPARATOR . ltrim( str_replace( '\\', '/', $file ), '/' );
		$real      = realpath( $candidate );
		if ( false === $real || ! is_file( $real ) || is_link( $candidate ) ) {
			return '';
		}

		$allowed_dirs = array( $this->directories->backups(), $this->directories->logs() );
		$inside       = false;
		foreach ( $allowed_dirs as $dir ) {
			if ( '' !== $dir && Paths::is_inside( $dir, $real ) ) {
				$inside = true;
				break;
			}
		}
		if ( ! $inside ) {
			return '';
		}

		$name = strtolower( basename( $real ) );
		foreach ( self::ALLOWED_SUFFIXES as $suffix ) {
			if ( strlen( $name ) > strlen( $suffix ) && substr( $name, -strlen( $suffix ) ) === $suffix ) {
				return $real;
			}
		}
		return '';
	}

	/**
	 * Why a resolved file must not be sent now: it belongs to a backup that
	 * is being restored. '' otherwise (logs, other backups).
	 *
	 * @param string $real Resolved real path.
	 * @return string
	 */
	private function restoring( string $real ): string {
		$backups = $this->directories->backups();
		if ( '' === $backups || ! Paths::is_inside( $backups, $real ) ) {
			return '';
		}
		$name = basename( $real );
		$dot  = strpos( $name, '.' );
		if ( false === $dot || 0 === $dot ) {
			return '';
		}
		return JobConflicts::restoring( substr( $name, 0, $dot ), ( $this->active )() );
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

	/**
	 * Make the process suitable for streaming a large file.
	 *
	 * @return void
	 */
	private function prepare_output(): void {
		// Output compression corrupts Content-Length and Range responses; both calls may be disallowed by the host.
		HostFunctions::ini_set( 'zlib.output_compression', '0' );
		HostFunctions::apache_setenv( 'no-gzip', '1' );
		HostFunctions::set_time_limit( 0 );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		nocache_headers();
	}
}
