<?php
/**
 * In which order a set of tables can be dropped with foreign keys among them.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. With foreign key checks on, no server drops a table another
 * table still references, and MariaDB and MySQL 5.7 refuse it even when
 * the referencing table is dropped in the same statement (measured on
 * every supported server, tests/Fixtures/Restore/foreign-keys.php case 8a
 * and 12). So the plan drops the tables no remaining table references
 * first, one at a time, with checks on; a key of a table to itself does
 * not count.
 *
 * What is left after that are cycles (a references b, b references a) and
 * the tables they reference. No order drops a cycle with checks on on
 * MariaDB or MySQL 5.7; with checks off, one statement drops it on every
 * server. The plan drops such a group in one statement with checks off,
 * and only when no table outside the set references a member (then no
 * key is left pointing at a dropped table).
 *
 * A table that a table outside the set references is kept, and so is
 * every table a kept table references: dropping it would fail with checks
 * on, and with checks off would leave the other table's key pointing at
 * nothing. The caller reports them.
 */
final class DropOrder {

	/**
	 * The steps: each is a list of tables dropped in one statement, with checks off when "checks_off".
	 *
	 * @var array<int, array{tables: string[], checks_off: bool}>
	 */
	private $steps = array();

	/**
	 * Tables kept: name => the tables outside the set that reference it (directly or through a kept table).
	 *
	 * @var array<string, string[]>
	 */
	private $kept = array();

	/**
	 * Plan the drops.
	 *
	 * @param string[]                                             $tables The tables to drop.
	 * @param array<int, array{table: string, referenced: string}> $keys   Foreign keys whose referenced table is in the set (any referencing table).
	 * @param bool                                                 $fold   Whether the server compares table names without case.
	 * @return self
	 */
	public static function plan( array $tables, array $keys, bool $fold ): self {
		$plan  = new self();
		$key   = static function ( string $name ) use ( $fold ): string {
			return $fold ? strtolower( $name ) : $name;
		};
		$names = array();
		foreach ( $tables as $table ) {
			$names[ $key( $table ) ] = $table;
		}
		// referenced => the tables that reference it.
		$referrers = array();
		$outside   = array();
		foreach ( $keys as $row ) {
			$child  = $key( $row['table'] );
			$parent = $key( $row['referenced'] );
			if ( ! isset( $names[ $parent ] ) || $child === $parent ) {
				continue;
			}
			if ( isset( $names[ $child ] ) ) {
				$referrers[ $parent ][ $child ] = true;
			} else {
				$outside[ $parent ][] = $row['table'];
			}
		}

		// Kept: referenced from outside, and whatever a kept table references (closure).
		$kept  = array();
		$queue = array_keys( $outside );
		while ( array() !== $queue ) {
			$table = (string) array_shift( $queue );
			if ( isset( $kept[ $table ] ) ) {
				continue;
			}
			$kept[ $table ] = true;
			foreach ( $referrers as $parent => $children ) {
				if ( isset( $children[ $table ] ) && ! isset( $kept[ $parent ] ) ) {
					$queue[]              = $parent;
					$outside[ $parent ][] = $names[ $table ];
				}
			}
		}
		foreach ( array_keys( $kept ) as $table ) {
			$plan->kept[ $names[ $table ] ] = array_values( array_unique( $outside[ $table ] ?? array() ) );
		}
		ksort( $plan->kept );

		$left = array_diff_key( $names, $kept );
		while ( array() !== $left ) {
			// Tables no remaining table references: one statement each, checks on.
			$free = array();
			foreach ( array_keys( $left ) as $table ) {
				if ( array() === array_intersect_key( $referrers[ $table ] ?? array(), $left ) ) {
					$free[] = $table;
				}
			}
			if ( array() !== $free ) {
				sort( $free, SORT_STRING );
				foreach ( $free as $table ) {
					$plan->steps[] = array(
						'tables'     => array( $left[ $table ] ),
						'checks_off' => false,
					);
					unset( $left[ $table ] );
				}
				continue;
			}
			// Only cycles left (and what they reference): a group no remaining table outside it references.
			$group          = self::closed_group( $left, $referrers );
			$names_of_group = array();
			foreach ( $group as $table ) {
				$names_of_group[] = $left[ $table ];
				unset( $left[ $table ] );
			}
			sort( $names_of_group, SORT_STRING );
			$plan->steps[] = array(
				'tables'     => $names_of_group,
				'checks_off' => true,
			);
		}
		return $plan;
	}

	/**
	 * The steps, in order.
	 *
	 * @return array<int, array{tables: string[], checks_off: bool}>
	 */
	public function steps(): array {
		return $this->steps;
	}

	/**
	 * Tables kept, name => the tables outside the set that reference it.
	 *
	 * @return array<string, string[]>
	 */
	public function kept(): array {
		return $this->kept;
	}

	/**
	 * A strongly connected group of the remaining tables that no remaining table outside it references (one
	 * exists whenever every remaining table is referenced: the groups form a graph without cycles, and the
	 * one no other group points at is such a group).
	 *
	 * @param array<string, string>              $left      Remaining tables (key => name).
	 * @param array<string, array<string, bool>> $referrers Referenced => referencing tables.
	 * @return string[] Keys of the group.
	 */
	private static function closed_group( array $left, array $referrers ): array {
		// Edges child => parent among the remaining tables.
		$references = array();
		foreach ( $referrers as $parent => $children ) {
			if ( ! isset( $left[ $parent ] ) ) {
				continue;
			}
			foreach ( array_keys( $children ) as $child ) {
				if ( isset( $left[ $child ] ) ) {
					$references[ $child ][ $parent ] = true;
				}
			}
		}
		$tables = array_keys( $left );
		sort( $tables, SORT_STRING );
		foreach ( $tables as $table ) {
			// The tables reachable from $table by following references, and those that reach it back.
			$reach = self::reachable( $table, $references );
			$group = array( $table );
			foreach ( array_keys( $reach ) as $other ) {
				if ( isset( self::reachable( $other, $references )[ $table ] ) ) {
					$group[] = $other;
				}
			}
			$group = array_values( array_unique( $group ) );
			$in    = array_flip( $group );
			$open  = false;
			foreach ( $group as $member ) {
				foreach ( array_keys( $referrers[ $member ] ?? array() ) as $child ) {
					if ( isset( $left[ $child ] ) && ! isset( $in[ $child ] ) ) {
						$open = true;
						break 2;
					}
				}
			}
			if ( ! $open ) {
				return $group;
			}
		}
		return $tables; // Not reached: some group is always closed.
	}

	/**
	 * Tables reachable from a table along its references (not including itself unless on a cycle).
	 *
	 * @param string                             $from       Start.
	 * @param array<string, array<string, bool>> $references Child => parents.
	 * @return array<string, bool>
	 */
	private static function reachable( string $from, array $references ): array {
		$seen  = array();
		$queue = array_keys( $references[ $from ] ?? array() );
		while ( array() !== $queue ) {
			$table = (string) array_shift( $queue );
			if ( isset( $seen[ $table ] ) ) {
				continue;
			}
			$seen[ $table ] = true;
			foreach ( array_keys( $references[ $table ] ?? array() ) as $next ) {
				$queue[] = $next;
			}
		}
		return $seen;
	}
}
