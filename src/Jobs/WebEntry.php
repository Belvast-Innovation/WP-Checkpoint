<?php
/**
 * Where a request held by the maintenance file comes into the runtime.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\HostFunctions;
use WPCheckpoint\Support\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Called by the maintenance file's code (Restore\Maintenance::CODE) once it included the runtime (WebRuntime), in a
 * request it holds, with WordPress not loaded but wp-config.php run (its database settings and table prefix are what
 * the run connects with): the runtime's hash and the one the data file names first (different: nothing is done, the
 * note says why, and OPcache is told to read the runtime again), then one run of the job that holds the site with this
 * maintenance file (WebRun::tick_mark()), its pace kept in the visitor file (VisitorFile), its notes in the runtime
 * log (RuntimeLog), cleaned (redacted, paths and the site's hosts masked: the request's host among them). Of the request
 * it reads only its host (for the masks) and when it started (the run's budget counts from then). The request then ends
 * with the maintenance page, whatever happened here.
 */
final class WebEntry {

	/**
	 * One run, from the maintenance file's code.
	 *
	 * @param string $dir      The WordPress directory (the maintenance file's).
	 * @param string $mark     The mark the data file names.
	 * @param string $expected The runtime's hash the data file names.
	 * @param string $said     The hash the runtime said of itself.
	 * @return void
	 */
	public static function run( string $dir, string $mark, string $expected, string $said ): void {
		$host  = self::request_host();
		$hosts = '' === $host ? array() : array( $host );
		$log   = new RuntimeLog( $dir, $mark );
		$note  = static function ( string $line ) use ( $log, $dir, $hosts ): void {
			$log->write( WebRun::clean( $line, new Redactor( Redactor::installation_secrets() ), array( '{abspath}' => rtrim( $dir, '/\\' ) ), $hosts ), time() );
		};
		self::quietly(
			$note,
			static function () use ( $dir, $mark, $expected, $said, $note, $hosts ): void {
				self::go( $dir, $mark, $expected, $said, $note, $hosts );
			}
		);
	}

	/**
	 * Run $what so that nothing it says reaches the visitor (a host's display_errors, before WordPress set its own):
	 * display_errors off, any output dropped, and what PHP says meanwhile to $note (but what was silenced with "@",
	 * which the code handles itself); the error handler and the output buffers as they were afterwards.
	 *
	 * @param callable $note function( string $line ): void.
	 * @param callable $what function(): void.
	 * @return void
	 */
	public static function quietly( callable $note, callable $what ): void {
		HostFunctions::ini_set( 'display_errors', '0' );
		$level = ob_get_level();
		ob_start();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- what PHP says during the run goes to the runtime log, never to the visitor.
			static function ( int $number, string $message ) use ( $note ): bool {
				if ( 0 === ( error_reporting() & $number ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting,WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting -- read, not changed: whether "@" silenced it.
					return true; // Silenced with "@" (or not reported): the code handles it.
				}
				call_user_func( $note, 'PHP said: ' . $message );
				return true;
			}
		);
		try {
			call_user_func( $what );
		} finally {
			restore_error_handler();
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * The run (run()).
	 *
	 * @param string   $dir      The WordPress directory.
	 * @param string   $mark     The mark the data file names.
	 * @param string   $expected The runtime's hash the data file names.
	 * @param string   $said     The hash the runtime said of itself.
	 * @param callable $note     function( string $line ): void, into the runtime log.
	 * @param string[] $hosts    The request's host, when it is a domain name.
	 * @return void
	 */
	private static function go( string $dir, string $mark, string $expected, string $said, callable $note, array $hosts ): void {
		if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $expected ) || ! hash_equals( $expected, $said ) ) {
			HostFunctions::opcache_invalidate( rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . Maintenance::RUNTIME );
			$note( 'The runtime here is not the one the restore wrote (its hash is not the one the maintenance file names: an older copy may still be cached); nothing was done. WP-CLI puts the site back: wp wpcheckpoint job list shows the restore.' );
			return;
		}
		$pace   = new VisitorFile( $dir, $mark );
		$plugin = $pace->plugin();
		if ( '' === $plugin ) {
			$note( 'The visitor file of this restore cannot be read; nothing was done. WP-CLI puts the site back: wp wpcheckpoint job list shows the restore.' );
			return;
		}
		try {
			$credentials = Credentials::from_wordpress();
		} catch ( \Throwable $e ) {
			$note( 'The database settings of wp-config.php cannot be read here; nothing was done.' );
			return;
		}
		$run = new WebRun( $credentials, $dir, self::multisite(), $plugin, $note, null, array(), $pace, $hosts );
		$run->tick_mark( $mark, self::started() );
	}

	/**
	 * Whether wp-config.php says the site is a network.
	 *
	 * @return bool
	 */
	private static function multisite(): bool {
		return defined( 'MULTISITE' ) && (bool) constant( 'MULTISITE' );
	}

	/**
	 * When the request started (its budget counts from then).
	 *
	 * @return float
	 */
	private static function started(): float {
		return isset( $_SERVER['REQUEST_TIME_FLOAT'] ) && is_numeric( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
	}

	/**
	 * The request's host name, lowercase and without its port, '' when it is not a domain name (masked in what the run
	 * says): at least two labels, the last of letters. A header anyone can send makes a mask, which only hides more;
	 * one like "job" or "1" would hide words of the notes.
	 *
	 * @return string
	 */
	public static function request_host(): string {
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		if ( 1 !== preg_match( '/\A((?:[A-Za-z0-9-]{1,63}\.)+[A-Za-z]{2,63})\.?(?::[0-9]{1,5})?\z/', $host, $found ) || strlen( $found[1] ) > 253 ) {
			return '';
		}
		return strtolower( $found[1] );
	}
}
