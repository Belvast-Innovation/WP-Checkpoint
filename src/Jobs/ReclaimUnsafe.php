<?php
/**
 * Thrown when the reaper cannot tell leftovers from live work.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * A job lookup failed during reclamation. Nothing may be deleted on the
 * strength of an answer that was never given.
 */
final class ReclaimUnsafe extends \RuntimeException {
}
