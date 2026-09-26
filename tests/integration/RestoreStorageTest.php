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
		$this->assertStringContainsString( 'A restore is in progress in a storage directory that this request cannot confirm to be the one WPCHECKPOINT_STORAGE_DIR names', $result->message );
		$this->assertStringContainsString( 'Set it to the directory the restore was started with', $result->message );
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
		$this->assertSame( $a, Directories::load_state()['path'], 'the stored spelling stays' );

		// The control: once no restore is unfinished, another custom directory becomes the choice (with its own token).
		Plugin::instance()->jobs()->transition( Plugin::instance()->jobs()->find( $id ), Job::CANCELLED );
		$this->custom_storage( substr( basename( $b ), strlen( 'wpc-custom-' ) ) );
		$this->assertSame( $b, Plugin::instance()->directories()->base() );
		$this->assertNotSame( $token, Directories::load_state()['token'] );
	}

	public function test_a_job_whose_directory_is_gone_is_failed_and_one_elsewhere_waits(): void {
		$this->register( ExportJob::ID, array( $this->counting_step( 'e', 5 ) ) );
		$base  = Plugin::instance()->directories()->base();
		$gone  = $this->job_of( ExportJob::ID );
		$other = $this->job_of( ExportJob::ID );
		$blind = $this->job_of( ExportJob::ID );
		file_put_contents( $base . '-file', 'not a directory' );
		self::set( $gone, array( 'storage_path' => $base . '-gone' ) );
		self::set( $other, array( 'storage_path' => $this->elsewhere ) );
		self::set( $blind, array( 'storage_path' => $base . '-file/x' ) ); // Its parent cannot be listed.
		try {
			Plugin::instance()->jobs()->settle_storage();
		} finally {
			@unlink( $base . '-file' );
		}
		$gone = Plugin::instance()->jobs()->find( $gone );
		$this->assertSame( Job::FAILED, $gone->status, 'its parent lists and holds no such directory' );
		$this->assertSame( Job::FAILURE_FINAL, $gone->failure_kind, 'a retry from this directory could not continue it' );
		$this->assertStringContainsString( 'is no longer at the path it was started in; the job cannot continue from this storage directory', $gone->last_error );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $other )->status, 'there, but not this one: the gate refuses it' );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $blind )->status, 'a parent that cannot be listed is no evidence' );
	}

	public function test_a_request_naming_the_restores_own_directory_takes_it_back_with_its_token(): void {
		$a = $this->custom_storage( 'a-' . wp_generate_password( 6, false ) );
		$this->register( RestoreJob::ID, array( $this->counting_step( 'r', 5 ) ) );
		$id    = $this->job_of( RestoreJob::ID );
		$token = Directories::load_state()['token'];
		// Something else recorded another directory meanwhile (a request without the constant).
		$state          = Directories::load_state();
		$state['path']  = Plugin::instance()->directories()->base() . '-elsewhere';
		$state['token'] = 'bbbbbbbbbbbb';
		\WPCheckpoint\Support\Options::set( Directories::OPTION, $state );
		$this->custom_storage( substr( basename( $a ), strlen( 'wpc-custom-' ) ) );
		$this->assertSame( $a, Plugin::instance()->directories()->base() );
		$this->assertSame( $token, Directories::load_state()['token'], 'the restore\'s own token' );
		$this->assertSame( 'more', Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT )->status );
	}

	public function test_a_restore_whose_directory_is_gone_does_not_hold_the_plugin(): void {
		$a = $this->custom_storage( 'a-' . wp_generate_password( 6, false ) );
		$this->register( RestoreJob::ID, array( $this->counting_step( 'r', 5 ) ) );
		$id = $this->job_of( RestoreJob::ID );
		// Moved away (mv A B) and the constant set to the new place everywhere.
		$b = get_temp_dir() . 'wpc-custom-b-' . wp_generate_password( 6, false );
		$this->custom[] = $b;
		rename( $a, $b );
		$this->custom_storage_at( $b );
		$this->assertSame( $b, Plugin::instance()->directories()->base(), 'the plugin goes on in the new directory' );
		Plugin::instance()->jobs()->settle_storage();
		$job = Plugin::instance()->jobs()->find( $id );
		$this->assertSame( Job::FAILED, $job->status, 'the restore whose directory is gone ends' );
		$this->assertSame( 'The storage directory changed; the job cannot continue.', $job->last_error, 'the new directory has its own token' );
	}

	public function test_a_request_that_cannot_read_the_restores_does_not_switch(): void {
		$a = $this->custom_storage( 'a-' . wp_generate_password( 6, false ) );
		$this->assertSame( $a, Plugin::instance()->directories()->base(), 'the control: A is the choice' );
		$b = get_temp_dir() . 'wpc-custom-b-' . wp_generate_password( 6, false );
		Plugin::instance()->reset_directories();
		$dirs = new Directories(
			array(
				'custom_dir' => $b,
				'restores'   => '__return_null',
			)
		);
		$this->assertSame( '', $dirs->base() );
		$this->assertStringContainsString( 'Whether a restore is in progress cannot be read', $dirs->last_error() );
		$this->assertStringNotContainsString( 'A restore is in progress', $dirs->last_error(), 'not claimed' );
		$this->assertDirectoryDoesNotExist( $b );
		$this->assertSame( $a, Directories::load_state()['path'] );
		// The control: where the restores are read and one is in another directory that is there, it is claimed.
		$known = new Directories(
			array(
				'custom_dir' => $b,
				'restores'   => static function () use ( $a ): array {
					return array(
						array(
							'path'  => $a,
							'token' => 'cccccccccccc',
						),
					);
				},
			)
		);
		$this->assertSame( '', $known->base() );
		$this->assertStringContainsString( 'A restore is in progress', $known->last_error() );
	}

	public function test_an_unreachable_directory_is_not_replaced_while_a_restore_may_use_it(): void {
		$this->register( RestoreJob::ID, array( $this->counting_step( 'r', 5 ) ) );
		$id   = $this->job_of( RestoreJob::ID );
		$base = Plugin::instance()->directories()->base();
		// The stored directory is the restore's, it is not reachable from this request, and nothing shows it
		// gone (its parent is a file).
		file_put_contents( $base . '-file', 'not a directory' );
		$stored = $base . '-file/x';
		self::set( $id, array( 'storage_path' => $stored ) );
		$remember = function ( string $path ): void {
			$state         = Directories::load_state();
			$state['path'] = $path;
			\WPCheckpoint\Support\Options::set( Directories::OPTION, $state );
		};
		$remember( $stored );
		try {
			$dirs = new Directories();
			$this->assertSame( '', $dirs->base(), 'no other directory chosen' );
			$this->assertStringContainsString( 'cannot be reached from this request', $dirs->last_error() );
			$this->assertSame( $stored, Directories::load_state()['path'] );

			// Nor when the restore names it another way that this request cannot resolve either.
			self::set( $id, array( 'storage_path' => $base . '-file/./x' ) );
			$this->assertSame( '', ( new Directories() )->base(), 'another spelling nothing can resolve: held' );
			self::set( $id, array( 'storage_path' => $stored ) );

			// A restore whose own directory is positively gone does not hold it.
			self::set( $id, array( 'storage_path' => $base . '-gone-restore' ) );
			$this->assertNotSame( '', ( new Directories() )->base(), 'its restore is gone: another directory is chosen' );
			$remember( $stored );
			self::set( $id, array( 'storage_path' => $stored ) );

			// Nor when the restore names no directory at all (an empty row): nothing shows it is another one.
			self::set( $id, array( 'storage_path' => '' ) );
			$this->assertSame( '', ( new Directories() )->base(), 'empty: held' );
			self::set( $id, array( 'storage_path' => $stored ) );

			// Nor is it when the restores cannot be read.
			$this->assertSame( '', ( new Directories( array( 'restores' => '__return_null' ) ) )->base(), 'unknown: held' );

			// A restore elsewhere does not hold an unreachable directory that is not its own.
			self::set( $id, array( 'storage_path' => $base ) );
			$this->assertNotSame( '', ( new Directories() )->base(), 'another directory is chosen' );

			// Nor does a restore's own directory that is positively gone (its parent lists without it).
			$remember( $base . '-gone-dir' );
			self::set( $id, array( 'storage_path' => $base . '-gone-dir' ) );
			$this->assertNotSame( '', ( new Directories() )->base(), 'gone: another directory is chosen' );

			// The control: with the restore ended, the unreachable directory does not hold either.
			$remember( $stored );
			Plugin::instance()->jobs()->transition( Plugin::instance()->jobs()->find( $id ), Job::CANCELLED );
			$after = new Directories();
			$this->assertNotSame( '', $after->base(), $after->last_error() );
		} finally {
			@unlink( $base . '-file' );
		}
	}

	public function test_unfinished_jobs_are_read_from_the_table_and_an_unreadable_table_is_no_answer(): void {
		global $wpdb;
		$this->register( ExportJob::ID, array( $this->counting_step( 'e', 5 ) ) );
		$this->job_of( ExportJob::ID );
		$this->assertTrue( \WPCheckpoint\Jobs\JobRepository::has_unfinished() );
		$this->assertFalse( \WPCheckpoint\Jobs\JobRepository::has_unfinished( RestoreJob::ID ) );
		// A read that fails is no answer, and so is a failed look for the table.
		$hits   = 0;
		$broken = static function ( $query ) use ( &$hits ) {
			// Also the look for the table: its LIKE pattern escapes the underscores of the name.
			if ( false !== strpos( (string) $query, 'wpcheckpoint' ) ) {
				++$hits;
				return 'SELECT no_such_column FROM no_such_table_here';
			}
			return $query;
		};
		add_filter( 'query', $broken );
		$this->assertNull( \WPCheckpoint\Jobs\JobRepository::has_unfinished() );
		$this->assertNull( \WPCheckpoint\Jobs\JobRepository::unfinished_restores() );
		remove_filter( 'query', $broken );
		$this->assertSame( 4, $hits, 'the control: the read and the look for the table failed, twice each' );
		$this->assertSame( array(), \WPCheckpoint\Jobs\JobRepository::unfinished_restores(), 'the control: read, none' );
		// No table at all: no job.
		$wpdb->query( 'DROP TABLE ' . Schema::jobs_table() );
		$this->assertFalse( \WPCheckpoint\Jobs\JobRepository::has_unfinished() );
		$this->assertSame( array(), \WPCheckpoint\Jobs\JobRepository::unfinished_restores() );
	}
}
