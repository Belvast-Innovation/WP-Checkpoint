<?php
/**
 * Turns rows into SQL text.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Database;

/**
 * Pure formatting: identifiers in backticks, NULL as NULL, numeric
 * columns unquoted when the value looks like a number, binary columns as
 * X'..' hex, everything else as a single-quoted string with backslash
 * escapes. Backslash escaping is byte-safe only when no continuation
 * byte of a multibyte character can be 0x5C or 0x27, which holds for
 * utf8 and utf8mb4 (continuation bytes are 0x80-0xBF) and not for GBK,
 * Big5 or Shift_JIS; on such a connection every string goes out as hex
 * (hex_all), correct at the cost of readability.
 */
final class SqlWriter {

	/**
	 * Whether every string is written as hex.
	 *
	 * @var bool
	 */
	private $hex_all;

	/**
	 * Constructor.
	 *
	 * @param string $charset Connection character set.
	 */
	public function __construct( string $charset ) {
		$this->hex_all = ! self::backslash_safe( $charset );
	}

	/**
	 * Whether backslash escaping is byte-safe for a character set.
	 *
	 * @param string $charset MySQL charset name.
	 * @return bool
	 */
	public static function backslash_safe( string $charset ): bool {
		return in_array( strtolower( $charset ), array( 'utf8', 'utf8mb3', 'utf8mb4', 'latin1', 'ascii', 'binary' ), true );
	}

	/**
	 * Whether every string is hex-encoded on this connection.
	 *
	 * @return bool
	 */
	public function hex_all(): bool {
		return $this->hex_all;
	}

	/**
	 * A quoted identifier.
	 *
	 * @param string $name Table or column name.
	 * @return string
	 */
	public static function identifier( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * Classify a column from its SHOW COLUMNS type: 'binary', 'numeric' or 'text'.
	 *
	 * @param string $type Column type as SHOW COLUMNS prints it ("bigint(20) unsigned", "varbinary(255)").
	 * @return string
	 */
	public static function kind( string $type ): string {
		$type = strtolower( $type );
		if ( 1 === preg_match( '/\A(?:tiny|medium|long)?blob|\A(?:var)?binary|\Abit\b/', $type ) ) {
			return 'binary';
		}
		if ( 1 === preg_match( '/\A(?:tiny|small|medium|big)?int\b|\A(?:decimal|numeric|float|double|real|year)\b|\Abool(?:ean)?\b/', $type ) ) {
			return 'numeric';
		}
		return 'text';
	}

	/**
	 * One value as SQL.
	 *
	 * @param string|null $value Value as the driver returned it.
	 * @param string      $kind  Column kind (see kind()).
	 * @return string
	 */
	public function value( $value, string $kind ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		$value = (string) $value;
		if ( 'numeric' === $kind && 1 === preg_match( '/\A-?(?:\d+|\d*\.\d+)(?:[eE][-+]?\d+)?\z/', $value ) ) {
			return $value;
		}
		if ( 'binary' === $kind || $this->hex_all ) {
			return self::hex( $value );
		}
		return self::quote( $value );
	}

	/**
	 * A string as X'..' (or '' when empty, which MySQL treats the same).
	 *
	 * @param string $value Bytes.
	 * @return string
	 */
	public static function hex( string $value ): string {
		if ( '' === $value ) {
			return "''";
		}
		$hex = bin2hex( $value );
		return "X'{$hex}'"; // Interpolation allocates the result once; a large value is not copied twice.
	}

	/**
	 * A single-quoted, backslash-escaped string.
	 *
	 * @param string $value Bytes.
	 * @return string
	 */
	public static function quote( string $value ): string {
		$escaped = strtr(
			$value,
			array(
				'\\'   => '\\\\',
				"'"    => "\\'",
				"\0"   => '\\0',
				"\n"   => '\\n',
				"\r"   => '\\r',
				"\x1a" => '\\Z',
			)
		);
		return "'{$escaped}'"; // See hex().
	}

	/**
	 * The head of an INSERT statement: INSERT INTO `t` (`a`, `b`) VALUES .
	 *
	 * @param string   $table   Table name.
	 * @param string[] $columns Column names.
	 * @return string
	 */
	public static function insert_head( string $table, array $columns ): string {
		return 'INSERT INTO ' . self::identifier( $table ) . ' (' . implode( ', ', array_map( array( __CLASS__, 'identifier' ), $columns ) ) . ') VALUES ';
	}

	/**
	 * The values of one row as SQL, in order. The exporter writes them
	 * one by one so a large value is held once, not concatenated.
	 *
	 * @param array<int, string|null> $row   Values.
	 * @param string[]                $kinds Column kinds in the same order.
	 * @return string[]
	 */
	public function values( array $row, array $kinds ): array {
		$out = array();
		foreach ( $row as $i => $value ) {
			$out[] = $this->value( $value, isset( $kinds[ $i ] ) ? $kinds[ $i ] : 'text' );
		}
		return $out;
	}

	/**
	 * One row as a parenthesised tuple.
	 *
	 * @param array<int, string|null> $row   Values.
	 * @param string[]                $kinds Column kinds in the same order.
	 * @return string
	 */
	public function tuple( array $row, array $kinds ): string {
		return '(' . implode( ',', $this->values( $row, $kinds ) ) . ')';
	}
}
