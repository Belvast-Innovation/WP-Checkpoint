<?php
/**
 * A step that stops because of how the user answered one of its questions.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a step that ends the job because a question was answered with
 * a choice that stops it ("stop"). The job fails as for any other failure;
 * in the same write the Runner removes that answer from the job's options
 * (JobRepository::transition()), so a retry asks the question again rather
 * than stopping for the same reason: the retry is the user changing their
 * mind, or retrying after fixing what the question was about. The other
 * answers ("continue", "exclude") stay: they are still the user's choices.
 * A policy that stops (an unattended run's "fail") is not an answer and
 * stays too: a retry under it stops again.
 */
final class StoppedByAnswer extends \RuntimeException {

	/**
	 * Id of the question whose answer stopped the job.
	 *
	 * @var string
	 */
	private $question;

	/**
	 * Constructor.
	 *
	 * @param string $message  Why the job stopped.
	 * @param string $question Id of the question whose answer stopped it.
	 */
	public function __construct( string $message, string $question ) {
		parent::__construct( $message );
		$this->question = $question;
	}

	/**
	 * Id of the question whose answer stopped the job.
	 *
	 * @return string
	 */
	public function question(): string {
		return $this->question;
	}
}
