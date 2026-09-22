<?php
/**
 * A source file that is missing or unreadable when the packer needs it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Thrown by the packer when a source file is not there or cannot be
 * opened: distinct from SourceChanged (the file is there but different),
 * so a step can skip the file instead of starting it over.
 */
final class SourceGone extends \RuntimeException {
}
