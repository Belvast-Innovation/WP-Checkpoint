<?php
/**
 * A deletion the Deleter refused before touching anything.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Thrown by every entry point of Deleter when the path is not one the
 * plugin may delete (Deleter::refusal() says why). Nothing was deleted.
 */
final class DeletionRefused extends \RuntimeException {
}
