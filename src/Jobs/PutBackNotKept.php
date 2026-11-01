<?php
/**
 * Thrown when a put-back's question cannot be answered "keep".
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * An answer to keep the restored site at a put-back's question that could not put the job back as it was before the
 * put-back (JobRepository::give_up_put_back()): another installation manages the job, what the claim wrote over cannot
 * be read back whole, or the database refused the statement. Nothing was changed; the question stands.
 */
final class PutBackNotKept extends \RuntimeException {
}
