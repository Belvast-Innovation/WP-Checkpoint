<?php
/**
 * Removes plugin data when the plugin is deleted.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\LockFile;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\UninstallFence;

defined( 'ABSPATH' ) || exit;

/**
 * Called from uninstall.php after the plugin has been deactivated.
 *
 * Backups are user data. They, the settings and the tables are only removed
 * when the user opted in beforehand on the Settings tab; otherwise only
 * transient state is cleared so a reinstall picks up where it left off.
 */
final class Uninstaller {

	/**
	 * What expect() leaves in wpdb's last_error until a query runs.
	 */
	const NOT_RUN = 'WP Checkpoint: the query did not run.';

	/**
	 * Option holding the user's choice (boolean, default false).
	 */
	const OPTION_DELETE_DATA = StoredNames::DELETE_DATA;

	/**
	 * Option holding the installed plugin version.
	 */
	const OPTION_VERSION = StoredNames::VERSION;

	/**
	 * Every option the plugin owns. Keep in sync when adding options.
	 *
	 * @var string[]
	 */
	const OPTIONS = array(
		self::OPTION_VERSION,
		self::OPTION_DELETE_DATA,
		Directories::OPTION,
		\WPCheckpoint\Backups\Estimate::OPTION,
		\WPCheckpoint\Backups\Estimate::RATE_OPTION,
		\WPCheckpoint\Backups\ExportResults::OPTION,
		AutoUpdateHold::OPTION,
	);

	/**
	 * User meta keys the plugin owns.
	 *
	 * @var string[]
	 */
	const USER_META = array( 'wpcheckpoint_dismissed_notices' );

	/**
	 * Whether the user asked for a full clean-up.
	 *
	 * @return bool
	 */
	public static function should_delete_data(): bool {
		return UninstallSetting::enabled();
	}

