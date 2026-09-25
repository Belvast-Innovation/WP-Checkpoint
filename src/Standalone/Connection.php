<?php
/**
 * A database connection made without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Standalone;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are fixed text from Failure::MESSAGES, never HTML.
// phpcs:disable WordPress.DB.RestrictedFunctions -- this runs where WordPress (and $wpdb) is not loaded; mysqli is the only way.

/**
 * Connects the way wpdb does where it matters: DB_HOST split by
 * wpdb::parse_db_host()'s rules (socket, port, IPv6), no database named
 * when connecting, the character set set before the database is selected,
 * MYSQL_CLIENT_FLAGS passed on. Differences from wpdb: a DB_HOST that cannot
 * be split is a failure (wpdb goes on with it), utf8 is not upgraded to
 * utf8mb4 (the checks here read ASCII identifiers), and a connection gives
 * up after CONNECT_TIMEOUT seconds instead of PHP's socket timeout (a page
 * waiting a minute on an unreachable host would be cut off by the web
 * server with no reason given).
 *
 * Every mysqli call runs with mysqli's exceptions switched off and PHP's
 * errors caught locally, both put back afterwards: mysqli's warnings name
 * user@host and would otherwise reach an error handler installed by
 * something else. Every failure is a Failure chosen by the MySQL error
 * number.
 *
 * Read-only: whether a table or a row exists, which is how callers prove
 * the settings point to the right database before trusting them. Needs
 * mysqli, not mysqlnd.
 */
final class Connection {

	/**
	 * Seconds a connection attempt may take.
	 */
	const CONNECT_TIMEOUT = 10;

	/**
	 * Seconds a query may wait for an answer (mysqlnd only).
	 */
	const READ_TIMEOUT = 20;

	/**
	 * MYSQLI_CLIENT_MULTI_STATEMENTS (65536), never passed on.
	 */
	const MULTI_STATEMENTS = 65536;

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
	 * @param int         $timeout     Seconds a connection attempt may take.
	 * @return self
	 * @throws Failure When the database cannot be used.
	 */
	public static function open( Credentials $credentials, int $timeout = self::CONNECT_TIMEOUT ): self {
		return new self( self::connect( $credentials, $timeout ), $credentials->get( 'prefix' ) );
	}

	/**
	 * A mysqli connection made as open() makes it, for callers that run
	 * their own statements (the restore's import). Such callers rely on one
	 * call running one statement at most: they only use mysqli_query(), and
	 * PHP's mysqli_real_connect() itself drops MYSQLI_CLIENT_MULTI_STATEMENTS
	 * from the flags (several statements per call only through
	 * mysqli_multi_query()). The flag is removed from MYSQL_CLIENT_FLAGS here
	 * as well, so the rule does not rest on that alone.
	 *
	 * @param Credentials $credentials Settings.
	 * @param int         $timeout     Seconds a connection attempt may take.
	 * @return \mysqli
	 * @throws Failure When the database cannot be used.
	 */
	public static function connect( Credentials $credentials, int $timeout = self::CONNECT_TIMEOUT ): \mysqli {
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
		$flags = $credentials->flags() & ~self::MULTI_STATEMENTS;
		return self::quietly(
			static function () use ( $credentials, $host, $port, $socket, $timeout, $flags ): \mysqli {
				$mysqli = mysqli_init();
				if ( ! $mysqli instanceof \mysqli ) {
					throw new Failure( Failure::NO_MYSQLI );
				}
				mysqli_options( $mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, max( 1, $timeout ) );
				if ( defined( 'MYSQLI_OPT_READ_TIMEOUT' ) ) {
					mysqli_options( $mysqli, MYSQLI_OPT_READ_TIMEOUT, self::READ_TIMEOUT );
				}
				if ( ! mysqli_real_connect( $mysqli, $host, $credentials->get( 'user' ), $credentials->get( 'password' ), '', $port, $socket, $flags ) ) {
					throw self::failure( mysqli_connect_errno() );
				}
				try {
					self::charset( $mysqli, $credentials->get( 'charset' ), $credentials->get( 'collate' ) );
					if ( ! mysqli_select_db( $mysqli, $credentials->get( 'name' ) ) ) {
						throw self::failure( mysqli_errno( $mysqli ) );
					}
				} catch ( Failure $e ) {
					mysqli_close( $mysqli );
					throw $e;
				}
				return $mysqli;
			}
		);
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
		return $this->any( 'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1', array( $this->prefix . $suffix ) );
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
		return $this->any( 'SELECT 1 FROM `' . $this->prefix . $suffix . '` WHERE `' . $column . '` = ? LIMIT 1', array( $value ) );
	}

	/**
	 * Close.
	 *
	 * @return void
	 */
	public function close(): void {
		self::quietly(
			function (): bool {
				return mysqli_close( $this->mysqli );
			}
		);
	}

	/**
	 * Whether a prepared query with string parameters returns a row.
	 *
	 * @param string   $sql    SQL with ? placeholders.
	 * @param string[] $params Values.
	 * @return bool
	 * @throws Failure When the database does not answer.
	 */
	private function any( string $sql, array $params ): bool {
		return self::quietly(
			function () use ( $sql, $params ): bool {
				$statement = mysqli_prepare( $this->mysqli, $sql );
				if ( false === $statement ) {
					throw new Failure( Failure::QUERY );
				}
				try {
					if ( array() !== $params ) {
						mysqli_stmt_bind_param( $statement, str_repeat( 's', count( $params ) ), ...$params );
					}
					if ( ! mysqli_stmt_execute( $statement ) || ! mysqli_stmt_store_result( $statement ) ) {
						throw new Failure( Failure::QUERY );
					}
					return mysqli_stmt_num_rows( $statement ) > 0;
				} finally {
					mysqli_stmt_close( $statement );
				}
			}
		);
	}

	/**
	 * Run mysqli calls with its exceptions off and PHP's errors kept here,
	 * both as they were afterwards.
	 *
	 * @template T
	 * @param callable(): T $work Work.
	 * @return T
	 */
	private static function quietly( callable $work ) {
		$driver = new \mysqli_driver();
		$mode   = $driver->report_mode;
		mysqli_report( MYSQLI_REPORT_OFF );
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- mysqli's warnings name user@host.
			static function (): bool {
				return true;
			}
		);
		try {
			return $work();
		} finally {
			restore_error_handler();
			mysqli_report( $mode );
		}
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
		if ( ! mysqli_set_charset( $mysqli, $charset ) ) {
			if ( 'utf8mb4' !== $charset || ! mysqli_set_charset( $mysqli, 'utf8' ) ) {
				throw new Failure( Failure::CHARSET );
			}
			$charset = 'utf8';
			$collate = '';
		}
		if ( '' !== $collate && ! mysqli_query( $mysqli, 'SET NAMES ' . $charset . ' COLLATE ' . $collate ) ) {
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
		if ( in_array( $errno, array( 1045, 1698 ), true ) ) {
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
