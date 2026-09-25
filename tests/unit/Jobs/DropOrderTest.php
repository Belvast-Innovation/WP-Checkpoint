<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\DropOrder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The order in which tables with foreign keys among them are dropped: what
 * no remaining table references first, with checks on; cycles in one
 * statement with checks off, only when nothing outside them references
 * them; what a table outside the set references is kept.
 */
final class DropOrderTest extends TestCase {

	/**
	 * @param array<int, array{0: string, 1: string}> $keys child => parent pairs.
	 */
	private static function plan( array $tables, array $keys, bool $fold = false ): DropOrder {
		return DropOrder::plan(
			$tables,
			array_map(
				static function ( array $key ): array {
					return array(
						'table'      => $key[0],
						'referenced' => $key[1],
					);
				},
				$keys
			),
			$fold
		);
	}

	/**
	 * Steps as "a,b" (checks on) or "!a,b" (checks off).
	 *
	 * @return string[]
	 */
	private static function steps( DropOrder $plan ): array {
		return array_map(
			static function ( array $step ): string {
				return ( $step['checks_off'] ? '!' : '' ) . implode( ',', $step['tables'] );
			},
			$plan->steps()
		);
	}

	public function test_without_keys_every_table_goes_alone_with_checks_on(): void {
		$this->assertSame( array( 'a', 'b' ), self::steps( self::plan( array( 'b', 'a' ), array() ) ) );
	}

	public function test_a_child_goes_before_its_parent_whatever_the_names(): void {
		$this->assertSame( array( 'z_child', 'a_parent' ), self::steps( self::plan( array( 'a_parent', 'z_child' ), array( array( 'z_child', 'a_parent' ) ) ) ) );
		$this->assertSame( array( 'c', 'b', 'a' ), self::steps( self::plan( array( 'a', 'b', 'c' ), array( array( 'c', 'b' ), array( 'b', 'a' ) ) ) ), 'a chain, from its end' );
	}

	public function test_a_key_to_itself_does_not_hold_a_table_back(): void {
		$this->assertSame( array( 's' ), self::steps( self::plan( array( 's' ), array( array( 's', 's' ) ) ) ) );
	}

	public function test_a_cycle_goes_in_one_statement_with_checks_off_and_what_it_references_after(): void {
		$plan = self::plan( array( 'a', 'b', 'p', 'x' ), array( array( 'a', 'b' ), array( 'b', 'a' ), array( 'b', 'p' ), array( 'x', 'a' ) ) );
		$this->assertSame( array( 'x', '!a,b', 'p' ), self::steps( $plan ), 'x references the cycle: first; p is referenced by it: after' );
		$this->assertSame( array(), $plan->kept() );
	}

	public function test_a_table_referenced_from_outside_is_kept_with_what_it_references(): void {
		$plan = self::plan(
			array( 'p', 'q', 'r', 'free' ),
			array( array( 'live_ext', 'p' ), array( 'p', 'q' ), array( 'r', 'p' ) )
		);
		$this->assertSame( array( 'free', 'r' ), self::steps( $plan ), 'the control: the rest goes, r (a child of p) too' );
		$this->assertSame(
			array(
				'p' => array( 'live_ext' ),
				'q' => array( 'p' ),
			),
			$plan->kept()
		);
	}

	public function test_a_cycle_referenced_from_outside_is_kept_not_dropped_with_checks_off(): void {
		$plan = self::plan( array( 'a', 'b' ), array( array( 'a', 'b' ), array( 'b', 'a' ), array( 'other_db.t', 'a' ) ) );
		$this->assertSame( array(), self::steps( $plan ) );
		$this->assertSame( array( 'a', 'b' ), array_keys( $plan->kept() ) );
	}

