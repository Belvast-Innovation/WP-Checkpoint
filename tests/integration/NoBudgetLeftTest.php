<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Cli\ExportCommand;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\DatabaseExportStep;
use WPCheckpoint\Jobs\DatabaseImportStep;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\ExportOptions;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\ManifestStep;
use WPCheckpoint\Jobs\PackStep;
use WPCheckpoint\Jobs\PreflightStep;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\RestoreVerifyStep;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\StoreStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\VerifyJob;
use WPCheckpoint\Jobs\VerifyStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The first unit of a tick always runs: every tick below starts with its
 * time already used up (as when the request's bootstrap, or a slow read
 * before the first unit, took it all), and every one of them must still
 * move the job on, or a slow host never gets anywhere (and the no-progress
 * guard fails the job). The jobs are real: an export of the database and
 * a few uploads, a full verify of it, and a restore of it into temporary
 * tables, which between them run every step that loops on the budget
 * (tests/unit/Support/FirstUnitRuleTest.php lists them).
 */
final class NoBudgetLeftTest extends JobTestCase {

	/** @var string */
	private $uploads;

	public function set_up(): void {
		parent::set_up();
		$this->uploads = wp_upload_dir()['basedir'] . '/wpcnobudget';
		wp_mkdir_p( $this->uploads );
		file_put_contents( $this->uploads . '/a.txt', str_repeat( 'no budget ', 300 ) );
		// More than one piece and one hash chunk: stored, packed and hashed over several units.
		$handle = fopen( $this->uploads . '/big.bin', 'wb' );
		for ( $i = 0; $i < 6; $i++ ) {
			fwrite( $handle, random_bytes( 1048576 ) );
		}
		fclose( $handle );
	}

	public function tear_down(): void {
		Deleter::empty_directory( $this->uploads );
		@rmdir( $this->uploads );
		parent::tear_down();
	}

	/**
	 * Tick until the job ends, each tick started an hour ago; every tick must change the step or the cursor.
	 *
	 * @return array{0: Job, 1: int, 2: string[]} The job at its end, the ticks it took, and the steps that ran.
	 */
	private function run_with_no_time_left( Job $job, int $max = 20000 ): array {
		// A runner of its own, on the real clock with a fixed budget: "started an hour ago" then means no time
		// left whatever limits this environment has.
		$runner = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array( 'budget' => new Budget( 20, 32 * 1048576, false ) )
		);
		$ran    = array();
		for ( $ticks = 1; $ticks <= $max; $ticks++ ) {
			$was    = Plugin::instance()->jobs()->find( $job->id );
			$before = self::position( $was );
			$ran[]  = $was->step;
			$result = $runner->tick( $job->id, microtime( true ) - 3600 );
			$now    = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				$GLOBALS['wpdb']->query( 'COMMIT' );
				return array( $now, $ticks, array_values( array_unique( array_filter( $ran ) ) ) );
			}
			$this->assertNotSame( TickResult::WAITING, $result->status, "tick {$ticks}: " . $result->message );
			$this->assertNotSame( $before, self::position( $now ), "tick {$ticks} ({$now->step}) moved nothing on" );
		}
		$this->fail( 'the job did not end' );
	}

	/**
	 * Where a job stands: its step and cursor, without the Runner's own counters.
	 */
	private static function position( Job $job ): string {
		return (string) wp_json_encode( array( $job->step, JobContext::strip_reserved( $job->cursor ) ) );
	}

	/**
	 * An export of the database and the uploads in the fixture directory, with no time left in any tick.
	 *
	 * @return string The backup's base name.
	 */
	private function export(): string {
		$options = ExportOptions::normalize( array_merge( ExportCommand::options( array( 'yes' => true ) ), array( 'contents' => array( 'files' => array( 'uploads' ) ) ) ) );
		$job     = Plugin::instance()->job_actions()->start( ExportJob::ID, self::$admin_id, $options );
		list( $job, $ticks, $ran ) = $this->run_with_no_time_left( $job );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertGreaterThan( 20, $ticks, 'the control: the export crossed many ticks' );
		foreach ( array( PreflightStep::ID, FileScanStep::ID, DatabaseExportStep::ID, PackStep::ID, ManifestStep::ID, StoreStep::ID ) as $step ) {
			$this->assertContains( $step, $ran, 'a tick with no time left started in ' . $step );
		}
		return (string) ExportPlan::read( Residue::work_dir( $job->storage_path, $job->id ), ExportPlan::PLAN )['base'];
	}

	public function test_an_export_moves_on_in_every_tick_with_no_time_left(): void {
		$this->assertNotSame( '', $this->export() );
	}

	public function test_a_verify_moves_on_in_every_tick_with_no_time_left(): void {
		$base = $this->export();
		$job  = Plugin::instance()->job_actions()->start( VerifyJob::ID, self::$admin_id, VerifyJob::options( array( 'base' => $base ) ) );
		list( $job, $ticks, $ran ) = $this->run_with_no_time_left( $job );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertGreaterThan( 2, $ticks, 'the control: the verify crossed ticks' );
		$this->assertContains( VerifyStep::ID, $ran );
	}

	public function test_a_restore_moves_on_in_every_tick_with_no_time_left(): void {
		$base = $this->export();
		$job  = Plugin::instance()->jobs()->create( RestoreJob::ID, self::$admin_id, array(), array( 'base' => $base ) );
		try {
			list( $job, $ticks, $ran ) = $this->run_with_no_time_left( $job );
			$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
			$this->assertGreaterThan( 10, $ticks, 'the control: the restore crossed many ticks' );
			foreach ( array( RestoreVerifyStep::ID, RestorePreflightStep::ID, DatabaseImportStep::ID ) as $step ) {
				$this->assertContains( $step, $ran, 'a tick with no time left started in ' . $step );
			}
		} finally {
			Plugin::instance()->jobs()->reclaim_work( Plugin::instance()->jobs()->find( $job->id ) );
		}
	}
}
