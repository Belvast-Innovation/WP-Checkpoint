<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\CliOnly;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\HoldingStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * A job whose row holds a JSON column that cannot be read (its cursor, options or questions: NULL where a value is
 * written, cut short, not JSON) is not run, says so, and its row is left as it is: the column is not read as empty.
 * A step that ends on an empty cursor (as the swap does on a job that holds the site swapped) would otherwise
 * complete the job, and an export would start over. A job that holds the site is not failed (a failed job no longer
 * puts the site back) and goes on once its row is repaired; any other is failed for good. A cursor that is empty, and
 * options or questions that are NULL, are read as they are (the controls).
 */
final class UnreadableJobRowTest extends JobTestCase {

	const TYPE = 'unreadable_row';

	/** A type whose one step only WP-CLI runs. */
	const CLI_TYPE = 'unreadable_cli';

	/** Cursors that cannot be read, by what they are. */
	const CURSORS = array(
		'NULL'      => null,
		'empty'     => '',
		'cut short' => '{"phase":"committed","mark":"WP Check',
		'not JSON'  => 'phase=committed',
		'a string'  => '"committed"',
		'JSON null' => 'null',
		'a number'  => '7',
	);

	/** @var int Units the step ran. */
	private $runs = 0;

	/** @var int Times the step's cleanup ran. */
	private $cleanups = 0;

	public function set_up(): void {
		parent::set_up();
		$this->runs     = 0;
		$this->cleanups = 0;
		// Ends on an empty cursor, like the swap on a job that holds the site swapped (its step is over).
		$this->register(
			self::TYPE,
			array(
				new HoldingStep(
					'unreadable_step',
					function ( JobContext $context ): StepResult {
						++$this->runs;
						return array() === $context->cursor() ? StepResult::done( 'ended' ) : StepResult::progress( array( 'n' => 2 ), 50, 'on' );
					},
					function (): void {
						++$this->cleanups;
					}
				),
			)
		);
		$this->register(
			self::CLI_TYPE,
			array(
				new class( 'unreadable_cli_step', function (): StepResult {
					++$this->runs;
					return StepResult::done( 'ended' );
				} ) extends HoldingStep implements CliOnly {},
			)
		);
	}

	/**
	 * A job of the type, its row then written with these columns (committed: the Runner reads the row).
	 *
	 * @param array<string, mixed> $columns Columns.
	 * @return int Job id.
	 */
	private function job_with( array $columns ): int {
		global $wpdb;
		$id = $this->job_of( self::TYPE, false );
		$wpdb->update( JobRepository::table(), $columns, array( 'id' => $id ) );
		$wpdb->query( 'COMMIT' );
		return $id;
	}

	private function tick( int $id ): TickResult {
		return $this->autocommit(
			static function () use ( $id ) {
				return Plugin::instance()->runner()->tick( $id );
			}
		);
	}

	private function column( int $id, string $column ) {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		// get_row(), not get_var(): get_var() gives NULL for an empty string, and the two are told apart here.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$column} FROM " . JobRepository::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- a fixed column name, the jobs table.
		return $row[ $column ];
	}

	private static function retry_refusal( int $id ): string {
		try {
			Plugin::instance()->job_actions()->retry( $id );
		} catch ( \Throwable $e ) {
			return $e->getMessage();
		}
		return '';
	}

