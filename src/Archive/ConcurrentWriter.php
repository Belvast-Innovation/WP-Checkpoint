<?php
/**
 * Another process writes the same volume.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Thrown by Packer when the open volume's length is not what this process
 * wrote: another process (a run that outlived its lease) is writing the
 * same work directory. The run stops without touching the volume.
 */
final class ConcurrentWriter extends \RuntimeException {
}
