<?php
/**
 * Thrown when a swap may not enter the site: the uninstall fence is closed or missing.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

/**
 * The job's write that would have recorded the site as being changed found the uninstall fence closed (an uninstall
 * of WP Checkpoint under way, on this site or one sharing its database) or not there (UninstallFence). Nothing was
 * written and nothing on the site was changed; the runner backs off and the swap tries to enter again.
 */
final class FenceClosed extends TransientFailure {
}
