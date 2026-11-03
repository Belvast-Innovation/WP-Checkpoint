<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Cli\RunLoop;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\PutBack;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Jobs\Redo;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\StaleJob;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Jobs\Undo;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\StoredNames;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * wp wpcheckpoint job redo (JobActions::redo(), Jobs\Redo, SwapStep's redo): an undone restore is swapped back in by
 * its own plan after a confirmation code that says what is dropped; every refusal has its control (the same case
 * without the obstacle goes through); a redo that does not stand ends undone again as of its undo, with an exit code
 * that says how. And the question an undo asks when the site it put back fails its check.
 */
final class RedoTest extends SwapTestCase {

	/** @var int[] Rows this test put into the jobs table by hand (removed at its end). */
	private $rows = array();

	/** @var array<string, string|null> This plugin's rows the test writes, as they were before it (null: none). */
	private $states = array();

	/** @var string[] Put-back evidence directories there before the test (the test removes the ones it made). */
	private $evidence_before = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( array( StoredNames::DELETE_DATA, StoredNames::UNINSTALL_NOTICE ) as $name ) {
			$this->states[ $name ] = $this->state( $name );
		}
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
		Plugin::instance()->job_actions()->put_back_gate( new PutBack( Plugin::instance()->jobs() ) );
		foreach ( $this->rows as $id ) {
			$wpdb->delete( Schema::jobs_table(), array( 'id' => $id ) );
		}
		$this->rows = array();
		Plugin::instance()->job_actions()->undo_gate( new Undo( Plugin::instance()->jobs() ) );
		Plugin::instance()->job_actions()->redo_gate( new Redo( Plugin::instance()->jobs() ) );
		parent::tear_down();
		// The site's options as they were (the swap's tables are back by now): the rows the test wrote go.
		foreach ( $this->states as $name => $value ) {
			$this->drop_state( $name );
			if ( null !== $value ) {
				$this->write_state( $value, $name );
			}
		}
	}

	/**
	 * The job actions, with the undo's and the redo's checks for the test's site.
	 *
	 * @param string $version The running plugin's version the redo holds the restored copy to.
	 * @return \WPCheckpoint\Jobs\JobActions
	 */
	private function actions( string $version = \WPCheckpoint\Tests\Fixtures\Restore\PluginCopy::VERSION ) {
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
		$actions->redo_gate( $this->redo_checks( '', $version ) );
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

	private function jobs(): JobRepository {
		return Plugin::instance()->jobs();
	}

	/**
	 * A restore of the swap backup, run to its end.
	 */
	private function completed(): Job {
		$done = $this->cli_run( $this->at_swap() );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		return $done;
	}

	/**
	 * Undo it (code, then confirmed), run to its end.
	 */
	private function undone( Job $job ): Job {
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$done = $this->cli_run( $this->jobs()->find( $job->id ) );
		$this->assertSame( Job::UNDONE, $done->status, $done->last_error . ' ' . $done->progress_message );
		return $done;
	}

	/**
	 * A restore, completed and undone: the job, the site before the restore, the site restored.
	 *
	 * @return array{0: Job, 1: array<string, mixed>, 2: array<string, mixed>}
	 */
	private function undone_restore(): array {
		$before   = $this->site();
		$job      = $this->completed();
		$restored = $this->site();
		$this->retype( $job );
		$done = $this->undone( $job );
		$this->assertSame( $before, $this->site(), 'the control: undone' );
		// Undone two minutes ago: a redo that ends now and wrote the time of its end instead of the undo's is told apart.
		$this->set( $done->id, array( 'finished_at' => $done->finished_at - 120 ) );
		return array( $this->jobs()->find( $done->id ), $before, $restored );
	}

	/**
	 * Claim a redo: the code, then the confirmed call.
	 *
	 * @return array{ok: bool, message: string, confirmed: bool, code: string, lines: string[]}
	 */
	private function claim( Job $job ): array {
		$told = $this->actions()->redo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertFalse( $told['confirmed'] );
		$done = $this->actions()->redo( $job->id, $told['code'] );
		$this->assertTrue( $done['ok'], $done['message'] );
		$this->assertTrue( $done['confirmed'] );
		return $done;
	}

	/**
	 * Why a redo of the job is refused now ('' when it is not), from the command without a code.
	 */
	private function refusal( Job $job, string $version = \WPCheckpoint\Tests\Fixtures\Restore\PluginCopy::VERSION ): string {
		$told = $this->actions( $version )->redo( $job->id, '' );
		return $told['ok'] ? '' : $told['message'];
	}

	private function set( int $id, array $values ): void {
		global $wpdb;
		$wpdb->update( Schema::jobs_table(), $values, array( 'id' => $id ) );
		$wpdb->query( 'COMMIT' );
	}

	private function other( array $values ): int {
		global $wpdb;
		$wpdb->insert( Schema::jobs_table(), $values + array( 'created_at' => time() ) );
		$wpdb->query( 'COMMIT' );
		$this->rows[] = (int) $wpdb->insert_id;
		return (int) $wpdb->insert_id;
	}

	/**
	 * A row of this plugin's own state written on the site.
	 */
	private function write_state( string $value, string $name = StoredNames::DELETE_DATA ): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'options';
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE option_name = %s", $name ) );
		$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		$wpdb->query( 'COMMIT' );
	}

	private function state( string $name = StoredNames::DELETE_DATA ): ?string {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->base_prefix}options` WHERE option_name = %s", $name ) );
		return null === $value ? null : (string) $value;
	}

	private function drop_state( string $name ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->base_prefix}options` WHERE option_name = %s", $name ) );
		$wpdb->query( 'COMMIT' );
	}

	/** @var int[] The waits the swap step asked for (carry_fails()). */
	private $slept = array();

	/**
	 * The swap's carry of this plugin's state into the restored copy fails at its start: once, or every time; for a
	 * moment (TransientFailure) or not. The waits the step asks for are recorded in $slept.
	 */
	private function carry_fails( Job $job, bool $always = false, bool $moment = false ): void {
		$this->slept               = array();
		$this->swap_parts['sleep'] = function ( int $seconds ): void {
			$this->slept[] = $seconds;
		};
		$thrown                 = false;
		$this->swap_parts['at'] = function ( string $point ) use ( &$thrown, $always, $moment ): void {
			$this->seams[] = $point;
			if ( 'carry_locked' === $point && ( $always || ! $thrown ) ) {
				$thrown = true;
				if ( $moment ) {
					throw new TransientFailure( 'Lock wait timeout exceeded' );
				}
				throw new \RuntimeException( 'Table is marked as crashed and should be repaired' );
			}
		};
		$this->retype( $job );
		$this->seams = array();
	}

	/**
	 * The job row's columns a refusal must leave as they are.
	 */
	private function row( int $id ): array {
		$job = $this->jobs()->find( $id );
		return array( $job->status, $job->step, $job->site_state, $job->finished_at, $job->committed_at, $job->cursor, $job->options, $job->updated_at );
	}

	public function test_an_undone_restore_is_swapped_back_in_once_confirmed_with_the_plugins_state_of_now(): void {
		list( $job, $before, $restored ) = $this->undone_restore();
		$undone_at                       = $job->finished_at;
		$this->write_state( 'written since the undo' );
		$seen = $this->site();
		$was  = $this->row( $job->id );

		$told = $this->actions()->redo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertFalse( $told['confirmed'], 'without the code nothing is done' );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{16}\z/', $told['code'] );
		$this->assertStringContainsString( 'wp wpcheckpoint job redo ' . $job->id . ' --confirm=' . $told['code'], $told['message'] );
		$this->assertStringContainsString( 'Everything written on the site since ' . gmdate( 'Y-m-d H:i', $undone_at ), implode( "\n", $told['lines'] ), 'what is dropped, since the undo' );
		$this->assertSame( $seen, $this->site(), 'nothing changed' );
		$this->assertSame( $was, $this->row( $job->id ) );

		$wrong = $this->actions()->redo( $job->id, str_repeat( '0', 16 ) );
		$this->assertFalse( $wrong['ok'] );
		$this->assertStringContainsString( 'does not match', $wrong['message'] );
		// The code binds the job, its plan and the time of the undo under a name of its own: an undo's code of the very
		// same is another code.
		$plan    = $this->plan_of( $job );
		$entries = array_merge( $plan['site'], $plan['dirs'], $plan['tables'] );
		usort(
			$entries,
			static function ( array $a, array $b ): int {
				return $a['seq'] <=> $b['seq'];
			}
		);
		$attempt = (int) ExportPlan::read( $this->work( $job ), RestoreFiles::SWAP_PLAN )['attempt'];
		$this->assertSame( $told['code'], Redo::code( $job, $attempt, $entries, $undone_at ), 'the control: the code is the one computed' );
		$undo_code = Undo::code( $job, $attempt, $entries, $undone_at );
		$this->assertNotSame( $told['code'], $undo_code );
		$this->assertFalse( $this->actions()->redo( $job->id, $undo_code )['ok'], 'an undo\'s code is not taken' );
		$this->assertSame( $was, $this->row( $job->id ), 'a wrong code changes nothing' );

		$claimed = $this->actions()->redo( $job->id, $told['code'] );
		$this->assertTrue( $claimed['ok'], $claimed['message'] );
		$queued = $this->jobs()->find( $job->id );
		$this->assertSame( Job::QUEUED, $queued->status );
		$this->assertSame( SwapStep::ID, $queued->step );
		$this->assertSame( Job::SITE_UNTOUCHED, $queued->site_state, 'nothing changed yet' );
		$this->assertSame( 'redo', $queued->cursor['phase'] ?? '' );
		$this->assertSame( $undone_at, $queued->cursor['undone_at'] ?? null, 'the time of the undo kept in its cursor' );
		$this->assertSame( 0, $queued->finished_at );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site(), 'it does not hold the site before it changes it' );

		$this->seams = array();
		$lines       = array();
		$code        = $this->loop_code( $queued, $lines );
		$done        = $this->jobs()->find( $job->id );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code, implode( "\n", $lines ) );
		$this->assertSame( Job::COMPLETED, $done->status, $done->last_error . ' ' . $done->progress_message );
		$this->assertSame( Job::SITE_SWAPPED, $done->site_state );
		$this->assertGreaterThan( 0, $done->committed_at, 'a new commit time: the undo window counts from it' );
		$this->assertContains( 'redo_carried', $this->seams, 'the plugin\'s state was carried into the restored copy' );
		$this->assertLessThan( array_search( 'dir_aside', $this->seams, true ), array_search( 'redo_carried', $this->seams, true ), 'before anything was renamed' );
		$this->assertSame( 1, count( array_keys( $this->seams, 'carry_locked', true ) ), 'and only then' );
		$this->assertSame( $restored, $this->site(), 'the restored site, as it was before the undo' );
		$this->assertSame( 'written since the undo', $this->state(), 'with this plugin\'s state of now' );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site() );

		// And it can be undone again, within its new window.
		$again = $this->undone( $done );
		$this->assertSame( $before, $this->site(), 'undone again' );
		$this->assertSame( Job::UNDONE, $again->status );
	}

	public function test_a_restore_undone_at_its_question_after_the_release_is_swapped_back_in(): void {
		$before                     = $this->site();
		$this->swap_parts['verify'] = self::checks_after()['unknown'];
		$this->register_type();
		$job = $this->at_swap();
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $job ) );
		$asked = $this->jobs()->find( $job->id );
		$this->assertSame( SwapStep::UNVERIFIED_QUESTION, $asked->questions[0]['id'] ?? '' );
		$restored = $this->site();
		unset( $this->swap_parts['verify'] );
		$this->retype( $asked ); // The checks pass from here on.
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::UNVERIFIED_QUESTION => RestoreJob::UNDO ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$undone = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $undone->status, $undone->last_error );
		$this->assertSame( $before, $this->site() );
		$this->claim( $undone );
		$this->assertArrayNotHasKey( SwapStep::UNVERIFIED_QUESTION, (array) ( $this->jobs()->find( $job->id )->options['answers'] ?? array() ), 'the answer to undo is not taken for the redo\'s own check' );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->assertSame( Job::COMPLETED, $this->jobs()->find( $job->id )->status );
		$this->assertSame( $restored, $this->site() );
	}

	public function test_the_claim_is_written_only_on_the_undone_job_as_read(): void {
		list( $job ) = $this->undone_restore();
		$cursor      = SwapStep::redo_cursor( 1, $job->site_mark, $job->finished_at );
		$token       = $job->managing_token();
		$cases       = array(
			'finished_at'     => array( array( 'finished_at' => $job->finished_at - 1 ), array( 'finished_at' => $job->finished_at ) ),
			'status'          => array( array( 'status' => Job::FAILED ), array( 'status' => Job::UNDONE ) ),
			'step'            => array( array( 'step' => \WPCheckpoint\Jobs\SwapCheckStep::ID ), array( 'step' => SwapStep::ID ) ),
			'site_state'      => array( array( 'site_state' => Job::SITE_SWAPPED ), array( 'site_state' => Job::SITE_UNTOUCHED ) ),
			'committed_at'    => array( array( 'committed_at' => 5 ), array( 'committed_at' => 0 ) ),
			'work_expired_at' => array( array( 'work_expired_at' => 5 ), array( 'work_expired_at' => 0 ) ),
			'options reset'   => array( array( 'options_json' => (string) wp_json_encode( array( JobRepository::OPTIONS_RESET => 5 ) ) ), array( 'options_json' => (string) wp_json_encode( $job->options ) ) ),
			'lock'            => array(
				array(
					'lock_token'   => 'a-run',
					'locked_until' => time() + 600,
				),
				array(
					'lock_token'   => '',
					'locked_until' => 0,
				),
			),
			'managed'         => array( array( 'held_by' => str_repeat( 'e', 32 ) ), array( 'held_by' => '' ) ),
		);
		foreach ( $cases as $case => list( $broken, $mended ) ) {
			$this->set( $job->id, $broken );
			$was = $this->row( $job->id );
			try {
				$this->jobs()->reopen_for_redo( $job, $cursor, 'claimed', $token );
				$this->fail( $case . ': claimed a row that is not the job as read' );
			} catch ( StaleJob $e ) {
				$this->assertSame( $was, $this->row( $job->id ), $case . ': nothing written' );
			}
			$this->set( $job->id, $mended );
		}
		$claimed = $this->jobs()->reopen_for_redo( $job, $cursor, 'claimed', $token );
		$this->assertSame( Job::QUEUED, $claimed->status, 'the control' );
		$this->assertSame( $cursor, $claimed->cursor );
	}

	public function test_redo_is_refused_with_the_reason_while_another_job_is_in_the_way_and_changes_nothing(): void {
		list( $job ) = $this->undone_restore();
		$this->assertSame( '', $this->refusal( $job ), 'the control: a redo is offered' );
		$was  = $this->row( $job->id );
		$site = $this->site();
		$cases = array(
			'holds'     => array(
				array(
					'type'       => $this->type,
					'status'     => Job::FAILED,
					'step'       => SwapStep::ID,
					'site_state' => Job::SITE_CHANGING,
				),
				'holds the site: nothing is swapped back in until it has ended',
			),
			'restore'   => array(
				array(
					'type'   => $this->type,
					'status' => Job::QUEUED,
				),
				'is under way',
			),
			'later'     => array(
				array(
					'type'         => $this->type,
					'status'       => Job::COMPLETED,
					'site_state'   => Job::SITE_UNTOUCHED,
					'committed_at' => $job->finished_at + 1,
				),
				'swapped the site after restore job',
			),
			'same_second' => array(
				array(
					'type'         => $this->type,
					'status'       => Job::COMPLETED,
					'site_state'   => Job::SITE_UNTOUCHED,
					'committed_at' => $job->finished_at,
				),
				'swapped the site after restore job',
			),
			'unordered' => array(
				array(
					'type'       => $this->type,
					'status'     => Job::COMPLETED,
					'site_state' => Job::SITE_SWAPPED,
				),
				'recorded no time of its swap',
			),
		);
		foreach ( $cases as $case => list( $values, $said ) ) {
			$id  = $this->other( $values );
			$why = $this->refusal( $job );
			$this->assertStringContainsString( $said, $why, $case );
			$this->assertSame( $was, $this->row( $job->id ), $case . ': nothing changed' );
			$this->assertSame( $site, $this->site(), $case );
			global $wpdb;
			$wpdb->delete( Schema::jobs_table(), array( 'id' => $id ) );
			$wpdb->query( 'COMMIT' );
			$this->assertSame( '', $this->refusal( $job ), $case . ': the control, without it' );
		}
		// A job committed before the undo is not in the way.
		$this->other(
			array(
				'type'         => $this->type,
				'status'       => Job::COMPLETED,
				'committed_at' => $job->finished_at - 5,
			)
		);
		$this->assertSame( '', $this->refusal( $job ), 'a commit before the undo' );
	}

	public function test_redo_is_refused_by_the_jobs_own_state_and_changes_nothing(): void {
		list( $job ) = $this->undone_restore();
		$at          = $job->finished_at;
		$cases       = array(
			'window'   => array( array( 'finished_at' => $at - Undo::WINDOW_SECONDS - 1 ), array( 'finished_at' => $at ), 'can no longer be swapped back in' ),
			'expired'  => array( array( 'work_expired_at' => $at ), array( 'work_expired_at' => 0 ), 'can no longer be swapped back in' ),
			'reset'    => array( array( 'options_json' => (string) wp_json_encode( array( JobRepository::OPTIONS_RESET => $at ) ) ), array( 'options_json' => (string) wp_json_encode( $job->options ) ), 'were reset when it was put back' ),
			'mark'     => array( array( 'site_mark' => '' ), array( 'site_mark' => $job->site_mark ), 'no maintenance mark' ),
			'elsewhere' => array( array( 'held_by' => str_repeat( 'f', 32 ) ), array( 'held_by' => '' ), 'managed by another installation' ),
			'status'   => array( array( 'status' => Job::FAILED ), array( 'status' => Job::UNDONE ), 'only a restore that was undone' ),
		);
		foreach ( $cases as $case => list( $broken, $mended, $said ) ) {
			$this->set( $job->id, $broken );
			$was = $this->row( $job->id );
			$this->assertStringContainsString( $said, $this->refusal( $job ), $case );
			$this->assertSame( $was, $this->row( $job->id ), $case . ': nothing changed' );
			$this->set( $job->id, $mended );
			$this->assertSame( '', $this->refusal( $job ), $case . ': the control' );
		}
	}

	public function test_redo_is_refused_when_an_entry_is_not_where_the_undo_left_it_or_the_plan_cannot_be_read(): void {
		global $wpdb;
		list( $job ) = $this->undone_restore();
		$plan        = $this->plan_of( $job );
		$table       = null;
		foreach ( $plan['tables'] as $entry ) {
			if ( $wpdb->prefix . 'swt_keep' === $entry['live'] ) {
				$table = $entry;
			}
		}
		$dir = $plan['dirs'][0];
		$this->assertNotNull( $table );
		$cases = array(
			// The restored table under its moved-aside name: the plan's state after a swap, not before one.
			'table' => array(
				function () use ( $wpdb, $table ): void {
					$wpdb->query( "RENAME TABLE `{$table['stage']}` TO `{$table['old']}`" );
				},
				function () use ( $wpdb, $table ): void {
					$wpdb->query( "RENAME TABLE `{$table['old']}` TO `{$table['stage']}`" );
				},
				'is not where the undo left it',
			),
			// The restored table gone from all of the plan's names: neither state.
			'table gone' => array(
				function () use ( $wpdb, $table ): void {
					$wpdb->query( "RENAME TABLE `{$table['stage']}` TO `{$table['stage']}x`" );
				},
				function () use ( $wpdb, $table ): void {
					$wpdb->query( "RENAME TABLE `{$table['stage']}x` TO `{$table['stage']}`" );
				},
				'is not where the undo left it',
			),
			'dir'   => array(
				function () use ( $dir ): void {
					rename( $dir['stage'], $dir['old'] );
				},
				function () use ( $dir ): void {
					rename( $dir['old'], $dir['stage'] );
				},
				'is not where the undo left it',
			),
			'plan'  => array(
				function () use ( $job ): void {
					$file = RestoreFiles::path( $this->work( $job ), RestoreFiles::SWAP_PLAN );
					rename( $file, $file . '.away' );
				},
				function () use ( $job ): void {
					$file = RestoreFiles::path( $this->work( $job ), RestoreFiles::SWAP_PLAN );
					rename( $file . '.away', $file );
				},
				'cannot be read whole',
			),
		);
		foreach ( $cases as $case => list( $break, $mend, $said ) ) {
			$break();
			$was = $this->row( $job->id );
			$this->assertStringContainsString( $said, $this->refusal( $job ), $case );
			$this->assertSame( $was, $this->row( $job->id ), $case . ': nothing changed' );
			$mend();
			$this->assertSame( '', $this->refusal( $job ), $case . ': the control' );
		}
	}

	public function test_redo_is_refused_for_tables_of_the_site_made_since_that_the_plan_would_not_move_and_names_them(): void {
		global $wpdb;
		list( $job ) = $this->undone_restore();
		$made        = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$made[] = $wpdb->prefix . sprintf( 'swt_made_%02d', $i );
		}
		$this->create( $made[0], '(id INT) ENGINE=InnoDB' );
		$why = $this->refusal( $job );
		$this->assertStringContainsString( 'its restore would have moved away and that are not in its plan', $why );
		$this->assertStringContainsString( $made[0], $why );
		$this->assertStringContainsString( 'may have been made by the restored site while it was live, before the undo: if it belongs to the restored site, move it aside or rename it first, then run the redo again', $why, 'why it may be there, and the way on' );
		foreach ( array_slice( $made, 1 ) as $name ) {
			$this->create( $name, '(id INT) ENGINE=InnoDB' );
		}
		$why = $this->refusal( $job );
		$this->assertStringContainsString( $made[9], $why, 'ten named' );
		$this->assertStringNotContainsString( $made[10], $why );
		$this->assertStringContainsString( 'and 2 more', $why, 'the rest counted' );
		// Not this site's (outside its prefix): left, as the swap leaves it.
		foreach ( $made as $name ) {
			$wpdb->query( "DROP TABLE `{$name}`" );
		}
		$this->create( 'swtother_made', '(id INT) ENGINE=InnoDB' );
		$this->assertSame( '', $this->refusal( $job ), 'the control: a table that is not this site\'s is not in the way' );
	}

	public function test_redo_is_refused_when_the_restored_plugins_hold_another_version_of_the_plugin(): void {
		list( $job ) = $this->undone_restore();
		$this->assertStringContainsString( 'The restored plugins hold WP Checkpoint ' . \WPCheckpoint\Tests\Fixtures\Restore\PluginCopy::VERSION . ', and 1.2.3 runs now', $this->refusal( $job, '1.2.3' ) );
		$this->assertSame( '', $this->refusal( $job ), 'the control: the version that runs' );
	}

	public function test_a_claim_is_refused_while_a_run_holds_the_job_and_the_code_binds_the_time_of_the_undo(): void {
		list( $job ) = $this->undone_restore();
		$told        = $this->actions()->redo( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->set(
			$job->id,
			array(
				'lock_token'   => 'held-by-a-run',
				'locked_until' => time() + 600,
			)
		);
		$was     = $this->row( $job->id );
		$refused = $this->actions()->redo( $job->id, $told['code'] );
		$this->assertFalse( $refused['ok'] );
		$this->assertStringContainsString( 'a run holds it', $refused['message'] );
		$this->assertSame( $was, $this->row( $job->id ) );
		$this->set(
			$job->id,
			array(
				'lock_token'   => '',
				'locked_until' => 0,
				'finished_at'  => $job->finished_at - 1,
			)
		);
		$this->assertStringContainsString( 'does not match', $this->actions()->redo( $job->id, $told['code'] )['message'], 'the time of the undo is bound' );
		$this->set( $job->id, array( 'finished_at' => $job->finished_at ) );
		$this->assertTrue( $this->actions()->redo( $job->id, $told['code'] )['ok'], 'the control' );
		$this->cli_run( $this->jobs()->find( $job->id ) );
	}

	/**
	 * A redo whose carry fails: claimed and run until it asks.
	 *
	 * @return array{0: Job, 1: array<string, mixed>, 2: array<string, mixed>} The job as asked, the site as the undo left it, the restored site.
	 */
	private function asked_about_the_carry( bool $always = false ): array {
		list( $job, $before, $restored ) = $this->undone_restore();
		$this->write_state( 'written since the undo' );
		$this->carry_fails( $job, $always );
		$this->claim( $job );
		$lines  = array();
		$code   = $this->loop_code( $this->jobs()->find( $job->id ), $lines );
		$paused = $this->jobs()->find( $job->id );
		$this->assertSame( RunLoop::EXIT_PAUSED, $code, implode( "\n", $lines ) );
		$this->assertSame( Job::PAUSED, $paused->status, $paused->last_error . ' ' . $paused->progress_message );
		$this->assertSame( SwapStep::REDO_CARRY_QUESTION, $paused->questions[0]['id'] ?? '' );
		$this->assertSame( array( SwapStep::REDO, RestoreJob::KEEP ), $paused->questions[0]['choices'] ?? null, 'no default' );
		$this->assertSame( 'redo_ask', $paused->cursor['phase'] ?? '' );
		$this->assertSame( Job::SITE_UNTOUCHED, $paused->site_state, 'it does not hold the site while it asks' );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site() );
		$this->assertSame( $before, $this->site(), 'the site open as the undo left it, its maintenance file down' );
		$this->assertContains( 'carry_locked', $this->seams, 'the control: the carry began' );
		$this->assertNotContains( 'dir_aside', $this->seams, 'nothing was renamed' );
		$this->assertLessThan( array_search( 'redo_ask_recorded', $this->seams, true ), array_search( 'redo_carry_down', $this->seams, true ), 'the file came down before the position said untouched' );
		$file = ExportPlan::read( $this->work( $paused ), RestoreFiles::REDO_CARRY );
		$this->assertContains( StoredNames::DELETE_DATA, $file['names'], 'the rows that would be lost' );
		$this->assertSame( count( $file['names'] ), (int) $paused->questions[0]['count'] );
		$text = QuestionText::for_job( $paused, Plugin::instance()->directories(), 'strval' );
		$this->assertStringContainsString( 'could not carry WP Checkpoint\'s own settings into the restored copy', $text[0]['text'] );
		$this->assertStringContainsString( 'Table is marked as crashed', $text[0]['text'] );
		$this->assertStringContainsString( gmdate( 'Y-m-d H:i', $paused->cursor['undone_at'] + Undo::WINDOW_SECONDS ), $text[0]['text'], 'until when' );
		$this->assertSame( array_slice( $file['names'], 0, QuestionText::MAX_LISTED ), $text[0]['listed'] );
		return array( $paused, $before, $restored );
	}

	public function test_a_redo_whose_carry_fails_asks_and_keep_ends_it_undone_as_of_its_undo_at_the_answer(): void {
		list( $job, $before ) = $this->asked_about_the_carry();
		$at                   = (int) $job->cursor['undone_at'];
		$answered             = Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::REDO_CARRY_QUESTION => RestoreJob::KEEP ) );
		$this->assertSame( Job::UNDONE, $answered->status, 'ended at the answer: no run needed' );
		$this->assertSame( $at, $answered->finished_at, 'as of the undo' );
		$this->assertSame( array(), $answered->questions );
		$this->assertStringContainsString( 'The redo was given up as you chose', $answered->progress_message );
		$this->assertSame( $before, $this->site() );
		$this->assertSame( RunLoop::EXIT_CANCELLED, $this->loop_code( $answered ), 'job run says it was given up' );
		$this->assertSame( '', $this->refusal( $answered ), 'and it can be redone again' );
	}

	public function test_the_answer_that_ends_a_question_is_written_only_on_the_job_as_read(): void {
		list( $job ) = $this->asked_about_the_carry();
		foreach ( array( 'updated_at' => $job->updated_at + 1, 'status' => Job::QUEUED ) as $column => $value ) {
			$this->set( $job->id, array( $column => $value ) );
			try {
				$this->jobs()->answer( $job, array( SwapStep::REDO_CARRY_QUESTION => RestoreJob::KEEP ) );
				$this->fail( $column . ': answered on a row that changed' );
			} catch ( StaleJob $e ) {
				$this->assertStringContainsString( 'changed meanwhile', $e->getMessage(), $column );
			}
			$this->assertNotSame( Job::UNDONE, $this->jobs()->find( $job->id )->status, $column . ': not ended' );
			$this->set( $job->id, array( $column => 'status' === $column ? Job::PAUSED : $job->updated_at ) );
		}
		$this->assertSame( Job::UNDONE, $this->jobs()->answer( $this->jobs()->find( $job->id ), array( SwapStep::REDO_CARRY_QUESTION => RestoreJob::KEEP ) )->status, 'the control' );
	}

	public function test_a_redo_whose_carry_fails_asks_and_redo_swaps_it_in_without_the_rows_listed(): void {
		list( $job, , $restored ) = $this->asked_about_the_carry();
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::REDO_CARRY_QUESTION => SwapStep::REDO ) );
		$this->seams = array();
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::COMPLETED, $done->status, $done->last_error );
		$this->assertNotContains( 'carry_locked', $this->seams, 'nothing carried, as chosen' );
		$this->assertSame( $restored, $this->site() );
		$this->assertNotSame( 'written since the undo', $this->state(), 'the row listed was lost' );
		$this->assertStringContainsString( 'were not carried into the restored copy, as chosen', Plugin::instance()->job_presenter()->log_tail( $done ) );
	}

	public function test_the_answer_to_swap_back_in_is_void_when_the_rows_that_would_be_lost_changed_and_is_asked_again(): void {
		list( $job, , $restored ) = $this->asked_about_the_carry();
		$asked                    = ExportPlan::read( $this->work( $job ), RestoreFiles::REDO_CARRY )['names'];
		$extra                    = StoredNames::UNINSTALL_NOTICE;
		if ( in_array( $extra, $asked, true ) ) {
			$this->drop_state( $extra ); // Listed: taken away, the list changes as much.
		} else {
			$this->write_state( 'another row', $extra );
		}
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::REDO_CARRY_QUESTION => SwapStep::REDO ) );
		$this->seams = array();
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ), 'asked again' );
		$again = $this->jobs()->find( $job->id );
		$this->assertSame( SwapStep::REDO_CARRY_QUESTION, $again->questions[0]['id'] ?? '' );
		$this->assertNotContains( 'dir_aside', $this->seams, 'nothing renamed' );
		$this->assertStringContainsString( 'the answer is void, and it asks again', Plugin::instance()->job_presenter()->log_tail( $again ) );
		$file = ExportPlan::read( $this->work( $again ), RestoreFiles::REDO_CARRY );
		$this->assertStringContainsString( 'changed since the question was asked', $file['reason'] );
		$this->assertNotSame( $asked, $file['names'], 'the rows of now listed' );
		$this->assertSame( in_array( $extra, $asked, true ), ! in_array( $extra, (array) $file['names'], true ) );
		$this->assertSame( count( $file['names'] ), (int) $again->questions[0]['count'] );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site(), 'not holding the site while it asks again' );
		$this->assertSame( array(), $this->maintenance_files() );
		// Answered again, with the rows now listed: it goes on.
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::REDO_CARRY_QUESTION => SwapStep::REDO ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->assertSame( $restored, $this->site() );
	}

	public function test_a_carry_that_fails_for_a_moment_is_tried_again_in_the_same_run(): void {
		list( $job, , $restored ) = $this->undone_restore();
		$this->write_state( 'written since the undo' );
		$this->carry_fails( $job, false, true );
		$this->claim( $job );
		$lines = array();
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ), $lines ), implode( "\n", $lines ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::COMPLETED, $done->status, $done->last_error );
		$counts = array_count_values( $this->seams );
		$this->assertSame( 2, $counts['carry_locked'] ?? 0, 'the carry tried twice' );
		$this->assertSame( 1, $counts['redo_carry_retry'] ?? 0 );
		$this->assertSame( 1, $counts['entered'] ?? 0, 'in the run that entered: no other run, no wait between runs' );
		$this->assertSame( array( SwapStep::CARRY_RETRIES[0] ), $this->slept );
		$this->assertSame( $restored, $this->site() );
		$this->assertSame( 'written since the undo', $this->state(), 'carried the second time' );
	}

	public function test_a_carry_that_keeps_failing_for_a_moment_is_rolled_back_in_the_same_run_and_exits_1(): void {
		list( $job, $before ) = $this->undone_restore();
		$at                   = $job->finished_at;
		$this->write_state( 'written since the undo' );
		$this->carry_fails( $job, true, true );
		$this->claim( $job );
		$lines = array();
		$this->assertSame( RunLoop::EXIT_FAILED, $this->loop_code( $this->jobs()->find( $job->id ), $lines ), implode( "\n", $lines ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status, 'rolled back: undone again, not left waiting in maintenance mode: ' . $done->last_error );
		$this->assertSame( $at, $done->finished_at, 'as of the undo' );
		$this->assertSame( 0, $done->work_expired_at, 'its restored copy kept' );
		$this->assertStringContainsString( 'The redo stopped and was rolled back', $done->progress_message );
		$counts = array_count_values( $this->seams );
		$this->assertSame( 1 + count( SwapStep::CARRY_RETRIES ), $counts['carry_locked'] ?? 0, 'every try' );
		$this->assertSame( 1, $counts['entered'] ?? 0, 'all in the run that entered' );
		$this->assertSame( SwapStep::CARRY_RETRIES, $this->slept );
		$this->assertNotContains( 'dir_aside', $this->seams, 'nothing renamed' );
		$this->assertStringContainsString( 'could not be carried into the restored copy through 3 tries', Plugin::instance()->job_presenter()->log_tail( $done ) );
		$this->assertSame( $before, $this->site(), 'as the undo left it' );
		$this->assertSame( 'written since the undo', $this->state(), 'the plugin\'s state of now stays' );
		$this->assertSame( array(), $this->maintenance_files() );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site() );
		$this->assertSame( '', $this->refusal( $done ), 'it can be redone again' );
	}

	public function test_a_redo_question_unanswered_by_the_deadline_ends_undone_and_is_not_failed(): void {
		list( $job ) = $this->asked_about_the_carry();
		// Asked long ago: the rule for other questions (7 days from when it was asked) does not apply.
		$this->set( $job->id, array( 'updated_at' => time() - JobRepository::WORK_RETENTION_SECONDS - 60 ) );
		$this->jobs()->reap();
		$this->assertSame( Job::PAUSED, $this->jobs()->find( $job->id )->status, 'the control: not before the deadline' );
		$at = time() - Undo::WINDOW_SECONDS - 1;
		$this->set_cursor_time( $job->id, $at );
		$this->jobs()->reap();
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status, 'given up, not failed' );
		$this->assertSame( $at, $done->finished_at, 'as of the undo' );
		$this->assertSame( 0, $done->work_expired_at, 'its restored copy kept as long as an undone one\'s' );
	}

	/**
	 * Move the time of the undo in a job's cursor.
	 */
	private function set_cursor_time( int $id, int $at ): void {
		$job                      = $this->jobs()->find( $id );
		$cursor                   = $job->cursor;
		$cursor['undone_at']      = $at;
		$this->set( $id, array( 'cursor_json' => (string) wp_json_encode( $cursor ) ) );
	}

	public function test_a_claimed_redo_that_never_runs_is_not_failed_by_the_stall_rule_and_ends_undone_at_its_deadline(): void {
		list( $job ) = $this->undone_restore();
		$at          = $job->finished_at;
		$this->claim( $job );
		$this->set(
			$job->id,
			array(
				'progress_at' => time() - 2 * JobRepository::STALL_SECONDS,
				'created_at'  => time() - 2 * JobRepository::STALL_SECONDS,
			)
		);
		$this->jobs()->reap();
		$this->assertSame( Job::QUEUED, $this->jobs()->find( $job->id )->status, 'not failed after 24 hours' );
		$this->assertSame( $at, $this->jobs()->find( $job->id )->cursor['undone_at'] ?? null );
		$past = time() - Undo::WINDOW_SECONDS - 10;
		$this->set_cursor_time( $job->id, $past );
		$this->jobs()->reap();
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status, 'given up, not failed' );
		$this->assertSame( $past, $done->finished_at, 'as of the undo' );
	}

	public function test_a_redo_whose_check_fails_is_swapped_back_out_and_exits_9(): void {
		list( $job, $before ) = $this->undone_restore();
		$at                   = $job->finished_at;
		$this->retype( $job, array( 'verify' => static function (): string { return 'home'; } ) );
		$this->claim( $job );
		$lines = array();
		$code  = $this->loop_code( $this->jobs()->find( $job->id ), $lines );
		$done  = $this->jobs()->find( $job->id );
		$this->assertSame( RunLoop::EXIT_REDO_REVERSED, $code, implode( "\n", $lines ) );
		$this->assertSame( Job::UNDONE, $done->status, $done->last_error );
		$this->assertSame( $at, $done->finished_at, 'as of the undo' );
		$this->assertSame( 0, $done->committed_at );
		$this->assertStringContainsString( 'The redo was reversed: the site\'s check after it failed (the home page did not answer', $done->progress_message );
		$this->assertSame( $before, $this->site(), 'as the undo left it' );
		$this->assertSame( array(), $this->maintenance_files() );
		$this->assertSame( '', $this->refusal( $done ), 'it can be redone again' );
	}

	public function test_a_redo_whose_check_cannot_tell_under_an_undo_policy_is_swapped_back_out_and_exits_9(): void {
		list( $job, $before ) = $this->undone_restore();
		$this->set( $job->id, array( 'options_json' => (string) wp_json_encode( array_replace_recursive( $job->options, array( 'policy' => array( RestoreJob::UNVERIFIED => RestoreJob::UNDO ) ) ) ) ) );
		$this->retype( $job, array( 'verify' => self::checks_after()['unknown'] ) );
		$this->claim( $this->jobs()->find( $job->id ) );
		$this->assertSame( RunLoop::EXIT_REDO_REVERSED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status );
		$this->assertStringContainsString( 'could not be checked after it', $done->progress_message );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_redo_that_stops_midway_is_rolled_back_and_exits_1(): void {
		list( $job, $before ) = $this->undone_restore();
		$at                   = $job->finished_at;
		$renames              = 0;
		$this->retype(
			$job,
			array(
				'rename' => static function ( string $from, string $to ) use ( &$renames ): bool {
					return 3 === ++$renames ? false : rename( $from, $to ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- a sandbox; the third fails, once.
				},
			)
		);
		$this->claim( $job );
		$this->assertSame( RunLoop::EXIT_FAILED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status, 'not failed: a retry would start at the final check' );
		$this->assertSame( $at, $done->finished_at );
		$this->assertStringContainsString( 'The redo stopped and was rolled back', $done->progress_message );
		$this->assertSame( $before, $this->site(), 'put back as the undo left it' );
		$this->assertSame( array(), $this->maintenance_files() );
		$this->assertGreaterThan( 3, $renames, 'the control: the third rename failed, and the rollback renamed back' );
	}

	public function test_a_redo_given_up_by_its_check_before_it_changed_the_site_exits_2_and_can_be_made_again(): void {
		global $wpdb;
		list( $job, , $restored ) = $this->undone_restore();
		$at                       = $job->finished_at;
		$this->claim( $job );
		$this->create( $wpdb->prefix . 'swt_made', '(id INT) ENGINE=InnoDB' );
		$this->assertSame( RunLoop::EXIT_CANCELLED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status );
		$this->assertSame( $at, $done->finished_at, 'the time of the undo, not of this end' );
		$this->assertStringContainsString( 'The redo did not go on', $done->progress_message );
		$this->assertStringContainsString( 'swt_made', $done->progress_message );
		$this->assertSame( array(), $this->maintenance_files() );
		$wpdb->query( "DROP TABLE `{$wpdb->prefix}swt_made`" );
		$this->claim( $done );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->assertSame( $restored, $this->site() );
	}

	public function test_a_cancel_before_the_redo_changed_the_site_gives_it_up_and_after_it_is_refused(): void {
		list( $job, $before ) = $this->undone_restore();
		$at                   = $job->finished_at;
		$this->claim( $job );
		$out = Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( 'redo_given_up', $out['reason'] );
		$given = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $given->status, 'undone again, not cancelled' );
		$this->assertSame( $at, $given->finished_at );
		$this->assertSame( RunLoop::EXIT_CANCELLED, $this->loop_code( $given ) );
		$this->assertSame( $before, $this->site() );

		// A run holds it: a request, which the write that records "enter" sees.
		$this->claim( $given );
		$this->set(
			$job->id,
			array(
				'lock_token'   => 'a-run',
				'locked_until' => time() + 600,
			)
		);
		$this->assertSame( 'redo_requested', Plugin::instance()->job_actions()->cancel( $job->id )['reason'] );
		$this->assertGreaterThan( 0, $this->jobs()->find( $job->id )->cancel_requested );
		$this->set(
			$job->id,
			array(
				'lock_token'   => '',
				'locked_until' => 0,
			)
		);
		$this->seams = array();
		$this->assertSame( RunLoop::EXIT_CANCELLED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status );
		$this->assertStringContainsString( 'It was cancelled', $done->progress_message );
		$this->assertNotContains( 'redo_checking', $this->seams, 'seen by the next run before anything else (a run that waits sees it too)' );
		$this->assertSame( $before, $this->site() );

		// A request that lands while the run checks: the write that records "enter" sees it.
		$id                     = $job->id;
		$this->swap_parts['at'] = function ( string $point ) use ( $id ): void {
			$this->seams[] = $point;
			if ( 'redo_checking' === $point ) {
				$this->set( $id, array( 'cancel_requested' => time() ) );
			}
		};
		$this->retype( $done );
		$this->claim( $done );
		$this->seams = array();
		$this->assertSame( RunLoop::EXIT_CANCELLED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status );
		$this->assertStringContainsString( 'It was cancelled', $done->progress_message );
		$this->assertContains( 'redo_checking', $this->seams, 'the control: the run went as far as its checks' );
		$this->assertNotContains( 'entered', $this->seams, 'and recorded no "enter"' );
		$this->assertSame( $before, $this->site() );
		$this->swap_parts['at'] = function ( string $point ): void {
			$this->seams[] = $point;
		};
		$this->retype( $done );

		// After it changed the site: refused, and it goes on.
		$this->claim( $done );
		$this->killed_at( $this->jobs()->find( $job->id ), 'dir_aside' );
		$changing = $this->jobs()->find( $job->id );
		$this->assertSame( Job::SITE_CHANGING, $changing->site_state );
		$out = Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( 'redoing', $out['reason'] );
		$this->assertContains( 'redoing', \WPCheckpoint\Jobs\JobActions::CANCEL_REFUSED );
		$this->assertSame( 0, $this->jobs()->find( $job->id )->cancel_requested, 'nothing requested' );
		$this->assertSame( Job::UNDONE, $this->cli_run( $this->jobs()->find( $job->id ) )->status, 'the killed run is rolled back as the undo left it' );
	}

	public function test_an_uninstall_keeps_the_restored_copy_while_a_redo_asks(): void {
		list( $job ) = $this->asked_about_the_carry();
		$holding     = Uninstaller::holding();
		$this->assertContains(
			array(
				'id'    => $job->id,
				'until' => 0,
				'how'   => 'redoing',
			),
			$holding['undo']
		);
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::REDO_CARRY_QUESTION => RestoreJob::KEEP ) );
		$after = Uninstaller::holding();
		$this->assertSame( 'undone', array_column( $after['undo'], 'how', 'id' )[ $job->id ] ?? '', 'the control: undone, kept until its work expires' );
	}

	public function test_an_undo_whose_site_fails_its_check_asks_with_the_site_open_and_keep_ends_it_undone(): void {
		$before = $this->site();
		$job    = $this->completed();
		$this->retype( $job, array( 'verify' => static function (): string { return 'home'; } ) );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->seams = array();
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ), 'job undo exits 6 while it asks' );
		$asked = $this->jobs()->find( $job->id );
		$this->assertSame( Job::PAUSED, $asked->status );
		$this->assertSame( SwapStep::AFTER_UNDO_QUESTION, $asked->questions[0]['id'] ?? '' );
		$this->assertSame( array( RestoreJob::KEEP, SwapStep::REDO ), $asked->questions[0]['choices'] ?? null );
		$this->assertSame( Job::SITE_UNTOUCHED, $asked->site_state );
		$this->assertSame( $before, $this->site(), 'the site open, its maintenance file down' );
		$this->assertLessThan( array_search( 'undone_ask_recorded', $this->seams, true ), array_search( 'maintenance_down', $this->seams, true ), 'down before it asks' );
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site() );
		$text = QuestionText::for_job( $asked, Plugin::instance()->directories(), 'strval' );
		$this->assertStringContainsString( 'the site\'s check then failed (the home page did not answer as a WordPress site)', $text[0]['text'] );
		$this->assertStringContainsString( 'The site is open now', $text[0]['text'] );
		$at       = (int) $asked->cursor['undone_at'];
		$answered = Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::AFTER_UNDO_QUESTION => RestoreJob::KEEP ) );
		$this->assertSame( Job::UNDONE, $answered->status );
		$this->assertSame( $at, $answered->finished_at );
		$this->assertStringContainsString( 'the site\'s check then failed', $answered->progress_message );
		$this->assertSame( RunLoop::EXIT_UNCHECKED, $this->loop_code( $answered ), 'job run then exits 8' );
		$this->assertSame( $before, $this->site() );
	}

	public function test_an_undo_whose_site_cannot_be_checked_asks_too_and_redo_swaps_the_restored_copy_back_in(): void {
		$job      = $this->completed();
		$restored = $this->site();
		$this->retype( $job, array( 'verify' => self::checks_after()['unknown'] ) );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$asked = $this->jobs()->find( $job->id );
		$this->assertSame( SwapStep::AFTER_UNDO_QUESTION, $asked->questions[0]['id'] ?? '' );
		$text = QuestionText::for_job( $asked, Plugin::instance()->directories(), 'strval' );
		$this->assertStringContainsString( 'the site could not be checked (the home page did not answer', $text[0]['text'] );
		// The redo after it checks the restored site as a swap does: passed this time.
		$this->retype( $asked );
		$this->actions(); // The redo's checks for this site.
		// Answers the swap and the undo had (the restored site's own question among them) are not taken for the redo's.
		$options            = $asked->options;
		$options['answers'] = array(
			SwapStep::UNVERIFIED_QUESTION => RestoreJob::UNDO,
			SwapStep::CARRY_QUESTION      => SwapStep::PUT_BACK,
		);
		$this->set( $job->id, array( 'options_json' => (string) wp_json_encode( $options ) ) );
		$answered = Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::AFTER_UNDO_QUESTION => SwapStep::REDO ) );
		$this->assertSame( array( SwapStep::AFTER_UNDO_QUESTION => SwapStep::REDO ), $answered->options['answers'], 'the earlier answers gone, in the same write' );
		$this->seams = array();
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::COMPLETED, $done->status, $done->last_error . ' ' . $done->progress_message );
		$this->assertContains( 'redo_checking', $this->seams, 'every check of a redo made' );
		$this->assertSame( $restored, $this->site() );
	}

	/**
	 * As a run finds a job that died between recording its asking position and writing the question (two writes).
	 */
	private function died_before_the_question( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . " SET status = %s, questions_json = NULL WHERE id = %d", Job::QUEUED, $id ) );
		$wpdb->query( 'COMMIT' );
	}

	public function test_an_answer_given_to_an_earlier_asking_is_not_taken_for_a_new_one(): void {
		$before = $this->site();
		$job    = $this->completed();
		$fail   = array( 'verify' => static function (): string { return 'home'; } );
		// The first undo asks; answered "redo", the restored copy goes back in.
		$this->retype( $job, $fail );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->retype( $job );
		$this->actions();
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::AFTER_UNDO_QUESTION => SwapStep::REDO ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->assertSame( SwapStep::REDO, $this->jobs()->find( $job->id )->options['answers'][ SwapStep::AFTER_UNDO_QUESTION ] ?? null, 'the control: the old answer is still in the options' );
		// The second undo asks too, and dies before the question is written: the old "redo" is not taken for it.
		$this->retype( $job, $fail );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->died_before_the_question( $job->id );
		$this->seams = array();
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ), 'asked again' );
		$asked = $this->jobs()->find( $job->id );
		$this->assertSame( SwapStep::AFTER_UNDO_QUESTION, $asked->questions[0]['id'] ?? '' );
		$this->assertNotContains( 'redo_checking', $this->seams, 'no redo started' );
		$this->assertSame( $before, $this->site(), 'the site as the undo put it back' );

		// The same within one redo: an answer voided because the rows changed, then a run that dies before it asks again.
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::AFTER_UNDO_QUESTION => RestoreJob::KEEP ) );
		$this->set( $job->id, array( 'finished_at' => $this->jobs()->find( $job->id )->finished_at - 120 ) );
		$this->write_state( 'written since the undo' );
		$this->carry_fails( $job );
		$this->claim( $this->jobs()->find( $job->id ) );
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		// The rows that would be lost change: this one comes, or goes.
		if ( in_array( StoredNames::UNINSTALL_NOTICE, (array) ExportPlan::read( $this->work( $job ), RestoreFiles::REDO_CARRY )['names'], true ) ) {
			$this->drop_state( StoredNames::UNINSTALL_NOTICE );
		} else {
			$this->write_state( 'another row', StoredNames::UNINSTALL_NOTICE );
		}
		Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::REDO_CARRY_QUESTION => SwapStep::REDO ) );
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ), 'the control: voided, asked again' );
		$this->died_before_the_question( $job->id );
		$this->seams = array();
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ), 'asked again, not swapped in by the voided answer' );
		$this->assertNotContains( 'dir_aside', $this->seams );
		$this->assertSame( SwapStep::REDO_CARRY_QUESTION, $this->jobs()->find( $job->id )->questions[0]['id'] ?? '' );
		$this->assertSame( $before, $this->site() );
	}

	public function test_an_undos_question_unanswered_by_the_deadline_keeps_the_site(): void {
		$before = $this->site();
		$job    = $this->completed();
		$this->retype( $job, array( 'verify' => static function (): string { return 'home'; } ) );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->loop_code( $this->jobs()->find( $job->id ) );
		$this->jobs()->reap();
		$this->assertSame( Job::PAUSED, $this->jobs()->find( $job->id )->status, 'the control: not before the deadline' );
		$this->set_cursor_time( $job->id, time() - Undo::WINDOW_SECONDS - 5 );
		$this->jobs()->reap();
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status, 'kept, not failed' );
		$this->assertSame( 0, $done->work_expired_at );
		$this->assertSame( $before, $this->site() );
	}

	public function test_with_its_maintenance_file_up_a_redo_checks_again_and_gives_up_when_that_no_longer_holds(): void {
		global $wpdb;
		list( $job, $before, $restored ) = $this->undone_restore();
		$at                              = $job->finished_at;
		$table                           = null;
		foreach ( $this->plan_of( $job )['tables'] as $entry ) {
			if ( $wpdb->prefix . 'swt_keep' === $entry['live'] ) {
				$table = $entry;
			}
		}
		$this->assertNotNull( $table );
		$made  = $wpdb->prefix . 'swt_made_up';
		$cases = array(
			// ⑧: a table of this site's made between the checks and the file.
			'made since' => array(
				function () use ( $made ): void {
					$this->create( $made, '(id INT) ENGINE=InnoDB' );
				},
				function () use ( $wpdb, $made ): void {
					$wpdb->query( "DROP TABLE `{$made}`" );
				},
				$made,
			),
			// ⑦: an entry of the plan moved meanwhile.
			'not in place' => array(
				function () use ( $wpdb, $table ): void {
					$wpdb->query( "RENAME TABLE `{$table['stage']}` TO `{$table['stage']}x`" );
				},
				function () use ( $wpdb, $table ): void {
					$wpdb->query( "RENAME TABLE `{$table['stage']}x` TO `{$table['stage']}`" );
				},
				'is not where the undo left it',
			),
		);
		foreach ( $cases as $case => list( $break, $mend, $said ) ) {
			$this->swap_parts['at'] = function ( string $point ) use ( $break ): void {
				$this->seams[] = $point;
				if ( 'maintenance' === $point ) {
					$break(); // With the file up: what the checks before it saw no longer holds.
				}
			};
			$this->retype( $this->jobs()->find( $job->id ) );
			$this->claim( $this->jobs()->find( $job->id ) );
			$this->seams = array();
			$lines       = array();
			$this->assertSame( RunLoop::EXIT_CANCELLED, $this->loop_code( $this->jobs()->find( $job->id ), $lines ), $case . ': given up: ' . implode( "\n", $lines ) );
			$done = $this->jobs()->find( $job->id );
			$this->assertSame( Job::UNDONE, $done->status, $case );
			$this->assertSame( $at, $done->finished_at, $case . ': as of the undo' );
			$this->assertSame( 'redo_stopped', $done->cursor['phase'] ?? '', $case );
			$this->assertStringContainsString( 'The redo did not go on', $done->progress_message, $case );
			$this->assertStringContainsString( $said, $done->progress_message, $case );
			$this->assertContains( 'redo_checking', $this->seams, $case . ': the control: the checks before the file held' );
			$this->assertContains( 'maintenance', $this->seams, $case . ': the control: the file was up' );
			$this->assertNotContains( 'redo_rechecked', $this->seams, $case );
			$this->assertNotContains( 'carry_locked', $this->seams, $case . ': nothing carried' );
			$this->assertNotContains( 'dir_aside', $this->seams, $case . ': nothing renamed' );
			$this->assertLessThan( array_search( 'redo_stop_recorded', $this->seams, true ), array_search( 'redo_stop_down', $this->seams, true ), $case . ': the file down before the position said untouched' );
			$this->assertSame( array(), $this->maintenance_files(), $case . ': the file taken down' );
			$this->assertSame( array(), Plugin::instance()->jobs()->holding_site(), $case );
			$mend();
			$this->assertSame( $before, $this->site(), $case . ': as the undo left it' );
		}
		// The control: the same run, nothing changed with the file up, goes through the check and on.
		$this->swap_parts['at'] = function ( string $point ): void {
			$this->seams[] = $point;
		};
		$this->retype( $this->jobs()->find( $job->id ) );
		$this->claim( $this->jobs()->find( $job->id ) );
		$this->seams = array();
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->assertContains( 'redo_rechecked', $this->seams );
		$this->assertSame( $restored, $this->site() );
	}

	public function test_a_redo_given_up_with_its_file_up_and_killed_between_its_two_steps_is_finished_by_the_next_run(): void {
		global $wpdb;
		list( $job, $before ) = $this->undone_restore();
		$at                   = $job->finished_at;
		$made                 = $wpdb->prefix . 'swt_made_up';
		// The killed run: the file down, "enter" still recorded (rolled back: nothing to put back); then "redo_stopped"
		// recorded (the file down again, given up).
		foreach ( array( 'redo_stop_down' => RunLoop::EXIT_FAILED, 'redo_stop_recorded' => RunLoop::EXIT_CANCELLED ) as $seam => $exit ) {
			$this->child_sql = array(
				'seam' => 'maintenance',
				'sql'  => array( "CREATE TABLE IF NOT EXISTS `{$made}` (id INT) ENGINE=InnoDB" ),
			);
			$this->retype( $this->jobs()->find( $job->id ) );
			$this->claim( $this->jobs()->find( $job->id ) );
			$this->killed_at( $this->jobs()->find( $job->id ), $seam );
			$killed = $this->jobs()->find( $job->id );
			$this->assertSame( 'redo_stop_down' === $seam ? 'enter' : 'redo_stopped', $killed->cursor['phase'] ?? '', $seam . ': the control: killed where meant' );
			$wpdb->query( "DROP TABLE IF EXISTS `{$made}`" );
			$this->child_sql = array();
			$lines           = array();
			$this->assertSame( $exit, $this->loop_code( $killed, $lines ), $seam . ': ' . implode( "\n", $lines ) );
			$done = $this->jobs()->find( $job->id );
			$this->assertSame( Job::UNDONE, $done->status, $seam . ': ' . $done->last_error . ' ' . $done->progress_message );
			$this->assertSame( $at, $done->finished_at, $seam . ': as of the undo' );
			$this->assertSame( $before, $this->site(), $seam );
			$this->assertSame( array(), $this->maintenance_files(), $seam );
			$this->assertSame( array(), Plugin::instance()->jobs()->holding_site(), $seam );
		}
	}

	public function test_a_redo_that_failed_before_it_changed_the_site_ends_undone_at_its_deadline(): void {
		list( $job ) = $this->undone_restore();
		$this->claim( $job );
		// A failure where the site is untouched ("redo": a failure of the moment that used up its retries, say).
		$this->set(
			$job->id,
			array(
				'status'      => Job::FAILED,
				'finished_at' => time() - 60,
				'last_error'  => 'Step "restore_swap": the database went away.',
			)
		);
		$failed = $this->jobs()->find( $job->id );
		$this->assertSame( 'redo', $failed->cursor['phase'] ?? '' );
		$this->jobs()->reap();
		$this->assertSame( Job::FAILED, $this->jobs()->find( $job->id )->status, 'the control: not before the deadline' );
		$past = time() - Undo::WINDOW_SECONDS - 10;
		$this->set_cursor_time( $job->id, $past );
		$this->jobs()->reap();
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status, 'undone as of its undo, not left failed' );
		$this->assertSame( $past, $done->finished_at );
		$this->assertSame( '', $done->last_error );
		$this->assertSame( 0, $done->work_expired_at, 'its restored copy kept as long as an undone restore\'s' );

		// Its work expired by the purge before the pass that settles it (the two times are the same length): settled all the same.
		$this->set(
			$job->id,
			array(
				'status'          => Job::FAILED,
				'finished_at'     => time() - 60,
				'work_expired_at' => time() - 30,
			)
		);
		$this->assertSame( 'redo', $this->jobs()->find( $job->id )->cursor['phase'] ?? '', 'the control: failed at "redo" again' );
		$this->jobs()->reap();
		$this->assertSame( Job::UNDONE, $this->jobs()->find( $job->id )->status, 'not left failed' );

		// A hundred older failed redos managed by another installation (a copied database, say), which nothing here can
		// settle, do not keep this one from its deadline.
		$cursor = (string) wp_json_encode(
			array(
				'phase'     => 'redo',
				'attempt'   => 1,
				'mark'      => 'elsewhere',
				'undone_at' => $past,
			)
		);
		for ( $i = 0; $i < 100; $i++ ) {
			$this->other(
				array(
					'type'          => $this->type,
					'status'        => Job::FAILED,
					'step'          => SwapStep::ID,
					'site_state'    => Job::SITE_UNTOUCHED,
					'cursor_json'   => $cursor,
					'storage_token' => 'another-installation',
					'finished_at'   => time() - 7200,
				)
			);
		}
		$this->set(
			$job->id,
			array(
				'status'          => Job::FAILED,
				'finished_at'     => time() - 60,
				'work_expired_at' => 0,
			)
		);
		$this->set_cursor_time( $job->id, $past );
		$this->jobs()->reap();
		$this->assertSame( Job::UNDONE, $this->jobs()->find( $job->id )->status, 'settled past the others' );
		$this->assertSame( Job::FAILED, $this->jobs()->find( end( $this->rows ) )->status, 'the control: theirs are not this installation\'s to settle' );
	}

	public function test_answering_redo_clears_a_cancel_request_left_from_before(): void {
		// At the redo's own question.
		list( $job, , $restored ) = $this->asked_about_the_carry();
		$this->set( $job->id, array( 'cancel_requested' => time() - 30 ) ); // Left by a take-over's rollback, say.
		$this->assertGreaterThan( 0, $this->jobs()->find( $job->id )->cancel_requested, 'the control: a request left on the row' );
		$answered = Plugin::instance()->job_actions()->answer( $job->id, array( SwapStep::REDO_CARRY_QUESTION => SwapStep::REDO ) );
		$this->assertSame( 0, $answered->cancel_requested );
		$this->assertSame( 0, $this->jobs()->find( $job->id )->cancel_requested, 'in the answer\'s write' );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ), 'swapped back in, not given up' );
		$this->assertSame( $restored, $this->site() );

		// At an undo's question about the site it put back.
		$this->retype( $this->jobs()->find( $job->id ), array( 'verify' => static function (): string { return 'home'; } ) );
		$told = $this->actions()->undo( $job->id, '' );
		$this->assertTrue( $this->actions()->undo( $job->id, $told['code'] )['ok'] );
		$this->assertSame( RunLoop::EXIT_PAUSED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->retype( $this->jobs()->find( $job->id ) );
		$this->actions();
		$this->set( $job->id, array( 'cancel_requested' => time() - 30 ) );
		$this->assertGreaterThan( 0, $this->jobs()->find( $job->id )->cancel_requested, 'the control: a request left on the row' );
		$this->jobs()->answer( $this->jobs()->find( $job->id ), array( SwapStep::AFTER_UNDO_QUESTION => SwapStep::REDO ) );
		$this->assertSame( 0, $this->jobs()->find( $job->id )->cancel_requested );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->assertSame( $restored, $this->site() );
	}

	/**
	 * A redo claimed and killed after it moved the first directory aside: half made.
	 *
	 * @return array{0: Job, 1: array<string, mixed>, 2: array<string, mixed>, 3: int} The job, the site as the undo left it, the restored site, the time of the undo.
	 */
	private function half_redo(): array {
		list( $job, $before, $restored ) = $this->undone_restore();
		$at                              = $job->finished_at;
		$this->retype( $job );
		$this->claim( $job );
		$this->killed_at( $this->jobs()->find( $job->id ), 'dir_aside' );
		$half = $this->jobs()->find( $job->id );
		$this->assertSame( Job::SITE_CHANGING, $half->site_state, 'the control: half made' );
		$this->assertSame( $at, SwapStep::redo_at( $half->cursor ), 'the control: a redo\'s cursor' );
		return array( $half, $before, $restored, $at );
	}

	public function test_a_redo_half_made_and_put_back_ends_undone_as_of_its_undo_with_its_restored_copy_kept(): void {
		list( $job, $before, $restored, $at ) = $this->half_redo();
		$told                                = $this->actions()->put_back( $job->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertStringContainsString( 'the restore is undone again as of that undo', implode( "\n", $told['lines'] ) );
		$this->assertStringContainsString( gmdate( 'Y-m-d H:i', $at ), implode( "\n", $told['lines'] ) );
		$this->assertTrue( $this->actions()->put_back( $job->id, $told['code'] )['ok'] );
		$claimed = $this->jobs()->find( $job->id );
		$this->assertSame( $at, $claimed->cursor['undone_at'] ?? null );
		$this->assertSame( 0, $claimed->cancel_requested, 'no cancel recorded: it ends undone' );
		$lines = array();
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $claimed, $lines ), implode( "\n", $lines ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status, 'not cancelled: ' . $done->last_error . ' ' . $done->progress_message );
		$this->assertSame( $at, $done->finished_at, 'as of the undo' );
		$this->assertSame( 0, $done->committed_at );
		$this->assertSame( 0, $done->work_expired_at );
		$this->assertStringContainsString( 'The redo was put back as asked', $done->progress_message );
		$this->assertSame( $before, $this->site(), 'as the undo left it' );
		$this->assertSame( array(), $this->maintenance_files() );
		$this->assertDirectoryExists( $this->work( $done ), 'its work kept' );
		// Its restored copy is still there, whole: swapped back in by another redo.
		$this->claim( $done );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$this->assertSame( $restored, $this->site() );
	}

	public function test_a_redo_half_made_put_back_and_taken_over_from_another_installation_takes_then_continue_and_ends_undone(): void {
		list( $job, $before, , $at ) = $this->half_redo();
		$told                        = $this->actions()->put_back( $job->id, '' );
		$this->assertTrue( $this->actions()->put_back( $job->id, $told['code'] )['ok'] );
		$this->killed_at( $this->jobs()->find( $job->id ), 'dir_back' );
		$stuck = $this->jobs()->find( $job->id );
		$this->assertTrue( SwapStep::putting_back( $stuck->cursor ), 'the control: a put-back under way' );
		$this->assertSame( $at, SwapStep::redo_at( $stuck->cursor ), 'the control: a redo\'s' );
		$this->assertSame( Job::SITE_CHANGING, $stuck->site_state );
		$this->set( $job->id, array( 'held_by' => 'ffffffffffff' ) );
		$here = new \WPCheckpoint\Jobs\JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new \WPCheckpoint\Jobs\Loopback( false ),
			new \WPCheckpoint\Jobs\HeldSite(
				array(
					'abspath'   => $this->abspath,
					'site_dirs' => function (): array {
						return $this->dirs;
					},
				)
			)
		);
		$held = $here->held_elsewhere( $job->id );
		$this->assertNotNull( $held );
		$this->assertSame( '', $held['assessment']['direction'] );
		$said = implode( "\n", \WPCheckpoint\Jobs\JobPresenter::held_lines( $held['job'], $held['assessment'] ) );
		$this->assertStringContainsString( '--then=continue', $said, 'it ends undone whatever is chosen: both are offered' );
		$this->assertStringContainsString( '--then=rollback', $said, $said );
		$this->assertStringContainsString( 'the restore ends undone', $said, $said );
		$yes = $here->rebind( $job->id, \WPCheckpoint\Jobs\HeldSite::code( \WPCheckpoint\Jobs\HeldSite::REBIND, $held['job'], '' ), 'continue' );
		$this->assertTrue( $yes['ok'], $yes['message'] );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ) );
		$done = $this->jobs()->find( $job->id );
		$this->assertSame( Job::UNDONE, $done->status, $done->last_error . ' ' . $done->progress_message );
		$this->assertSame( $at, $done->finished_at, 'as of the undo' );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_redo_put_back_killed_once_it_is_put_back_is_finished_undone_by_the_next_run(): void {
		foreach ( array( 'restored', 'reverted' ) as $seam ) {
			$this->tear_down_swap();
			$this->set_up_swap();
			$GLOBALS['wpdb']->query( 'UPDATE ' . JobRepository::table() . ' SET committed_at = 0, site_state = 0' );
			$GLOBALS['wpdb']->query( 'COMMIT' );
			list( $job, $before, , $at ) = $this->half_redo();
			$told                        = $this->actions()->put_back( $job->id, '' );
			$this->assertTrue( $this->actions()->put_back( $job->id, $told['code'] )['ok'], $seam );
			$this->killed_at( $this->jobs()->find( $job->id ), $seam );
			$this->assertSame( $at, SwapStep::redo_at( $this->jobs()->find( $job->id )->cursor ), $seam . ': the time of the undo kept on the cursor' );
			$this->assertSame( RunLoop::EXIT_COMPLETED, $this->loop_code( $this->jobs()->find( $job->id ) ), $seam );
			$done = $this->jobs()->find( $job->id );
			$this->assertSame( Job::UNDONE, $done->status, $seam . ': ' . $done->last_error . ' ' . $done->progress_message );
			$this->assertSame( $at, $done->finished_at, $seam );
			$this->assertSame( $before, $this->site(), $seam );
			$this->assertSame( array(), $this->maintenance_files(), $seam );
		}
	}

	public function test_a_redo_killed_at_any_seam_is_finished_by_the_next_run(): void {
		// Every seam a redo passes, with how often, from one that runs whole.
		list( $job ) = $this->undone_restore();
		$this->write_state( 'written since the undo' );
		$this->retype( $job );
		$this->claim( $job );
		$this->seams = array();
		$this->assertSame( Job::COMPLETED, $this->cli_run( $this->jobs()->find( $job->id ) )->status );
		$cases = array();
		$count = array();
		foreach ( $this->seams as $seam ) {
			$count[ $seam ] = ( $count[ $seam ] ?? 0 ) + 1;
			$cases[]        = array( $seam, $count[ $seam ] );
		}
		foreach ( array( 'redo_checking', 'entered', 'maintenance', 'redo_rechecked', 'carry_locked', 'redo_carried', 'dir_aside', 'dir_in', 'batch_sent', 'committed', 'verified', 'done_recorded' ) as $seam ) {
			$this->assertArrayHasKey( $seam, $count, 'the control: a redo passes ' . $seam );
		}
		foreach ( $cases as $case ) {
			list( $seam, $nth ) = $case;
			$label              = $seam . ' #' . $nth;
			$this->tear_down_swap();
			$this->set_up_swap();
			// The earlier cases' swaps were put back by the tear-down: their jobs no longer stand as commits.
			$GLOBALS['wpdb']->query( 'UPDATE ' . JobRepository::table() . ' SET committed_at = 0, site_state = 0' );
			$GLOBALS['wpdb']->query( 'COMMIT' );
			list( $job, $before, $restored ) = $this->undone_restore();
			$at                              = $job->finished_at;
			$this->write_state( 'written since the undo' );
			$this->retype( $job );
			$this->claim( $job );
			$this->killed_at( $this->jobs()->find( $job->id ), $seam, $nth );
			$done = $this->cli_run( $this->jobs()->find( $job->id ) );
			$this->assertContains( $done->status, array( Job::COMPLETED, Job::UNDONE ), $label . ': ' . $done->last_error . ' ' . $done->progress_message );
			if ( Job::COMPLETED === $done->status ) {
				$this->assertSame( $restored, $this->site(), $label . ': swapped back in, whole' );
				$this->assertSame( 'written since the undo', $this->state(), $label . ': with the plugin\'s state of now' );
			} else {
				$this->assertSame( $at, $done->finished_at, $label . ': undone as of the undo' );
				$this->assertSame( $before, $this->site(), $label . ': as the undo left it, whole' );
			}
			$this->assertSame( array(), $this->maintenance_files(), $label . ': no maintenance file' );
			$this->assertSame( array(), Plugin::instance()->jobs()->holding_site(), $label );
		}
	}

	public function test_an_undone_restore_is_not_retried_and_the_note_points_to_redo(): void {
		list( $job ) = $this->undone_restore();
		try {
			Plugin::instance()->job_actions()->retry( $job->id );
			$this->fail( 'retried' );
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'was undone', $e->getMessage() );
		}
		$this->assertStringContainsString( 'wp wpcheckpoint job redo ' . $job->id, \WPCheckpoint\Jobs\JobPresenter::undone_note( $job->id ) );
	}
}
