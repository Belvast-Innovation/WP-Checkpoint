<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\HoldingStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * A job whose row holds a JSON column that cannot be read (its cursor, options or questions: NULL where a value is
 * written, cut short, not JSON) stops before any step runs, says so, and is left as it is: the column is not read as
 * empty. A step that ends on an empty cursor (as the swap does on a job that holds the site swapped) would otherwise
 * complete the job, and an export would start over. A cursor that is empty, and options or questions that are NULL,
 * are read as they are (the controls).
 */
final class UnreadableJobRowTest extends JobTestCase {

	const TYPE = 'unreadable_row';

	/** @var int Units the step ran. */
	private $runs = 0;

	public function set_up(): void {
		parent::set_up();
		$this->runs = 0;
		// Ends on an empty cursor, like the swap on a job that holds the site swapped (its step is over).
		$this->register(
			self::TYPE,
			array(
				new HoldingStep(
					'unreadable_step',
					function ( JobContext $context ): StepResult {
						++$this->runs;
						return array() === $context->cursor() ? StepResult::done( 'ended' ) : StepResult::progress( array( 'n' => 2 ), 50, 'on' );
					}
				),
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

	private function tick( int $id ): \WPCheckpoint\Jobs\TickResult {
		return $this->autocommit(
			static function () use ( $id ) {
				return Plugin::instance()->runner()->tick( $id );
			}
		);
	}

	private function column( int $id, string $column ) {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return $wpdb->get_var( $wpdb->prepare( "SELECT {$column} FROM " . JobRepository::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- a fixed column name, the jobs table.
	}

	public function test_a_job_whose_cursor_cannot_be_read_stops_before_any_step_runs_and_its_row_is_left_as_it_is(): void {
		$unreadable = array();
		foreach ( array(
			'NULL'         => null,
			'empty'        => '',
			'cut short'    => '{"phase":"committed","mark":"WP Check',
			'not JSON'     => 'phase=committed',
			'a string'     => '"committed"',
			'JSON null'    => 'null',
			'a number'     => '7',
		) as $what => $value ) {
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
			$this->assertSame( Job::FAILED, $job->status, $what . ': ' . $result->status . ' ' . $result->message );
			$this->assertStringContainsString( 'its position (cursor) cannot be read', (string) $job->last_error, $what );
			$this->assertSame( Job::SITE_SWAPPED, $job->site_state, $what . ': still recorded as holding the site' );
			$this->assertTrue( Plugin::instance()->jobs()->holds_site( $job ), $what . ': and it holds it' );
			$this->assertSame( $value, $this->column( $id, 'cursor_json' ), $what . ': the column left as it was' );
			$this->assertSame( array( 'cursor_json' ), $job->unreadable_columns, $what );
			$unreadable[ $what ] = $id;
		}
		// What WP-CLI says first: no command to finish a job that is not run again. The control: a failed job that
		// holds the site and whose cursor is read is told to be retried, in the words looked for here.
		$readable = $this->job_with(
			array(
				'cursor_json' => '{"n":1}',
				'site_state'  => Job::SITE_SWAPPED,
				'status'      => Job::FAILED,
			)
		);
		$warnings = implode( "\n", Plugin::instance()->half_swapped_warnings() );
		$this->assertStringContainsString( 'wp wpcheckpoint job retry ' . $readable . ',', $warnings, 'the control' );
		foreach ( $unreadable as $what => $id ) {
			$this->assertStringContainsString( 'Restore job ' . $id . ' holds the site changed, and its row cannot be read', $warnings, $what );
			$this->assertStringNotContainsString( 'wp wpcheckpoint job retry ' . $id . ',', $warnings, $what );
		}
		// The controls: an empty cursor is read as empty (the step ends, the job completes), a position as a position.
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
		$this->runs = 0;
		$id         = $this->job_with( array( 'cursor_json' => '{"n":1}' ) );
		$this->tick( $id );
		$this->assertSame( 1, $this->runs, 'a position: the step ran' );
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
			try {
				Plugin::instance()->job_actions()->retry( $id );
				$this->fail( $what . ': retried' );
			} catch ( \Throwable $e ) {
				$this->assertStringContainsString( 'cannot be read', $e->getMessage(), $what );
			}
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
		$this->assertSame( 1, $this->runs, 'NULL options and questions: the step ran' );
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
}
