<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Cancelled;
use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\StaleJob;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Support\UninstallSetting;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\CliHoldingStep;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;
use WPCheckpoint\Tests\Fixtures\Jobs\HoldingStep;

/**
 * A job that holds the site changed (site_state), and a step only WP-CLI runs (CliOnly): what the engine does
 * and does not do with them. Every rule has its control: the same with a job that holds nothing.
 */
final class SiteStateTest extends WP_UnitTestCase {

	/** @var string */
	private $root;

	/** @var Directories */
	private $dirs;

	/** @var float */
	private $now;

	/** @var JobRepository */
	private $repo;

	/** @var JobTypes */
	private $types;

	/** @var mixed The plugin's version option before the test (the DROP TABLE of the jobs table commits what a test wrote). */
	private $version;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-site-state-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/releases/a/wp-includes', 0755, true );
		mkdir( $this->root . '/releases/b/wp-includes', 0755, true );
		$this->dirs  = $this->site( 'releases/a' );
		$this->now   = 1_800_000_000.0;
		$this->repo  = $this->repo_for( $this->dirs );
		$this->types   = new JobTypes();
		$this->version = get_option( Uninstaller::OPTION_VERSION );
		Schema::ensure();
	}

	public function tear_down(): void {
		global $wpdb;
		// The DROP TABLE below commits what the test wrote: the settings the uninstall tests changed are put back.
		UninstallSetting::save( false );
		false === $this->version ? delete_option( Uninstaller::OPTION_VERSION ) : update_option( Uninstaller::OPTION_VERSION, $this->version );
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

	private function runner( bool $cli, ?JobRepository $repo = null ): Runner {
		return new Runner(
			$repo ?? $this->repo,
			$this->types,
			new Redactor( Redactor::installation_secrets() ),
			array(
				'clock'        => function (): float {
					return $this->now;
				},
				'memory'       => static function (): int {
					return 10 * 1048576;
				},
				'budget'       => new Budget( 20, 32 * 1048576, false ),
				'memory_limit' => -1,
				'cli'          => $cli,
			)
		);
	}

	private function register( string $id, array $steps ): void {
		$this->types->add( new FixtureJobType( $id, $steps ) );
	}

	/**
	 * The job's row as stored.
	 *
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		global $wpdb;
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::jobs_table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * Set columns of a job's row.
	 *
	 * @param array<string, mixed> $columns Columns.
	 */
	private function set( int $id, array $columns ): void {
		global $wpdb;
		$this->assertSame( 1, (int) $wpdb->update( Schema::jobs_table(), $columns, array( 'id' => $id ) ), 'the control: the row was set' );
	}

	/**
	 * A job of a type with one plain step, with its work directory, stored as $columns say.
	 *
	 * @param array<string, mixed> $columns Columns.
	 */
	private function job( array $columns ): Job {
		if ( ! $this->types->get( 'plain' ) ) {
			$this->register( 'plain', array( new ClosureStep( 'plain', static function (): StepResult {
				return StepResult::done( 'done' );
			} ) ) );
		}
		$job = $this->repo->create( 'plain' );
		$this->set( $job->id, $columns );
		mkdir( Residue::work_dir( $job->storage_path, $job->id ), 0755, true );
		return $this->repo->find( $job->id );
	}

	private function work_left( Job $job ): bool {
		clearstatcache();
		return is_dir( Residue::work_dir( $job->storage_path, $job->id ) );
	}

	public function test_a_tick_from_another_driver_leaves_a_job_at_a_cli_only_step_alone_and_wp_cli_runs_it(): void {
		$ran = 0;
		$this->register(
			'cli',
			array(
				new CliHoldingStep(
					'swap',
					static function () use ( &$ran ): StepResult {
						++$ran;
						return StepResult::done( 'swapped' );
					}
				),
			)
		);
		$job = $this->repo->create( 'cli' );
		$this->set( $job->id, array( 'cron_deferrals' => 3 ) ); // Written back to 0 by any tick that reaches the Runner's gate.
		$before = $this->row( $job->id );
		$this->now += 5;
		$result = $this->runner( false )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::CLI, $result->status );
		$this->assertSame( -1, $result->retry_after );
		$this->assertStringContainsString( 'wp wpcheckpoint job run ' . $job->id, $result->message );
		$this->assertSame( 0, $ran );
		$this->assertSame( $before, $this->row( $job->id ), 'the row is not written: no lock, no count, no time' );

		Loopback::schedule( $job->id, 60 );
		$this->assertNotFalse( wp_next_scheduled( Loopback::HOOK, array( $job->id ) ), 'the control: an event was there' );
		( new Loopback( false ) )->after_tick( $result );
		$this->assertFalse( wp_next_scheduled( Loopback::HOOK, array( $job->id ) ), 'no driver is scheduled' );

		// A question the step asked is answered anywhere: a tick from another driver says the job waits for it.
		$this->set( $job->id, array( 'status' => Job::PAUSED, 'questions_json' => wp_json_encode( array( array( 'id' => 'keep', 'kind' => 'choice', 'choices' => array( 'keep', 'undo' ) ) ) ) ) );
		$this->assertSame( TickResult::PAUSED, $this->runner( false )->tick( $job->id, $this->now )->status );
		$this->set( $job->id, array( 'status' => Job::RUNNING, 'questions_json' => null ) );

		$done = $this->runner( true )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::COMPLETED, $done->status, 'WP-CLI runs it' );
		$this->assertSame( 1, $ran );
		$this->assertSame( 0, $this->repo->find( $job->id )->cron_deferrals, 'the control: a tick that reaches the gate writes the count back' );
	}

	public function test_a_tick_from_another_driver_stops_where_the_next_step_runs_in_wp_cli_only(): void {
		$ran = 0;
		$this->register(
			'two',
			array(
				new ClosureStep( 'check', static function (): StepResult {
					return StepResult::done( 'checked' );
				} ),
				new CliHoldingStep(
					'swap',
					static function () use ( &$ran ): StepResult {
						++$ran;
						return StepResult::done( 'swapped' );
					}
				),
			)
		);
		$job    = $this->repo->create( 'two' );
		$result = $this->runner( false )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::CLI, $result->status );
		$this->assertSame( 0, $ran );
		$row = $this->repo->find( $job->id );
		$this->assertSame( 'swap', $row->step, 'the step before it is done' );
		$this->assertSame( Job::RUNNING, $row->status );
		$this->assertSame( '', $row->lock_token, 'the lock is given back' );
		$this->assertSame( TickResult::COMPLETED, $this->runner( true )->tick( $job->id, $this->now )->status );
		$this->assertSame( 1, $ran );
	}

	public function test_the_site_state_is_written_in_the_statement_that_writes_the_cursor(): void {
		global $wpdb;
		$seen = array();
		$this->register(
			'hold',
			array(
				new HoldingStep(
					'swap',
					function ( JobContext $ctx ) use ( &$seen ): StepResult {
						$n = (int) ( $ctx->cursor()['n'] ?? 0 );
						if ( 0 === $n ) {
							$ctx->checkpoint(
								array(
									'n'    => 1,
									'site' => Job::SITE_CHANGING,
								),
								10
							);
							$seen[] = (int) $this->row( $ctx->job()->id )['site_state'];
							return StepResult::progress(
								array(
									'n'    => 2,
									'site' => Job::SITE_SWAPPED,
								),
								50
							);
						}
						return StepResult::done( 'swapped' );
					}
				),
			)
		);
		$this->register( 'plain2', array( new ClosureStep( 'plain', static function ( JobContext $ctx ): StepResult {
			$ctx->checkpoint( array( 'n' => 1 ), 10 );
			return StepResult::done( 'done' );
		} ) ) );
		$updates = array();
		$filter  = static function ( string $sql ) use ( &$updates ): string {
			if ( 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, '`cursor_json` =' ) ) {
				$updates[] = $sql;
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			$job = $this->repo->create( 'hold' );
			$this->runner( true )->tick( $job->id, $this->now );
			$held    = $updates;
			$updates = array();
			$plain   = $this->repo->create( 'plain2' );
			$this->runner( true )->tick( $plain->id, $this->now );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( array( Job::SITE_CHANGING ), $seen, 'the checkpoint wrote it' );
		$after = $this->repo->find( $job->id );
		$this->assertSame( Job::COMPLETED, $after->status );
		$this->assertSame( Job::SITE_SWAPPED, $after->site_state, 'the step\'s last state stays when it is done (its empty cursor says nothing)' );
		$this->assertNotEmpty( $held, 'the control: the cursor writes were seen' );
		$with = array_filter(
			$held,
			static function ( string $sql ): bool {
				return false !== strpos( $sql, '`site_state`' );
			}
		);
		$this->assertCount( 2, $with, 'the checkpoint and the progress each wrote it with their cursor, in one statement' );
		$this->assertNotEmpty( $updates, 'the control: the plain job\'s cursor writes were seen' );
		foreach ( $updates as $sql ) {
			$this->assertStringNotContainsString( 'site_state', $sql, 'a step that does not hold the site leaves the column alone' );
		}
		$this->assertSame( Job::SITE_UNTOUCHED, $this->repo->find( $plain->id )->site_state );
	}

	/**
	 * This installation's storage in a custom directory (WPCHECKPOINT_STORAGE_DIR) of the stand-in site.
	 */
	private function custom( string $name ): Directories {
		return new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/releases/a/', 'custom_dir' => $this->root . '/' . $name ) );
	}

	public function test_a_job_that_holds_the_site_goes_on_after_the_storage_directory_moved_and_one_that_does_not_is_refused(): void {
		$ran = 0;
		$this->register(
			'hold2',
			array(
				new CliHoldingStep(
					'swap',
					static function () use ( &$ran ): StepResult {
						++$ran;
						return StepResult::progress( array( 'site' => Job::SITE_UNTOUCHED ), 50 );
					}
				),
			)
		);
		mkdir( $this->root . '/store-a' );
		mkdir( $this->root . '/store-b' );
		$first = $this->repo_for( $this->custom( 'store-a' ) );
		$held  = $first->create( 'hold2' );
		$plain = $first->create( 'hold2' );
		$this->set( $held->id, array( 'site_state' => Job::SITE_CHANGING, 'status' => Job::RUNNING ) );
		$this->set( $plain->id, array( 'status' => Job::RUNNING ) );
		// The directory is moved (the constant set to another place): a new token, the old one kept as this installation's.
		$moved = $this->custom( 'store-b' );
		$this->assertNotSame( $held->storage_token, (string) $moved->state()['token'], 'the control: another token' );
		$this->assertContains( $held->storage_token, Directories::own_tokens( $moved->state() ), 'the control: the old token is still this installation\'s' );
		$other = $this->repo_for( $moved );

		$this->assertSame( TickResult::BLOCKED, $this->runner( true, $other )->tick( $plain->id, $this->now )->status, 'the control: a job that holds nothing is refused' );
		$this->assertSame( 0, $ran );
		$result = $this->runner( true, $other )->tick( $held->id, $this->now );
		$this->assertSame( TickResult::MORE, $result->status, (string) $result->message );
		$this->assertGreaterThan( 0, $ran, 'the job that holds the site ran' );
	}

	public function test_a_copied_database_s_job_that_holds_the_site_is_not_run_by_the_copy(): void {
		$ran = 0;
		$this->register(
			'hold3',
			array(
				new CliHoldingStep(
					'swap',
					static function () use ( &$ran ): StepResult {
						++$ran;
						return StepResult::progress( array( 'site' => Job::SITE_CHANGING ), 50 );
					}
				),
			)
		);
		$held = $this->repo->create( 'hold3' );
		$this->set( $held->id, array( 'site_state' => Job::SITE_CHANGING, 'status' => Job::RUNNING ) );
		// Another ABSPATH with the same stored state: a copy of the site (the clone notice is pending).
		$copy = $this->site( 'releases/b' );
		$copy->base();
		$this->assertTrue( $copy->state()['clone_detected'], 'the control: the copy is detected' );
		$repo = $this->repo_for( $copy );
		$this->assertFalse( $repo->gate( $held )['allowed'] );
		$this->assertNull( $repo->acquire( $held->id ), 'the storage token is part of the compare-and-set' );
		$this->assertSame( TickResult::BLOCKED, $this->runner( true, $repo )->tick( $held->id, $this->now )->status );
		$this->assertSame( 0, $ran );
		$this->assertSame( TickResult::MORE, $this->runner( true )->tick( $held->id, $this->now )->status, 'the control: the original runs it' );
		$this->assertGreaterThan( 0, $ran );
	}

	public function test_a_job_that_holds_the_site_is_not_run_by_code_older_than_the_table(): void {
		$ran = 0;
		$this->register(
			'hold4',
			array(
				new CliHoldingStep(
					'swap',
					static function () use ( &$ran ): StepResult {
						++$ran;
						return StepResult::progress( array( 'site' => Job::SITE_CHANGING ), 50 );
					}
				),
			)
		);
		$held   = $this->repo->create( 'hold4' );
		$stored = Options::get( Schema::OPTION );
		$this->set( $held->id, array( 'site_state' => Job::SITE_CHANGING, 'status' => Job::RUNNING ) );
		Options::set( Schema::OPTION, array_merge( (array) $stored, array( 'min_compatible' => Schema::CURRENT + 1 ) ) );
		try {
			$result = $this->runner( true )->tick( $held->id, $this->now );
		} finally {
			Options::set( Schema::OPTION, $stored );
		}
		$this->assertSame( TickResult::BLOCKED, $result->status );
		$this->assertStringContainsString( 'newer version', (string) $result->message );
		$this->assertSame( 0, $ran );
		$this->assertSame( TickResult::MORE, $this->runner( true )->tick( $held->id, $this->now )->status, 'the control: runs with the schema compatible' );
	}

	public function test_a_checkpoint_the_database_refuses_stops_the_run_before_it_goes_on(): void {
		global $wpdb;
		$after = 0;
		$this->register(
			'hold5',
			array(
				new CliHoldingStep(
					'swap',
					static function ( JobContext $ctx ) use ( &$after ): StepResult {
						$ctx->checkpoint( array( 'site' => Job::SITE_CHANGING ), 10 );
						++$after; // What the step would change once the row says so.
						return StepResult::progress( array( 'site' => Job::SITE_SWAPPED ), 50 );
					}
				),
			)
		);
		$refuse = true;
		$filter = static function ( string $sql ) use ( &$refuse ): string {
			if ( $refuse && 0 === strpos( $sql, 'UPDATE' ) && false !== strpos( $sql, '`site_state` =' ) ) {
				return 'SELECT * FROM a_table_that_is_not_there_' . bin2hex( random_bytes( 3 ) );
			}
			return $sql;
		};
		$job = $this->repo->create( 'hold5' );
		add_filter( 'query', $filter );
		$quiet = $wpdb->suppress_errors( true );
		try {
			$result = $this->runner( true )->tick( $job->id, $this->now );
		} finally {
			$wpdb->suppress_errors( $quiet );
			remove_filter( 'query', $filter );
		}
		$this->assertSame( TickResult::LOST, $result->status );
		$this->assertSame( 0, $after, 'the step did not go on' );
		$this->assertSame( Job::SITE_UNTOUCHED, $this->repo->find( $job->id )->site_state );

		$this->set( $job->id, array( 'locked_until' => 1 ) );
		$refuse = false;
		$this->runner( true )->tick( $job->id, $this->now );
		$this->assertGreaterThan( 0, $after, 'the control: with the write stored it goes on' );
		$this->assertSame( Job::SITE_SWAPPED, $this->repo->find( $job->id )->site_state );
	}

	public function test_the_engine_refuses_what_would_leave_the_site_changed_behind(): void {
		$cases = array(
			'retry from an earlier step'   => static function (): StepResult {
				throw new \WPCheckpoint\Jobs\RetryFrom( 'from the start', 'first' );
			},
			'cancelled while changing'     => static function (): StepResult {
				throw new Cancelled( 'cancelled' );
			},
			'done while changing'          => static function (): StepResult {
				return StepResult::done( 'done' );
			},
			'a site state out of range'    => static function (): StepResult {
				return StepResult::progress( array( 'site' => 7 ), 50 );
			},
		);
		foreach ( $cases as $label => $end ) {
			$type = 'refuse_' . md5( $label );
			$this->register(
				$type,
				array(
					new ClosureStep( 'first', static function (): StepResult {
						return StepResult::done( 'first' );
					} ),
					new CliHoldingStep( 'swap', $end ),
				)
			);
			$job = $this->repo->create( $type );
			$this->set( $job->id, array( 'step' => 'swap', 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING ) );
			$this->runner( true )->tick( $job->id, $this->now );
			$after = $this->repo->find( $job->id );
			$this->assertSame( Job::FAILED, $after->status, $label );
			$this->assertSame( Job::SITE_CHANGING, $after->site_state, $label );
			$this->assertSame( '', $after->lock_token, $label . ': the lock is given back' );
			$this->assertArrayNotHasKey( JobRepository::RETRY_FROM_KEY, $after->cursor, $label . ': a retry continues this step' );
		}
		// The control: with the site as it was, a retry from an earlier step is recorded.
		$this->register( 'refuse_control', array( new ClosureStep( 'first', static function (): StepResult {
			return StepResult::done( 'first' );
		} ), new CliHoldingStep( 'swap', $cases['retry from an earlier step'] ) ) );
		$job = $this->repo->create( 'refuse_control' );
		$this->set( $job->id, array( 'step' => 'swap', 'status' => Job::RUNNING ) );
		$this->runner( true )->tick( $job->id, $this->now );
		$this->assertSame( 'first', $this->repo->find( $job->id )->cursor[ JobRepository::RETRY_FROM_KEY ] ?? null );
	}

	public function test_time_and_storage_rules_leave_a_job_that_holds_the_site_alone(): void {
		$old   = (int) $this->now - JobRepository::STALL_SECONDS - 3600;
		$held  = $this->job( array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING, 'progress_at' => $old, 'created_at' => $old, 'started_at' => $old ) );
		$plain = $this->job( array( 'status' => Job::RUNNING, 'progress_at' => $old, 'created_at' => $old, 'started_at' => $old ) );
		$this->repo->reap();
		$this->assertSame( Job::FAILED, $this->repo->find( $plain->id )->status, 'the control: 24 hours without progress fail a job' );
		$this->assertSame( Job::RUNNING, $this->repo->find( $held->id )->status );

		$held2  = $this->job( array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_SWAPPED ) );
		$plain2 = $this->job( array( 'status' => Job::RUNNING ) );
		$next   = $this->site( 'releases/b' );
		$next->base();
		( new Notices( $next ) )->record_dismissal( 'clone_detected' ); // Keeps the new directory: settles the jobs.
		$this->assertSame( Job::FAILED, $this->repo->find( $plain2->id )->status, 'the control: another storage directory fails a job' );
		$this->assertSame( Job::RUNNING, $this->repo->find( $held2->id )->status );
	}

	public function test_expiry_purge_and_the_residue_rules_leave_a_job_that_holds_the_site_alone(): void {
		$long   = (int) $this->now - 400 * 86400;
		$failed = $this->job( array( 'status' => Job::FAILED, 'site_state' => Job::SITE_CHANGING, 'finished_at' => $long ) );
		$done   = $this->job( array( 'status' => Job::COMPLETED, 'site_state' => Job::SITE_SWAPPED, 'finished_at' => $long ) );
		$plain1 = $this->job( array( 'status' => Job::FAILED, 'finished_at' => $long ) );
		$plain2 = $this->job( array( 'status' => Job::COMPLETED, 'finished_at' => $long ) );
		$this->repo->purge();
		$this->assertNull( $this->repo->find( $plain1->id ), 'the control: an old failed job goes' );
		$this->assertNull( $this->repo->find( $plain2->id ), 'the control: an old completed job goes' );
		$this->assertFalse( $this->work_left( $plain1 ) );
		foreach ( array( $failed, $done ) as $job ) {
			$now = $this->repo->find( $job->id );
			$this->assertNotNull( $now );
			$this->assertSame( 0, $now->work_expired_at );
			$this->assertTrue( $this->work_left( $job ) );
		}

		$recent = (int) $this->now - 60;
		$swapped = $this->job( array( 'status' => Job::COMPLETED, 'site_state' => Job::SITE_SWAPPED, 'finished_at' => $recent ) );
		$ended   = $this->job( array( 'status' => Job::COMPLETED, 'finished_at' => $recent ) );
		$this->repo->reap_residue();
		$this->assertFalse( $this->work_left( $ended ), 'the control: a completed job\'s work is an orphan' );
		$this->assertTrue( $this->work_left( $swapped ) );
		$this->assertFalse( $this->repo->reclaim_work( $this->repo->find( $swapped->id ) ) );
		$this->assertTrue( $this->work_left( $swapped ) );
	}

	public function test_a_cancel_is_only_requested_while_the_site_is_changing_and_refused_once_it_is_swapped(): void {
		$this->register( 'plain', array( new ClosureStep( 'plain', static function (): StepResult {
			return StepResult::done( 'done' );
		} ) ) );
		$actions  = new JobActions( $this->repo, $this->runner( false ), new Loopback( false ) );
		$changing = $this->job( array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING ) );
		$outcome  = $actions->cancel( $changing->id );
		$this->assertSame( 'requested', $outcome['reason'] );
		$now = $this->repo->find( $changing->id );
		$this->assertSame( Job::RUNNING, $now->status );
		$this->assertSame( (int) $this->now, $now->cancel_requested );
		$this->assertTrue( $this->work_left( $changing ) );

		$swapped = $this->job( array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_SWAPPED ) );
		$this->assertSame( 'swapped', $actions->cancel( $swapped->id )['reason'] );
		$this->assertStringContainsString( 'cannot change it back', \WPCheckpoint\Rest\JobsController::cancel_message( 'swapped' ) );
		$this->assertSame( Job::RUNNING, $this->repo->find( $swapped->id )->status );

		$plain = $this->job( array( 'status' => Job::RUNNING ) );
		$this->assertSame( 'cleaned', $actions->cancel( $plain->id )['reason'], 'the control: a job that holds nothing is cancelled' );
		$this->assertSame( Job::CANCELLED, $this->repo->find( $plain->id )->status );
	}

	public function test_no_transition_from_outside_cancels_or_fails_a_job_that_holds_the_site(): void {
		$lapsed   = (int) $this->now - 10;
		$changing = $this->job( array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING, 'lock_token' => 'a-dead-run', 'locked_until' => $lapsed ) );
		$this->assertNull( $this->repo->acquire_for_cancel( $changing->id ), 'a cancel does not take the lapsed lock of a job that holds the site' );
		try {
			$this->repo->transition( $this->repo->find( $changing->id ), Job::CANCELLED );
			$this->fail( 'the job was cancelled from outside' );
		} catch ( StaleJob $e ) {
			$this->assertSame( Job::RUNNING, $this->repo->find( $changing->id )->status );
		}
		$plain = $this->job( array( 'status' => Job::RUNNING, 'lock_token' => 'a-dead-run', 'locked_until' => $lapsed ) );
		$this->assertNotNull( $this->repo->acquire_for_cancel( $plain->id ), 'the control: the lapsed lock of a job that holds nothing is taken' );
		$plain2 = $this->job( array( 'status' => Job::RUNNING ) );
		$this->repo->transition( $plain2, Job::CANCELLED );
		$this->assertSame( Job::CANCELLED, $this->repo->find( $plain2->id )->status, 'the control: cancelled from outside' );

		// Failed from outside: a question nobody answered for the retention period.
		$long     = (int) $this->now - JobRepository::WORK_RETENTION_SECONDS - 60;
		$question = wp_json_encode( array( array( 'id' => 'keep', 'kind' => 'choice', 'choices' => array( 'keep', 'undo' ) ) ) );
		$asking   = $this->job( array( 'status' => Job::PAUSED, 'site_state' => Job::SITE_SWAPPED, 'questions_json' => $question, 'updated_at' => $long ) );
		$plain3   = $this->job( array( 'status' => Job::PAUSED, 'questions_json' => $question, 'updated_at' => $long ) );
		$this->repo->reap();
		$this->assertSame( Job::FAILED, $this->repo->find( $plain3->id )->status, 'the control: an unanswered job is failed from outside' );
		$this->assertSame( Job::PAUSED, $this->repo->find( $asking->id )->status );
		$this->assertTrue( $this->work_left( $asking ) );
	}

	public function test_late_cron_requests_never_fail_a_job_that_holds_the_site(): void {
		$held  = $this->job( array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING, 'cron_deferrals' => 10 ) );
		$plain = $this->job( array( 'status' => Job::RUNNING, 'cron_deferrals' => 10 ) );
		$this->assertSame( JobRepository::FAIL_DONE, $this->repo->fail_for_late_cron( $plain, 'late', 10 ), 'the control: a job that holds nothing is failed' );
		$this->assertSame( JobRepository::FAIL_HELD, $this->repo->fail_for_late_cron( $held, 'late', 10 ) );
		$this->assertSame( Job::RUNNING, $this->repo->find( $held->id )->status );
	}

	public function test_a_step_that_put_the_site_back_after_a_cancel_request_ends_the_job_cancelled_and_cleaned(): void {
		$cleaned = 0;
		$this->register(
			'undo',
			array(
				new CliHoldingStep(
					'swap',
					static function ( JobContext $ctx ): StepResult {
						if ( 0 === $ctx->job()->cancel_requested ) {
							return StepResult::progress( array( 'site' => Job::SITE_CHANGING ), 50 );
						}
						$ctx->checkpoint( array( 'site' => Job::SITE_UNTOUCHED, 'back' => true ), 60 );
						throw new Cancelled( 'The site is as it was; the restore is cancelled.' );
					},
					static function () use ( &$cleaned ): void {
						++$cleaned;
					}
				),
			)
		);
		$job = $this->repo->create( 'undo' );
		mkdir( Residue::work_dir( $job->storage_path, $job->id ), 0755, true );
		$this->assertSame( TickResult::MORE, $this->runner( true )->tick( $job->id, $this->now )->status );
		$this->assertSame( Job::SITE_CHANGING, $this->repo->find( $job->id )->site_state );
		$actions = new JobActions( $this->repo, $this->runner( false ), new Loopback( false ) );
		$this->assertSame( 'requested', $actions->cancel( $job->id )['reason'] );
		$this->assertSame( 0, $cleaned, 'the control: nothing is cleaned while the site is changing' );

		$result = $this->runner( true )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::FINISHED, $result->status );
		$after = $this->repo->find( $job->id );
		$this->assertSame( Job::CANCELLED, $after->status );
		$this->assertSame( Job::SITE_UNTOUCHED, $after->site_state );
		$this->assertSame( 1, $cleaned );
		$this->assertFalse( $this->work_left( $job ), 'the engine reclaimed its work' );
	}

	public function test_the_log_of_a_job_that_holds_the_site_goes_to_php_when_its_file_cannot_be_written(): void {
		$sink  = $this->root . '/php-error.log';
		$was   = ini_get( 'error_log' );
		$redac = new Redactor( array() );
		ini_set( 'error_log', $sink );
		try {
			( new Logger( $this->root . '/missing/dir/job.log', $redac ) )->info( 'dropped line' );
			( new Logger( $this->root . '/missing/dir/job.log', $redac, Logger::DEFAULT_MAX_BYTES, array( Runner::class, 'to_php_log' ) ) )->info( 'kept line', array( 'at' => ABSPATH . 'wp-content/x' ) );
			( new Logger( $this->root . '/job.log', $redac, Logger::DEFAULT_MAX_BYTES, array( Runner::class, 'to_php_log' ) ) )->info( 'file line' );
		} finally {
			ini_set( 'error_log', (string) $was );
		}
		$php = (string) @file_get_contents( $sink );
		$this->assertStringContainsString( 'kept line', $php );
		$this->assertStringContainsString( '{wp-content}/x', $php, 'the paths in it are masked' );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $php );
		$this->assertStringNotContainsString( 'dropped line', $php, 'without the fallback a line is dropped, as before' );
		$this->assertStringNotContainsString( 'file line', $php, 'a writable file takes the line' );
		$this->assertStringContainsString( 'file line', (string) file_get_contents( $this->root . '/job.log' ), 'the control: the file was written' );
	}

	public function test_uninstalling_keeps_everything_while_a_job_holds_the_site(): void {
		$was = ini_get( 'error_log' );
		ini_set( 'error_log', $this->root . '/php-error.log' );
		try {
			$this->uninstall_with_a_held_site();
		} finally {
			ini_set( 'error_log', (string) $was );
		}
		$this->assertStringContainsString( 'left in place', (string) file_get_contents( $this->root . '/php-error.log' ), 'the reason is logged' );
	}

	private function uninstall_with_a_held_site(): void {
		UninstallSetting::save( true );
		update_option( Uninstaller::OPTION_VERSION, '1.2.3' );
		$held = $this->job( array( 'status' => Job::COMPLETED, 'site_state' => Job::SITE_SWAPPED ) );
		$live = $this->job( array( 'status' => Job::RUNNING ) );
		$this->assertSame( 1, Uninstaller::jobs_holding_the_site() );
		Uninstaller::run();
		$this->assertTrue( Schema::table_exists(), 'the jobs table stays' );
		$this->assertSame( '1.2.3', get_option( Uninstaller::OPTION_VERSION ) );
		$this->assertSame( Job::RUNNING, $this->repo->find( $live->id )->status, 'no job is cancelled' );
		$this->assertSame( Job::SITE_SWAPPED, $this->repo->find( $held->id )->site_state );

		$this->set( $held->id, array( 'site_state' => Job::SITE_UNTOUCHED ) );
		$this->assertSame( 0, Uninstaller::jobs_holding_the_site() );
		Uninstaller::run();
		$this->assertFalse( Schema::table_exists(), 'the control: without it the data goes as the user chose' );
		$this->assertFalse( get_option( Uninstaller::OPTION_VERSION ) );
	}

	public function test_uninstalling_keeps_everything_when_whether_a_job_holds_the_site_cannot_be_read(): void {
		global $wpdb;
		UninstallSetting::save( true );
		update_option( Uninstaller::OPTION_VERSION, '1.2.3' );
		$this->job( array( 'status' => Job::RUNNING ) );
		$filter = static function ( string $sql ): string {
			return false !== strpos( $sql, "LIKE 'site_state'" ) ? 'SELECT * FROM a_table_that_is_not_there' : $sql;
		};
		$was   = ini_get( 'error_log' );
		$quiet = $wpdb->suppress_errors( true );
		ini_set( 'error_log', $this->root . '/php-error.log' );
		add_filter( 'query', $filter );
		try {
			$this->assertNull( Uninstaller::jobs_holding_the_site() );
			Uninstaller::run();
		} finally {
			remove_filter( 'query', $filter );
			ini_set( 'error_log', (string) $was );
			$wpdb->suppress_errors( $quiet );
		}
		$this->assertTrue( Schema::table_exists(), 'the jobs table stays' );
		$this->assertSame( '1.2.3', get_option( Uninstaller::OPTION_VERSION ) );
		$this->assertStringContainsString( 'could not tell', (string) file_get_contents( $this->root . '/php-error.log' ) );
		$this->assertSame( 0, Uninstaller::jobs_holding_the_site(), 'the control: readable, it holds none' );
	}

	public function test_a_cancel_request_ends_with_the_swap_and_with_a_retry_of_a_job_that_holds_nothing(): void {
		$this->register(
			'swaps',
			array(
				new CliHoldingStep(
					'swap',
					static function ( JobContext $ctx ): StepResult {
						$ctx->checkpoint( array( 'site' => Job::SITE_SWAPPED ), 90 );
						return StepResult::done( 'swapped' );
					}
				),
			)
		);
		$job = $this->repo->create( 'swaps' );
		$this->set( $job->id, array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING, 'cancel_requested' => 5 ) );
		$this->runner( true )->tick( $job->id, $this->now );
		$after = $this->repo->find( $job->id );
		$this->assertSame( Job::COMPLETED, $after->status );
		$this->assertSame( 0, $after->cancel_requested, 'a request the swap outran does not act on what changes the site later' );

		$held  = $this->job( array( 'status' => Job::FAILED, 'site_state' => Job::SITE_CHANGING, 'cancel_requested' => 5, 'finished_at' => (int) $this->now ) );
		$plain = $this->job( array( 'status' => Job::FAILED, 'cancel_requested' => 5, 'finished_at' => (int) $this->now ) );
		$this->repo->transition( $held, Job::QUEUED );
		$this->repo->transition( $plain, Job::QUEUED );
		$this->assertSame( 5, $this->repo->find( $held->id )->cancel_requested, 'kept: the retry puts the site back, then cancels' );
		$this->assertSame( 0, $this->repo->find( $plain->id )->cancel_requested );
	}

	public function test_a_table_without_columns_this_code_needs_never_fails_a_job_that_holds_the_site(): void {
		$held  = $this->job( array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING ) );
		$plain = $this->job( array( 'status' => Job::RUNNING ) );
		$this->assertSame( JobRepository::FAIL_DONE, $this->repo->fail_for_missing_columns( $plain, 'columns missing', array( 'x' ) ), 'the control' );
		$this->assertSame( JobRepository::FAIL_HELD, $this->repo->fail_for_missing_columns( $held, 'columns missing', array( 'x' ) ) );
		$this->assertSame( Job::RUNNING, $this->repo->find( $held->id )->status );
	}
}

