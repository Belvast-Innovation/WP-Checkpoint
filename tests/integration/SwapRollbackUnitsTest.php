<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The rollback of a swap in bounded units (SwapStep::roll_back()): one entry's renames a unit, the first of a run
 * always going, a run with its budget spent stopping between units and the next going on from what is there, under
 * WP-CLI; the swap step stays WP-CLI's only, so neither cron nor a request of its own moves a rollback on.
 */
final class SwapRollbackUnitsTest extends SwapTestCase {

	/**
	 * A restore killed once two of its table batches were renamed (every entry a batch of its own): its rollback has
	 * the directories and two table entries to put back.
	 *
	 * @return array{0: Job, 1: array<string, mixed>} The job, the site before the restore.
	 */
	private function half(): array {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', 2 );
		$half = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::SITE_CHANGING, $half->site_state, 'the control: the swap half made' );
		$this->assertNotSame( $before, $this->site(), 'the control: renamed' );
		return array( $half, $before );
	}

	/**
	 * A WP-CLI tick that starts with no time left (started an hour ago, on a fixed budget).
	 *
	 * @param Job $job Job.
	 * @return TickResult
	 */
	private function cli_tick_with_no_time_left( Job $job ): TickResult {
		$runner = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array(
				'cli'    => true,
				'budget' => new Budget( 20, 32 * 1048576, false ),
			)
		);
		return $this->autocommit(
			static function () use ( $runner, $job ): TickResult {
				return $runner->tick( $job->id, microtime( true ) - 3600 );
			}
		);
	}

	/**
	 * A tick of a driver other than WP-CLI (cron, the request of its own), with time to spare.
	 *
	 * @param Job $job Job.
	 * @return TickResult
	 */
	private function web_tick( Job $job ): TickResult {
		$runner = new Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), new Redactor( Redactor::installation_secrets() ) );
		return $this->autocommit(
			static function () use ( $runner, $job ): TickResult {
				return $runner->tick( $job->id, microtime( true ) );
			}
		);
	}

	/**
	 * How many units of the rollback ran: its renames, a table entry's one statement or a directory entry's renames.
	 *
	 * @return int
	 */
	private function units(): int {
		$units = 0;
		$dir   = false;
		foreach ( $this->seams as $seam ) {
			if ( 'table_back' === $seam ) {
				++$units;
			}
			if ( 'dir_back' === $seam && ! $dir ) {
				++$units; // A directory entry's renames are one unit; counted at its first.
			}
			$dir = 'dir_back' === $seam;
		}
		return $units;
	}

	public function test_with_no_time_left_a_rollback_puts_back_one_unit_a_run_until_the_site_is_back(): void {
		list( $job, $before ) = $this->half();
		$runs                 = array();
		for ( $i = 1; $i <= 30; $i++ ) {
			$this->seams = array();
			$was         = Plugin::instance()->jobs()->find( $job->id );
			$result      = $this->cli_tick_with_no_time_left( $was );
			$now         = Plugin::instance()->jobs()->find( $job->id );
			$runs[]      = array(
				'status' => $result->status,
				'units'  => $this->units(),
				'back'   => (int) ( $now->cursor['back'] ?? -1 ),
				'paused' => in_array( 'back_paused', $this->seams, true ),
			);
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			$this->assertSame( TickResult::MORE, $result->status, "run {$i}: " . $result->message );
			$this->assertSame( 1, $this->units(), "run {$i}: one unit, the first of the run" );
			$this->assertTrue( $runs[ $i - 1 ]['paused'], "run {$i}: stopped before the next unit" );
			$this->assertSame( (int) ( $was->cursor['back'] ?? 0 ) + 1, $runs[ $i - 1 ]['back'], "run {$i}: counted, so the run made progress" );
			$this->assertSame( Job::SITE_CHANGING, $now->site_state, "run {$i}: the site still being put back" );
			$this->assertNotSame( array(), $this->maintenance_files(), "run {$i}: its maintenance file held between runs" );
		}
		$done = Plugin::instance()->jobs()->find( $job->id );
		$this->assertGreaterThanOrEqual( 3, count( $runs ), 'the control: more than one run: ' . wp_json_encode( $runs ) );
		$this->assertSame( Job::FAILED, $done->status, $done->last_error . ' ' . wp_json_encode( $runs ) );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site(), 'the site as it was before the restore' );
		$this->assertSame( array(), $this->maintenance_files() );
		$this->assertTrue( $done->retry_useful(), 'a retry from the final check, as after any rollback' );
	}

	public function test_a_later_unit_does_not_start_when_the_time_left_does_not_cover_the_slowest_so_far(): void {
		list( $job, $before ) = $this->half();
		// The first directory put back takes 2 seconds, the rest none; the run starts with 4 seconds of its budget
		// left. After that unit about 2 seconds are left: the budget is not spent (should_stop() is false), but less
		// than the slowest unit with its margin (3 seconds), so no other unit starts.
		$calls = 0;
		$this->retype(
			$job,
			array(
				'rename' => static function ( string $from, string $to ) use ( &$calls ): bool {
					if ( 0 === $calls++ ) {
						sleep( 2 );
					}
					return rename( $from, $to );
				},
			)
		);
		$runner      = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array(
				'cli'    => true,
				'budget' => new Budget( 20, 32 * 1048576, false ),
			)
		);
		$this->seams = array();
		$result      = $this->autocommit(
			static function () use ( $runner, $job ): TickResult {
				return $runner->tick( $job->id, microtime( true ) - 16 );
			}
		);
		$this->assertSame( TickResult::MORE, $result->status, $result->message );
		$this->assertSame( 1, $this->dir_units(), 'one slow directory, then about 2 seconds left: less than the slowest unit with its margin, so no other starts: ' . implode( ' ', $this->seams ) );
		$this->assertSame( 1, $calls > 0 ? 1 : 0, 'the control: the slow rename ran' );
		$this->assertContains( 'back_paused', $this->seams );
		// The control: there was another directory to put back, and the next runs put it back.
		$this->seams = array();
		$done        = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertGreaterThanOrEqual( 1, $this->dir_units(), 'the control: more directories were left' );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site() );
	}

	/**
	 * How many directory entries the rollback put back (each entry's renames one unit).
	 *
	 * @return int
	 */
	private function dir_units(): int {
		$units = 0;
		$dir   = false;
		foreach ( $this->seams as $seam ) {
			if ( 'dir_back' === $seam && ! $dir ) {
				++$units;
			}
			$dir = 'dir_back' === $seam;
		}
		return $units;
	}

	public function test_with_time_left_a_rollback_ends_in_one_run(): void {
		list( $job, $before ) = $this->half();
		$this->seams          = array();
		$result               = $this->cli_tick( $job );
		$done                 = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $done->status, $result->message );
		$this->assertGreaterThanOrEqual( 3, $this->units(), 'the control: every unit in this run' );
		$this->assertNotContains( 'back_paused', $this->seams, 'never stopped between units' );
		$this->assertSame( $before, $this->site() );
	}

	public function test_neither_cron_nor_a_request_of_its_own_moves_a_rollback_on(): void {
		list( $job ) = $this->half();
		$this->cli_tick_with_no_time_left( $job ); // One unit put back: a rollback under way.
		$held = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'rollback', $held->cursor['phase'] ?? '', 'the control: a rollback under way' );
		$this->assertSame( 1, (int) ( $held->cursor['back'] ?? 0 ), 'the control: one unit done' );
		$site        = $this->site();
		$this->seams = array();
		$result      = $this->web_tick( $held );
		$this->assertSame( TickResult::CLI, $result->status, 'a step only WP-CLI runs: ' . $result->message );
		$this->assertSame( array(), $this->seams, 'the swap step did not run' );
		$after = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( $held->cursor, $after->cursor, 'its position as it was' );
		$this->assertSame( $site, $this->site(), 'nothing put back' );
		// The control: WP-CLI moves it on.
		$this->seams = array();
		$this->cli_tick_with_no_time_left( $after );
		$this->assertSame( 1, $this->units(), 'WP-CLI puts back the next unit' );
	}

	public function test_a_rollback_killed_between_units_after_a_stop_is_finished_by_the_next_run(): void {
		list( $job, $before ) = $this->half();
		$this->cli_tick_with_no_time_left( $job );
		$stopped = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 1, (int) ( $stopped->cursor['back'] ?? 0 ), 'the control: stopped after one unit' );
		$this->killed_at( $stopped, 'table_back', 1 ); // The next run killed right after its first unit.
		$killed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'rollback', $killed->cursor['phase'] ?? '', 'the control: still putting back' );
		$this->assertNotSame( $before, $this->site(), 'the control: not all put back' );
		$done = $this->cli_run( $killed );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'finished from what is there' );
		$this->assertSame( array(), $this->maintenance_files() );
	}
}
