<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Admin\LogDownload;
use WPCheckpoint\Cli\JobCommand;
use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\PutBack;
use WPCheckpoint\Jobs\PutBackEvidence;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * wp wpcheckpoint job put-back (JobActions::put_back(), Jobs\PutBack): a restore that is stuck while it holds the site
 * is put back from its plan, what is there and its maintenance mark, without its cursor or options. A swap half made
 * ends cancelled, one committed ends undone. What the claim writes over is kept byte for byte next to the job's log
 * (PutBackEvidence) and the claim writes only while the row still holds it. Every refusal has its control.
 */
final class PutBackTest extends SwapTestCase {

	/** @var string[] Evidence directories there before the test (the test removes the ones it made). */
	private $evidence_before = array();

	/** @var int[] Rows this test put into the jobs table by hand (removed at its end). */
	private $rows = array();

	public function set_up(): void {
		parent::set_up();
		$this->evidence_before = array_column( Residue::scan_evidence( Plugin::instance()->directories()->base() ), 'path' );
	}

	public function tear_down(): void {
		global $wpdb;
		$base = Plugin::instance()->directories()->base();
		if ( '' !== $base ) {
			foreach ( Residue::scan_evidence( $base ) as $entry ) {
				if ( ! in_array( $entry['path'], $this->evidence_before, true ) ) {
					Deleter::delete_tree( $base . DIRECTORY_SEPARATOR . 'logs', $entry['path'] );
				}
			}
		}
		foreach ( $this->rows as $id ) {
			$wpdb->delete( Schema::jobs_table(), array( 'id' => $id ) );
		}
		$this->rows = array();
		Plugin::instance()->job_actions()->put_back_gate( new PutBack( Plugin::instance()->jobs() ) );
		parent::tear_down();
	}

