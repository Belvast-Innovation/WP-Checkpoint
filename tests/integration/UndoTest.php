<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\Undo;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * wp wpcheckpoint job undo and job redo (JobActions::undo(), redo(), Jobs\Undo): a completed restore is undone by its
 * own reverse after a confirmation code that says what is dropped, and ends undone (not failed: no Retry); its
 * restored copy is swapped back in by redo, with a confirmation of its own, for 7 days. Every refusal has its control:
 * the same case without the obstacle goes through.
 */
final class UndoTest extends SwapTestCase {

	/** @var int[] Rows this test put into the jobs table by hand (removed at its end). */
	private $rows = array();

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->rows as $id ) {
			$wpdb->delete( Schema::jobs_table(), array( 'id' => $id ) );
		}
		$this->rows = array();
		Plugin::instance()->job_actions()->undo_gate( new Undo( Plugin::instance()->jobs() ) );
		parent::tear_down();
	}

	/**
	 * The job actions, with the undo's checks for the test's site (its WordPress directory, its restore type).
	 *
	 * @return \WPCheckpoint\Jobs\JobActions
	 */
	private function actions() {
		$actions = Plugin::instance()->job_actions();
		$actions->undo_gate(
			new Undo(
				Plugin::instance()->jobs(),
				array(
					'abspath'       => $this->abspath,
					'restore_types' => array( $this->type ),
				)
			)
		);
		return $actions;
	}

	/**
	 * A restore of the swap backup, run to its end.
	 *
	 * @return Job
	 */
	private function completed(): Job {
		$done = $this->cli_run( $this->at_swap() );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertSame( Job::SITE_SWAPPED, $done->site_state );
		$this->assertGreaterThan( 0, $done->committed_at, 'the commit time was recorded' );
		return $done;
	}

	/**
	 * Undo it: the code, then the confirmed undo, run by WP-CLI to its end.
	 *
	 * @param Job $job Completed restore.
	 * @return Job
	 */
	private function undone( Job $job ): Job {
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertFalse( $told['confirmed'] );
		$done = $this->actions()->undo( $job->id, $told['code'] );
		$this->assertTrue( $done['ok'], $done['message'] );
		$this->assertTrue( $done['confirmed'] );
		return $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
	}

	/**
	 * Set columns of a job's row.
	 *
	 * @param int                  $id     Job id.
	 * @param array<string, mixed> $values Column => value.
	 */
	private function set( int $id, array $values ): void {
		global $wpdb;
		$wpdb->update( Schema::jobs_table(), $values, array( 'id' => $id ) );
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * A row of another job, by hand.
	 *
	 * @param array<string, mixed> $values Column => value.
	 * @return int
	 */
	private function other( array $values ): int {
		global $wpdb;
		$wpdb->insert( Schema::jobs_table(), $values + array( 'created_at' => time() ) );
		$wpdb->query( 'COMMIT' );
		$this->rows[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	public function test_a_completed_restore_is_undone_by_its_own_reverse_once_confirmed_and_ends_undone(): void {
		$before = $this->site();
		$job    = $this->completed();
		$this->assertRestored( $before );
		$after = $this->site();

		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertFalse( $told['confirmed'], 'without the code nothing is done' );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{16}\z/', $told['code'] );
		$this->assertStringContainsString( '--confirm=' . $told['code'], $told['message'] );
		$this->assertStringContainsString( 'Everything written on the site since', implode( "\n", $told['lines'] ) );
		$this->assertStringContainsString( 'job redo', implode( "\n", $told['lines'] ), 'says that it can be swapped back in' );
		$this->assertSame( $after, $this->site(), 'nothing changed' );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status );

		$wrong = $this->actions()->undo( $job->id, str_repeat( '0', 16 ) );
		$this->assertFalse( $wrong['ok'] );
		$this->assertStringContainsString( 'does not match', $wrong['message'] );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status, 'a wrong code changes nothing' );

		$claimed = $this->actions()->undo( $job->id, $told['code'] );
		$this->assertTrue( $claimed['ok'], $claimed['message'] );
		$queued = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::QUEUED, $queued->status );
		$this->assertSame( SwapStep::ID, $queued->step );
		$this->assertSame( 'reverse', $queued->cursor['phase'] ?? '' );
		$this->assertSame( SwapStep::ASKED, $queued->cursor['reason'] ?? '' );
		$this->assertTrue( $queued->cursor['out'] ?? false );
		$this->assertSame( array( $job->id ), Plugin::instance()->jobs()->holding_site(), 'it holds the site from the claim on' );

		$done = $this->cli_run( $queued );
		$this->assertSame( Job::UNDONE, $done->status, (string) $done->last_error . ' ' . $done->progress_message );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( '', $done->last_error );
		$this->assertStringContainsString( 'The restore was undone', $done->progress_message );
		$this->assertFalse( $done->retry_useful() );
		$this->assertTrue( $done->can_redo() );
		$this->assertSame( $before, $this->site(), 'the site as it was before the restore' );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site() );
		$this->assertSame( SwapCheckStep::ID, $done->cursor[ JobRepository::RETRY_FROM_KEY ] ?? '', 'a redo starts at the final check' );
	}

	public function test_an_undone_restore_is_not_retried_anywhere_and_says_how_to_redo_it(): void {
		$job  = $this->completed();
		$done = $this->undone( $job );
		$this->assertSame( Job::UNDONE, $done->status );
		try {
			Plugin::instance()->job_actions()->retry( $job->id );
			$this->fail( 'retried' );
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'only a redo', $e->getMessage() );
		}
		$this->assertSame( Job::UNDONE, Plugin::instance()->jobs()->find( $job->id )->status );
		$response = $this->rest( 'POST', 'jobs/' . $job->id . '/retry' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertStringContainsString( 'wp wpcheckpoint job redo ' . $job->id, (string) ( $response->get_data()['message'] ?? '' ) );
		$this->assertSame( Job::UNDONE, Plugin::instance()->jobs()->find( $job->id )->status, 'still undone' );
	}

	public function test_the_undone_restore_is_swapped_back_in_by_redo_once_confirmed(): void {
		$before = $this->site();
		$job    = $this->completed();
		$done   = $this->undone( $job );
		$this->assertSame( $before, $this->site() );

		$told = $this->actions()->redo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertFalse( $told['confirmed'] );
		$this->assertStringContainsString( 'job redo ' . $job->id . ' --confirm=' . $told['code'], $told['message'] );
		$this->assertStringContainsString( 'kept for 7 days after the undo', implode( "\n", $told['lines'] ) );
		$this->assertNotSame( $this->actions()->undo( $job->id, '' )['code'], $told['code'] );
		$this->assertSame( $before, $this->site(), 'nothing changed' );

		$this->assertFalse( $this->actions()->redo( $job->id, str_repeat( 'a', 16 ) )['ok'], 'a wrong code' );
		$claimed = $this->actions()->redo( $job->id, $told['code'] );
		$this->assertTrue( $claimed['ok'], $claimed['message'] );
		$queued = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::QUEUED, $queued->status );
		$this->assertSame( SwapCheckStep::ID, $queued->step );
		$this->assertSame( array(), $queued->cursor );

		$again = $this->cli_run( $queued );
		$this->assertSame( Job::COMPLETED, $again->status, (string) $again->last_error );
		$this->assertRestored( $before );
		$this->assertGreaterThanOrEqual( $done->committed_at, $again->committed_at, 'committed again' );
	}

	public function test_undo_and_redo_are_refused_with_the_reason_while_another_job_is_in_the_way(): void {
		$job = $this->completed();
		$ok  = function () use ( $job ): array {
			return $this->actions()->undo( $job->id, '' );
		};
		$this->assertTrue( $ok()['ok'], 'the control' );

		// A job that holds the site.
		$holder = $this->other( array( 'type' => 'export', 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING, 'storage_token' => $job->storage_token ) );
		$this->assertStringContainsString( 'Job ' . $holder . ' holds the site', $ok()['message'] );
		$this->set( $holder, array( 'status' => Job::COMPLETED ) );
		$this->assertTrue( $ok()['ok'], 'once it has ended' );

		// Another restore under way.
		$other = $this->other( array( 'type' => $this->type, 'status' => Job::QUEUED, 'storage_token' => $job->storage_token ) );
		$this->assertStringContainsString( 'Restore job ' . $other . ' is under way', $ok()['message'] );
		$this->set( $other, array( 'status' => Job::CANCELLED ) );
		$this->assertTrue( $ok()['ok'] );

		// A swap committed after it, by another installation.
		$later = $this->other( array( 'type' => 'restore', 'status' => Job::COMPLETED, 'storage_token' => 'another0another0another0another0', 'committed_at' => $job->committed_at + 1 ) );
		$this->assertStringContainsString( 'Restore job ' . $later . ' swapped the site after restore job ' . $job->id, $ok()['message'] );
		$this->set( $later, array( 'committed_at' => $job->committed_at ) );
		$this->assertStringContainsString( 'swapped the site after', $ok()['message'], 'the same second and a later id: after it' );
		$this->set( $later, array( 'committed_at' => $job->committed_at - 1 ) );
		$this->assertTrue( $ok()['ok'], 'one committed before it is no obstacle' );

		// Managed by another installation.
		$this->set( $job->id, array( 'held_by' => 'another0another0another0another0' ) );
		$this->assertStringContainsString( 'managed by another installation', $ok()['message'] );
		$this->set( $job->id, array( 'held_by' => '' ) );
		$this->assertTrue( $ok()['ok'] );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status, 'nothing was changed by the refusals' );
	}

	public function test_undo_is_refused_outside_its_window_and_without_a_commit_time(): void {
		$job = $this->completed();
		$ok  = function () use ( $job ): array {
			return $this->actions()->undo( $job->id, '' );
		};
		$this->set( $job->id, array( 'committed_at' => time() - Undo::WINDOW_SECONDS + 120 ) );
		$this->assertTrue( $ok()['ok'], 'the control: within the window' );
		$this->set( $job->id, array( 'committed_at' => time() - Undo::WINDOW_SECONDS - 120 ) );
		$this->assertStringContainsString( 'can no longer be undone', $ok()['message'] );
		$this->set( $job->id, array( 'committed_at' => 0 ) );
		$this->assertStringContainsString( 'before this version of WP Checkpoint recorded', $ok()['message'] );
		$this->set( $job->id, array( 'committed_at' => time() ) );
		$this->assertTrue( $ok()['ok'] );
	}

	public function test_undo_is_refused_when_a_table_or_directory_is_not_where_the_swap_left_it(): void {
		global $wpdb;
		$job  = $this->completed();
		$plan = $this->plan_of( $job );
		$ok   = function () use ( $job ): array {
			return $this->actions()->undo( $job->id, '' );
		};
		$this->assertTrue( $ok()['ok'], 'the control' );
		$old = '';
		foreach ( $plan['tables'] as $entry ) {
			if ( $entry['had_live'] ) {
				$old = $entry['old'];
				break;
			}
		}
		$this->assertNotSame( '', $old, 'the control: a table was moved aside' );
		$wpdb->query( "RENAME TABLE `{$old}` TO `{$old}x`" );
		try {
			$this->assertStringContainsString( 'is not where the restore left it', $ok()['message'] );
		} finally {
			$wpdb->query( "RENAME TABLE `{$old}x` TO `{$old}`" );
		}
		$this->assertTrue( $ok()['ok'] );
		$dir = '';
		foreach ( $plan['dirs'] as $entry ) {
			if ( $entry['had_live'] ) {
				$dir = $entry['old'];
				break;
			}
		}
		$this->assertNotSame( '', $dir, 'the control: a directory was moved aside' );
		rename( $dir, $dir . '-away' );
		try {
			$this->assertStringContainsString( 'is not where the restore left it', $ok()['message'] );
		} finally {
			rename( $dir . '-away', $dir );
		}
		$this->assertTrue( $ok()['ok'] );
	}

	public function test_after_the_restore_completed_and_the_cleanup_ran_it_is_still_undone_within_its_window(): void {
		$before = $this->site();
		$job    = $this->completed();
		$jobs   = Plugin::instance()->jobs();
		$base   = $jobs->files_base( $job );
		// The control: a cancelled job's work directory, which the same cleanup reclaims.
		$cancelled = $this->other( array( 'type' => 'export', 'status' => Job::CANCELLED, 'storage_token' => $job->storage_token, 'storage_path' => $job->storage_path, 'finished_at' => time() - 60 ) );
		$work      = Residue::work_dir( $base, $cancelled );
		mkdir( $work, 0755, true );
		file_put_contents( $work . '/left.txt', 'left' );

		// Every cleanup the plugin runs, by a clock a minute short of the end of the window.
		$late = new JobRepository(
			Plugin::instance()->directories(),
			null,
			static function () use ( $job ): int {
				return $job->committed_at + Undo::WINDOW_SECONDS - 60;
			}
		);
		$late->reap();
		$late->expire_work();
		$late->purge();
		clearstatcache();
		$this->assertDirectoryDoesNotExist( $work, 'the control: the cleanup ran and reclaimed what was left to it' );
		$this->assertTrue( is_file( RestoreFiles::path( $this->work( $job ), RestoreFiles::SWAP_PLAN ) ), 'the plan file stays' );
		$this->assertNotSame( array(), $this->plan_of( $job )['tables'], 'the plan rows stay' );

		$this->actions()->undo_gate(
			new Undo(
				$jobs,
				array(
					'abspath'       => $this->abspath,
					'restore_types' => array( $this->type ),
					'now'           => static function () use ( $job ): int {
						return $job->committed_at + Undo::WINDOW_SECONDS - 60;
					},
				)
			)
		);
		$actions = Plugin::instance()->job_actions();
		$told    = $actions->undo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertTrue( $actions->undo( $job->id, $told['code'] )['ok'] );
		$done = $this->cli_run( $jobs->find( $job->id ) );
		$this->assertSame( Job::UNDONE, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'put back after the cleanup' );
	}

	public function test_redo_is_possible_for_7_days_after_the_undo_and_the_restored_copy_is_reclaimed_after(): void {
		global $wpdb;
		$job  = $this->completed();
		$done = $this->undone( $job );
		$jobs = Plugin::instance()->jobs();
		$ok   = function () use ( $job ): array {
			return $this->actions()->redo( $job->id, '' );
		};
		$as_restore = function ( callable $read ) use ( $job ) {
			$this->set( $job->id, array( 'type' => \WPCheckpoint\Jobs\RestoreJob::ID ) ); // What the query looks for.
			try {
				return $read();
			} finally {
				$this->set( $job->id, array( 'type' => $this->type ) );
			}
		};
		$this->assertTrue( $as_restore( array( JobRepository::class, 'restore_in_progress' ) ), 'an undone restore with its copy kept holds automatic updates of the plugin back, as a failed one does' );

		// A cleanup a minute short of 7 days after the undo leaves the copy.
		$late = new JobRepository(
			Plugin::instance()->directories(),
			null,
			static function () use ( $done ): int {
				return $done->finished_at + JobRepository::WORK_RETENTION_SECONDS - 60;
			}
		);
		$this->assertSame( 0, $late->expire_work() );
		$this->assertTrue( $ok()['ok'], 'the control: within 7 days' );
		$staged = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( \WPCheckpoint\Jobs\TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) );
		$this->assertNotSame( array(), $staged, 'the control: the restored copy is under its staging names' );

		// The undo more than 7 days ago: refused before the cleanup ran, and the cleanup reclaims the copy.
		$this->set( $job->id, array( 'finished_at' => time() - JobRepository::WORK_RETENTION_SECONDS - 60 ) );
		$this->assertStringContainsString( 'can no longer be swapped back in', $ok()['message'] );
		$this->assertGreaterThanOrEqual( 1, Plugin::instance()->jobs()->expire_work() );
		$expired = $jobs->find( $job->id );
		$this->assertGreaterThan( 0, $expired->work_expired_at );
		$this->assertSame( Job::UNDONE, $expired->status, 'still undone' );
		$this->assertSame( array(), (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( \WPCheckpoint\Jobs\TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) ), 'the restored copy was reclaimed' );
		$this->assertStringContainsString( 'can no longer be swapped back in', $ok()['message'] );
		$this->assertFalse( $as_restore( array( JobRepository::class, 'restore_in_progress' ) ), 'once its copy is reclaimed, no longer' );
	}
}
