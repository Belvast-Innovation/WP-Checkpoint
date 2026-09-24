<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\PluginList;
use WPCheckpoint\Restore\Refused;
use WPCheckpoint\Restore\TablePlan;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which tables a restore creates under which names, and the strict reader
 * of the lists of active plugins.
 */
final class TablePlanTest extends TestCase {

	const TOKEN = 'abcdef012345';

	/**
	 * @param string[] $names Table names.
	 * @return array<int, array{name: string, chunks: int}>
	 */
	private static function tables( array $names ): array {
		return array_map(
			static function ( string $name ): array {
				return array(
					'name'   => $name,
					'chunks' => 1,
				);
			},
			$names
		);
	}

	private static function plan( array $names, string $backup = 'wp_', string $site = 'wp_', array $excluded = array(), bool $fold = false, bool $multisite = false ): TablePlan {
		return TablePlan::make( self::tables( $names ), $backup, $site, $multisite, $excluded, self::TOKEN, 7, '1a2b', $fold );
	}

	public function test_names_on_this_site_and_what_is_left_out(): void {
		$plan = self::plan( array( 'wp_options', 'wp_posts', 'other_x', 'wp_wpcheckpoint_jobs', 'wcptmpabcdef_3_0000_posts', 'wp_skip' ), 'wp_', 'site2_', array( 'wp_skip' ) );
		$this->assertSame(
			array(
				array( 'wp_options', 'site2_options', 0 ),
				array( 'wp_posts', 'site2_posts', 1 ),
				array( 'other_x', 'other_x', 2 ),
			),
			array_map(
				static function ( array $t ): array {
					return array( $t['table'], $t['final'], $t['number'] );
				},
				$plan->tables()
			),
			'another installation\'s table keeps its name'
		);
		$this->assertSame( 'wcptmpabcdef_7_1a2b_posts', $plan->find( 'wp_posts' )['temporary'] );
		$this->assertSame(
			array(
				'wp_wpcheckpoint_jobs'      => 'jobs',
				'wcptmpabcdef_3_0000_posts' => 'temporary',
				'wp_skip'                   => 'excluded',
			),
			$plan->skipped()
		);
		$this->assertSame( 'wcptmpabcdef_7_1a2b_posts', $plan->reference( 'wp_posts' ), 'a restored table: its temporary name' );
		$this->assertSame( 'site2_users', $plan->reference( 'wp_users' ), 'any other: its name here' );
		$this->assertEquals( $plan, TablePlan::from_array( $plan->to_array() ) );
	}

	public function test_a_final_name_too_long_is_refused_with_leaving_it_out_first(): void {
		$long = 'wp_' . str_repeat( 'x', 60 );
		$this->assertCount( 2, self::plan( array( 'wp_options', $long ) )->tables(), 'the control: 63 bytes fit' );
		try {
			self::plan( array( 'wp_options', $long ), 'wp_', 'wp_site_' );
			$this->fail( 'refused' );
		} catch ( Refused $e ) {
			$message = $e->getMessage();
			$this->assertStringContainsString( 'wp_site_' . str_repeat( 'x', 60 ) . ' on this site (68 bytes)', $message );
			$this->assertLessThan( strpos( $message, 'table prefix' ), strpos( $message, 'Leave this table out of the restore' ), 'leaving it out comes first' );
			$this->assertStringContainsString( 'Only when restoring into a new, empty site', $message );
			$this->assertStringContainsString( 'makes it lose track of its own data', $message );
		}
	}

	public function test_two_tables_with_one_final_name_are_refused(): void {
		$this->expectException( Refused::class );
		$this->expectExceptionMessage( 'would both be named b_posts' );
		self::plan( array( 'a_options', 'a_posts', 'b_posts' ), 'a_', 'b_' );
	}

	public function test_names_that_differ_in_case_only_clash_where_the_server_ignores_case(): void {
		$this->assertCount( 3, self::plan( array( 'wp_options', 'wp_Posts', 'wp_posts' ) )->tables(), 'the control: a server that tells them apart' );
		$this->expectException( Refused::class );
		self::plan( array( 'wp_options', 'wp_Posts', 'wp_posts' ), 'wp_', 'wp_', array(), true );
	}

	public function test_the_options_table_and_on_a_network_the_sitemeta_table_are_required(): void {
		$this->assertCount( 2, self::plan( array( 'wp_options', 'wp_sitemeta' ), 'wp_', 'wp_', array(), false, true )->tables() );
		foreach ( array( array( array( 'wp_posts' ), false, 'wp_options' ), array( array( 'wp_options' ), true, 'wp_sitemeta' ) ) as list( $names, $multisite, $missing ) ) {
			try {
				self::plan( $names, 'wp_', 'wp_', array(), false, $multisite );
				$this->fail( 'refused' );
			} catch ( Refused $e ) {
				$this->assertStringContainsString( 'no table ' . $missing, $e->getMessage() );
			}
		}
		$this->expectException( Refused::class );
		self::plan( array( 'wp_options', 'wp_posts' ), 'wp_', 'wp_', array( 'wp_options' ) );
	}

	public function test_lists_of_active_plugins_are_read_exactly_or_not_at_all(): void {
		$this->assertSame( array( 'a/a.php', 'b/b.php' ), PluginList::read( serialize( array( 'a/a.php', 'b/b.php' ) ) ) );
		$this->assertSame( array( 'wp-checkpoint/wp-checkpoint.php' => 1700000000 ), PluginList::read( serialize( array( 'wp-checkpoint/wp-checkpoint.php' => 1700000000 ) ) ) );
		$this->assertSame( array(), PluginList::read( 'a:0:{}' ) );
		$this->assertSame( array( 'ü/ü.php' ), PluginList::read( PluginList::write( array( 'ü/ü.php' ) ) ), 'bytes, not characters' );
		foreach ( array(
			'not serialized'     => 'a/a.php',
			'a string'           => serialize( 'a/a.php' ),
			'a wrong length'     => 'a:1:{i:0;s:8:"a/a.php";}',
			'a wrong count'      => 'a:2:{i:0;s:7:"a/a.php";}',
			'something after'    => serialize( array( 'a/a.php' ) ) . 'x',
			'nested'             => serialize( array( array( 'a/a.php' ) ) ),
			'an object'          => 'a:1:{i:0;O:8:"stdClass":0:{}}',
			'cut short'          => substr( serialize( array( 'a/a.php' ) ), 0, -3 ),
			'a leading zero key' => 'a:1:{i:01;s:7:"a/a.php";}',
		) as $label => $value ) {
			$this->assertNull( PluginList::read( $value ), $label );
		}
	}
}
