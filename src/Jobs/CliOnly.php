<?php
/**
 * A step that runs only in a WP-CLI process.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A step whose work no web request may carry: it cannot be cut into units
 * a web server's time limit would respect, or it changes the site under the
 * requests that would drive it. While a job stands at such a step, a tick
 * from any other driver (a page, the loopback request, WP-Cron) returns
 * TickResult::CLI before it looks at the storage gate or takes the lock:
 * the job row is not written, nothing is counted and no driver is
 * scheduled. A tick that finishes the step before it stops there too.
 */
interface CliOnly {
}
