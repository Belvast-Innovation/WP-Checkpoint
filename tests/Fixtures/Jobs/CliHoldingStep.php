<?php

namespace WPCheckpoint\Tests\Fixtures\Jobs;

use WPCheckpoint\Jobs\CliOnly;

/**
 * A HoldingStep only WP-CLI runs (tests only).
 */
final class CliHoldingStep extends HoldingStep implements CliOnly {
}
