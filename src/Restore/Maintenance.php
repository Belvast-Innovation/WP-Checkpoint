<?php
/**
 * The maintenance file the swap puts up while it renames the site's directories and tables.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Support\AtomicFile;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- a small file in ABSPATH, read byte for byte.

/**
 * WordPress answers every request with its maintenance page while
 * ABSPATH/.maintenance sets $upgrading to a time less than ten minutes
 * ago. The swap writes one that names its restore (mark()): written whole
 * or not at all (AtomicFile; a half-written file would be a parse error on
 * every request), rewritten with the time of the moment between the swap's
 * steps, and removed only while it still holds this restore's own
 * contents. A maintenance file of anyone else (an update in progress, an
 * administrator's own) is never written over or removed: state() says
 * "other" and the swap waits. Whether the file is there is told by
 * positive evidence only (lstat, or its directory listed without it); what
 * cannot be read is "unknown", and nothing is written or removed then.
 */
final class Maintenance {

	const FILE = '.maintenance';

	const NONE    = 'none';
	const OURS    = 'ours';
	const OTHER   = 'other';
	const UNKNOWN = 'unknown';

	/**
	 * Most bytes read of a maintenance file: ours is far shorter, so a longer one is someone else's.
	 */
	const MAX_READ = 4096;

	/**
	 * Directory (ABSPATH, or a test's stand-in).
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * What names this restore in the file.
	 *
	 * @var string
	 */
	private $mark;

	/**
	 * Constructor.
	 *
	 * @param string $dir  Directory the file is in (ABSPATH).
	 * @param string $mark mark() of the restore.
	 */
	public function __construct( string $dir, string $mark ) {
		$this->dir  = rtrim( $dir, '/\\' );
		$this->mark = $mark;
	}

	/**
	 * What names a restore in its maintenance file: the installation's storage token, the job and the run.
	 *
	 * @param string $token  Storage token.
	 * @param int    $job_id Job id.
	 * @param string $random The restore's random part (hex).
	 * @return string
	 */
	public static function mark( string $token, int $job_id, string $random ): string {
		return sprintf( 'WP Checkpoint restore %s-%d-%s', preg_replace( '/[^0-9a-f]/', '', $token ), $job_id, preg_replace( '/[^0-9a-f]/', '', $random ) );
	}

	/**
	 * The file's contents for a time.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public function contents( int $time ): string {
		return "<?php\n\$upgrading = " . $time . '; // ' . $this->mark . "\n";
	}

	/**
	 * Whether the file holds this restore's contents (any time).
	 *
	 * @param string $contents Contents.
	 * @return bool
	 */
	public function is_ours( string $contents ): bool {
		return 1 === preg_match( '/\A<\?php\n\$upgrading = [0-9]{1,19}; \/\/ ' . preg_quote( $this->mark, '/' ) . '\n\z/', $contents );
	}

	/**
	 * The time a file of this restore's says, or null when it is not one.
	 *
	 * @param string $contents Contents.
	 * @return int|null
	 */
	public function time_of( string $contents ) {
		return $this->is_ours( $contents ) && 1 === preg_match( '/= ([0-9]+);/', $contents, $m ) ? (int) $m[1] : null;
	}

	/**
	 * What is there: NONE (positively not there), OURS, OTHER (anything else, a link included), or UNKNOWN (it
	 * could not be told).
	 *
	 * @return string
	 */
	public function state(): string {
		$path = $this->path();
		clearstatcache( true, $path );
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would name the path.
		if ( false === $stat ) {
			return Paths::positively_gone( $path ) ? self::NONE : self::UNKNOWN;
		}
		if ( 0100000 !== ( $stat['mode'] & 0170000 ) ) {
			return self::OTHER;
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $handle ) {
			return self::UNKNOWN;
		}
		$contents = @fread( $handle, self::MAX_READ + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $contents ) {
			return self::UNKNOWN;
		}
		return $this->is_ours( $contents ) ? self::OURS : self::OTHER;
	}

	/**
	 * Put up (or refresh) this restore's file with a time.
	 *
	 * @param int           $time    Unix time.
	 * @param callable|null $confirm Called right before the file is put in place (a lease check; throws to stop).
	 * @return void
	 * @throws \RuntimeException When another maintenance file is there or what is there cannot be told; nothing is
	 *                           written.
	 * @throws \WPCheckpoint\Support\AtomicWriteFailed When it could not be written.
	 */
	public function put( int $time, $confirm = null ): void {
		$state = $this->state();
		if ( self::OTHER === $state || self::UNKNOWN === $state ) {
			throw new \RuntimeException( self::OTHER === $state ? 'Another maintenance file is in place.' : 'Whether a maintenance file is in place cannot be told.' );
		}
		AtomicFile::write( $this->dir, self::FILE, $this->contents( $time ), null === $confirm ? array() : array( 'confirm' => $confirm ) );
	}

	/**
	 * Take this restore's file down. Anything else in its place stays.
	 *
	 * @return bool Whether none of this restore's is there now (removed, or none was).
	 * @throws \WPCheckpoint\Support\DeletionRefused When the Deleter refuses the path (not ABSPATH).
	 */
	public function remove(): bool {
		$state = $this->state();
		if ( self::OURS === $state ) {
			return Deleter::delete_maintenance_file( $this->dir, self::FILE ) && self::OURS !== $this->state();
		}
		return self::UNKNOWN !== $state;
	}

	/**
	 * The file's path.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->dir . DIRECTORY_SEPARATOR . self::FILE;
	}
}
