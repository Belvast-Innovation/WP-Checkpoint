<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Cancelled;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\NotHere;
use WPCheckpoint\Jobs\RetryFrom;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\RunnerRepository;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;
use WPCheckpoint\Tests\Fixtures\StorageDirs;

/**
 * A run made without WordPress (the Runner's "web" option) leaves a job to WP-CLI as it is: a step that can go no
 * further there (StepResult::LEAVE), or a storage method it cannot do (RunnerRepository::can(), NotHere). The lease is
 * released: nothing is counted against the job, no failure is written, the row is as it was but for what the claim
 * wrote (the lease; for a queued job its status, attempts and start), however often it comes back. In WordPress a step
 * that leaves is an error, and fails the job.
 */
final class RunnerLeaveTest extends WP_UnitTestCase {

	/** @var string[]|null The storage directories in wp-content when the test started (StorageDirs); null before set_up(). */
	private $storage_before = null;

	/** @var JobRepository */
	private $repo;

	/** @var JobTypes */
	private $types;

	/** @var Directories */
	private $dirs;

	/** @var string */
	private $root = '';

	/** @var float */
	private $now = 1_800_000_000.0;

	/**
	 * The columns a run that takes a job and lets it go writes (the lease), and wp_run_at: the storage here is
	 * WordPress's (JobRepository), whose claim and release stamp it (a run without WordPress over WebJobs does not:
	 * WebGraceTest).
	 */
	const LEASE = array( 'lock_token', 'locked_until', 'updated_at', 'wp_run_at' );

	public function set_up(): void {
		parent::set_up();
		\WPCheckpoint\Tests\Fixtures\RunTables::note();
		$this->storage_before = StorageDirs::listing();
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-leave-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/site/wp-includes', 0755, true );
		$dirs        = new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/site/' ) );
		$this->dirs  = $dirs;
		$this->repo  = new JobRepository( $dirs, null, function (): int {
			return (int) floor( $this->now );
		} );
		$this->types = new JobTypes();
		Schema::ensure();
		$dirs->base();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		if ( '' !== $this->root ) {
			\WPCheckpoint\Tests\Fixtures\Sandbox::remove( $this->root );
		}
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		\WPCheckpoint\Tests\Fixtures\RunTables::put_back();
		parent::tear_down();
		if ( null !== $this->storage_before ) {
			StorageDirs::remove_made_since( $this->storage_before );
		}
	}

	/**
	 * A runner on the fake clock, made without WordPress ($web) or in WordPress.
	 *
	 * @param bool                  $web  Whether made without WordPress.
	 * @param RunnerRepository|null $repo Storage (this test's repository by default).
	 * @param array<string, mixed>  $more More options.
	 * @return Runner
	 */
	private function runner( bool $web, $repo = null, array $more = array() ): Runner {
		return new Runner(
			null === $repo ? $this->repo : $repo,
			$this->types,
			new Redactor( Redactor::installation_secrets() ),
			array_merge(
				array(
					'clock'        => function (): float {
						return $this->now;
					},
					'memory'       => static function (): int {
						return 10 * 1048576;
					},
					'budget'       => new Budget( 20, 32 * 1048576, false ),
					'memory_limit' => -1,
					'paths'        => array( '{abspath}' => rtrim( ABSPATH, '/' ) ),
					'cli'          => ! $web,
					'web'          => $web,
				),
				$more
			)
		);
	}

	/**
	 * A job of a one-step type, standing at that step with a cursor, running with no lease (as a run that died leaves
	 * it, its lease lapsed).
	 *
	 * @param ClosureStep          $step   The step.
	 * @param array<string, mixed> $set    More columns to write on its row.
	 * @return Job
	 */
	private function job( ClosureStep $step, array $set = array() ): Job {
		$type = 'leave_' . bin2hex( random_bytes( 3 ) );
		$this->types->add( new FixtureJobType( $type, array( $step ) ) );
		$job = $this->repo->create( $type, 0, array( 'at' => 1 ) );
		global $wpdb;
		$wpdb->update(
			Schema::jobs_table(),
			array_merge(
				array(
					'status'     => Job::RUNNING,
					'step'       => $step->id(),
					'attempts'   => 1,
					'started_at' => (int) $this->now - 60,
				),
				$set
			),
			array( 'id' => $job->id )
		);
		return $this->repo->find( $job->id );
	}

