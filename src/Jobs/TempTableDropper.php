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
 * back afterwards (to 0 only when it read 0 before; to 1 when it could not
 * be read): "checks on" must not depend on what other code did with the
 * connection in the same request. It is read back right before every DROP,
 * because a reconnect can happen at any time and brings the server's
 * default; a value other than the one the drop needs (or none) stops the
 * call, and the rest waits for the next pass. A step with checks off turns
 * them off for its one statement, right after reading the keys again (a
 * table outside the group that references a member since the plan was made
 * holds the group back for this pass).
 *
 * One call runs at most MAX_STATEMENTS statements and spends at most
 * MAX_SECONDS seconds before its next statement; what is left is returned
 * as remaining for the next pass, so a reclaim unit stays bounded whatever
 * the number of tables. (One DROP of a large table can itself take longer
 * than that; the bound is checked between statements.)
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
	 * Most DROP statements one call runs; the rest waits for the next pass.
	 */
	const MAX_STATEMENTS = 100;

	/**
	 * Most seconds one call spends before it leaves the rest to the next pass.
	 */
	const MAX_SECONDS = 5.0;

	/**
	 * Why a call stopped early when foreign_key_checks was not what the next drop needs.
	 */
	const CHECKS_CHANGED = 'foreign_key_checks was not as expected right before a drop (a reconnect, or other code on the connection); the rest waits for the next pass';

	/**
	 * Drop tables, at most MAX_STATEMENTS statements and MAX_SECONDS seconds in one call.
	 *
	 * @param string[]      $tables         Names TempTables::is_safe_name() accepts (others are reported as failed).
	 * @param string        $like           The prefix every one of them has (TempTables::owner_prefix()), to find the keys that point at them.
	 * @param int           $max_statements Most statements (tests use fewer).
	 * @param callable|null $clock          function(): float, seconds (tests); microtime by default.
	 * @return array{dropped: string[], failed: string[], kept: array<string, string[]>, remaining: string[], stopped: string}
	 */
	public static function drop( array $tables, string $like, int $max_statements = self::MAX_STATEMENTS, $clock = null ): array {
		global $wpdb;
		$clock = is_callable( $clock ) ? $clock : static function (): float {
			return microtime( true );
		};
		$out   = array(
			'dropped'   => array(),
			'failed'    => array(),
			'kept'      => array(),
			'remaining' => array(),
			'stopped'   => '',
		);
		$safe  = array();
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
		$started = (float) call_user_func( $clock );
		$before  = self::checks();
		self::set_checks( 1 );
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a server setting.
			$fold  = (int) $wpdb->get_var( 'SELECT @@lower_case_table_names' ) > 0;
			$keys  = self::keys( $like );
			$plan  = DropOrder::plan( $safe, null === $keys ? array() : $keys, $fold );
			$steps = $plan->steps();

			$out['kept'] = $plan->kept();
			$run         = 0;
			foreach ( $steps as $n => $step ) {
				if ( $run >= $max_statements || (float) call_user_func( $clock ) - $started >= self::MAX_SECONDS ) {
					$out['remaining'] = self::tables_from( $steps, $n );
					break;
				}
				++$run;
				$ok = false;
				if ( ! $step['checks_off'] ) {
					// Read back right before the statement: a reconnect can happen at any time and brings the server's default.
					if ( ! self::checks_are( 1 ) ) {
						$out['stopped']   = self::CHECKS_CHANGED;
						$out['remaining'] = self::tables_from( $steps, $n );
						break;
					}
					$ok = self::run_drop( $step['tables'] );
				} elseif ( null !== $keys ) { // Without keys every table is free: never reached then.
					$fresh = self::keys( $like );
					if ( null !== $fresh && ! self::referenced_from_outside( $step['tables'], $fresh, $fold ) ) {
						self::set_checks( 0 );
						try {
							if ( ! self::checks_are( 0 ) ) {
								$out['stopped']   = self::CHECKS_CHANGED;
								$out['remaining'] = self::tables_from( $steps, $n );
								break;
							}
							$ok = self::run_drop( $step['tables'] );
						} finally {
							self::set_checks( 1 ); // The run's own setting; the session's is put back at the end.
						}
					}
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
	 * The tables of the steps from $n on.
	 *
	 * @param array<int, array{tables: string[], checks_off: bool}> $steps Steps.
	 * @param int                                                   $n     First step.
	 * @return string[]
	 */
	private static function tables_from( array $steps, int $n ): array {
		$out = array();
		foreach ( array_slice( $steps, $n ) as $step ) {
			$out = array_merge( $out, $step['tables'] );
		}
		return $out;
	}

	/**
	 * Whether the session's foreign_key_checks reads back as $value (not when it cannot be read).
	 *
	 * @param int $value 0 or 1.
	 * @return bool
	 */
	private static function checks_are( int $value ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a session setting.
		return (string) $value === (string) $wpdb->get_var( 'SELECT @@SESSION.foreign_key_checks' );
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
}
