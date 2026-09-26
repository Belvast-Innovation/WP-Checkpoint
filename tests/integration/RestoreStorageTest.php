<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
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
		foreach ( $this->custom as $dir ) {
			Deleter::empty_directory( $dir );
			@rmdir( $dir );
		}
		parent::tear_down();
	}

	/** @var string[] Custom storage directories a test used. */
	private $custom = array();

	/**
	 * This request's storage directory: WPCHECKPOINT_STORAGE_DIR set to $name (under the temporary directory).
	 */
	private function custom_storage( string $name ): string {
		$dir            = get_temp_dir() . 'wpc-custom-' . $name;
		$this->custom[] = $dir;
		$this->custom_storage_at( $dir );
		return $dir;
	}

	/**
	 * This request's storage directory: WPCHECKPOINT_STORAGE_DIR set to $dir as written.
	 */
	private function custom_storage_at( string $dir ): void {
		Plugin::instance()->reset_directories();
		$this->replace_internal( Plugin::instance(), 'directories', new Directories( array( 'custom_dir' => $dir ) ) );
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
		// Its files are reached under the current directory: the lock file is there, and its log can be read.
		$base = Plugin::instance()->directories()->base();
		$this->assertFileExists( $base . '/tmp/job-' . $id . '.lock' );
		$this->assertStringStartsWith( $base . DIRECTORY_SEPARATOR . 'logs', Plugin::instance()->job_presenter()->log_file( Plugin::instance()->jobs()->find( $id ) ) );
		// The control: a row whose location is another directory gets neither.
		self::set( $id, array( 'storage_path' => $this->elsewhere ) );
		$this->assertSame( '', Plugin::instance()->job_presenter()->log_file( Plugin::instance()->jobs()->find( $id ) ) );
	}

	public function test_a_restore_survives_a_request_that_names_another_custom_directory(): void {
		$a = $this->custom_storage( 'a-' . wp_generate_password( 6, false ) );
		$this->register( RestoreJob::ID, array( $this->counting_step( 'r', 5 ) ) );
		$id    = $this->job_of( RestoreJob::ID );
		$token = Directories::load_state()['token'];
		$this->assertSame( $a, Plugin::instance()->jobs()->find( $id )->storage_path );

		// The web server names another directory: nothing of this request's choice is kept.
		$b = $this->custom_storage( 'b-' . wp_generate_password( 6, false ) );
		delete_site_transient( 'wpcheckpoint_jobs_reaped' ); // Housekeeping runs in this tick.
		$result = Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT );
		$this->assertSame( 'blocked', $result->status );
		$this->assertStringContainsString( 'A restore is in progress in the storage directory it was started with', $result->message );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $id )->status, 'not failed by housekeeping' );
		$this->assertSame( $token, Directories::load_state()['token'], 'not re-tokened' );
		$this->assertSame( $a, Directories::load_state()['path'] );
		$this->assertDirectoryDoesNotExist( $b, 'nothing created there' );

		// WP-CLI, with the directory it was started with: the restore goes on.
		$this->custom_storage( substr( basename( $a ), strlen( 'wpc-custom-' ) ) );
		$this->assertSame( 'more', Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT )->status );
		$this->assertSame( 2, self::units( $id ) );
		// The same directory written another way is the same directory: not refused, not re-tokened.
		$this->custom_storage_at( dirname( $a ) . '/.' . '/' . basename( $a ) . '/../' . basename( $a ) );
		$this->assertSame( 'more', Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT )->status );
		$this->assertSame( 3, self::units( $id ) );
		$this->assertSame( $token, Directories::load_state()['token'] );

		// The control: once no restore is unfinished, another custom directory becomes the choice (with its own token).
		Plugin::instance()->jobs()->transition( Plugin::instance()->jobs()->find( $id ), Job::CANCELLED );
		$this->custom_storage( substr( basename( $b ), strlen( 'wpc-custom-' ) ) );
		$this->assertSame( $b, Plugin::instance()->directories()->base() );
		$this->assertNotSame( $token, Directories::load_state()['token'] );
	}

	public function test_a_job_whose_directory_is_gone_is_failed_and_one_elsewhere_waits(): void {
		$this->register( ExportJob::ID, array( $this->counting_step( 'e', 5 ) ) );
		$gone  = $this->job_of( ExportJob::ID );
		$other = $this->job_of( ExportJob::ID );
		self::set( $gone, array( 'storage_path' => Plugin::instance()->directories()->base() . '-gone' ) );
		self::set( $other, array( 'storage_path' => $this->elsewhere ) );
		Plugin::instance()->jobs()->settle_storage();
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $gone )->status, 'its parent lists and holds no such directory' );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $other )->status, 'there, but not this one: the gate refuses it' );
	}

	public function test_unfinished_jobs_are_read_from_the_table_and_an_unreadable_table_is_no_answer(): void {
		global $wpdb;
		$this->register( ExportJob::ID, array( $this->counting_step( 'e', 5 ) ) );
		$this->job_of( ExportJob::ID );
		$this->assertTrue( \WPCheckpoint\Jobs\JobRepository::has_unfinished() );
		$this->assertFalse( \WPCheckpoint\Jobs\JobRepository::has_unfinished( RestoreJob::ID ) );
		// A query that fails is no answer.
		$broken = static function ( $query ) {
			return false !== strpos( (string) $query, 'SELECT COUNT(*) FROM ' . Schema::jobs_table() ) ? 'SELECT COUNT(*) FROM ' . Schema::jobs_table() . ' WHERE no_such_column = 1' : $query;
		};
		add_filter( 'query', $broken );
		$this->assertNull( \WPCheckpoint\Jobs\JobRepository::has_unfinished() );
		remove_filter( 'query', $broken );
		// No table at all: no job.
		$wpdb->query( 'DROP TABLE ' . Schema::jobs_table() );
		$this->assertFalse( \WPCheckpoint\Jobs\JobRepository::has_unfinished() );
	}
}