	/**
	 * The job's row as stored, but the lease.
	 *
	 * @param int $id Job id.
	 * @return array<string, string|null>
	 */
	private function row( int $id ): array {
		global $wpdb;
		$row = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::jobs_table() . ' WHERE id = %d', $id ), ARRAY_A );
		$this->assertNotSame( array(), $row, 'the control: the row is read' );
		return array_diff_key( $row, array_flip( self::LEASE ) );
	}

	public function test_a_run_without_wordpress_that_leaves_writes_nothing_but_the_lease_however_often(): void {
		$step   = new ClosureStep(
			'stage',
			static function ( JobContext $ctx ): StepResult {
				return $ctx->is_cli() ? StepResult::done( 'done in WP-CLI' ) : StepResult::leave( 'Only WP-CLI can do what is left.' );
			}
		);
		$job    = $this->job( $step );
		$before = $this->row( $job->id );
		$left   = array();
		for ( $i = 1; $i <= Runner::MAX_NO_PROGRESS + 2; $i++ ) {
			$this->now += 30;
			$result     = $this->runner(
				true,
				null,
				array(
					'on_left' => static function ( $job, string $reason ) use ( &$left ): void {
						$left[] = $reason;
					},
				)
			)->tick( $job->id );
			$this->assertSame( TickResult::LEFT, $result->status, "run {$i}: " . $result->message );
			$this->assertSame( 'Only WP-CLI can do what is left.', $result->message );
			$this->assertSame( $before, $this->row( $job->id ), "run {$i}: the row as it was, but the lease" );
			$this->assertSame( '', $this->repo->find( $job->id )->lock_token, "run {$i}: let go" );
		}
		$this->assertCount( Runner::MAX_NO_PROGRESS + 2, $left, 'told why, every time' );
		// WP-CLI then goes on from where the job was left.
		$this->now += 30;
		$this->assertSame( TickResult::COMPLETED, $this->runner( false )->tick( $job->id )->status );
		$this->assertSame( Job::COMPLETED, $this->repo->find( $job->id )->status );
	}

	public function test_the_control_a_run_that_makes_progress_changes_the_row(): void {
		$job    = $this->job(
			new ClosureStep(
				'stage',
				function ( JobContext $ctx ): StepResult {
					$this->now += 30; // Past the 20 seconds of the budget: the tick ends after this run.
					return StepResult::progress( array( 'at' => (int) ( $ctx->cursor()['at'] ?? 0 ) + 1 ), 10 );
				}
			)
		);
		$before = $this->row( $job->id );
		$this->runner( true )->tick( $job->id );
		$this->assertNotSame( $before, $this->row( $job->id ), 'what the comparison above would see' );
	}

	public function test_a_queued_job_that_is_left_keeps_what_the_claim_wrote_and_nothing_else(): void {
		$job    = $this->job(
			new ClosureStep(
				'stage',
				static function (): StepResult {
					return StepResult::leave( 'Only WP-CLI can do what is left.' );
				}
			),
			array(
				'status'     => Job::QUEUED,
				'attempts'   => 0,
				'started_at' => 0,
			)
		);
		$before = $this->row( $job->id );
		$this->assertSame( TickResult::LEFT, $this->runner( true )->tick( $job->id )->status );
		$after = $this->row( $job->id );
		$this->assertSame( array( 'status', 'attempts', 'started_at' ), array_keys( array_diff_assoc( $after, $before ) ), 'but the lease, only what the claim writes of a queued job' );
		$this->assertSame( Job::RUNNING, $after['status'] );
		$this->assertSame( '', $this->repo->find( $job->id )->lock_token, 'let go' );
	}

	public function test_a_row_unreadable_after_the_claim_is_let_go_when_it_cannot_be_failed_here(): void {
		$job  = $this->job( new ClosureStep( 'stage', array( $this, 'must_not_run' ) ) );
		$repo = new RestrictedRepository( $this->repo, 'owns_files_of' );

		$repo->unreadable_after_claim = true;

		$before = $this->row( $job->id );
		$result = $this->runner( true, $repo )->tick( $job->id );
		$this->assertSame( TickResult::LEFT, $result->status, $result->message );
		$this->assertSame( '', $this->repo->find( $job->id )->lock_token, 'the claim let go, not left to lapse' );
		$this->assertSame( $before, $this->row( $job->id ), 'nothing else written' );
	}

	public function test_in_wordpress_a_step_that_leaves_fails_the_job(): void {
		$job    = $this->job(
			new ClosureStep(
				'stage',
				static function (): StepResult {
					return StepResult::leave( 'Only WP-CLI can do what is left.' );
				}
			)
		);
		$result = $this->runner( false )->tick( $job->id );
		$this->assertSame( TickResult::FAILED, $result->status );
		$this->assertStringContainsString( 'left the job to WP-CLI', (string) $this->repo->find( $job->id )->last_error );
	}

	/**
	 * Each method a storage may be unable to do (not RunnerRepository::BASIC), with a job that reaches it.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function optional_methods(): array {
		$out = array();
		foreach ( get_class_methods( RunnerRepository::class ) as $method ) {
			if ( 'can' !== $method && ! in_array( $method, RunnerRepository::BASIC, true ) ) {
				$out[ $method ] = array( $method );
			}
		}
		return $out;
	}

	/**
	 * @dataProvider optional_methods
	 *
	 * @param string $method A method the storage cannot do here.
	 */
	public function test_a_method_the_storage_cannot_do_here_leaves_the_job_as_it_is( string $method ): void {
		list( $job, $schema ) = $this->reaching( $method );
		// The control: with every method there, this job reaches it.
		$all = new RestrictedRepository( $this->repo, '' );
		list( $control, $control_schema ) = $this->reaching( $method );
		$this->runner( true, $all )->tick( $control->id, null, $control_schema );
		$this->assertGreaterThan( 0, $all->calls[ $method ] ?? 0, 'the control: the job reaches ' . $method . ' (' . wp_json_encode( $all->calls ) . ')' );

		$before = $this->row( $job->id );
		$repo   = new RestrictedRepository( $this->repo, $method );
		$result = $this->runner( true, $repo )->tick( $job->id, null, $schema );
		$this->assertSame( TickResult::LEFT, $result->status, $method . ': ' . $result->message );
		$this->assertSame( $before, $this->row( $job->id ), $method . ': the row as it was, but the lease (the path stopped before its first write)' );
		$this->assertNotSame( Job::FAILED, $this->repo->find( $job->id )->status );
	}

	/**
	 * The methods that come after the failure is written on the path of a table that lacks columns (the job's note).
	 *
	 * @return array<string, array{0: string}>
	 */
	public function after_the_columns_failure(): array {
		return array(
			'owns_files_of' => array( 'owns_files_of' ),
			'log_event'     => array( 'log_event' ),
		);
	}

	/**
	 * @dataProvider after_the_columns_failure
	 *
	 * @param string $method A method the storage cannot do here, which that path calls after a write.
	 */
	public function test_a_table_that_lacks_columns_is_left_as_it_is_when_its_note_cannot_be_written( string $method ): void {
		$set = 'log_event' === $method ? array( 'storage_path' => $this->root . '/elsewhere' ) : array();
		// The control: with every method there, the path writes the failure and reaches the method after it.
		$all     = new RestrictedRepository( $this->repo, '' );
		$control = $this->job( new ClosureStep( 'stage', array( $this, 'must_not_run' ) ), $set );
		$this->runner( true, $all )->tick( $control->id, null, array( 'blocked_count' ) );
		$this->assertGreaterThan( 0, $all->calls['fail_for_missing_columns'] ?? 0, 'the control: the failure is written' );
		$this->assertGreaterThan( 0, $all->calls[ $method ] ?? 0, 'the control: ' . $method . ' is reached after it' );

		$job    = $this->job( new ClosureStep( 'stage', array( $this, 'must_not_run' ) ), $set );
		$before = $this->row( $job->id );
		$result = $this->runner( true, new RestrictedRepository( $this->repo, $method ) )->tick( $job->id, null, array( 'blocked_count' ) );
		$this->assertSame( TickResult::LEFT, $result->status, $result->message );
		$this->assertSame( $before, $this->row( $job->id ), 'the failure is not written when what follows it cannot be done' );
	}

	/**
	 * A job a tick takes to the method, and the schema problems to pass the tick.
	 *
	 * @param string $method Method.
	 * @return array{0: Job, 1: string[]}
	 */
	private function reaching( string $method ): array {
		$plain = static function ( callable $run ): ClosureStep {
			return new ClosureStep( 'stage', $run );
		};
		switch ( $method ) {
			case 'pause_for_answer':
				return array(
					$this->job(
						$plain(
							static function (): StepResult {
								return StepResult::ask( array( 'at' => 2 ), array( array( 'id' => 'which' ) ), 'Which?' );
							}
						)
					),
					array(),
				);
			case 'reclaim_work':
				return array(
					$this->job(
						$plain(
							static function (): StepResult {
								throw new Cancelled( 'cancelled' );
							}
						),
						array( 'cancel_requested' => (int) $this->now )
					),
					array(),
				);
			case 'save_record':
				return array(
					$this->job(
						$plain(
							static function ( JobContext $ctx ): StepResult {
								$ctx->record( 'tables', 3 );
								return StepResult::progress( array( 'at' => 2 ), 10 );
							}
						)
					),
					array(),
				);
			case 'files_base':
				return array(
					$this->job(
						$plain(
							static function (): StepResult {
								throw new RetryFrom( 'Start over.', 'stage' );
							}
						),
						array( 'held_by' => (string) $this->dirs->state()['token'] )
					),
					array(),
				);
			case 'fail_for_missing_columns':
				return array( $this->job( $plain( array( $this, 'must_not_run' ) ) ), array( 'blocked_count' ) );
			case 'reset_cron_deferrals':
				return array( $this->job( $plain( array( $this, 'must_not_run' ) ), array( 'cron_deferrals' => 1 ) ), array() );
			case 'record_blocked':
				// Bound to another storage directory: the gate refuses it, and counts the refusal; with late cron requests
				// counted, which the tick clears before the gate (so the refusal is written after a write).
				return array(
					$this->job(
						$plain( array( $this, 'must_not_run' ) ),
						array(
							'storage_token'  => 'dddddddddddd',
							'cron_deferrals' => 1,
						)
					),
					array(),
				);
			case 'note_held_back':
				return array( $this->job( $plain( array( $this, 'must_not_run' ) ), array( 'cursor_json' => '{not json', 'site_state' => Job::SITE_CHANGING ) ), array() );
			case 'log_event':
				return array( $this->job( $plain( array( $this, 'must_not_run' ) ), array( 'cursor_json' => '{not json', 'storage_path' => $this->root . '/elsewhere' ) ), array() );
			case 'fail_unreadable':
			case 'owns_files_of':
				return array( $this->job( $plain( array( $this, 'must_not_run' ) ), array( 'cursor_json' => '{not json' ) ), array() );
		}
		$this->fail( 'No job reaches ' . $method . ': add one here.' );
	}

	/**
	 * A step that must not run.
	 *
	 * @return StepResult
	 */
	public function must_not_run(): StepResult {
		$this->fail( 'The step ran.' );
	}
}

