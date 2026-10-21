<?php
/**
 * The maintenance file the swap puts up while it renames the site's directories and tables.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Support\AtomicFile;
use WPCheckpoint\Support\AtomicWriteFailed;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\HostFunctions;
use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- small files in ABSPATH, read byte for byte.

/**
 * WordPress answers every request with its maintenance page while
 * ABSPATH/.maintenance sets $upgrading to a time less than ten minutes
 * ago. The swap's .maintenance is the same code for every restore (CODE:
 * whatever a host's OPcache keeps of it is right); what names the restore
 * (its mark, new_mark(): random, kept in the swap's cursor), its time and
 * the hash of the key that lets the restore's own checks through are in a
 * data file next to it (DATA: ".php", its first line "<?php exit; ?>", so
 * a request for it prints nothing), which CODE reads on every request
 * with file_get_contents(), not through OPcache. CODE blocks every request
 * when the data file cannot be read or is not in its form; lets one through
 * whose X-WPCheckpoint-Pass header hashes (SHA-256) to the data file's hash,
 * and then defines DISABLE_WP_CRON (no cron runs in such a request); and
 * otherwise says the data file's time. Both are written whole or not at all
 * (AtomicFile), the data file first and CODE after it; taken down in the
 * other order, CODE first; so a run that dies between the two leaves at most
 * a data file with nothing reading it. CODE is checked before it is put in
 * place (check_code()). After each rename and deletion OPcache is told
 * (opcache_invalidate(), where it can be): harmless, and not relied on.
 *
 * While the site is half swapped the file is held (hold()): its time is
 * held(), far in the future, so WordPress never lets it lapse after its ten
 * minutes. A run that dies there leaves the site answering with the
 * maintenance page, not a mix of the old site and the restored one that
 * visitors could write to; WP-CLI, which runs the swap and its rollback,
 * does not stop for a maintenance file. A maintenance file of anyone else
 * (an update in progress, an administrator's own, another restore's) is
 * never written over or removed: state() says "other" and the swap waits.
 * CODE with no data file of a form it reads ("bare": someone removed or
 * changed the data file) blocks the site and names no restore: the restore
 * that holds the site writes its data file next to it, or takes it down.
 * Whether a file is there is told by positive evidence only (lstat, or its
 * directory listed without it); what cannot be read is "unknown", and
 * nothing is written or removed then.
 *
 * The single file #82 wrote (the time and the mark in .maintenance itself,
 * legacy()) is still read: told as ours or someone else's, held or not, and
 * taken down. It is not rewritten when it is read; a put() of the same
 * restore writes the two files in its place (the data file first: until
 * CODE replaces it, the single file still says the restore's time).
 */
final class Maintenance {

	const FILE = '.maintenance';

	/**
	 * The data file CODE reads (in the same directory).
	 */
	const DATA = '.wpcheckpoint-maintenance.php';

	/**
	 * The request header whose SHA-256 the data file holds while the restore checks the site it swapped in.
	 */
	const PASS_HEADER = 'X-WPCheckpoint-Pass';

	/**
	 * The data file's hash while nothing is let through.
	 */
	const NO_PASS = '-';

	const NONE    = 'none';
	const OURS    = 'ours';
	const OTHER   = 'other';
	const UNKNOWN = 'unknown';
	const BARE    = 'bare';

	/**
	 * Most bytes read of either file: ours are far shorter, so a longer one is someone else's.
	 */
	const MAX_READ = 4096;

	/**
	 * The time of a held file on a 64-bit PHP: 2100-01-01, which WordPress's ten minutes never reach.
	 */
	const HELD_64 = 4102444800;

	/**
	 * The data file: the mark, the time, the pass hash (64 lowercase hex characters, or NO_PASS).
	 */
	const DATA_FORM = '/\A<\?php exit; \?>\n(WP Checkpoint restore [0-9a-f]{32})\n([0-9]{1,19})\n([0-9a-f]{64}|-)\n\z/';

	/**
	 * The single file #82 wrote: the time and the mark.
	 */
	const LEGACY_FORM = '/\A<\?php\n\$upgrading = ([0-9]{1,19}); \/\/ (WP Checkpoint restore [0-9a-f]{32})\n\z/';