	/**
	 * The job actions, with the put-back's checks for the test's site (its WordPress directory, its restore type).
	 *
	 * @return \WPCheckpoint\Jobs\JobActions
	 */
	private function actions() {
		$actions = Plugin::instance()->job_actions();
		$actions->put_back_gate(
			new PutBack(
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
	 * A restore killed between two batches of its renames: the swap half made.
	 *
	 * @return array{0: Job, 1: array<string, mixed>} The job, the site before the restore.
	 */
	private function half(): array {
		$this->swap_parts['batch'] = 1; // Every entry a batch of its own.
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', 1 );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::SITE_CHANGING, $now->site_state, 'the control: the swap half made' );
		$this->assertNotSame( $before, $this->site(), 'the control: something was renamed' );
		return array( $now, $before );
	}

	/**
	 * A restore killed right after its swap was committed.
	 *
	 * @return array{0: Job, 1: array<string, mixed>} The job, the site before the restore.
	 */
	private function committed(): array {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'committed', 1 );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::SITE_SWAPPED, $now->site_state, 'the control: the swap committed' );
		$this->assertSame( 'committed', $now->cursor['phase'] ?? '' );
		return array( $now, $before );
	}

	/**
	 * Its code, then the confirmed put-back.
	 *
	 * @param Job $job Job.
	 * @return array{ok: bool, message: string, confirmed: bool, code: string, lines: string[]} What the confirmed call said.
	 */
	private function claim( Job $job ): array {
		$told = $this->actions()->put_back( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] . "\n" . implode( "\n", $told['lines'] ) );
		$this->assertFalse( $told['confirmed'] );
		$sent    = array();
		$capture = static function ( $sql ) use ( &$sent ) {
			if ( is_string( $sql ) && false !== strpos( $sql, 'SHA2(CAST(cursor_json' ) ) {
				$sent[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $capture );
		try {
			$done = $this->actions()->put_back( $job->id, $told['code'] );
		} finally {
			remove_filter( 'query', $capture );
		}
		// What the claim's statement asked, condition by condition, when it did not go through.
		$this->assertTrue( $done['ok'], $done['message'] . "\n" . ( array() === $sent ? 'no claim sent' : print_r( self::conditions( (string) end( $sent ), $job->id ), true ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- test diagnostics.
		$this->assertTrue( $done['confirmed'] );
		return $done;
	}

	/**
	 * WP-CLI ticks to the end, with the message of the last tick (what the command prints last, RunLoop::report()).
	 *
	 * @param Job $job Job.
	 * @return array{0: Job, 1: string}
	 */
	private function run_out( Job $job ): array {
		$last = '';
		for ( $i = 0; $i < 50; $i++ ) {
			$result = $this->cli_tick( $job );
			$last   = (string) $result->message;
			$now    = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) || TickResult::WAITING === $result->status ) {
				return array( $now, $last );
			}
		}
		$this->fail( 'the put-back did not end' );
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
	 * The evidence directory of the job's put-back, from its cursor.
	 *
	 * @param Job $job The job as claimed.
	 * @return string
	 */
	private function evidence( Job $job ): string {
		$relative = (string) ( $job->cursor['evidence'] ?? '' );
		$this->assertStringStartsWith( 'logs/job-' . $job->id . '-putback-', $relative );
		return Plugin::instance()->directories()->base() . '/' . $relative;
	}

	/**
	 * The maintenance file and the self-check row are gone, and no job holds the site.
	 *
	 * @param string $why Label.
	 */
	private function assertNothingLeft( string $why ): void {
		$this->assertSame( array(), $this->maintenance_files(), $why . ': no maintenance file' );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site(), $why . ': no job holds the site' );
	}

	public function test_a_swap_half_made_is_put_back_and_the_restore_cancelled(): void {
		list( $job, $before ) = $this->half();
		$raw                  = Plugin::instance()->jobs()->put_back_raw( $job->id );
		$half                 = $this->site();

		$told = $this->actions()->put_back( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertFalse( $told['confirmed'], 'without the code nothing is done' );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{16}\z/', $told['code'] );
		$this->assertStringContainsString( 'put-back ' . $job->id . ' --confirm=' . $told['code'], $told['message'] );
		$this->assertStringContainsString( 'the restore is cancelled', implode( "\n", $told['lines'] ) );
		$this->assertSame( $half, $this->site(), 'nothing changed' );
		$this->assertSame( $raw, Plugin::instance()->jobs()->put_back_raw( $job->id ), 'the row as it was' );

		$wrong = $this->actions()->put_back( $job->id, str_repeat( '0', 16 ) );
		$this->assertFalse( $wrong['ok'] );
		$this->assertStringContainsString( 'does not match', $wrong['message'] );
		$this->assertSame( $raw, Plugin::instance()->jobs()->put_back_raw( $job->id ), 'a wrong code changes nothing' );

		$this->claim( $job );
		$claimed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::QUEUED, $claimed->status );
		$this->assertSame( 'rollback', $claimed->cursor['phase'] ?? '' );
		$this->assertSame( SwapStep::PUT_BACK_REASON, $claimed->cursor['reason'] ?? '' );
		$this->assertGreaterThan( 0, $claimed->cancel_requested, 'its end is a cancel' );
		$this->assertSame( $job->options, $claimed->options, 'readable options are left as they are' );
		$dir = $this->evidence( $claimed );
		$this->assertSame( $raw['cursor_json'], file_get_contents( $dir . '/cursor_json' ), 'the cursor as the row held it, byte for byte' );
		$this->assertStringContainsString( (string) $claimed->cursor['evidence'], Plugin::instance()->job_presenter()->log_tail( $claimed ), 'the log says where' );

		list( $done, $said ) = $this->run_out( $claimed );
		$this->assertSame( Job::CANCELLED, $done->status, $done->last_error . ' ' . $done->progress_message );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site(), 'the site as it was before the restore' );
		$this->assertStringContainsString( 'put back as it was before the restore, and the restore cancelled', $done->progress_message );
		$this->assertStringContainsString( 'put back', $said, 'the command says it' );
		$this->assertNothingLeft( 'cancelled' );
		$this->assertDirectoryExists( $dir, 'what the claim wrote over stays (the cancel reclaims the work directory only)' );
	}

	public function test_a_committed_swap_is_put_back_as_an_undo_and_the_restore_ends_undone(): void {
		list( $job, $before ) = $this->committed();
		$told                 = $this->actions()->put_back( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertStringContainsString( 'Everything written on the site since', implode( "\n", $told['lines'] ) );
		$this->assertStringContainsString( 'kept for 7 days', implode( "\n", $told['lines'] ) );
		$this->assertTrue( $this->actions()->put_back( $job->id, $told['code'] )['ok'] );
		$claimed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'reverse', $claimed->cursor['phase'] ?? '' );
		$this->assertTrue( $claimed->cursor['out'] ?? false );
		$this->assertSame( 0, $claimed->cancel_requested );

		list( $done ) = $this->run_out( $claimed );
		$this->assertSame( Job::UNDONE, $done->status, $done->last_error . ' ' . $done->progress_message );
		$this->assertSame( $before, $this->site(), 'the site as it was before the restore' );
		$this->assertStringContainsString( 'put back as it was before the restore', $done->progress_message );
		$this->assertFalse( $done->can_retry(), 'an undone restore is not run again' );
		$this->assertNothingLeft( 'undone' );
	}

	public function test_with_its_cursor_and_options_unreadable_a_restore_is_put_back_all_the_same(): void {
		foreach ( array( 'half', 'committed' ) as $case ) {
			$this->tear_down_swap();
			$this->set_up_swap();
			list( $job, $before ) = 'half' === $case ? $this->half() : $this->committed();
			$cursor               = "{\"phase\":\"rena\x00 damaged " . $case;
			$options              = 'not json { ' . $case;
			$this->set(
				$job->id,
				array(
					'cursor_json'  => $cursor,
					'options_json' => $options,
				)
			);
			$read = Plugin::instance()->jobs()->find( $job->id );
			$this->assertEqualsCanonicalizing( array( 'cursor_json', 'options_json' ), $read->unreadable_columns, $case . ': the control: neither can be read' );

			$this->claim( $read );
			$claimed = Plugin::instance()->jobs()->find( $job->id );
			$this->assertSame( array(), $claimed->unreadable_columns, $case );
			$dir = $this->evidence( $claimed );
			$this->assertSame( $cursor, file_get_contents( $dir . '/cursor_json' ), $case . ': the cursor kept byte for byte' );
			$this->assertSame( $options, file_get_contents( $dir . '/options_json' ), $case . ': the options kept byte for byte' );
			$this->assertSame( array( JobRepository::OPTIONS_RESET ), array_keys( $claimed->options ), $case . ': the options reset, and it says so' );
			$this->assertStringContainsString( 'options reset', Plugin::instance()->job_presenter()->log_tail( $claimed ), $case );

			list( $done ) = $this->run_out( $claimed );
			$this->assertSame( 'half' === $case ? Job::CANCELLED : Job::UNDONE, $done->status, $case . ': ' . $done->last_error . ' ' . $done->progress_message );
			$this->assertSame( $before, $this->site(), $case . ': the site as it was before the restore' );
			$this->assertNothingLeft( $case );
		}
	}

	public function test_a_row_changed_between_the_read_and_the_claim_is_not_written_over(): void {
		global $wpdb;
		$table = Schema::jobs_table();
		foreach ( array( 'cursor', 'lock' ) as $case ) {
			$this->tear_down_swap();
			$this->set_up_swap();
			list( $job, $before ) = $this->half();
			$raw                  = Plugin::instance()->jobs()->put_back_raw( $job->id );
			$told                 = $this->actions()->put_back( $job->id, '' );
			$this->assertTrue( $told['ok'], $case . ': ' . $told['message'] );
			$changed = false;
			$change  = function ( $sql ) use ( &$changed, $job, $case, $table, $wpdb ) {
				if ( ! $changed && is_string( $sql ) && 0 === strpos( $sql, 'UPDATE ' . $table . ' SET status = ' ) && false !== strpos( $sql, 'SHA2(CAST(cursor_json' ) ) {
					$changed = true; // Right before the claim is sent: another writer meanwhile (a run's checkpoint, or a run taking the lock).
					if ( 'cursor' === $case ) {
						$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET cursor_json = %s WHERE id = %d", '{"phase":"rename","changed":1}', $job->id ) );
					} else {
						$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lock_token = 'another-run', locked_until = %d WHERE id = %d", time() + 60, $job->id ) );
					}
				}
				return $sql;
			};
			add_filter( 'query', $change );
			try {
				$claimed = $this->actions()->put_back( $job->id, $told['code'] );
			} finally {
				remove_filter( 'query', $change );
			}
			$this->assertTrue( $changed, $case . ': the control: the row was changed right before the claim' );
			$this->assertFalse( $claimed['ok'], $case );
			$this->assertStringContainsString( 'changed meanwhile, or a run holds it; nothing was changed', $claimed['message'], $case );
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( 'cursor' === $case ) {
				$this->assertSame( '{"phase":"rename","changed":1}', Plugin::instance()->jobs()->put_back_raw( $job->id )['cursor_json'], 'the change stays, not written over' );
			} else {
				$this->assertSame( 'another-run', $now->lock_token, 'the run keeps the job' );
			}
			$this->assertSame( Job::SITE_CHANGING, $now->site_state, $case );
			$this->assertNotSame( SwapStep::PUT_BACK_REASON, $now->cursor['reason'] ?? '', $case . ': not claimed' );
			// The control: once the other writer is done, the same job is put back.
			$this->set(
				$job->id,
				array(
					'cursor_json'  => $raw['cursor_json'],
					'lock_token'   => $raw['lock_token'],
					'locked_until' => $raw['locked_until'],
				)
			);
			$this->claim( Plugin::instance()->jobs()->find( $job->id ) );
			list( $done ) = $this->run_out( Plugin::instance()->jobs()->find( $job->id ) );
			$this->assertSame( Job::CANCELLED, $done->status, $case );
			$this->assertSame( $before, $this->site(), $case );
		}
	}

	public function test_a_plan_that_cannot_be_read_is_refused_with_what_can_be_read_and_the_job_keeps_the_site(): void {
		global $wpdb;
		list( $job ) = $this->half();
		$raw         = Plugin::instance()->jobs()->put_back_raw( $job->id );
		$plan        = $wpdb->base_prefix . SwapPlan::TABLE;
		$complete    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$plan} WHERE job_id = %d AND kind = %s", $job->id, SwapPlan::COMPLETE ), ARRAY_A );
		$this->assertIsArray( $complete, 'the control: the plan was complete' );
		$wpdb->delete( $plan, array( 'id' => $complete['id'] ) );
		$wpdb->query( 'COMMIT' );
		try {
			$told = $this->actions()->put_back( $job->id, '' );
			$this->assertFalse( $told['ok'] );
			$this->assertStringContainsString( 'inconsistent state and needs a person', $told['message'] );
			$lines = implode( "\n", $told['lines'] );
			$this->assertStringContainsString( 'not marked complete', $lines, 'the plan rows, by attempt' );
			$this->assertMatchesRegularExpression( '/wcpold[0-9a-f]{6}_' . $job->id . '_\w+: moved aside by the swap/', $lines, 'the job\'s tables, what each is' );
			$this->assertStringContainsString( 'a table is there under this name', $lines, 'which live names are there' );
			$this->assertSame( $raw, Plugin::instance()->jobs()->put_back_raw( $job->id ), 'nothing changed' );
			$this->assertSame( array( $job->id ), Plugin::instance()->jobs()->holding_site(), 'it keeps holding the site' );
		} finally {
			$wpdb->insert( $plan, $complete );
			$wpdb->query( 'COMMIT' );
		}
		$this->assertTrue( $this->actions()->put_back( $job->id, '' )['ok'], 'the control: with the plan whole it goes' );
	}

	public function test_put_back_is_refused_with_the_reason_while_something_is_in_the_way(): void {
		global $wpdb;
		list( $job ) = $this->half();
		$this->assertTrue( $this->actions()->put_back( $job->id, '' )['ok'], 'the control' );

		$this->set( $job->id, array( 'held_by' => 'aaaaaaaaaaaa' ) );
		$this->assertStringContainsString( 'managed by another installation', $this->actions()->put_back( $job->id, '' )['message'] );
		$this->set( $job->id, array( 'held_by' => '' ) );

		$this->set(
			$job->id,
			array(
				'lock_token'   => 'a-run',
				'locked_until' => time() + 60,
			)
		);
		$this->assertStringContainsString( 'A run holds restore job', $this->actions()->put_back( $job->id, '' )['message'] );
		$this->set(
			$job->id,
			array(
				'lock_token'   => '',
				'locked_until' => 0,
			)
		);

		$wpdb->insert(
			Schema::jobs_table(),
			array(
				'type'          => $this->type,
				'status'        => Job::FAILED,
				'site_state'    => Job::SITE_CHANGING,
				'storage_token' => $job->storage_token,
				'storage_path'  => $job->storage_path,
				'created_at'    => time(),
			)
		);
		$wpdb->query( 'COMMIT' );
		$this->rows[] = (int) $wpdb->insert_id;
		$this->assertStringContainsString( 'holds the site too', $this->actions()->put_back( $job->id, '' )['message'] );
		$wpdb->delete( Schema::jobs_table(), array( 'id' => array_pop( $this->rows ) ) );
		$wpdb->query( 'COMMIT' );

		$this->assertTrue( $this->actions()->put_back( $job->id, '' )['ok'], 'the control: nothing in the way' );
	}

	/**
	 * A put-back of a committed swap whose carry back fails (not for a moment), claimed and run until it asks.
	 *
	 * @return array{0: Job, 1: array<string, mixed>, 2: array<string, mixed>} The job as asked, the row before the claim, the site before the restore.
	 */
	private function asked(): array {
		list( $job, $before ) = $this->committed();
		$raw                  = Plugin::instance()->jobs()->put_back_raw( $job->id );
		$thrown               = false;
		$this->swap_parts['at'] = function ( string $point ) use ( &$thrown ): void {
			$this->seams[] = $point;
			if ( 'carry_back_locked' === $point && ! $thrown ) {
				$thrown = true;
				throw new \RuntimeException( 'Table is marked as crashed and should be repaired' );
			}
		};
		$this->retype( $job );
		$this->claim( $job );
		$asked = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::PAUSED, $asked->status, $asked->last_error . ' ' . $asked->progress_message );
		$this->assertSame( SwapStep::CARRY_QUESTION, $asked->questions[0]['id'] ?? '' );
		$this->assertSame( array( SwapStep::PUT_BACK, RestoreJob::KEEP ), $asked->questions[0]['choices'] ?? null, 'no default' );
		$this->assertSame( Job::SITE_SWAPPED, $asked->site_state, 'nothing was put back' );
		$this->assertTrue( $thrown, 'the control: the carry back failed' );
		return array( $asked, $raw, $before );
	}

