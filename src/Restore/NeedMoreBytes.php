<?php
/**
 * Raised by SqlLexer when a statement goes on past the end of the buffer.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Not an error: the reader reads more of the chunk and starts the statement
 * again. At the end of the file it becomes a Refused (the chunk ends inside
 * a statement).
 */
final class NeedMoreBytes extends \RuntimeException {
}
