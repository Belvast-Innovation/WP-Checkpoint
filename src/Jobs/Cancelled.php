<?php
/**
 * A step's end of a job whose cancel was requested.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown by a step that found a cancel request (Job::$cancel_requested) and
 * has undone what it did to the site: the Runner cancels the job and runs
 * the cleanup, as for a cancel taken with the lock. The step's last cursor
 * must say that the site is as it was (HoldsSite::site_state() of it is
 * Job::SITE_UNTOUCHED): the row refuses to cancel a job that holds the site
 * changed, and the run then ends as one that lost its lock.
 */
class Cancelled extends \RuntimeException {
}
