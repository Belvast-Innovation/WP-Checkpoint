<?php
/**
 * Thrown when a job is asked to move to a status it cannot reach.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * A programming error, never user input: the caller asked for a transition
 * the state machine does not allow.
 */
final class InvalidTransition extends \LogicException {
}
