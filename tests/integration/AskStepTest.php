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
							'id'      => 'unreadable',
							'kind'    => 'unreadable',
							'count'   => 3,
							'file'    => 'review.json',
							'choices' => array( 'continue', 'fail' ),
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
		$this->assertSame( TickResult::PAUSED, $result->status, (string) $this->repo->find( $job->id )->last_error );
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
		$this->assertSame( 'review.json', $data['questions'][0]['file'], 'the details stay in the work directory; the question points at them' );
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
		foreach ( array(
			'a question that was not asked' => array( 'large_dirs' => 'include' ),
			'a choice that is not offered'  => array( 'unreadable' => 'maybe' ),
			'a nested value'                => array( 'unreadable' => array( 'continue' ) ),
			'an integer key'                => array( 0 => 'continue' ),
			'nothing'                       => array(),
		) as $case => $answers ) {
			try {
				$this->repo->answer( $paused, $answers );
				$this->fail( $case );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertTrue( $this->repo->find( $job->id )->awaiting_answer(), $case . ': the job still waits' );
			}
		}
		$this->repo->answer( $paused, array( 'unreadable' => 'fail' ) );
		// A second answer to the same pause: the job no longer waits.
		try {
			$this->repo->answer( $this->repo->find( $job->id ), array( 'unreadable' => 'continue' ) );
			$this->fail();
		} catch ( InvalidTransition $e ) {
			$this->assertTrue( true );
		}
		$this->assertSame( TickResult::COMPLETED, $this->runner()->tick( $job->id, $this->now )->status );
		$this->assertSame( array( 'fail' ), $seen );

		// An answer given at creation (an earlier decision) counts as given.
		$again = $this->repo->create( 'ask', 0, array(), array( 'answers' => array( 'unreadable' => 'continue', 'other' => 1 ) ) );
		$this->assertSame( TickResult::COMPLETED, $this->runner()->tick( $again->id, $this->now )->status );
	}

	public function test_questions_must_be_pointers_with_ids_and_the_caps_are_reachable(): void {
		$this->types->add( new FixtureJobType( 'leaky-shape', array( new ClosureStep( 'q', static function (): StepResult {
			return StepResult::ask( array(), array( array( 'id' => 'unreadable', 'paths' => array( 'wp-content/uploads/a.jpg' ) ) ), 'no' );
		} ) ) ) );
		$job    = $this->repo->create( 'leaky-shape' );
		$result = $this->runner()->tick( $job->id, $this->now );
		$this->assertSame( TickResult::FAILED, $result->status, 'a question carrying details instead of pointing at a file is refused' );
		$this->assertStringContainsString( 'details belong in a file', $this->repo->find( $job->id )->last_error );
		$this->assertSame( array(), $this->repo->find( $job->id )->questions, 'nothing was written' );

		foreach ( array(
			'no id'          => array( array( 'kind' => 'x' ) ),
			'bad id'         => array( array( 'id' => 'Has Spaces' ) ),
			'duplicate id'   => array( array( 'id' => 'a' ), array( 'id' => 'a' ) ),
			'negative count' => array( array( 'id' => 'a', 'count' => -1 ) ),
			'file with path' => array( array( 'id' => 'a', 'file' => '../review.json' ) ),
			'too many'       => array_fill( 0, JobRepository::MAX_QUESTIONS + 1, array( 'id' => 'a' ) ),
			'empty choices'  => array( array( 'id' => 'a', 'choices' => array() ) ),
			'not an array'   => array( 'a' ),
		) as $case => $questions ) {
			try {
				JobRepository::validate_questions( $questions );
				$this->fail( $case );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertTrue( true );
			}
		}

		// The largest legal payload: MAX_QUESTIONS questions with every field at its maximum fits the byte cap
		// with room, so the cap is a backstop that the shape rules keep from being reached by accident.
		$largest = array();
		for ( $i = 0; $i < JobRepository::MAX_QUESTIONS; $i++ ) {
			$largest[] = array(
				'id'      => str_pad( (string) $i, 64, 'x', STR_PAD_LEFT ),
				'kind'    => str_repeat( 'k', 64 ),
				'count'   => PHP_INT_MAX,
				'bytes'   => PHP_INT_MAX,
				'file'    => str_repeat( 'f', 128 ),
				'choices' => array_map( static function ( int $n ): string {
					return str_pad( (string) $n, 32, 'c', STR_PAD_LEFT );
				}, range( 1, JobRepository::MAX_CHOICES ) ),
			);
		}
		$validated = JobRepository::validate_questions( $largest );
		$size      = strlen( (string) wp_json_encode( $validated ) );
		$this->assertGreaterThan( JobRepository::MAX_QUESTIONS_BYTES / 2, $size, 'the cap is within reach of the largest legal payload' );
		$this->assertLessThanOrEqual( JobRepository::MAX_QUESTIONS_BYTES, $size );
		$this->types->add( new FixtureJobType( 'largest', array( new ClosureStep( 'q', static function ( JobContext $ctx ) use ( $largest ): StepResult {
			return empty( $ctx->options()['answers'] ) ? StepResult::ask( array(), $largest, 'big' ) : StepResult::done();
		} ) ) ) );
		$job = $this->repo->create( 'largest' );
		$this->assertSame( TickResult::PAUSED, $this->runner()->tick( $job->id, $this->now )->status );
		$this->assertCount( JobRepository::MAX_QUESTIONS, $this->repo->find( $job->id )->questions );
		$this->assertCount( JobRepository::MAX_QUESTIONS, $this->presenter()->present( $this->repo->find( $job->id ) )['questions'] );
		// And the answers cap: one answer to every question at the maximum length.
		$answers = array();
		foreach ( $largest as $question ) {
			$answers[ $question['id'] ] = $question['choices'][0];
		}
		$this->assertLessThanOrEqual( JobRepository::MAX_ANSWERS_BYTES, strlen( (string) wp_json_encode( $answers ) ) );
		$this->repo->answer( $this->repo->find( $job->id ), $answers );
		$this->assertSame( TickResult::COMPLETED, $this->runner()->tick( $job->id, $this->now )->status );
	}

	public function test_a_running_row_left_with_questions_is_healed_on_acquire_and_the_healing_is_logged(): void {
		global $wpdb;
		$seen = array();
		$runs = 0;
		$this->types->add( new FixtureJobType( 'ask', array( $this->asking_step( $seen, $runs ) ) ) );
		$job = $this->repo->create( 'ask' );
		$this->assertSame( TickResult::PAUSED, $this->runner()->tick( $job->id, $this->now )->status );
		// The 7-day rule fails the waiting job with its questions in place; a retry queues it again.
		$this->now += JobRepository::WORK_RETENTION_SECONDS + 1;
		$this->repo->reap();
		$failed = $this->repo->find( $job->id );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertNotSame( array(), $failed->questions, 'the failed row still carries the questions' );
		$wpdb->update( Schema::jobs_table(), array( 'work_expired_at' => 0 ), array( 'id' => $job->id ) ); // As if the retry had won the window.
		$this->repo->transition( $this->repo->find( $job->id ), Job::QUEUED );
		$result = $this->runner()->tick( $job->id, $this->now );
		$this->assertSame( TickResult::PAUSED, $result->status, 'the step ran again and asked again' );
		$this->assertSame( 2, $runs );
		$log = (string) file_get_contents( $this->base . '/' . $this->repo->find( $job->id )->log_path );
		$this->assertStringContainsString( 'Stale questions cleared', $log );
		$this->assertStringContainsString( 'stale questions cleared on acquire', (string) file_get_contents( $this->base . '/logs/storage.log' ) );

		// A running row with questions and an expired lease (the shape a crash between two writes would leave).
		$this->repo->answer( $this->repo->find( $job->id ), array( 'unreadable' => 'continue' ) );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING, 'questions_json' => '[{"id":"unreadable"}]', 'lock_token' => 'dead', 'locked_until' => (int) $this->now - 10 ), array( 'id' => $job->id ) );
		$this->assertSame( TickResult::COMPLETED, $this->runner()->tick( $job->id, $this->now )->status, 'acquired, healed, run to the end with the stored answer' );
	}

	public function test_a_late_answer_starts_a_fresh_stall_clock(): void {
		$calls = 0;
		$this->types->add( new FixtureJobType( 'late', array( new ClosureStep( 'q', static function ( JobContext $ctx ) use ( &$calls ): StepResult {
			++$calls;
			if ( empty( $ctx->options()['answers']['go'] ) ) {
				return StepResult::ask( array(), array( array( 'id' => 'go', 'choices' => array( 'yes' ) ) ), 'go?' );
			}
			return $calls < 4 ? StepResult::wait( 30, array(), 'remote busy' ) : StepResult::done();
		} ) ) ) );
		$job = $this->repo->create( 'late' );
		$this->assertSame( TickResult::PAUSED, $this->runner()->tick( $job->id, $this->now )->status );
		$this->now += 2 * 86400;
		$this->repo->answer( $this->repo->find( $job->id ), array( 'go' => 'yes' ) );
		$this->assertSame( (int) $this->now, $this->repo->find( $job->id )->progress_at, 'the decision is progress' );
		$this->assertSame( TickResult::WAITING, $this->runner()->tick( $job->id, $this->now )->status );
		$this->repo->reap();
		$this->assertSame( Job::RUNNING, $this->repo->find( $job->id )->status, 'a wait right after a late answer is not a stall' );
	}

	public function test_storage_refusals_do_not_refresh_the_clock_of_a_waiting_job(): void {
		$seen = array();
		$runs = 0;
		$this->types->add( new FixtureJobType( 'ask', array( $this->asking_step( $seen, $runs ) ) ) );
		$job = $this->repo->create( 'ask' );
		$this->assertSame( TickResult::PAUSED, $this->runner()->tick( $job->id, $this->now )->status );
		$updated = $this->repo->find( $job->id )->updated_at;
		// Another storage directory: the gate would refuse for storage, but the answer check comes first.
		mkdir( $this->root . '/elsewhere', 0755, true );
		$other = new JobRepository( new Directories( array( 'is_web_request' => false, 'document_root' => '', 'custom_dir' => $this->root . '/elsewhere' ) ), null, function (): int {
			return (int) floor( $this->now );
		} );
		$this->now += 3600;
		$gate = $other->gate( $this->repo->find( $job->id ) );
		$this->assertSame( 'awaiting_answer', $gate['reason'] );
		$result = ( new Runner( $other, $this->types, new Redactor(), array( 'clock' => function (): float {
			return $this->now;
		}, 'memory' => static function (): int {
			return 10 * 1048576;
		}, 'budget' => new Budget( 20, 32 * 1048576, false ), 'memory_limit' => -1 ) ) )->tick( $job->id, $this->now );
		$this->assertSame( TickResult::PAUSED, $result->status );
		$this->assertSame( $updated, $this->repo->find( $job->id )->updated_at, 'the retention clock did not move' );
		$this->assertSame( 0, $this->repo->find( $job->id )->blocked_count );
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
			return StepResult::ask( array(), array( array( 'id' => 'x', 'file' => $secret . '.json' ) ), 'leak' );
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
		$this->assertMatchesRegularExpression( '/credentials|plain file name/', $this->repo->find( $job->id )->last_error, 'refused by the secret check, or earlier by the shape check when the password has characters a file name cannot' );
		$this->assertStringNotContainsString( $secret, $this->repo->find( $job->id )->last_error );
		$this->assertSame( array(), $this->repo->find( $job->id )->questions );

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
