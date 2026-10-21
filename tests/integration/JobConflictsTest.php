<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobConflict;
use WPCheckpoint\Jobs\JobsUnavailable;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * JobActions::start() applies the concurrency rules after the insert, so
 * that two requests racing each other cannot both start.
 */
final class JobConflictsTest extends JobTestCase {

	public function test_a_second_export_is_refused_and_leaves_no_row(): void {
		$actions = Plugin::instance()->job_actions();
		$first   = $actions->start( 'export', self::$admin_id, array() );
		try {
			$actions->start( 'export', self::$admin_id, array() );
			$this->fail( 'a second export must be refused' );
		} catch ( JobConflict $e ) {
			$this->assertSame( sprintf( 'A backup is already being made (job %d).', $first->id ), $e->getMessage() );
		}
		$this->assertCount( 1, Plugin::instance()->jobs()->list_jobs(), 'the refused job was removed before it ran' );
	}

	public function test_starts_wait_for_each_other_and_give_up_with_a_reason(): void {
		// Another request in the middle of a start: it holds the named lock on its own connection.
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name  = 'wpcheckpoint_start_' . substr( md5( DB_NAME . '.' . $GLOBALS['wpdb']->base_prefix . 'wpcheckpoint_jobs' ), 0, 16 );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
		try {
			Plugin::instance()->job_actions()->start( 'export', self::$admin_id, array() );
			$this->fail( 'a start must not run while another one holds the lock' );
		} catch ( JobsUnavailable $e ) {
			$this->assertSame( 'Another job is being started right now; try again in a moment.', $e->getMessage() );
		} finally {
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$other->close();
		}
		$this->assertSame( array(), Plugin::instance()->jobs()->list_jobs(), 'nothing was inserted' );
		$job = Plugin::instance()->job_actions()->start( 'export', self::$admin_id, array() );
		$this->assertGreaterThan( 0, $job->id, 'once the lock is free, the start goes through' );
		$this->assertSame( '0', (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT IS_USED_LOCK(%s) IS NOT NULL', $name ) ), 'the lock is released after the start' );
	}

	public function test_exclusive_work_holds_the_start_lock_until_it_returns_or_throws(): void {
		$name    = 'wpcheckpoint_start_' . substr( md5( DB_NAME . '.' . $GLOBALS['wpdb']->base_prefix . 'wpcheckpoint_jobs' ), 0, 16 );
		$other   = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$actions = Plugin::instance()->job_actions();
		$seen    = $actions->exclusive(
			static function ( array $active ) use ( $other, $name ): string {
				return (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
			}
		);
		$this->assertSame( '0', $seen, 'no other request can take the lock during the work' );
		try {
			$actions->exclusive(
				static function (): void {
					throw new \RuntimeException( 'work failed' );
				}
			);
			$this->fail( 'the work\'s exception is passed on' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'work failed', $e->getMessage() );
		}
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ), 'released after the work, also when it threw' );
		$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		$other->close();
	}

	public function test_of_two_racing_jobs_the_earlier_one_wins(): void {
		// Both inserted before either checked: what two requests arriving together produce.
		$repo    = Plugin::instance()->jobs();
		$actions = Plugin::instance()->job_actions();
		$a       = $repo->create( 'export', self::$admin_id );
		$b       = $repo->create( 'export', self::$admin_id );
		$this->assertSame( '', $actions->conflict_for( $a ), 'the earlier one sees nothing before it' );
		$this->assertSame( sprintf( 'A backup is already being made (job %d).', $a->id ), $actions->conflict_for( $b ) );
	}

	/**
	 * A job that failed (queued, then failed before a driver took it: retryable).
	 */
	private function failed( string $type, array $options = array(), string $failure = Job::FAILURE_TEMPORARY ): Job {
		$repo = Plugin::instance()->jobs();
		$job  = $repo->create( $type, self::$admin_id, array(), $options );
		$job  = $repo->transition( $job, Job::FAILED, 'Stopped.', '', $failure );
		$this->assertTrue( $job->can_retry(), 'the control: its work files are there' );
		return $job;
	}

	public function test_a_failed_job_is_retried_only_where_it_could_start(): void {
		global $wpdb;
		$repo    = Plugin::instance()->jobs();
		$actions = Plugin::instance()->job_actions();
		foreach ( array(
			'an export during a restore' => array( 'restore', array( 'base' => 'site-20260923-120000-ab12' ), 'export', array(), 'A restore is in progress (job %d)' ),
			'a restore during a restore' => array( 'restore', array( 'base' => 'site-20260923-120000-ab12' ), 'restore', array( 'base' => 'other-20260923-120000-cd34' ), 'A restore is in progress (job %d)' ),
			'a restore during an export' => array( 'export', array(), 'restore', array( 'base' => 'other-20260923-120000-cd34' ), 'A backup is being made (job %d)' ),
			'an export during an export' => array( 'export', array(), 'export', array(), 'A backup is already being made (job %d)' ),
		) as $case => list( $running_type, $running_options, $type, $options, $reason ) ) {
			$failed  = $this->failed( $type, $options );
			$running = $repo->create( $running_type, self::$admin_id, array(), $running_options );
			$this->assertNotFalse( $repo->acquire( $running->id ), $case . ': the control, it runs' );
			try {
				$actions->retry( $failed->id );
				$this->fail( $case . ': retried next to a job it must not run with' );
			} catch ( JobConflict $e ) {
				$this->assertStringStartsWith( sprintf( $reason, $running->id ), $e->getMessage(), $case );
			}
			$this->assertSame( Job::FAILED, $repo->find( $failed->id )->status, $case . ': still failed, not queued' );
			$this->assertSame( array( $running->id ), array_map( static function ( Job $job ): int { return $job->id; }, $actions->active() ), $case . ': only the running job is active' );
			// The control: once it has ended, the retry goes through.
			$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'status' => Job::COMPLETED, 'lock_token' => '', 'locked_until' => 0 ), array( 'id' => $running->id ) );
			$this->assertSame( Job::QUEUED, $actions->retry( $failed->id )->status, $case . ': retried once it ended' );
			$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'status' => Job::CANCELLED ), array( 'id' => $failed->id ) );
		}
	}

	public function test_a_job_that_cannot_be_retried_says_so_first_and_nothing_gives_way_for_it(): void {
		global $wpdb;
		$repo     = Plugin::instance()->jobs();
		$actions  = Plugin::instance()->job_actions();
		$running  = $repo->create( 'export', self::$admin_id );
		$this->assertNotFalse( $repo->acquire( $running->id ), 'the control: an export runs' );
		$estimate = $repo->create( 'estimate', self::$admin_id );
		$expired  = $this->failed( 'export' );
		$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'work_expired_at' => time() ), array( 'id' => $expired->id ) );
		$this->assertFalse( $repo->find( $expired->id )->can_retry(), 'the control: recorded as reclaimed' );
		$abandoned = $this->failed( 'export', array(), Job::FAILURE_FINAL . ':' . Job::REASON_ABANDONED );
		$this->assertSame( Job::REASON_ABANDONED, $repo->find( $abandoned->id )->failure_reason, 'the control: recorded as abandoned' );
		$completed = $repo->create( 'export', self::$admin_id );
		$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'status' => Job::COMPLETED ), array( 'id' => $completed->id ) );
		$this->assertSame( Job::COMPLETED, $repo->find( $completed->id )->status, 'the control: completed' );
		foreach ( array(
			'its work files reclaimed' => array( $expired->id, 'cannot be retried' ),
			'abandoned'                => array( $abandoned->id, 'was abandoned' ),
			'not failed'               => array( $completed->id, 'cannot go from completed to queued' ),
		) as $case => list( $id, $said ) ) {
			try {
				$actions->retry( $id );
				$this->fail( $case . ': retried' );
			} catch ( \WPCheckpoint\Jobs\InvalidTransition $e ) {
				$this->assertStringContainsString( $said, $e->getMessage(), $case . ': why it cannot be retried, not the job it would conflict with' );
			}
		}
		$this->assertSame( Job::QUEUED, $repo->find( $estimate->id )->status, 'nothing gave way for a retry that could not happen' );
		// The control: a job that can be retried, next to the running export, is refused for the conflict.
		try {
			$actions->retry( $this->failed( 'export' )->id );
			$this->fail( 'retried next to a running export' );
		} catch ( JobConflict $e ) {
			$this->assertStringStartsWith( sprintf( 'A backup is already being made (job %d)', $running->id ), $e->getMessage() );
		}
	}

	public function test_a_retry_cancels_an_estimate_and_holds_the_start_lock(): void {
		$repo     = Plugin::instance()->jobs();
		$actions  = Plugin::instance()->job_actions();
		$failed   = $this->failed( 'export' );
		$estimate = $repo->create( 'estimate', self::$admin_id );
		$this->assertSame( Job::QUEUED, $actions->retry( $failed->id )->status, 'an estimate gives way, as to a new export' );
		$this->assertSame( Job::CANCELLED, $repo->find( $estimate->id )->status );
		$again = $this->failed( 'verify', array( 'base' => 'other-20260923-120000-cd34' ) );
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$name  = 'wpcheckpoint_start_' . substr( md5( DB_NAME . '.' . $GLOBALS['wpdb']->base_prefix . 'wpcheckpoint_jobs' ), 0, 16 );
		$this->assertSame( '1', (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) );
		try {
			$actions->retry( $again->id );
			$this->fail( 'a retry must not run while a start holds the lock' );
		} catch ( JobsUnavailable $e ) {
			$this->assertSame( 'Another job is being started right now; try again in a moment.', $e->getMessage() );
		} finally {
			$other->query( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
			$other->close();
		}
		$this->assertSame( Job::FAILED, $repo->find( $again->id )->status, 'not queued while the lock was held' );
	}

	/**
	 * A restore that failed partway through its swap: it holds the site (JobRepository::holding_site()).
	 */
	private function holding(): Job {
		global $wpdb;
		$job = $this->failed( 'restore', array( 'base' => 'site-20260923-120000-ab12' ) );
		$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'site_state' => Job::SITE_CHANGING ), array( 'id' => $job->id ) );
		$this->assertSame( array( $job->id ), Plugin::instance()->jobs()->holding_site(), 'the control: it holds the site' );
		return $job;
	}

	/**
	 * The ids of every job row.
	 *
	 * @return int[]
	 */
	private function ids(): array {
		return array_map(
			static function ( Job $job ): int {
				return $job->id;
			},
			Plugin::instance()->jobs()->list_jobs( array(), 500 )
		);
	}

	public function test_nothing_is_created_while_a_failed_restore_holds_the_site(): void {
		global $wpdb;
		$repo     = Plugin::instance()->jobs();
		$actions  = Plugin::instance()->job_actions();
		$plain    = $this->failed( 'restore', array( 'base' => 'site-20260923-120000-ab12' ) );
		$held     = $this->holding();
		$estimate = $repo->create( 'estimate', self::$admin_id );
		$ids      = $this->ids();
		$said     = esc_html( sprintf( 'Restore job %1$d stopped partway through and holds the site: no job can start until it has ended (a backup now would capture a half restored site). Resolve it with: wp wpcheckpoint job retry %1$d, then wp wpcheckpoint job run %1$d', $held->id ) );
		foreach ( array(
			'an export'       => array( 'export', array() ),
			'another restore' => array( 'restore', array( 'base' => 'other-20260923-120000-cd34' ) ),
			'a verify'        => array( 'verify', array( 'base' => 'other-20260923-120000-cd34' ) ),
			'an estimate'     => array( 'estimate', array() ),
		) as $case => list( $type, $options ) ) {
			try {
				$actions->start( $type, self::$admin_id, $options );
				$this->fail( $case . ': started while a restore holds the site' );
			} catch ( JobConflict $e ) {
				$this->assertSame( $said, $e->getMessage(), $case . ': which job, and what to do with it' );
			}
			$this->assertSame( $ids, $this->ids(), $case . ': nothing was created' );
		}
		$this->assertSame( Job::QUEUED, $repo->find( $estimate->id )->status, 'nothing gave way for a start that was refused' );

		// The page's "Back up now": 409 with the reason, nothing created.
		$response = $this->rest( 'POST', 'backups' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( sprintf( 'Restore job %1$d stopped partway through and holds the site', $held->id ), strstr( (string) $response->get_data()['message'], ':', true ) );
		$this->assertSame( $ids, $this->ids(), 'the request created nothing' );

		// Not ended (queued again), and managed by another installation: what is said follows where it is.
		$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'status' => Job::QUEUED ), array( 'id' => $held->id ) );
		foreach ( array(
			'queued'    => sprintf( 'Restore job %1$d holds the site and has not ended: no job can start until it has. wp wpcheckpoint job status %1$d shows where it is.', $held->id ),
			'elsewhere' => sprintf( 'Restore job %1$d holds the site and another installation manages it: no job can start until it has ended. wp wpcheckpoint job status %1$d says what can be done from here.', $held->id ),
		) as $case => $expected ) {
			if ( 'elsewhere' === $case ) {
				$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'status' => Job::FAILED, 'held_by' => 'abababababab' ), array( 'id' => $held->id ) );
				$this->assertFalse( $repo->manages( $repo->find( $held->id ) ), 'the control: managed elsewhere' );
			}
			try {
				$actions->start( 'export', self::$admin_id, array() );
				$this->fail( $case . ': started while a restore holds the site' );
			} catch ( JobConflict $e ) {
				$this->assertSame( esc_html( $expected ), $e->getMessage(), $case );
			}
			$this->assertSame( $ids, $this->ids(), $case . ': nothing was created' );
		}

		// The controls: abandoned from here, the restore no longer holds the site (the same rule as release and the
		// notices), and a failed restore that never changed the site ($plain) never did: an export starts.
		$at = time() - 60;
		$wpdb->update(
			\WPCheckpoint\Jobs\JobRepository::table(),
			array(
				'finished_at'  => $at,
				'failure_kind' => Job::stamp_failure( Job::FAILURE_FINAL . ':' . Job::REASON_ABANDONED, $at ),
				'held_by'      => (string) Plugin::instance()->directories()->state()['token'],
			),
			array( 'id' => $held->id )
		);
		$this->assertSame( array(), $repo->holding_site(), 'the control: abandoned here, it holds nothing' );
		$this->assertSame( Job::FAILED, $repo->find( $plain->id )->status, 'the control: a failed restore that does not hold the site' );
		$export = $actions->start( 'export', self::$admin_id, array() );
		$this->assertSame( Job::QUEUED, $export->status );
		$this->assertSame( Job::CANCELLED, $repo->find( $estimate->id )->status, 'and the estimate gave way to it' );
	}

	public function test_nothing_is_created_when_whether_a_restore_holds_the_site_cannot_be_read(): void {
		$ids    = $this->ids();
		$broken = 0;
		$break  = static function ( string $sql ) use ( &$broken ): string {
			if ( false !== strpos( $sql, 'WHERE site_state <>' ) ) {
				++$broken;
				return 'SELECT * FROM a_table_that_is_not_there_wcp';
			}
			return $sql;
		};
		add_filter( 'query', $break );
		$suppress = $GLOBALS['wpdb']->suppress_errors( true );
		try {
			Plugin::instance()->job_actions()->start( 'export', self::$admin_id, array() );
			$this->fail( 'started without knowing whether a restore holds the site' );
		} catch ( JobsUnavailable $e ) {
			$this->assertSame( 'Whether a restore holds the site could not be read; try again in a moment.', $e->getMessage() );
		} finally {
			remove_filter( 'query', $break );
			$GLOBALS['wpdb']->suppress_errors( $suppress );
		}
		$this->assertSame( 1, $broken, 'the control: the read of the jobs that hold the site was the one that failed' );
		$this->assertSame( $ids, $this->ids(), 'nothing was created' );
		// The control: read again, nothing holds the site and the export starts.
		$this->assertSame( Job::QUEUED, Plugin::instance()->job_actions()->start( 'export', self::$admin_id, array() )->status );
	}

	public function test_a_retry_while_another_job_holds_the_site_is_refused_and_the_holder_is_retried(): void {
		$repo   = Plugin::instance()->jobs();
		$held   = $this->holding();
		$export = $this->failed( 'export' );
		try {
			Plugin::instance()->job_actions()->retry( $export->id );
			$this->fail( 'an export retried while a restore holds the site' );
		} catch ( JobConflict $e ) {
			$this->assertStringStartsWith( sprintf( 'Restore job %d stopped partway through and holds the site', $held->id ), $e->getMessage() );
		}
		$this->assertSame( Job::FAILED, $repo->find( $export->id )->status, 'still failed, not queued' );
		// The control: the restore that holds the site is retried, the way to put the site right.
		$this->assertSame( Job::QUEUED, Plugin::instance()->job_actions()->retry( $held->id )->status );
	}

	public function test_nothing_starts_during_a_restore_and_a_started_job_is_not_discarded(): void {
		$repo    = Plugin::instance()->jobs();
		$restore = $repo->create( 'restore', self::$admin_id, array(), array( 'base' => 'site-20260923-120000-ab12' ) );
		try {
			Plugin::instance()->job_actions()->start( 'verify', self::$admin_id, array( 'base' => 'other-20260923-120000-cd34' ) );
			$this->fail( 'nothing starts during a restore' );
		} catch ( JobConflict $e ) {
			$this->assertStringStartsWith( sprintf( 'A restore is in progress (job %d)', $restore->id ), $e->getMessage() );
		}
		// A job a driver has taken is never deleted by discard_unstarted() (one statement, conditions on the row).
		$this->assertNotFalse( $repo->acquire( $restore->id ) );
		$this->assertFalse( $repo->discard_unstarted( $restore->id ) );
		$this->assertInstanceOf( Job::class, $repo->find( $restore->id ) );
	}
}
