<?php
/**
 * A step that stops the job because of a choice: the user's answer, or a policy.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a step that ends the job because questions were answered with
 * a choice that stops it ("stop"), or a policy decided so (an unattended
 * run's "fail"). The job fails as for any other failure; in the same write
 * the Runner removes the answers to the questions named here from the
 * job's options (JobRepository::transition()), so a retry asks those
 * questions again rather than stopping for the same answers. The step
 * names every question answered "stop" at once, so one retry asks all of
 * them. The other answers ("continue", "exclude") stay: they are still the
 * user's choices. A policy is not an answer and stays: a retry under it
 * stops again. What a retry asks about is whatever the step finds then:
 * the export's review asks from what its pre-flight recorded, the
 * restore's free space check measures again.
 */
final class Stopped extends \RuntimeException {

	/**
	 * Ids of the questions whose answers stopped the job.
	 *
	 * @var string[]
	 */
	private $questions;

	/**
	 * Constructor.
	 *
	 * @param string   $message   Why the job stopped.
	 * @param string[] $questions Ids of the questions whose answers (or policy) stopped it.
	 */
	public function __construct( string $message, array $questions ) {
		parent::__construct( $message );
		$this->questions = array_values( array_map( 'strval', $questions ) );
	}

	/**
	 * Ids of the questions whose answers stopped the job.
	 *
	 * @return string[]
	 */
	public function questions(): array {
		return $this->questions;
	}
}