	/**
	 * The code of .maintenance, the same for every restore. WordPress require()s it inside wp_is_maintenance_mode(),
	 * with $upgrading global: it declares nothing (a second require must not fail), and what else it sets is local.
	 * passes() is the same rule for the plugin's own code; MaintenanceTest runs both on the same requests.
	 */
	const CODE = <<<'CODE'
<?php
// WP Checkpoint: a restore holds this site. Its mark, its time and its pass are in .wpcheckpoint-maintenance.php.
$upgrading         = time();
$wpcheckpoint_data = @file_get_contents( __DIR__ . '/.wpcheckpoint-maintenance.php' );
if ( is_string( $wpcheckpoint_data ) && 1 === preg_match( '/\A<\?php exit; \?>\nWP Checkpoint restore [0-9a-f]{32}\n([0-9]{1,19})\n([0-9a-f]{64}|-)\n\z/', $wpcheckpoint_data, $wpcheckpoint_found ) ) {
	$upgrading         = (int) $wpcheckpoint_found[1];
	$wpcheckpoint_pass = isset( $_SERVER['HTTP_X_WPCHECKPOINT_PASS'] ) && is_string( $_SERVER['HTTP_X_WPCHECKPOINT_PASS'] ) ? $_SERVER['HTTP_X_WPCHECKPOINT_PASS'] : '';
	if ( '-' !== $wpcheckpoint_found[2] && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $wpcheckpoint_pass ) && hash_equals( $wpcheckpoint_found[2], hash( 'sha256', $wpcheckpoint_pass ) ) ) {
		$upgrading = 0;
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}
	}
}

