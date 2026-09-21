<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;

/**
 * A step that asks the user a question: the job pauses, no driver ticks
 * it, the answers land in the options, the same step runs again. Job
 * options travel with the job and never carry a secret.
 */
final class AskStepTest extends WP_UnitTestCase {

	/** @var Directories */
	private $dirs;

	/** @var string */
	private $base;

	/** @var float */
	private $now;

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
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-ask-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->root . '/site/wp-includes', 0755, true );
		$this->dirs  = new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/site/' ) );
		$this->base  = $this->dirs->base();
		$this->now   = 1_800_000_000.0;
		$this->repo  = new JobRepository( $this->dirs, null, function (): int {
			return (int) floor( $this->now );
		} );
		$this->types = new JobTypes();
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

	private function runner(): Runner {
		return new Runner(
			$this->repo,
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
				'paths'        => array( '{abspath}' => rtrim( ABSPATH, '/' ) ),
			)
		);
	}

	private function presenter(): JobPresenter {
		return new JobPresenter( new Redactor( Redactor::installation_secrets() ), $this->types, $this->dirs );
	}

	/**
	 * A step that asks unless the options carry a policy or an answer, and
	 * records what it saw. Its cursor marks that it did some work first.
	 *
	 * @param array<int, string> $seen Answers the step ran with (collected).
	 */
	private function asking_step( array &$seen, int &$runs ): ClosureStep {
		return new ClosureStep( 'review', function ( JobContext $ctx ) use ( &$seen, &$runs ): StepResult {
			++$runs;
			$options = $ctx->options();
			$answer  = isset( $options['answers']['unreadable'] ) ? (string) $options['answers']['unreadable'] : ( isset( $options['policy']['unreadable'] ) ? (string) $options['policy']['unreadable'] : '' );
			if ( '' === $answer ) {
				$ctx->checkpoint( array( 'listed' => 3 ), 40, 'listed 3 unreadable files' );
				return StepResult::ask(
					array( 'listed' => 3 ),
					array(
						array(
							'id'    => 'unreadable',
							'count' => 3,
							'paths' => array( 'wp-content/uploads/a.jpg', 'wp-content/uploads/b.jpg', 'wp-content/uploads/c.jpg' ),
						),
					),
					'3 files cannot be read; continue without them?'
				);
			}
			$seen[] = $answer;
			return StepResult::done( 'reviewed with ' . $answer );
		} );
	}

	public function test_a_question_pauses_the_job_until_it_is_answered_and_the_same_step_continues(): void {
		$seen = array();
		$runs = 0;
		$this->types->add( new FixtureJobType( 'ask', array( $this->asking_step( $seen, $runs ), new ClosureStep( 'after', static function (): StepResult {
			return StepResult::done( 'after' );
		} ) ) ) );
		$job = $this->repo->create( 'ask', 0, array(), array( 'contents' => array( 'database' => true ) ) );
		$this->assertSame( array( 'contents' => array( 'database' => true ) ), $this->repo->find( $job->id )->options );

		$result = $this->runner()->tick( $job->id, $this->now );
		$this->assertSame( TickResult::PAUSED, $result->status );
		$this->assertSame( -1, $result->retry_after, 'nothing to wait out' );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::PAUSED, $stored->status );
		$this->assertTrue( $stored->awaiting_answer() );
		$this->assertSame( 'unreadable', $stored->questions[0]['id'] );
		$this->assertSame( 3, $stored->questions[0]['count'] );
		$this->assertSame( 'review', $stored->step );
		$this->assertSame( array( 'listed' => 3 ), JobContext::strip_reserved( $stored->cursor ), 'the cursor is kept' );
		$this->assertSame( 0, $stored->cursor['__runner']['no_progress'], 'asking is not a zero-progress unit' );
		$this->assertSame( '', $stored->lock_token, 'a paused job holds no lock' );
		$this->assertSame( 1, $runs );
		$progress_at = $stored->progress_at;

		// Ticks while unanswered: refused before the step runs, no back-off recorded, no stall.
		$this->now += 2 * 86400;
		$again      = $this->runner()->tick( $job->id, $this->now );
		$this->assertSame( TickResult::PAUSED, $again->status );
		$this->assertStringContainsString( 'waiting for your decision', $again->message );
		$this->assertSame( 1, $runs, 'the step did not run' );
		$this->assertSame( 0, $this->repo->find( $job->id )->blocked_count );
		$this->assertNull( $this->repo->acquire( $job->id ), 'the lock cannot be taken either' );
		$this->repo->reap();
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::PAUSED, $stored->status, 'the 24-hour stall rule does not apply to a paused job' );
		$this->assertSame( $progress_at, $stored->progress_at );

		// Presented: the questions are there, options never are.
		$data = $this->presenter()->present( $stored );
		$this->assertSame( 'unreadable', $data['questions'][0]['id'] );
		$this->assertSame( 'wp-content/uploads/b.jpg', $data['questions'][0]['paths'][1] );
		$this->assertArrayNotHasKey( 'options', $data );
		$this->assertArrayNotHasKey( 'cursor', $data );

		// Answered: the next tick runs the same step with the answers and finishes the job.
		try {
			$this->repo->answer( $stored, array( 'unreadable' => 'continue' ) );
		} catch ( \Throwable $e ) {
			$this->fail( get_class( $e ) . ': ' . $e->getMessage() );
		}
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::PAUSED, $stored->status );
		$this->assertFalse( $stored->awaiting_answer() );
		$this->assertSame( array(), $stored->questions );
		$this->assertSame( 'continue', $stored->options['answers']['unreadable'] );
		$this->assertSame( true, $stored->options['contents']['database'], 'the original options are kept' );
		$this->assertNull( $this->presenter()->present( $stored )['questions'] );

		$result = $this->runner()->tick( $job->id, $this->now );
		$this->assertSame( TickResult::COMPLETED, $result->status );
		$this->assertSame( array( 'continue' ), $seen );
		$this->assertSame( 2, $runs );
		$this->assertSame( Job::COMPLETED, $this->repo->find( $job->id )->status );
		$log = (string) file_get_contents( $this->base . '/' . $this->repo->find( $job->id )->log_path );
		$this->assertStringContainsString( 'Step asks for a decision', $log );
	}

	public function test_a_policy_in_the_options_answers_without_asking(): void {
		$seen = array();
		$runs = 0;
		$this->types->add( new FixtureJobType( 'unattended', array( $this->asking_step( $seen, $runs ) ) ) );
		$job    = $this->repo->create( 'unattended', 0, array(), array( 'policy' => array( 'unreadable' => 'fail' ) ) );
		$result = $this->runner()->tick( $job->id, $this->now );
		$this->assertSame( TickResult::COMPLETED, $result->status );
		$this->assertSame( array( 'fail' ), $seen );
	}

	public function test_answering_is_refused_unless_the_job_waits_and_a_second_answer_merges(): void {
		$seen = array();
		$runs = 0;
		$this->types->add( new FixtureJobType( 'ask', array( $this->asking_step( $seen, $runs ) ) ) );
		$job = $this->repo->create( 'ask' );
		try {
			$this->repo->answer( $job, array( 'unreadable' => 'continue' ) );
			$this->fail();
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'not waiting', $e->getMessage() );
		}
		$this->runner()->tick( $job->id, $this->now );
		$paused = $this->repo->find( $job->id );
		$this->repo->answer( $paused, array( 'large_dirs' => 'include' ) );
		// A second answer to the same pause: the job no longer waits.
		try {
			$this->repo->answer( $this->repo->find( $job->id ), array( 'unreadable' => 'continue' ) );
			$this->fail();
		} catch ( InvalidTransition $e ) {
			$this->assertTrue( true );
		}
		// The step asks again (its question is still open) and the new answer merges with the old one.
		$this->assertSame( TickResult::PAUSED, $this->runner()->tick( $job->id, $this->now )->status );
		$this->repo->answer( $this->repo->find( $job->id ), array( 'unreadable' => 'continue' ) );
		$stored = $this->repo->find( $job->id );
		$this->assertSame( array( 'large_dirs' => 'include', 'unreadable' => 'continue' ), $stored->options['answers'] );
		$this->assertSame( TickResult::COMPLETED, $this->runner()->tick( $job->id, $this->now )->status );
	}

	public function test_options_questions_and_answers_must_not_carry_a_secret(): void {
		$secret = defined( 'DB_PASSWORD' ) && strlen( (string) DB_PASSWORD ) >= 4 ? (string) DB_PASSWORD : '';
		if ( '' === $secret ) {
			$this->markTestSkipped( 'The test database has no password to use as a known secret.' );
		}
		$runs = 0;
		$seen = array();
		$this->types->add( new FixtureJobType( 'ask', array( $this->asking_step( $seen, $runs ) ) ) );
		$this->types->add( new FixtureJobType( 'leaky', array( new ClosureStep( 'leak', static function () use ( $secret ): StepResult {
			return StepResult::ask( array(), array( array( 'id' => 'x', 'value' => $secret ) ), 'leak' );
		} ) ) ) );
		try {
			$this->repo->create( 'ask', 0, array(), array( 'exclude' => 'prefix-' . $secret ) );
			$this->fail( 'options are checked like a cursor' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'credentials', $e->getMessage() );
		}
		$job    = $this->repo->create( 'leaky' );
		$result = $this->runner()->tick( $job->id, $this->now );
		$this->assertSame( TickResult::FAILED, $result->status, 'a question carrying a secret fails the job instead of being shown' );
		$this->assertStringContainsString( 'credentials', $this->repo->find( $job->id )->last_error );
		$this->assertStringNotContainsString( $secret, $this->repo->find( $job->id )->last_error );

		$job = $this->repo->create( 'ask' );
		$this->runner()->tick( $job->id, $this->now );
		try {
			$this->repo->answer( $this->repo->find( $job->id ), array( 'unreadable' => $secret ) );
			$this->fail( 'answers are checked like a cursor' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( $this->repo->find( $job->id )->awaiting_answer(), 'the job still waits' );
		}
	}

	public function test_an_unanswered_job_is_given_up_after_the_retention_period_and_its_work_reclaimed(): void {
		$seen = array();
		$runs = 0;
		$this->types->add( new FixtureJobType( 'ask', array( $this->asking_step( $seen, $runs ) ) ) );
		$job = $this->repo->create( 'ask' );
		$this->runner()->tick( $job->id, $this->now );
		$work = Residue::work_dir( $this->base, $job->id );
		mkdir( $work );
		touch( $work . '/scan.summary.json' );

		$this->now += JobRepository::WORK_RETENTION_SECONDS - 60;
		$this->repo->reap();
		$this->assertSame( Job::PAUSED, $this->repo->find( $job->id )->status, 'still within the retention period' );
		$this->assertDirectoryExists( $work );

		$this->now += 120;
		$this->repo->reap();
		$stored = $this->repo->find( $job->id );
		$this->assertSame( Job::FAILED, $stored->status );
		$this->assertStringContainsString( 'No answer within 7 days', $stored->last_error );
		$this->assertGreaterThan( 0, $stored->work_expired_at );
		$this->assertFalse( $stored->can_retry() );
		$this->assertDirectoryDoesNotExist( $work );
	}

	public function test_cancelling_an_unanswered_job_cleans_up_and_no_driver_follows_a_question(): void {
		$seen = array();
		$runs = 0;
		$this->types->add( new FixtureJobType( 'ask', array( $this->asking_step( $seen, $runs ) ) ) );
		$hops    = 0;
		$actions = new JobActions( $this->repo, $this->runner(), new Loopback( true, function () use ( &$hops ): void {
			++$hops;
		} ) );
		$job     = $this->repo->create( 'ask' );
		$result  = $actions->tick( $job->id, $this->now );
		$this->assertSame( TickResult::PAUSED, $result->status );
		$this->assertSame( 0, $hops, 'no self-request for a question' );
		$this->assertFalse( wp_next_scheduled( 'wpcheckpoint_job_tick', array( $job->id ) ), 'no cron event either' );
		$work = Residue::work_dir( $this->base, $job->id );
		mkdir( $work );
		$outcome = $actions->cancel( $job->id );
		$this->assertSame( 'cleaned', $outcome['reason'] );
		$this->assertSame( Job::CANCELLED, $this->repo->find( $job->id )->status );
		$this->assertDirectoryDoesNotExist( $work );
	}
}
