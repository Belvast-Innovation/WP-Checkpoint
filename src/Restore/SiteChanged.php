<?php
/**
 * Raised when the swap's plan was written for another site than this one.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * The plan's site entry (SwapPlan::SITE) names another WordPress directory or table prefix than this site's now:
 * the plan is evidence of what the swap was checked for, so it is not written again. Or an entry of the content
 * directory is not where the files preflight saw it (OtherEntries): its record is what the files were staged by. The
 * swap is refused before it changes anything; a final failure (retrying would refuse the same way): the restore is
 * started again.
 */
final class SiteChanged extends \RuntimeException {
}
