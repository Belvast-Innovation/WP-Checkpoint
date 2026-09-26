<?php
/**
 * Database schema: creation, append-only migrations, compatibility.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The stored schema state carries two numbers: "version" (the last migration
 * applied) and "min_compatible" (the oldest plugin schema level that can
 * still read and write the tables). Migrations are append-only (new
 * columns must have a default or allow NULL, new indexes are fine), so
 * they normally leave min_compatible untouched; only a change that breaks
 * older code raises it. A downgraded plugin therefore keeps working as long
 * as its CURRENT is at least the stored min_compatible, and only sees a
 * notice that the schema comes from a newer version.
 */
final class Schema {

	const OPTION  = 'wpcheckpoint_db_version';
	const CURRENT = 7;

	/**
	 * Jobs table name without the prefix.
	 */
	const JOBS_TABLE = 'wpcheckpoint_jobs';

	/**
	 * The jobs table's columns in their current definitions: name => definition. The table is created from
	 * this list, and ensure() checks the table against it before it records a version.
	 */
	const COLUMNS = array(
		'id'               => 'bigint(20) unsigned NOT NULL AUTO_INCREMENT',
		'site_id'          => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'type'             => "varchar(64) NOT NULL DEFAULT ''",
		'status'           => "varchar(16) NOT NULL DEFAULT 'queued'",
		'step'             => "varchar(64) NOT NULL DEFAULT ''",
		'cursor_json'      => 'longtext NULL',
		'options_json'     => 'longtext NULL',
		'questions_json'   => 'longtext NULL',
		'progress'         => 'tinyint(3) unsigned NOT NULL DEFAULT 0',
		'progress_message' => "varchar(191) NOT NULL DEFAULT ''",
		'attempts'         => 'int(10) unsigned NOT NULL DEFAULT 0',
		'blocked_count'    => 'int(10) unsigned NOT NULL DEFAULT 0',
		'cron_deferrals'   => 'int(10) unsigned NOT NULL DEFAULT 0',
		'storage_token'    => "varchar(32) NOT NULL DEFAULT ''",
		'storage_path'     => "varchar(1024) NOT NULL DEFAULT ''",
		'log_path'         => "varchar(255) NOT NULL DEFAULT ''",
		'last_error'       => 'text NULL',
		'failure_kind'     => "varchar(32) NOT NULL DEFAULT ''",
		'work_expired_at'  => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'takeovers'        => 'int(10) unsigned NOT NULL DEFAULT 0',
		'takeover_mark'    => "varchar(32) NOT NULL DEFAULT ''",
		'owner_user'       => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'created_at'       => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'started_at'       => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'updated_at'       => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'progress_at'      => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'finished_at'      => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'locked_until'     => 'bigint(20) unsigned NOT NULL DEFAULT 0',
		'lock_token'       => "varchar(32) NOT NULL DEFAULT ''",
	);

	/**
	 * The last failed upgrade attempt (Schema::ensure()): when the next may start, and its problems.
	 */
	const RETRY_OPTION = 'wpcheckpoint_db_upgrade_retry';

	/**
	 * Seconds after a failed upgrade attempt before the next.
	 */
	const RETRY_SECONDS = 600;

	/**
	 * Tests: whether this request may upgrade, or null to detect (may_upgrade()).
	 *
	 * @var bool|null
	 */
	private static $may_upgrade = null;

	/**
	 * The problem ensure() reports when the table is not there after creating it.
	 */
	const NO_TABLE = '(the table itself)';

	/**
	 * Oldest schema level the code in this plugin version can operate on.
	 */
	const MIN_COMPATIBLE = 1;

	/**
	 * After this many seconds uninstall starts no further call to drop temporary tables. A call already
	 * running finishes (TempTableDropper bounds it: its first statement, then at most MAX_SECONDS more before
	 * each next one), so uninstall can spend somewhat longer than this.
	 */
	const UNINSTALL_DROP_SECONDS = 15.0;

