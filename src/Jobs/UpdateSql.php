<?php
/**
 * An UPDATE in the form wpdb::update() gives it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The template wpdb::update() prepares (wpdb::_update_replace_helper() and update()): "UPDATE `table` SET `a` = %s,
 * `b` = %d WHERE `c` = %d AND `d` = %s"; a null value is "`a` = NULL" in the SET and "`c` IS NULL" in the WHERE, with
 * no placeholder. SessionExecutor sends JobStore's updates in this form, so that both executors send the same
 * statement (WpdbExecutor lets wpdb::update() build it, after wpdb's checks of each value against its column).
 */
final class UpdateSql {

	/**
	 * The template and its values.
	 *
	 * @param string               $table         Table.
	 * @param array<string, mixed> $data          Column => value.
	 * @param string[]             $formats       Their formats, in order ("%s" or "%d").
	 * @param array<string, mixed> $where         Column => value.
	 * @param string[]             $where_formats Their formats, in order.
	 * @return array{0: string, 1: array<int, mixed>}
	 * @throws \InvalidArgumentException When there is nothing to set or no condition, a name is not a column name, or a format is not "%s" or "%d".
	 */
	public static function template( string $table, array $data, array $formats, array $where, array $where_formats ): array {
		if ( array() === $data || array() === $where ) {
			throw new \InvalidArgumentException( 'An update sets something, on a condition.' );
		}
		$params = array();
		$set    = array();
		foreach ( array_keys( $data ) as $i => $column ) {
			if ( null === $data[ $column ] ) {
				$set[] = self::name( $column ) . ' = NULL';
				continue;
			}
			$set[]    = self::name( $column ) . ' = ' . self::format( $formats[ $i ] ?? '%s' );
			$params[] = $data[ $column ];
		}
		$conditions = array();
		foreach ( array_keys( $where ) as $i => $column ) {
			if ( null === $where[ $column ] ) {
				$conditions[] = self::name( $column ) . ' IS NULL';
				continue;
			}
			$conditions[] = self::name( $column ) . ' = ' . self::format( $where_formats[ $i ] ?? '%s' );
			$params[]     = $where[ $column ];
		}
		return array( 'UPDATE ' . self::name( $table ) . ' SET ' . implode( ', ', $set ) . ' WHERE ' . implode( ' AND ', $conditions ), $params );
	}

	/**
	 * A table or column name, quoted.
	 *
	 * @param string $name Name.
	 * @return string
	 * @throws \InvalidArgumentException When it is not a plain name.
	 */
	private static function name( string $name ): string {
		if ( 1 !== preg_match( '/\A[A-Za-z0-9_$]{1,64}\z/', $name ) ) {
			throw new \InvalidArgumentException( 'Not a plain table or column name.' );
		}
		return '`' . $name . '`';
	}

	/**
	 * A format JobStore uses.
	 *
	 * @param string $format Format.
	 * @return string
	 * @throws \InvalidArgumentException When it is another.
	 */
	private static function format( string $format ): string {
		if ( '%s' !== $format && '%d' !== $format ) {
			throw new \InvalidArgumentException( 'A format is "%s" or "%d".' );
		}
		return $format;
	}
}
