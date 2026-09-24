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
		$plan = self::plan( array( 'wp_options', 'wp_posts', 'wp_wpcheckpoint_jobs', 'wcptmpabcdef_3_0000_posts', 'wp_skip' ), 'wp_', 'site2_', array( 'wp_skip' ) );
		$this->assertSame(
			array(
				array( 'wp_options', 'site2_options', 0 ),
				array( 'wp_posts', 'site2_posts', 1 ),
			),
			array_map(
				static function ( array $t ): array {
					return array( $t['table'], $t['final'], $t['number'] );
				},
				$plan->tables()
			)
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

	public function test_names_that_differ_in_case_only_clash_where_the_server_ignores_case(): void {
		// With every table under the backup's prefix, two tables share a final name only when case does not count.
		$this->assertCount( 3, self::plan( array( 'wp_options', 'wp_Posts', 'wp_posts' ), 'wp_', 'x_' )->tables(), 'the control: a server that tells them apart' );
		$this->expectException( Refused::class );
		$this->expectExceptionMessage( 'The tables wp_Posts and wp_posts would both be named x_posts on this site' );
		self::plan( array( 'wp_options', 'wp_Posts', 'wp_posts' ), 'wp_', 'x_', array(), true );
	}

	public function test_a_table_of_another_installation_is_refused_with_its_ways_out(): void {
		$this->assertCount( 1, self::plan( array( 'wp_options', 'other_x' ), 'wp_', 'wp_', array( 'other_x' ) )->tables(), 'the control: left out, the rest goes through' );
		try {
			self::plan( array( 'wp_options', 'other_x' ) );
			$this->fail( 'refused' );
		} catch ( Refused $e ) {
			$message = $e->getMessage();
			$this->assertStringContainsString( 'The table other_x of the backup does not carry the backup\'s table prefix (wp_)', $message );
			$this->assertStringContainsString( 'this database may hold another site\'s table', $message, 'why' );
			$this->assertStringContainsString( 'Leave the table out of the restore', $message );
			$this->assertStringContainsString( 'restore it by hand from the backup\'s SQL', $message );
		}
	}

	public function test_two_tables_with_one_temporary_name_are_refused(): void {
		// "wp_a-b" needs cleaning, so its temporary name ends in "a_b_" and a hash of "a-b"; a table can be named that.
		$twin = 'wp_a_b_' . substr( hash( 'sha256', 'a-b' ), 0, 7 );
		$this->assertCount( 2, self::plan( array( 'wp_options', 'wp_a-b' ) )->tables(), 'the control: one of them' );
		$this->assertSame( self::plan( array( 'wp_options', 'wp_a-b' ) )->find( 'wp_a-b' )['temporary'], self::plan( array( 'wp_options', $twin ) )->find( $twin )['temporary'], 'the two names meet' );
		$this->expectException( Refused::class );
		$this->expectExceptionMessage( 'would share a temporary name' );
		self::plan( array( 'wp_options', 'wp_a-b', $twin ) );
	}

	public function test_the_jobs_table_is_left_out_in_any_case_where_the_server_ignores_case(): void {
		$this->assertSame( array( 'wp_WPCheckpoint_Jobs' ), array_keys( array_diff_key( array_flip( array_column( self::plan( array( 'wp_options', 'wp_WPCheckpoint_Jobs' ) )->tables(), 'table' ) ), array( 'wp_options' => 0 ) ) ), 'the control: a server that tells case apart keeps it' );
		$plan = self::plan( array( 'wp_options', 'wp_WPCheckpoint_Jobs' ), 'wp_', 'wp_', array(), true );
		$this->assertSame( array( 'wp_WPCheckpoint_Jobs' => 'jobs' ), $plan->skipped() );
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
