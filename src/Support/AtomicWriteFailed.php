<?php
/**
 * Thrown when an atomic write could not be completed.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

defined( 'ABSPATH' ) || exit;

/**
 * AtomicFile::write() did not leave the file in place: it could not be
 * created, written, moved into place or read back as written. Nothing of
 * the attempt is left (the temporary file and a wrong final file are
 * removed). The message names the stage, never a path.
 */
final class AtomicWriteFailed extends \RuntimeException {
}