	public function test_a_put_backs_question_is_seen_waits_with_no_deadline_and_keep_puts_the_job_back_as_it_was(): void {
		list( $job, $raw ) = $this->asked();
		// Seen in job status and on the page.
		$status = JobCommand::status_layout( Plugin::instance()->job_presenter()->present( $job ), 'json' );
		$this->assertStringContainsString( SwapStep::CARRY_QUESTION, (string) wp_json_encode( $status['rows'] ), 'job status shows the question' );
		$text = QuestionText::for_job( $job, Plugin::instance()->directories(), 'strval' );
		$this->assertStringContainsString( 'could not carry WP Checkpoint\'s own settings back', $text[0]['text'], 'the page says it' );
		$this->assertStringNotContainsString( 'deadline', strtolower( $text[0]['text'] ), 'no deadline is named' );

		// Long unanswered: nothing gives it up or answers it.
		$this->set(
			$job->id,
			array(
				'committed_at' => time() - 30 * 86400,
				'updated_at'   => time() - 30 * 86400,
				'progress_at'  => time() - 30 * 86400,
			)
		);
		Plugin::instance()->jobs()->reap();
		$still = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::PAUSED, $still->status, 'not given up' );
		$this->assertTrue( $still->awaiting_answer(), 'not answered' );
		$this->assertSame( array(), (array) ( $still->options['answers'] ?? array() ) );
		// Nor does a driver: a job that waits for an answer is not run (the step's own lack of a deadline is tested by
		// answering late, in the test after this one).
		$this->retype( $still );
		$again = $this->cli_run( $still );
		$this->assertSame( Job::PAUSED, $again->status, 'still asked after the wait' );
		$this->assertSame( SwapStep::CARRY_QUESTION, $again->questions[0]['id'] ?? '' );
		$this->assertSame( Job::SITE_SWAPPED, $again->site_state );

