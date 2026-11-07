<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\TextMask;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The jobs table's name is made in one place (Schema::jobs_table_for()); what a step's fenced writes lock
 * (JobContext::fence()) is the table the Runner's storage says (RunnerRepository::jobs_table()), not one read from
 * WordPress. The two storages giving the same name for the same prefix, and WordPress's value unchanged, are in the
 * integration test (JobsTableNameTest).
 */
final class JobsTableTest extends TestCase {

	public function test_the_name_is_made_from_the_prefix(): void {
		$this->assertSame( 'wp_wpcheckpoint_jobs', Schema::jobs_table_for( 'wp_' ) );
		$this->assertSame( 'wpcheckpoint_jobs', Schema::jobs_table_for( '' ) );
		$this->assertSame( 'site9_wpcheckpoint_jobs', Schema::jobs_table_for( 'site9_' ) );
	}

	public function test_the_fence_locks_the_table_the_runner_gives(): void {
		$job     = new Job();
		$job->id = 5;
		$context = new JobContext(
			$job,
			array(),
			new Budget( 20, 32 * 1048576, false ),
			new Logger( sys_get_temp_dir() . '/wpc-jobs-table-' . bin2hex( random_bytes( 4 ) ) . '.log', TextMask::redact_only( new Redactor() ) ),
			static function (): float {
				return 0.0;
			},
			static function (): int {
				return 0;
			},
			0.0,
			-1,
			null,
			null,
			array(
				'cli'   => false,
				'table' => 'other_wpcheckpoint_jobs',
			),
			null,
			'token'
		);
		$this->assertSame(
			array(
				'table' => 'other_wpcheckpoint_jobs',
				'id'    => 5,
				'token' => 'token',
			),
			$context->fence()
		);
	}
}
