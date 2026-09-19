<?php
/**
 * Thrown when the runtime leaves no room for a job step at all.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * The memory or time limit of the runtime is below what one step needs.
 * Not a TransientFailure: a limit does not change between retries. The
 * runner fails the job with this message so the user reads the real
 * cause instead of "could not make progress" after three empty ticks.
 */
final class BudgetExhausted extends \RuntimeException {
}
