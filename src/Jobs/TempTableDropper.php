<?php
/**
 * Drops this installation's temporary tables in an order their foreign keys allow.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress side of DropOrder: reads the foreign keys that point at
 * the tables (information_schema, the current database only as the
 * referenced side), plans, and runs the drops on $wpdb. A step with checks
 * off sets the session's foreign_key_checks to 0 for that one statement and
 * puts the previous value back whatever happens.
 *
 * When the keys cannot be read, nothing is dropped with checks off: every
 * table is dropped alone with checks on, and the server refuses what would
 * leave a key pointing at nothing; those tables are tried again on the next
 * pass.
 */
final class TempTableDropper {

	/**
	 * Drop tables.
	 *
	 * @param string[] $tables Names TempTables::is_safe_name() accepts (others are reported as failed).
	 * @param string   $like   The prefix every one of them has (TempTables::owner_prefix()), to find the keys that point at them.
	 * @return array{dropped: string[], failed: string[], kept: array<string, string[]>}
	 */
	public static function drop( array $tables, string $like ): array {
		global $wpdb;
		$out  = array(
			'dropped' => array(),
			'failed'  => array(),
			'kept'    => array(),
		);
		$safe = array();
		foreach ( $tables as $table ) {
			if ( TempTables::is_safe_name( $table ) ) {
				$safe[] = $table;
			} else {
				$out['failed'][] = $table;
			}
		}
		if ( array() === $safe ) {
			return $out;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a server setting.
		$fold = (int) $wpdb->get_var( 'SELECT @@lower_case_table_names' ) > 0;
		$keys = self::keys( $like );
		$plan = DropOrder::plan( $safe, null === $keys ? array() : $keys, $fold );

		$out['kept'] = $plan->kept();
		foreach ( $plan->steps() as $step ) {
			if ( $step['checks_off'] && null === $keys ) {
				$out['failed'] = array_merge( $out['failed'], $step['tables'] ); // Not reached: without keys every table is free.
				continue;
			}
			$ok = $step['checks_off'] ? self::drop_with_checks_off( $step['tables'] ) : self::run_drop( $step['tables'] );
			if ( $ok ) {
				$out['dropped'] = array_merge( $out['dropped'], $step['tables'] );
			} else {
				$out['failed'] = array_merge( $out['failed'], $step['tables'] );
			}
		}
		return $out;
	}

	/**
	 * The foreign keys that point at tables starting with $like, or null when they cannot be read.
	 *
	 * @param string $like Prefix.
	 * @return array<int, array{table: string, referenced: string}>|null
	 */
	private static function keys( string $like ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- foreign keys of the current database.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT CONSTRAINT_SCHEMA, TABLE_NAME, REFERENCED_TABLE_NAME, CONSTRAINT_SCHEMA = DATABASE() FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE UNIQUE_CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME LIKE %s',
				$wpdb->esc_like( $like ) . '%'
			),
			ARRAY_N
		);
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) { // wpdb clears last_error before each query.
			return null;
		}
		$keys = array();
		foreach ( $rows as $row ) {
			$keys[] = array(
				// A table of another database that references one of these is outside the set, whatever its name.
				'table'      => '1' === (string) $row[3] ? (string) $row[1] : (string) $row[0] . '.' . (string) $row[1],
				'referenced' => (string) $row[2],
			);
		}
		return $keys;
	}

	/**
	 * DROP TABLE for tables, checks as they are.
	 *
	 * @param string[] $tables Safe names.
	 * @return bool
	 */
	private static function run_drop( array $tables ): bool {
		global $wpdb;
		$list = implode(
			', ',
			array_map(
				static function ( string $table ): string {
					return '`' . $table . '`';
				},
				$tables
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- this installation's temporary tables, names validated by TempTables::is_safe_name().
		return false !== $wpdb->query( "DROP TABLE IF EXISTS {$list}" );
	}

	/**
	 * DROP TABLE for a group only its own members reference, with the session's checks off for that statement.
	 *
	 * @param string[] $tables Safe names.
	 * @return bool
	 */
	private static function drop_with_checks_off( array $tables ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a session setting.
		$before = '0' === (string) $wpdb->get_var( 'SELECT @@SESSION.foreign_key_checks' ) ? 0 : 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a session setting.
		$wpdb->query( 'SET SESSION foreign_key_checks = 0' );
		try {
			return self::run_drop( $tables );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a session setting, 0 or 1.
			$wpdb->query( "SET SESSION foreign_key_checks = {$before}" );
		}
	}
}
