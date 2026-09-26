<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\ReclaimActions;
use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Jobs\LockFile;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\StaleJob;
use WPCheckpoint\Jobs\TempTableDropper;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\StorageReclaim;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Tests\Fixtures\Permissions;

final class JobRepositoryTest extends WP_UnitTestCase {

	/** @var Directories */
	private $dirs;

	/** @var string */
	private $base;

	/** @var int */
	private $now;

	/** @var JobRepository */
	private $repo;

	/** @var string */
	private $root;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// The core test case rewrites CREATE TABLE into CREATE TEMPORARY TABLE, which
		// SHOW TABLES cannot see; the plugin's own table is created for real and dropped again.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		delete_site_transient( 'wpcheckpoint_jobs_reaped' );
		delete_site_transient( 'wpcheckpoint_jobs_purged' );
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-jobs-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/releases/a/wp-includes', 0755, true );
		mkdir( $this->root . '/releases/b/wp-includes', 0755, true );
		$this->dirs = $this->site( 'releases/a' );
		$this->base = $this->dirs->base();
		$this->assertNotSame( '', $this->base );
		$this->now  = 1_800_000_000;
		$this->repo = $this->repo_for( $this->dirs );
		$this->assertSame( 'created', Schema::ensure()['action'] );
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		foreach ( glob( WP_CONTENT_DIR . '/wp-checkpoint-*' ) ?: array() as $dir ) {
			Deleter::empty_directory( $dir );
			@rmdir( $dir );
		}
		Deleter::empty_directory( $this->root );
		@rmdir( $this->root );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		parent::tear_down();
	}

	private function site( string $abspath ): Directories {
		return new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/' . $abspath . '/' ) );
	}

	private function repo_for( Directories $dirs ): JobRepository {
		return new JobRepository( $dirs, null, function (): int {
			return $this->now;
		} );
	}

	public function test_schema_is_created_once_and_recreated_when_the_table_is_dropped(): void {
		global $wpdb;
		$this->assertTrue( Schema::table_exists() );
		$this->assertSame( array( 'version' => Schema::CURRENT, 'min_compatible' => 1 ), Schema::stored() );
		$this->assertSame( 'none', Schema::ensure()['action'] );
		$this->assertStringStartsWith( $wpdb->base_prefix, Schema::jobs_table() );

		$wpdb->query( 'DROP TABLE ' . Schema::jobs_table() );
		$this->assertSame( 'created', Schema::ensure()['action'], 'a missing table is recreated' );
		$this->assertTrue( Schema::table_exists() );
	}

	public function test_newer_but_compatible_schema_is_used_incompatible_is_refused(): void {
		Options::set( Schema::OPTION, array( 'version' => Schema::CURRENT + 1, 'min_compatible' => Schema::CURRENT ) );
		$this->assertSame( 'newer', Schema::ensure()['action'] );
		$this->assertTrue( Schema::is_compatible() );
		$this->assertTrue( Schema::is_newer() );
		$this->assertSame( array( 'version' => Schema::CURRENT + 1, 'min_compatible' => Schema::CURRENT ), Schema::stored(), 'never downgraded' );
		$job = $this->repo->create( 'export' );
		$this->assertTrue( $this->repo->gate( $job )['allowed'] );

		Options::set( Schema::OPTION, array( 'version' => Schema::CURRENT + 2, 'min_compatible' => Schema::CURRENT + 1 ) );
		$this->assertSame( 'incompatible', Schema::ensure()['action'] );
		$this->assertFalse( Schema::is_compatible() );
		$gate = $this->repo->gate( $job );
		$this->assertFalse( $gate['allowed'] );
		$this->assertSame( 'schema', $gate['reason'] );
		$this->expectException( JobsUnavailable::class );
		$this->repo->create( 'export' );
	}

	public function test_create_brings_a_table_left_behind_by_an_older_version_up_to_date_first(): void {
		global $wpdb;
		// A version 2 table: what an update leaves until something calls Schema::ensure(). The first job a
		// command creates may come before any tick, admin page or activation did.
		foreach ( array( 'options_json', 'questions_json', 'takeovers', 'takeover_mark' ) as $column ) {
			$wpdb->query( 'ALTER TABLE ' . Schema::jobs_table() . ' DROP COLUMN `' . $column . '`' );
		}
		$this->assertSame( '', $wpdb->last_error );
		Options::set( Schema::OPTION, array( 'version' => 2, 'min_compatible' => 1 ) );
		$job = $this->repo->create( 'export', 0, array(), array( 'policy' => array( 'unreadable' => 'continue' ) ) );
		$this->assertSame( array( 'policy' => array( 'unreadable' => 'continue' ) ), $this->repo->find( $job->id )->options );
		$this->assertSame( Schema::CURRENT, Schema::stored()['version'] );
	}

	public function test_create_and_find(): void {
		$job = $this->repo->create( 'export', 42, array( 'table' => 'wp_posts', 'offset' => 0 ) );
		$this->assertGreaterThan( 0, $job->id );
		$this->assertSame( Job::QUEUED, $job->status );
		$this->assertSame( get_current_blog_id(), $job->site_id );
		$this->assertSame( 42, $job->owner_user );
		$this->assertSame( array( 'table' => 'wp_posts', 'offset' => 0 ), $job->cursor );
		$this->assertSame( $this->dirs->state()['token'], $job->storage_token );
		$this->assertSame( $this->base, $job->storage_path );
		$this->assertMatchesRegularExpression( '#^logs/job-' . $job->id . '-[0-9a-f]{8}\.log$#', $job->log_path );
		$this->assertSame( $this->now, $job->created_at );
		$this->assertSame( $this->now, $job->progress_at );
		$this->assertNull( $this->repo->find( 999999 ) );
		$this->assertSame( array( $job->id ), array_map( static function ( Job $j ) { return $j->id; }, $this->repo->list_jobs() ) );
		$this->assertSame( 1, $this->repo->counts()[ Job::QUEUED ] );
	}

	public function test_cursor_must_not_contain_credentials(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->repo->create( 'export', 0, array( 'note' => 'db password is ' . DB_PASSWORD ) );
	}

	public function test_cursor_check_sees_every_escaped_form_of_a_secret(): void {
		// Slash, double quote, backslash and non-ASCII are escaped by wp_json_encode().
		$secrets = array( 'Ab/cd12345', 'x"y12345678', 'back\\slash1', 'pässwörd123' );
		foreach ( $secrets as $secret ) {
			try {
				JobRepository::assert_cursor_has_no_secrets( array( 'note' => 'leak ' . $secret ), $secrets );
				$this->fail( 'not caught: ' . $secret );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Cursor must not contain credentials.', $e->getMessage() );
			}
		}
		JobRepository::assert_cursor_has_no_secrets( array( 'table' => 'wp_posts', 'offset' => 500 ), $secrets );
		JobRepository::assert_cursor_has_no_secrets( array( 'short' => 'abc' ), array( 'abc' ) );
		$this->assertTrue( true, 'identifiers and secrets shorter than the minimum pass' );
	}

	public function test_lock_is_a_compare_and_set_between_drivers(): void {
		$job = $this->repo->create( 'export' );

		$first = $this->repo->acquire( $job->id, 100 );
		$this->assertNotNull( $first );
		$this->assertSame( Job::RUNNING, $first['job']->status );
		$this->assertSame( 1, $first['job']->attempts );
		$this->assertSame( $this->now, $first['job']->started_at );
		$this->assertSame( $this->now + 100, $first['job']->locked_until );
		$lock = LockFile::path( $this->base, $job->id );
		$this->assertFileExists( $lock );
		$this->assertTrue( LockFile::is_owned_by( $lock, $first['token'] ) );
		$this->assertStringNotContainsString( $first['token'], (string) file_get_contents( $lock ), 'only the hash is on disk' );

		$this->assertNull( $this->repo->acquire( $job->id, 100 ), 'a second driver is refused while the lock is held' );
		$this->assertFalse( $this->repo->heartbeat( $first['job'], 'someone-else', 100 ) );
		$this->assertFalse( $this->repo->release( $first['job'], 'someone-else' ) );
		$this->assertTrue( $this->repo->heartbeat( $first['job'], $first['token'], 200 ) );
		$this->assertSame( $this->now + 200, $this->repo->find( $job->id )->locked_until );
		$this->assertSame( $this->now + 200, LockFile::read( $lock )['locked_until'], 'the lock file follows the lease' );

		$this->assertTrue( $this->repo->release( $first['job'], $first['token'] ) );
		$this->assertFileExists( $lock, 'the lock file covers the whole running phase, not only the lease' );
		$this->assertSame( Job::RUNNING, $this->repo->find( $job->id )->status, 'still in progress between ticks' );

		$second = $this->repo->acquire( $job->id, 100 );
		$this->assertNotNull( $second, 'released locks can be taken again' );
		$this->assertSame( 1, $second['job']->attempts, 'attempts only counts starts from queued' );
		$this->assertTrue( LockFile::is_owned_by( $lock, $second['token'] ) );

		$this->repo->transition( $second['job'], Job::COMPLETED, '', $second['token'] );
		$this->assertFileDoesNotExist( $lock, 'removed with the terminal status' );
	}

	public function test_repeated_writes_within_the_same_second_keep_the_lock(): void {
		// MySQL reports changed rows, not matched rows; the fake clock never moves here.
		$job  = $this->repo->create( 'export' );
		$held = $this->repo->acquire( $job->id, 100 );
		$this->assertTrue( $this->repo->heartbeat( $held['job'], $held['token'], 100 ) );
		$this->assertTrue( $this->repo->heartbeat( $held['job'], $held['token'], 100 ), 'an identical heartbeat is still ours' );
		$this->repo->save_progress( $held['job'], $held['token'], 'db', array( 'offset' => 1 ), 10 );
		$this->repo->save_progress( $held['job'], $held['token'], 'db', array( 'offset' => 1 ), 10 );
		$this->assertSame( array( 'offset' => 1 ), $this->repo->find( $job->id )->cursor );
	}

	public function test_expired_lock_can_be_taken_over_and_the_old_holder_is_fenced_off(): void {
		$job   = $this->repo->create( 'export' );
		$first = $this->repo->acquire( $job->id, 100 );
		$this->assertNotNull( $first );
		$this->repo->save_progress( $first['job'], $first['token'], 'db', array( 'offset' => 100 ), 10 );

		$this->now += 101;
		$second     = $this->repo->acquire( $job->id, 100 );
		$this->assertNotNull( $second, 'the expired lock is taken over' );
		$this->assertNotSame( $first['token'], $second['token'] );
		$lock = LockFile::path( $this->base, $job->id );
		$this->assertTrue( LockFile::is_owned_by( $lock, $second['token'] ) );
		$this->assertFalse( LockFile::is_owned_by( $lock, $first['token'] ) );
		$this->assertFalse( $this->repo->heartbeat( $first['job'], $first['token'] ), 'the old holder lost the lock' );
		$this->assertFalse( $this->repo->release( $first['job'], $first['token'] ) );
		$this->assertFileExists( $lock, 'the old holder cannot remove the new holder\'s lock file' );

		$this->repo->save_progress( $second['job'], $second['token'], 'db', array( 'offset' => 200 ), 20 );
		try {
			$this->repo->save_progress( $first['job'], $first['token'], 'db', array( 'offset' => 100 ), 10 );
			$this->fail( 'expected StaleJob' );
		} catch ( StaleJob $e ) {
			$this->assertSame( array( 'offset' => 200 ), $this->repo->find( $job->id )->cursor, 'the old holder cannot rewind the cursor' );
		}
		try {
			$this->repo->transition( $first['job'], Job::COMPLETED, '', $first['token'] );
			$this->fail( 'expected StaleJob' );
		} catch ( StaleJob $e ) {
			$this->assertSame( Job::RUNNING, $this->repo->find( $job->id )->status, 'the old holder cannot finish the job' );
		}
		try {
			$this->repo->transition( $first['job'], Job::FAILED, 'boom', $first['token'] );
			$this->fail( 'expected StaleJob' );
		} catch ( StaleJob $e ) {
			$this->assertSame( Job::RUNNING, $this->repo->find( $job->id )->status );
		}
		$this->assertFileExists( $lock );

		$this->repo->transition( $second['job'], Job::COMPLETED, '', $second['token'] );
		$this->assertSame( Job::COMPLETED, $this->repo->find( $job->id )->status );
		$this->assertFileDoesNotExist( $lock );
	}

	public function test_leaving_running_needs_the_token_except_for_cancel(): void {
		$job  = $this->repo->create( 'export' );
		$held = $this->repo->acquire( $job->id );
		foreach ( array( Job::COMPLETED, Job::FAILED, Job::PAUSED ) as $to ) {
			try {
				$this->repo->transition( $this->repo->find( $job->id ), $to );
				$this->fail( 'expected InvalidTransition for ' . $to );
			} catch ( InvalidTransition $e ) {
				$this->assertStringContainsString( 'requires the lock token', $e->getMessage() );
			}
		}
		$this->assertSame( Job::RUNNING, $this->repo->find( $job->id )->status );
		$this->repo->transition( $held['job'], Job::PAUSED, '', $held['token'] );
		$this->assertSame( Job::PAUSED, $this->repo->find( $job->id )->status );
		$this->assertFileExists( LockFile::path( $this->base, $job->id ), 'paused is not terminal' );
		$this->repo->transition( $this->repo->find( $job->id ), Job::RUNNING );
		$this->assertSame( Job::RUNNING, $this->repo->find( $job->id )->status, 'paused to running needs no token; the lock is taken by acquire()' );

		// An administrator cancels a running job without the lock; the holder notices at its next write.
		$held = $this->repo->acquire( $job->id );
		$this->assertNotNull( $held );
		$this->repo->transition( $this->repo->find( $job->id ), Job::CANCELLED );
		$this->assertFalse( $this->repo->heartbeat( $held['job'], $held['token'] ) );
		$this->expectException( StaleJob::class );
		$this->repo->save_progress( $held['job'], $held['token'], 'db', array(), 1 );
	}

	public function test_transitions_are_guarded_by_the_expected_status(): void {
		$job = $this->repo->create( 'export' );
		$this->assertSame( Job::CANCELLED, $this->repo->transition( $job, Job::CANCELLED )->status );
		$this->assertSame( $this->now, $this->repo->find( $job->id )->finished_at );

		$job    = $this->repo->create( 'export' );
		$held   = $this->repo->acquire( $job->id );
		$loaded = $this->repo->find( $job->id );
		$stale  = $this->repo->find( $job->id );
		$this->repo->transition( $loaded, Job::CANCELLED );
		$this->assertFileDoesNotExist( LockFile::path( $this->base, $job->id ), 'terminal states drop the lock file' );
		try {
			$this->repo->transition( $stale, Job::FAILED, 'boom', $held['token'] );
			$this->fail( 'expected StaleJob' );
		} catch ( StaleJob $e ) {
			$this->assertSame( Job::CANCELLED, $this->repo->find( $job->id )->status );
		}

		$this->expectException( InvalidTransition::class );
		$this->repo->transition( $this->repo->find( $job->id ), Job::RUNNING );
	}

	public function test_concurrent_cancel_between_load_and_write_is_detected(): void {
		$job     = $this->repo->create( 'export' );
		$held    = $this->repo->acquire( $job->id );
		$running = $this->repo->find( $job->id );
		$repo    = $this->repo;
		$done    = false;
		add_filter( 'query', function ( string $sql ) use ( $repo, $job, &$done ): string {
			// Interleave: the first UPDATE guarded on "running" is preceded by a cancel from another driver.
			if ( ! $done && false !== strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, "'completed'" ) ) {
				$done = true;
				$repo->transition( $repo->find( $job->id ), Job::CANCELLED );
			}
			return $sql;
		} );
		try {
			$this->expectException( StaleJob::class );
			$this->repo->transition( $running, Job::COMPLETED, '', $held['token'] );
		} finally {
			remove_all_filters( 'query' );
			$this->assertSame( Job::CANCELLED, $this->repo->find( $job->id )->status );
		}
	}

	public function test_failed_jobs_can_be_retried_keeping_the_cursor(): void {
		$job  = $this->repo->create( 'export' );
		$held = $this->repo->acquire( $job->id );
		$job  = $this->repo->find( $job->id );
		$this->repo->save_progress( $job, $held['token'], 'db', array( 'table' => 'wp_posts', 'offset' => 500 ), 40, 'half way' );
		$this->repo->transition( $job, Job::FAILED, 'disk full at ' . DB_PASSWORD, $held['token'] );
		$stored = $this->repo->find( $job->id );
		$this->assertStringNotContainsString( DB_PASSWORD, $stored->last_error, 'errors are redacted before storing' );
		$this->assertSame( 40, $stored->progress );
		$this->assertFileDoesNotExist( LockFile::path( $this->base, $job->id ), 'failed drops the lock file' );

		$this->repo->transition( $stored, Job::QUEUED );
		$again = $this->repo->find( $job->id );
		$this->assertSame( Job::QUEUED, $again->status );
		$this->assertSame( array( 'table' => 'wp_posts', 'offset' => 500 ), $again->cursor );
		$this->assertSame( 'db', $again->step );
		$this->assertSame( 0, $again->finished_at );
		$second = $this->repo->acquire( $job->id );
		$this->assertSame( 2, $second['job']->attempts );
		$this->assertFileExists( LockFile::path( $this->base, $job->id ), 'written again on the retry' );
	}

	public function test_storage_gate_blocks_with_back_off_and_stalls_fail_after_a_day(): void {
		$job = $this->repo->create( 'export' );
		$this->assertSame( array( 'allowed' => true, 'reason' => '', 'message' => '', 'retry_after' => 0 ), $this->repo->gate( $job ) );

		// Storage becomes unusable: the custom directory cannot be created because a file is in the way.
		file_put_contents( $this->root . '/notadir', 'x' );
		$broken = new Directories( array( 'is_web_request' => false, 'document_root' => '', 'custom_dir' => $this->root . '/notadir' ) );
		$repo   = $this->repo_for( $broken );
		$this->assertSame( '', $broken->base() );
		$gate = $repo->gate( $job );
		$this->assertFalse( $gate['allowed'] );
		$this->assertSame( 'storage_unavailable', $gate['reason'] );
		$this->assertSame( 5, $gate['retry_after'] );
		$this->assertNull( $repo->acquire( $job->id ), 'no storage, no lock' );
		foreach ( array( 15, 60, 300, 300, 300 ) as $expected ) {
			$this->assertSame( $expected, $repo->record_blocked( $job ) );
		}
		$this->assertSame( 300, $repo->gate( $this->repo->find( $job->id ) )['retry_after'] );

		// Progress resets the back-off.
		$held = $this->repo->acquire( $job->id );
		$this->repo->save_progress( $held['job'], $held['token'], 's', array(), 1 );
		$this->assertSame( 0, $this->repo->find( $job->id )->blocked_count );
		$this->assertSame( 5, $repo->gate( $this->repo->find( $job->id ) )['retry_after'], 'the sequence starts over' );
		$this->repo->release( $held['job'], $held['token'] );

		// No progress for 24 hours: given up, with a message that tells whether it ever started.
		$never = $this->repo->create( 'export' );
		$this->now += JobRepository::STALL_SECONDS + 1;
		$this->repo->reap();
		$failed = $this->repo->find( $job->id );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertStringContainsString( 'No progress for 24 hours', $failed->last_error );
		$this->assertFileDoesNotExist( LockFile::path( $this->base, $job->id ) );
		$this->assertSame( Job::FAILED, $this->repo->find( $never->id )->status );
		$this->assertStringContainsString( 'Queued for 24 hours without starting', $this->repo->find( $never->id )->last_error );
	}

	public function test_a_locked_job_is_never_treated_as_stalled(): void {
		$job = $this->repo->create( 'export' );
		$this->now += JobRepository::STALL_SECONDS + 1;
		$held = $this->repo->acquire( $job->id, 1000 );
		$this->assertNotNull( $held );
		$this->repo->reap();
		$this->assertSame( Job::RUNNING, $this->repo->find( $job->id )->status );
	}

	public function test_acquire_refuses_jobs_bound_to_another_directory(): void {
		$old = $this->repo->create( 'export' );
		$next = $this->site( 'releases/b' );
		$next->base();
		$this->assertTrue( $next->state()['clone_detected'] );
		$repo = $this->repo_for( $next );
		$this->assertFalse( $repo->gate( $old )['allowed'] );
		$this->assertNull( $repo->acquire( $old->id ), 'the storage token is part of the compare-and-set' );
		$this->assertSame( Job::QUEUED, $this->repo->find( $old->id )->status );
		$this->assertFileDoesNotExist( LockFile::path( $next->base(), $old->id ) );
		$this->assertFileDoesNotExist( LockFile::path( $this->base, $old->id ) );
	}

	public function test_jobs_of_a_replaced_directory_fail_when_the_clone_notice_is_dismissed(): void {
		$old  = $this->repo->create( 'export' );
		$held = $this->repo->acquire( $old->id );
		$this->repo->release( $held['job'], $held['token'] );
		$this->assertFileExists( LockFile::path( $this->base, $old->id ) );

		$next = $this->site( 'releases/b' );
		$next->base();
		$this->assertTrue( $next->state()['clone_detected'] );
		$repo = $this->repo_for( $next );
		$gate = $repo->gate( $this->repo->find( $old->id ) );
		$this->assertFalse( $gate['allowed'] );
		$this->assertSame( 'storage_changed', $gate['reason'] );
		$this->assertStringContainsString( 'clone notice', $gate['message'] );
		$this->assertSame( 0, $repo->settle_storage(), 'nothing is settled while the notice is pending' );

		( new Notices( $next ) )->record_dismissal( 'clone_detected' );

		$failed = $repo->find( $old->id );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertStringContainsString( 'storage directory changed', $failed->last_error );
		$this->assertSame( '', $failed->failure_kind, 'the change can be undone and the work files are intact: Retry stays offered' );
		$this->assertFileExists( LockFile::path( $this->base, $old->id ), 'files in another directory are never touched' );
		$this->assertStringContainsString( 'Job ' . $old->id . ' is bound to another storage directory', (string) file_get_contents( $next->base() . '/logs/storage.log' ) );
	}

	public function test_jobs_created_during_the_clone_fail_when_the_original_directory_is_reclaimed(): void {
		$next = $this->site( 'releases/b' );
		$next->base();
		$repo_new = $this->repo_for( $next );
		$during   = $repo_new->create( 'export' );
		$held     = $repo_new->acquire( $during->id );
		$repo_new->release( $held['job'], $held['token'] );
		$this->assertTrue( StorageReclaim::is_busy( $next->base() ), 'the lock file stays between ticks' );

		$token  = ReclaimActions::expected_token( $this->base );
		$result = ( new ReclaimActions( $next ) )->run_reclaim( true, $token, false );
		$this->assertTrue( $result['ok'], $result['message'] );

		$failed = $repo_new->find( $during->id );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertSame( $this->base, $next->base() );
		$this->assertFileExists( LockFile::path( $during->storage_path, $during->id ), 'the abandoned directory is left alone' );
	}

	public function test_orphaned_lock_files_are_reaped_and_live_ones_kept(): void {
		$job = $this->repo->create( 'export' );
		// is_busy() judges with the real clock; the repository below uses the fake one.
		LockFile::write( $this->base, $job->id, 'crashed', time() - StorageReclaim::ACTIVITY_WINDOW - 1 );
		$this->assertFalse( StorageReclaim::is_busy( $this->base ), 'a long expired lease is not a running job' );
		LockFile::write( $this->base, $job->id, 'fresh', time() + 60 );
		$this->assertTrue( StorageReclaim::is_busy( $this->base ) );

		$done = $this->repo->create( 'export' );
		$this->repo->transition( $done, Job::CANCELLED );
		LockFile::write( $this->base, 424242, 'orphan', $this->now + 60 );
		LockFile::write( $this->base, $done->id, 'leftover', $this->now + 60 );
		LockFile::write( $this->base, $job->id, 'crashed', $this->now - 100000 );
		$this->repo->reap();
		$this->assertFileDoesNotExist( LockFile::path( $this->base, 424242 ), 'no such job' );
		$this->assertFileDoesNotExist( LockFile::path( $this->base, $done->id ), 'terminal job' );
		$this->assertFileExists( LockFile::path( $this->base, $job->id ), 'kept until the job leaves queued, running or paused' );
	}

	public function test_purge_respects_retention_and_the_row_cap_and_removes_logs(): void {
		$keep_running = $this->repo->create( 'export' );
		$this->repo->acquire( $keep_running->id );
		$old_done = $this->repo->create( 'export' );
		$held     = $this->repo->acquire( $old_done->id );
		$this->repo->transition( $this->repo->find( $old_done->id ), Job::COMPLETED, '', $held['token'] );
		$old_failed = $this->repo->create( 'export' );
		$held       = $this->repo->acquire( $old_failed->id );
		$this->repo->transition( $this->repo->find( $old_failed->id ), Job::FAILED, 'x', $held['token'] );
		foreach ( array( $keep_running, $old_done, $old_failed ) as $j ) {
			file_put_contents( $this->base . '/' . $this->repo->find( $j->id )->log_path, "log\n" );
		}

		$this->now += 31 * 86400;
		$this->assertSame( 1, $this->repo->purge(), 'completed after 30 days' );
		$this->assertNull( $this->repo->find( $old_done->id ) );
		$this->assertFileDoesNotExist( $this->base . '/' . $old_done->log_path );
		$this->assertNotNull( $this->repo->find( $old_failed->id ), 'failed kept for 90 days' );

		$this->now += 60 * 86400;
		$this->assertSame( 1, $this->repo->purge(), 'failed after 90 days' );
		$this->assertNotNull( $this->repo->find( $keep_running->id ), 'running jobs and their logs are never purged' );
		$this->assertFileExists( $this->base . '/' . $this->repo->find( $keep_running->id )->log_path );
	}

	public function test_purge_leaves_logs_in_another_directory_alone(): void {
		global $wpdb;
		$other = $this->root . '/elsewhere';
		mkdir( $other . '/logs', 0755, true );
		file_put_contents( $other . '/logs/job-9-deadbeef.log', "foreign\n" );
		$wpdb->insert( Schema::jobs_table(), array( 'id' => 9, 'type' => 'x', 'status' => Job::COMPLETED, 'storage_path' => $other, 'log_path' => 'logs/job-9-deadbeef.log', 'created_at' => 1, 'finished_at' => 1 ), array( '%d', '%s', '%s', '%s', '%s', '%d', '%d' ) );
		$this->assertSame( 1, $this->repo->purge() );
		$this->assertNull( $this->repo->find( 9 ) );
		$this->assertFileExists( $other . '/logs/job-9-deadbeef.log' );
	}

	public function test_purge_row_cap_keeps_the_newest(): void {
		global $wpdb;
		$table = Schema::jobs_table();
		for ( $i = 0; $i < JobRepository::MAX_ROWS + 5; $i++ ) {
			$wpdb->insert( $table, array( 'type' => 'x', 'status' => Job::COMPLETED, 'created_at' => $i, 'finished_at' => $this->now ), array( '%s', '%s', '%d', '%d' ) );
		}
		$this->assertSame( 5, $this->repo->purge() );
		$this->assertSame( JobRepository::MAX_ROWS, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		$this->assertSame( 5, (int) $wpdb->get_var( "SELECT MIN(created_at) FROM {$table}" ), 'the oldest rows went first' );
	}

	public function test_maintenance_is_throttled(): void {
		$job = $this->repo->create( 'export' );
		$this->now += JobRepository::STALL_SECONDS + 1;
		$this->repo->maintenance();
		$this->assertSame( Job::FAILED, $this->repo->find( $job->id )->status );

		$another = $this->repo->create( 'export' );
		$this->now += JobRepository::STALL_SECONDS + 1;
		$this->repo->maintenance();
		$this->assertSame( Job::QUEUED, $this->repo->find( $another->id )->status, 'reaped at most once per throttle window' );
	}

	public function test_uninstall_cancels_live_jobs_and_removes_their_lock_files(): void {
		$running = $this->repo->create( 'export' );
		$held    = $this->repo->acquire( $running->id );
		$queued  = $this->repo->create( 'export' );
		$done    = $this->repo->create( 'export' );
		$this->repo->transition( $done, Job::CANCELLED );
		$lock = LockFile::path( $this->base, $running->id );
		$this->assertFileExists( $lock );

		$this->assertFalse( Uninstaller::should_delete_data() );
		Uninstaller::run();

		$this->assertTrue( Schema::table_exists(), 'the table is kept without the opt-in' );
		$this->assertSame( Job::CANCELLED, $this->repo->find( $running->id )->status );
		$this->assertSame( Job::CANCELLED, $this->repo->find( $queued->id )->status );
		$this->assertFileDoesNotExist( $lock );
		$this->assertFalse( $this->repo->heartbeat( $held['job'], $held['token'] ), 'a driver still holding the lock is stopped' );
		$this->assertSame( 0, Uninstaller::cancel_jobs(), 'nothing left to cancel' );
	}

	public function test_multisite_table_is_network_wide_and_records_the_site(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$subsite = self::factory()->blog->create();
		switch_to_blog( $subsite );
		try {
			$this->assertSame( $wpdb->base_prefix . 'wpcheckpoint_jobs', Schema::jobs_table(), 'not the sub-site prefix' );
			$job = $this->repo->create( 'export' );
			$this->assertSame( $subsite, $job->site_id );
		} finally {
			restore_current_blog();
		}
		$this->assertSame( $job->id, $this->repo->find( $job->id )->id, 'visible from the main site' );
	}

	/**
	 * A failed job with a work directory and a temporary table.
	 *
	 * @return array{0: Job, 1: string, 2: string} Job, work directory, table name.
	 */
	private function failed_job_with_work( string $type = 'export' ): array {
		global $wpdb;
		$job  = $this->repo->create( $type );
		$held = $this->repo->acquire( $job->id );
		$job  = $this->repo->transition( $this->repo->find( $job->id ), Job::FAILED, 'boom', $held['token'] );
		$dir  = Residue::work_dir( $this->base, $job->id );
		mkdir( $dir . '/sub', 0700, true );
		file_put_contents( $dir . '/site.part001.wpcheckpoint.zip.partial', 'x' );
		file_put_contents( $dir . '/sub/f.txt', 'y' );
		$table = TempTables::name( $this->dirs->state()['token'], $job->id, 'beef', 'posts' );
		$wpdb->query( "CREATE TABLE `{$table}` (id int)" );
		return array( $job, $dir, $table );
	}

	/**
	 * Drop tables whatever keys tie them (test cleanup).
	 */
	private function force_drop( array $tables ): void {
		global $wpdb;
		$wpdb->query( 'SET SESSION foreign_key_checks = 0' );
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$wpdb->query( 'SET SESSION foreign_key_checks = 1' );
	}

	private function table_exists( string $name ): bool {
		global $wpdb;
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
	}

	public function test_failed_jobs_keep_their_work_for_seven_days_then_lose_it_and_the_right_to_a_retry(): void {
		list( $job, $dir, $table ) = $this->failed_job_with_work();
		file_put_contents( $this->base . '/' . $job->log_path, "log\n" );

		$this->now += 6 * 86400;
		$this->assertSame( 0, $this->repo->purge() );
		$this->assertDirectoryExists( $dir, 'kept for a retry' );
		$this->assertTrue( $this->table_exists( $table ) );
		$this->assertTrue( $this->repo->find( $job->id )->can_retry() );

		$this->now += 2 * 86400;
		$this->assertSame( 0, $this->repo->purge(), 'the row stays for 90 days' );
		$stored = $this->repo->find( $job->id );
		$this->assertNotNull( $stored );
		$this->assertSame( Job::FAILED, $stored->status );
		$this->assertSame( $this->now, $stored->work_expired_at );
		$this->assertFalse( $stored->can_retry() );
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertFalse( $this->table_exists( $table ) );
		$this->assertFileExists( $this->base . '/' . $job->log_path, 'the log stays with the row' );
		try {
			$this->repo->transition( $stored, Job::QUEUED );
			$this->fail( 'a job whose work files are gone must not be queued again' );
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'retention', $e->getMessage() );
		}
		$this->assertSame( Job::FAILED, $this->repo->find( $job->id )->status );
		$this->assertSame( 0, $this->repo->expire_work(), 'idempotent' );

		$this->now += 90 * 86400;
		$this->assertSame( 1, $this->repo->purge() );
		$this->assertNull( $this->repo->find( $job->id ) );
	}

	public function test_a_retried_job_within_retention_finds_its_files_and_the_marker_is_written_before_the_files_go(): void {
		Permissions::require_enforced(); // The work directory is made unwritable below.
		list( $job, $dir, $table ) = $this->failed_job_with_work();
		$queued = $this->repo->transition( $job, Job::QUEUED );
		$this->assertSame( Job::QUEUED, $queued->status );
		$this->assertFileExists( $dir . '/sub/f.txt' );
		$this->assertTrue( $this->table_exists( $table ) );

		// Fail again, let retention pass, and make the deletion fail: the row is marked all the same.
		$held = $this->repo->acquire( $job->id );
		$this->repo->transition( $this->repo->find( $job->id ), Job::FAILED, 'again', $held['token'] );
		$this->now += 8 * 86400;
		chmod( $dir, 0500 );
		try {
			$this->repo->purge();
		} finally {
			chmod( $dir, 0700 );
		}
		$this->assertGreaterThan( 0, $this->repo->find( $job->id )->work_expired_at, 'marked although the files could not be removed' );
		$this->assertFalse( $this->repo->find( $job->id )->can_retry() );
		$this->assertDirectoryExists( $dir );
		$this->assertStringContainsString( 'could not be deleted', (string) file_get_contents( $this->base . '/logs/storage.log' ) );
		// Now deletable: the next reap treats it as an orphan and finishes the job.
		$this->repo->reap();
		$this->assertDirectoryDoesNotExist( $dir );
		$this->assertFalse( $this->table_exists( $table ) );
	}

	public function test_reap_removes_orphans_by_the_catalogue_rules_and_leaves_live_work_alone(): void {
		global $wpdb;
		$token = $this->dirs->state()['token'];
		$tmp   = $this->base . '/tmp';

		// Live: a running job's work directory and table.
		$running = $this->repo->create( 'export' );
		$this->repo->acquire( $running->id );
		mkdir( Residue::work_dir( $this->base, $running->id ) );
		touch( Residue::work_dir( $this->base, $running->id ) . '/v.partial' );
		$live_table = TempTables::name( $token, $running->id, 'beef', 'posts' );
		$wpdb->query( "CREATE TABLE `{$live_table}` (id int)" );
		// Failed within retention: kept.
		list( $recent, $recent_dir, $recent_table ) = $this->failed_job_with_work();
		// Orphans: no such job, a cancelled job, a job bound to another directory; another installation's table is not ours.
		mkdir( $tmp . '/job-424242' );
		touch( $tmp . '/job-424242/x' );
		$wpdb->query( 'CREATE TABLE `' . TempTables::name( $token, 424242, 'beef', 'gone' ) . '` (id int)' );
		$done = $this->repo->create( 'export' );
		$this->repo->transition( $done, Job::CANCELLED );
		mkdir( Residue::work_dir( $this->base, $done->id ) );
		$foreign = $this->repo->create( 'export' );
		$wpdb->update( Schema::jobs_table(), array( 'storage_token' => 'ffffffffffff' ), array( 'id' => $foreign->id ) );
		mkdir( Residue::work_dir( $this->base, $foreign->id ) );
		$other_site = 'wcptmpffffff_1_beef_theirs';
		$wpdb->query( "CREATE TABLE `{$other_site}` (id int)" );
		// Unowned: verification directories and stray files by age.
		mkdir( $tmp . '/verify-00000000deadbeef' );
		touch( $tmp . '/verify-00000000deadbeef/files.index.jsonl', time() - Residue::VERIFY_TTL - 60 );
		touch( $tmp . '/verify-00000000deadbeef', time() - Residue::VERIFY_TTL - 60 );
		mkdir( $tmp . '/verify-0000000000c0ffee' );
		touch( $tmp . '/old.partial', time() - Residue::STRAY_TTL - 60 );
		touch( $tmp . '/new.cdr' );
		touch( $tmp . '/young.partial', time() - Residue::VERIFY_TTL - 60 );
		$this->now = time();

		$this->repo->reap();

		$this->assertDirectoryExists( Residue::work_dir( $this->base, $running->id ) );
		$this->assertTrue( $this->table_exists( $live_table ) );
		$this->assertDirectoryExists( $recent_dir );
		$this->assertTrue( $this->table_exists( $recent_table ) );
		$this->assertDirectoryDoesNotExist( $tmp . '/job-424242' );
		$this->assertFalse( $this->table_exists( TempTables::name( $token, 424242, 'beef', 'gone' ) ) );
		$this->assertDirectoryDoesNotExist( Residue::work_dir( $this->base, $done->id ) );
		$this->assertDirectoryDoesNotExist( Residue::work_dir( $this->base, $foreign->id ), 'a job bound elsewhere never owned files here' );
		$this->assertTrue( $this->table_exists( $other_site ), 'another installation sharing the database keeps its tables' );
		$wpdb->query( "DROP TABLE `{$other_site}`" );
		$this->assertDirectoryDoesNotExist( $tmp . '/verify-00000000deadbeef' );
		$this->assertDirectoryExists( $tmp . '/verify-0000000000c0ffee', 'a verification may still be running' );
		$this->assertFileDoesNotExist( $tmp . '/old.partial' );
		$this->assertFileExists( $tmp . '/new.cdr' );
		$this->assertFileExists( $tmp . '/young.partial', 'stray files wait seven days' );
		$this->assertFileExists( LockFile::path( $this->base, $running->id ) );
	}

	/**
	 * Temporary tables of a job with foreign keys among them: a parent whose name sorts before its child's, a
	 * cycle, a key of a table to itself. One pass drops them all, and the session's checks are as they were.
	 */
	public function test_temporary_tables_with_foreign_keys_among_them_go_in_one_pass(): void {
		global $wpdb;
		list( $job, , $plain ) = $this->failed_job_with_work();
		$token                 = $this->dirs->state()['token'];
		$name                  = static function ( string $table ) use ( $token, $job ): string {
			return TempTables::name( $token, $job->id, 'beef', $table );
		};
		$wpdb->query( 'CREATE TABLE `' . $name( 'a_parent' ) . '` (id INT PRIMARY KEY) ENGINE=InnoDB' );
		$wpdb->query( 'CREATE TABLE `' . $name( 'z_child' ) . '` (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES `' . $name( 'a_parent' ) . '` (id)) ENGINE=InnoDB' );
		$wpdb->query( 'CREATE TABLE `' . $name( 'c_one' ) . '` (id INT PRIMARY KEY, two INT) ENGINE=InnoDB' );
		$wpdb->query( 'CREATE TABLE `' . $name( 'c_two' ) . '` (id INT PRIMARY KEY, one INT, FOREIGN KEY (one) REFERENCES `' . $name( 'c_one' ) . '` (id)) ENGINE=InnoDB' );
		$wpdb->query( 'ALTER TABLE `' . $name( 'c_one' ) . '` ADD FOREIGN KEY (two) REFERENCES `' . $name( 'c_two' ) . '` (id)' );
		$wpdb->query( 'CREATE TABLE `' . $name( 's_self' ) . '` (id INT PRIMARY KEY, up INT, FOREIGN KEY (up) REFERENCES `' . $name( 's_self' ) . '` (id)) ENGINE=InnoDB' );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( 4, (int) $wpdb->get_var( "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wcptmp%'" ), 'the control: the keys are there' );

		$all = array( $plain, $name( 'a_parent' ), $name( 'z_child' ), $name( 'c_one' ), $name( 'c_two' ), $name( 's_self' ) );
		try {
			$this->assertTrue( $this->repo->reclaim_work( $job ), 'every table went' );
			foreach ( $all as $table ) {
				$this->assertFalse( $this->table_exists( $table ), $table );
			}
			$this->assertSame( '1', (string) $wpdb->get_var( 'SELECT @@SESSION.foreign_key_checks' ), 'checks back on for the session' );
		} finally {
			$this->force_drop( $all );
		}
	}

	/**
	 * The same for the orphans a reap finds (a job that no longer exists).
	 */
	public function test_orphaned_temporary_tables_with_foreign_keys_go_in_one_pass(): void {
		global $wpdb;
		$token  = $this->dirs->state()['token'];
		$parent = TempTables::name( $token, 424242, 'beef', 'a_parent' );
		$child  = TempTables::name( $token, 424242, 'beef', 'z_child' );
		$wpdb->query( "CREATE TABLE `{$parent}` (id INT PRIMARY KEY) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$child}` (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES `{$parent}` (id)) ENGINE=InnoDB" );
		$this->assertTrue( $this->table_exists( $parent ) && $this->table_exists( $child ), 'the control: created' );
		try {
			$this->repo->reap();
			$this->assertFalse( $this->table_exists( $child ) );
			$this->assertFalse( $this->table_exists( $parent ), 'in the same pass' );
		} finally {
			$this->force_drop( array( $child, $parent ) );
		}
	}

	/**
	 * A temporary table a table outside the job has a key to stays (dropping it would leave that key pointing
	 * at nothing), with what it references; the storage log names the table and the one that references it.
	 * The job's other tables go.
	 */
	public function test_a_temporary_table_another_table_has_a_key_to_is_kept_and_named(): void {
		global $wpdb;
		list( $job, , $plain ) = $this->failed_job_with_work();
		$token                 = $this->dirs->state()['token'];
		$p                     = TempTables::name( $token, $job->id, 'beef', 'p' );
		$q                     = TempTables::name( $token, $job->id, 'beef', 'q' );
		$r                     = TempTables::name( $token, $job->id, 'beef', 'r' );
		$outside               = $wpdb->base_prefix . 'wpcfk_outside';
		$wpdb->query( "CREATE TABLE `{$q}` (id INT PRIMARY KEY) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$p}` (id INT PRIMARY KEY, q INT, FOREIGN KEY (q) REFERENCES `{$q}` (id)) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$r}` (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES `{$p}` (id)) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$outside}` (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES `{$p}` (id)) ENGINE=InnoDB" );
		try {
			$this->assertFalse( $this->repo->reclaim_work( $job ), 'a table left behind is not success' );
			$this->assertTrue( $this->table_exists( $p ) );
			$this->assertTrue( $this->table_exists( $q ), 'p references it' );
			$this->assertFalse( $this->table_exists( $r ), 'the control: a child of p goes' );
			$this->assertFalse( $this->table_exists( $plain ), 'the control: the rest goes' );
			$log = (string) file_get_contents( $this->base . '/logs/storage.log' );
			$this->assertStringContainsString( "temporary tables of job {$job->id}: {$p} is kept; a foreign key of {$outside}, which stays, references it.", $log );
			$this->assertStringContainsString( "{$q} is kept; a foreign key of {$p}, which stays, references it.", $log );
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS `{$outside}`, `{$r}`" );
			$wpdb->query( "DROP TABLE IF EXISTS `{$p}`" );
			$wpdb->query( "DROP TABLE IF EXISTS `{$q}`" );
		}
	}

	/**
	 * Uninstall drops the temporary tables the same way.
	 */
	public function test_uninstall_drops_temporary_tables_with_foreign_keys_among_them(): void {
		global $wpdb;
		$token  = $this->dirs->state()['token'];
		$parent = TempTables::name( $token, 5, 'beef', 'a_parent' );
		$child  = TempTables::name( $token, 5, 'beef', 'z_child' );
		$wpdb->query( "CREATE TABLE `{$parent}` (id INT PRIMARY KEY) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$child}` (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES `{$parent}` (id)) ENGINE=InnoDB" );
		$this->assertTrue( $this->table_exists( $parent ), 'the control: created' );
		try {
			Schema::drop();
			$this->assertFalse( $this->table_exists( $child ) );
			$this->assertFalse( $this->table_exists( $parent ) );
		} finally {
			$this->force_drop( array( $child, $parent ) );
		}
	}

	/**
	 * Checks on means on, whatever the session had: with checks turned off earlier in the request by other code
	 * and the keys unreadable (so every table counts as free), a temporary table another table has a key to is
	 * not dropped, and the session's setting is put back afterwards. The control: an unreferenced table goes.
	 */
	public function test_checks_are_on_for_the_drops_whatever_the_session_had(): void {
		global $wpdb;
		list( $job, , $plain ) = $this->failed_job_with_work();
		$p                     = TempTables::name( $this->dirs->state()['token'], $job->id, 'beef', 'p' );
		$outside               = $wpdb->base_prefix . 'wpcfk_outside';
		$wpdb->query( "CREATE TABLE `{$p}` (id INT PRIMARY KEY) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$outside}` (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES `{$p}` (id)) ENGINE=InnoDB" );
		$break = static function ( string $query ): string {
			return false !== strpos( $query, 'REFERENTIAL_CONSTRAINTS' ) ? 'SELECT * FROM wpcfk_no_such_table' : $query;
		};
		add_filter( 'query', $break );
		$wpdb->query( 'SET SESSION foreign_key_checks = 0' );
		$suppressed = $wpdb->suppress_errors();
		try {
			$this->assertFalse( $this->repo->reclaim_work( $job ) );
			$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT @@SESSION.foreign_key_checks' ), 'the session\'s setting is back' );
			$wpdb->query( 'SET SESSION foreign_key_checks = 1' );
			$this->assertFalse( $this->table_exists( $plain ), 'the control: an unreferenced table went' );
			$this->assertTrue( $this->table_exists( $p ), 'the referenced one did not' );
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_filter( 'query', $break );
			$this->force_drop( array( $outside, $p ) );
		}
	}

	/**
	 * A table of another database with a key to a temporary table: kept, and the log does not name that
	 * database (on shared hosts its name holds the account's).
	 */
	public function test_a_key_from_another_database_keeps_the_table_and_the_log_names_no_database(): void {
		global $wpdb;
		list( $job, , $plain ) = $this->failed_job_with_work();
		$p                     = TempTables::name( $this->dirs->state()['token'], $job->id, 'beef', 'p' );
		$other                 = 'wpcfk_other_' . bin2hex( random_bytes( 3 ) );
		$here                  = (string) $wpdb->get_var( 'SELECT DATABASE()' );
		$wpdb->query( "CREATE TABLE `{$p}` (id INT PRIMARY KEY) ENGINE=InnoDB" );
		$wpdb->query( "CREATE DATABASE `{$other}`" );
		$wpdb->query( "CREATE TABLE `{$other}`.`t` (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES `{$here}`.`{$p}` (id)) ENGINE=InnoDB" );
		$this->assertSame( '', $wpdb->last_error, 'the control: the key across databases exists' );
		try {
			$this->assertFalse( $this->repo->reclaim_work( $job ) );
			$this->assertTrue( $this->table_exists( $p ) );
			$this->assertFalse( $this->table_exists( $plain ), 'the control: the rest went' );
			$log = (string) file_get_contents( $this->base . '/logs/storage.log' );
			$this->assertStringContainsString( "{$p} is kept; a foreign key of a table in another database, which stays, references it.", $log );
			$this->assertStringNotContainsString( $other, $log );
		} finally {
			$wpdb->query( "DROP DATABASE IF EXISTS `{$other}`" );
			$this->force_drop( array( $p ) );
		}
	}

	/**
	 * The keys are read again right before a cycle goes with checks off: a table outside it that has a key to
	 * a member by then holds the cycle back for this pass (no key is left pointing at nothing).
	 */
	public function test_a_key_that_appears_before_a_cycle_goes_holds_it_back(): void {
		global $wpdb;
		list( $job, , $plain ) = $this->failed_job_with_work();
		$token       = $this->dirs->state()['token'];
		$one         = TempTables::name( $token, $job->id, 'beef', 'c_one' );
		$two         = TempTables::name( $token, $job->id, 'beef', 'c_two' );
		$late        = $wpdb->base_prefix . 'wpcfk_late';
		$wpdb->query( "CREATE TABLE `{$one}` (id INT PRIMARY KEY, two INT) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$two}` (id INT PRIMARY KEY, one INT, FOREIGN KEY (one) REFERENCES `{$one}` (id)) ENGINE=InnoDB" );
		$wpdb->query( "ALTER TABLE `{$one}` ADD FOREIGN KEY (two) REFERENCES `{$two}` (id)" );
		$reads = 0;
		$add   = function ( string $query ) use ( &$reads, $late, $one ): string {
			global $wpdb;
			if ( false !== strpos( $query, 'REFERENTIAL_CONSTRAINTS' ) && 2 === ++$reads ) {
				// Between the plan and the cycle's drop: another table gets a key to a member.
				$wpdb->query( "CREATE TABLE `{$late}` (id INT PRIMARY KEY, one INT, FOREIGN KEY (one) REFERENCES `{$one}` (id)) ENGINE=InnoDB" );
			}
			return $query;
		};
		add_filter( 'query', $add );
		try {
			$this->assertFalse( $this->repo->reclaim_work( $job ) );
			$this->assertSame( 2, $reads, 'the control: read once for the plan, once before the cycle' );
			$this->assertTrue( $this->table_exists( $late ), 'the control: the late table was made' );
			$this->assertTrue( $this->table_exists( $one ), 'held back' );
			$this->assertTrue( $this->table_exists( $two ) );
			remove_filter( 'query', $add );
			$wpdb->query( "DROP TABLE `{$late}`" );
			$this->assertTrue( $this->repo->reclaim_work( $job ), 'the next pass, without it, drops the cycle' );
			$this->assertFalse( $this->table_exists( $one ) );
		} finally {
			remove_filter( 'query', $add );
			$this->force_drop( array( $late, $two, $one, $plain ) );
		}
	}

	/**
	 * After a cycle goes with checks off, checks are on again for the next drop: a parent of the cycle that a
	 * table outside gets a key to in the meantime is refused by the server (not dropped, not stopped), and the
	 * session's own setting (0 here) is back at the end.
	 */
	public function test_checks_are_on_again_after_a_cycle_goes(): void {
		global $wpdb;
		list( $job, , $plain ) = $this->failed_job_with_work();
		$token       = $this->dirs->state()['token'];
		$one         = TempTables::name( $token, $job->id, 'beef', 'c_one' );
		$two         = TempTables::name( $token, $job->id, 'beef', 'c_two' );
		$parent      = TempTables::name( $token, $job->id, 'beef', 'p' );
		$late        = $wpdb->base_prefix . 'wpcfk_late';
		$wpdb->query( "CREATE TABLE `{$parent}` (id INT PRIMARY KEY) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$one}` (id INT PRIMARY KEY, two INT, p INT, FOREIGN KEY (p) REFERENCES `{$parent}` (id)) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$two}` (id INT PRIMARY KEY, one INT, FOREIGN KEY (one) REFERENCES `{$one}` (id)) ENGINE=InnoDB" );
		$wpdb->query( "ALTER TABLE `{$one}` ADD FOREIGN KEY (two) REFERENCES `{$two}` (id)" );
		$reads = 0;
		$add   = function ( string $query ) use ( &$reads, $late, $parent ): string {
			global $wpdb;
			if ( false !== strpos( $query, 'REFERENTIAL_CONSTRAINTS' ) && 2 === ++$reads ) {
				// After the plan, before the cycle: a table outside gets a key to the cycle's parent.
				$wpdb->query( "CREATE TABLE `{$late}` (id INT PRIMARY KEY, p INT, FOREIGN KEY (p) REFERENCES `{$parent}` (id)) ENGINE=InnoDB" );
			}
			return $query;
		};
		add_filter( 'query', $add );
		$wpdb->query( 'SET SESSION foreign_key_checks = 0' );
		try {
			$this->assertFalse( $this->repo->reclaim_work( $job ) );
			$this->assertSame( '0', (string) $wpdb->get_var( 'SELECT @@SESSION.foreign_key_checks' ), 'the session\'s own setting is back' );
			$wpdb->query( 'SET SESSION foreign_key_checks = 1' );
			$this->assertFalse( $this->table_exists( $one ), 'the control: the cycle went' );
			$this->assertFalse( $this->table_exists( $two ) );
			$this->assertTrue( $this->table_exists( $parent ), 'refused with checks on' );
			$log = (string) file_get_contents( $this->base . '/logs/storage.log' );
			$this->assertStringContainsString( 'temporary tables of job ' . $job->id . ': 1 entries could not be deleted', $log, 'refused by the server' );
			$this->assertStringNotContainsString( TempTableDropper::CHECKS_CHANGED, $log, 'not stopped: checks were on' );
		} finally {
			remove_filter( 'query', $add );
			$this->force_drop( array( $late, $two, $one, $parent, $plain ) );
		}
	}

	/**
	 * The session's setting is read back right before every drop: when it is not on (a reconnect brings the
	 * server's default), the call stops there, says why, and leaves the rest for the next pass.
	 */
	public function test_a_drop_waits_when_checks_are_not_on_right_before_it(): void {
		global $wpdb;
		list( $job, , $plain ) = $this->failed_job_with_work();
		$token                 = $this->dirs->state()['token'];
		$b                     = TempTables::name( $token, $job->id, 'beef', 't_b' );
		$c                     = TempTables::name( $token, $job->id, 'beef', 't_c' );
		$wpdb->query( "CREATE TABLE `{$b}` (id INT) ENGINE=InnoDB" );
		$wpdb->query( "CREATE TABLE `{$c}` (id INT) ENGINE=InnoDB" );
		$reads = 0;
		$lie   = static function ( string $query ) use ( &$reads ): string {
			// Reads: the session's value before the run, then one right before each drop. The third says "off".
			if ( 'SELECT @@SESSION.foreign_key_checks' === $query && 3 === ++$reads ) {
				return 'SELECT 0';
			}
			return $query;
		};
		add_filter( 'query', $lie );
		try {
			$this->assertFalse( $this->repo->reclaim_work( $job ) );
			$this->assertSame( 3, $reads, 'the control: read before each drop' );
			$this->assertFalse( $this->table_exists( $plain ), 'the control: the drop before went (names in order: posts, t_b, t_c)' );
			$this->assertTrue( $this->table_exists( $b ), 'waits' );
			$this->assertTrue( $this->table_exists( $c ) );
			$this->assertStringContainsString( 'temporary tables of job ' . $job->id . ' stopped: ' . TempTableDropper::CHECKS_CHANGED, (string) file_get_contents( $this->base . '/logs/storage.log' ) );
			remove_filter( 'query', $lie );
			$this->assertTrue( $this->repo->reclaim_work( $job ), 'the next pass finishes' );
			$this->assertFalse( $this->table_exists( $b ) );
			$this->assertFalse( $this->table_exists( $c ) );
		} finally {
			remove_filter( 'query', $lie );
			$this->force_drop( array( $b, $c, $plain ) );
		}
	}

	/**
	 * One call runs at most MAX_STATEMENTS drops: one table more takes a second pass (the largest input the
	 * bound lets through in one call, and one past it).
	 */
	public function test_more_tables_than_one_call_drops_take_more_passes(): void {
		global $wpdb;
		list( $job, , $plain ) = $this->failed_job_with_work();
		$token                 = $this->dirs->state()['token'];
		$tables                = array( $plain );
		for ( $i = 1; $i <= TempTableDropper::MAX_STATEMENTS; $i++ ) {
			$tables[] = TempTables::name( $token, $job->id, 'beef', sprintf( 'n%03d', $i ) );
			$wpdb->query( 'CREATE TABLE `' . end( $tables ) . '` (id INT) ENGINE=MyISAM' );
		}
		try {
			$this->assertFalse( $this->repo->reclaim_work( $job ), 'one table is left' );
			$left = array_values( array_filter( $tables, array( $this, 'table_exists' ) ) );
			$this->assertCount( 1, $left );
			$this->assertStringContainsString( TempTableDropper::MAX_STATEMENTS . ' entries deleted, more remain for the next pass', (string) file_get_contents( $this->base . '/logs/storage.log' ) );
			$this->assertTrue( $this->repo->reclaim_work( $job ), 'the next pass' );
			$this->assertFalse( $this->table_exists( $left[0] ) );
		} finally {
			$this->force_drop( $tables );
		}
	}

	/**
	 * The count and the time bounds, with fewer statements and a clock that runs out: each call stops where
	 * its bound says, and calls on what remains drop everything.
	 */
	public function test_a_call_stops_at_its_statement_and_time_bounds(): void {
		global $wpdb;
		$token  = $this->dirs->state()['token'];
		$tables = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$tables[] = TempTables::name( $token, 77, 'beef', 't' . $i );
			$wpdb->query( 'CREATE TABLE `' . end( $tables ) . '` (id INT) ENGINE=MyISAM' );
		}
		try {
			$passes = 0;
			$left   = $tables;
			while ( array() !== $left && $passes < 10 ) {
				++$passes;
				$result = TempTableDropper::drop( $left, TempTables::owner_prefix( $token ), 3 );
				$this->assertLessThanOrEqual( 3, count( $result['dropped'] ) );
				$left = $result['remaining'];
			}
			$this->assertSame( 3, $passes, '3 + 3 + 1' );
			$this->assertSame( array(), array_values( array_filter( $tables, array( $this, 'table_exists' ) ) ) );

			$more = array( TempTables::name( $token, 77, 'beef', 'u1' ), TempTables::name( $token, 77, 'beef', 'u2' ) );
			foreach ( $more as $table ) {
				$wpdb->query( "CREATE TABLE `{$table}` (id INT) ENGINE=MyISAM" );
			}
			$now    = 1000.0;
			$result = TempTableDropper::drop(
				$more,
				TempTables::owner_prefix( $token ),
				TempTableDropper::MAX_STATEMENTS,
				static function () use ( &$now ): float {
					$now += TempTableDropper::MAX_SECONDS + 0.01; // Each look: the whole time (the first step runs anyway).
					return $now;
				}
			);
			$this->assertSame( array( $more[0] ), $result['dropped'], 'one statement, then the time is up' );
			$this->assertSame( array( $more[1] ), $result['remaining'] );
		} finally {
			$this->force_drop( array_merge( $tables, $more ?? array() ) );
		}
	}

	/**
	 * The first statement of a call always runs, even when reading the keys took all the time: every call makes
	 * progress, and the next one goes on.
	 */
	public function test_the_first_drop_of_a_call_runs_even_when_the_time_is_already_up(): void {
		global $wpdb;
		$token  = $this->dirs->state()['token'];
		$tables = array( TempTables::name( $token, 78, 'beef', 't1' ), TempTables::name( $token, 78, 'beef', 't2' ) );
		foreach ( $tables as $table ) {
			$wpdb->query( "CREATE TABLE `{$table}` (id INT) ENGINE=MyISAM" );
		}
		$now = 1000.0;
		try {
			$result = TempTableDropper::drop(
				$tables,
				TempTables::owner_prefix( $token ),
				TempTableDropper::MAX_STATEMENTS,
				static function () use ( &$now ): float {
					$now += 100.0; // Every look: far past the time.
					return $now;
				}
			);
			$this->assertSame( array( $tables[0] ), $result['dropped'], 'the first ran' );
			$this->assertSame( array( $tables[1] ), $result['remaining'], 'the second waits' );
		} finally {
			$this->force_drop( $tables );
		}
	}

	/**
	 * Uninstall runs as many bounded calls as it takes: more tables than one call drops all go.
	 */
	public function test_uninstall_drops_more_temporary_tables_than_one_call_takes(): void {
		global $wpdb;
		$token  = $this->dirs->state()['token'];
		$tables = array();
		for ( $i = 0; $i <= TempTableDropper::MAX_STATEMENTS; $i++ ) {
			$tables[] = TempTables::name( $token, 79, 'beef', sprintf( 'u%03d', $i ) );
			$wpdb->query( 'CREATE TABLE `' . end( $tables ) . '` (id INT) ENGINE=MyISAM' );
		}
		$this->assertCount( TempTableDropper::MAX_STATEMENTS + 1, array_filter( $tables, array( $this, 'table_exists' ) ), 'the control: created' );
		try {
			Schema::drop();
			$this->assertSame( array(), array_values( array_filter( $tables, array( $this, 'table_exists' ) ) ) );
		} finally {
			$this->force_drop( $tables );
		}
	}

	/**
	 * Uninstall runs one bounded call even when its time is already up (the first unit always runs), and
	 * starts no further one then: more tables than one call takes leave the rest.
	 */
	public function test_uninstall_runs_one_call_even_when_its_time_is_already_up(): void {
		global $wpdb;
		$token  = $this->dirs->state()['token'];
		$tables = array();
		for ( $i = 0; $i <= TempTableDropper::MAX_STATEMENTS; $i++ ) {
			$tables[] = TempTables::name( $token, 80, 'beef', sprintf( 'v%03d', $i ) );
			$wpdb->query( 'CREATE TABLE `' . end( $tables ) . '` (id INT) ENGINE=MyISAM' );
		}
		$now = 1000.0;
		try {
			Schema::drop(
				static function () use ( &$now ): float {
					$now += 3600.0; // Every look: the time is long gone.
					return $now;
				}
			);
			$this->assertCount( 1, array_filter( $tables, array( $this, 'table_exists' ) ), 'one call ran (MAX_STATEMENTS drops), no second one' );
		} finally {
			$this->force_drop( $tables );
		}
	}

	public function test_a_table_the_plugin_could_not_have_created_is_a_reported_failure_not_a_silent_skip(): void {
		global $wpdb;
		list( $job, $dir, $table ) = $this->failed_job_with_work();
		// A name with the job's prefix but a character the naming rule never produces (someone hand-made it).
		$odd = TempTables::job_prefix( $this->dirs->state()['token'], $job->id ) . 'beef_orders_ü';
		$wpdb->query( "CREATE TABLE `{$odd}` (id int)" );
		try {
			$this->assertFalse( $this->repo->reclaim_work( $job ), 'a table left behind is not success' );
			$this->assertFalse( $this->table_exists( $table ), 'the well-formed table went' );
			$this->assertTrue( $this->table_exists( $odd ) );
			$this->assertDirectoryDoesNotExist( $dir );
			$this->assertStringContainsString( 'temporary tables of job ' . $job->id . ': 1 entries could not be deleted', (string) file_get_contents( $this->base . '/logs/storage.log' ) );
		} finally {
			$wpdb->query( "DROP TABLE IF EXISTS `{$odd}`" );
		}
	}

	public function test_a_failing_job_lookup_reclaims_nothing_in_that_pass(): void {
		global $wpdb;
		$token = $this->dirs->state()['token'];
		$tmp   = $this->base . '/tmp';
		$job   = $this->repo->create( 'export' );
		$this->repo->acquire( $job->id );
		mkdir( Residue::work_dir( $this->base, $job->id ) );
		touch( Residue::work_dir( $this->base, $job->id ) . '/site.part001.wpcheckpoint.zip.partial' );
		$table = TempTables::name( $token, $job->id, 'beef', 'posts' );
		$wpdb->query( "CREATE TABLE `{$table}` (id int)" );
		mkdir( $tmp . '/job-424242' );
		LockFile::write( $this->base, 424242, 'orphan', $this->now + 60 );

		// The jobs table is unreachable for the duration of the pass: every lookup fails, none says "no row".
		$jobs = Schema::jobs_table();
		$wpdb->query( "RENAME TABLE {$jobs} TO {$jobs}_away" );
		$suppressed = $wpdb->suppress_errors();
		try {
			$this->repo->reap();
		} finally {
			$wpdb->suppress_errors( $suppressed );
			$wpdb->query( "RENAME TABLE {$jobs}_away TO {$jobs}" );
		}
		$this->assertDirectoryExists( Residue::work_dir( $this->base, $job->id ), 'a running job\'s work survives a database hiccup' );
		$this->assertFileExists( Residue::work_dir( $this->base, $job->id ) . '/site.part001.wpcheckpoint.zip.partial' );
		$this->assertTrue( $this->table_exists( $table ) );
		$this->assertDirectoryExists( $tmp . '/job-424242', 'not even a real orphan is touched when lookups fail' );
		$this->assertFileExists( LockFile::path( $this->base, 424242 ) );
		$this->assertFileExists( LockFile::path( $this->base, $job->id ) );
		$this->assertStringContainsString( 'nothing is reclaimed in this pass', (string) file_get_contents( $this->base . '/logs/storage.log' ) );

		// With the table back, the same pass removes exactly the orphans.
		$this->repo->reap();
		$this->assertDirectoryDoesNotExist( $tmp . '/job-424242' );
		$this->assertFileDoesNotExist( LockFile::path( $this->base, 424242 ) );
		$this->assertDirectoryExists( Residue::work_dir( $this->base, $job->id ) );
		$this->assertTrue( $this->table_exists( $table ) );
	}

	public function test_a_retry_that_raced_the_expiry_loses(): void {
		global $wpdb;
		list( $job, $dir ) = $this->failed_job_with_work();
		$stale = $this->repo->find( $job->id );
		$this->assertTrue( $stale->can_retry() );
		// expire_work() marked the row after this object was read and is still deleting the files.
		$wpdb->update( Schema::jobs_table(), array( 'work_expired_at' => $this->now ), array( 'id' => $job->id ), array( '%d' ), array( '%d' ) );
		try {
			$this->repo->transition( $stale, Job::QUEUED );
			$this->fail( 'the row, not the object in memory, decides' );
		} catch ( StaleJob $e ) {
			$this->addToAssertionCount( 1 );
		}
		$this->assertSame( Job::FAILED, $this->repo->find( $job->id )->status );
		$this->assertFalse( $this->repo->find( $job->id )->can_retry() );
		// A fresh read gives the reason.
		try {
			$this->repo->transition( $this->repo->find( $job->id ), Job::QUEUED );
			$this->fail();
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'retention', $e->getMessage() );
		}
		$this->assertDirectoryExists( $dir, 'the files are the expiry\'s to remove, not this test\'s concern' );
	}

	public function test_reclaim_is_bounded_and_finishes_over_several_passes(): void {
		list( $job, $dir ) = $this->failed_job_with_work();
		for ( $i = 0; $i < JobRepository::RECLAIM_MAX_ENTRIES + 10; $i++ ) {
			touch( $dir . '/sub/' . $i );
		}
		$this->now += 8 * 86400;
		$this->repo->purge();
		$this->assertDirectoryExists( $dir, 'one pass deletes at most RECLAIM_MAX_ENTRIES entries' );
		$this->assertGreaterThan( 0, $this->repo->find( $job->id )->work_expired_at );
		$this->repo->reap();
		$this->assertDirectoryDoesNotExist( $dir, 'the next pass finishes the orphan' );
	}

	public function test_schema_version_two_adds_the_column_to_a_version_one_table(): void {
		global $wpdb;
		$table = Schema::jobs_table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN work_expired_at" );
		Options::set( Schema::OPTION, array( 'version' => 1, 'min_compatible' => 1 ) );
		$result = Schema::ensure();
		$this->assertSame( 'migrated', $result['action'] );
		$this->assertSame( Schema::CURRENT, $result['version'], 'every later migration runs too' );
		$this->assertSame( 1, $result['min_compatible'], 'older code ignores the column' );
		$this->assertContains( 'work_expired_at', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
		$job = $this->repo->create( 'export' );
		$this->assertSame( 0, $this->repo->find( $job->id )->work_expired_at );
	}

	public function test_schema_version_three_adds_the_options_and_questions_columns_to_a_version_two_table(): void {
		global $wpdb;
		$table = Schema::jobs_table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN options_json, DROP COLUMN questions_json" );
		Options::set( Schema::OPTION, array( 'version' => 2, 'min_compatible' => 1 ) );
		$result = Schema::ensure();
		$this->assertSame( 'migrated', $result['action'] );
		$this->assertSame( Schema::CURRENT, $result['version'], 'every later migration runs too' );
		$this->assertSame( 1, $result['min_compatible'], 'older code ignores both columns' );
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		$this->assertContains( 'options_json', $columns );
		$this->assertContains( 'questions_json', $columns );
		$job = $this->repo->create( 'export', 0, array(), array( 'contents' => array( 'files' => array( 'uploads' ) ) ) );
		$this->assertSame( array( 'contents' => array( 'files' => array( 'uploads' ) ) ), $this->repo->find( $job->id )->options );
		$this->assertSame( array(), $this->repo->find( $job->id )->questions );
		$this->assertFalse( $this->repo->find( $job->id )->awaiting_answer() );
	}

	public function test_schema_version_four_adds_the_takeover_columns_and_older_code_keeps_working(): void {
		global $wpdb;
		$table = Schema::jobs_table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN takeovers, DROP COLUMN takeover_mark" );
		Options::set( Schema::OPTION, array( 'version' => 3, 'min_compatible' => 1 ) );
		$result = Schema::ensure();
		$this->assertSame( 'migrated', $result['action'] );
		$this->assertSame( Schema::CURRENT, $result['version'] );
		$this->assertSame( 1, $result['min_compatible'], 'older code ignores both columns' );
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		$this->assertContains( 'takeovers', $columns );
		$this->assertContains( 'takeover_mark', $columns );
		$job = $this->repo->create( 'export' );
		$this->assertSame( 0, $this->repo->find( $job->id )->takeovers );
		$this->assertSame( '', $this->repo->find( $job->id )->takeover_mark );
		// Code of version 3 writes rows without the new columns: the defaults hold, and this code reads them.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (type, status, cursor_json, created_at) VALUES (%s, %s, %s, %d)", 'export', Job::QUEUED, '[]', 1 ) );
		$this->assertSame( '', $wpdb->last_error );
		$old = $this->repo->find( (int) $wpdb->insert_id );
		$this->assertSame( 0, $old->takeovers );
		$this->assertSame( '', $old->takeover_mark );
	}

	public function test_schema_version_five_adds_the_failure_kind_and_older_code_keeps_working(): void {
		global $wpdb;
		$table = Schema::jobs_table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN failure_kind" );
		Options::set( Schema::OPTION, array( 'version' => 4, 'min_compatible' => 1 ) );
		$result = Schema::ensure();
		$this->assertSame( 'migrated', $result['action'] );
		$this->assertSame( Schema::CURRENT, $result['version'], 'every later migration runs too' );
		$this->assertSame( 1, $result['min_compatible'], 'older code ignores the column' );
		$this->assertContains( 'failure_kind', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
		// A row failed by code of version 4 has no kind: retrying it is offered, as it was.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (type, status, cursor_json, last_error, created_at, finished_at) VALUES (%s, %s, %s, %s, %d, %d)", 'export', Job::FAILED, '[]', 'boom', 1, 2 ) );
		$this->assertSame( '', $wpdb->last_error );
		$old = $this->repo->find( (int) $wpdb->insert_id );
		$this->assertSame( '', $old->failure_kind );
		$this->assertTrue( $old->retry_useful() );
	}

	public function test_the_job_a_failing_transition_returns_reads_its_kind_like_a_row(): void {
		$job    = $this->repo->create( 'export' );
		$failed = $this->repo->transition( $job, Job::FAILED, 'The index is missing.', '', Job::FAILURE_FINAL );
		$this->assertSame( Job::FAILURE_FINAL, $failed->failure_kind, 'the kind, not the stamped column value' );
		$this->assertFalse( $failed->retry_useful(), 'the tick that fails a job answers as a reload would' );
		$this->assertSame( $failed->failure_kind, $this->repo->find( $job->id )->failure_kind );
	}

	public function test_a_kind_left_by_a_downgrade_does_not_hide_retry_later(): void {
		global $wpdb;
		$table = Schema::jobs_table();
		$job   = $this->repo->create( 'export' );
		$job   = $this->repo->transition( $job, Job::FAILED, 'The index is missing.', '', Job::FAILURE_FINAL );
		// The control: as written, the kind is read and Retry is hidden.
		$this->assertSame( Job::FAILURE_FINAL, $this->repo->find( $job->id )->failure_kind );
		$this->assertFalse( $this->repo->find( $job->id )->retry_useful() );
		// Code of version 4 retries it and it fails again: neither write touches the column, finished_at moves.
		$wpdb->update( $table, array( 'status' => Job::QUEUED, 'finished_at' => 0 ), array( 'id' => $job->id ) );
		$this->assertSame( '', $this->repo->find( $job->id )->failure_kind, 'retried' );
		$wpdb->update( $table, array( 'status' => Job::FAILED, 'finished_at' => $job->finished_at + 30, 'last_error' => 'Step "database": RuntimeException: disk quota' ), array( 'id' => $job->id ) );
		$this->assertStringStartsWith( 'final:', (string) $wpdb->get_var( $wpdb->prepare( "SELECT failure_kind FROM {$table} WHERE id = %d", $job->id ) ), 'the old kind is still in the row' );
		$again = $this->repo->find( $job->id );
		$this->assertSame( '', $again->failure_kind, 'it does not speak for the new failure' );
		$this->assertTrue( $again->retry_useful(), 'the worst case is a Retry offered once too often, never a dead end' );
	}
	public function test_schema_version_six_widens_a_failure_kind_declared_narrower(): void {
		global $wpdb;
		$table     = Schema::jobs_table();
		$this->now = 1800000000; // A real time: ten digits, as in production.
		$job       = $this->repo->create( 'export' ); // Before the table is narrowed: create() runs the migrations.
		// A table migrated with the first definition of version 5.
		$wpdb->query( "UPDATE {$table} SET failure_kind = ''" ); // Longer stamps left by earlier tests would stop the change.
		$wpdb->query( "ALTER TABLE {$table} MODIFY failure_kind varchar(16) NOT NULL DEFAULT ''" );
		$this->assertStringContainsString( 'varchar(16)', (string) $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'failure_kind'" )->Type );
		Options::set( Schema::OPTION, array( 'version' => 5, 'min_compatible' => 1 ) );
		// The control: there, a stamped kind is lost. wpdb refuses the whole failing transition when it knows the
		// column's width (a fresh request); where it cached the wider one, the server cuts the value.
		try {
			$failed = $this->repo->transition( $job, Job::FAILED, 'The database went away.', '', Job::FAILURE_TEMPORARY );
			$this->assertSame( '', $this->repo->find( $failed->id )->failure_kind, 'cut: the kind does not read back' );
		} catch ( StaleJob $e ) {
			$this->assertSame( Job::QUEUED, $this->repo->find( $job->id )->status, 'refused: the job cannot even fail' );
		}
		$result = Schema::ensure();
		$this->assertSame( 'migrated', $result['action'] );
		$this->assertSame( Schema::CURRENT, $result['version'] );
		$this->assertSame( 1, $result['min_compatible'] );
		$this->assertStringContainsString( 'varchar(32)', (string) $wpdb->get_row( "SHOW COLUMNS FROM {$table} LIKE 'failure_kind'" )->Type );
		$again  = $this->repo->create( 'export' );
		$failed = $this->repo->transition( $again, Job::FAILED, 'Step "database": TableChanged: The structure of table wp_x changed.', '', Job::FAILURE_FINAL . ':' . Job::REASON_TABLE_CHANGED );
		$this->assertSame( Job::FAILURE_FINAL, $this->repo->find( $failed->id )->failure_kind );
		$this->assertSame( Job::REASON_TABLE_CHANGED, $this->repo->find( $failed->id )->failure_reason, 'the longest value fits' );
	}

	public function test_schema_version_seven_adds_the_cron_deferrals_with_a_default_for_rows_written_without_it(): void {
		global $wpdb;
		$table = Schema::jobs_table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN cron_deferrals" );
		Options::set( Schema::OPTION, array( 'version' => 6, 'min_compatible' => 1 ) );
		$result = Schema::ensure();
		$this->assertSame( 'migrated', $result['action'] );
		$this->assertSame( Schema::CURRENT, $result['version'] );
		$this->assertSame( 1, $result['min_compatible'], 'older code ignores the column' );
		$this->assertContains( 'cron_deferrals', $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) );
		// Rows written without the column (as code of version 6 writes them): the default holds, and this code counts from it.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (type, status, cursor_json, created_at) VALUES (%s, %s, %s, %d)", 'export', Job::QUEUED, '[]', 1 ) );
		$this->assertSame( '', $wpdb->last_error );
		$old = (int) $wpdb->insert_id;
		$this->assertSame( 0, $this->repo->find( $old )->cron_deferrals );
		$this->assertTrue( $this->repo->count_cron_deferral( $old, 3 ) );
		$this->assertSame( 1, $this->repo->find( $old )->cron_deferrals );
	}

	public function test_cron_deferrals_are_counted_up_to_the_limit_for_active_jobs_only(): void {
		$job = $this->repo->create( 'export' );
		$this->assertTrue( $this->repo->count_cron_deferral( $job->id, 2 ) );
		$this->assertTrue( $this->repo->count_cron_deferral( $job->id, 2 ) );
		$this->assertFalse( $this->repo->count_cron_deferral( $job->id, 2 ), 'at the limit: not counted, the tick runs' );
		$this->assertSame( 2, $this->repo->find( $job->id )->cron_deferrals );
		$this->assertFalse( $this->repo->count_cron_deferral( 987654, 2 ), 'no such job' );
		$done = $this->repo->transition( $this->repo->create( 'export' ), Job::CANCELLED );
		$this->assertFalse( $this->repo->count_cron_deferral( $done->id, 2 ), 'an ended job' );
		$this->assertSame( 0, $this->repo->find( $done->id )->cron_deferrals );
	}
}
