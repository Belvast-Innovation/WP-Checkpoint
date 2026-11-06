<?php
/**
 * A statement template in wpdb::prepare()'s form, turned into one for a connection that binds "?".
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal messages about this code's own templates.

/**
 * JobStore writes each statement once, as a template for wpdb::prepare() (WpdbExecutor runs it as it is). On a
 * connection made without WordPress (SessionExecutor, through Restore\ImportSession, which binds each "?" as a quoted
 * string) the same template runs after convert(), with wpdb::prepare()'s meaning:
 *
 * - "'%s'" and "\"%s\"" lose their quotes first, as wpdb strips them;
 * - "%%" is a percent sign;
 * - "%d" is the value as an integer ((int), as vsprintf() reads it), written into the statement as digits, unquoted
 *   as wpdb writes it (a quoted number would not be one where the server wants a number, such as a LIMIT);
 * - "%s" is a "?", the value bound as a string.
 *
 * Anything else is refused, not passed on: "%f", "%F", "%i", a numbered or padded placeholder ("%1$s", "%5s"), a
 * percent sign that starts nothing ("%x", one at the end), a "?" in the template's text (the connection would take it
 * for a placeholder) and a count of values that differs from the placeholders'.
 */
final class Placeholders {

	/**
	 * The statement for a connection that binds "?", and the values it binds (the strings, in order).
	 *
	 * @param string            $template A template in wpdb::prepare()'s form.
	 * @param array<int, mixed> $params   One value per placeholder, in order.
	 * @return array{0: string, 1: string[]}
	 * @throws \InvalidArgumentException When the template holds a form refused here, or the counts differ.
	 */
	public static function convert( string $template, array $params ): array {
		if ( false !== strpos( $template, '?' ) ) {
			throw new \InvalidArgumentException( 'A template holds a "?" in its text.' );
		}
		$template = str_replace( array( "'%s'", '"%s"' ), '%s', $template );
		$params   = array_values( $params );
		$sql      = '';
		$bound    = array();
		$next     = 0;
		$length   = strlen( $template );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $template[ $i ];
			if ( '%' !== $char ) {
				$sql .= $char;
				continue;
			}
			$kind = $i + 1 < $length ? $template[ $i + 1 ] : '';
			if ( '%' === $kind ) {
				$sql .= '%';
				++$i;
				continue;
			}
			if ( 's' !== $kind && 'd' !== $kind ) {
				throw new \InvalidArgumentException( sprintf( 'A template holds a placeholder form refused here: "%%%s".', $kind ) );
			}
			if ( ! array_key_exists( $next, $params ) ) {
				throw new \InvalidArgumentException( 'A template holds more placeholders than values.' );
			}
			$value = $params[ $next ];
			++$next;
			++$i;
			if ( 'd' === $kind ) {
				$sql .= (string) self::integer( $value );
				continue;
			}
			if ( ! is_scalar( $value ) && null !== $value ) {
				throw new \InvalidArgumentException( 'A value for "%s" is not a scalar.' );
			}
			$sql    .= '?';
			$bound[] = (string) $value;
		}
		if ( count( $params ) !== $next ) {
			throw new \InvalidArgumentException( 'A template holds fewer placeholders than values.' );
		}
		return array( $sql, $bound );
	}

	/**
	 * A value as "%d" reads it.
	 *
	 * @param mixed $value Value.
	 * @return int
	 * @throws \InvalidArgumentException When it is not a scalar.
	 */
	private static function integer( $value ): int {
		if ( ! is_scalar( $value ) && null !== $value ) {
			throw new \InvalidArgumentException( 'A value for "%d" is not a scalar.' );
		}
		return (int) $value;
	}
}