/**
 * The test's repository, with one method this storage "cannot do here": can() says no, and the method throws NotHere
 * if it is reached all the same; every call is counted.
 */
final class RestrictedRepository implements RunnerRepository {

	/** @var JobRepository */
	private $inner;

	/** @var string */
	private $refused;

	/** @var array<string, int> */
	public $calls = array();

	/** @var bool Whether the job the claim takes reads as unreadable (its row changed between the first read and the claim). */
	public $unreadable_after_claim = false;

	public function __construct( JobRepository $inner, string $refused ) {
		$this->inner   = $inner;
		$this->refused = $refused;
	}

	/**
	 * Count a call, and refuse the refused method.
	 *
	 * @param string $method Method.
	 * @return void
	 */
	private function call( string $method ): void {
		if ( $method === $this->refused ) {
			throw new NotHere( 'The test refuses ' . $method ); // A method that is the first write of its path may be reached: it writes nothing.
		}
		$this->calls[ $method ] = ( $this->calls[ $method ] ?? 0 ) + 1;
	}

	public function can( string $method ): bool {
		return $method !== $this->refused;
	}

	public function jobs_table(): string {
		$this->call( 'jobs_table' );
		return $this->inner->jobs_table();
	}

	public function now(): int {
		return $this->inner->now();
	}

