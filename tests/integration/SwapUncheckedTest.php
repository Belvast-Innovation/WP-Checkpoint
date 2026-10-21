<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Restore\SelfCheck;
use WPCheckpoint\Support\StoredNames;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A restored site whose checks could not tell whether it works: checked again after waits (a new key each time),
 * then let out and asked whether to keep it (undo puts the site back, the maintenance file held again first), or
 * decided by the restore policy without asking (undo: in maintenance mode, never let out); nobody answering for 7
 * days keeps it, and the job is not failed. The checks are stood in for ("verify" part), or made for real with every
 * request failing (pre_http_request).
 */
final class SwapUncheckedTest extends SwapTestCase {

	/**
	 * What a check that could not tell says.
	 *
	 * @return array{verdict: string, check: string, why: string, status: int}
	 */
	private static function unknown(): array {
		return array(
			'verdict' => 'unknown',
			'check'   => 'home',
			'why'     => 'status',
			'status'  => 404,
		);
	}

	/**
	 * WP-CLI ticks through the waits until the job is no longer queued or running.
	 *
	 * @param Job   $job   Job.
	 * @param int[] $waits The waits seen (filled).
	 * @return Job
	 */
	private function through( Job $job, array &$waits = array() ): Job {
		for ( $i = 0; $i < 60; $i++ ) {
			$result = $this->cli_tick( $job );
			if ( TickResult::WAITING === $result->status ) {
				$waits[] = $result->retry_after;
			}
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				return $now;
			}
		}
		$this->fail( 'the job did not stop' );
	}

	private function row(): ?string {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . SelfCheck::table( $wpdb->base_prefix ) . ' WHERE option_name = %s', StoredNames::SELF_CHECK ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the options table.
		return null === $value ? null : (string) $value;
	}

	private function asked( Job $job ): Job {
		$waits = array();
		$keys  = array();
		$this->swap_parts['verify'] = static function ( $context, array $pass ) use ( &$keys ): array {
			$keys[] = $pass['key'];
			return self::unknown();
		};
		$this->retype( $job );
		$paused = $this->through( $job, $waits );
		$this->assertSame( Job::PAUSED, $paused->status, (string) $paused->last_error );
		$this->assertSame( array( 15, 60, 120 ), $waits, 'checked again after the waits' );
		$this->assertCount( SwapStep::CHECK_TRIES, array_unique( $keys ), 'four tries, each with a key of its own' );
		$this->assertSame( SwapStep::UNVERIFIED_QUESTION, $paused->questions[0]['id'] ?? null );
		$this->assertSame( array( 'keep', 'undo' ), $paused->questions[0]['choices'] ?? null );
		$this->assertSame( Job::SITE_SWAPPED, $paused->site_state, 'it holds the site, swapped' );
		$this->assertSame( array(), $this->maintenance_files(), 'open to visitors while it waits: the file is down' );
		$this->assertNull( $this->row(), 'the self-check row is gone' );
		return $paused;
	}

	public function test_a_check_that_could_not_tell_is_made_again_after_waits_with_a_new_key(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$tries  = 0;
		$keys   = array();
		$this->swap_parts['verify'] = static function ( $context, array $pass ) use ( &$tries, &$keys ) {
			$keys[] = $pass['key'];
			return ++$tries < 4 ? self::unknown() : true;
		};
		$this->retype( $job );
		$waits = array();
		$done  = $this->through( $job, $waits );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertSame( array( 15, 60, 120 ), $waits );
		$this->assertCount( 4, array_unique( $keys ) );
		$this->assertRestored( $before );
		$this->assertSame( array(), $done->questions, 'passed on the last try: nothing asked' );
	}

	public function test_a_site_that_could_not_be_checked_is_let_out_and_asked_about_and_kept(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$paused = $this->asked( $job );
		$this->assertRestored( $before );
		$text = QuestionText::for_job( $paused, Plugin::instance()->directories(), array( Plugin::instance()->job_presenter(), 'clean' ) )[0]['text'] ?? '';
		$this->assertStringContainsString( 'The restored site could not be checked after the swap: the home page answered HTTP 404.', $text );
		$this->assertStringContainsString( 'everything written on the site since the swap', $text, 'what undoing costs' );
		$this->assertStringContainsString( sprintf( 'Restore job %d waits for an answer: whether to keep the restored site', $job->id ), implode( "\n", Plugin::instance()->half_swapped_warnings() ), 'WP-CLI says what it waits for' );
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::UNVERIFIED_QUESTION => 'keep' ) );
		$done = $this->through( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertRestored( $before );
		$this->assertSame( array(), $this->maintenance_files() );
	}

	public function test_undo_after_the_site_was_let_out_holds_the_file_again_and_puts_the_site_back(): void {
		$before = $this->site();
		$job    = $this->at_swap();
		$this->asked( $job );
		$held = array();
		$this->swap_parts['at'] = function ( string $point ) use ( &$held ): void {
			$this->seams[] = $point;
			if ( 'table_back' === $point ) {
				$held[] = Maintenance::held_in( $this->abspath );
			}
		};
		$this->retype( $job );
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::UNVERIFIED_QUESTION => 'undo' ) );
		$done = $this->through( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'could not be checked, and the swap was undone as chosen', (string) $done->last_error );
		$this->assertNotSame( array(), $held, 'the control: tables were put back' );
		$this->assertSame( array( true ), array_unique( $held ), 'held again before the first rename back' );
		$this->assertSame( $before, $this->site(), 'the site as it was' );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
	}

	public function test_undo_after_the_site_was_let_out_waits_for_someone_elses_maintenance_file(): void {
		global $wpdb;
		$before = $this->site();
		$job    = $this->at_swap();
		$this->asked( $job );
		$restored = $this->site();
		unset( $restored['maintenance'] );
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::UNVERIFIED_QUESTION => 'undo' ) );
		$update = "<?php \$upgrading = " . time() . "; // an update\n";
		file_put_contents( $this->abspath . '/.maintenance', $update );
		foreach ( array( 1, 2 ) as $tick ) {
			$result = $this->cli_tick( $job );
			$this->assertSame( TickResult::WAITING, $result->status, $tick . ': ' . $result->message );
			$this->assertStringContainsString( 'Another maintenance file is in the WordPress directory', $result->message, $tick );
			$now = Plugin::instance()->jobs()->find( $job->id );
			$this->assertNotSame( 'reverse', $now->cursor['phase'] ?? '', $tick . ': the undo not recorded yet' );
			$this->assertSame( 0, (int) ( $now->cursor['__runner']['retries'] ?? 0 ), $tick . ': nothing counted against it' );
			$site = $this->site();
			unset( $site['maintenance'] );
			$this->assertSame( $restored, $site, $tick . ': nothing moved' );
			$this->assertSame( array( '.maintenance' => $update ), $this->maintenance_files(), $tick . ': left as it was' );
		}
		$this->remove_maintenance();
		// Once it is gone the undo starts; another file put up after the first rename back is waited out too.
		$seen = 0;
		$this->swap_parts['at'] = function ( string $point ) use ( &$seen, $update ): void {
			$this->seams[] = $point;
			if ( 'table_back' === $point && 1 === ++$seen ) {
				$this->remove_maintenance(); // An update takes the held file down and puts up its own.
				file_put_contents( $this->abspath . '/.maintenance', $update );
			}
		};
		$this->retype( $job );
		$result = $this->cli_tick( $job );
		$this->assertGreaterThanOrEqual( 1, $seen, 'the control: tables were put back (all of them before the directories)' );
		$this->assertSame( TickResult::WAITING, $result->status, $result->message );
		$this->assertStringContainsString( 'the undo waits for it to go before it moves anything', $result->message );
		$this->assertSame( 'reverse', Plugin::instance()->jobs()->find( $job->id )->cursor['phase'] ?? '', 'recorded, and continued from there' );
		$result = $this->cli_tick( $job );
		$this->assertSame( TickResult::WAITING, $result->status, 'a later run waits too: ' . $result->message );
		$this->remove_maintenance();
		$done = $this->through( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'the swap was undone as chosen', (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'once it is gone, put back' );
	}

	public function test_a_cancel_after_the_site_was_put_back_takes_its_own_bare_file_down(): void {
		$job  = $this->at_swap();
		$plan = $this->plan_of( $job );
		$last = $plan['dirs'][ count( $plan['dirs'] ) - 1 ];
		rename( $last['stage'], $last['stage'] . '-away' ); // Its rename fails: the swap rolls back.
		$this->killed_at( $job, 'reverted', 1 );
		rename( $last['stage'] . '-away', $last['stage'] );
		$this->assertSame( 'reverted', Plugin::instance()->jobs()->find( $job->id )->cursor['phase'] ?? '' );
		\WPCheckpoint\Tests\Fixtures\Sandbox::remove( $this->abspath . '/' . Maintenance::DATA ); // Its data file taken away (a scanner).
		$this->assertSame( array( '.maintenance' ), array_keys( $this->maintenance_files() ), 'the control: the code is left, naming no restore' );
		Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $job->id )->status );
		$this->assertSame( array(), $this->maintenance_files(), 'this job\'s, put up and let go by it: taken down' );
	}

	public function test_a_cancel_leaves_a_maintenance_file_that_names_no_restore(): void {
		$job = $this->at_swap();
		file_put_contents( $this->abspath . '/.maintenance', Maintenance::CODE ); // No data file: it names no restore.
		$result = $this->cli_tick( $job );
		$this->assertSame( TickResult::WAITING, $result->status, 'the control: the swap waits for it' );
		$this->assertStringContainsString( 'without the data file that names its restore', $result->message );
		Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $job->id )->status );
		$this->assertSame( array( '.maintenance' => Maintenance::CODE ), $this->maintenance_files(), 'not this restore\'s to take down' );
		$this->remove_maintenance();
		// The control: the same cancel takes this restore's own file down (the cleanup runs, and removes).
		$this->tear_down_swap();
		$this->set_up_swap();
		$this->register_type();
		$job = $this->at_swap();
		file_put_contents( $this->abspath . '/.maintenance', "<?php \$upgrading = time(); // an update\n" );
		$this->assertSame( TickResult::WAITING, $this->cli_tick( $job )->status, 'the control: it waits, its mark recorded' );
		$mark = (string) ( Plugin::instance()->jobs()->find( $job->id )->cursor['mark'] ?? '' );
		$this->assertNotSame( '', $mark );
		$this->remove_maintenance();
		$this->put_maintenance( $mark, time() - 3600 );
		Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $job->id )->status );
		$this->assertSame( array(), $this->maintenance_files(), 'its own file goes with the cancel' );
	}

	public function test_the_policy_undo_puts_the_site_back_in_maintenance_mode_without_letting_it_out(): void {
		$before = $this->site();
		$job    = $this->at_swap( array( 'policy' => array( 'unverified' => 'undo' ) ) );
		$this->swap_parts['verify'] = static function (): array {
			return self::unknown();
		};
		$this->retype( $job );
		$done = $this->through( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'could not be checked, and the swap was undone as chosen', (string) $done->last_error );
		$this->assertNotContains( 'unheld_commit', $this->seams, 'never let out' );
		$this->assertNotContains( 'asked', $this->seams );
		$this->assertContains( 'self_check_removed', $this->seams, 'the control: the seams are seen' );
		$this->assertSame( $before, $this->site() );
		$this->assertSame( array(), $done->questions );
	}

	public function test_the_policy_keep_keeps_the_site_without_asking(): void {
		$job    = $this->at_swap( array( 'policy' => array( 'unverified' => 'keep' ) ) );
		$before = $this->site();
		$this->swap_parts['verify'] = static function (): array {
			return self::unknown();
		};
		$this->retype( $job );
		$done = $this->through( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertNotContains( 'asked', $this->seams );
		$this->assertContains( 'unheld_commit', $this->seams, 'the control: let out' );
		$this->assertRestored( $before );
	}

	public function test_seven_days_without_an_answer_keep_the_site_and_the_next_wp_cli_run_finishes(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$this->asked( $job );
		$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'updated_at' => time() - 8 * 86400 ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		Plugin::instance()->jobs()->reap();
		$kept = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::PAUSED, $kept->status, 'not failed: ' . (string) $kept->last_error );
		$this->assertSame( array(), $kept->questions, 'answered' );
		$this->assertSame( 'keep', $kept->options['answers'][ SwapStep::UNVERIFIED_QUESTION ] ?? null );
		$this->assertStringContainsString( 'No answer within 7 days: the restored site, which could not be checked, was kept. Run wp wpcheckpoint job run ' . $job->id . ' to finish the restore.', (string) $kept->progress_message );
		$this->assertTrue( Plugin::instance()->jobs()->holds_site( $kept ), 'it still holds the site' );
		$held = Uninstaller::holding();
		$this->assertSame( array( $job->id ), $held['kept'] ?? null );
		$this->assertStringContainsString( 'then finish the restore with: wp wpcheckpoint job run ' . $job->id, Uninstaller::held_back( $held ) );
		$done = $this->through( $kept );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertRestored( $before );
		$this->assertFalse( Plugin::instance()->jobs()->holds_site( $done ) );
	}

	public function test_requests_that_all_fail_cannot_be_told_and_the_site_is_asked_about(): void {
		$this->real_checks          = true;
		$this->swap_parts['urls']   = static function (): array {
			return array(
				'home'  => 'http://site.invalid/?wpcheckpoint_check=1',
				'login' => 'http://site.invalid/wp-login.php?wpcheckpoint_check=1',
				'self'  => 'http://site.invalid/?rest_route=' . SelfCheck::PATH,
			);
		};
		$asked = 0;
		$block = static function () use ( &$asked ) {
			++$asked;
			return new \WP_Error( 'http_request_failed', 'blocked by the test' );
		};
		$this->register_type();
		$job = $this->at_swap(); // The steps before the swap make their own requests: blocked only for the checks.
		add_filter( 'pre_http_request', $block );
		try {
			$done = $this->through( $job );
		} finally {
			remove_filter( 'pre_http_request', $block );
		}
		$this->assertSame( Job::PAUSED, $done->status, (string) $done->last_error );
		$this->assertSame( SwapStep::UNVERIFIED_QUESTION, $done->questions[0]['id'] ?? null );
		$this->assertGreaterThanOrEqual( SwapStep::CHECK_TRIES * 3, $asked, 'the control: every request went to the filter' );
		$text = QuestionText::for_job( $done, Plugin::instance()->directories(), array( Plugin::instance()->job_presenter(), 'clean' ) )[0]['text'] ?? '';
		$this->assertStringContainsString( 'did not answer (no connection, or no answer in time)', $text );
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::UNVERIFIED_QUESTION => 'keep' ) );
		$this->assertSame( Job::COMPLETED, $this->through( $job )->status );
	}

	public function test_a_job_that_left_the_self_check_row_has_it_removed_when_its_work_is_reclaimed_and_only_its_own(): void {
		global $wpdb;
		$repo   = Plugin::instance()->jobs();
		$table  = SelfCheck::table( $wpdb->base_prefix );
		$failed = $repo->create( \WPCheckpoint\Jobs\RestoreJob::ID, self::$admin_id, array(), array( 'base' => 'site-20260923-120000-ab12' ) );
		$failed = $repo->transition( $failed, Job::FAILED, 'Stopped.' );
		$other  = SelfCheck::value( $failed->id + 1000, str_repeat( 'c', 64 ), str_repeat( 'd', 64 ) );
		$wpdb->replace( $table, array( 'option_name' => StoredNames::SELF_CHECK, 'option_value' => $other, 'autoload' => 'no' ) );
		$wpdb->query( 'COMMIT' );
		$repo->reclaim_work( $failed );
		$this->assertSame( $other, $this->row(), 'another job\'s row stays' );
		$own = SelfCheck::value( $failed->id, str_repeat( 'c', 64 ), str_repeat( 'd', 64 ) );
		$wpdb->replace( $table, array( 'option_name' => StoredNames::SELF_CHECK, 'option_value' => $own, 'autoload' => 'no' ) );
		$wpdb->query( 'COMMIT' );
		$this->assertSame( $own, $this->row(), 'the control: its own row is there' );
		$holding = clone $failed;
		$holding->site_state = Job::SITE_SWAPPED;
		$repo->reclaim_work( $holding );
		$this->assertSame( $own, $this->row(), 'a job that holds the site is not reclaimed: its row stays' );
		$repo->reclaim_work( $failed );
		$this->assertNull( $this->row(), 'its own row goes with its work' );
	}

	public function test_the_checks_stop_at_their_next_request_once_another_run_took_the_job_over(): void {
		global $wpdb;
		$this->real_checks        = true;
		$this->swap_parts['urls'] = static function (): array {
			return array(
				'home'  => 'http://site.invalid/?wpcheckpoint_check=1',
				'login' => 'http://site.invalid/wp-login.php?wpcheckpoint_check=1',
				'self'  => 'http://site.invalid/?rest_route=' . SelfCheck::PATH,
			);
		};
		$this->register_type();
		$job      = $this->at_swap();
		$requests = 0;
		$taken    = static function () use ( &$requests, $wpdb, $job ) {
			if ( 1 === ++$requests ) {
				// Another run takes the job over while this one is in its first request.
				$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'lock_token' => 'another run' ), array( 'id' => $job->id ) );
				$wpdb->query( 'COMMIT' );
			}
			return new \WP_Error( 'http_request_failed', 'no answer' );
		};
		add_filter( 'pre_http_request', $taken );
		try {
			for ( $i = 0; $i < 10 && 0 === $requests; $i++ ) {
				try {
					$this->cli_tick( $job );
				} catch ( \Throwable $e ) {
					$this->assertStringContainsString( 'no longer held', $e->getMessage() );
				}
			}
		} finally {
			remove_filter( 'pre_http_request', $taken );
		}
		$this->assertSame( 1, $requests, 'no request after the one during which the job was taken over' );
		$this->assertSame( 'another run', Plugin::instance()->jobs()->find( $job->id )->lock_token, 'the control: it was taken over' );
	}
}