		// Managed by another installation (taken over meanwhile): not given up from here.
		$this->set( $job->id, array( 'held_by' => 'aaaaaaaaaaaa' ) );
		try {
			Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::CARRY_QUESTION => RestoreJob::KEEP ) );
			$this->fail( 'a job managed elsewhere is not given up from here' );
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'managed by another installation', $e->getMessage() );
		}
		$this->set( $job->id, array( 'held_by' => '' ) );

		// The record of another put-back than the one answered (a later claim meanwhile): not used.
		$answered = Plugin::instance()->jobs()->find( $job->id );
		$later    = array( 'evidence' => 'logs/job-' . $job->id . '-putback-20990101000000-0000abcd' ) + $answered->cursor;
		$this->set( $job->id, array( 'cursor_json' => wp_json_encode( $later ) ) );
		try {
			Plugin::instance()->jobs()->give_up_put_back( $answered );
			$this->fail( 'an answer to an earlier put-back does not give up a later one' );
		} catch ( \WPCheckpoint\Jobs\StaleJob $e ) {
			$this->assertStringContainsString( 'changed while it was being answered', $e->getMessage() );
		}
		$this->set( $job->id, array( 'cursor_json' => wp_json_encode( $answered->cursor ) ) );

		// A record that was changed is refused, the question kept.
		$file  = $this->evidence( $still ) . '/cursor_json';
		$bytes = (string) file_get_contents( $file );
		file_put_contents( $file, $bytes . ' ' );
		try {
			Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::CARRY_QUESTION => RestoreJob::KEEP ) );
			$this->fail( 'a changed record is not used' );
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'cannot be read back', $e->getMessage() );
		}
		$this->assertTrue( Plugin::instance()->jobs()->find( $job->id )->awaiting_answer(), 'still asked' );
		file_put_contents( $file, $bytes );

		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::CARRY_QUESTION => RestoreJob::KEEP ) );
		$back = Plugin::instance()->jobs()->put_back_raw( $job->id );
		$this->assertNotSame( '', $raw['lock_token'], 'the control: the stuck row held the expired token of the run that died' );
		$this->assertSame( array_replace( $raw, array( 'lock_token' => '', 'locked_until' => 0 ) ), $back, 'every column the claim wrote over is as it was before the put-back, but the lock' );
		$this->assertFalse( Plugin::instance()->jobs()->heartbeat( Plugin::instance()->jobs()->find( $job->id ), (string) $raw['lock_token'] ), 'the run that died does not get the job back with its old token' );
		$kept = Plugin::instance()->jobs()->find( $job->id );
		$this->assertNotSame( Job::COMPLETED, $kept->status, 'never completed' );
		$this->assertSame( Job::SITE_SWAPPED, $kept->site_state );
		$this->assertSame( array( $job->id ), Plugin::instance()->jobs()->holding_site(), 'it still holds the site' );
		$this->assertStringContainsString( 'put-back was given up as the administrator chose', Plugin::instance()->job_presenter()->log_tail( $kept ) );

		// It still needs a way on, and has one: put back again (the carry back no longer fails).
		$this->retype( $kept );
		$this->claim( $kept );
		list( $done ) = $this->run_out( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::UNDONE, $done->status, $done->last_error . ' ' . $done->progress_message );
	}

	public function test_a_put_backs_question_answered_put_back_drops_the_plugins_state_and_ends_undone(): void {
		list( $job, , $before ) = $this->asked();
		// Answered long after the swap (an undo's question would be past its deadline and kept): put back all the same.
		$this->set( $job->id, array( 'committed_at' => time() - 30 * 86400 ) );
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::CARRY_QUESTION => SwapStep::PUT_BACK ) );
		list( $done ) = $this->run_out( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::UNDONE, $done->status, $done->last_error . ' ' . $done->progress_message );
		$this->assertSame( $before, $this->site() );
		$this->assertStringContainsString( 'were not carried back, as chosen', Plugin::instance()->job_presenter()->log_tail( $done ) );
		$this->assertNothingLeft( 'answered' );
	}

	public function test_a_job_whose_options_were_reset_is_not_retried(): void {
		list( $job ) = $this->half();
		$this->set( $job->id, array( 'options_json' => 'not json' ) );
		$this->claim( Plugin::instance()->jobs()->find( $job->id ) );
		// As if its put-back then failed.
		$this->set(
			$job->id,
			array(
				'status'      => Job::FAILED,
				'finished_at' => time(),
			)
		);
		$failed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertTrue( $failed->can_retry(), 'the control: retry would otherwise be offered' );
		$this->assertFalse( Plugin::instance()->job_presenter()->present( $failed )['retryable'], 'the page does not offer it' );
		try {
			Plugin::instance()->job_actions()->retry( $job->id );
			$this->fail( 'a retry with reset options is refused' );
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'reset by wp wpcheckpoint job put-back', $e->getMessage() );
		}
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $job->id )->status );
		// Put it back to running so that the swap's tear-down puts the site back by its own rules.
		$this->set(
			$job->id,
			array(
				'status'      => Job::RUNNING,
				'finished_at' => 0,
			)
		);
		$this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
	}

	public function test_a_check_that_fails_after_the_put_back_is_said_and_the_put_back_ends_as_it_would(): void {
		foreach ( array( 'half', 'committed' ) as $case ) {
			$this->tear_down_swap();
			$this->set_up_swap();
			list( $job, $before ) = 'half' === $case ? $this->half() : $this->committed();
			$this->retype( $job, array( 'verify' => static function (): string { return 'home'; } ) );
			$this->claim( $job );
			$this->seams          = array();
			list( $done, $said )  = $this->run_out( Plugin::instance()->jobs()->find( $job->id ) );
			$this->assertSame( 'half' === $case ? Job::CANCELLED : Job::UNDONE, $done->status, $case . ': no question, no other end' );
			$this->assertContains( 'undo_checked', $this->seams, $case . ': checked' );
			$found = 'the home page did not answer as a WordPress site';
			$this->assertStringContainsString( 'check then failed: ' . $found, $done->progress_message, $case . ': job status' );
			$this->assertStringContainsString( $found, Plugin::instance()->job_presenter()->present( $done )['message'], $case . ': the page' );
			$this->assertStringContainsString( $found, $said, $case . ': the command' );
			$this->assertStringContainsString( 'did not pass its check; it is let out all the same', Plugin::instance()->job_presenter()->log_tail( $done ), $case );
			$this->assertSame( $before, $this->site(), $case );
			$this->assertNothingLeft( $case );
		}
	}

	public function test_a_put_back_killed_at_any_seam_is_finished_by_the_next_run(): void {
		foreach ( array( 'half', 'committed' ) as $case ) {
			$this->tear_down_swap();
			$this->set_up_swap();
			list( $job ) = 'half' === $case ? $this->half() : $this->committed();
			$this->retype( $job );
			$this->claim( $job );
			$this->seams = array();
			$this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
			$cases = array();
			$count = array();
			foreach ( $this->seams as $seam ) {
				$count[ $seam ] = ( $count[ $seam ] ?? 0 ) + 1;
				$cases[]        = array( $seam, $count[ $seam ] );
			}
			foreach ( array( 'rollback', 'restored', 'undo_checked', 'undo_verdict_recorded', 'unheld', 'reverted', 'maintenance_down' ) as $seam ) {
				$this->assertArrayHasKey( $seam, $count, $case . ': the control: a put-back passes ' . $seam );
			}
			foreach ( $cases as $one ) {
				list( $seam, $nth ) = $one;
				$label              = $case . ', ' . $seam . ' #' . $nth;
				$this->tear_down_swap();
				$this->set_up_swap();
				list( $job, $before ) = 'half' === $case ? $this->half() : $this->committed();
				$this->retype( $job );
				$this->claim( $job );
				$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), $seam, $nth );
				$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
				$this->assertSame( 'half' === $case ? Job::CANCELLED : Job::UNDONE, $done->status, $label . ': ' . $done->last_error . ' ' . $done->progress_message );
				$this->assertSame( $before, $this->site(), $label . ': the site as it was before the restore' );
				$this->assertNothingLeft( $label );
			}
		}
	}

	public function test_what_the_claim_wrote_over_is_never_offered_and_goes_with_the_row(): void {
		list( $job ) = $this->half();
		$marker      = 'wpc-evidence-marker-' . bin2hex( random_bytes( 4 ) );
		$this->set( $job->id, array( 'cursor_json' => '{"damaged":"' . $marker ) );
		$this->claim( Plugin::instance()->jobs()->find( $job->id ) );
		$claimed = Plugin::instance()->jobs()->find( $job->id );
		$dir     = $this->evidence( $claimed );
		$this->assertStringContainsString( $marker, (string) file_get_contents( $dir . '/cursor_json' ), 'the control: the record holds it' );

		// The log download: the job's log (it names the record), never the record.
		$out    = fopen( 'php://memory', 'w+b' );
		$status = ( new LogDownload( Plugin::instance()->job_actions(), Plugin::instance()->job_presenter() ) )->handle( $job->id, static function (): void {}, $out );
		rewind( $out );
		$body = (string) stream_get_contents( $out );
		$this->assertSame( 200, $status );
		$this->assertStringContainsString( 'putback', $body, 'the control: the log was downloaded, and names where the record is' );
		$this->assertStringNotContainsString( $marker, $body, 'not the record' );

		// A request for it through the web server is refused.
		$host = rtrim( (string) getenv( 'WPCHECKPOINT_TEST_LOOPBACK_HOST' ), '/' );
		if ( '' !== $host ) {
			$this->assertStringStartsWith( WP_CONTENT_DIR . '/', $dir, 'the record is under wp-content, where the web server could reach it' );
			$url     = $host . '/wp-content/' . substr( $dir, strlen( WP_CONTENT_DIR ) + 1 ) . '/cursor_json';
			$control = wp_remote_get( $host . '/wp-content/index.php', array( 'timeout' => 20 ) );
			$this->assertSame( 200, wp_remote_retrieve_response_code( $control ), 'the control: the web server answers for wp-content' );
			$asked = wp_remote_get( $url, array( 'timeout' => 20 ) );
			$this->assertContains( wp_remote_retrieve_response_code( $asked ), array( 403, 404 ), 'refused' );
			$this->assertStringNotContainsString( $marker, (string) wp_remote_retrieve_body( $asked ) );
		}

		// It goes with the row; one whose row is gone is an orphan.
		$this->cli_run( $claimed );
		$ended  = Plugin::instance()->jobs()->find( $job->id );
		$delete = new \ReflectionMethod( JobRepository::class, 'delete_files_of' );
		$delete->setAccessible( true );
		$base   = Plugin::instance()->directories()->base();
		$orphan = PutBackEvidence::write( $base, 999999, array( 'cursor_json' => '{}' ), time() );
		$this->assertDirectoryExists( $base . '/' . $orphan['dir'] );
		Plugin::instance()->jobs()->reap_residue();
		$this->assertDirectoryDoesNotExist( $base . '/' . $orphan['dir'], 'an orphan, its row positively gone' );
		$this->assertDirectoryExists( $dir, 'the control: one whose row is there stays' );
		$delete->invoke( Plugin::instance()->jobs(), $ended );
		$this->assertDirectoryDoesNotExist( $dir, 'gone with the row\'s files' );
	}
}
