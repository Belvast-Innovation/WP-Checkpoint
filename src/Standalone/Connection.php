<?php
/**
 * A database connection made without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Standalone;

defined( 'ABSPATH' ) || defined( 'WPCHECKPOINT_STANDALONE' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are fixed text from Failure::MESSAGES, never HTML.
// phpcs:disable WordPress.DB.RestrictedFunctions -- this runs where WordPress (and $wpdb) is not loaded; mysqli is the only way.

/**
 * Connects the way wpdb does: DB_HOST split by wpdb::parse_db_host()'s
 * rules (socket, port, IPv6), no database named when connecting and the
 * database selected after, MYSQL_CLIENT_FLAGS passed on, the character set
 * set (utf8 when utf8mb4 is refused), mysqli's exceptions switched off so
 * that its messages (which name user@host) never travel. Every failure is a
 * Failure chosen by the MySQL error number.
 *
 * Read-only: whether a table or a row exists, which is how callers prove
 * the settings point to the right database before trusting them.
 */
final class Connection {

	/**
	 * Connection.
	 *
	 * @var \mysqli
	 */
	private $mysqli;

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param \mysqli $mysqli Connection.
	 * @param string  $prefix Table prefix.
	 */
	private function __construct( \mysqli $mysqli, string $prefix ) {
		$this->mysqli = $mysqli;
		$this->prefix = $prefix;
	}

