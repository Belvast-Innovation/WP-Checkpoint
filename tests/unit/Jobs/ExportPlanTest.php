<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Archive\Packer;
use WPCheckpoint\Jobs\ExportPlan;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ExportPlanTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-plan-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700, true );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
	}

	public function test_files_are_written_whole_and_read_back(): void {
		$this->assertFalse( ExportPlan::exists( $this->dir, ExportPlan::PLAN ) );
		ExportPlan::write(
			$this->dir,
			ExportPlan::PLAN,
			array(
				'tables' => array( 'wp_posts' ),
				'base'   => 'site-20260921-100000-ab12',
			)
		);
		$this->assertTrue( ExportPlan::exists( $this->dir, ExportPlan::PLAN ) );
		$this->assertSame( array( 'wp_posts' ), ExportPlan::read( $this->dir, ExportPlan::PLAN )['tables'] );
		$this->assertFileDoesNotExist( $this->dir . '/plan.json.tmp', 'the temporary file was renamed over the target' );
		ExportPlan::write( $this->dir, ExportPlan::PLAN, array( 'tables' => array() ) );
		$this->assertSame( array( 'tables' => array() ), ExportPlan::read( $this->dir, ExportPlan::PLAN ), 'replaced whole, not merged' );
	}

	public function test_a_missing_or_damaged_file_fails_closed(): void {
		try {
			ExportPlan::read( $this->dir, ExportPlan::REVIEW );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'review.json', $e->getMessage() );
		}
		file_put_contents( $this->dir . '/review.json', '{"findings": ' );
		try {
			ExportPlan::read( $this->dir, ExportPlan::REVIEW );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'cannot be read', $e->getMessage() );
		}
		file_put_contents( $this->dir . '/review.json', '[1, 2]' );
		$this->assertSame( array( 1, 2 ), ExportPlan::read( $this->dir, ExportPlan::REVIEW ), 'a JSON array is an array; callers check the keys they need' );
	}

	public function test_the_whole_archive_and_the_exported_database_must_fit(): void {
		// 1 GB of files in 1000 files, 100 MB of database: before the export the chunks and their copies in the
		// volumes, after it only the copies.
		$before = ExportPlan::required_bytes( 1e9, 1000, 1e8, false );
		$after  = ExportPlan::required_bytes( 1e9, 1000, 1e8, true );
		$this->assertSame( 1e9 + 2e8 + 1000 * ExportPlan::ENTRY_OVERHEAD_BYTES + Packer::SPACE_MARGIN_BYTES, $before );
		$this->assertSame( $before - 1e8, $after );
		// Beyond a 32-bit integer.
		$this->assertGreaterThan( 5e12, ExportPlan::required_bytes( 5e12, 1, 0.0, true ) );
	}

	public function test_planned_files_leave_out_the_directories_the_review_left_out(): void {
		$scan   = array(
			'counts' => array(
				'files' => 30,
				'bytes' => 1000000,
			),
		);
		$review = array(
			'findings'  => array(
				'heavy' => array(
					array(
						'p'     => 'wp-content/a/node_modules',
						'bytes' => 300000,
					),
					array(
						'p'     => 'wp-content/b/.git',
						'bytes' => 200000,
					),
				),
			),
			'decisions' => array( 'exclude_paths' => array( 'wp-content/a/node_modules' ) ),
		);
		$this->assertSame(
			array(
				'bytes' => 700000.0,
				'count' => 30,
			),
			ExportPlan::planned_files( $scan, $review ),
			'only the one left out; the count stays an upper bound'
		);
		$this->assertSame(
			array(
				'bytes' => 0.0,
				'count' => 0,
			),
			ExportPlan::planned_files( array(), array() )
		);
	}

	public function test_effective_plan_is_a_pure_function_of_plan_and_review(): void {
		$plan   = array(
			'tables'     => array( 'wp_options', 'wp_posts', 'wp_sessions' ),
			'notes'      => array( 'View wp_v is not part of the backup (views are not exported).' ),
			'groups'     => array( 'uploads' ),
			'exclusions' => array( 'wp-content/cache' ),
		);
		$review = array(
			'findings'  => array(
				'oversize' => array(
					array(
						'table' => 'wp_options',
						'exact' => true,
						'count' => 3,
						'limit' => 4194304,
					),
					array(
						'table' => 'wp_posts',
						'exact' => false,
						'count' => null,
						'limit' => 4194304,
					),
				),
			),
			'decisions' => array(
				'exclude_tables'   => array( 'wp_sessions' ),
				'exclude_oversize' => array( 'wp_options', 'wp_posts', 'wp_not_in_plan' ),
				'exclude_paths'    => array( 'wp-content/uploads/node_modules', 'wp-content/cache', 'wp-content/uploads/node_modules' ),
				'notes'            => array( 'Directory wp-content/uploads/node_modules (120 MB) was left out of the backup, as chosen.' ),
			),
		);
		$first  = ExportPlan::effective( $plan, $review );
		$second = ExportPlan::effective( $plan, $review );
		$this->assertSame( $first, $second );
		$this->assertSame( array( 'wp_options', 'wp_posts' ), $first['tables'] );
		$this->assertSame( array( 'wp_options', 'wp_posts' ), $first['exclude_oversize'], 'only tables of the plan' );
		$this->assertSame( array( 'wp-content/cache' ), $first['exclusions'], 'patterns come from the plan only' );
		$this->assertSame( array( 'wp-content/uploads/node_modules', 'wp-content/cache' ), $first['exclude_paths'], 'literal paths stay literal, deduplicated' );
		$this->assertSame( array( 'uploads' ), $first['groups'] );
		$this->assertCount( 2, $first['notes'] );
		$this->assertSame(
			array(
				'wp_options' => 3,
				'wp_posts'   => null,
			),
			$first['oversize_counts']
		);
		// Applying the review twice changes nothing: the decisions are not appended anywhere.
		$this->assertSame( $first, ExportPlan::effective( $plan, $review ) );
	}

	public function test_a_directory_with_glob_characters_is_excluded_literally_and_its_siblings_are_not(): void {
		$plan      = array(
			'tables'     => array(),
			'groups'     => array( 'uploads' ),
			'exclusions' => array( 'wp-content/cache' ),
		);
		$review    = array(
			'findings'  => array(),
			'decisions' => array( 'exclude_paths' => array( 'wp-content/uploads/[2024]/node_modules' ) ),
		);
		$effective = ExportPlan::effective( $plan, $review );
		$this->assertSame( array( 'wp-content/cache' ), $effective['exclusions'], 'the decided directory never becomes a pattern' );
		$this->assertSame( array( 'wp-content/uploads/[2024]/node_modules' ), $effective['exclude_paths'] );
		$this->assertTrue( ExportPlan::excluded_by_path( 'wp-content/uploads/[2024]/node_modules', $effective['exclude_paths'] ) );
		$this->assertTrue( ExportPlan::excluded_by_path( 'wp-content/uploads/[2024]/node_modules/x/y.js', $effective['exclude_paths'] ) );
		$this->assertFalse( ExportPlan::excluded_by_path( 'wp-content/uploads/2/node_modules/x.js', $effective['exclude_paths'] ), 'a sibling a glob would have matched' );
		$this->assertFalse( ExportPlan::excluded_by_path( 'wp-content/uploads/0/node_modules/x.js', $effective['exclude_paths'] ) );
		$this->assertFalse( ExportPlan::excluded_by_path( 'wp-content/uploads/[2024]/node_modules_extra/x.js', $effective['exclude_paths'] ), 'a prefix match needs the slash' );
		$this->assertFalse( ExportPlan::excluded_by_path( 'wp-content/uploads/[2024]/node_module', $effective['exclude_paths'] ) );
		$this->assertFalse( ( new \WPCheckpoint\Files\Exclusions( $effective['exclusions'], array() ) )->excludes( 'wp-content/uploads/2/node_modules/x.js' ), 'and the pattern list does not carry it either' );
	}

	public function test_a_review_without_decisions_is_not_usable(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no decisions' );
		ExportPlan::effective( array( 'tables' => array( 'wp_posts' ) ), array( 'findings' => array() ) );
	}
}
