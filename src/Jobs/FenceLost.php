<?php
/**
 * Thrown while an uninstall deletes, when the fence it closed is no longer its own.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * The uninstall's heartbeat (UninstallFence::beat()) found the fence opened (healed as stale, or by hand) or closed
 * by another uninstall since: a swap may enter the site meanwhile, so the uninstall stops before its next deletion.
 */
final class FenceLost extends \RuntimeException {
}