	public function find( int $id ) {
		return $this->inner->find( $id );
	}

	public function gate( Job $job ): array {
		return $this->inner->gate( $job );
	}

	public function record_blocked( Job $job ): int {
		$this->call( __FUNCTION__ );
		return $this->inner->record_blocked( $job );
	}

	public function reset_cron_deferrals( int $id ): void {
		$this->call( __FUNCTION__ );
		$this->inner->reset_cron_deferrals( $id );
	}

	public function acquire( int $id, int $lease = JobRepository::LOCK_SECONDS ) {
		$held = $this->inner->acquire( $id, $lease );
		if ( null !== $held && $this->unreadable_after_claim ) {
			$held['job']->unreadable_columns = array( 'cursor_json' );
		}
		return $held;
	}

	public function heartbeat( Job $job, string $token, int $lease = JobRepository::LOCK_SECONDS ): bool {
		return $this->inner->heartbeat( $job, $token, $lease );
	}

	public function release( Job $job, string $token ): bool {
		return $this->inner->release( $job, $token );
	}

	public function save_progress( Job $job, string $token, string $step, array $cursor, int $progress, string $message = '', bool $advanced = true, $site_state = null, $site_mark = null ): int {
		return $this->inner->save_progress( $job, $token, $step, $cursor, $progress, $message, $advanced, $site_state, $site_mark );
	}