	/**
	 * Jobs table name (network-wide on multisite, like the storage directory).
	 *
	 * @return string
	 */
	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->base_prefix . self::JOBS_TABLE;
	}

	/**
	 * Stored schema state.
	 *
	 * @return array{version: int, min_compatible: int}
	 */
	public static function stored(): array {
		$stored = Options::get( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array(
			'version'        => isset( $stored['version'] ) ? (int) $stored['version'] : 0,
			'min_compatible' => isset( $stored['min_compatible'] ) ? (int) $stored['min_compatible'] : 0,
		);
	}

	/**
	 * Whether this plugin version may use the stored schema.
	 *
	 * @return bool
	 */
	public static function is_compatible(): bool {
		return self::CURRENT >= self::stored()['min_compatible'];
	}

	/**
	 * Whether the stored schema was written by a newer plugin version.
	 *
	 * @return bool
	 */
	public static function is_newer(): bool {
		return self::stored()['version'] > self::CURRENT;
	}

	/**
	 * Whether the jobs table exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table = $wpdb->base_prefix . self::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- schema check.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * What the jobs table lacks against COLUMNS, read back from the server:
	 * a missing column by its name, a varchar narrower than defined as
	 * "name (varchar(16), needs varchar(32))". Null when the columns could
	 * not be read (no answer is no evidence either way).
	 *
	 * @return string[]|null
	 */
	public static function column_problems() {
		global $wpdb;
		$table = $wpdb->base_prefix . self::JOBS_TABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema check; table name from the prefix and a constant.
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
		if ( ! is_array( $rows ) || array() === $rows ) {
			return null;
		}
		$types = array();
		foreach ( $rows as $row ) {
			if ( isset( $row['Field'], $row['Type'] ) ) {
				$types[ (string) $row['Field'] ] = strtolower( (string) $row['Type'] );
			}
		}
		$problems = array();
		foreach ( self::COLUMNS as $name => $definition ) {
			if ( ! isset( $types[ $name ] ) ) {
				$problems[] = $name;
				continue;
			}
			// A widened varchar (version 6): every supported server reports the width as declared.
			if ( 1 === preg_match( '/^varchar\((\d+)\)/', $definition, $want ) && 1 === preg_match( '/^varchar\((\d+)\)/', $types[ $name ], $have ) && (int) $have[1] < (int) $want[1] ) {
				$problems[] = sprintf( '%s (varchar(%d), needs varchar(%d))', $name, (int) $have[1], (int) $want[1] );
			}
		}
		return $problems;
	}

	/**
	 * Why the jobs table cannot be used, for a job's error or a refusal.
	 *
	 * @param string[]|null $problems column_problems().
	 * @return string
	 */
	public static function problem_message( $problems ): string {
		if ( null === $problems ) {
			return __( 'The columns of the job table could not be read; the database may be unavailable.', 'wp-checkpoint' );
		}
		if ( in_array( self::NO_TABLE, $problems, true ) ) {
			return __( 'The job table could not be created: the database refused it (for example, its user lacks the CREATE privilege).', 'wp-checkpoint' );
		}
		return sprintf(
			/* translators: %s: column names of the plugin's job table, some with the width they have and need. */
			__( 'The job table lacks columns this version of WP Checkpoint needs, or has them narrower (%s). Opening the WP Checkpoint page adds them; if they stay missing, the database refused to change the table (for example, its user lacks the ALTER privilege).', 'wp-checkpoint' ),
			implode( ', ', $problems )
		);
	}

	/**
	 * Why jobs wait while an upgrade is due and this request may not try it (ensure()'s "pending").
	 *
	 * @param string[]|null $last What the last failed attempt found the table lacked (ensure()'s "last_problems").
	 * @return string
	 */
	public static function pending_message( $last = null ): string {
		if ( is_array( $last ) && array() !== $last ) {
			return sprintf(
				/* translators: %s: column names of the plugin's job table, some with the width they have and need. */
				__( 'WP Checkpoint has to update its database table first; the last attempt did not complete (%s). It is tried again from the WP Checkpoint page, by cron or by WP-CLI, at most every ten minutes; jobs continue once it has worked.', 'wp-checkpoint' ),
				implode( ', ', $last )
			);
		}
		return __( 'WP Checkpoint has to update its database table first. That happens on the next visit to the WP Checkpoint page, the next cron run or the next WP-CLI command; jobs continue once it has.', 'wp-checkpoint' );
	}

	/**
	 * The column a problem of column_problems() is about.
	 *
	 * @param string $problem Problem.
	 * @return string
	 */
	public static function problem_column( string $problem ): string {
		$space = strpos( $problem, ' ' );
		return false === $space ? $problem : substr( $problem, 0, $space );
	}

	/**
	 * Create or upgrade the schema. Safe to call on every request: it only
	 * touches the database when the table is missing or the stored version
	 * is behind (with $verify, it also reads the columns back). Never
	 * downgrades. The new version is recorded only after the table's columns
	 * were read back and match COLUMNS; otherwise the stored version stays
	 * and the result is "failed" with the problems. With $verify and the
	 * version current, columns lost since are added again ("repaired", or
	 * "failed" when that did not work).
	 *
	 * Only an admin request, cron and WP-CLI try (may_upgrade()); any other
	 * request (REST: a tick, a loopback hop, a retry or an answer from the
	 * page; a page of the site) only reads the stored version: "pending"
	 * when an upgrade is due (with "last_problems" when an attempt failed:
	 * a record of what the table was then, not evidence of what it is now).
	 * After a failed attempt the next waits RETRY_SECONDS: in the wait,
	 * nothing is sent, but the table is read again, "failed" with what it
	 * lacks now, "pending" when it cannot be read, and on as usual when it
	 * lacks nothing any more.
	 *
	 * @param bool $verify Whether to read the columns back when the version is current (creating a job, the
	 *                     plugin's page, activation, retry and answer; not every tick). Only where the request
	 *                     may upgrade: the page, activation, WP-CLI.
	 * @return array{action: string, version: int, min_compatible: int, problems?: string[]|null, last_problems?: string[]} action: none|created|migrated|repaired|pending|newer|incompatible|failed; problems with failed (read from the table in this call; null when it could not be read).
	 */
	public static function ensure( bool $verify = false ): array {
		$stored = self::stored();
		$result = static function ( string $action, $problems = array() ) use ( $stored ): array {
			$out = array(
				'action'         => $action,
				'version'        => $stored['version'],
				'min_compatible' => $stored['min_compatible'],
			);
			if ( 'failed' === $action ) {
				$out['problems'] = $problems;
			} elseif ( 'pending' === $action && is_array( $problems ) && array() !== $problems ) {
				$out['last_problems'] = $problems;
			}
			return $out;
		};

		if ( $stored['version'] > self::CURRENT ) {
			return $result( self::is_compatible() ? 'newer' : 'incompatible' );
		}

		$exists  = self::table_exists();
		$current = $exists && self::CURRENT === $stored['version'];
		if ( $current && ! $verify ) {
			return $result( 'none' );
		}
		$last = self::last_failure();
		if ( ! self::may_upgrade() ) {
			if ( $current ) {
				return $result( 'none' );
			}
			// The record of a failed attempt says what the table was then, not now (it may have been fixed by
			// hand since): jobs wait for a request that may look, rather than fail on it.
			return $result( 'pending', null === $last ? null : $last['problems'] );
		}
		if ( null !== $last && time() < $last['after'] ) {
			// Nothing is sent to the database in the wait, but the table is read again: its cause may be gone.
			$now = self::verified();
			if ( null === $now ) {
				return $result( 'pending', $last['problems'] );
			}
			if ( array() !== $now ) {
				return $result( 'failed', $now );
			}
			// Nothing missing any more: go on as if there had been no failure.
		}

		if ( $current ) {
			$problems = self::column_problems();
			if ( null === $problems || array() === $problems ) {
				// Nothing missing; unreadable columns are no evidence that something is (nor that it was fixed).
				if ( array() === $problems ) {
					Options::delete( self::RETRY_OPTION );
				}
				return $result( 'none' );
			}
			// Lost after the version was recorded (removed by hand, a table brought from elsewhere): the table
			// in its current shape again. The version stays as it is either way.
			self::quietly( array( __CLASS__, 'create_jobs_table' ) );
			$problems = self::verified();
			if ( array() !== $problems ) {
				self::record_failure( $problems );
				return $result( 'failed', $problems );
			}
			Options::delete( self::RETRY_OPTION );
			return $result( 'repaired' );
		}

		$from = $exists ? $stored['version'] : 0;
		$min  = $exists && $stored['min_compatible'] > 0 ? $stored['min_compatible'] : self::MIN_COMPATIBLE;
		self::quietly(
			static function () use ( $from, &$min ): void {
				for ( $version = $from + 1; $version <= self::CURRENT; $version++ ) {
					$min = max( $min, self::migrate( $version ) );
				}
			}
		);
		// dbDelta() reports nothing when a statement fails (no ALTER privilege, a full disk): the table is the evidence.
		$problems = self::verified();
		if ( null === $problems || array() !== $problems ) {
			self::record_failure( $problems );
			return $result( 'failed', $problems );
		}
		// The record first: a request that dies between the two writes leaves the version behind and no record,
		// and the next attempt migrates again (every migration can be repeated).
		Options::delete( self::RETRY_OPTION );
		Options::set(
			self::OPTION,
			array(
				'version'        => self::CURRENT,
				'min_compatible' => $min,
			)
		);
		return array(
			'action'         => 0 === $from ? 'created' : 'migrated',
			'version'        => self::CURRENT,
			'min_compatible' => $min,
		);
	}

	/**
	 * Whether this request may try an upgrade: an admin request, cron or WP-CLI.
	 *
	 * @return bool
	 */
	private static function may_upgrade(): bool {
		if ( null !== self::$may_upgrade ) {
			return self::$may_upgrade;
		}
		return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Tests: whether this request counts as one that may upgrade (true, false), or null to detect.
	 *
	 * @param bool|null $may Value.
	 * @return void
	 */
	public static function set_upgrade_context( $may ): void {
		self::$may_upgrade = is_bool( $may ) ? $may : null;
	}

	/**
	 * The last failed attempt: when the next may start, and its problems. Null when none is recorded.
	 *
	 * @return array{after: int, problems: string[]|null}|null
	 */
	private static function last_failure() {
		$stored = Options::get( self::RETRY_OPTION, null );
		if ( ! is_array( $stored ) || ! isset( $stored['after'] ) || ! is_int( $stored['after'] ) ) {
			return null;
		}
		$problems = null;
		if ( isset( $stored['problems'] ) && is_array( $stored['problems'] ) ) {
			$problems = array();
			foreach ( $stored['problems'] as $problem ) {
				if ( is_string( $problem ) ) {
					$problems[] = $problem;
				}
			}
		}
		return array(
			// A time further out than one wait (a clock that jumped, a damaged value) has run out.
			'after'    => $stored['after'] > time() + self::RETRY_SECONDS ? 0 : $stored['after'],
			'problems' => $problems,
		);
	}

	/**
	 * Record a failed attempt; the next waits RETRY_SECONDS.
	 *
	 * @param string[]|null $problems What the table lacks.
	 * @return void
	 */
	private static function record_failure( $problems ): void {
		Options::set(
			self::RETRY_OPTION,
			array(
				'after'    => time() + self::RETRY_SECONDS,
				'problems' => $problems,
			)
		);
	}

	/**
	 * What the table lacks after a migration: column_problems(), or NO_TABLE when the table is not there at all.
	 *
	 * @return string[]|null
	 */
	private static function verified() {
		return self::table_exists() ? self::column_problems() : array( self::NO_TABLE );
	}

	/**
	 * Run $work with wpdb's error output off: a refused statement repeats on every call until the cause is fixed,
	 * and its text would go to the error log and, with errors displayed, into a REST response each time. What
	 * is wrong is reported from the table itself (ensure()'s problems).
	 *
	 * @param callable $work Work.
	 * @return void
	 */
	private static function quietly( callable $work ): void {
		global $wpdb;
		$quiet = $wpdb->suppress_errors( true );
		try {
			call_user_func( $work );
		} finally {
			$wpdb->suppress_errors( $quiet );
		}
	}

	/**
	 * Apply one migration. Each is idempotent (dbDelta) and append-only.
	 *
	 * @param int $version Target version.
	 * @return int The min_compatible this migration requires (usually unchanged).
	 */
	private static function migrate( int $version ): int {
		switch ( $version ) {
			case 1:
				self::create_jobs_table();
				return 1;
			case 2:
				// Adds work_expired_at (default 0); older code ignores the column, so min_compatible stays 1.
				self::create_jobs_table();
				return 1;
			case 3:
				// Adds options_json and questions_json (NULL); older code ignores both, so min_compatible stays 1.
				self::create_jobs_table();
				return 1;
			case 4:
				// Adds takeovers (0) and takeover_mark (''); older code ignores both, so min_compatible stays 1.
				self::create_jobs_table();
				return 1;
			case 5:
				// Adds failure_kind (''); older code ignores it, so min_compatible stays 1.
				self::create_jobs_table();
				return 1;
			case 6:
				// Widens failure_kind to varchar(32): version 5 declared it varchar(16) before it was released,
				// and a table migrated with that definition refuses a stamped kind (and with it the whole failing
				// transition). dbDelta() changes the column type; a table created as version 5 is already wide.
				self::create_jobs_table();
				return 1;
			case 7:
				// Adds cron_deferrals (0); older code ignores it, so min_compatible stays 1.
				self::create_jobs_table();
				return 1;
		}
		return self::MIN_COMPATIBLE;
	}

	/**
	 * The jobs table in its current shape; dbDelta() creates it or adds the
	 * columns that are missing (version 1 lacked work_expired_at, version 2
	 * lacked options_json and questions_json, version 3 lacked takeovers and
	 * takeover_mark, version 4 lacked failure_kind, version 5 declared it narrower,
	 * version 6 lacked cron_deferrals).
	 *
	 * @return void
	 */
	private static function create_jobs_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->base_prefix . self::JOBS_TABLE;
		$collate = $wpdb->get_charset_collate();
		$columns = array();
		foreach ( self::COLUMNS as $name => $definition ) {
			$columns[] = "\t\t\t{$name} {$definition},";
		}
		$sql = "CREATE TABLE {$table} (\n" . implode( "\n", $columns ) . "
			PRIMARY KEY  (id),
			KEY status (status),
			KEY status_locked (status,locked_until),
			KEY type (type),
			KEY created_at (created_at)
		) {$collate};";
		dbDelta( $sql );
	}

	/**
	 * Drop the tables (uninstall with data deletion).
	 *
	 * @param callable|null $clock function(): float, seconds (tests); microtime by default.
	 * @return void
	 */
	public static function drop( $clock = null ): void {
		$clock = is_callable( $clock ) ? $clock : static function (): float {
			return microtime( true );
		};
		global $wpdb;
		$state = Directories::load_state();
		$token = isset( $state['token'] ) && is_string( $state['token'] ) ? $state['token'] : '';
		try {
			$prefix = \WPCheckpoint\Jobs\TempTables::owner_prefix( $token );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table listing.
			$names  = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			$tables = array();
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				if ( \WPCheckpoint\Jobs\TempTables::job_id_of( $token, (string) $name ) > 0 ) {
					$tables[] = (string) $name;
				}
			}
			// In an order their foreign keys allow; a table another table still references stays (uninstall only).
			// One call is bounded; uninstall runs one call whatever the time, then more on what the last one left
			// (its "remaining", not its "failed") until one drops nothing or UNINSTALL_DROP_SECONDS have passed.
			// What is left then stays in the database; nothing reports it yet (uninstall has no later pass; a
			// report is a T042 follow-up).
			$started = (float) call_user_func( $clock );
			while ( array() !== $tables ) {
				$result = \WPCheckpoint\Jobs\TempTableDropper::drop( $tables, $prefix );
				if ( array() === $result['dropped'] ) {
					break;
				}
				$tables = $result['remaining'];
				if ( (float) call_user_func( $clock ) - $started >= self::UNINSTALL_DROP_SECONDS ) {
					break;
				}
			}
		} catch ( \InvalidArgumentException $e ) {
			// No usable token: no temporary tables can have been created.
			unset( $e );
		}
		$table = $wpdb->base_prefix . self::JOBS_TABLE;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the prefix and a constant; uninstall only.
		Options::delete( self::OPTION );
		Options::delete( self::RETRY_OPTION );
	}
}
