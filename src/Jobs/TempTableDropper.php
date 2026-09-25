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
 * referenced side), plans, and runs the drops on $wpdb.
 *
 * The session's foreign_key_checks is set to 1 for the whole run and put
 * back as it was afterwards, whatever happens: "checks on" must not depend
 * on what other code did with the connection in the same request. A step
 * with checks off turns them off for its one statement, right after reading
 * the keys again (a table outside the group that references a member since
 * the plan was made holds the group back for this pass).
 *
 * When the keys cannot be read, nothing is dropped with checks off: every
 * table is dropped alone with checks on, and the server refuses what would
 * leave a key pointing at nothing; those tables are tried again on the next
 * pass.
 */
final class TempTableDropper {

	/**
	 * How a referencing table in another database is named: its database's name (on shared hosts often the
	 * account's) goes nowhere, and no table of the set can have this name.
	 */
	const ANOTHER_DATABASE = 'a table in another database';

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
		$before = self::checks();
		self::set_checks( 1 );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a server setting.
			$fold = (int) $wpdb->get_var( 'SELECT @@lower_case_table_names' ) > 0;
			$keys = self::keys( $like );
			$plan = DropOrder::plan( $safe, null === $keys ? array() : $keys, $fold );

			$out['kept'] = $plan->kept();
			foreach ( $plan->steps() as $step ) {
				$ok = false;
				if ( ! $step['checks_off'] ) {
					$ok = self::run_drop( $step['tables'] );
				} elseif ( null !== $keys ) { // Without keys every table is free: never reached then.
					$fresh = self::keys( $like );
					$ok    = null !== $fresh && ! self::referenced_from_outside( $step['tables'], $fresh, $fold ) && self::drop_with_checks_off( $step['tables'] );
				}
				if ( $ok ) {
					$out['dropped'] = array_merge( $out['dropped'], $step['tables'] );
				} else {
					$out['failed'] = array_merge( $out['failed'], $step['tables'] );
				}
			}
		} finally {
			self::set_checks( $before );
		}
		return $out;
	}

	/**
	 * Whether a table outside a group references a member.
	 *
	 * @param string[]                                             $group Members.
	 * @param array<int, array{table: string, referenced: string}> $keys  Keys.
	 * @param bool                                                 $fold  Whether names compare without case.
	 * @return bool
	 */
	private static function referenced_from_outside( array $group, array $keys, bool $fold ): bool {
		$in = array();
		foreach ( $group as $table ) {
			$in[ $fold ? strtolower( $table ) : $table ] = true;
		}
		foreach ( $keys as $key ) {
			$child  = $fold ? strtolower( $key['table'] ) : $key['table'];
			$parent = $fold ? strtolower( $key['referenced'] ) : $key['referenced'];
			if ( isset( $in[ $parent ] ) && ! isset( $in[ $child ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The session's foreign_key_checks: 0, or 1 (also when it cannot be read).
	 *
	 * @return int
	 */
	private static function checks(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a session setting.
		return '0' === (string) $wpdb->get_var( 'SELECT @@SESSION.foreign_key_checks' ) ? 0 : 1;
	}

	/**
	 * Set the session's foreign_key_checks.
	 *
	 * @param int $value 0 or 1.
	 * @return void
	 */
	private static function set_checks( int $value ): void {
		global $wpdb;
		$value = 0 === $value ? 0 : 1;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- a session setting, 0 or 1.
		$wpdb->query( "SET SESSION foreign_key_checks = {$value}" );
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
				'SELECT TABLE_NAME, REFERENCED_TABLE_NAME, CONSTRAINT_SCHEMA = DATABASE() FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE UNIQUE_CONSTRAINT_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME LIKE %s',
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
				'table'      => '1' === (string) $row[2] ? (string) $row[0] : self::ANOTHER_DATABASE,
				'referenced' => (string) $row[1],
			);
		}
		return $keys;
	}

	/**
	 * DROP TABLE for tables, with the run's checks (on).
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
		self::set_checks( 0 );
		try {
			return self::run_drop( $tables );
		} finally {
			self::set_checks( 1 ); // The run's own setting; drop() puts the session's back at its end.
		}
	}
}
