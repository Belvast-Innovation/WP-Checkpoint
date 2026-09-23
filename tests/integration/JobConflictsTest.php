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
