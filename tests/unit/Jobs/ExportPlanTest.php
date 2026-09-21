<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

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
		ExportPlan::write( $this->dir, ExportPlan::PLAN, array( 'tables' => array( 'wp_posts' ), 'base' => 'site-20260921-100000-ab12' ) );
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
					array( 'table' => 'wp_options', 'exact' => true, 'count' => 3, 'limit' => 4194304 ),
					array( 'table' => 'wp_posts', 'exact' => false, 'count' => null, 'limit' => 4194304 ),
				),
			),
			'decisions' => array(
				'exclude_tables'   => array( 'wp_sessions' ),
				'exclude_oversize' => array( 'wp_options', 'wp_posts', 'wp_not_in_plan' ),
				'exclude_dirs'     => array( 'wp-content/uploads/node_modules', 'wp-content/cache' ),
				'notes'            => array( 'Directory wp-content/uploads/node_modules (120 MB) was left out of the backup, as chosen.' ),
			),
		);
		$first  = ExportPlan::effective( $plan, $review );
		$second = ExportPlan::effective( $plan, $review );
		$this->assertSame( $first, $second );
		$this->assertSame( array( 'wp_options', 'wp_posts' ), $first['tables'] );
		$this->assertSame( array( 'wp_options', 'wp_posts' ), $first['exclude_oversize'], 'only tables of the plan' );
		$this->assertSame( array( 'wp-content/cache', 'wp-content/uploads/node_modules' ), $first['exclusions'] );
		$this->assertSame( array( 'uploads' ), $first['groups'] );
		$this->assertCount( 2, $first['notes'] );
		$this->assertSame( array( 'wp_options' => 3, 'wp_posts' => null ), $first['oversize_counts'] );
		// Applying the review twice changes nothing: the decisions are not appended anywhere.
		$this->assertSame( $first, ExportPlan::effective( $plan, $review ) );
	}

	public function test_a_review_without_decisions_is_not_usable(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'no decisions' );
		ExportPlan::effective( array( 'tables' => array( 'wp_posts' ) ), array( 'findings' => array() ) );
	}
}
