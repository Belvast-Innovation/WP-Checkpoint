<?php
/**
 * Raised by Serialized when arrays and objects nest deeper than it reads.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Replace;

defined( 'ABSPATH' ) || exit;

/**
 * Not damage: unserialize() reads deeper values. The engine leaves such a
 * value unchanged and counts it apart.
 */
final class TooDeep extends NotSerialized {
}
