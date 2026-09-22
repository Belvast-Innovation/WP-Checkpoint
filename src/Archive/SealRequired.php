<?php
/**
 * The open volume cannot take the next entry.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Archive;

/**
 * Thrown by Packer::add_entry() and Packer::finish() when the open volume
 * has no room for the entry: the caller checkpoints, seals the volume
 * (Packer::seal_volume()), checkpoints again and opens the next one
 * (Packer::open_volume()). The packer never seals or creates a volume as
 * a side effect of adding an entry: sealing and creating are irreversible
 * transitions with a replay rule of their own, and each must be a unit
 * the caller checkpoints on both sides.
 */
final class SealRequired extends \RuntimeException {
}
