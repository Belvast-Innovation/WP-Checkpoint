<?php
/**
 * Outcome of one Step::run() call.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * Four outcomes: progress (cursor advanced, call again), done (next step),
 * wait (nothing can be done right now, try again after some seconds), ask
 * (a decision is needed; the job pauses until it is answered).
 */
final class StepResult {

	const PROGRESS = 'progress';
	const DONE     = 'done';
	const WAIT     = 'wait';
	const ASK      = 'ask';

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
	 * Questions are shown to the user, so they hold what the user needs to
	 * decide (kinds, counts, names) and nothing else: no credentials (the
	 * secret check runs on them) and no row values; details belong in a
	 * file under the work directory.
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
}