CODE;

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
	 * Whether a directory holds a held maintenance file of this plugin's (any restore's): the site answers with the
	 * maintenance page, and it does not lapse. CODE with a data file it cannot read counts as held: it blocks every
	 * request, and nothing lets it lapse.
	 *
	 * @param string $dir Directory (ABSPATH).
	 * @return bool
	 */
	public static function held_in( string $dir ): bool {
		$dir                 = rtrim( $dir, '/\\' );
		list( $kind, $code ) = self::look( $dir . DIRECTORY_SEPARATOR . self::FILE );
		if ( 'file' !== $kind ) {
			return false;
		}
		if ( 1 === preg_match( self::LEGACY_FORM, $code, $legacy ) ) {
			return self::held() === (int) $legacy[1];
		}
		if ( self::CODE !== $code ) {
			return false;
		}
		$data = self::parse( self::look( $dir . DIRECTORY_SEPARATOR . self::DATA ) );
		return null === $data || self::held() === $data['time'];
	}

	/**
	 * The mark of the restore whose maintenance file is in a directory, or '' when there is none of this plugin's (or
	 * it cannot be read): what JobRepository::find_by_site_mark() finds the job by. Of the single file, from it; of
	 * CODE, from its data file.
	 *
	 * @param string $dir Directory (ABSPATH).
	 * @return string
	 */
	public static function mark_in( string $dir ): string {
		$dir                 = rtrim( $dir, '/\\' );
		list( $kind, $code ) = self::look( $dir . DIRECTORY_SEPARATOR . self::FILE );
		if ( 'file' !== $kind ) {
			return '';
		}
		if ( 1 === preg_match( self::LEGACY_FORM, $code, $legacy ) ) {
			return $legacy[2];
		}
		$data = self::CODE === $code ? self::parse( self::look( $dir . DIRECTORY_SEPARATOR . self::DATA ) ) : null;
		return null === $data ? '' : $data['mark'];
	}

	/**
	 * Whether .maintenance in a directory is CODE, which reads the data file next to it: true, false (positively not:
	 * not there, or anything else, the single file included), or null when it cannot be told.
	 *
	 * @param string $dir Directory (ABSPATH).
	 * @return bool|null
	 */
	public static function code_in( string $dir ) {
		list( $kind, $code ) = self::look( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . self::FILE );
		if ( self::UNKNOWN === $kind ) {
			return null;
		}
		return 'file' === $kind && self::CODE === $code;
	}

	/**
	 * The mark in a directory's data file, or '' when it has none of its form (or cannot be read).
	 *
	 * @param string $dir Directory (ABSPATH).
	 * @return string
	 */
	public static function data_mark( string $dir ): string {
		$data = self::parse( self::look( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . self::DATA ) );
		return null === $data ? '' : $data['mark'];
	}

	/**
	 * Whether a request with this pass key is let through the maintenance file in a directory: the rule CODE applies,
	 * read the way CODE reads (file_get_contents(), through a link as well), for the plugin's own code in a request
	 * CODE let through.
	 *
	 * @param string $dir Directory (ABSPATH).
	 * @param string $key The request's X-WPCheckpoint-Pass header.
	 * @return bool
	 */
	public static function passes( string $dir, string $key ): bool {
		$dir  = rtrim( $dir, '/\\' );
		$code = @file_get_contents( $dir . DIRECTORY_SEPARATOR . self::FILE ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not there: not let through.
		if ( self::CODE !== $code ) {
			return false;
		}
		$data = @file_get_contents( $dir . DIRECTORY_SEPARATOR . self::DATA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
		if ( ! is_string( $data ) || 1 !== preg_match( self::DATA_FORM, $data, $found ) ) {
			return false;
		}
		return self::NO_PASS !== $found[3] && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $key ) && hash_equals( $found[3], hash( 'sha256', $key ) );
	}

	/**
	 * Check code before it is put in place as .maintenance: it is included once in a function of its own (a parse
	 * error is caught), and, where exec() is there, php -l is run on it as well.
	 *
	 * @param string $path The temporary file.
	 * @return void
	 * @throws AtomicWriteFailed When it does not parse.
	 */
	public static function check_code( string $path ): void {
		$include = static function ( string $file ): void {
			$upgrading = null;
			include $file;
		};
		try {
			$include( $path );
		} catch ( \ParseError $e ) {
			throw new AtomicWriteFailed( 'The maintenance file\'s code does not parse.' );
		}
		if ( false === HostFunctions::lint( $path ) ) {
			throw new AtomicWriteFailed( 'The maintenance file\'s code does not pass php -l.' );
		}
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
	 * @param string $dir  Directory the files are in (ABSPATH).
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
	 * This restore's data file for a time and a pass hash.
	 *
	 * @param int    $time      Unix time.
	 * @param string $pass_hash SHA-256 of the pass key (64 lowercase hex characters), or NO_PASS.
	 * @return string
	 */
	public function data( int $time, string $pass_hash = self::NO_PASS ): string {
		return "<?php exit; ?>\n" . $this->mark . "\n" . $time . "\n" . $pass_hash . "\n";
	}

	/**
	 * The single file #82 wrote for this restore and a time (read, never written: tests write it).
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public function legacy( int $time ): string {
		return "<?php\n\$upgrading = " . $time . '; // ' . $this->mark . "\n";
	}

	/**
	 * What is there: NONE (no .maintenance, positively), OURS (this restore's, either form), BARE (CODE with no data
	 * file of a form it reads), OTHER (anything else, a link included, or CODE with another restore's data file), or
	 * UNKNOWN (it could not be told).
	 *
	 * @return string
	 */
	public function state(): string {
		list( $kind, $code ) = self::look( $this->path() );
		if ( 'file' !== $kind ) {
			return $kind;
		}
		if ( 1 === preg_match( self::LEGACY_FORM, $code, $legacy ) ) {
			return $this->mark === $legacy[2] ? self::OURS : self::OTHER;
		}
		if ( self::CODE !== $code ) {
			return self::OTHER;
		}
		$look = self::look( $this->data_path() );
		if ( self::UNKNOWN === $look[0] || self::OTHER === $look[0] ) {
			return $look[0];
		}
		$data = self::parse( $look );
		if ( null === $data ) {
			return self::BARE;
		}
		return $this->mark === $data['mark'] ? self::OURS : self::OTHER;
	}

	/**
	 * The time this restore's file says, or null when what is there is not this restore's.
	 *
	 * @return int|null
	 */
	public function time() {
		list( $kind, $code ) = self::look( $this->path() );
		if ( 'file' !== $kind ) {
			return null;
		}
		if ( 1 === preg_match( self::LEGACY_FORM, $code, $legacy ) ) {
			return $this->mark === $legacy[2] ? (int) $legacy[1] : null;
		}
		$data = self::CODE === $code ? self::parse( self::look( $this->data_path() ) ) : null;
		return null !== $data && $this->mark === $data['mark'] ? $data['time'] : null;
	}

	/**
	 * Put up (or refresh) this restore's file with a time, and with a pass hash or none: the data file, then CODE
	 * where .maintenance is not CODE yet.
	 *
	 * @param int           $time      Unix time.
	 * @param callable|null $confirm   Called right before each file is put in place (a lease check; throws to stop).
	 * @param string        $pass_hash SHA-256 of the pass key (64 lowercase hex characters), or NO_PASS.
	 * @return void
	 * @throws \InvalidArgumentException When the pass hash is not of its form.
	 * @throws \RuntimeException When another maintenance file is there or what is there cannot be told; nothing is
	 *                           written.
	 * @throws AtomicWriteFailed When it could not be written.
	 */
	public function put( int $time, $confirm = null, string $pass_hash = self::NO_PASS ): void {
		if ( self::NO_PASS !== $pass_hash && 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $pass_hash ) ) {
			throw new \InvalidArgumentException( 'Not a pass hash.' );
		}
		$state = $this->state();
		if ( self::OTHER === $state || self::UNKNOWN === $state ) {
			throw new \RuntimeException( self::OTHER === $state ? 'Another maintenance file is in place.' : 'Whether a maintenance file is in place cannot be told.' );
		}
		$options = null === $confirm ? array() : array( 'confirm' => $confirm );
		AtomicFile::write( $this->dir, self::DATA, $this->data( $time, $pass_hash ), $options );
		HostFunctions::opcache_invalidate( $this->data_path() );
		if ( self::CODE !== self::look( $this->path() )[1] ) {
			AtomicFile::write( $this->dir, self::FILE, self::CODE, $options + array( 'check' => array( self::class, 'check_code' ) ) );
			HostFunctions::opcache_invalidate( $this->path() );
		}
	}

	/**
	 * Put up (or keep up) this restore's file so that it never lapses (held()), while the site is half swapped.
	 *
	 * @param callable|null $confirm   Called right before each file is put in place.
	 * @param string        $pass_hash SHA-256 of the pass key, or NO_PASS.
	 * @return void
	 * @throws \RuntimeException When another maintenance file is there or what is there cannot be told.
	 */
	public function hold( $confirm = null, string $pass_hash = self::NO_PASS ): void {
		$this->put( self::held(), $confirm, $pass_hash );
	}

	/**
	 * Whether this restore's file is there and held.
	 *
	 * @return bool
	 */
	public function is_held(): bool {
		return self::OURS === $this->state() && self::held() === $this->time();
	}

	/**
	 * Take this restore's file down: .maintenance when it is this restore's (or bare), then the data file when it is
	 * this restore's (or of no form) and .maintenance is not CODE. Anything else stays.
	 *
	 * @param callable|null $confirm Called right before each file is deleted (a lease check; throws to stop).
	 * @return bool Whether none of this restore's is there now (removed, or none was).
	 * @throws \WPCheckpoint\Support\DeletionRefused When the Deleter refuses the path (not ABSPATH).
	 * @throws \RuntimeException What $confirm throws to stop; nothing more is deleted then.
	 */
	public function remove( $confirm = null ): bool {
		$state = $this->state();
		if ( self::UNKNOWN === $state ) {
			return false;
		}
		if ( self::OURS === $state || self::BARE === $state ) {
			$gone = Deleter::delete_maintenance_file( $this->dir, self::FILE, $confirm );
			HostFunctions::opcache_invalidate( $this->path() );
			if ( ! $gone ) {
				return false;
			}
		}
		list( $kind, $code ) = self::look( $this->path() );
		if ( self::UNKNOWN === $kind || ( 'file' === $kind && self::CODE === $code ) ) {
			// CODE (another restore's, once this one's is down) reads the data file: it stays.
			return self::UNKNOWN !== $kind && self::OURS !== $this->state();
		}
		$look = self::look( $this->data_path() );
		$data = self::parse( $look );
		if ( 'file' === $look[0] && ( null === $data || $this->mark === $data['mark'] ) ) {
			$gone = Deleter::delete_maintenance_file( $this->dir, self::DATA, $confirm );
			HostFunctions::opcache_invalidate( $this->data_path() );
			return $gone;
		}
		return self::UNKNOWN !== $look[0];
	}

	/**
	 * The path of .maintenance.
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->dir . DIRECTORY_SEPARATOR . self::FILE;
	}

	/**
	 * The path of the data file.
	 *
	 * @return string
	 */
	public function data_path(): string {
		return $this->dir . DIRECTORY_SEPARATOR . self::DATA;
	}

	/**
	 * What is at a path: array( NONE, '' ) when positively not there, array( UNKNOWN, '' ) when it cannot be told,
	 * array( OTHER, '' ) for anything but a regular file of at most MAX_READ bytes (a link included), and
	 * array( 'file', contents ) otherwise.
	 *
	 * @param string $path Path.
	 * @return array{0: string, 1: string}
	 */
	private static function look( string $path ): array {
		clearstatcache( true, $path );
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would name the path.
		if ( false === $stat ) {
			return array( Paths::positively_gone( $path ) ? self::NONE : self::UNKNOWN, '' );
		}
		if ( 0100000 !== ( $stat['mode'] & 0170000 ) ) {
			return array( self::OTHER, '' );
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $handle ) {
			return array( self::UNKNOWN, '' );
		}
		$contents = @fread( $handle, self::MAX_READ + 1 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $contents ) {
			return array( self::UNKNOWN, '' );
		}
		return strlen( $contents ) > self::MAX_READ ? array( self::OTHER, '' ) : array( 'file', $contents );
	}

	/**
	 * A data file's mark and time, or null when it is not a regular file in DATA_FORM.
	 *
	 * @param array{0: string, 1: string} $look look() of it.
	 * @return array{mark: string, time: int, pass: string}|null
	 */
	private static function parse( array $look ) {
		if ( 'file' !== $look[0] || 1 !== preg_match( self::DATA_FORM, $look[1], $found ) ) {
			return null;
		}
		return array(
			'mark' => $found[1],
			'time' => (int) $found[2],
			'pass' => $found[3],
		);
	}
}