	public function test_names_are_compared_without_case_where_the_server_ignores_it(): void {
		$keys = array( array( 'Z_CHILD', 'a_parent' ) );
		$this->assertSame( array( 'z_child', 'a_parent' ), self::steps( self::plan( array( 'a_parent', 'z_child' ), $keys, true ) ) );
		$apart = self::plan( array( 'a_parent', 'z_child' ), $keys, false );
		$this->assertSame( array( 'z_child' ), self::steps( $apart ), 'the control: a server that tells case apart sees another table, outside the set' );
		$this->assertSame( array( 'a_parent' => array( 'Z_CHILD' ) ), $apart->kept(), 'which keeps the parent' );
	}

	/**
	 * Random sets of tables and keys (fixed seeds): every table is dropped once or kept; a table dropped with
	 * checks on is referenced by no table still there; a group dropped with checks off only by its own members;
	 * what a kept table references is kept.
	 */
	public function test_every_plan_on_random_keys_keeps_the_rules(): void {
		for ( $seed = 1; $seed <= 400; $seed++ ) {
			mt_srand( $seed );
			$count  = mt_rand( 1, 12 );
			$tables = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$tables[] = 't' . $i;
			}
			$keys = array();
			for ( $k = mt_rand( 0, 20 ); $k > 0; $k-- ) {
				$child  = mt_rand( 0, 9 ) < 8 ? $tables[ mt_rand( 0, $count - 1 ) ] : 'outside' . mt_rand( 0, 2 );
				$keys[] = array( $child, $tables[ mt_rand( 0, $count - 1 ) ] );
			}
			$plan = self::plan( $tables, $keys );
			$seen = array();
			foreach ( $plan->steps() as $step ) {
				foreach ( $step['tables'] as $table ) {
					$seen[] = $table;
				}
			}
			$kept = array_keys( $plan->kept() );
			$all  = array_merge( $seen, $kept );
			sort( $all );
			$this->assertSame( $tables === array() ? array() : self::sorted( $tables ), $all, "seed {$seed}: every table once" );

			$there = array_flip( array_merge( $tables, array( 'outside0', 'outside1', 'outside2' ) ) );
			foreach ( $plan->steps() as $step ) {
				$group = array_flip( $step['tables'] );
				foreach ( $keys as list( $child, $parent ) ) {
					if ( isset( $group[ $parent ] ) && $child !== $parent && isset( $there[ $child ] ) ) {
						$this->assertTrue( $step['checks_off'] && isset( $group[ $child ] ), "seed {$seed}: {$parent} dropped while {$child} references it" );
					}
				}
				foreach ( $step['tables'] as $table ) {
					unset( $there[ $table ] );
				}
			}
			foreach ( $keys as list( $child, $parent ) ) {
				if ( isset( $plan->kept()[ $child ] ) ) {
					$this->assertArrayHasKey( $parent, $plan->kept(), "seed {$seed}: {$parent} is referenced by the kept {$child}" );
				}
			}
		}
	}

	/**
	 * A shape that made an earlier planner slow (a chain of cycles): planned well inside a step.
	 */
	public function test_a_chain_of_cycles_is_planned_quickly(): void {
		$tables = array();
		$keys   = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$tables[] = 'a' . $i;
			$tables[] = 'b' . $i;
			$keys[]   = array( 'a' . $i, 'b' . $i );
			$keys[]   = array( 'b' . $i, 'a' . $i );
			if ( $i > 0 ) {
				$keys[] = array( 'a' . $i, 'a' . ( $i - 1 ) );
			}
		}
		$started = microtime( true );
		$plan    = self::plan( $tables, $keys );
		$this->assertLessThan( 2.0, microtime( true ) - $started, '4000 tables' );
		$this->assertCount( 2000, $plan->steps(), 'one statement per cycle' );
		$this->assertSame( array( 'a1999', 'b1999' ), $plan->steps()[0]['tables'], 'the end of the chain first' );
	}

	/**
	 * @return string[]
	 */
	private static function sorted( array $names ): array {
		sort( $names );
		return $names;
	}
}
