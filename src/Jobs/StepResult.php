<?php
/**
 * Outcome of one Step::run() call.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * Five outcomes: progress (cursor advanced, call again), done (next step),
 * wait (nothing can be done right now, try again after some seconds), ask
 * (a decision is needed; the job pauses until it is answered), and leave
 * (what is left can only be done in WP-CLI; only a run made without WordPress
 * gives it, see leave()).
 */
final class StepResult {

	const PROGRESS = 'progress';
	const DONE     = 'done';
	const WAIT     = 'wait';
	const ASK      = 'ask';
	const LEAVE    = 'leave';

	/**
	 * One of the constants.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Cursor to store (progress and wait).
	 *
	 * @var array<string, mixed>
	 */
	public $cursor;

	/**
	 * Progress within the step, 0-100.
	 *
	 * @var int
	 */
	public $percent;

	/**
	 * Short progress text.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Seconds to wait (wait only).
	 *
	 * @var int
	 */
	public $seconds;

	/**
	 * Questions for the user (ask only).
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $questions = array();

	/**
	 * Constructor.
	 *
	 * @param string               $kind    Kind.
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param int                  $percent Percent.
	 * @param string               $message Message.
	 * @param int                  $seconds Seconds.
	 */
	private function __construct( string $kind, array $cursor, int $percent, string $message, int $seconds ) {
		$this->kind    = $kind;
		$this->cursor  = $cursor;
		$this->percent = max( 0, min( 100, $percent ) );
		$this->message = $message;
		$this->seconds = $seconds;
	}

	/**
	 * Work was done and the cursor moved; call run() again.
	 *
	 * @param array<string, mixed> $cursor  New cursor.
	 * @param int                  $percent Progress within the step.
	 * @param string               $message Progress text.
	 * @return StepResult
	 */
	public static function progress( array $cursor, int $percent, string $message = '' ): StepResult {
		return new self( self::PROGRESS, $cursor, $percent, $message, 0 );
	}

	/**
	 * The step is complete.
	 *
	 * @param string $message Progress text.
	 * @return StepResult
	 */
	public static function done( string $message = '' ): StepResult {
		return new self( self::DONE, array(), 100, $message, 0 );
	}

	/**
	 * Nothing can be done right now (rate limit, a resource held elsewhere);
	 * not an error. The cursor is stored and the job is ticked again later.
	 *
	 * @param int                  $seconds Seconds to wait (the runner caps it).
	 * @param array<string, mixed> $cursor  Cursor to keep.
	 * @param string               $message Reason.
	 * @return StepResult
	 */
	public static function wait( int $seconds, array $cursor, string $message = '' ): StepResult {
		return new self( self::WAIT, $cursor, 0, $message, max( 1, $seconds ) );
	}

	/**
	 * A decision only the user can make. The cursor is stored, the
	 * questions are recorded on the job and the job is paused; nobody ticks
	 * it until the answers are stored (JobRepository::answer()), after
	 * which the same step runs again and reads them from
	 * JobContext::options()['answers']. The step must ask everything it
	 * needs in one go, and must not ask when the options already carry a
	 * policy or answers for it: an unattended driver never answers.
	 *
	 * A question is a pointer, not the details: an array with "id" (the
	 * answer is keyed by it) and optionally "kind", "count", "bytes",
	 * "file" (a file under the work directory holding the details: paths,
	 * table and column names) and "choices" (the answers allowed); any
	 * other field is refused (JobRepository::validate_questions()), so no
	 * user data or row value ever lands in the job row or the client
	 * payload, and the secret check runs on what is left.
	 *
	 * @param array<string, mixed>             $cursor    Cursor to keep.
	 * @param array<int, array<string, mixed>> $questions Questions, each with at least an "id".
	 * @param string                           $message   Progress text shown while paused.
	 * @return StepResult
	 */
	public static function ask( array $cursor, array $questions, string $message = '' ): StepResult {
		$result            = new self( self::ASK, $cursor, 0, $message, 0 );
		$result->questions = array_values( $questions );
		return $result;
	}

	/**
	 * What is left of the step can only be done in WP-CLI here (a run made without WordPress cannot flush the object
	 * cache, or cannot ask): the run stops, the job is let go and left as it is, for WP-CLI. Not progress and not a
	 * failure: no cursor is written (what the step checkpointed before stays), nothing is counted against the job
	 * (Runner::MAX_NO_PROGRESS), and no failure is written; the lease is released (TickResult::LEFT), and what the claim
	 * wrote stays (for a job that was queued, its status, attempts and start too). Only a run
	 * made without WordPress (the Runner's "web" option) gives it; in WP-CLI it is a step's error, and fails the job.
	 *
	 * @param string $message Why (English: such a run has no translations).
	 * @return StepResult
	 */
	public static function leave( string $message ): StepResult {
		return new self( self::LEAVE, array(), 0, $message, 0 );
	}
}
