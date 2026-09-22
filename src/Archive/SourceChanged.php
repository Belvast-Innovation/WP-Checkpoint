<?php
/**
 * A source file that no longer matches the entry being written.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Thrown by Packer when the file behind the open entry turns out to be
 * shorter than the size the entry declared (it was truncated or replaced
 * while it was being read). The caller decides: the pack step drops the
 * entry and starts it over from a fresh stat, a bounded number of times.
 */
final class SourceChanged extends \RuntimeException {
}
