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
	const CURRENT = 4;

	/**
	 * Jobs table name without the prefix.
	 */
	const JOBS_TABLE = 'wpcheckpoint_jobs';

	/**
	 * Oldest schema level the code in this plugin version can operate on.
	 */
	const MIN_COMPATIBLE = 1;

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
	 * Create or upgrade the schema. Safe to call on every request: it only
	 * touches the database when the table is missing or the stored version
	 * is behind. Never downgrades.
	 *
	 * @return array{action: string, version: int, min_compatible: int} action: none|created|migrated|newer|incompatible.
	 */
	public static function ensure(): array {
		$stored = self::stored();

		if ( $stored['version'] > self::CURRENT ) {
			return array(
				'action'         => self::is_compatible() ? 'newer' : 'incompatible',
				'version'        => $stored['version'],
				'min_compatible' => $stored['min_compatible'],
			);
		}

		$exists = self::table_exists();
		if ( $exists && self::CURRENT === $stored['version'] ) {
			return array(
				'action'         => 'none',
				'version'        => $stored['version'],
				'min_compatible' => $stored['min_compatible'],
			);
		}

		$from = $exists ? $stored['version'] : 0;
		$min  = $exists && $stored['min_compatible'] > 0 ? $stored['min_compatible'] : self::MIN_COMPATIBLE;
		for ( $version = $from + 1; $version <= self::CURRENT; $version++ ) {
			$min = max( $min, self::migrate( $version ) );
		}
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
		}
		return self::MIN_COMPATIBLE;
	}

	/**
	 * The jobs table in its current shape; dbDelta() creates it or adds the
	 * columns that are missing (version 1 lacked work_expired_at, version 2
	 * lacked options_json and questions_json, version 3 lacked takeovers and
	 * takeover_mark).
	 *
	 * @return void
	 */
	private static function create_jobs_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = $wpdb->base_prefix . self::JOBS_TABLE;
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			site_id bigint(20) unsigned NOT NULL DEFAULT 0,
			type varchar(64) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'queued',
			step varchar(64) NOT NULL DEFAULT '',
			cursor_json longtext NULL,
			options_json longtext NULL,
			questions_json longtext NULL,
			progress tinyint(3) unsigned NOT NULL DEFAULT 0,
			progress_message varchar(191) NOT NULL DEFAULT '',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			blocked_count int(10) unsigned NOT NULL DEFAULT 0,
			storage_token varchar(32) NOT NULL DEFAULT '',
			storage_path varchar(1024) NOT NULL DEFAULT '',
			log_path varchar(255) NOT NULL DEFAULT '',
			last_error text NULL,
			work_expired_at bigint(20) unsigned NOT NULL DEFAULT 0,
			takeovers int(10) unsigned NOT NULL DEFAULT 0,
			takeover_mark varchar(32) NOT NULL DEFAULT '',
			owner_user bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			started_at bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
			progress_at bigint(20) unsigned NOT NULL DEFAULT 0,
			finished_at bigint(20) unsigned NOT NULL DEFAULT 0,
			locked_until bigint(20) unsigned NOT NULL DEFAULT 0,
			lock_token varchar(32) NOT NULL DEFAULT '',
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
	 * @return void
	 */
	public static function drop(): void {
		global $wpdb;
		$state = Directories::load_state();
		$token = isset( $state['token'] ) && is_string( $state['token'] ) ? $state['token'] : '';
		try {
			$prefix = \WPCheckpoint\Jobs\TempTables::owner_prefix( $token );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- table listing.
			$names = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				if ( \WPCheckpoint\Jobs\TempTables::job_id_of( $token, (string) $name ) > 0 && \WPCheckpoint\Jobs\TempTables::is_safe_name( (string) $name ) ) {
					$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- this installation's temporary table, name validated; uninstall only.
				}
			}
		} catch ( \InvalidArgumentException $e ) {
			// No usable token: no temporary tables can have been created.
			unset( $e );
		}
		$table = $wpdb->base_prefix . self::JOBS_TABLE;
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the prefix and a constant; uninstall only.
		Options::delete( self::OPTION );
	}
}