	public function test_a_job_that_holds_the_site_and_whose_cursor_cannot_be_read_is_not_run_nor_failed_and_goes_on_once_repaired(): void {
		$unreadable = array();
		foreach ( self::CURSORS as $what => $value ) {
			$this->runs = 0;
			$id         = $this->job_with(
				array(
					'cursor_json' => $value,
					'site_state'  => Job::SITE_SWAPPED,
				)
			);
			$result = $this->tick( $id );
			$job    = Plugin::instance()->jobs()->find( $id );
			$this->assertSame( 0, $this->runs, $what . ': no step ran' );
			$this->assertSame( TickResult::BLOCKED, $result->status, $what . ': ' . $result->message );
			$this->assertStringContainsString( 'its position (cursor) cannot be read', $result->message, $what );
			$this->assertStringContainsString( 'it is not failed: it is not run until its row is repaired', $result->message, $what );
			$this->assertSame( Job::QUEUED, $job->status, $what . ': not failed' );
			$this->assertSame( '', (string) $job->last_error, $what );
			$this->assertSame( '', $job->lock_token, $what . ': no lock left' );
			$this->assertSame( Job::SITE_SWAPPED, $job->site_state, $what . ': still recorded as holding the site' );
			$this->assertTrue( Plugin::instance()->jobs()->holds_site( $job ), $what . ': and it holds it' );
			$this->assertSame( $value, $this->column( $id, 'cursor_json' ), $what . ': the column left as it was' );
			$this->assertSame( array( 'cursor_json' ), $job->unreadable_columns, $what );
			$this->assertStringContainsString( 'cannot be read', Plugin::instance()->job_actions()->release( $id, '' )['message'], $what . ': no command to run it' );
			$unreadable[ $what ] = $id;
		}
		// The control: a job that holds the site and whose cursor is read is told to be run, and a failed one to be
		// retried, in the words looked for here.
		$readable = $this->job_with(
			array(
				'cursor_json' => '{"n":1}',
				'site_state'  => Job::SITE_SWAPPED,
			)
		);
		$this->assertStringContainsString( 'run it with wp wpcheckpoint job run', Plugin::instance()->job_actions()->release( $readable, '' )['message'], 'the control' );
		$failed   = $this->job_with(
			array(
				'cursor_json' => '{"n":1}',
				'site_state'  => Job::SITE_SWAPPED,
				'status'      => Job::FAILED,
			)
		);
		$warnings = implode( "\n", Plugin::instance()->half_swapped_warnings() );
		$this->assertStringContainsString( 'wp wpcheckpoint job retry ' . $failed . ',', $warnings, 'the control' );
		$this->assertStringContainsString( 'Restore job ' . $readable . ' ', $warnings, 'the control' );
		$this->assertStringNotContainsString( 'Restore job ' . $readable . ' holds the site changed, and its row cannot be read', $warnings, 'the control' );
		foreach ( $unreadable as $what => $id ) {
			$this->assertStringContainsString( 'Restore job ' . $id . ' holds the site changed, and its row cannot be read', $warnings, $what );
			$this->assertStringNotContainsString( 'wp wpcheckpoint job run ' . $id . "\n", $warnings . "\n", $what );
		}
		// Repaired: it goes on where its cursor says, without a retry.
		global $wpdb;
		$this->runs = 0;
		$wpdb->update( JobRepository::table(), array( 'cursor_json' => '{"n":1}' ), array( 'id' => $unreadable['cut short'] ) );
		$wpdb->query( 'COMMIT' );
		$this->tick( $unreadable['cut short'] );
		$this->assertGreaterThan( 0, $this->runs, 'repaired: the step ran' );
		// The controls: an empty cursor is read as empty (the step ends, the job completes).
		$this->runs = 0;
		$id         = $this->job_with(
			array(
				'cursor_json' => '[]',
				'site_state'  => Job::SITE_SWAPPED,
			)
		);
		$this->tick( $id );
		$this->assertSame( 1, $this->runs, 'an empty cursor: the step ran' );
		$this->assertSame( Job::COMPLETED, Plugin::instance()->jobs()->find( $id )->status );
	}

	public function test_a_job_that_holds_the_site_and_waits_for_an_answer_or_for_wp_cli_says_so_as_before(): void {
		global $wpdb;
		foreach ( array( 'readable' => '{"n":1}', 'unreadable' => '{"n":' ) as $what => $cursor ) {
			$this->runs = 0;
			$id         = $this->job_with(
				array(
					'cursor_json'    => $cursor,
					'site_state'     => Job::SITE_SWAPPED,
					'status'         => Job::PAUSED,
					'questions_json' => '[{"id":"q","kind":"k","count":1,"choices":["a"]}]',
				)
			);
			$result = $this->tick( $id );
			$this->assertSame( TickResult::PAUSED, $result->status, $what . ': ' . $result->message );
			$this->assertSame( 0, $this->runs, $what );
			// At a step only WP-CLI runs, ticked from elsewhere (the Runner of the tests is not WP-CLI's).
			$id = Plugin::instance()->jobs()->create( self::CLI_TYPE, self::$admin_id )->id;
			$wpdb->update(
				JobRepository::table(),
				array(
					'cursor_json' => $cursor,
					'site_state'  => Job::SITE_SWAPPED,
				),
				array( 'id' => $id )
			);
			$wpdb->query( 'COMMIT' );
			$result = $this->tick( $id );
			$this->assertSame( TickResult::CLI, $result->status, $what . ': ' . $result->message );
			$this->assertSame( 0, $this->runs, $what );
		}
	}

