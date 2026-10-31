<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\Undo;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\SelfCheck;
use WPCheckpoint\Support\StoredNames;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * wp wpcheckpoint job undo (JobActions::undo(), Jobs\Undo): a completed restore is undone by its own reverse after a
 * confirmation code that says what is dropped, and ends undone (not failed: no Retry anywhere); its restored copy is
 * kept for 7 days after the undo, then reclaimed. Every refusal has its control: the same case without the obstacle
 * goes through.
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
		$this->assertStringContainsString( 'kept for 7 days after the undo', implode( "\n", $told['lines'] ), 'says how long the restored site is kept' );
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
		$this->assertSame( Job::SITE_SWAPPED, $queued->site_state, 'nothing changed yet: still swapped, at the undo\'s first position' );
		$this->assertSame( 'undo', $queued->cursor['phase'] ?? '' );
		$this->assertSame( SwapStep::ASKED, $queued->cursor['reason'] ?? '' );
		$this->assertTrue( $queued->cursor['out'] ?? false );
		$this->assertSame( array( $job->id ), Plugin::instance()->jobs()->holding_site(), 'it holds the site from the claim on' );

		$this->seams = array();
		$done        = $this->cli_run( $queued );
		$this->assertSame( Job::UNDONE, $done->status, (string) $done->last_error . ' ' . $done->progress_message );
		$this->assertContains( 'carried_back', $this->seams, 'the plugin\'s own state was carried back' );
		$this->assertNotContains( 'carry_asked', $this->seams, 'nothing asked when the carry back succeeds' );
		$this->assertContains( 'undo_checked', $this->seams, 'the site was checked after the undo' );
		$this->assertSame( 0, $done->committed_at, 'no swap of it stands any more' );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( '', $done->last_error );
		$this->assertStringContainsString( 'The restore was undone', $done->progress_message );
		$this->assertFalse( $done->retry_useful() );
		$this->assertTrue( $done->is_terminal() );
		$this->assertSame( $before, $this->site(), 'the site as it was before the restore' );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site() );
	}

	public function test_an_undone_restore_is_not_retried_anywhere_and_says_how_long_its_copy_is_kept(): void {
		$job  = $this->completed();
		$done = $this->undone( $job );
		$this->assertSame( Job::UNDONE, $done->status );
		try {
			Plugin::instance()->job_actions()->retry( $job->id );
			$this->fail( 'retried' );
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'was undone', $e->getMessage() );
		}
		$this->assertSame( Job::UNDONE, Plugin::instance()->jobs()->find( $job->id )->status );
		$response = $this->rest( 'POST', 'jobs/' . $job->id . '/retry' );
		$this->assertSame( 409, $response->get_status() );
		$this->assertStringContainsString( 'Swapping the restored copy back in comes in a later version; the restored copy is kept until 7 days after the undo.', (string) ( $response->get_data()['message'] ?? '' ) );
		$this->assertSame( Job::UNDONE, Plugin::instance()->jobs()->find( $job->id )->status, 'still undone' );
	}

	public function test_undo_is_refused_with_the_reason_while_another_job_is_in_the_way(): void {
		$job = $this->completed();
		$ok  = function () use ( $job ): array {
			return $this->actions()->undo( $job->id, '' );
		};
		$this->assertTrue( $ok()['ok'], 'the control' );

		// A job that holds the site.
		$holder = $this->other( array( 'type' => 'export', 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING, 'storage_token' => $job->storage_token ) );
		$this->assertStringContainsString( 'Job ' . $holder . ' holds the site', $ok()['message'] );
		// Ended as a job that changed the site ends without holding it: recorded untouched again (no job completes while
		// it is changing the site; one left changing with no commit time is refused below as a swap of unknown order).
		$this->set(
			$holder,
			array(
				'status'     => Job::COMPLETED,
				'site_state' => Job::SITE_UNTOUCHED,
			)
		);
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

		// A swap with no commit time recorded (an earlier version's, here or of an installation sharing the database).
		$this->set(
			$later,
			array(
				'committed_at' => 0,
				'site_state'   => Job::SITE_SWAPPED,
				'finished_at'  => $job->committed_at + 10,
			)
		);
		$this->assertStringContainsString( 'Job ' . $later . ' holds the site swapped but recorded no time of its swap', $ok()['message'], 'ended after it: its order cannot be told' );
		$this->set( $later, array( 'finished_at' => 0 ) );
		$this->assertStringContainsString( 'recorded no time of its swap', $ok()['message'], 'not ended' );
		$this->set( $later, array( 'finished_at' => $job->committed_at - 10 ) );
		$this->assertTrue( $ok()['ok'], 'ended before it was committed: earlier' );
		$this->set( $later, array( 'site_state' => Job::SITE_UNTOUCHED, 'finished_at' => $job->committed_at + 10 ) );
		$this->assertTrue( $ok()['ok'], 'one that holds nothing is no obstacle' );

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

	/**
	 * Uninstall WP Checkpoint with its data deleted, stopped before its first step that removes anything (the seam
	 * throws there, so a test that is wrong deletes nothing): the points it reached, and what it logged.
	 *
	 * @param callable|null $closed Run right after the uninstall closed its fence (before its next read).
	 * @return array{points: string[], log: string}
	 */
	private function uninstall_until_it_removes( $closed = null ): array {
		$points   = array();
		$property = new \ReflectionProperty( \WPCheckpoint\Support\Uninstaller::class, 'at' );
		$property->setAccessible( true );
		$property->setValue(
			null,
			static function ( string $point ) use ( &$points, $closed ): void {
				$points[] = $point;
				if ( null !== $closed && 0 === strpos( $point, 'closed:' ) ) {
					call_user_func( $closed );
				}
				if ( 0 === strpos( $point, 'unit:' ) ) {
					throw new \DomainException( 'stopped before it removes anything' );
				}
			}
		);
		$log = $this->sandbox . '/php-error-' . bin2hex( random_bytes( 4 ) ) . '.log'; // This call's lines only.
		$was = ini_get( 'error_log' );
		ini_set( 'error_log', $log );
		\WPCheckpoint\Support\UninstallSetting::save( true );
		try {
			\WPCheckpoint\Support\Uninstaller::run();
		} catch ( \DomainException $e ) {
			$points[] = 'stopped';
		} finally {
			$property->setValue( null, null );
			ini_set( 'error_log', (string) $was );
			\WPCheckpoint\Support\UninstallSetting::save( false );
		}
		clearstatcache();
		return array(
			'points' => $points,
			'log'    => is_file( $log ) ? (string) file_get_contents( $log ) : '',
		);
	}

	public function test_an_uninstall_keeps_everything_while_an_undone_restore_keeps_its_restored_site_and_goes_on_after(): void {
		global $wpdb;
		$job    = $this->completed();
		$done   = $this->undone( $job );
		$staged = function () use ( $wpdb, $job ): array {
			return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( \WPCheckpoint\Jobs\TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) );
		};
		$kept   = $staged();
		$this->assertNotSame( array(), $kept, 'the control: the restored copy is under its staging names' );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state, 'the control: it holds no site' );
		$until = $done->finished_at + JobRepository::WORK_RETENTION_SECONDS;

		$held = \WPCheckpoint\Support\Uninstaller::holding();
		$this->assertSame( 0, $held['all'] );
		$this->assertSame( array( array( 'id' => $job->id, 'until' => $until, 'how' => 'undone' ) ), $held['undo'] );
		$out = $this->uninstall_until_it_removes();
		$this->assertNotContains( 'stopped', $out['points'], 'it stops before any step that removes something' );
		$this->assertSame( array(), preg_grep( '/^unit:/', $out['points'] ) );
		$this->assertContains( 'check', $out['points'], 'the control: the uninstall ran and read' );
		$this->assertStringContainsString( sprintf( 'restore job %d was undone, and the restored site it replaced, with what visitors wrote on it while it was live, is kept until %s UTC', $job->id, gmdate( 'Y-m-d H:i', $until ) ), $out['log'], 'why, and until when' );
		$this->assertStringContainsString( 'left in place so what visitors wrote on the restored site is not deleted', $out['log'] );
		$this->assertSame( $kept, $staged(), 'the restored copy stays' );
		$this->assertSame( Job::UNDONE, Plugin::instance()->jobs()->find( $job->id )->status );

		// 7 days after the undo it is no longer kept: the uninstall goes on (to its first step, where the test stops it).
		$this->set( $job->id, array( 'finished_at' => time() - JobRepository::WORK_RETENTION_SECONDS - 60 ) );
		$this->assertSame( array(), \WPCheckpoint\Support\Uninstaller::holding()['undo'] );
		$out = $this->uninstall_until_it_removes();
		$this->assertContains( 'unit:cancel', $out['points'], 'the uninstall goes on' );
		$this->assertStringNotContainsString( 'was undone', $out['log'] );
		// The same once its work expired, whatever the time.
		$this->set( $job->id, array( 'finished_at' => time(), 'work_expired_at' => time() ) );
		$this->assertSame( array(), \WPCheckpoint\Support\Uninstaller::holding()['undo'], 'reclaimed: nothing kept' );

		// One that appears after the uninstall's first read stops it at the read before its next step.
		$this->set( $job->id, array( 'finished_at' => time() - JobRepository::WORK_RETENTION_SECONDS - 60, 'work_expired_at' => 0 ) );
		$out = $this->uninstall_until_it_removes(
			function () use ( $job ): void {
				$this->set( $job->id, array( 'finished_at' => time() ) );
			}
		);
		$this->assertContains( 'closed:' . \WPCheckpoint\Jobs\UninstallFence::DONE, $out['points'], 'the control: it got past its first read and closed the fence' );
		$this->assertSame( array(), preg_grep( '/^unit:/', $out['points'] ), 'it stops before any step that removes something' );
		$this->assertStringContainsString( sprintf( 'restore job %d was undone', $job->id ), $out['log'] );
	}

	public function test_an_uninstall_keeps_everything_while_an_undo_that_put_the_site_back_has_not_ended(): void {
		list( $job ) = $this->claimed();
		$reverted    = wp_json_encode( array( 'phase' => 'reverted' ) + SwapStep::undo_cursor( 1, 'mark' ) );
		$this->set(
			$job->id,
			array(
				'site_state'   => Job::SITE_UNTOUCHED,
				'committed_at' => 0,
				'cursor_json'  => $reverted,
			)
		);
		$this->assertSame( array( array( 'id' => $job->id, 'until' => 0, 'how' => 'undoing' ) ), \WPCheckpoint\Support\Uninstaller::holding()['undo'] );
		$out = $this->uninstall_until_it_removes();
		$this->assertSame( array(), preg_grep( '/^unit:/', $out['points'] ), 'it stops before it cancels anything' );
		$this->assertStringContainsString( sprintf( 'an undo of restore job %d put the site back and has not ended, and the restored site it replaced, with what visitors wrote on it while it was live, is kept until 7 days after the undo ends (it goes on only when it runs: after a reinstall, wp wpcheckpoint job run %d)', $job->id, $job->id ), $out['log'] );
		$this->assertSame( Job::QUEUED, Plugin::instance()->jobs()->find( $job->id )->status, 'not cancelled' );

		// Failed there: kept until 7 days after it failed (when the cleanup reclaims a failed job's work).
		$at = time() - 60;
		$this->set(
			$job->id,
			array(
				'status'       => Job::FAILED,
				'finished_at'  => $at,
				'failure_kind' => Job::stamp_failure( Job::FAILURE_TEMPORARY, $at ),
			)
		);
		$this->assertSame( array( array( 'id' => $job->id, 'until' => $at + JobRepository::WORK_RETENTION_SECONDS, 'how' => 'failed' ) ), \WPCheckpoint\Support\Uninstaller::holding()['undo'] );
		$this->assertStringContainsString( sprintf( 'an undo of restore job %d stopped with an error after it put the site back, and the restored site it replaced, with what visitors wrote on it while it was live, is kept until %s UTC;', $job->id, gmdate( 'Y-m-d H:i', $at + JobRepository::WORK_RETENTION_SECONDS ) ), \WPCheckpoint\Support\Uninstaller::held_back( \WPCheckpoint\Support\Uninstaller::holding() ) );
		$this->set( $job->id, array( 'work_expired_at' => time() ) );
		$this->assertSame( array(), \WPCheckpoint\Support\Uninstaller::holding()['undo'], 'its work reclaimed: not kept' );
		$this->set( $job->id, array( 'work_expired_at' => 0, 'finished_at' => time() - JobRepository::WORK_RETENTION_SECONDS - 60 ) );
		$this->assertSame( array(), \WPCheckpoint\Support\Uninstaller::holding()['undo'], 'past it: not kept' );

		// A swap whose position cannot be read may be such an undo: kept. One rolled back (not an undo) is not.
		$this->set(
			$job->id,
			array(
				'status'      => Job::QUEUED,
				'finished_at' => 0,
				'cursor_json' => '{not json',
			)
		);
		$this->assertSame( array( array( 'id' => $job->id, 'until' => 0, 'how' => 'unknown' ) ), \WPCheckpoint\Support\Uninstaller::holding()['undo'] );
		$this->assertStringContainsString( sprintf( '(after a reinstall, wp wpcheckpoint job status %d shows what it is)', $job->id ), \WPCheckpoint\Support\Uninstaller::held_back( \WPCheckpoint\Support\Uninstaller::holding() ) );
		$this->set( $job->id, array( 'cursor_json' => wp_json_encode( array( 'phase' => 'reverted', 'attempt' => 1, 'mark' => 'mark' ) ) ) );
		$this->assertSame( array(), \WPCheckpoint\Support\Uninstaller::holding()['undo'], 'the control: a swap rolled back keeps nothing of visitors\'' );
		$out = $this->uninstall_until_it_removes();
		$this->assertContains( 'unit:cancel', $out['points'], 'and the uninstall goes on' );
	}

	public function test_the_restored_copy_is_kept_for_7_days_after_the_undo_and_reclaimed_after(): void {
		global $wpdb;
		$job    = $this->completed();
		$done   = $this->undone( $job );
		$jobs   = Plugin::instance()->jobs();
		$staged = function () use ( $wpdb, $job ): array {
			return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( \WPCheckpoint\Jobs\TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) );
		};
		$this->assertNotSame( array(), $staged(), 'the control: the restored copy is under its staging names' );

		// A cleanup a minute short of 7 days after the undo leaves it.
		$late = new JobRepository(
			Plugin::instance()->directories(),
			null,
			static function () use ( $done ): int {
				return $done->finished_at + JobRepository::WORK_RETENTION_SECONDS - 60;
			}
		);
		$this->assertSame( 0, $late->expire_work() );
		$this->assertSame( 0, $jobs->find( $job->id )->work_expired_at );
		$this->assertNotSame( array(), $staged(), 'kept' );

		// 7 days after the undo the cleanup reclaims it; the job stays undone.
		$after = new JobRepository(
			Plugin::instance()->directories(),
			null,
			static function () use ( $done ): int {
				return $done->finished_at + JobRepository::WORK_RETENTION_SECONDS + 60;
			}
		);
		$this->assertGreaterThanOrEqual( 1, $after->expire_work() );
		$expired = $jobs->find( $job->id );
		$this->assertGreaterThan( 0, $expired->work_expired_at );
		$this->assertSame( Job::UNDONE, $expired->status, 'still undone' );
		$this->assertSame( array(), $staged(), 'the restored copy was reclaimed' );

		// What a reclaim left of it (a deletion that failed) is an orphan of an expired undone job: the reaper takes it.
		$work = Residue::work_dir( $jobs->files_base( $expired ), $job->id );
		mkdir( $work, 0755, true );
		file_put_contents( $work . '/left.txt', 'left' );
		$jobs->reap();
		clearstatcache();
		$this->assertDirectoryDoesNotExist( $work, 'reaped' );
	}

	/**
	 * The swap's crash seams recorded, and the carry back failing once (not for a moment) at its start.
	 *
	 * @param Job $job Job.
	 */
	private function carry_fails_once( Job $job ): void {
		$thrown                 = false;
		$this->swap_parts['at'] = function ( string $point ) use ( &$thrown ): void {
			$this->seams[] = $point;
			if ( 'carry_back_locked' === $point && ! $thrown ) {
				$thrown = true;
				throw new \RuntimeException( 'Table is marked as crashed and should be repaired' );
			}
		};
		$this->retype( $job );
		$this->seams = array();
	}

	/**
	 * A row of this plugin's own state written on the restored site since the swap.
	 */
	private function write_state( string $value ): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'options';
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", StoredNames::DELETE_DATA ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", StoredNames::DELETE_DATA, $value ) );
		$wpdb->query( 'COMMIT' );
	}

	private function state(): ?string {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->base_prefix}options` WHERE option_name = %s", StoredNames::DELETE_DATA ) );
		return null === $value ? null : (string) $value;
	}

	/**
	 * An undo whose carry back fails: claimed and run until it asks.
	 *
	 * @return array{0: Job, 1: array<string, mixed>, 2: array<string, mixed>, 3: int} The job as asked, the site before the restore, after it, and when the swap was committed.
	 */
	private function asked_about_the_carry(): array {
		$before = $this->site();
		$job    = $this->completed();
		$after  = $this->site();
		$at     = $job->committed_at;
		$this->write_state( 'written since the restore' );
		$this->carry_fails_once( $job );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$paused = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::PAUSED, $paused->status, (string) $paused->last_error . ' ' . $paused->progress_message );
		$this->assertTrue( $paused->awaiting_answer() );
		$this->assertSame( SwapStep::CARRY_QUESTION, $paused->questions[0]['id'] ?? '' );
		$this->assertSame( array( SwapStep::PUT_BACK, RestoreJob::KEEP ), $paused->questions[0]['choices'] ?? null, 'no default: both ways, nothing else' );
		$this->assertGreaterThan( 0, (int) ( $paused->questions[0]['count'] ?? 0 ) );
		$this->assertContains( 'carry_back_locked', $this->seams, 'the control: the carry back began' );
		$this->assertNotContains( 'carried_back', $this->seams );
		$this->assertNotContains( 'table_back', $this->seams, 'nothing was put back' );
		$this->assertSame( Job::SITE_SWAPPED, $paused->site_state, 'the restored site is in place' );
		$this->assertSame( $after, $this->site(), 'as it was, its maintenance file down while the question waits' );
		$this->assertSame( array( $job->id ), Plugin::instance()->jobs()->holding_site(), 'it holds the site while it asks' );
		$text = QuestionText::for_job( $paused, Plugin::instance()->directories(), 'strval' );
		$this->assertStringContainsString( 'could not carry WP Checkpoint\'s own settings back', $text[0]['text'] );
		$this->assertStringContainsString( 'Table is marked as crashed', $text[0]['text'], 'why' );
		$file = \WPCheckpoint\Jobs\ExportPlan::read( $this->work( $paused ), RestoreFiles::CARRY_BACK );
		$this->assertContains( StoredNames::DELETE_DATA, $file['names'], 'the rows that would be lost, in the work file' );
		$this->assertSame( count( $file['names'] ), (int) $paused->questions[0]['count'] );
		$this->assertSame( array_slice( $file['names'], 0, QuestionText::MAX_LISTED ), $text[0]['listed'], 'the first of them listed with the question' );
		return array( $paused, $before, $after, $at );
	}

	public function test_an_undo_whose_carry_back_fails_asks_and_put_back_drops_the_plugins_state_written_since(): void {
		list( $job, $before ) = $this->asked_about_the_carry();
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::CARRY_QUESTION => SwapStep::PUT_BACK ) );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::UNDONE, $done->status, (string) $done->last_error );
		$this->assertContains( 'table_back', $this->seams, 'put back' );
		$this->assertSame( $before, $this->site(), 'the site as it was before the restore' );
		$this->assertNotSame( 'written since the restore', $this->state(), 'the plugin\'s state written since was not carried back, as chosen' );
		$this->assertStringContainsString( 'were not carried back, as chosen', Plugin::instance()->job_presenter()->log_tail( $done ) );
	}

	public function test_an_undo_whose_carry_back_fails_asks_and_keep_leaves_the_restored_site_and_a_later_undo_asks_anew(): void {
		list( $job, , $after, $at ) = $this->asked_about_the_carry();
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::CARRY_QUESTION => RestoreJob::KEEP ) );
		$kept = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $kept->status, (string) $kept->last_error );
		$this->assertSame( Job::SITE_SWAPPED, $kept->site_state );
		$this->assertSame( $at, $kept->committed_at, 'the time it was committed is unchanged' );
		$this->assertContains( 'undo_given_up', $this->seams );
		$this->assertStringContainsString( 'given up as the administrator chose', Plugin::instance()->job_presenter()->log_tail( $kept ) );
		$this->assertSame( $after, $this->site(), 'the restored site stays, its maintenance file down' );
		$this->assertSame( 'written since the restore', $this->state(), 'and its state' );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site() );

		// A later undo asks its own question: the earlier answer is not taken for it.
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->assertArrayNotHasKey( SwapStep::CARRY_QUESTION, (array) ( Plugin::instance()->jobs()->find( $job->id )->options['answers'] ?? array() ) );
		$this->carry_fails_once( $job );
		$again = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::PAUSED, $again->status, 'asked again, not answered by the earlier "keep"' );
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::CARRY_QUESTION => SwapStep::PUT_BACK ) );
		$this->assertSame( Job::UNDONE, $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) )->status );
	}

	public function test_a_check_of_the_site_that_fails_after_the_undo_is_said_and_the_site_let_out_all_the_same(): void {
		global $wpdb;
		$before = $this->site();
		$job    = $this->completed();
		$this->retype( $job, array( 'verify' => static function (): string { return 'home'; } ) );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->seams = array();
		$done        = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::UNDONE, $done->status, 'undone all the same: no question, no reverse' );
		$this->assertContains( 'undo_checked', $this->seams );
		$this->assertStringContainsString( 'the site\'s check then failed: the home page did not answer as a WordPress site', $done->progress_message );
		$this->assertStringContainsString( 'did not pass its check; it is let out all the same', Plugin::instance()->job_presenter()->log_tail( $done ) );
		$this->assertSame( $before, $this->site(), 'the site as it was before the restore, its maintenance file down' );
		$wpdb->query( 'COMMIT' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . SelfCheck::table( $wpdb->base_prefix ) . ' WHERE option_name = %s', StoredNames::SELF_CHECK ) ), 'the self-check row is gone' );
		$this->assertNotContains( 'table_back', array_slice( $this->seams, (int) array_search( 'undo_checked', $this->seams, true ) ), 'nothing swapped after the check' );
	}

	public function test_a_check_after_the_undo_that_cannot_tell_is_made_again_after_waits_then_said(): void {
		$job = $this->completed();
		$this->retype(
			$job,
			array(
				'verify' => static function (): array {
					return array(
						'verdict' => 'unknown',
						'check'   => 'home',
						'why'     => 'none',
						'status'  => 0,
					);
				},
			)
		);
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$waits = array();
		$done  = null;
		for ( $i = 0; $i < 30; $i++ ) {
			$result = $this->cli_tick( $job );
			if ( \WPCheckpoint\Jobs\TickResult::WAITING === $result->status ) {
				$waits[] = $result->retry_after;
			}
			$done = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $done->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
		}
		$this->assertSame( Job::UNDONE, $done->status );
		$this->assertSame( SwapStep::CHECK_WAITS, $waits, 'checked again after each wait' );
		$this->assertStringContainsString( 'the site could not be checked: the home page did not answer', $done->progress_message );
	}

	/**
	 * Whether a maintenance file (or its data file) of the sandboxed site is there, and the self-check row.
	 *
	 * @return array{maintenance: bool, self_check: bool}
	 */
	private function leftovers(): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return array(
			'maintenance' => array() !== $this->maintenance_files(),
			'self_check'  => null !== $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . SelfCheck::table( $wpdb->base_prefix ) . ' WHERE option_name = %s', StoredNames::SELF_CHECK ) ),
		);
	}

	public function test_an_undo_killed_at_any_seam_is_finished_by_the_next_run(): void {
		// Every seam an undo passes, with how often, from one that runs whole.
		$job = $this->completed();
		$this->write_state( 'written since the restore' );
		$this->retype( $job );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->seams = array();
		$this->assertSame( Job::UNDONE, $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) )->status );
		$cases = array();
		$count = array();
		foreach ( $this->seams as $seam ) {
			$count[ $seam ] = ( $count[ $seam ] ?? 0 ) + 1;
			$cases[]        = array( $seam, $count[ $seam ] );
		}
		foreach ( array( 'carry_back_locked', 'carried_back', 'table_back', 'dir_back', 'restored', 'undo_check_written', 'undo_checked', 'undo_verdict_recorded', 'undo_self_check_removed', 'unheld', 'reverted', 'maintenance_down' ) as $seam ) {
			$this->assertArrayHasKey( $seam, $count, 'the control: an undo passes ' . $seam );
		}
		$this->assertGreaterThan( 15, count( $cases ) );

		foreach ( $cases as $case ) {
			list( $seam, $nth ) = $case;
			$label = $seam . ' #' . $nth;
			$this->tear_down_swap();
			$this->set_up_swap();
			$before = $this->site();
			$job    = $this->completed();
			$this->write_state( 'written since the restore' );
			$this->retype( $job );
			$told = $this->actions()->undo( $job->id, '' );
			$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'], $label );
			$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), $seam, $nth );
			$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
			$this->assertSame( Job::UNDONE, $done->status, $label . ': ' . $done->last_error . ' ' . $done->progress_message );
			$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state, $label );
			$this->assertSame( $before, $this->site(), $label . ': the site as it was before the restore, whole' );
			$this->assertSame( 'written since the restore', $this->state(), $label . ': the plugin\'s own state carried back' );
			$this->assertSame(
				array(
					'maintenance' => false,
					'self_check'  => false,
				),
				$this->leftovers(),
				$label . ': no maintenance file, no self-check row'
			);
			$this->assertSame( array(), Plugin::instance()->jobs()->holding_site(), $label );
		}
	}

	public function test_an_undo_killed_while_it_asks_about_its_carry_back_asks_again_with_the_site_let_out(): void {
		foreach ( array( 'carry_ask_recorded', 'carry_asked' ) as $seam ) {
			$this->tear_down_swap();
			$this->set_up_swap();
			$job   = $this->completed();
			$after = $this->site();
			$this->retype( $job );
			$told = $this->actions()->undo( $job->id, '' );
			$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
			// The carry back fails in the child too: an old options table whose id is not AUTO_INCREMENT is refused.
			$this->break_old_options( $job );
			$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), $seam );
			$asked = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
			$this->assertSame( Job::PAUSED, $asked->status, $seam . ': ' . $asked->last_error );
			$this->assertSame( SwapStep::CARRY_QUESTION, $asked->questions[0]['id'] ?? '', $seam );
			$this->assertSame( $after, $this->site(), $seam . ': the restored site, let out' );
			$this->mend_old_options( $job );
			Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::CARRY_QUESTION => SwapStep::PUT_BACK ) );
			$this->assertSame( Job::UNDONE, $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) )->status, $seam );
		}
	}

	/**
	 * The old options table's id no longer AUTO_INCREMENT: the carry back refuses it before it writes (not a failure
	 * of the moment), in this process and in a child alike.
	 */
	private function break_old_options( Job $job ): void {
		global $wpdb;
		$old = $this->old_options( $job );
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE `{$old}` MODIFY option_id bigint(20) unsigned NOT NULL" ), $wpdb->last_error );
	}

	private function mend_old_options( Job $job ): void {
		global $wpdb;
		$old = $this->old_options( $job );
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE `{$old}` MODIFY option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT" ), $wpdb->last_error );
	}

	private function old_options( Job $job ): string {
		global $wpdb;
		foreach ( $this->plan_of( $job )['tables'] as $entry ) {
			if ( \WPCheckpoint\Restore\SwapPlan::TABLE_OF === $entry['kind'] && $wpdb->base_prefix . 'options' === $entry['live'] ) {
				return (string) $entry['old'];
			}
		}
		$this->fail( 'the control: the plan has the options table' );
	}

	public function test_an_undo_taken_up_after_a_table_was_put_back_does_not_ask_when_its_carry_back_fails(): void {
		global $wpdb;
		$before = $this->site();
		$job    = $this->completed();
		$this->retype( $job );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$old = $this->old_options( $job );
		$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'table_back', 1 );
		$wpdb->query( 'COMMIT' );
		$this->assertNotEmpty( $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old ) ) ), 'the control: a table was put back, the options table not yet (its carry back is tried again)' );
		$this->break_old_options( $job );
		$this->seams = array();
		$done        = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::UNDONE, $done->status, 'not asked: part of the site was put back already' );
		$this->assertContains( 'carry_back_locked', $this->seams, 'the control: the carry back was tried' );
		$this->assertNotContains( 'carry_asked', $this->seams );
		$this->assertStringContainsString( 'the site is put back all the same', Plugin::instance()->job_presenter()->log_tail( $done ) );
		$this->mend_old_options_live();
		$this->assertSame( $before, $this->site() );
	}

	/**
	 * The options table the undo put back, its id made AUTO_INCREMENT again (break_old_options() made it not).
	 */
	private function mend_old_options_live(): void {
		global $wpdb;
		$this->assertNotFalse( $wpdb->query( "ALTER TABLE `{$wpdb->base_prefix}options` MODIFY option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT" ), $wpdb->last_error );
	}

	public function test_an_undo_taken_up_after_its_check_was_recorded_does_not_check_again(): void {
		$job    = $this->completed();
		$checks = 0;
		$this->retype(
			$job,
			array(
				'verify' => static function () use ( &$checks ): bool {
					++$checks;
					return true;
				},
			)
		);
		$this->swap_parts['verify'] = static function (): bool {
			return true;
		};
		$this->trace = $this->sandbox . '/trace';
		$told        = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'undo_verdict_recorded' );
		$this->assertContains( 'undo_checked', array_filter( explode( "\n", (string) file_get_contents( $this->trace ) ) ), 'the control: the killed run checked' );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::UNDONE, $done->status );
		$this->assertSame( 0, $checks, 'the recorded verdict stands: not checked again' );
	}

	public function test_a_job_that_holds_the_site_by_the_time_the_undo_is_claimed_is_seen_under_the_start_lock(): void {
		$job   = $this->completed();
		$told  = $this->actions()->undo( $job->id, '' );
		$reads = 0;
		$holder = 0;
		// The checks read the clock once each time they run: the second time is under the start lock, right before the
		// claim. Another job comes to hold the site just then.
		Plugin::instance()->job_actions()->undo_gate(
			new Undo(
				Plugin::instance()->jobs(),
				array(
					'abspath'       => $this->abspath,
					'restore_types' => array( $this->type ),
					'now'           => function () use ( &$reads, &$holder, $job ): int {
						if ( 2 === ++$reads ) {
							$holder = $this->other( array( 'type' => 'export', 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING, 'storage_token' => $job->storage_token ) );
						}
						return time();
					},
				)
			)
		);
		$refused = Plugin::instance()->job_actions()->undo( $job->id, $told['code'] );
		$this->assertSame( 2, $reads, 'the control: the checks ran twice' );
		$this->assertFalse( $refused['ok'] );
		$this->assertStringContainsString( 'Job ' . $holder . ' holds the site', $refused['message'] );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status, 'not claimed' );
	}

	/**
	 * A completed restore claimed for an undo, not run yet.
	 *
	 * @return array{0: Job, 1: array<string, mixed>} The job, the site as restored.
	 */
	private function claimed(): array {
		$job   = $this->completed();
		$after = $this->site();
		$this->retype( $job );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		return array( Plugin::instance()->jobs()->find( $job->id ), $after );
	}

	/**
	 * Cancel through the job actions, with $meanwhile run on another connection right before the first statement that
	 * would give the undo up (between the cancel's read of the row and its write).
	 *
	 * @param int           $id        Job id.
	 * @param callable|null $meanwhile function( \wpdb $other ): void.
	 * @return array{job: Job, cleaned: bool, reason: string}
	 */
	private function cancel_with( int $id, $meanwhile = null ): array {
		$ran    = false;
		$filter = static function ( $query ) use ( &$ran, $meanwhile ) {
			if ( ! $ran && null !== $meanwhile && false !== strpos( (string) $query, 'cursor_json LIKE' ) ) {
				$ran   = true;
				$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
				call_user_func( $meanwhile, $other );
				$other->close();
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$out = $this->autocommit(
				static function () use ( $id ) {
					return Plugin::instance()->job_actions()->cancel( $id );
				}
			);
		} finally {
			remove_filter( 'query', $filter );
		}
		if ( null !== $meanwhile ) {
			$this->assertTrue( $ran, 'the control: the other connection changed the row right before the cancel\'s write' );
		}
		return $out;
	}

	public function test_a_second_cancel_of_an_undo_a_run_holds_is_said_as_requested_not_refused(): void {
		list( $job ) = $this->claimed();
		$this->set(
			$job->id,
			array(
				'lock_token'   => 'held-by-a-run',
				'locked_until' => time() + 300,
			)
		);
		$this->assertSame( 'undo_requested', $this->cancel_with( $job->id )['reason'] );
		$this->assertSame( 'undo_requested', $this->cancel_with( $job->id )['reason'], 'the request is there: still a request, not "it is changing the site"' );
		$this->set(
			$job->id,
			array(
				'lock_token'   => '',
				'locked_until' => 0,
			)
		);
		$this->assertSame( Job::COMPLETED, $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) )->status, 'and it gives itself up' );
	}

	public function test_a_cancel_whose_undo_changed_meanwhile_is_said_as_what_the_row_is_now(): void {
		$table = JobRepository::table();
		$cases = array(
			'given up by its run'       => array(
				static function ( \wpdb $other ) use ( $table ): callable {
					return static function ( int $id ) use ( $other, $table ): void {
						$other->query( $other->prepare( "UPDATE {$table} SET status = %s, cursor_json = '[]' WHERE id = %d", Job::COMPLETED, $id ) );
					};
				},
				'undo_given_up',
			),
			'ended'                     => array(
				static function ( \wpdb $other ) use ( $table ): callable {
					return static function ( int $id ) use ( $other, $table ): void {
						$other->query( $other->prepare( "UPDATE {$table} SET status = %s, site_state = %d, cursor_json = %s WHERE id = %d", Job::UNDONE, Job::SITE_UNTOUCHED, wp_json_encode( array( 'phase' => 'reverted', 'reason' => SwapStep::ASKED, 'out' => true ) ), $id ) );
					};
				},
				'undo_ended',
			),
			'started changing the site' => array(
				static function ( \wpdb $other ) use ( $table ): callable {
					return static function ( int $id ) use ( $other, $table ): void {
						$other->query( $other->prepare( "UPDATE {$table} SET site_state = %d, cursor_json = %s WHERE id = %d", Job::SITE_CHANGING, wp_json_encode( array( 'phase' => 'reverse', 'reason' => SwapStep::ASKED, 'out' => true ) ), $id ) );
					};
				},
				'undoing',
			),
		);
		foreach ( $cases as $case => $how ) {
			$this->tear_down_swap();
			$this->set_up_swap();
			list( $job ) = $this->claimed();
			$change      = null;
			$out         = $this->cancel_with(
				$job->id,
				static function ( \wpdb $other ) use ( $how, $job, &$change ): void {
					$change = $how[0]( $other );
					$change( $job->id );
				}
			);
			$this->assertSame( $how[1], $out['reason'], $case );
			$this->assertSame( 0, Plugin::instance()->jobs()->find( $job->id )->cancel_requested, $case . ': nothing written by the cancel' );
			// Back to what the tear-down puts away: the site as the undo was claimed (nothing of it was changed here).
			$this->set(
				$job->id,
				array(
					'status'      => Job::COMPLETED,
					'site_state'  => Job::SITE_SWAPPED,
					'cursor_json' => '[]',
				)
			);
		}
	}

	public function test_an_undo_that_failed_before_it_changed_the_site_is_given_up_by_a_cancel(): void {
		list( $job, $after ) = $this->claimed();
		$this->set(
			$job->id,
			array(
				'status'       => Job::FAILED,
				'failure_kind' => Job::stamp_failure( Job::FAILURE_TEMPORARY, time() ),
				'last_error'   => 'The database server went away.',
				'finished_at'  => time(),
			)
		);
		$out = $this->cancel_with( $job->id );
		$this->assertSame( 'undo_given_up', $out['reason'] );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::COMPLETED, $now->status );
		$this->assertSame( '', $now->last_error, 'no failure kept' );
		$this->assertSame( '', $now->failure_kind );
		$this->assertSame( $after, $this->site() );
	}

	public function test_an_undo_given_up_at_once_takes_the_lock_file_of_a_run_that_died_with_it(): void {
		list( $job ) = $this->claimed();
		$base = Plugin::instance()->jobs()->files_base( $job );
		$this->set(
			$job->id,
			array(
				'lock_token'   => 'a-run-that-died',
				'locked_until' => time() - 60,
			)
		);
		$this->assertTrue( \WPCheckpoint\Jobs\LockFile::write( $base, $job->id, 'a-run-that-died', time() - 60 ) );
		clearstatcache();
		$this->assertFileExists( \WPCheckpoint\Jobs\LockFile::path( $base, $job->id ), 'the control: the lock file is there' );
		$this->assertSame( 'undo_given_up', $this->cancel_with( $job->id )['reason'] );
		clearstatcache();
		$this->assertFileDoesNotExist( \WPCheckpoint\Jobs\LockFile::path( $base, $job->id ) );
	}

	public function test_the_rest_cancel_of_an_undo_says_what_happened(): void {
		list( $job ) = $this->claimed();
		$this->autocommit(
			function () use ( $job ): void {
				$response = $this->rest( 'POST', 'jobs/' . $job->id . '/cancel' );
				$this->assertSame( 200, $response->get_status() );
				$this->assertSame( 'undo_given_up', $response->get_data()['result'] ?? null );
			}
		);
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $job->id )->status );
		list( $second ) = $this->claimed_again( $job );
		$this->killed_at( $second, 'table_back', 1 );
		$this->autocommit(
			function () use ( $second ): void {
				$response = $this->rest( 'POST', 'jobs/' . $second->id . '/cancel' );
				$this->assertSame( 409, $response->get_status(), 'refused once the undo changed the site' );
			}
		);
		$this->assertSame( Job::UNDONE, $this->cli_run( Plugin::instance()->jobs()->find( $second->id ) )->status );
	}

	/**
	 * The same restore claimed for an undo again (after an earlier undo was given up).
	 *
	 * @return array{0: Job}
	 */
	private function claimed_again( Job $job ): array {
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		return array( Plugin::instance()->jobs()->find( $job->id ) );
	}

	public function test_a_cancel_before_the_undo_changed_the_site_gives_it_up_at_once(): void {
		list( $job, $after ) = $this->claimed();
		$out = Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( 'undo_given_up', $out['reason'] );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::COMPLETED, $now->status, 'the restore completed again' );
		$this->assertSame( Job::SITE_SWAPPED, $now->site_state );
		$this->assertSame( 0, $now->cancel_requested );
		$this->assertSame( $job->committed_at, $now->committed_at );
		$this->assertSame( $after, $this->site(), 'the site untouched' );
		$this->assertTrue( $this->actions()->undo( $job->id, '' )['ok'], 'and it can be undone again' );
	}

	public function test_a_cancel_while_a_run_holds_the_undo_before_it_changed_the_site_is_seen_by_that_run(): void {
		list( $job, $after ) = $this->claimed();
		// A run holds it (its lease live): the cancel is a request, which the run sees before it changes anything.
		$this->set(
			$job->id,
			array(
				'lock_token'   => 'held-by-a-run',
				'locked_until' => time() + 300,
			)
		);
		$out = Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( 'undo_requested', $out['reason'] );
		$this->assertGreaterThan( 0, Plugin::instance()->jobs()->find( $job->id )->cancel_requested );
		$this->set(
			$job->id,
			array(
				'lock_token'   => '',
				'locked_until' => 0,
			)
		);
		$this->seams = array();
		$done        = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $done->status );
		$this->assertSame( 0, $done->cancel_requested );
		$this->assertContains( 'undo_not_started', $this->seams );
		$this->assertNotContains( 'undo_started', $this->seams, 'it never started changing the site' );
		$this->assertSame( $after, $this->site() );
		$log = Plugin::instance()->job_presenter()->log_tail( $done );
		$this->assertStringContainsString( 'cancelled before it changed the site', $log );
		$this->assertStringNotContainsString( 'the swap was complete before it could act', $log, 'not said as a cancel that came too late' );
	}

	/**
	 * Another connection, in a process of its own: it runs $sql in a transaction, says how many rows it changed, and
	 * commits $seconds later. Returns once the statement ran (not committed yet).
	 *
	 * @param string $sql     Statement.
	 * @param float  $seconds How long it holds the transaction open.
	 * @return array{process: resource, dir: string, affected: int}
	 */
	private function hold_row( string $sql, float $seconds ): array {
		$dir  = Sandbox::make( 'undo-race' );
		$flag = $dir . '/ran';
		file_put_contents(
			$dir . '/child.php',
			'<?php' . "\n" .
			'$c = json_decode( file_get_contents( $argv[1] ), true );' . "\n" .
			'$db = mysqli_init();' . "\n" .
			'mysqli_real_connect( $db, $c["host"], $c["user"], $c["password"], $c["name"], $c["port"] );' . "\n" .
			'mysqli_query( $db, "START TRANSACTION" );' . "\n" .
			'mysqli_query( $db, $c["sql"] );' . "\n" .
			'file_put_contents( $c["flag"], (string) mysqli_affected_rows( $db ) );' . "\n" .
			'usleep( (int) $c["hold"] );' . "\n" .
			'mysqli_query( $db, "COMMIT" );' . "\n"
		);
		$host = explode( ':', DB_HOST );
		file_put_contents(
			$dir . '/config.json',
			(string) wp_json_encode(
				array(
					'host'     => $host[0],
					'port'     => isset( $host[1] ) && is_numeric( $host[1] ) ? (int) $host[1] : 3306,
					'user'     => DB_USER,
					'password' => DB_PASSWORD,
					'name'     => DB_NAME,
					'sql'      => $sql,
					'flag'     => $flag,
					'hold'     => (int) ( $seconds * 1000000 ),
				)
			)
		);
		$process = proc_open( array( PHP_BINARY, $dir . '/child.php', $dir . '/config.json' ), array(), $pipes );
		$this->assertIsResource( $process );
		for ( $i = 0; $i < 100 && ! is_file( $flag ); $i++ ) {
			usleep( 50000 );
		}
		clearstatcache( true, $flag );
		return array(
			'process'  => $process,
			'dir'      => $dir,
			'affected' => (int) @file_get_contents( $flag ), // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test; 0 when it did not run.
		);
	}

	/**
	 * Wait for the other process and remove its sandbox.
	 *
	 * @param array{process: resource, dir: string, affected: int}|null $held hold_row().
	 */
	private function let_go( $held ): void {
		if ( null === $held ) {
			return;
		}
		if ( is_resource( $held['process'] ) ) {
			proc_close( $held['process'] );
		}
		Sandbox::remove( $held['dir'] );
	}

	public function test_a_cancel_not_committed_yet_holds_the_undos_first_change_back_and_the_undo_then_gives_up(): void {
		global $wpdb;
		list( $job, $after ) = $this->claimed();
		// The cancel request, as JobRepository::cancel_undo() writes it with a run holding the job: on the job's row, in
		// another connection's transaction that commits two and a half seconds later.
		$request = $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET cancel_requested = %d WHERE id = %d AND cancel_requested = 0', time(), $job->id );
		$held    = null;
		$from    = 0.0;
		$waited  = -1.0;
		$this->swap_parts['at'] = function ( string $point ) use ( &$held, &$from, &$waited, $request ): void {
			$this->seams[] = $point;
			if ( 'undo_starting' === $point && null === $held ) {
				$held = $this->hold_row( $request, 2.5 ); // Right before the write that starts changing the site.
				$from = microtime( true );
			}
			if ( in_array( $point, array( 'undo_not_started', 'undo_started' ), true ) && $waited < 0 ) {
				$waited = microtime( true ) - $from; // Whichever way the write went.
			}
		};
		$this->retype( $job );
		$this->seams = array();
		try {
			$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
			$this->assertNotNull( $held, 'the control: the other connection ran at the seam' );
			$this->assertSame( 1, $held['affected'], 'the control: its request was written, not committed yet' );
		} finally {
			$this->let_go( $held );
		}
		$this->assertGreaterThanOrEqual( 1.0, $waited, 'the undo\'s write waited for the other transaction' );
		$this->assertNotContains( 'undo_started', $this->seams, 'and then did not start changing the site' );
		$this->assertSame( Job::COMPLETED, $done->status, 'given up' );
		$this->assertSame( Job::SITE_SWAPPED, $done->site_state );
		$this->assertSame( 0, $done->cancel_requested );
		$this->assertSame( $after, $this->site(), 'the site untouched' );
	}

	public function test_an_undo_whose_first_change_is_not_committed_yet_holds_a_cancel_back_which_is_then_refused(): void {
		global $wpdb;
		list( $job ) = $this->claimed();
		$cursor      = array( 'phase' => 'reverse' ) + SwapStep::undo_cursor( 1, $job->site_mark );
		// The undo's first change, as JobRepository::save_progress() writes it: the site state changing, on the condition
		// that no cancel is requested; in another connection's transaction that commits two and a half seconds later.
		$start = $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET site_state = %d, cursor_json = %s WHERE id = %d AND cancel_requested = 0', Job::SITE_CHANGING, wp_json_encode( $cursor ), $job->id );
		$held  = null;
		$wpdb->query( 'COMMIT' ); // The claim, written by this connection: the other one must not wait on it.
		try {
			$held = $this->hold_row( $start, 2.5 );
			$this->assertSame( 1, $held['affected'], 'the control: the undo\'s write ran, not committed yet' );
			$from = microtime( true );
			$out  = $this->autocommit(
				static function () use ( $job ) {
					return Plugin::instance()->job_actions()->cancel( $job->id );
				}
			);
			$waited = microtime( true ) - $from;
		} finally {
			$this->let_go( $held );
		}
		$this->assertGreaterThanOrEqual( 1.0, $waited, 'the cancel waited for the other transaction' );
		$this->assertSame( 'undoing', $out['reason'], 'and was refused once it committed' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 0, $now->cancel_requested, 'nothing recorded' );
		$this->assertSame( Job::SITE_CHANGING, $now->site_state );
		$this->assertSame( Job::QUEUED, $now->status );
		// Put the row back as it was claimed, for the tear-down (nothing of the site was changed).
		$this->set(
			$job->id,
			array(
				'site_state'  => Job::SITE_SWAPPED,
				'cursor_json' => wp_json_encode( SwapStep::undo_cursor( 1, $job->site_mark ) ),
			)
		);
	}

	public function test_a_cancel_that_lands_right_before_the_undo_starts_changing_the_site_wins(): void {
		list( $job, $after ) = $this->claimed();
		// Between the run's read of the row and its write that starts the change.
		$this->swap_parts['at'] = function ( string $point ) use ( $job ): void {
			$this->seams[] = $point;
			if ( 'undo_starting' === $point ) {
				$this->set( $job->id, array( 'cancel_requested' => time() ) );
			}
		};
		$this->retype( $job );
		$this->seams = array();
		$done        = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertContains( 'undo_starting', $this->seams, 'the control: the request landed there' );
		$this->assertNotContains( 'undo_started', $this->seams );
		$this->assertSame( Job::COMPLETED, $done->status, 'given up, not undone' );
		$this->assertSame( $after, $this->site() );
	}

	public function test_a_cancel_after_the_undo_changed_the_site_is_refused_and_the_undo_finishes(): void {
		$before          = $this->site();
		list( $job )     = $this->claimed();
		$this->killed_at( $job, 'table_back', 1 );
		$changing = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::SITE_CHANGING, $changing->site_state, 'the control: the undo changed the site' );
		$out = Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( 'undoing', $out['reason'] );
		$this->assertSame( 0, Plugin::instance()->jobs()->find( $job->id )->cancel_requested, 'nothing recorded' );
		$this->assertStringContainsString( 'finishes putting the site back', \WPCheckpoint\Rest\JobsController::cancel_message( 'undoing' ) );
		$this->assertSame( Job::UNDONE, $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) )->status );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_cancel_of_a_failed_undo_that_changed_the_site_says_it_goes_on_once_retried(): void {
		$before      = $this->site();
		list( $job ) = $this->claimed();
		$this->killed_at( $job, 'table_back', 1 );
		$this->set(
			$job->id,
			array(
				'status'       => Job::FAILED,
				'failure_kind' => Job::stamp_failure( Job::FAILURE_TEMPORARY, time() ),
				'last_error'   => 'The database server went away.',
				'finished_at'  => time(),
				'lock_token'   => '',
				'locked_until' => 0,
			)
		);
		$this->assertSame( Job::SITE_CHANGING, Plugin::instance()->jobs()->find( $job->id )->site_state, 'the control: the undo changed the site' );
		$out = Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( 'undo_failed', $out['reason'] );
		$said = \WPCheckpoint\Rest\JobsController::cancel_message( 'undo_failed' );
		$this->assertStringNotContainsString( 'finishes', $said, 'a failed undo does not finish by itself' );
		$this->assertStringNotContainsString( 'answer keep', $said );
		$this->assertStringContainsString( 'wp wpcheckpoint job retry', $said );
		$this->assertStringContainsString( 'finishes putting the site back', \WPCheckpoint\Rest\JobsController::cancel_message( 'undoing' ), 'the control: the running undo is said to finish' );
		$this->assertTrue( \WPCheckpoint\Cli\JobCommand::cancel_verdict( 'undo_failed' )['error'], 'refused' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $now->status, 'not cancelled' );
		$this->assertSame( 0, $now->cancel_requested, 'nothing recorded' );
		// What it says is what happens: retried, it goes on and puts the site back.
		$this->assertSame( Job::QUEUED, $this->actions()->retry( $job->id )->status );
		$this->assertSame( Job::UNDONE, $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) )->status );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_cancel_of_a_failed_undo_whose_work_was_reclaimed_does_not_say_to_retry_it(): void {
		list( $job ) = $this->claimed();
		$at          = time() - JobRepository::WORK_RETENTION_SECONDS - 60;
		$this->set(
			$job->id,
			array(
				'site_state'   => Job::SITE_UNTOUCHED,
				'committed_at' => 0,
				'cursor_json'  => wp_json_encode( array( 'phase' => 'reverted' ) + SwapStep::undo_cursor( 1, 'mark' ) ),
				'status'       => Job::FAILED,
				'finished_at'  => $at,
				'failure_kind' => Job::stamp_failure( Job::FAILURE_TEMPORARY, $at ),
			)
		);
		$this->assertSame( 'undo_failed', Plugin::instance()->job_actions()->cancel( $job->id )['reason'], 'the control: while it can be retried, it is said so' );
		$this->set( $job->id, array( 'work_expired_at' => time() ) );
		$this->assertFalse( Plugin::instance()->jobs()->find( $job->id )->can_retry(), 'the control: it cannot be retried' );
		$this->assertSame( 'undo_expired', Plugin::instance()->job_actions()->cancel( $job->id )['reason'] );
		$said = \WPCheckpoint\Rest\JobsController::cancel_message( 'undo_expired' );
		$this->assertStringNotContainsString( 'job retry', $said );
		$this->assertStringContainsString( 'can no longer be retried', $said );
		$this->assertTrue( \WPCheckpoint\Cli\JobCommand::cancel_verdict( 'undo_expired' )['error'], 'refused' );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $job->id )->status, 'not cancelled' );
	}

	public function test_a_cancel_of_an_undo_put_back_and_managed_elsewhere_does_not_say_it_holds_the_site(): void {
		list( $job ) = $this->claimed();
		$this->set(
			$job->id,
			array(
				'site_state'   => Job::SITE_UNTOUCHED,
				'committed_at' => 0,
				'cursor_json'  => wp_json_encode( array( 'phase' => 'reverted' ) + SwapStep::undo_cursor( 1, 'mark' ) ),
				'held_by'      => 'another0another0another0another0',
			)
		);
		$out = Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( 'managed_elsewhere', $out['reason'] );
		$this->assertStringNotContainsString( 'holds the site', \WPCheckpoint\Rest\JobsController::cancel_message( 'managed_elsewhere' ) );
		$this->assertStringContainsString( 'holds the site changed', \WPCheckpoint\Rest\JobsController::cancel_message( 'elsewhere' ), 'the control: a row holding the site is said so' );
		$this->assertTrue( \WPCheckpoint\Cli\JobCommand::cancel_verdict( 'managed_elsewhere' )['error'], 'refused' );
		$this->assertNotSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $job->id )->status );
	}

	public function test_every_reason_a_cancel_refuses_with_is_a_conflict_in_rest_and_an_error_in_wp_cli(): void {
		foreach ( \WPCheckpoint\Jobs\JobActions::CANCEL_REFUSED as $reason ) {
			$this->assertTrue( \WPCheckpoint\Cli\JobCommand::cancel_verdict( $reason )['error'], $reason );
			$this->assertNotSame( \WPCheckpoint\Rest\JobsController::cancel_message( 'cleaned' ), \WPCheckpoint\Rest\JobsController::cancel_message( $reason ), $reason . ': its own words, not "cancelled"' );
		}
		$this->assertFalse( \WPCheckpoint\Cli\JobCommand::cancel_verdict( 'undo_given_up' )['error'], 'the control: a cancel that gave the undo up is a success' );
	}

	public function test_an_undo_whose_window_closed_after_it_was_claimed_is_given_up_before_it_changes_the_site(): void {
		list( $job, $after ) = $this->claimed();
		$this->set( $job->id, array( 'committed_at' => time() - Undo::WINDOW_SECONDS - 60 ) );
		$this->seams = array();
		$done        = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $done->status );
		$this->assertNotContains( 'undo_started', $this->seams );
		$this->assertSame( $after, $this->site(), 'nothing put back' );
		$this->assertStringContainsString( 'ran out before the undo changed the site', Plugin::instance()->job_presenter()->log_tail( $done ) );
	}

	public function test_an_unanswered_question_about_the_carry_back_is_given_up_at_its_deadline(): void {
		list( $job, , $after ) = $this->asked_about_the_carry();
		// Asked long ago, the deadline not reached: left.
		$this->set( $job->id, array( 'updated_at' => time() - JobRepository::WORK_RETENTION_SECONDS - 60 ) );
		Plugin::instance()->jobs()->reap();
		$this->assertSame( array(), (array) ( Plugin::instance()->jobs()->find( $job->id )->options['answers'] ?? array() ), 'not answered before its deadline' );
		// The deadline (from the commit) passed, however recently it was asked.
		$this->set(
			$job->id,
			array(
				'committed_at' => time() - Undo::WINDOW_SECONDS - 60,
				'updated_at'   => time(),
			)
		);
		Plugin::instance()->jobs()->reap();
		$answered = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( RestoreJob::KEEP, $answered->options['answers'][ SwapStep::CARRY_QUESTION ] ?? null, 'answered "keep"' );
		$this->assertStringContainsString( 'No answer by the deadline', $answered->progress_message );
		$kept = $this->cli_run( $answered );
		$this->assertSame( Job::COMPLETED, $kept->status );
		$this->assertSame( $after, $this->site(), 'the restored site stays' );
		$log = Plugin::instance()->job_presenter()->log_tail( $kept );
		$this->assertStringContainsString( 'The deadline to answer passed', $log );
		$this->assertStringNotContainsString( 'as the administrator chose', $log );
	}

	public function test_putting_back_answered_after_the_deadline_keeps_the_restored_site_and_says_why(): void {
		list( $job, , $after ) = $this->asked_about_the_carry();
		$this->set( $job->id, array( 'committed_at' => time() - Undo::WINDOW_SECONDS - 60 ) );
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::CARRY_QUESTION => SwapStep::PUT_BACK ) );
		$kept = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $kept->status );
		$this->assertNotContains( 'table_back', $this->seams, 'nothing was put back' );
		$this->assertSame( $after, $this->site() );
		$log = Plugin::instance()->job_presenter()->log_tail( $kept );
		$this->assertStringContainsString( 'came after the deadline', $log );
		$this->assertStringNotContainsString( 'as the administrator chose', $log );
	}

	public function test_the_carry_question_names_its_deadline(): void {
		list( $job ) = $this->asked_about_the_carry();
		// Committed two days ago: the deadline is five days from now, not seven (it counts from the commit).
		$this->set( $job->id, array( 'committed_at' => time() - 2 * 86400 ) );
		$job  = Plugin::instance()->jobs()->find( $job->id );
		$text = QuestionText::for_job( $job, Plugin::instance()->directories(), 'strval' );
		$this->assertStringContainsString( 'Answer by ' . gmdate( 'Y-m-d H:i', $job->committed_at + Undo::WINDOW_SECONDS ) . ' UTC', $text[0]['text'] );
		$this->assertStringNotContainsString( '7 days', $text[0]['text'] );
	}

	public function test_restores_are_undone_from_the_latest_back(): void {
		$before = $this->site();
		$first  = $this->completed();
		$middle = $this->site();
		$second = $this->restore_again();
		$this->assertGreaterThanOrEqual( $first->committed_at, $second->committed_at );
		$this->assertStringContainsString( 'Restore job ' . $second->id . ' swapped the site after restore job ' . $first->id, $this->actions()->undo( $first->id, '' )['message'], 'the earlier one first: refused' );
		$this->assertSame( Job::UNDONE, $this->undone( $second )->status, 'the later one first' );
		$this->assertSame( $middle, $this->site(), 'the site as the first restore left it' );
		$this->assertSame( 0, Plugin::instance()->jobs()->find( $second->id )->committed_at, 'its swap stands no more' );
		$this->assertSame( Job::UNDONE, $this->undone( Plugin::instance()->jobs()->find( $first->id ) )->status, 'then the earlier one' );
		$this->assertSame( $before, $this->site(), 'the site as it was before both' );
	}

	/**
	 * A second restore of the site as it is now (after completed()): its tables and files backed up as they are, then
	 * restored and run to its end, so that undoing it puts back exactly what the first restore left.
	 *
	 * @return Job
	 */
	private function restore_again(): Job {
		global $wpdb;
		$tables  = self::live_tables();
		$base    = $this->backup( $tables, null, null, array( 'files' => self::backup_files() ) );
		$exclude = array_values( array_diff( $tables, array_merge( array( $wpdb->prefix . 'swt_keep', $wpdb->prefix . 'swt_new' ), self::site_tables() ) ) );
		$jobs    = Plugin::instance()->jobs();
		$job     = $jobs->create(
			$this->type,
			self::$admin_id,
			array(),
			array(
				'base'           => $base,
				'exclude_tables' => $exclude,
			)
		);
		$runner  = Plugin::instance()->runner();
		for ( $i = 0; $i < 500; $i++ ) {
			$result = $this->autocommit(
				static function () use ( $runner, $job ): \WPCheckpoint\Jobs\TickResult {
					return $runner->tick( $job->id, microtime( true ) );
				}
			);
			$now    = $jobs->find( $job->id );
			if ( \WPCheckpoint\Jobs\TickResult::CLI === $result->status || ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
		}
		$done = $this->cli_run( $jobs->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $done->status, 'the second restore: ' . $done->last_error );
		return $done;
	}

	public function test_the_carry_question_is_shown_when_the_names_could_not_be_read(): void {
		list( $job ) = $this->asked_about_the_carry();
		\WPCheckpoint\Jobs\ExportPlan::write(
			$this->work( $job ),
			RestoreFiles::CARRY_BACK,
			array(
				'reason' => 'RuntimeException: crashed',
				'names'  => null,
			)
		);
		$text = QuestionText::for_job( $job, Plugin::instance()->directories(), 'strval' );
		$this->assertSame( array(), $text[0]['listed'] );
		$this->assertStringContainsString( 'which of WP Checkpoint\'s own settings would be lost could not be read', $text[0]['text'] );
		$this->assertStringContainsString( 'everything written on the site since the restore is dropped', $text[0]['text'] );
	}
}
