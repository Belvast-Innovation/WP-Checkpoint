<?php
/**
 * Every option and transient name the plugin writes.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The one list of the names the plugin stores under in the options and
 * sitemeta tables: exact names, and the names built at run time from a
 * fixed prefix and a suffix of a fixed form (a job or user id, a SHA-256
 * in hex). The PHPStan rule StoredNamesRule holds every call of the
 * options and transients API to it: a name written is a constant listed in
 * EXACT, or comes from one of the builders here.
 *
 * A restore carries the running plugin's rows over the backup's by these
 * exact names (Restore\StateCarry), so that nothing else that starts with
 * "wpcheckpoint_" is touched: a row of the backup's own named like that (a
 * table prefix "wpcheckpoint_" makes "wpcheckpoint_user_roles") stays the
 * backup's. The names built at run time are not carried: they are
 * short-lived (a one-time loopback token and its job's pointer, a probe
 * challenge, a 60-second message) and the running plugin issues new ones.
 */
final class StoredNames {

	const STORAGE          = 'wpcheckpoint_storage';
	const VERSION          = 'wpcheckpoint_version';
	const DB_VERSION       = 'wpcheckpoint_db_version';
	const DB_UPGRADE_RETRY = 'wpcheckpoint_db_upgrade_retry';
	const DELETE_DATA      = 'wpcheckpoint_delete_data_on_uninstall';
	const UNINSTALL_NOTICE = 'wpcheckpoint_uninstall_setting_notice';
	const ESTIMATE         = 'wpcheckpoint_estimate';
	const EXPORT_RATE      = 'wpcheckpoint_export_rate';
	const EXPORT_RESULTS   = 'wpcheckpoint_export_results';
	const ENVIRONMENT      = 'wpcheckpoint_environment';
	const FOREIGN_TABLES   = 'wpcheckpoint_foreign_tables';
	const JOBS_REAPED      = 'wpcheckpoint_jobs_reaped';
	const JOBS_PURGED      = 'wpcheckpoint_jobs_purged';
	const JOBS_SWEPT       = 'wpcheckpoint_jobs_swept';
	const LOCK_RECHECK     = 'wpcheckpoint_lock_recheck';
	const LOCK_VERIFY      = 'wpcheckpoint_lock_verify';

	/**
	 * Every exact name, whatever it is stored as (option, site option, transient, site transient).
	 */
	const EXACT = array(
		self::STORAGE,
		self::VERSION,
		self::DB_VERSION,
		self::DB_UPGRADE_RETRY,
		self::DELETE_DATA,
		self::UNINSTALL_NOTICE,
		self::ESTIMATE,
		self::EXPORT_RATE,
		self::EXPORT_RESULTS,
		self::ENVIRONMENT,
		self::FOREIGN_TABLES,
		self::JOBS_REAPED,
		self::JOBS_PURGED,
		self::JOBS_SWEPT,
		self::LOCK_RECHECK,
		self::LOCK_VERIFY,
	);

	const LOOPBACK_TOKEN  = 'wpcheckpoint_loopback_';
	const LOOPBACK_JOB    = 'wpcheckpoint_loopback_job_';
	const RECLAIM_MESSAGE = 'wpcheckpoint_reclaim_message_';
	const PROBE           = 'wpcheckpoint_probe_';

	/**
	 * The names built at run time: prefix => the form of what follows ("id": decimal digits; "sha256": 64 hex digits).
	 */
	const BUILT = array(
		self::LOOPBACK_TOKEN  => 'sha256',
		self::LOOPBACK_JOB    => 'id',
		self::RECLAIM_MESSAGE => 'id',
		self::PROBE           => 'sha256',
	);

	/**
	 * Where WordPress keeps a transient's value and its expiry, in front of the name.
	 */
	const TRANSIENT_FORMS = array( '_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_' );

	/**
	 * The site transient of a loopback token, by the token's SHA-256.
	 *
	 * @param string $hash SHA-256 of the token, 64 lowercase hex digits.
	 * @return string
	 * @throws \InvalidArgumentException When the hash is not of that form.
	 */
	public static function loopback_token( string $hash ): string {
		return self::built( self::LOOPBACK_TOKEN, $hash );
	}

	/**
	 * The site transient that points from a job to its outstanding loopback token.
	 *
	 * @param int $job_id Job id.
	 * @return string
	 */
	public static function loopback_job( int $job_id ): string {
		return self::built( self::LOOPBACK_JOB, (string) $job_id );
	}

	/**
	 * The site transient of the message a storage reclaim leaves for one user.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function reclaim_message( int $user_id ): string {
		return self::built( self::RECLAIM_MESSAGE, (string) $user_id );
	}

	/**
	 * The site transient of a protection probe's challenge, by the challenge's SHA-256.
	 *
	 * @param string $hash SHA-256 of the challenge, 64 lowercase hex digits.
	 * @return string
	 * @throws \InvalidArgumentException When the hash is not of that form.
	 */
	public static function probe( string $hash ): string {
		return self::built( self::PROBE, $hash );
	}

	/**
	 * The site transient that locks one of the environment actions.
	 *
	 * @param string $which recheck or verify.
	 * @return string
	 * @throws \InvalidArgumentException For another action.
	 */
	public static function environment_lock( string $which ): string {
		if ( 'recheck' === $which ) {
			return self::LOCK_RECHECK;
		}
		if ( 'verify' === $which ) {
			return self::LOCK_VERIFY;
		}
		throw new \InvalidArgumentException( 'No such environment action.' );
	}

	/**
	 * Whether a string is a SHA-256 as the builders take it (64 lowercase hex digits).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_sha256( string $value ): bool {
		return 64 === strlen( $value ) && strspn( $value, '0123456789abcdef' ) === 64;
	}

	/**
	 * Every row name an exact name can be stored under in a table: the name itself (an option, a site option)
	 * and a transient's value and expiry, in the options table (every form) or in sitemeta (the site forms).
	 *
	 * @param bool $sitemeta Whether the table is sitemeta.
	 * @return string[]
	 */
	public static function stored_forms( bool $sitemeta ): array {
		$forms = $sitemeta ? array( '', '_site_transient_', '_site_transient_timeout_' ) : array_merge( array( '' ), self::TRANSIENT_FORMS );
		$out   = array();
		foreach ( self::EXACT as $name ) {
			foreach ( $forms as $form ) {
				$out[] = $form . $name;
			}
		}
		return $out;
	}

	/**
	 * A name built from a prefix of BUILT and a suffix of its form.
	 *
	 * @param string $prefix Prefix.
	 * @param string $suffix Suffix.
	 * @return string
	 * @throws \InvalidArgumentException When the suffix is not of the prefix's form.
	 */
	private static function built( string $prefix, string $suffix ): string {
		$form = self::BUILT[ $prefix ];
		$ok   = 'sha256' === $form ? self::is_sha256( $suffix ) : ( '' !== $suffix && strlen( $suffix ) <= 20 && strspn( $suffix, '0123456789' ) === strlen( $suffix ) );
		if ( ! $ok ) {
			throw new \InvalidArgumentException( 'A stored name built from a value of the wrong form.' );
		}
		return $prefix . $suffix;
	}
}
