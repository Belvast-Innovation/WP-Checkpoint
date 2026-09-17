<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\LockFile;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;

final class RunnerTest extends WP_UnitTestCase {

	/** @var Directories */
	private $dirs;

	/** @var string */
	private $base;

	/** @var float */
	private $now;

	/** @var int */
	private $memory;

	/** @var JobRepository */
	private $repo;

	/** @var JobTypes */
	private $types;

	/** @var string */
	private $root;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-runner-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/releases/a/wp-includes', 0755, true );
		mkdir( $this->root . '/releases/b/wp-includes', 0755, true );
		$this->dirs   = $this->site( 'releases/a' );
		$this->base   = $this->dirs->base();
		$this->now    = 1_800_000_000.0;
		$this->memory = 10 * 1048576;
		$this->repo   = $this->repo_for( $this->dirs );
		$this->types  = new JobTypes();
		Schema::ensure();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
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
			return (int) floor( $this->now );
		} );
	}

	/**
	 * A runner on the fake clock and fake memory reader.
	 */
	private function runner( int $seconds = 20, int $memory_budget = 32 * 1048576, array $options = array() ): Runner {
		return new Runner(
			$this->repo,
			$this->types,
			new Redactor( Redactor::installation_secrets() ),
			array_merge(
				array(
					'clock'        => function (): float {
						return $this->now;
					},
					'memory'       => function (): int {
						return $this->memory;
					},
					'budget'       => new Budget( $seconds, $memory_budget, false ),
					'memory_limit' => -1,
					'paths'        => array( '{abspath}' => rtrim( ABSPATH, '/' ) ),
				),
				$options
			)
		);
	}

	private function register( string $id, array $steps ): void {
		$this->types->add( new FixtureJobType( $id, $steps ) );
	}

	/**
	 * A counting step: one unit per loop, $units in total, each unit calls $on_unit( n ).
	 */
	private function counting_step( string $id, int $units, callable $on_unit ): ClosureStep {
		return new ClosureStep( $id, function ( JobContext $ctx ) use ( $units, $on_unit ): StepResult {
			$n = isset( $ctx->cursor()['n'] ) ? (int) $ctx->cursor()['n'] : 0;
			while ( $n < $units ) {
				if ( $ctx->should_stop() ) {
					return StepResult::progress( array( 'n' => $n ), (int) ( $n / $units * 100 ) );
				}
				++$n;
				$on_unit( $n, $ctx );
			}
			return StepResult::done( 'all ' . $units );
		} );
	}

	public function test_slow_step_respects_a_real_time_budget_and_ticks_add_up_to_one_run(): void {
		$seen = array();
		$this->register( 'slow', array( $this->counting_step( 'count', 12, function ( int $n ) use ( &$seen ): void {
			usleep( 300000 );
			$seen[] = $n;
		} ) ) );
		$runner = new Runner( $this->repo, $this->types, new Redactor(), array( 'budget' => new Budget( 2, 32 * 1048576, false ), 'memory_limit' => -1 ) );

		$job   = $this->repo->create( 'slow' );
		$ticks = 0;
		do {
			$started = microtime( true );
			$result  = $runner->tick( $job->id, $started );
			$took    = microtime( true ) - $started;
			++$ticks;
			$this->assertLessThan( 3.0, $took, 'a tick returns within budget + one unit' );
			if ( TickResult::MORE === $result->status ) {
				$this->assertGreaterThanOrEqual( 2.0, $took, 'the budget is used up before returning' );
				$this->assertSame( 0, $result->retry_after );
			}
		} while ( TickResult::COMPLETED !== $result->status && $ticks < 10 );

		$this->assertSame( TickResult::COMPLETED, $result->status );
		$this->assertGreaterThanOrEqual( 2, $ticks, 'the work did not fit in one budget' );
		$this->assertSame( range( 1, 12 ), $seen, 'no unit repeated or skipped across ticks' );
		$this->assertSame( Job::COMPLETED, $this->repo->find( $job->id )->status );
	}

	public function test_steps_run_in_order_with_progress_mapping_and_a_redacted_log(): void {
		$this->register( 'two', array(
			$this->counting_step( 'a', 3, function ( int $n, JobContext $ctx ): void {
				$this->now += 3; // Each unit costs 3 seconds on the fake clock.
				$ctx->logger()->info( 'unit ' . $n . ' with ' . DB_PASSWORD );
			} ),
			new ClosureStep( 'b', static function (): StepResult {
				return StepResult::done( 'b done' );
			} ),
		) );
		$job = $this->repo->create( 'two' );

		$result = $this->runner( 5 )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::MORE, $result->status );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::RUNNING, $stored->status );
		$this->assertSame( 'a', $stored->step );
		$this->assertSame( 2, $stored->cursor['n'], 'two units of 3 seconds fit a 5 second budget' );
		$this->assertSame( 33, $stored->progress, 'step a at 66% of 2 steps' );
		$this->assertSame( '', $stored->lock_token, 'released between ticks' );
		$this->assertFileExists( LockFile::path( $this->base, $job->id ), 'the lock file stays' );

		$result = $this->runner( 5 )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::COMPLETED, $result->status );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::COMPLETED, $stored->status );
		$this->assertSame( 100, $stored->progress );
		$this->assertSame( 'b', $stored->step );
		$this->assertFileDoesNotExist( LockFile::path( $this->base, $job->id ) );

		$log = (string) file_get_contents( $this->base . '/' . $stored->log_path );
		$this->assertStringContainsString( 'Tick started', $log );
		$this->assertStringContainsString( 'Step done', $log );
		$this->assertStringContainsString( 'Job completed', $log );
		$this->assertStringContainsString( 'unit 1', $log );
		$this->assertStringNotContainsString( DB_PASSWORD, $log );
	}

	public function test_lock_lost_during_a_step_stops_it_at_once(): void {
		$reached = false;
		$other   = $this->repo_for( $this->dirs );
		$this->register( 'cancelled', array( new ClosureStep( 'c', function ( JobContext $ctx ) use ( &$reached, $other ): StepResult {
			$ctx->checkpoint( array( 'i' => 1 ), 10 );
			$other->transition( $other->find( $ctx->job()->id ), Job::CANCELLED ); // An administrator cancels meanwhile.
			$ctx->checkpoint( array( 'i' => 2 ), 20 ); // Throws LockLost.
			$reached = true;
			return StepResult::done();
		} ) ) );
		$job    = $this->repo->create( 'cancelled' );
		$result = $this->runner()->tick( $job->id );
		$this->assertSame( TickResult::LOST, $result->status );
		$this->assertFalse( $reached, 'the step did not continue after the lock was lost' );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::CANCELLED, $stored->status );
		$this->assertSame( 1, $stored->cursor['i'], 'the cursor stays at the last successful checkpoint' );
		$this->assertSame( '', $stored->lock_token );
	}

	public function test_transient_failures_back_off_and_fail_after_the_limit(): void {
		$this->register( 'flaky', array( new ClosureStep( 'f', static function (): StepResult {
			throw new TransientFailure( 'remote timed out' );
		} ) ) );
		$job     = $this->repo->create( 'flaky' );
		$created = $this->repo->find( $job->id )->progress_at;
		foreach ( array( 5, 15, 60, 300, 300 ) as $attempt => $wait ) {
			$result = $this->runner()->tick( $job->id );
			$this->assertSame( TickResult::WAITING, $result->status, 'attempt ' . ( $attempt + 1 ) );
			$this->assertSame( $wait, $result->retry_after );
			$this->assertStringContainsString( 'remote timed out', $result->message );
			$stored = $this->repo->find( $job->id );
			$this->assertSame( Job::RUNNING, $stored->status );
			$this->assertSame( $attempt + 1, $stored->cursor['__runner']['retries'] );
			$this->assertSame( $created, $stored->progress_at, 'a retried failure is not progress' );
			$this->now += $wait;
		}
		$result = $this->runner()->tick( $job->id );
		$this->assertSame( TickResult::FAILED, $result->status );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::FAILED, $stored->status );
		$this->assertStringContainsString( 'failed 6 times', $stored->last_error );
		$this->assertStringContainsString( 'TransientFailure: remote timed out', $stored->last_error );
	}

	public function test_other_exceptions_fail_the_job_with_a_masked_message_and_cleanup_runs(): void {
		$cleaned = array();
		$this->register( 'broken', array(
			new ClosureStep( 'first', static function (): StepResult {
				return StepResult::done();
			}, function () use ( &$cleaned ): void {
				$cleaned[] = 'first';
			} ),
			new ClosureStep( 'second', static function (): StepResult {
				throw new \RuntimeException( 'cannot write ' . ABSPATH . 'wp-content/x with ' . DB_PASSWORD );
			}, function () use ( &$cleaned ): void {
				$cleaned[] = 'second';
				throw new \RuntimeException( 'cleanup broke' );
			} ),
			new ClosureStep( 'third', static function (): StepResult {
				return StepResult::done();
			}, function () use ( &$cleaned ): void {
				$cleaned[] = 'third';
			} ),
		) );
		$job    = $this->repo->create( 'broken' );
		$result = $this->runner()->tick( $job->id );
		$this->assertSame( TickResult::FAILED, $result->status );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::FAILED, $stored->status );
		$this->assertSame( 'second', $stored->step );
		$this->assertStringContainsString( 'Step "second": RuntimeException: cannot write {abspath}/wp-content/x', $stored->last_error );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $stored->last_error );
		$this->assertStringNotContainsString( DB_PASSWORD, $stored->last_error );
		$this->assertStringNotContainsString( DB_PASSWORD, $result->message );
		$this->assertFileDoesNotExist( LockFile::path( $this->base, $job->id ) );

		$this->assertSame( 1, $this->runner()->cleanup( $stored ), 'steps up to the current one; a failing cleanup is logged' );
		$this->assertSame( array( 'first', 'second' ), $cleaned );
		$this->assertStringContainsString( 'Cleanup failed', (string) file_get_contents( $this->base . '/' . $stored->log_path ) );
	}

	public function test_no_progress_three_times_fails_but_checkpoints_count_as_progress(): void {
		$this->register( 'stuck', array( new ClosureStep( 's', static function ( JobContext $ctx ): StepResult {
			return StepResult::progress( $ctx->cursor(), 0, 'still here' );
		} ) ) );
		$job = $this->repo->create( 'stuck' );
		foreach ( array( 1, 2 ) as $attempt ) {
			$result = $this->runner()->tick( $job->id );
			$this->assertSame( TickResult::MORE, $result->status );
			$this->assertSame( $attempt, $this->repo->find( $job->id )->cursor['__runner']['no_progress'] );
		}
		$result = $this->runner()->tick( $job->id );
		$this->assertSame( TickResult::FAILED, $result->status );
		$this->assertStringContainsString( 'could not make progress within the budget (3 attempts)', $this->repo->find( $job->id )->last_error );

		$this->register( 'checkpointing', array( new ClosureStep( 'c', function ( JobContext $ctx ): StepResult {
			$i = isset( $ctx->cursor()['i'] ) ? (int) $ctx->cursor()['i'] : 0;
			$ctx->checkpoint( array( 'i' => $i + 1 ), 50 );
			$this->now += 30; // Budget spent after the checkpoint.
			return StepResult::progress( array( 'i' => $i + 1 ), 50 );
		} ) ) );
		$job = $this->repo->create( 'checkpointing' );
		foreach ( array( 1, 2, 3, 4 ) as $i ) {
			$result = $this->runner()->tick( $job->id, $this->now );
			$this->assertSame( TickResult::MORE, $result->status );
			$this->assertSame( 0, $this->repo->find( $job->id )->cursor['__runner']['no_progress'], 'the cursor moved through the checkpoint' );
			$this->assertSame( $i, $this->repo->find( $job->id )->cursor['i'] );
		}
	}

	public function test_a_dead_process_is_taken_over_from_the_last_checkpoint(): void {
		$seen = array();
		$this->register( 'resumable', array( $this->counting_step( 'count', 6, function ( int $n, JobContext $ctx ) use ( &$seen ): void {
			$seen[] = $n;
			$ctx->checkpoint( array( 'n' => $n ), $n * 16 );
			$this->now += 4;
		} ) ) );
		$job    = $this->repo->create( 'resumable' );
		$result = $this->runner( 10 )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::MORE, $result->status );
		$this->assertSame( array( 1, 2, 3 ), $seen );

		// Another process takes the lock and dies without releasing it.
		$dead = $this->repo->acquire( $job->id, 120 );
		$this->assertNotNull( $dead );
		$this->assertSame( TickResult::BUSY, $this->runner( 10 )->tick( $job->id, $this->now )->status );
		$this->now += 121;

		$result = $this->runner( 10 )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::COMPLETED, $result->status, 'the expired lock was taken over and the rest fits one budget' );
		$this->assertSame( range( 1, 6 ), $seen, 'resumed from the last checkpoint, nothing repeated' );
	}

	public function test_gate_busy_finished_and_missing_results(): void {
		$this->register( 'plain', array( new ClosureStep( 'p', static function (): StepResult {
			return StepResult::done();
		} ) ) );
		$this->assertSame( TickResult::MISSING, $this->runner()->tick( 424242 )->status );

		$job  = $this->repo->create( 'plain' );
		$held = $this->repo->acquire( $job->id );
		$busy = $this->runner()->tick( $job->id );
		$this->assertSame( TickResult::BUSY, $busy->status );
		$this->assertSame( 5, $busy->retry_after );
		$this->repo->release( $held['job'], $held['token'] );

		$this->assertSame( TickResult::COMPLETED, $this->runner()->tick( $job->id )->status );
		$done = $this->runner()->tick( $job->id );
		$this->assertSame( TickResult::FINISHED, $done->status );
		$this->assertSame( -1, $done->retry_after );

		$old  = $this->repo->create( 'plain' );
		$next = $this->site( 'releases/b' );
		$next->base();
		$this->assertTrue( $next->state()['clone_detected'] );
		$this->repo = $this->repo_for( $next );
		$blocked    = $this->runner()->tick( $old->id );
		$this->assertSame( TickResult::BLOCKED, $blocked->status );
		$this->assertSame( 5, $blocked->retry_after );
		$this->assertSame( 15, $this->runner()->tick( $old->id )->retry_after, 'back-off grows' );
		$this->assertStringContainsString( 'clone notice', $blocked->message );
	}

	public function test_memory_budget_stops_after_the_unit_that_crosses_it(): void {
		$this->register( 'hungry', array( $this->counting_step( 'eat', 10, function (): void {
			$this->memory += 20 * 1048576;
		} ) ) );

		$job    = $this->repo->create( 'hungry' );
		$result = $this->runner( 20, 32 * 1048576 )->tick( $job->id );
		$this->assertSame( TickResult::MORE, $result->status );
		$this->assertSame( 2, $this->repo->find( $job->id )->cursor['n'], '10 + 20 fits, 10 + 40 exceeds the 32 MB growth budget' );

		$this->memory = 10 * 1048576;
		$job          = $this->repo->create( 'hungry' );
		$result       = $this->runner( 20, 100 * 1048576, array( 'memory_limit' => 64 * 1048576 ) )->tick( $job->id );
		$this->assertSame( TickResult::MORE, $result->status );
		$this->assertSame( 3, $this->repo->find( $job->id )->cursor['n'], '70 MB + 8 MB headroom exceeds the 64 MB limit; 50 MB did not' );

		$this->memory = 10 * 1048576;
		$job          = $this->repo->create( 'hungry' );
		$result       = $this->runner( 20, 100 * 1048576, array( 'memory_limit' => -1 ) )->tick( $job->id );
		$this->assertSame( 5, $this->repo->find( $job->id )->cursor['n'], 'unlimited: only the growth budget stops it' );
	}

	public function test_a_job_that_only_waits_is_still_given_up_after_a_day(): void {
		$this->register( 'patient', array( new ClosureStep( 'w', static function ( JobContext $ctx ): StepResult {
			return StepResult::wait( 3600, $ctx->cursor(), 'remote busy' );
		} ) ) );
		$job     = $this->repo->create( 'patient' );
		$created = $this->repo->find( $job->id )->progress_at;
		for ( $i = 0; $i < 25; $i++ ) {
			$result = $this->runner()->tick( $job->id );
			$this->assertSame( TickResult::WAITING, $result->status );
			$this->assertSame( 300, $result->retry_after, 'capped at the gate back-off maximum' );
			$this->assertSame( $created, $this->repo->find( $job->id )->progress_at, 'waiting is not progress' );
			$this->now += 3600;
		}
		$this->assertStringContainsString( 'Wait shortened to the maximum', (string) file_get_contents( $this->base . '/' . $this->repo->find( $job->id )->log_path ) );
		$this->repo->reap();
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::FAILED, $stored->status );
		$this->assertStringContainsString( '24 hours', $stored->last_error );
	}

	public function test_steps_cannot_overwrite_the_runner_state_through_the_cursor(): void {
		$this->register( 'sneaky', array( new ClosureStep( 'x', function ( JobContext $ctx ): StepResult {
			$this->assertArrayNotHasKey( '__runner', $ctx->cursor(), 'the step never sees the runner state' );
			$ctx->checkpoint( array( '__runner' => array( 'retries' => 99, 'no_progress' => 99 ), '__runner_extra' => 1, 'k' => 1 ), 10 );
			throw new TransientFailure( 'after checkpoint' );
		} ) ) );
		$job = $this->repo->create( 'sneaky' );
		$this->assertSame( 5, $this->runner()->tick( $job->id )->retry_after, 'the checkpoint moved the cursor: first failure' );
		$this->assertSame( array( 'k' => 1, '__runner' => array( 'retries' => 1, 'no_progress' => 0 ) ), $this->repo->find( $job->id )->cursor );
		$this->assertSame( 15, $this->runner()->tick( $job->id )->retry_after, 'an identical checkpoint is not progress: second failure' );
		$this->assertSame( array( 'k' => 1, '__runner' => array( 'retries' => 2, 'no_progress' => 0 ) ), $this->repo->find( $job->id )->cursor );

		$this->register( 'stuck', array( new ClosureStep( 's', static function ( JobContext $ctx ): StepResult {
			$cursor              = $ctx->cursor();
			$cursor['__runner']  = array( 'no_progress' => 0 );
			$cursor['__runner2'] = 'x';
			return StepResult::progress( $cursor, 0 );
		} ) ) );
		$job = $this->repo->create( 'stuck' );
		$this->assertSame( TickResult::MORE, $this->runner()->tick( $job->id )->status );
		$this->assertSame( array( '__runner' => array( 'retries' => 0, 'no_progress' => 1 ) ), $this->repo->find( $job->id )->cursor );
		$this->assertSame( TickResult::MORE, $this->runner()->tick( $job->id )->status );
		$this->assertSame( 2, $this->repo->find( $job->id )->cursor['__runner']['no_progress'] );
		$this->assertSame( TickResult::FAILED, $this->runner()->tick( $job->id )->status );
	}

	public function test_unknown_type_or_step_fails_the_job(): void {
		$job    = $this->repo->create( 'nope' );
		$result = $this->runner()->tick( $job->id );
		$this->assertSame( TickResult::FAILED, $result->status );
		$this->assertStringContainsString( 'Unknown job type "nope"', $this->repo->find( $job->id )->last_error );

		$this->register( 'plain', array( new ClosureStep( 'p', static function (): StepResult {
			return StepResult::done();
		} ) ) );
		$job  = $this->repo->create( 'plain' );
		$held = $this->repo->acquire( $job->id );
		$this->repo->save_progress( $held['job'], $held['token'], 'zzz', array(), 0 );
		$this->repo->release( $held['job'], $held['token'] );
		$this->assertSame( TickResult::FAILED, $this->runner()->tick( $job->id )->status );
		$this->assertStringContainsString( 'Unknown step "zzz"', $this->repo->find( $job->id )->last_error );
	}
}