	public function save_record( Job $job, string $token, string $key, $value ): void {
		$this->call( __FUNCTION__ );
		$this->inner->save_record( $job, $token, $key, $value );
	}

	public function pause_for_answer( Job $job, string $token, array $questions ): Job {
		$this->call( __FUNCTION__ );
		return $this->inner->pause_for_answer( $job, $token, $questions );
	}

	public function transition( Job $job, string $to, string $error = '', string $token = '', string $failure = '', string $retry_from = '', array $forget = array(), int $at = 0 ): Job {
		return $this->inner->transition( $job, $to, $error, $token, $failure, $retry_from, $forget, $at );
	}

	public function fail_unreadable( Job $job, string $message ): string {
		$this->call( __FUNCTION__ );
		return $this->inner->fail_unreadable( $job, $message );
	}

	public function fail_for_missing_columns( Job $job, string $message, array $unusable ): string {
		$this->call( __FUNCTION__ );
		return $this->inner->fail_for_missing_columns( $job, $message, $unusable );
	}

	public function note_held_back( Job $job, string $message ): bool {
		$this->call( __FUNCTION__ );
		return $this->inner->note_held_back( $job, $message );
	}

	public function files_base( Job $job ): string {
		$this->call( __FUNCTION__ );
		return $this->inner->files_base( $job );
	}

	public function owns_files_of( Job $job ): bool {
		$this->call( __FUNCTION__ );
		return $this->inner->owns_files_of( $job );
	}

	public function reclaim_work( Job $job, int $budget = JobRepository::RECLAIM_MAX_ENTRIES ): bool {
		$this->call( __FUNCTION__ );
		return $this->inner->reclaim_work( $job, $budget );
	}

	public function log_event( string $message ): void {
		$this->call( __FUNCTION__ );
		$this->inner->log_event( $message );
	}
}
