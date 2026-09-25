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
 * A cycle (a references b, b references a) never becomes free that way.
 * No order drops it with checks on on MariaDB or MySQL 5.7; with checks
 * off, one statement drops it on every server. The plan drops a cycle in
 * one statement with checks off once no remaining table outside it
 * references it, and only when no table outside the set references it at
 * all (then no key is left pointing at a dropped table).
 *
 * A table that a table outside the set references is kept, and so is
 * every table a kept table references: dropping it would fail with checks
 * on, and with checks off would leave the other table's key pointing at
 * nothing. The caller reports them.
 *
 * The work is linear in the tables and keys (strongly connected groups by
 * Tarjan's algorithm, then the groups in rounds), so a reclaim unit stays
 * bounded whatever the keys look like.
 */
final class DropOrder {

	/**
	 * The steps: each is a list of tables dropped in one statement, with checks off when "checks_off".
	 *
	 * @var array<int, array{tables: string[], checks_off: bool}>
	 */
	private $steps = array();

	/**
	 * Tables kept: name => the tables that reference it and stay (outside the set, or kept themselves).
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
		$references = array(); // Child => parents, both in the set.
		$outside    = array(); // Parent in the set => referencing tables that stay.
		foreach ( $keys as $row ) {
			$child  = $key( $row['table'] );
			$parent = $key( $row['referenced'] );
			if ( ! isset( $names[ $parent ] ) || $child === $parent ) {
				continue;
			}
			if ( isset( $names[ $child ] ) ) {
				$references[ $child ][ $parent ] = true;
			} else {
				$outside[ $parent ][ $row['table'] ] = true;
			}
		}

		// Kept: referenced from outside, and whatever a kept table references.
		$kept  = array();
		$queue = array_keys( $outside );
		for ( $i = 0; isset( $queue[ $i ] ); $i++ ) { // The queue grows while it is read.
			$table = (string) $queue[ $i ];
			if ( isset( $kept[ $table ] ) ) {
				continue;
			}
			$kept[ $table ] = true;
			foreach ( array_keys( $references[ $table ] ?? array() ) as $parent ) {
				$outside[ $parent ][ $names[ $table ] ] = true;
				$queue[]                                = $parent;
			}
		}
		foreach ( array_keys( $kept ) as $table ) {
			$referrers = array_keys( $outside[ $table ] );
			sort( $referrers, SORT_STRING );
			$plan->kept[ $names[ $table ] ] = $referrers;
		}
		ksort( $plan->kept );

		// The rest: strongly connected groups, dropped in rounds once no remaining group references them.
		$left = array_diff_key( $names, $kept );
		$adj  = array();
		foreach ( array_keys( $left ) as $table ) {
			$adj[ $table ] = array_keys( array_intersect_key( $references[ $table ] ?? array(), $left ) );
		}
		$groups   = self::components( array_keys( $left ), $adj );
		$group_of = array();
		foreach ( $groups as $g => $members ) {
			foreach ( $members as $table ) {
				$group_of[ $table ] = $g;
			}
		}
		$referenced_by = array(); // Group => groups that reference it.
		$parents_of    = array(); // Group => groups it references.
		foreach ( $adj as $child => $parents ) {
			foreach ( $parents as $parent ) {
				$from = $group_of[ $child ];
				$to   = $group_of[ $parent ];
				if ( $from !== $to ) {
					$referenced_by[ $to ][ $from ] = true;
					$parents_of[ $from ][ $to ]    = true;
				}
			}
		}
		$ready = array();
		foreach ( array_keys( $groups ) as $g ) {
			if ( empty( $referenced_by[ $g ] ) ) {
				$ready[] = $g;
			}
		}
		while ( array() !== $ready ) {
			$round = array();
			foreach ( $ready as $g ) {
				$members = array();
				foreach ( $groups[ $g ] as $table ) {
					$members[] = $left[ $table ];
				}
				sort( $members, SORT_STRING );
				$round[ $members[0] ] = array( $g, $members );
			}
			ksort( $round, SORT_STRING );
			$next = array();
			foreach ( $round as list( $g, $members ) ) {
				$plan->steps[] = array(
					'tables'     => $members,
					'checks_off' => count( $members ) > 1,
				);
				foreach ( array_keys( $parents_of[ $g ] ?? array() ) as $parent ) {
					unset( $referenced_by[ $parent ][ $g ] );
					if ( empty( $referenced_by[ $parent ] ) ) {
						$next[] = $parent;
					}
				}
			}
			$ready = $next;
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
	 * Tables kept, name => the tables that reference it and stay.
	 *
	 * @return array<string, string[]>
	 */
	public function kept(): array {
		return $this->kept;
	}

	/**
	 * Strongly connected groups (Tarjan's algorithm, without recursion).
	 *
	 * @param string[]                          $nodes Nodes.
	 * @param array<string, array<int, string>> $adj   Node => nodes it points to.
	 * @return array<int, string[]>
	 */
	private static function components( array $nodes, array $adj ): array {
		$counter = 0;
		$index   = array();
		$low     = array();
		$on      = array();
		$stack   = array();
		$out     = array();
		foreach ( $nodes as $start ) {
			if ( isset( $index[ $start ] ) ) {
				continue;
			}
			$index[ $start ] = $counter;
			$low[ $start ]   = $counter;
			++$counter;
			$stack[]      = $start;
			$on[ $start ] = true;
			$work         = array( array( $start, 0 ) );
			while ( array() !== $work ) {
				$top              = count( $work ) - 1;
				list( $node, $i ) = $work[ $top ];
				$next             = $adj[ $node ] ?? array();
				if ( $i < count( $next ) ) {
					$work[ $top ][1] = $i + 1;
					$w               = $next[ $i ];
					if ( ! isset( $index[ $w ] ) ) {
						$index[ $w ] = $counter;
						$low[ $w ]   = $counter;
						++$counter;
						$stack[]  = $w;
						$on[ $w ] = true;
						$work[]   = array( $w, 0 );
					} elseif ( ! empty( $on[ $w ] ) ) {
						$low[ $node ] = min( $low[ $node ], $index[ $w ] );
					}
					continue;
				}
				array_pop( $work );
				if ( array() !== $work ) {
					$up         = $work[ count( $work ) - 1 ][0];
					$low[ $up ] = min( $low[ $up ], $low[ $node ] );
				}
				if ( $low[ $node ] === $index[ $node ] ) {
					$group = array();
					do {
						$w        = (string) array_pop( $stack );
						$on[ $w ] = false;
						$group[]  = $w;
					} while ( $w !== $node );
					$out[] = $group;
				}
			}
		}
		return $out;
	}
}
