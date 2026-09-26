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
 * created, written, moved into place, read back, or it read back
 * otherwise than written. The temporary file, and a final file that read
 * back otherwise, are removed (attempted: a removal can fail too). The
 * message names the stage, never a path.
 */
final class AtomicWriteFailed extends \RuntimeException {
}
