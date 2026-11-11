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
	const MAX_READ = 16384;

	/**
	 * The time of a held file on a 64-bit PHP: 2100-01-01, which WordPress's ten minutes never reach.
	 */
	const HELD_64 = 4102444800;

	/**
	 * The data file: the mark, the time, the pass hash (64 lowercase hex characters, or NO_PASS), and, written since the
	 * run without WordPress (b2), the SHA-256 of the runtime the swap wrote next to it (RUNTIME, Jobs\WebRuntime): CODE
	 * runs the runtime only when it says that hash of itself. The four lines of the earlier form are read as ever.
	 */
	const DATA_FORM = '/\A<\?php exit; \?>\n(WP Checkpoint restore [0-9a-f]{32})\n([0-9]{1,19})\n([0-9a-f]{64}|-)\n(?:([0-9a-f]{64})\n)?\z/';

	/**
	 * The runtime a request held by the maintenance file runs to put a half-made swap back (Jobs\WebRuntime::write()):
	 * the classes a run without WordPress needs, in one file next to the maintenance file (the plugin's own directory is
	 * one the swap renames).
	 */
	const RUNTIME = '.wpcheckpoint-runtime.php';

	/**
	 * The visitor file (Jobs\VisitorFile): its modification time is when CODE may next run the runtime (the throttle,
	 * set before any database query), its contents the pace of runs held up (Jobs\WebPace).
	 */
	const VISITOR = '.wpcheckpoint-visitor.php';

	/**
	 * The runtime's log (Jobs\RuntimeLog): what runs without WordPress did not take on, while the site is held.
	 */
	const LOG = '.wpcheckpoint-runtime-log.php';

	/**
	 * The files of a run without WordPress, in the order they are taken down after the data file (the log last: it is
	 * added to the job's log first).
	 */
	const WEB_FILES = array( self::VISITOR, self::RUNTIME, self::LOG );

	/**
	 * How a file of WEB_FILES names its restore: a line of its own with the mark (its first lines).
	 */
	const WEB_MARK = '/^# (WP Checkpoint restore [0-9a-f]{32})$/m';

	/**
	 * The single file #82 wrote: the time and the mark.
	 */
	const LEGACY_FORM = '/\A<\?php\n\$upgrading = ([0-9]{1,19}); \/\/ (WP Checkpoint restore [0-9a-f]{32})\n\z/';

	/**
	 * The code of .maintenance as released before the run without WordPress (CODE now), still told as this plugin's:
	 * the same for every restore. WordPress require()s it inside wp_is_maintenance_mode(),
	 * with $upgrading global: it declares nothing (a second require must not fail), and what else it sets is local.
	 * passes() is the same rule for the plugin's own code; MaintenanceTest runs both on the same requests. While it
	 * blocks, $upgrading is a float, never an integer: WordPress lets a request through a maintenance file whose time is
	 * an integer when it carries that time and its MD5 (wp_scrape_key, wp_scrape_nonce: its check for fatal errors after
	 * a plugin or theme edit), and a held file's time is public. Pinned (MaintenanceTest): code a released version wrote
	 * stays in KNOWN_CODES, so that a file it put up is still told as this plugin's. Written line by line with "\n",
	 * not as a nowdoc: the line breaks of a nowdoc are the source file's, which a checkout on Windows (or an upload in
	 * text mode) turns into "\r\n", and a file that version put up would no longer be told as this plugin's.
	 */
	const CODE_1 =
		'<?php' . "\n"
		. '// WP Checkpoint: a restore holds this site. Its mark, its time and its pass are in .wpcheckpoint-maintenance.php.' . "\n"
		. '$upgrading         = (float) time();' . "\n"
		. '$wpcheckpoint_data = @file_get_contents( __DIR__ . \'/.wpcheckpoint-maintenance.php\' );' . "\n"
		. 'if ( is_string( $wpcheckpoint_data ) && 1 === preg_match( \'/\\A<\\?php exit; \\?>\\nWP Checkpoint restore [0-9a-f]{32}\\n([0-9]{1,19})\\n([0-9a-f]{64}|-)\\n\\z/\', $wpcheckpoint_data, $wpcheckpoint_found ) ) {' . "\n"
		. '	$upgrading         = (float) $wpcheckpoint_found[1];' . "\n"
		. '	$wpcheckpoint_pass = isset( $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] ) && is_string( $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] ) ? $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] : \'\';' . "\n"
		. '	if ( \'-\' !== $wpcheckpoint_found[2] && 1 === preg_match( \'/\\A[0-9a-f]{64}\\z/\', $wpcheckpoint_pass ) && hash_equals( $wpcheckpoint_found[2], hash( \'sha256\', $wpcheckpoint_pass ) ) ) {' . "\n"
		. '		$upgrading = 0;' . "\n"
		. '		if ( ! defined( \'DISABLE_WP_CRON\' ) ) {' . "\n"
		. '			define( \'DISABLE_WP_CRON\', true );' . "\n"
		. '		}' . "\n"
		. '	}' . "\n"
		. '}' . "\n";

	/**
	 * The code of .maintenance as released with the run without WordPress (CODE now), still told as this plugin's: the
	 * same for every restore. WordPress require()s it inside wp_is_maintenance_mode(),
	 * with $upgrading global: it declares nothing (a second require must not fail: the runtime is included only while
	 * its entry class is not declared, and the constant is defined only when it is not), and what else it sets is
	 * local. passes() is the same rule for the plugin's own code; MaintenanceTest runs both on the same requests. While
	 * it blocks, $upgrading is a float, never an integer: WordPress lets a request through a maintenance file whose
	 * time is an integer when it carries that time and its MD5 (wp_scrape_key, wp_scrape_nonce: its check for fatal
	 * errors after a plugin or theme edit), and a held file's time is public. Pinned (MaintenanceTest): code a released
	 * version wrote stays in KNOWN_CODES. Written line by line with "\n", not as a nowdoc (see CODE_1).
	 *
	 * Beyond CODE_1: a request held (not let through) by a data file that names the runtime's hash (DATA_FORM's fifth
	 * line), outside WP-CLI and outside check_code(), in a process running as the visitor file's owner where POSIX can
	 * tell (not a cron of root's that loads WordPress: the files the run writes would be root's), runs the runtime when
	 * the visitor file's time has come: under a
	 * non-blocking lock of the visitor file where the file system has locks (only a lock someone else holds stops it:
	 * the database lease is what lets one run in, the lock only spares the others the include), it sets that time five
	 * seconds on (before the runtime opens any connection), defines WPCHECKPOINT_WEB_ENTRY (the runtime's first statement
	 * returns without it), includes the runtime (which returns its own hash) and gives Jobs\WebEntry::run() the
	 * directory, the mark, the hash the data file names and the one the runtime said. Nothing it throws goes further.
	 * The request still ends with the maintenance page: $upgrading is not changed.
	 */
	const CODE_2 =
		'<?php' . "\n"
		. '// WP Checkpoint: a restore holds this site. Its mark, its time and its pass are in .wpcheckpoint-maintenance.php; .wpcheckpoint-runtime.php puts a half-made restore back.' . "\n"
		. '$upgrading         = (float) time();' . "\n"
		. '$wpcheckpoint_data = @file_get_contents( __DIR__ . \'/.wpcheckpoint-maintenance.php\' );' . "\n"
		. 'if ( is_string( $wpcheckpoint_data ) && 1 === preg_match( \'/\\A<\\?php exit; \\?>\\n(WP Checkpoint restore [0-9a-f]{32})\\n([0-9]{1,19})\\n([0-9a-f]{64}|-)\\n(?:([0-9a-f]{64})\\n)?\\z/\', $wpcheckpoint_data, $wpcheckpoint_found ) ) {' . "\n"
		. '	$upgrading         = (float) $wpcheckpoint_found[2];' . "\n"
		. '	$wpcheckpoint_pass = isset( $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] ) && is_string( $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] ) ? $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] : \'\';' . "\n"
		. '	if ( \'-\' !== $wpcheckpoint_found[3] && 1 === preg_match( \'/\\A[0-9a-f]{64}\\z/\', $wpcheckpoint_pass ) && hash_equals( $wpcheckpoint_found[3], hash( \'sha256\', $wpcheckpoint_pass ) ) ) {' . "\n"
		. '		$upgrading = 0;' . "\n"
		. '		if ( ! defined( \'DISABLE_WP_CRON\' ) ) {' . "\n"
		. '			define( \'DISABLE_WP_CRON\', true );' . "\n"
		. '		}' . "\n"
		. '	} elseif ( ! empty( $wpcheckpoint_found[4] ) && empty( $wpcheckpoint_check ) && ! ( defined( \'WP_CLI\' ) && WP_CLI ) && ! class_exists( \'WPCheckpoint\\Jobs\\WebEntry\', false ) ) {' . "\n"
		. '		$wpcheckpoint_visitor = __DIR__ . \'/.wpcheckpoint-visitor.php\';' . "\n"
		. '		$wpcheckpoint_lock    = @fopen( $wpcheckpoint_visitor, \'r+\' );' . "\n"
		. '		$wpcheckpoint_busy    = 0;' . "\n"
		. '		if ( false !== $wpcheckpoint_lock && ( ! function_exists( \'posix_geteuid\' ) || @fileowner( $wpcheckpoint_visitor ) === posix_geteuid() ) && ( @flock( $wpcheckpoint_lock, LOCK_EX | LOCK_NB, $wpcheckpoint_busy ) || 1 !== $wpcheckpoint_busy ) ) {' . "\n"
		. '			clearstatcache( true, $wpcheckpoint_visitor );' . "\n"
		. '			$wpcheckpoint_next = @filemtime( $wpcheckpoint_visitor );' . "\n"
		. '			$wpcheckpoint_due  = false !== $wpcheckpoint_next && $wpcheckpoint_next <= time() && @touch( $wpcheckpoint_visitor, time() + 5 );' . "\n"
		. '			@flock( $wpcheckpoint_lock, LOCK_UN );' . "\n"
		. '			@fclose( $wpcheckpoint_lock );' . "\n"
		. '			if ( $wpcheckpoint_due ) {' . "\n"
		. '				if ( ! defined( \'WPCHECKPOINT_WEB_ENTRY\' ) ) {' . "\n"
		. '					define( \'WPCHECKPOINT_WEB_ENTRY\', true );' . "\n"
		. '				}' . "\n"
		. '				try {' . "\n"
		. '					$wpcheckpoint_said = @include __DIR__ . \'/.wpcheckpoint-runtime.php\';' . "\n"
		. '					if ( is_string( $wpcheckpoint_said ) && class_exists( \'WPCheckpoint\\Jobs\\WebEntry\', false ) ) {' . "\n"
		. '						\\WPCheckpoint\\Jobs\\WebEntry::run( __DIR__, $wpcheckpoint_found[1], $wpcheckpoint_found[4], $wpcheckpoint_said );' . "\n"
		. '					}' . "\n"
		. '				} catch ( \\Throwable $wpcheckpoint_error ) {' . "\n"
		. '					unset( $wpcheckpoint_error );' . "\n"
		. '				}' . "\n"
		. '			}' . "\n"
		. '		} elseif ( false !== $wpcheckpoint_lock ) {' . "\n"
		. '			@fclose( $wpcheckpoint_lock );' . "\n"
		. '		}' . "\n"
		. '	}' . "\n"
		. '}' . "\n";

	/**
	 * The code of .maintenance, the same for every restore. WordPress require()s it inside wp_is_maintenance_mode(),
	 * with $upgrading global: it declares nothing (a second require must not fail), and what else it sets is local.
	 * Pinned (MaintenanceTest), and written line by line with "\n" (see CODE_1). passes() is its rule for the pass key.
	 *
	 * Beyond CODE_2 (whose every rule it keeps): once a run without WordPress put the site back and only the flush of
	 * the object cache is left, which nothing can do before WordPress is loaded, that run records it in the visitor file
	 * (Jobs\VisitorFile::await_flush(): "flush", how many requests were let through for it and when the next may be).
	 * A held request whose time has come (the visitor file's time and lock, as for the runtime) then finds the record,
	 * of this restore's mark, and runs no runtime (there is nothing left for it to rename). It is let through
	 * ($upgrading 0, DISABLE_WP_CRON and WPCHECKPOINT_FLUSH_PASS defined) only when the file under Loader::NAME in the
	 * must-use plugins directory (WPMU_PLUGIN_DIR when wp-config.php defined it, else WordPress's default under
	 * WP_CONTENT_DIR: wp_plugin_directory_constants() runs after this file) holds the loader's code, by its SHA-256
	 * (the loader ends that request with the maintenance page's answer after the flush; any other file there would let
	 * the whole site run on what the cache still holds), and when the record's next time has come: still under the
	 * lock, the count is raised and written first, the request let through after. The first FLUSH_TRIES go at the
	 * runtime's own pace; after them one is let through every FLUSH_WAIT seconds, each said in the runtime log. Without
	 * the loader nothing is let through: WP-CLI flushes and ends it. Nor is anything let through that the loader would
	 * not end (its own conditions): a process on the command line (any, not WP-CLI alone: it runs no runtime either,
	 * and never touches the visitor file), a request for which SHORTINIT is true (WordPress stops before the must-use
	 * plugins), a data file whose time is not a held one, no runtime file next to this one. The order matters: with the
	 * record there, the visitor file's time is set on only once all of that holds and the count is written, so a
	 * request that cannot be let through takes neither a try nor the pace's window from one that can. Without the
	 * record a SHORTINIT request runs the runtime as any other (the runtime needs nothing of WordPress). The request
	 * let through does not load a page cache (the advanced-cache.php drop-in, which WordPress includes before the
	 * must-use plugins and which would answer it from its cache): enable_loading_advanced_cache_dropin is filtered to
	 * false for it (plugin.php is loaded before this file is required).
	 */
	const CODE =
		'<?php' . "\n"
		. '// WP Checkpoint: a restore holds this site. Its mark, its time and its pass are in .wpcheckpoint-maintenance.php; .wpcheckpoint-runtime.php puts a half-made restore back.' . "\n"
		. '$upgrading         = (float) time();' . "\n"
		. '$wpcheckpoint_data = @file_get_contents( __DIR__ . \'/.wpcheckpoint-maintenance.php\' );' . "\n"
		. 'if ( is_string( $wpcheckpoint_data ) && 1 === preg_match( \'/\\A<\\?php exit; \\?>\\n(WP Checkpoint restore [0-9a-f]{32})\\n([0-9]{1,19})\\n([0-9a-f]{64}|-)\\n(?:([0-9a-f]{64})\\n)?\\z/\', $wpcheckpoint_data, $wpcheckpoint_found ) ) {' . "\n"
		. '	$upgrading         = (float) $wpcheckpoint_found[2];' . "\n"
		. '	$wpcheckpoint_pass = isset( $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] ) && is_string( $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] ) ? $_SERVER[\'HTTP_X_WPCHECKPOINT_PASS\'] : \'\';' . "\n"
		. '	if ( \'-\' !== $wpcheckpoint_found[3] && 1 === preg_match( \'/\\A[0-9a-f]{64}\\z/\', $wpcheckpoint_pass ) && hash_equals( $wpcheckpoint_found[3], hash( \'sha256\', $wpcheckpoint_pass ) ) ) {' . "\n"
		. '		$upgrading = 0;' . "\n"
		. '		if ( ! defined( \'DISABLE_WP_CRON\' ) ) {' . "\n"
		. '			define( \'DISABLE_WP_CRON\', true );' . "\n"
		. '		}' . "\n"
		. '	} elseif ( ! empty( $wpcheckpoint_found[4] ) && empty( $wpcheckpoint_check ) && \'cli\' !== PHP_SAPI && ! ( defined( \'WP_CLI\' ) && WP_CLI ) && ! class_exists( \'WPCheckpoint\\Jobs\\WebEntry\', false ) ) {' . "\n"
		. '		$wpcheckpoint_visitor = __DIR__ . \'/.wpcheckpoint-visitor.php\';' . "\n"
		. '		$wpcheckpoint_lock    = @fopen( $wpcheckpoint_visitor, \'r+\' );' . "\n"
		. '		$wpcheckpoint_busy    = 0;' . "\n"
		. '		$wpcheckpoint_let     = false;' . "\n"
		. '		if ( false !== $wpcheckpoint_lock && ( ! function_exists( \'posix_geteuid\' ) || @fileowner( $wpcheckpoint_visitor ) === posix_geteuid() ) && ( @flock( $wpcheckpoint_lock, LOCK_EX | LOCK_NB, $wpcheckpoint_busy ) || 1 !== $wpcheckpoint_busy ) ) {' . "\n"
		. '			clearstatcache( true, $wpcheckpoint_visitor );' . "\n"
		. '			$wpcheckpoint_next = @filemtime( $wpcheckpoint_visitor );' . "\n"
		. '			$wpcheckpoint_text = @stream_get_contents( $wpcheckpoint_lock );' . "\n"
		. '			if ( is_string( $wpcheckpoint_text ) && 1 === preg_match( \'/\\A(<\\?php exit; \\?>\\n# (WP Checkpoint restore [0-9a-f]{32})\\n\\{[^\\n]*"flush":\\{"n":)([0-9]{1,9})(,"next":)([0-9]{1,10})(\\}[^\\n]*\\}\\n)\\z/\', $wpcheckpoint_text, $wpcheckpoint_flush ) && $wpcheckpoint_flush[2] === $wpcheckpoint_found[1] ) {' . "\n"
		. '				$wpcheckpoint_due    = false;' . "\n"
		. '				$wpcheckpoint_loader = false !== $wpcheckpoint_next && $wpcheckpoint_next <= time() && (int) $wpcheckpoint_flush[5] <= time() && ! ( defined( \'SHORTINIT\' ) && SHORTINIT ) && in_array( $wpcheckpoint_found[2], array( \'4102444800\', \'2147483647\' ), true ) && @file_exists( __DIR__ . \'/.wpcheckpoint-runtime.php\' ) ? @file_get_contents( ( defined( \'WPMU_PLUGIN_DIR\' ) ? WPMU_PLUGIN_DIR : ( defined( \'WP_CONTENT_DIR\' ) ? WP_CONTENT_DIR : __DIR__ . \'/wp-content\' ) . \'/mu-plugins\' ) . \'/!wpcheckpoint-runtime.php\', false, null, 0, 8193 ) : false;' . "\n"
		. '				if ( is_string( $wpcheckpoint_loader ) && in_array( hash( \'sha256\', $wpcheckpoint_loader ), array( \'e1ba77d7c466b93d01a56c5afe2075cc6abfd9ace30c5f96109986b4b8fb0d63\' ), true ) ) {' . "\n"
		. '					$wpcheckpoint_tries = (int) $wpcheckpoint_flush[3] + 1;' . "\n"
		. '					$wpcheckpoint_text  = $wpcheckpoint_flush[1] . $wpcheckpoint_tries . $wpcheckpoint_flush[4] . ( $wpcheckpoint_tries >= 3 ? time() + 300 : 0 ) . $wpcheckpoint_flush[6];' . "\n"
		. '					if ( @rewind( $wpcheckpoint_lock ) && @ftruncate( $wpcheckpoint_lock, 0 ) && strlen( $wpcheckpoint_text ) === @fwrite( $wpcheckpoint_lock, $wpcheckpoint_text ) && @fflush( $wpcheckpoint_lock ) ) {' . "\n"
		. '						@touch( $wpcheckpoint_visitor, time() + 5 );' . "\n"
		. '						$wpcheckpoint_let = true;' . "\n"
		. '						$wpcheckpoint_log = @fopen( __DIR__ . \'/.wpcheckpoint-runtime-log.php\', \'r+\' );' . "\n"
		. '						if ( false !== $wpcheckpoint_log ) {' . "\n"
		. '							@fseek( $wpcheckpoint_log, 0, SEEK_END );' . "\n"
		. '							@fwrite( $wpcheckpoint_log, gmdate( \'Y-m-d H:i:s\' ) . \' The site is put back and waits for its object cache to be flushed: a request was let through to flush it (try \' . $wpcheckpoint_tries . ").\\n" );' . "\n"
		. '							@fclose( $wpcheckpoint_log );' . "\n"
		. '						}' . "\n"
		. '					}' . "\n"
		. '				}' . "\n"
		. '			} else {' . "\n"
		. '				$wpcheckpoint_due = false !== $wpcheckpoint_next && $wpcheckpoint_next <= time() && @touch( $wpcheckpoint_visitor, time() + 5 );' . "\n"
		. '			}' . "\n"
		. '			@flock( $wpcheckpoint_lock, LOCK_UN );' . "\n"
		. '			@fclose( $wpcheckpoint_lock );' . "\n"
		. '			if ( $wpcheckpoint_let ) {' . "\n"
		. '				$upgrading = 0;' . "\n"
		. '				if ( ! defined( \'DISABLE_WP_CRON\' ) ) {' . "\n"
		. '					define( \'DISABLE_WP_CRON\', true );' . "\n"
		. '				}' . "\n"
		. '				if ( ! defined( \'WPCHECKPOINT_FLUSH_PASS\' ) ) {' . "\n"
		. '					define( \'WPCHECKPOINT_FLUSH_PASS\', true );' . "\n"
		. '				}' . "\n"
		. '				if ( function_exists( \'add_filter\' ) ) {' . "\n"
		. '					add_filter( \'enable_loading_advanced_cache_dropin\', static function () { return false; }, PHP_INT_MAX );' . "\n"
		. '				}' . "\n"
		. '			} elseif ( $wpcheckpoint_due ) {' . "\n"
		. '				if ( ! defined( \'WPCHECKPOINT_WEB_ENTRY\' ) ) {' . "\n"
		. '					define( \'WPCHECKPOINT_WEB_ENTRY\', true );' . "\n"
		. '				}' . "\n"
		. '				try {' . "\n"
		. '					$wpcheckpoint_said = @include __DIR__ . \'/.wpcheckpoint-runtime.php\';' . "\n"
		. '					if ( is_string( $wpcheckpoint_said ) && class_exists( \'WPCheckpoint\\Jobs\\WebEntry\', false ) ) {' . "\n"
		. '						\\WPCheckpoint\\Jobs\\WebEntry::run( __DIR__, $wpcheckpoint_found[1], $wpcheckpoint_found[4], $wpcheckpoint_said );' . "\n"
		. '					}' . "\n"
		. '				} catch ( \\Throwable $wpcheckpoint_error ) {' . "\n"
		. '					unset( $wpcheckpoint_error );' . "\n"
		. '				}' . "\n"
		. '			}' . "\n"
		. '		} elseif ( false !== $wpcheckpoint_lock ) {' . "\n"
		. '			@fclose( $wpcheckpoint_lock );' . "\n"
		. '		}' . "\n"
		. '	}' . "\n"
		. '}' . "\n";

	/**
	 * How many requests are let through to flush at the runtime's pace, and the seconds between them after that
	 * (Jobs\WebPace's longest wait): CODE says both.
	 */
	const FLUSH_TRIES = 3;
	const FLUSH_WAIT  = 300;

	const KNOWN_CODES = array( self::CODE_1, self::CODE_2, self::CODE );

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
		if ( ! in_array( $code, self::KNOWN_CODES, true ) ) {
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
		$data = in_array( $code, self::KNOWN_CODES, true ) ? self::parse( self::look( $dir . DIRECTORY_SEPARATOR . self::DATA ) ) : null;
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
		return 'file' === $kind && in_array( $code, self::KNOWN_CODES, true );
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
	 * Whether a directory's data file names a restore: true (a regular file of DATA_FORM), false (positively not: not
	 * there, or a regular file of another form), or null when it cannot be told (it cannot be read, or it is not a
	 * regular file: a link to a data file is read as one by the code that includes it).
	 *
	 * @param string $dir Directory (ABSPATH).
	 * @return bool|null
	 */
	public static function names_restore( string $dir ) {
		$look = self::look( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . self::DATA );
		if ( self::NONE === $look[0] ) {
			return false;
		}
		if ( 'file' !== $look[0] ) {
			return null;
		}
		return null !== self::parse( $look );
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
		if ( ! in_array( $code, self::KNOWN_CODES, true ) ) {
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
			$upgrading          = null;
			$wpcheckpoint_check = true; // CODE runs no runtime here (the check is in the swap's own process).
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
	 * The SHA-256 of the runtime the data file names, or null to keep what this restore's data file names now.
	 *
	 * @var string|null
	 */
	private $runtime;

	/**
	 * Constructor.
	 *
	 * @param string      $dir     Directory the files are in (ABSPATH).
	 * @param string      $mark    mark() of the restore.
	 * @param string|null $runtime The runtime's SHA-256 for the data file to name ('' for none), or null to keep what
	 *                             this restore's data file names (a put() that refreshes the time or the pass).
	 */
	public function __construct( string $dir, string $mark, $runtime = null ) {
		$this->dir     = rtrim( $dir, '/\\' );
		$this->mark    = $mark;
		$this->runtime = null === $runtime ? null : (string) $runtime;
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
	 * This restore's data file for a time and a pass hash, and the runtime's hash when one is named (the fifth line).
	 *
	 * @param int    $time      Unix time.
	 * @param string $pass_hash SHA-256 of the pass key (64 lowercase hex characters), or NO_PASS.
	 * @param string $runtime   SHA-256 of the runtime (64 lowercase hex characters), or '' for none.
	 * @return string
	 */
	public function data( int $time, string $pass_hash = self::NO_PASS, string $runtime = '' ): string {
		return "<?php exit; ?>\n" . $this->mark . "\n" . $time . "\n" . $pass_hash . "\n" . ( '' === $runtime ? '' : $runtime . "\n" );
	}

	/**
	 * The runtime's hash this restore's data file names ('' for none, or when the data file is not this restore's).
	 *
	 * @return string
	 */
	public function runtime_hash(): string {
		$data = self::parse( self::look( $this->data_path() ) );
		return null !== $data && $this->mark === $data['mark'] ? $data['runtime'] : '';
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
		if ( ! in_array( $code, self::KNOWN_CODES, true ) ) {
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
		$data = in_array( $code, self::KNOWN_CODES, true ) ? self::parse( self::look( $this->data_path() ) ) : null;
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
		$runtime = null === $this->runtime ? $this->runtime_hash() : $this->runtime;
		if ( '' !== $runtime && 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $runtime ) ) {
			throw new \InvalidArgumentException( 'Not a runtime hash.' );
		}
		$options = null === $confirm ? array() : array( 'confirm' => $confirm );
		AtomicFile::write( $this->dir, self::DATA, $this->data( $time, $pass_hash, $runtime ), $options );
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
	 * Whether this restore's held data file is there with no .maintenance at all: .maintenance positively not there
	 * (not unknown, not anything else in its place), the data file of DATA_FORM with this restore's mark and the held
	 * time. WordPress's upgrader ends an update by deleting .maintenance whatever it holds, so an update made while
	 * the site is held leaves this.
	 *
	 * @return bool
	 */
	public function left_held(): bool {
		if ( self::NONE !== self::look( $this->path() )[0] ) {
			return false;
		}
		$data = self::parse( self::look( $this->data_path() ) );
		return null !== $data && $this->mark === $data['mark'] && self::held() === $data['time'];
	}

	/**
	 * Whether this restore's held data file is there while .maintenance is positively not a code of this plugin's
	 * (which alone would read it and let it lapse): not there at all (left_held()), or someone else's (an update's,
	 * which its own end deletes). What cannot be told is not it.
	 *
	 * @return bool
	 */
	public function held_alone(): bool {
		list( $kind, $code ) = self::look( $this->path() );
		if ( self::UNKNOWN === $kind || ( 'file' === $kind && in_array( $code, self::KNOWN_CODES, true ) ) ) {
			return false;
		}
		$data = self::parse( self::look( $this->data_path() ) );
		return null !== $data && $this->mark === $data['mark'] && self::held() === $data['time'];
	}

	/**
	 * Where this restore's held data file is there with no code of this plugin's to read it (held_alone()), rewrite
	 * the data file alone with a time, so that it is no longer a held one: the restore's must-use plugin (Loader),
	 * which answers for a deleted .maintenance only while the data file is held, stops answering. No .maintenance is
	 * made, and someone else's is left as it is.
	 *
	 * @param int           $time    Unix time.
	 * @param callable|null $confirm Called right before the file is put in place (a lease check; throws to stop).
	 * @return bool Whether it was rewritten (false: not held alone, nothing written).
	 * @throws AtomicWriteFailed When it could not be written.
	 */
	public function lapse_alone( int $time, $confirm = null ): bool {
		if ( ! $this->held_alone() ) {
			return false;
		}
		$data = self::parse( self::look( $this->data_path() ) );
		if ( null === $data || $this->mark !== $data['mark'] ) {
			return false;
		}
		AtomicFile::write( $this->dir, self::DATA, $this->data( $time, self::NO_PASS, $data['runtime'] ), null === $confirm ? array() : array( 'confirm' => $confirm ) );
		HostFunctions::opcache_invalidate( $this->data_path() );
		return true;
	}

	/**
	 * Take this restore's file down: its runtime log first added to the job's log ($merge), then .maintenance when it
	 * is this restore's (or bare), then the data file when it is this restore's (or of no form) and .maintenance is not
	 * a code this plugin wrote, then the files of a run without WordPress that name this restore (WEB_FILES, the log
	 * last). Anything else stays. The take-down does not wait on the log: a $merge that throws (or none given) puts its
	 * lines in PHP's log instead, redacted (they were masked when written), and the take-down goes on.
	 *
	 * @param callable|null $confirm Called right before each file is deleted (a lease check; throws to stop).
	 * @param callable|null $merge   function( string[] $lines ): void, adds the runtime log's lines to the job's log.
	 * @return bool Whether none of this restore's is there now (removed, or none was).
	 * @throws \WPCheckpoint\Support\DeletionRefused When the Deleter refuses the path (not ABSPATH).
	 * @throws \RuntimeException What $confirm throws to stop; nothing more is deleted then.
	 */
	public function remove( $confirm = null, $merge = null ): bool {
		$state = $this->state();
		if ( self::UNKNOWN === $state ) {
			return false;
		}
		$this->merge_log( $merge );
		return $this->remove_files( $state, $confirm ) && $this->remove_web_files( $confirm );
	}

	/**
	 * The runtime log of this restore, added to the job's log (merge_log()'s rule).
	 *
	 * @param callable|null $merge function( string[] $lines ): void.
	 * @return void
	 */
	private function merge_log( $merge ): void {
		$path  = $this->dir . DIRECTORY_SEPARATOR . self::LOG;
		$lines = self::web_mark( $path ) === $this->mark ? self::log_lines( $path ) : array();
		if ( array() === $lines ) {
			return;
		}
		$merged = false;
		if ( is_callable( $merge ) ) {
			try {
				call_user_func( $merge, $lines );
				$merged = true;
			} catch ( \Throwable $e ) {
				unset( $e ); // Said below, with the lines.
			}
		}
		if ( ! $merged ) {
			$redactor = new \WPCheckpoint\Support\Redactor( \WPCheckpoint\Support\Redactor::installation_secrets() );
			error_log( 'WP Checkpoint: the log of the runs without WordPress could not be added to the job\'s log; it is taken down with the maintenance file. Its lines follow.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the job's log cannot take them.
			foreach ( $lines as $line ) {
				$masked = \WPCheckpoint\Support\Report::mask_paths( $redactor->redact( $line ), array( '{abspath}' => $this->dir ) );
				error_log( 'WP Checkpoint (runs without WordPress): ' . ( is_string( $masked ) ? $masked : 'A line could not be cleaned safely.' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- as above.
			}
		}
	}

	/**
	 * The lines of a runtime log (its header lines left out), or none when it cannot be read.
	 *
	 * @param string $path Path.
	 * @return string[]
	 */
	public static function log_lines( string $path ): array {
		$text = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable: nothing to add.
		if ( ! is_string( $text ) ) {
			return array();
		}
		$out = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( '' === $line || 0 === strpos( $line, '<?php' ) || 0 === strpos( $line, '# ' ) ) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * The mark a file of WEB_FILES names (in its first 512 bytes), '' when none (or it is not a regular file, or cannot
	 * be read).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function web_mark( string $path ): string {
		$read = self::web_head( $path );
		return is_string( $read ) && 1 === preg_match( self::WEB_MARK, $read, $found ) ? $found[1] : '';
	}

	/**
	 * The first 512 bytes of a file of WEB_FILES; false when it is positively not a regular file there (not there, a
	 * link, a directory), null when it is a regular file that cannot be read.
	 *
	 * @param string $path Path.
	 * @return string|false|null
	 */
	private static function web_head( string $path ) {
		clearstatcache( true, $path );
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would name the path.
		if ( false === $stat || 0100000 !== ( $stat['mode'] & 0170000 ) ) {
			return false;
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		if ( false === $handle ) {
			return null;
		}
		$head = @fread( $handle, 512 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		@fclose( $handle ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- see above.
		return is_string( $head ) ? $head : null;
	}

	/**
	 * The files of a run without WordPress that name this restore, taken down in WEB_FILES' order; one that cannot be
	 * read goes too (the names are this plugin's, and no other restore's maintenance file stands here: remove_files()
	 * stopped before this otherwise), so that a take-down never waits on a file it cannot read.
	 *
	 * @param callable|null $confirm Called right before each file is deleted.
	 * @return bool Whether none of them is there now.
	 * @throws \WPCheckpoint\Support\DeletionRefused When the Deleter refuses the path.
	 */
	private function remove_web_files( $confirm ): bool {
		$gone = true;
		foreach ( self::WEB_FILES as $name ) {
			$path = $this->dir . DIRECTORY_SEPARATOR . $name;
			if ( null !== self::web_head( $path ) && self::web_mark( $path ) !== $this->mark ) {
				clearstatcache( true, $path );
				$gone = $gone && ( false === @lstat( $path ) ? Paths::positively_gone( $path ) : true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- another restore's, or none: not this restore's to take down.
				continue;
			}
			$deleted = Deleter::delete_maintenance_file( $this->dir, $name, $confirm );
			HostFunctions::opcache_invalidate( $path );
			$gone = $gone && $deleted;
		}
		return $gone;
	}

	/**
	 * .maintenance and the data file (remove()).
	 *
	 * @param string        $state   state() as read.
	 * @param callable|null $confirm Called right before each file is deleted.
	 * @return bool
	 * @throws \WPCheckpoint\Support\DeletionRefused When the Deleter refuses the path.
	 */
	private function remove_files( string $state, $confirm ): bool {
		if ( self::OURS === $state || self::BARE === $state ) {
			$gone = Deleter::delete_maintenance_file( $this->dir, self::FILE, $confirm );
			HostFunctions::opcache_invalidate( $this->path() );
			if ( ! $gone ) {
				return false;
			}
		}
		list( $kind, $code ) = self::look( $this->path() );
		if ( self::UNKNOWN === $kind || ( 'file' === $kind && in_array( $code, self::KNOWN_CODES, true ) ) ) {
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
	 * @return array{mark: string, time: int, pass: string, runtime: string}|null
	 */
	private static function parse( array $look ) {
		if ( 'file' !== $look[0] || 1 !== preg_match( self::DATA_FORM, $look[1], $found ) ) {
			return null;
		}
		return array(
			'mark'    => $found[1],
			'time'    => (int) $found[2],
			'pass'    => $found[3],
			'runtime' => isset( $found[4] ) ? $found[4] : '',
		);
	}
}