	/**
	 * Connect.
	 *
	 * @param Credentials $credentials Settings.
	 * @return self
	 * @throws Failure When the database cannot be used.
	 */
	public static function open( Credentials $credentials ): self {
		if ( ! class_exists( 'mysqli' ) || ! function_exists( 'mysqli_init' ) ) {
			throw new Failure( Failure::NO_MYSQLI );
		}
		$parts = self::parse_host( $credentials->get( 'host' ) );
		if ( null === $parts ) {
			throw new Failure( Failure::BAD_HOST );
		}
		list( $host, $port, $socket, $is_ipv6 ) = $parts;
		if ( $is_ipv6 && extension_loaded( 'mysqlnd' ) ) {
			$host = '[' . $host . ']';
		}
		mysqli_report( MYSQLI_REPORT_OFF );
		$mysqli = mysqli_init();
		if ( ! $mysqli instanceof \mysqli ) {
			throw new Failure( Failure::NO_MYSQLI );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would print the host and user.
		if ( ! @mysqli_real_connect( $mysqli, $host, $credentials->get( 'user' ), $credentials->get( 'password' ), '', $port, $socket, $credentials->flags() ) ) {
			throw self::failure( mysqli_connect_errno() );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above, with the database name.
		if ( ! @mysqli_select_db( $mysqli, $credentials->get( 'name' ) ) ) {
			$errno = mysqli_errno( $mysqli );
			mysqli_close( $mysqli );
			throw self::failure( $errno );
		}
		self::charset( $mysqli, $credentials->get( 'charset' ), $credentials->get( 'collate' ) );
		return new self( $mysqli, $credentials->get( 'prefix' ) );
	}

	/**
	 * DB_HOST split as wpdb::parse_db_host() splits it: host, port, socket, IPv6.
	 *
	 * @param string $host DB_HOST.
	 * @return array{0: string, 1: int|null, 2: string|null, 3: bool}|null Null when it cannot be read.
	 */
	public static function parse_host( string $host ) {
		$socket     = null;
		$is_ipv6    = false;
		$socket_pos = strpos( $host, ':/' );
		if ( false !== $socket_pos ) {
			$socket = substr( $host, $socket_pos + 1 );
			$host   = substr( $host, 0, $socket_pos );
		}
		if ( substr_count( $host, ':' ) > 1 ) {
			$pattern = '#^(?:\[)?(?P<host>[0-9a-fA-F:]+)(?:\]:(?P<port>[\d]+))?#';
			$is_ipv6 = true;
		} else {
			$pattern = '#^(?P<host>[^:/]*)(?::(?P<port>[\d]+))?#';
		}
		if ( 1 !== preg_match( $pattern, $host, $matches ) ) {
			return null;
		}
		$name = ! empty( $matches['host'] ) ? $matches['host'] : '';
		$port = ! empty( $matches['port'] ) ? abs( (int) $matches['port'] ) : null;
		return array( $name, $port, $socket, $is_ipv6 );
	}

	/**
	 * Table prefix.
	 *
	 * @return string
	 */
	public function prefix(): string {
		return $this->prefix;
	}

	/**
	 * Whether a table with the prefix and this suffix exists.
	 *
	 * @param string $suffix Table name without the prefix.
	 * @return bool
	 * @throws Failure When the database does not answer.
	 */
	public function table_exists( string $suffix ): bool {
		return array() !== $this->select( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', array( $this->prefix . $suffix ) );
	}

	/**
	 * Whether a row with this value in this column exists in a table with the prefix.
	 *
	 * @param string $suffix Table name without the prefix.
	 * @param string $column Column (letters, digits, underscores).
	 * @param string $value  Value.
	 * @return bool
	 * @throws \InvalidArgumentException When a name is not a plain identifier.
	 * @throws Failure When the database does not answer.
	 */
	public function row_exists( string $suffix, string $column, string $value ): bool {
		if ( ! self::identifier( $suffix ) || ! self::identifier( $column ) ) {
			throw new \InvalidArgumentException( 'Table and column names are plain identifiers.' );
		}
		if ( ! $this->table_exists( $suffix ) ) {
			return false;
		}
		return array() !== $this->select( 'SELECT 1 FROM `' . $this->prefix . $suffix . '` WHERE `' . $column . '` = ? LIMIT 1', array( $value ) );
	}

	/**
	 * Close.
	 *
	 * @return void
	 */
	public function close(): void {
		mysqli_close( $this->mysqli );
	}

	/**
	 * Rows of a prepared query with string parameters.
	 *
	 * @param string   $sql    SQL with ? placeholders.
	 * @param string[] $params Values.
	 * @return array<int, array<int, mixed>>
	 * @throws Failure When the database does not answer.
	 */
	private function select( string $sql, array $params ): array {
		$statement = @mysqli_prepare( $this->mysqli, $sql ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- errors are reported by number only.
		if ( false === $statement ) {
			throw new Failure( Failure::QUERY );
		}
		if ( array() !== $params ) {
			$types = str_repeat( 's', count( $params ) );
			mysqli_stmt_bind_param( $statement, $types, ...$params );
		}
		if ( ! @mysqli_stmt_execute( $statement ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
			mysqli_stmt_close( $statement );
			throw new Failure( Failure::QUERY );
		}
		$result = mysqli_stmt_get_result( $statement );
		$rows   = false === $result ? array() : mysqli_fetch_all( $result, MYSQLI_NUM );
		mysqli_stmt_close( $statement );
		return $rows;
	}

	/**
	 * Set the character set and collation as wpdb does.
	 *
	 * @param \mysqli $mysqli  Connection.
	 * @param string  $charset DB_CHARSET.
	 * @param string  $collate DB_COLLATE.
	 * @return void
	 * @throws Failure When the character set is refused.
	 */
	private static function charset( \mysqli $mysqli, string $charset, string $collate ): void {
		if ( '' === $charset ) {
			return;
		}
		if ( ! self::identifier( $charset ) || ( '' !== $collate && ! self::identifier( $collate ) ) ) {
			throw new Failure( Failure::CHARSET );
		}
		if ( ! @mysqli_set_charset( $mysqli, $charset ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported by category.
			if ( 'utf8mb4' !== $charset || ! @mysqli_set_charset( $mysqli, 'utf8' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.
				throw new Failure( Failure::CHARSET );
			}
			$charset = 'utf8';
			$collate = '';
		}
		if ( '' !== $collate && ! @mysqli_query( $mysqli, 'SET NAMES ' . $charset . ' COLLATE ' . $collate ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.DB.RestrictedFunctions -- both names checked as identifiers above.
			throw new Failure( Failure::CHARSET );
		}
	}

	/**
	 * A Failure for a MySQL error number.
	 *
	 * @param int $errno Error number.
	 * @return Failure
	 */
	private static function failure( int $errno ): Failure {
		if ( in_array( $errno, array( 2002, 2003, 2005, 2006, 2013 ), true ) ) {
			return new Failure( Failure::UNREACHABLE );
		}
		if ( 1045 === $errno ) {
			return new Failure( Failure::ACCESS_DENIED );
		}
		if ( in_array( $errno, array( 1044, 1049 ), true ) ) {
			return new Failure( Failure::UNKNOWN_DATABASE );
		}
		return new Failure( Failure::OTHER, $errno );
	}

	/**
	 * Whether a name is a plain identifier (letters, digits, underscores).
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	private static function identifier( string $name ): bool {
		return '' !== $name && strspn( $name, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_' ) === strlen( $name );
	}
}
