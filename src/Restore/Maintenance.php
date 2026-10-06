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
 * contents (its mark, new_mark(): random, kept in the swap's cursor).
 * While the site is half swapped the file is held (hold()): its time is
 * held(), far in the future, so WordPress never lets it lapse after its ten
 * minutes. A run that dies there leaves the site answering with the
 * maintenance page, not a mix of the old site and the restored one that
 * visitors could write to; WP-CLI, which runs the swap and its rollback,
 * does not stop for a maintenance file. A maintenance file of anyone else (an update in progress, an
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
	 * The time of a held file on a 64-bit PHP: 2100-01-01, which WordPress's ten minutes never reach.
	 */
	const HELD_64 = 4102444800;

	/**
	 * The time of a held file (held_for() this PHP's integer size).
	 *
	 * @return int
	 */
	public static function held(): int {
		return self::held_for( PHP_INT_SIZE );
	}

	/**
	 * The time of a held file for an integer size: 2100-01-01, or on a 32-bit PHP the largest integer (2038, as
	 * far beyond WordPress's ten minutes; 2100 would not fit, and could not be written as one).
	 *
	 * @param int $int_size PHP_INT_SIZE.
	 * @return int
	 */
	public static function held_for( int $int_size ): int {
		return $int_size >= 8 ? (int) self::HELD_64 : 2147483647;
	}

	/**
	 * Whether a directory holds a held maintenance file of this plugin's (any restore's mark): the site answers
	 * with the maintenance page, and it does not lapse.
	 *
	 * @param string $dir Directory (ABSPATH).
	 * @return bool
	 */
	public static function held_in( string $dir ): bool {
		$path = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . self::FILE;
		clearstatcache( true, $path );
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not there: not held.
		if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) || $stat['size'] > self::MAX_READ ) {
			return false;
		}
		$contents = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		return is_string( $contents ) && 1 === preg_match( '/\A<\?php\n\$upgrading = ' . self::held() . '; \/\/ WP Checkpoint restore [0-9a-f]{32}\n\z/', $contents );
	}

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
	 * A new mark for a restore's maintenance file: random, and nothing else (the file is in the web root and may be
	 * served: it must not tell the storage token, which names the storage directory, or anything of the job).
	 *
	 * @return string
	 */
	public static function new_mark(): string {
		return 'WP Checkpoint restore ' . bin2hex( random_bytes( 16 ) );
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
	 * Put up (or keep up) this restore's file so that it never lapses (held()), while the site is half swapped.
	 *
	 * @param callable|null $confirm Called right before the file is put in place.
	 * @return void
	 * @throws \RuntimeException When another maintenance file is there or what is there cannot be told.
	 */
	public function hold( $confirm = null ): void {
		$this->put( self::held(), $confirm );
	}

	/**
	 * The mark of the restore whose maintenance file is in a directory, or '' when there is none of this plugin's (or
	 * it cannot be read): what JobRepository::find_by_site_mark() finds the job by.
	 *
	 * @param string $dir Directory (ABSPATH).
	 * @return string
	 */
	public static function mark_in( string $dir ): string {
		$path   = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . self::FILE;
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- not there or not readable: none.
		if ( false === $handle ) {
			return '';
		}
		$contents = @fread( $handle, self::MAX_READ + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- as above.
		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- as above.
		return is_string( $contents ) && 1 === preg_match( '/WP Checkpoint restore [0-9a-f]{32}/', $contents, $found ) ? $found[0] : '';
	}

	/**
	 * Whether this restore's file is there and held.
	 *
	 * @return bool
	 */
	public function is_held(): bool {
		if ( self::OURS !== $this->state() ) {
			return false;
		}
		$contents = @file_get_contents( $this->path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- ours: a few dozen bytes.
		return is_string( $contents ) && self::held() === $this->time_of( $contents );
	}

	/**
	 * Take this restore's file down. Anything else in its place stays.
	 *
	 * @param callable|null $confirm Called right before the file is deleted (a lease check; throws to stop).
	 * @return bool Whether none of this restore's is there now (removed, or none was).
	 * @throws \WPCheckpoint\Support\DeletionRefused When the Deleter refuses the path (not ABSPATH).
	 */
	public function remove( $confirm = null ): bool {
		$state = $this->state();
		if ( self::OURS === $state ) {
			return Deleter::delete_maintenance_file( $this->dir, self::FILE, $confirm ) && self::OURS !== $this->state();
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