	public function test_any_other_job_whose_cursor_cannot_be_read_is_failed_for_good_before_any_step_runs(): void {
		foreach ( self::CURSORS as $what => $value ) {
			$this->runs = 0;
			$id         = $this->job_with( array( 'cursor_json' => $value ) );
			$result     = $this->tick( $id );
			$job        = Plugin::instance()->jobs()->find( $id );
			$this->assertSame( 0, $this->runs, $what . ': no step ran' );
			$this->assertSame( Job::FAILED, $job->status, $what . ': ' . $result->status . ' ' . $result->message );
			$this->assertStringContainsString( 'its position (cursor) cannot be read', (string) $job->last_error, $what );
			$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind, $what );
			$this->assertSame( $value, $this->column( $id, 'cursor_json' ), $what . ': the column left as it was' );
			$this->assertStringContainsString( 'cannot be read', self::retry_refusal( $id ), $what );
			$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status, $what );
			$this->assertFalse( Plugin::instance()->job_presenter()->present( Plugin::instance()->jobs()->find( $id ), false )['retryable'], $what . ': no Retry offered' );
		}
		// The control: a position is read as a position.
		$this->runs = 0;
		$id         = $this->job_with( array( 'cursor_json' => '{"n":1}' ) );
		$this->tick( $id );
		$this->assertGreaterThan( 0, $this->runs, 'a position: the step ran' );
	}

	public function test_options_or_questions_that_cannot_be_read_stop_the_job_and_are_not_written_over(): void {
		foreach ( array(
			'options'   => array( 'options_json', '{"policy":{"unverified":"ke' ),
			'questions' => array( 'questions_json', '[{"id":"unverified","kind":"restored_site_unch' ),
		) as $what => list( $column, $value ) ) {
			$this->runs = 0;
			$columns    = array( $column => $value );
			if ( 'questions' === $what ) {
				$columns['status'] = Job::PAUSED; // A job that waits for an answer, its question not readable.
			}
			$id     = $this->job_with( $columns );
			$result = $this->tick( $id );
			$job    = Plugin::instance()->jobs()->find( $id );
			$this->assertSame( 0, $this->runs, $what . ': no step ran' );
			$this->assertSame( Job::FAILED, $job->status, $what . ': ' . $result->status . ' ' . $result->message );
			$this->assertStringContainsString( 'cannot be read', (string) $job->last_error, $what );
			$this->assertSame( $value, $this->column( $id, $column ), $what . ': the column left as it was' );
			$this->assertStringContainsString( 'cannot be read', self::retry_refusal( $id ), $what );
			$this->assertSame( $value, $this->column( $id, $column ), $what . ': not written over by the retry' );
			$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $id )->status );
		}
		// The control: NULL options and questions are a job's own (none given, none asked), and it runs.
		$this->runs = 0;
		$id         = $this->job_with(
			array(
				'options_json'   => null,
				'questions_json' => null,
			)
		);
		$this->tick( $id );
		$this->assertGreaterThan( 0, $this->runs, 'NULL options and questions: the step ran' );
		$this->assertSame( array(), Plugin::instance()->jobs()->find( $id )->unreadable_columns );
	}

	public function test_a_job_whose_options_cannot_be_read_is_not_answered(): void {
		$question = '[{"id":"q","kind":"k","count":1,"choices":["a","b"]}]';
		foreach ( array(
			'options cut short' => '{"policy":{"unverified":"ke',
			'options readable'  => '{"policy":{"unverified":"keep"}}',
		) as $what => $options ) {
			$id  = $this->job_with(
				array(
					'status'         => Job::PAUSED,
					'options_json'   => $options,
					'questions_json' => $question,
				)
			);
			$job = Plugin::instance()->jobs()->find( $id );
			try {
				Plugin::instance()->jobs()->answer( $job, array( 'q' => 'a' ) );
				$answered = '';
			} catch ( \Throwable $e ) {
				$answered = $e->getMessage();
			}
			if ( 'options readable' === $what ) {
				// The control: the same answer to the same question is taken, and written into the options.
				$this->assertSame( '', $answered );
				$this->assertStringContainsString( '"answers":{"q":"a"}', (string) $this->column( $id, 'options_json' ) );
				continue;
			}
			$this->assertStringContainsString( 'cannot be read', $answered, $what );
			$this->assertSame( $options, $this->column( $id, 'options_json' ), $what . ': not written over' );
			$this->assertSame( $question, $this->column( $id, 'questions_json' ), $what . ': still asked' );
		}
	}

	public function test_a_row_that_becomes_unreadable_before_the_lock_reads_it_is_not_run(): void {
		global $wpdb;
		foreach ( array(
			'cut short'          => '{"n":',
			'cut short, lost'    => '{"n":',
			'readable'           => '{"n":5}',
		) as $what => $written ) {
			$this->runs = 0;
			$id         = $this->job_with( array( 'cursor_json' => '{"n":1}' ) );
			$done       = false;
			$lose       = 'cut short, lost' === $what;
			$lost       = false;
			$table      = JobRepository::table();
			// Written right before the statement that takes the lock (and reads the row again): after the first read.
			// "Lost": the statement that gives the lock back then changes nothing (another process took it meanwhile).
			$hook = static function ( $query ) use ( &$done, &$lost, $lose, $wpdb, $table, $id, $written ) {
				if ( ! $done && false !== strpos( $query, 'SET takeovers = IF(' ) ) {
					$done = true;
					$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET cursor_json = %s WHERE id = %d", $written, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the jobs table.
				}
				if ( $lose && $done && ! $lost && 0 === strpos( $query, "UPDATE {$table} SET lock_token = '', locked_until = 0, updated_at" ) ) {
					$lost = true;
					return $query . ' AND 1 = 0';
				}
				return $query;
			};
			add_filter( 'query', $hook );
			try {
				$result = $this->tick( $id );
			} finally {
				remove_filter( 'query', $hook );
			}
			$this->assertTrue( $done, $what . ': the control: written between the reads' );
			$job = Plugin::instance()->jobs()->find( $id );
			if ( 'readable' === $what ) {
				$this->assertGreaterThan( 0, $this->runs, 'the control: a readable row is run' );
				continue;
			}
			if ( $lose ) {
				$this->assertTrue( $lost, 'the control: the lock was not given back' );
				$this->assertSame( TickResult::LOST, $result->status, $what . ': said, not thrown' );
				$this->assertSame( 0, $this->runs, $what . ': no step ran' );
				continue;
			}
			$this->assertSame( 0, $this->runs, $what . ': no step ran' );
			$this->assertSame( Job::FAILED, $job->status, $what );
			$this->assertSame( '', $job->lock_token, $what . ': the lock let go' );
			$this->assertSame( $written, $this->column( $id, 'cursor_json' ), $what . ': the column left as it was' );
		}
	}

	public function test_the_reaper_gives_up_an_unanswered_job_whose_questions_cannot_be_read_and_says_why_it_gives_up_a_stalled_one(): void {
		global $wpdb;
		$old = time() - JobRepository::WORK_RETENTION_SECONDS - 60;
		$ids = array();
		foreach ( array(
			'unreadable' => '[{"id":"unverified","kind":"restored_site_unch',
			'readable'   => '[{"id":"q","kind":"k","count":1,"choices":["a"]}]',
		) as $what => $questions ) {
			$ids[ $what ] = $this->job_with(
				array(
					'status'         => Job::PAUSED,
					'questions_json' => $questions,
					'updated_at'     => $old,
				)
			);
		}
		$stalled = $this->job_with(
			array(
				'cursor_json' => '{"n":',
				'created_at'  => $old,
				'progress_at' => $old,
			)
		);
		$this->autocommit(
			static function () {
				Plugin::instance()->jobs()->reap();
			}
		);
		$wpdb->query( 'COMMIT' );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $ids['readable'] )->status, 'the control: an unanswered job is given up' );
		$job = Plugin::instance()->jobs()->find( $ids['unreadable'] );
		$this->assertSame( Job::FAILED, $job->status, 'its questions cannot be read: still unanswered' );
		$this->assertStringContainsString( 'No answer within 7 days', (string) $job->last_error );
		$job = Plugin::instance()->jobs()->find( $stalled );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'its position (cursor) cannot be read', (string) $job->last_error, 'said as what kept it from running' );
		$this->assertSame( '{"n":', $this->column( $stalled, 'cursor_json' ) );
	}

	public function test_a_cancel_does_not_run_the_steps_cleanup_on_a_cursor_that_cannot_be_read(): void {
		foreach ( array(
			'readable'   => '{"n":1}',
			'unreadable' => '{"n":',
		) as $what => $cursor ) {
			$this->cleanups = 0;
			$id             = $this->job_with(
				array(
					'cursor_json' => $cursor,
					'step'        => 'unreadable_step',
				)
			);
			$this->autocommit(
				static function () use ( $id ) {
					Plugin::instance()->job_actions()->cancel( $id );
				}
			);
			$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $id )->status, $what );
			$this->assertSame( 'readable' === $what ? 1 : 0, $this->cleanups, $what . ': the step\'s cleanup' );
			$this->assertSame( $cursor, $this->column( $id, 'cursor_json' ), $what );
		}
	}
}
