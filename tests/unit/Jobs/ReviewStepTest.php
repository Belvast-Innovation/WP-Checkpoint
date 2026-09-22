<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\ReviewStep;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Redactor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The review over fixture files: what it asks, what a policy decides,
 * and that running it twice from the same inputs writes the same file.
 */
final class ReviewStepTest extends TestCase {

	/** @var string */
	private $root;

	/** @var string */
	private $work;

	protected function set_up(): void {
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-review-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/tmp', 0700, true );
		$this->work = $this->root . '/tmp/job-5';
		mkdir( $this->work, 0700 );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->root ) );
	}

	/**
	 * @param array<string, mixed> $options Job options.
	 */
	private function context( array $options ): JobContext {
		$job               = new Job();
		$job->id           = 5;
		$job->storage_path = $this->root;
		$job->options      = $options;
		return new JobContext(
			$job,
			array(),
			new Budget( 10, 33554432, false ),
			new Logger( $this->root . '/job.log', new Redactor() ),
			static function (): float {
				return microtime( true );
			},
			static function (): int {
				return memory_get_usage( true );
			},
			microtime( true ),
			-1,
			static function (): void {}
		);
	}

	private function inputs( array $oversize = array(), array $scan_lists = array(), array $scan_counts = array(), int $int_size = 8, array $limits = array( 'max_file_bytes' => 261469110272, 'max_file_limit' => 'index' ) ): void {
		ExportPlan::write( $this->work, ExportPlan::PREFLIGHT, array(
			'checks'   => array( 'int_size' => $int_size, 'max_entry_bytes' => 8 === $int_size ? 4398046511104 : 2147483647 ),
			'findings' => array( 'oversize' => $oversize ),
			'warnings' => array(),
		) );
		$lists = array_merge( array( 'unreadable' => array(), 'too_large' => array(), 'over_volume' => array(), 'heavy' => array() ), $scan_lists );
		file_put_contents( $this->work . '/' . FileScanStep::SUMMARY, json_encode( array(
			'counts' => array_merge( array( 'unreadable' => count( $lists['unreadable'] ), 'too_large' => count( $lists['too_large'] ), 'over_volume' => 0, 'heavy' => count( $lists['heavy'] ) ), $scan_counts ),
			'lists'  => $lists,
			'limits' => $limits,
		) ) );
	}

	private function review(): string {
		return (string) file_get_contents( $this->work . '/' . ExportPlan::REVIEW );
	}

	public function test_nothing_to_decide_finishes_with_empty_decisions(): void {
		$this->inputs();
		$result = ( new ReviewStep() )->run( $this->context( array() ) );
		$this->assertSame( StepResult::DONE, $result->kind );
		$review = json_decode( $this->review(), true );
		$this->assertSame( array( 'exclude_tables' => array(), 'exclude_oversize' => array(), 'exclude_paths' => array(), 'notes' => array() ), $review['decisions'] );
		$this->assertFalse( $review['asked'] );
	}

	public function test_findings_become_pointer_questions_and_the_review_file_holds_the_details(): void {
		$heavy = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$heavy[ 'wp-content/plugins/p' . $i . '/node_modules' ] = ( 60 + $i ) * 1048576;
		}
		$heavy['wp-content/plugins/small/node_modules'] = 10 * 1048576;
		$this->inputs(
			array(
				array( 'table' => 'wp_options', 'exact' => true, 'count' => 3, 'limit' => 4194304 ),
				array( 'table' => 'wp_postmeta', 'exact' => false, 'count' => null, 'limit' => 4194304 ),
			),
			array( 'unreadable' => array( 'wp-content/uploads/a.jpg', 'wp-content/uploads/b.jpg' ), 'heavy' => $heavy ),
			array( 'unreadable' => 7 )
		);
		$result = ( new ReviewStep() )->run( $this->context( array() ) );
		$this->assertSame( StepResult::ASK, $result->kind );
		$ids = array_column( $result->questions, 'id' );
		$this->assertSame( 'unreadable', $ids[0] );
		$this->assertSame( array( 'id' => 'unreadable', 'kind' => 'unreadable', 'count' => 7, 'file' => 'review.json', 'choices' => array( 'continue', 'stop' ) ), $result->questions[0], 'the count is the scan count, the paths stay in the file' );
		$this->assertSame( 10, count( preg_grep( '/\Alarge_dir_\d+\z/', $ids ) ), 'ten listed heavy directories' );
		$this->assertContains( 'large_dirs_more', $ids );
		$this->assertSame( 'oversize_0', $ids[ count( $ids ) - 2 ] );
		$this->assertSame( 'oversize_1', $ids[ count( $ids ) - 1 ] );
		$oversize = $result->questions[ count( $ids ) - 2 ];
		$this->assertSame( array( 'id' => 'oversize_0', 'kind' => 'oversize', 'file' => 'review.json', 'choices' => array( 'exclude', 'stop' ), 'count' => 3 ), $oversize );
		$this->assertSame( 'oversize_possible', $result->questions[ count( $ids ) - 1 ]['kind'] );
		$this->assertArrayNotHasKey( 'count', $result->questions[ count( $ids ) - 1 ], 'a sampled table has no count' );
		foreach ( $result->questions as $question ) {
			foreach ( array_keys( $question ) as $key ) {
				$this->assertContains( $key, array( 'id', 'kind', 'count', 'bytes', 'file', 'choices' ), 'questions are pointers only' );
			}
		}
		$review = json_decode( $this->review(), true );
		$this->assertArrayNotHasKey( 'decisions', $review, 'open questions: no decisions yet' );
		$this->assertSame( array( 'wp-content/uploads/a.jpg', 'wp-content/uploads/b.jpg' ), $review['findings']['unreadable']['listed'] );
		$this->assertSame( 12, count( $review['findings']['heavy'] ), 'the 10 MB one is below the threshold' );
		$this->assertSame( 'wp-content/plugins/p11/node_modules', $review['findings']['heavy'][0]['p'], 'largest first' );
		$this->assertSame( 'wp_options', $review['findings']['oversize'][0]['table'] );
		$first = $this->review();

		// Asked again before any answer (a tick that died after writing): the same file, the same questions.
		$again = ( new ReviewStep() )->run( $this->context( array() ) );
		$this->assertSame( $first, $this->review() );
		$this->assertSame( $result->questions, $again->questions );

		// Answered: decisions derived from the same inputs plus the answers; a second run writes an identical file.
		$answers = array( 'unreadable' => 'continue', 'large_dir_0' => 'exclude', 'large_dir_1' => 'include', 'large_dirs_more' => 'exclude', 'oversize_0' => 'exclude', 'oversize_1' => 'exclude' );
		for ( $i = 2; $i < 10; $i++ ) {
			$answers[ 'large_dir_' . $i ] = 'include';
		}
		$done = ( new ReviewStep() )->run( $this->context( array( 'answers' => $answers ) ) );
		$this->assertSame( StepResult::DONE, $done->kind );
		$decided = $this->review();
		$review  = json_decode( $decided, true );
		$this->assertTrue( $review['asked'] );
		$this->assertSame( array( 'wp-content/plugins/p11/node_modules', 'wp-content/plugins/p1/node_modules', 'wp-content/plugins/p0/node_modules' ), $review['decisions']['exclude_paths'], 'the largest, and the two beyond the listed ten' );
		$this->assertSame( array( 'wp_options', 'wp_postmeta' ), $review['decisions']['exclude_oversize'] );
		$this->assertSame( array(), $review['decisions']['exclude_tables'] );
		$this->assertCount( 1 + 3 + 2, $review['decisions']['notes'] );
		( new ReviewStep() )->run( $this->context( array( 'answers' => $answers ) ) );
		$this->assertSame( $decided, $this->review(), 'running twice writes the same bytes: nothing is appended' );
	}

	public function test_a_policy_decides_without_asking_and_fail_stops_with_the_reason(): void {
		$this->inputs(
			array( array( 'table' => 'wp_options', 'exact' => true, 'count' => 2, 'limit' => 4194304 ) ),
			array( 'unreadable' => array( 'wp-content/uploads/a.jpg' ), 'heavy' => array( 'wp-content/plugins/x/node_modules' => 200 * 1048576 ) )
		);
		$result = ( new ReviewStep() )->run( $this->context( array( 'policy' => array( 'unreadable' => 'continue', 'oversize' => 'exclude', 'large_dirs' => 'include' ) ) ) );
		$this->assertSame( StepResult::DONE, $result->kind );
		$review = json_decode( $this->review(), true );
		$this->assertSame( array( 'wp_options' ), $review['decisions']['exclude_oversize'] );
		$this->assertSame( array(), $review['decisions']['exclude_paths'] );
		$this->assertFalse( $review['asked'] );

		try {
			( new ReviewStep() )->run( $this->context( array( 'policy' => array( 'unreadable' => 'continue', 'oversize' => 'fail', 'large_dirs' => 'include' ) ) ) );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Stopped: table wp_options has rows larger than the single-row limit of 4194304 bytes (as SQL) (2 rows)', $e->getMessage() );
		}
		try {
			( new ReviewStep() )->run( $this->context( array( 'policy' => array( 'unreadable' => 'fail', 'oversize' => 'exclude', 'large_dirs' => 'include' ) ) ) );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Stopped: 1 files cannot be read', $e->getMessage() );
		}
		// A partial policy asks only what it does not cover.
		$result = ( new ReviewStep() )->run( $this->context( array( 'policy' => array( 'unreadable' => 'continue', 'oversize' => 'exclude' ) ) ) );
		$this->assertSame( StepResult::ASK, $result->kind );
		$this->assertSame( array( 'large_dir_0' ), array_column( $result->questions, 'id' ) );
		// An answer of "stop" stops as well.
		try {
			( new ReviewStep() )->run( $this->context( array( 'answers' => array( 'unreadable' => 'stop' ) ) ) );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'cannot be read', $e->getMessage() );
		}
	}

	public function test_files_too_large_stop_the_export_before_any_question_and_name_the_threshold_the_scan_used(): void {
		// 32-bit PHP: the container limit is the lower one; the scan recorded it, the message repeats it.
		$this->inputs( array(), array( 'too_large' => array( 'wp-content/uploads/huge.iso' ) ), array(), 4, array( 'max_file_bytes' => 2147483647, 'max_file_limit' => 'int_size' ) );
		try {
			( new ReviewStep() )->run( $this->context( array( 'policy' => array( 'unreadable' => 'continue', 'oversize' => 'exclude', 'large_dirs' => 'include' ) ) ) );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( '1 files are larger than 2047 MB, the largest file a backup made by this server\'s 32-bit PHP can hold: wp-content/uploads/huge.iso. Move them out of the site or exclude them, or run the backup on 64-bit PHP.', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->work . '/' . ExportPlan::REVIEW );
		// 64-bit PHP: the index line is the lower one; the message names the format, not the platform.
		$this->inputs( array(), array( 'too_large' => array( 'wp-content/uploads/huge.iso' ) ), array(), 8, array( 'max_file_bytes' => 261469110272, 'max_file_limit' => 'index' ) );
		try {
			( new ReviewStep() )->run( $this->context( array( 'policy' => array( 'unreadable' => 'continue', 'oversize' => 'exclude', 'large_dirs' => 'include' ) ) ) );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( '1 files are larger than 249356 MB, the largest file the backup format can describe: wp-content/uploads/huge.iso. Move them out of the site or exclude them.', $e->getMessage() );
		}
	}

	public function test_without_a_scan_summary_only_the_database_findings_are_reviewed(): void {
		ExportPlan::write( $this->work, ExportPlan::PREFLIGHT, array( 'checks' => array(), 'findings' => array( 'oversize' => array() ), 'warnings' => array() ) );
		$result = ( new ReviewStep() )->run( $this->context( array( 'contents' => array( 'files' => array() ) ) ) );
		$this->assertSame( StepResult::DONE, $result->kind );
	}
}
