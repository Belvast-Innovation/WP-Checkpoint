<?php
/**
 * The swap found the backup's tables judged otherwise than at the preflight, after it put up the maintenance file
 * and before it renamed anything.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown and caught inside SwapStep only, at the one point where the site's only change is the maintenance file:
 * the swap takes that file down, records the site as untouched and starts the restore over at the preflight
 * (never the rollback, which is for a site that may be half swapped).
 */
final class IncomingChanged extends \RuntimeException {
}
