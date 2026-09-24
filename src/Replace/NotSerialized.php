<?php
/**
 * Raised inside Serialized when the input does not follow the format.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Replace;

defined( 'ABSPATH' ) || exit;

/**
 * Internal to Serialized::rewrite(), which turns it into a null result.
 */
final class NotSerialized extends \Exception {
}
