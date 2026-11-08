<?php
/**
 * The log of the runs without WordPress, next to the maintenance file.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\Maintenance;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- a small file in ABSPATH, appended to under a lock.

/**
 * Restore\Maintenance::LOG: what runs without WordPress noted (what they did not take on, could not tell, or left to
 * WP-CLI), for an administrator who cannot open wp-admin while the site is held. "<?php exit; ?>" and the restore's mark
 * first (a request for it prints nothing; Restore\Maintenance::WEB_MARK), then a line per note, as given (the caller
 * cleans it: WebRun, WebEntry). Written only while this restore's data file is there (the log is taken down with the
 * maintenance file, Restore\Maintenance::remove(), after its lines were added to the job's log): a note made after
 * that goes to PHP's log instead. At most MAX_BYTES: past it, only the newer half is kept.
 */
final class RuntimeLog {

	/**
	 * Most bytes the log holds.
	 */
	const MAX_BYTES = 65536;

	/**
	 * The WordPress directory.
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * The restore's mark.
	 *
	 * @var string
	 */
	private $mark;

	/**
	 * Constructor.
	 *
	 * @param string $dir  The WordPress directory.
	 * @param string $mark The restore's mark.
	 */
	public function __construct( string $dir, string $mark ) {
		$this->dir  = rtrim( $dir, '/\\' );
		$this->mark = $mark;
	}

	/**
	 * Write a restore's runtime log, empty but for its header (whole or not at all): the swap does, with the visitor
	 * file, before the maintenance file; a run only appends to it, so that a run that comes after the take-down does
	 * not make it again.
	 *
	 * @param string        $dir     The WordPress directory.
	 * @param string        $mark    The restore's mark.
	 * @param callable|null $confirm Called right before it goes in place (the lease; throws to stop).
	 * @return void
	 * @throws \RuntimeException When it cannot be written.
	 */
	public static function create( string $dir, string $mark, $confirm = null ): void {
		\WPCheckpoint\Support\AtomicFile::write( $dir, Maintenance::LOG, self::header( $mark ), null === $confirm ? array() : array( 'confirm' => $confirm ) );
	}

	/**
	 * The header of a restore's log.
	 *
	 * @param string $mark Mark.
	 * @return string
	 */
	private static function header( string $mark ): string {
		return "<?php exit; ?>\n# " . $mark . "\n";
	}

	/**
	 * The path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->dir . DIRECTORY_SEPARATOR . Maintenance::LOG;
	}

	/**
	 * Add a line (one line: its line breaks become spaces), with the time; to PHP's log when the log is not this
	 * restore's to write (its data file gone, another restore's log there) or cannot be written.
	 *
	 * @param string $line Line, cleaned.
	 * @param int    $now  Unix time.
	 * @return bool Whether it went into this log.
	 */
	public function write( string $line, int $now ): bool {
		$line = gmdate( 'Y-m-d H:i:s', $now ) . ' ' . str_replace( array( "\r", "\n" ), ' ', $line ) . "\n";
		if ( Maintenance::data_mark( $this->dir ) === $this->mark && $this->append( $line ) ) {
			return true;
		}
		error_log( 'WP Checkpoint (run without WordPress): ' . rtrim( $line ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the log next to the maintenance file is not there to write.
		return false;
	}

	/**
	 * Append under the file's lock (where the file system has locks), the older half dropped past MAX_BYTES; nothing
	 * when there is no log (it is made by the swap: create()) or the file there names another restore.
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private function append( string $line ): bool {
		$path   = $this->path();
		$handle = @fopen( $path, 'r+' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not there (made by the swap, taken down with the maintenance file): the caller says it elsewhere.
		if ( false === $handle ) {
			return false;
		}
		$busy   = 0;
		$locked = @flock( $handle, LOCK_EX, $busy ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a file system without locks: appended without.
		try {
			$header = self::header( $this->mark );
			$text   = stream_get_contents( $handle );
			if ( ! is_string( $text ) || 0 !== strpos( $text, $header ) ) {
				return false; // Another restore's, or not one of ours.
			}
			$body = substr( $text, strlen( $header ) );
			if ( strlen( $header ) + strlen( $body ) + strlen( $line ) > self::MAX_BYTES ) {
				$keep = substr( $body, (int) ( strlen( $body ) / 2 ) );
				$cut  = strpos( $keep, "\n" );
				$body = false === $cut ? '' : substr( $keep, $cut + 1 );
			}
			$contents = $header . $body . $line;
			if ( ! rewind( $handle ) || ! ftruncate( $handle, 0 ) ) {
				return false;
			}
			$written = fwrite( $handle, $contents );
			fflush( $handle );
			return strlen( $contents ) === $written;
		} finally {
			if ( $locked ) {
				@flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
			}
			@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		}
	}
}
