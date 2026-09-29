<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Restore\TableMoves;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which live tables the backup does not have the swap moves away, and which it leaves and reports.
 */
final class TableMovesTest extends TestCase {

	const TOKEN = 'a1b2c3d4e5f6';

	/**
	 * WordPress's own table names read without a prefix (a single site).
	 */
	const CORE = array( 'posts', 'comments', 'links', 'options', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'commentmeta', 'users', 'usermeta' );

	/**
	 * The plugin's tables and the restore's temporary and old ones, with the base prefix.
	 *
	 * @return string[]
	 */
	private static function plugins_own( string $base ): array {
		return array(
			$base . 'wpcheckpoint_jobs',
			$base . 'wpcheckpoint_swap_plan',
			TempTables::ledger( self::TOKEN, 12, 'beef' ),
			TempTables::name( self::TOKEN, 12, 'beef', 'posts' ),
			TempTables::old( self::TOKEN, 12, 'beef', 'users' ),
			TempTables::name( 'ffffffffffff', 7, 'cafe', 'options' ), // Another installation's restore.
		);
	}

	public function test_with_an_empty_prefix_only_wordpress_tables_are_moved_and_the_others_reported(): void {
		$live = array_merge(
			self::CORE,
			array( 'app_sessions', 'wp_posts', 'shop_orders' ),
			self::plugins_own( '' ),
			array( 'excluded_log' )
		);
		$out  = TableMoves::select(
			'',
			'',
			$live,
			array( 'posts', 'options', 'users' ), // The backup's.
			array( 'comments', 'excluded_log' ), // Left out of the restore.
			self::CORE
		);
		$this->assertSame( array( 'links', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'commentmeta', 'usermeta' ), $out['move'] );
		$this->assertSame( array( 'app_sessions', 'wp_posts', 'shop_orders' ), $out['report'] );
		foreach ( array_merge( self::plugins_own( '' ), array( 'posts', 'options', 'users', 'comments', 'excluded_log' ) ) as $name ) {
			$this->assertNotContains( $name, $out['move'], $name );
			$this->assertNotContains( $name, $out['report'], $name );
		}
	}

	public function test_with_a_prefix_the_tables_that_start_with_it_are_moved_and_nothing_is_reported(): void {
		$live = array_merge(
			array( 'wp_posts', 'wp_options', 'wp_links', 'wp_2_posts', 'wp_shop_orders', 'wp_excluded_log', 'other_posts', 'app_sessions' ),
			self::plugins_own( 'wp_' )
		);
		$out  = TableMoves::select( 'wp_', 'wp_', $live, array( 'wp_posts', 'wp_options' ), array( 'wp_excluded_log' ), array() );
		$this->assertSame( array( 'wp_links', 'wp_2_posts', 'wp_shop_orders' ), $out['move'] );
		$this->assertSame( array(), $out['report'] );
	}

	public function test_the_plugins_own_tables_are_never_touched(): void {
		foreach ( array_merge( self::plugins_own( 'wp_' ), array( 'wpcheckpoint_jobs', 'wp_old_wpcheckpoint_swap_plan' ) ) as $name ) {
			$this->assertTrue( TableMoves::never( $name ), $name . ': this installation\'s, or a neighbour\'s' );
		}
		foreach ( array( 'wp_posts', 'wp_wpcheckpoint_jobs_old', 'wcp_posts', 'wcptmp_notes' ) as $name ) {
			$this->assertFalse( TableMoves::never( $name ), $name );
		}
	}
}
