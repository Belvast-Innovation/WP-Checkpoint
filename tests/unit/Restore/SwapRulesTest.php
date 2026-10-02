<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Restore\SwapRules;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The swap's rules on names alone: batches that never split an entry and move the live tables aside first, the
 * commit only when every entry is swapped, and a rollback from whatever was there that ends with the site's
 * tables where they were, whatever was cut where.
 */
final class SwapRulesTest extends TestCase {

	private static function table( int $seq, string $name, bool $had_live ): array {
		return array(
			'seq'      => $seq,
			'kind'     => SwapPlan::TABLE_OF,
			'live'     => 'wp_' . $name,
			'stage'    => 'wcptmpa1b2c3_7_beef_' . $name,
			'old'      => 'wcpolda1b2c3_7_beef_' . $name,
			'had_live' => $had_live,
		);
	}

	private static function move( int $seq, string $name ): array {
		return array(
			'seq'      => $seq,
			'kind'     => SwapPlan::MOVE,
			'live'     => 'wp_' . $name,
			'stage'    => '',
			'old'      => 'wcpolda1b2c3_7_beef_' . $name,
			'had_live' => true,
		);
	}

	public function test_the_batch_limit_is_half_the_packet_within_bounds(): void {
		$this->assertSame( 4096, SwapRules::limit( 1024 ) );
		$this->assertSame( 32768, SwapRules::limit( 65536 ) );
		$this->assertSame( 1048576, SwapRules::limit( 67108864 ) );
	}

	public function test_one_batch_moves_the_live_tables_aside_before_the_restored_ones_come_in(): void {
		$entries = array( self::table( 0, 'options', true ), self::table( 1, 'shop', false ), self::move( 2, 'old_plugin' ) );
		$batches = SwapRules::batches( $entries, 4096 );
		$this->assertCount( 1, $batches );
		$this->assertSame( array( 0, 1, 2 ), $batches[0]['seqs'] );
		$this->assertSame(
			'RENAME TABLE `wp_options` TO `wcpolda1b2c3_7_beef_options`, `wp_old_plugin` TO `wcpolda1b2c3_7_beef_old_plugin`, `wcptmpa1b2c3_7_beef_options` TO `wp_options`, `wcptmpa1b2c3_7_beef_shop` TO `wp_shop`',
			$batches[0]['sql']
		);
	}