	/**
	 * Run the uninstall routine.
	 *
	 * @return void
	 * @throws \Throwable What a step that removes something threw, once a fence this uninstall closed is open again.
	 */
	public static function run(): void {
		self::clear_transient_state();
		self::at( 'check' );
		$held = self::holding();
		if ( null === $held || 0 !== $held['all'] ) {
			// A restore holds the site changed (its swap under way, or the site as it was kept for an undo), or was
			// abandoned: no job is cancelled, and its staging roots, old tables, job row and storage stay, whatever the
			// user chose; the reason is logged. A count that cannot be read keeps them too.
			error_log( self::held_back( $held ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- uninstall has no screen to say it on.
			return;
		}
		// The fence first (UninstallFence): from here no swap enters the site. Its close waits for one entering now,
		// and the read after it sees that one; a schema older than the fence has no swap that could use it. A fence
		// left closed by an uninstall that did not finish is opened first, so that one closed now is a live uninstall's.
		UninstallFence::heal();
		$fence = UninstallFence::close();
		self::at( 'closed:' . $fence );
		if ( UninstallFence::FAILED === $fence ) {
			error_log( 'WP Checkpoint was uninstalled, but the fence that keeps a restore from starting to change the site meanwhile could not be closed; nothing was removed. Reinstall WP Checkpoint and uninstall it again.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- uninstall has no screen to say it on.
			return;
		}
		if ( UninstallFence::HELD === $fence ) {
			// Another uninstall, on a site sharing this database, holds the fence and opens it when it is done: what
			// this one removed after that would have nothing but the reads in between.
			error_log( 'WP Checkpoint was uninstalled while it was being uninstalled on a site that shares this database (or such an uninstall stopped less than an hour ago); nothing was removed. Reinstall WP Checkpoint and uninstall it again once that is over.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- uninstall has no screen to say it on.
			return;
		}
		// Opened again only by the uninstall that closed it.
		$closed = UninstallFence::DONE === $fence;
		try {
			self::remove( $closed );
		} catch ( \Throwable $e ) {
			// Whatever failed, a fence this uninstall closed does not stay closed (restores on a site sharing this
			// database would be refused until it is healed); a process killed outright leaves that to heal().
			if ( $closed ) {
				UninstallFence::open();
			}
			throw $e;
		}
	}

	/**
	 * The steps that remove something, each right after a read of whether a job holds the site: after the fence's
	 * close, and as a second line for a swap of an older version on a database this site shares (it does not know
	 * the fence).
	 *
	 * @param bool $closed Whether this uninstall closed the fence (it opens it again when it stops or keeps the data).
	 * @return void
	 */
	private static function remove( bool $closed ): void {
		$removed = array();
		if ( self::held_meanwhile( $closed, $removed ) ) {
			return;
		}
		self::at( 'unit:cancel' );
		if ( null === self::cancel_jobs() ) {
			// Which jobs changed the site cannot be read: none was cancelled, and nothing is removed.
			if ( $closed ) {
				UninstallFence::open();
			}
			error_log( self::held_back( null, $removed ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- uninstall has no screen to say it on.
			return;
		}
		$removed[] = 'cancelled the jobs that had not changed the site';
		if ( self::held_meanwhile( $closed, $removed ) ) {
			return;
		}
		self::at( 'unit:staging' );
		self::delete_site_residue();
		$removed[] = 'removed its staging next to the site';

		if ( ! self::should_delete_data() ) {
			if ( $closed ) {
				UninstallFence::open(); // The plugin's files go next; a site sharing this database still restores.
			}
			return;
		}

		if ( self::held_meanwhile( $closed, $removed ) ) {
			return;
		}
		self::at( 'unit:storage' );
		self::delete_storage();
		$removed[] = 'removed its storage directory';
		if ( self::held_meanwhile( $closed, $removed ) ) {
			return;
		}
		self::at( 'unit:temporary' );
		Schema::drop_temporary();
		$removed[] = 'dropped its temporary tables';
		if ( self::held_meanwhile( $closed, $removed ) ) {
			return;
		}
		self::at( 'unit:tables' );
		Schema::drop_tables();
		self::delete_options();
		self::delete_user_meta();
	}

	/**
	 * A test seam: function( string $point ), called before each read of whether a job holds the site ("check"),
	 * after the fence's close ("closed:" and what close() found), and before each step that removes something
	 * ("unit:" and its name). Null outside tests.
	 *
	 * @var callable|null
	 */
	private static $at = null; // @phpstan-ignore property.unusedType (set by tests only, through reflection)

	/**
	 * Call the test seam.
	 *
	 * @param string $point Where run() is.
	 * @return void
	 */
	private static function at( string $point ): void {
		if ( null !== self::$at ) {
			call_user_func( self::$at, $point );
		}
	}

	/**
	 * Whether a job holds the site changed now, or that cannot be read (holding()): then the fence is opened again
	 * (when this uninstall closed it) and the reason is logged, with what was already done.
	 *
	 * @phpstan-impure It reads the database each time.
	 * @param bool     $closed  Whether this uninstall closed the fence.
	 * @param string[] $removed What was done so far, in order.
	 * @return bool
	 */
	private static function held_meanwhile( bool $closed, array $removed ): bool {
		self::at( 'check' );
		$held = self::holding();
		if ( null !== $held && 0 === $held['all'] ) {
			return false;
		}
		if ( $closed ) {
			UninstallFence::open();
		}
		error_log( self::held_back( $held, $removed ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- uninstall has no screen to say it on.
		return true;
	}

	/**
	 * How many jobs hold the site changed (Job::$site_state), or null when that cannot be read (holding()).
	 *
	 * @return int|null
	 */
	public static function jobs_holding_the_site() {
		$held = self::holding();
		return null === $held ? null : $held['all'];
	}

	/**
	 * Why the uninstall stopped (logged: uninstall has no screen), from holding(), and what it had done before it
	 * found that (nothing, when it found it at the start): only what was left is said to be left.
	 *
	 * @param array{all: int, abandoned: int}|null $held    holding().
	 * @param string[]                             $removed What it had done, in order (run()).
	 * @return string
	 */
	public static function held_back( $held, array $removed = array() ): string {
		$then = 'Reinstall WP Checkpoint to finish or undo the restore.';
		if ( null === $held ) {
			$why = 'it could not tell whether a restore holds the site changed';
		} elseif ( $held['abandoned'] > 0 ) {
			$why  = sprintf( 'a restore was abandoned (%d of the %d that hold a site changed): an abandoned restore keeps its tables, which the site it was started on may need to be put back if this database is shared with it', $held['abandoned'], $held['all'] );
			$then = 'They stay until the site the restore was started on takes it over; nothing cleans an abandoned restore up yet.';
		} else {
			$why = 'a restore holds the site changed';
		}
		if ( array() === $removed ) {
			return sprintf( 'WP Checkpoint was uninstalled while %1$s; its staging next to the site, its tables and its storage directory were left in place so the site can still be put back. %2$s', $why, $then );
		}
		$left = array();
		foreach ( array(
			'removed its staging next to the site' => 'its staging next to the site',
			'removed its storage directory'        => 'its storage directory',
			'dropped its temporary tables'         => 'its temporary tables',
		) as $done => $what ) {
			if ( ! in_array( $done, $removed, true ) ) {
				$left[] = $what;
			}
		}
		$left[] = 'its jobs table, swap plan and uninstall fence, and its settings';
		/* The uninstall found it only after it had begun: what it had done, and what it left. */
		return sprintf( 'WP Checkpoint was being uninstalled when %1$s, after it had %2$s; it stopped there and left %3$s in place. %4$s', $why, implode( ', ', $removed ), implode( ', ', $left ), $then );
	}

	/**
	 * How many jobs hold the site changed (Job::$site_state), and how many of them were abandoned; null when that
	 * cannot be read. A table without the column (made before it existed) holds none. Every abandoned job counts,
	 * whoever gave it up: which installation this is cannot be told here (uninstall runs without the plugin, and the
	 * stored state may have been written by another installation sharing this database), and one abandoned from
	 * elsewhere may have left this site half swapped, its staging what the site was.
	 *
	 * @return array{all: int, abandoned: int}|null
	 */
	public static function holding() {
		global $wpdb;
		$table = $wpdb->base_prefix . Schema::JOBS_TABLE;
		// Each answer read with its error: a query that failed answers like "nothing" in wpdb, and nothing is not
		// evidence that no job holds the site.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		self::expect();
		$there = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( self::failed() ) {
			return null;
		}
		if ( $table !== $there ) {
			return array(
				'all'       => 0,
				'abandoned' => 0,
			);
		}
		self::expect();
		$column = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'site_state'", ARRAY_A );
		if ( self::failed() || ! is_array( $column ) ) {
			return null;
		}
		if ( array() === $column ) {
			return array(
				'all'       => 0,
				'abandoned' => 0,
			);
		}
		self::expect();
		$rows = $wpdb->get_results( "SELECT failure_kind, finished_at FROM {$table} WHERE site_state <> 0", ARRAY_N );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( self::failed() || ! is_array( $rows ) ) {
			return null;
		}
		$abandoned = 0;
		foreach ( $rows as $row ) {
			if ( Job::REASON_ABANDONED === Job::read_failure_reason( (string) $row[0], (int) $row[1] ) ) {
				++$abandoned;
			}
		}
		return array(
			'all'       => count( $rows ),
			'abandoned' => $abandoned,
		);
	}

	/**
	 * Mark wpdb's error before a query: wpdb clears it only when it runs one, so a query refused before that (a
	 * query filter that returns nothing, a connection not ready) keeps the mark and reads as failed, not as
	 * "nothing".
	 *
	 * @return void
	 */
	private static function expect(): void {
		global $wpdb;
		$wpdb->last_error = self::NOT_RUN;
	}

	/**
	 * Whether the last query failed or never ran (expect()). wpdb sets its error at every query it runs, and returns
	 * "nothing" for a failure.
	 *
	 * @phpstan-impure
	 * @return bool
	 */
	private static function failed(): bool {
		global $wpdb;
		$failed = '' !== (string) $wpdb->last_error;
		if ( self::NOT_RUN === $wpdb->last_error ) {
			$wpdb->last_error = ''; // The mark is not an error of anyone else's to find.
		}
		return $failed;
	}

	/**
	 * Cancel every queued, running or paused job that has not changed the site,
	 * and remove the lock file of each it cancelled: nothing can continue once
	 * the plugin is gone, and a driver still holding a lock must stop before
	 * the directory or the table disappears. A job that changed the site
	 * meanwhile is left alone (run() reads again after this and stops); a
	 * table without the site_state column (made before it existed) has no such
	 * job. Runs whether or not data is deleted, so a reinstall does not find
	 * jobs that look alive.
	 *
	 * @return int|null Jobs cancelled; null when which jobs changed the site cannot be read (none was cancelled).
	 */
	public static function cancel_jobs() {
		global $wpdb;
		$table = $wpdb->base_prefix . Schema::JOBS_TABLE;
		self::expect();
		$there = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( self::failed() ) {
			return null; // Whether there are jobs cannot be read: none was cancelled.
		}
		if ( $table !== $there ) {
			return 0;
		}
		$state = Directories::load_state();
		$path  = is_string( $state['path'] ) ? rtrim( $state['path'], '/\\' ) : '';
		$live  = array( Job::QUEUED, Job::RUNNING, Job::PAUSED );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- plugin table name from the prefix.
		self::expect();
		$column = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'site_state'", ARRAY_A );
		if ( self::failed() || ! is_array( $column ) ) {
			return null; // Which jobs changed the site cannot be told: none is cancelled, and run() stops.
		}
		$untouched = array() === $column ? '' : ' AND site_state = ' . Job::SITE_UNTOUCHED;
		$now       = time();
		$affected  = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, finished_at = %d, updated_at = %d, lock_token = '', locked_until = 0 WHERE status IN (%s, %s, %s){$untouched}", Job::CANCELLED, $now, $now, $live[0], $live[1], $live[2] ) );
		// The lock files of the jobs this cancelled (one cancelled otherwise in the same second too: it is cancelled).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, storage_path FROM {$table} WHERE status = %s AND finished_at = %d AND updated_at = %d", Job::CANCELLED, $now, $now ), ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			// Only the current directory's files: another directory is another installation's.
			if ( '' !== $path && Paths::same_location( (string) $row['storage_path'], $path ) ) {
				LockFile::remove( $path, (int) $row['id'] );
			}
		}
		return false === $affected ? null : max( 0, (int) $affected );
	}

	/**
	 * Delete this installation's staging roots and probes next to the site's directories (under any of its
	 * tokens), whatever the user chose: they are a cancelled restore's working copies, not user data, and
	 * nothing reaps them once the plugin is gone. Runs after cancel_jobs(), so no run holds them any more.
	 *
	 * Only when the stored state is demonstrably this installation's (no clone detected, and the storage
	 * directory's owner marker names this installation at this ABSPATH): a copied site that never resolved its
	 * storage carries the original's tokens, and the directories may be shared. Otherwise nothing is removed.
	 * Not bounded, as delete_storage() is not: an uninstall that runs out of time is run again, and what was
	 * removed stays removed.
	 *
	 * @return array{deleted: int, failed: string[]}
	 */
	public static function delete_site_residue(): array {
		$result = array(
			'deleted' => 0,
			'failed'  => array(),
		);
		$state  = Directories::load_state();
		if ( ! empty( $state['clone_detected'] ) || '' === self::owned_storage( $state ) ) {
			return $result;
		}
		foreach ( Residue::scan_site( Residue::site_dirs( ScanRoots::site_directories() ), Directories::own_tokens( $state ) ) as $entry ) {
			if ( Residue::STAGE_DIR === $entry['kind'] && Residue::keeps_stray( $entry['path'] ) ) {
				continue; // What a swap's rollback moved out of the way is someone's data, not the plugin's.
			}
			$part               = self::delete_tree( $entry['parent'], $entry['path'] );
			$result['deleted'] += $part['deleted'];
			$result['failed']   = array_merge( $result['failed'], $part['failed'] );
		}
		// The staging of jobs another installation started and this one took over, by the names they made (only those:
		// JobRepository::reclaim_scope(); a job given up keeps its files, which are at the original site's paths).
		$taken = \WPCheckpoint\Jobs\JobRepository::taken_over( Directories::own_tokens( $state ) );
		foreach ( null === $taken ? array() : $taken as $job ) {
			if ( \WPCheckpoint\Jobs\JobRepository::RECLAIM_ALL !== \WPCheckpoint\Jobs\JobRepository::reclaim_scope( $job, Directories::own_tokens( $state ) ) ) {
				continue;
			}
			foreach ( Residue::scan_site( Residue::site_dirs( ScanRoots::site_directories() ), array( $job->storage_token ) ) as $entry ) {
				if ( $entry['id'] !== $job->id || ( Residue::STAGE_DIR === $entry['kind'] && Residue::keeps_stray( $entry['path'] ) ) ) {
					continue;
				}
				$part               = self::delete_tree( $entry['parent'], $entry['path'] );
				$result['deleted'] += $part['deleted'];
				$result['failed']   = array_merge( $result['failed'], $part['failed'] );
			}
		}
		// A swap that died before its rename left a temporary maintenance file; never the maintenance file itself,
		// which a swap only leaves while it holds the site (and then nothing is uninstalled).
		foreach ( Residue::scan_maintenance( Residue::maintenance_dir() ) as $entry ) {
			try {
				if ( Deleter::delete_maintenance_file( Residue::maintenance_dir(), basename( $entry['path'] ) ) ) {
					++$result['deleted'];
				} else {
					$result['failed'][] = $entry['path'];
				}
			} catch ( DeletionRefused $e ) {
				$result['failed'][] = $entry['path'];
			}
		}
		return $result;
	}

	/**
	 * Delete the storage directory, but only when it demonstrably belongs to
	 * this installation.
	 *
	 * A custom directory (WPCHECKPOINT_STORAGE_DIR) is emptied of the plugin's
	 * own sub-directories and files; the directory itself is left alone.
	 * What the Deleter refuses (a custom directory that is a WordPress
	 * directory or holds one, which Directories no longer takes) is counted
	 * as failed and left in place.
	 *
	 * @return array{deleted: int, failed: string[]}
	 */
	public static function delete_storage(): array {
		$none  = array(
			'deleted' => 0,
			'failed'  => array(),
		);
		$state = Directories::load_state();
		$path  = self::owned_storage( $state );
		if ( '' === $path ) {
			return $none;
		}

		if ( Directories::SOURCE_CUSTOM === $state['source'] ) {
			$result = $none;
			foreach ( Directories::SUBDIRS as $sub ) {
				if ( is_dir( $path . DIRECTORY_SEPARATOR . $sub ) ) {
					$part               = self::delete_tree( $path, $path . DIRECTORY_SEPARATOR . $sub );
					$result['deleted'] += $part['deleted'];
					$result['failed']   = array_merge( $result['failed'], $part['failed'] );
				}
			}
			foreach ( array( 'index.php', '.htaccess', OwnerMarker::FILENAME ) as $file ) {
				if ( is_file( $path . DIRECTORY_SEPARATOR . $file ) ) {
					$part               = self::delete_tree( $path, $path . DIRECTORY_SEPARATOR . $file );
					$result['deleted'] += $part['deleted'];
					$result['failed']   = array_merge( $result['failed'], $part['failed'] );
				}
			}
			return $result;
		}

		if ( basename( $path ) !== Directories::DIR_PREFIX . $state['token'] ) {
			return $none;
		}
		$result = self::empty_directory( $path );
		if ( array() === $result['failed'] ) {
			if ( @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- failure is reported below.
				++$result['deleted'];
			} else {
				$result['failed'][] = $path;
			}
		}
		return $result;
	}

	/**
	 * Deleter::empty_directory(), with a refused path logged and counted as a failure (nothing was deleted).
	 *
	 * @param string $path Directory.
	 * @return array{deleted: int, failed: string[]}
	 */
	private static function empty_directory( string $path ): array {
		try {
			$result = Deleter::empty_directory( $path );
			return array(
				'deleted' => $result['deleted'],
				'failed'  => $result['failed'],
			);
		} catch ( DeletionRefused $e ) {
			error_log( 'WP Checkpoint: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- uninstall has no screen to say it on.
			return array(
				'deleted' => 0,
				'failed'  => array( $path ),
			);
		}
	}

	/**
	 * Deleter::delete_tree(), with a refused path logged and counted as a failure (nothing was deleted).
	 *
	 * @param string $base   Base.
	 * @param string $target Target.
	 * @return array{deleted: int, failed: string[], remaining: bool}
	 */
	private static function delete_tree( string $base, string $target ): array {
		try {
			return Deleter::delete_tree( $base, $target );
		} catch ( DeletionRefused $e ) {
			error_log( 'WP Checkpoint: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- uninstall has no screen to say it on.
			return array(
				'deleted'   => 0,
				'failed'    => array( $target ),
				'remaining' => false,
			);
		}
	}

	/**
	 * The stored storage directory, when it demonstrably belongs to this installation: a real directory (not a
	 * link) whose owner marker names this installation at this ABSPATH. '' otherwise.
	 *
	 * @param array<string, mixed> $state Stored state (Directories::load_state()).
	 * @return string
	 */
	private static function owned_storage( array $state ): string {
		$path = is_string( $state['path'] ) ? rtrim( $state['path'], '/\\' ) : '';
		if ( '' === $path || ! is_dir( $path ) || Deleter::is_reparse( $path ) ) {
			return '';
		}
		$marker = $path . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME;
		if ( ! @is_file( $marker ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would name the path.
			return '';
		}
		$contents = @file_get_contents( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- as above; a tiny local file.
		return is_string( $contents ) && OwnerMarker::matches( $contents, (string) $state['install_id'], ABSPATH ) ? $path : '';
	}

	/**
	 * Delete the plugin's user meta for every user.
	 *
	 * @return void
	 */
	public static function delete_user_meta(): void {
		foreach ( self::USER_META as $key ) {
			delete_metadata( 'user', 0, $key, '', true );
		}
	}

	/**
	 * Remove state that has no value after deletion, whatever the user chose.
	 *
	 * @return void
	 */
	public static function clear_transient_state(): void {
		\WPCheckpoint\Jobs\Loopback::unschedule_all();
		delete_site_transient( StoredNames::JOBS_REAPED );
		delete_site_transient( StoredNames::JOBS_PURGED );
		delete_site_transient( StoredNames::JOBS_SWEPT );
		delete_site_transient( StoredNames::FOREIGN_TABLES );
		delete_site_transient( Environment::CACHE );
	}

	/**
	 * Delete every option the plugin owns.
	 *
	 * @return void
	 */
	public static function delete_options(): void {
		foreach ( self::OPTIONS as $option ) {
			Options::delete( $option );
		}
		UninstallSetting::delete_everywhere();
	}
}
