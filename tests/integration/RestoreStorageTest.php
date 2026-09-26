<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * A restore keeps its files in the storage directory it was started with
 * (its row's storage_path), and no driver resolves them again: a request
 * that uses another directory (another token, or the same token at
 * another path) does not run it, and says why.
 */
final class RestoreStorageTest extends JobTestCase {

	/** @var string */
	private $elsewhere;

	public function set_up(): void {
		parent::set_up();
		$this->elsewhere = Plugin::instance()->directories()->base() . '-elsewhere';
		wp_mkdir_p( $this->elsewhere );
	}

	public function tear_down(): void {
		@rmdir( $this->elsewhere );
		parent::tear_down();
	}

	private static function units( int $id ): int {
		return (int) ( JobContext::strip_reserved( Plugin::instance()->jobs()->find( $id )->cursor )['n'] ?? 0 );
	}

	private static function set( int $id, array $row ): void {
		global $wpdb;
		$wpdb->update( Schema::jobs_table(), $row, array( 'id' => $id ) );
	}

	public function test_a_restore_is_not_run_by_a_request_that_uses_another_storage_directory(): void {
		$this->register( RestoreJob::ID, array( $this->counting_step( 'r', 5 ) ) );
		$id   = $this->job_of( RestoreJob::ID );
		$path = Plugin::instance()->jobs()->find( $id )->storage_path;
		$this->assertSame( 1, self::units( $id ) );

		// The same token at another path (the row says where its files are).
		self::set( $id, array( 'storage_path' => $this->elsewhere ) );
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 'blocked', $result->status );
		$this->assertStringContainsString( 'This restore keeps its files in the storage directory it was started with', $result->message );
		$this->assertStringContainsString( 'WPCHECKPOINT_STORAGE_DIR', $result->message );
		$this->assertSame( 1, self::units( $id ), 'not run' );

		// Another token (another directory choice in this request).
		self::set( $id, array( 'storage_path' => $path, 'storage_token' => 'aaaaaaaaaaaa' ) );
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 'blocked', $result->status );
		$this->assertStringContainsString( 'This restore keeps its files in the storage directory it was started with', $result->message );
		$this->assertSame( 1, self::units( $id ), 'not run' );

		// The control: the directory it was started with runs it again.
		self::set( $id, array( 'storage_token' => Plugin::instance()->directories()->state()['token'], 'blocked_count' => 0 ) );
		$this->assertSame( 'more', Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT )->status );
		$this->assertSame( 2, self::units( $id ) );
	}

	public function test_another_job_at_another_path_is_refused_with_the_general_reason(): void {
		$this->register( ExportJob::ID, array( $this->counting_step( 'e', 5 ) ) );
		$id = $this->job_of( ExportJob::ID );
		self::set( $id, array( 'storage_path' => $this->elsewhere ) );
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 'blocked', $result->status, 'the same rule for every job' );
		$this->assertSame( 'The storage directory changed; this job cannot continue.', $result->message );
		$this->assertSame( 1, self::units( $id ) );
	}

	public function test_a_path_written_another_way_is_the_same_directory(): void {
		$this->register( RestoreJob::ID, array( $this->counting_step( 'r', 5 ) ) );
		$id   = $this->job_of( RestoreJob::ID );
		$path = Plugin::instance()->jobs()->find( $id )->storage_path;
		// The same directory, spelled through its parent: the check compares real paths, not spellings.
		self::set( $id, array( 'storage_path' => $path . '/../' . basename( $path ) ) );
		$this->assertSame( 'more', Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT )->status );
		$this->assertSame( 2, self::units( $id ) );
		$this->assertNotSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status );
	}
}