	public function test_batches_stay_within_the_limit_and_never_split_an_entry(): void {
		$entries = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$entries[] = 0 === $i % 3 ? self::move( $i, 't' . $i ) : self::table( $i, 'table_' . $i, 1 === $i % 2 );
		}
		$limit   = 1000;
		$batches = SwapRules::batches( $entries, $limit );
		$this->assertGreaterThan( 5, count( $batches ), 'the control: the limit made several batches' );
		$seen = array();
		foreach ( $batches as $batch ) {
			$this->assertLessThanOrEqual( $limit, strlen( $batch['sql'] ) );
			foreach ( $batch['seqs'] as $seq ) {
				$entry = $entries[ $seq ];
				// Every rename of the entry is in this batch's statement.
				if ( SwapPlan::MOVE === $entry['kind'] || $entry['had_live'] ) {
					$this->assertStringContainsString( '`' . $entry['live'] . '` TO `' . $entry['old'] . '`', $batch['sql'] );
				}
				if ( SwapPlan::TABLE_OF === $entry['kind'] ) {
					$this->assertStringContainsString( '`' . $entry['stage'] . '` TO `' . $entry['live'] . '`', $batch['sql'] );
				}
				$seen[] = $seq;
			}
		}
		$this->assertSame( range( 0, 299 ), $seen, 'every entry once, in plan order' );
	}

	public function test_the_swap_is_made_only_when_every_entry_is_swapped(): void {
		$entries = array( self::table( 0, 'options', true ), self::table( 1, 'shop', false ), self::move( 2, 'old_plugin' ) );
		$before  = self::there( array( 'wp_options', 'wcptmpa1b2c3_7_beef_options', 'wcptmpa1b2c3_7_beef_shop', 'wp_old_plugin' ) );
		$after   = self::there( array( 'wp_options', 'wcpolda1b2c3_7_beef_options', 'wp_shop', 'wcpolda1b2c3_7_beef_old_plugin' ) );
		foreach ( $entries as $entry ) {
			$this->assertSame( SwapRules::BEFORE, SwapRules::table_state( $entry, $before ) );
			$this->assertSame( SwapRules::AFTER, SwapRules::table_state( $entry, $after ) );
		}
		$this->assertFalse( SwapRules::committed( $entries, $before ) );
		$this->assertTrue( SwapRules::committed( $entries, $after ) );
		$part = $after;
		unset( $part['wcpolda1b2c3_7_beef_old_plugin'] );
		$part['wp_old_plugin'] = true;
		$this->assertFalse( SwapRules::committed( $entries, $part ), 'one entry not swapped' );
	}

	/**
	 * Every cut of a batched swap (any prefix of the batches done, the next one done or not, as a server that
	 * renames table by table may leave it) rolls back to the site's names with the rules alone, run in reverse
	 * plan order; and running the rules again changes nothing.
	 */
	public function test_every_cut_of_a_batched_swap_rolls_back_to_the_site_as_it_was(): void {
		mt_srand( 4242 );
		$cuts = 0;
		for ( $run = 0; $run < 300; $run++ ) {
			$entries = array();
			$count   = mt_rand( 1, 12 );
			for ( $i = 0; $i < $count; $i++ ) {
				$entries[] = 0 === mt_rand( 0, 3 ) ? self::move( $i, 'm' . $i ) : self::table( $i, 't' . $i, 0 !== mt_rand( 0, 2 ) );
			}
			$site = array();
			foreach ( $entries as $entry ) {
				if ( SwapPlan::TABLE_OF === $entry['kind'] ) {
					$site[ $entry['stage'] ] = true;
				}
				if ( $entry['had_live'] ) {
					$site[ $entry['live'] ] = true;
				}
			}
			$original = $site;
			// Apply the renames of the batches one by one and cut after a random number of single renames.
			$renames = array();
			foreach ( SwapRules::batches( $entries, mt_rand( 60, 400 ) ) as $batch ) {
				preg_match_all( '/`([^`]+)` TO `([^`]+)`/', $batch['sql'], $m, PREG_SET_ORDER );
				foreach ( $m as $pair ) {
					$renames[] = array( $pair[1], $pair[2] );
				}
			}
			$cut = mt_rand( 0, count( $renames ) );
			for ( $i = 0; $i < $cut; $i++ ) {
				$this->assertArrayHasKey( $renames[ $i ][0], $site );
				$this->assertArrayNotHasKey( $renames[ $i ][1], $site, 'a rename never lands on a name in use' );
				unset( $site[ $renames[ $i ][0] ] );
				$site[ $renames[ $i ][1] ] = true;
			}
			if ( count( $renames ) === $cut ) {
				$this->assertTrue( SwapRules::committed( $entries, $site ), 'all done: made' );
				continue;
			}
			++$cuts;
			$this->assertFalse( SwapRules::committed( $entries, $site ) );
			for ( $pass = 0; $pass < 2; $pass++ ) {
				foreach ( array_reverse( $entries ) as $entry ) {
					foreach ( SwapRules::table_back( $entry, $site, 'wcpstray_x_' . $entry['seq'] ) as $pair ) {
						$this->assertArrayHasKey( $pair[0], $site );
						$this->assertArrayNotHasKey( $pair[1], $site );
						unset( $site[ $pair[0] ] );
						$site[ $pair[1] ] = true;
					}
				}
				ksort( $site );
				ksort( $original );
				$this->assertSame( $original, $site, 'pass ' . $pass . ' of run ' . $run );
			}
		}
		$this->assertGreaterThan( 200, $cuts, 'the control: most runs were cut before the end' );
	}

	public function test_a_table_made_under_a_name_the_old_one_returns_to_is_moved_out_of_the_way(): void {
		$entry = self::table( 0, 'options', true );
		// Swapped, then rolled back halfway: the restored one went back to T, someone made a new wp_options.
		$there = self::there( array( 'wcptmpa1b2c3_7_beef_options', 'wcpolda1b2c3_7_beef_options', 'wp_options' ) );
		$this->assertSame( array( array( 'wp_options', 'stray' ), array( 'wcpolda1b2c3_7_beef_options', 'wp_options' ) ), SwapRules::table_back( $entry, $there, 'stray' ) );
		$move = self::move( 1, 'x' );
		$this->assertSame( array( array( 'wp_x', 'stray' ), array( 'wcpolda1b2c3_7_beef_x', 'wp_x' ) ), SwapRules::table_back( $move, self::there( array( 'wp_x', 'wcpolda1b2c3_7_beef_x' ) ), 'stray' ) );
		$this->assertSame( array(), SwapRules::table_back( $move, self::there( array( 'wp_x' ) ), 'stray' ), 'never moved: nothing' );
	}

	public function test_a_directory_unit_is_put_back_from_what_is_there(): void {
		$had = array(
			'live'     => '/s/uploads',
			'stage'    => '/s/root/uploads',
			'old'      => '/s/root/old/uploads',
			'had_live' => true,
		);
		$new = array( 'had_live' => false ) + $had;
		// Moved aside, staged copy not yet in: the old one comes back.
		$this->assertSame( array( array( '/s/root/old/uploads', '/s/uploads' ) ), SwapRules::dir_back( $had, false, true, true, 'X' ) );
		// Both done: the staged copy goes back to S, the old one to L.
		$this->assertSame( array( array( '/s/uploads', '/s/root/uploads' ), array( '/s/root/old/uploads', '/s/uploads' ) ), SwapRules::dir_back( $had, true, false, true, 'X' ) );
		// Someone made L again while S is still there: L goes out of the way.
		$this->assertSame( array( array( '/s/uploads', 'X' ), array( '/s/root/old/uploads', '/s/uploads' ) ), SwapRules::dir_back( $had, true, true, true, 'X' ) );
		// Not begun: nothing.
		$this->assertSame( array(), SwapRules::dir_back( $had, true, true, false, 'X' ) );
		// No live one: the staged copy goes back; not begun or already back, nothing.
		$this->assertSame( array( array( '/s/uploads', '/s/root/uploads' ) ), SwapRules::dir_back( $new, true, false, false, 'X' ) );
		$this->assertSame( array(), SwapRules::dir_back( $new, false, true, false, 'X' ) );
		$this->assertSame( array(), SwapRules::dir_back( $new, true, true, false, 'X' ), 'L someone else\'s and S still staged: left alone' );
	}

	private static function there( array $names ): array {
		return array_fill_keys( $names, true );
	}
}
