<?php
/**
 * The database connection a restore imports through.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Standalone\Connection;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.RestrictedFunctions -- a connection of its own, next to $wpdb: the chunks' session settings must not reach WordPress's queries.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors; the database name is masked and the presenter cleans them.

/**
 * A mysqli connection of its own, opened with the site's settings
 * (Connection::connect()). Every statement goes through mysqli_query(),
 * which sends its text as one statement: never mysqli_multi_query().
 * The chunks' preamble sets the session's character set, SQL mode and
 * foreign key checks; on WordPress's shared connection those would change
 * how every later query of the same request is read, the job's own
 * checkpoints included. Each tick opens one and closes it at the end.
 *
 * The session starts with foreign key checks off and SQL mode
 * NO_AUTO_VALUE_ON_ZERO (what the preamble sets), so a statement runs the
 * same whether or not the chunk's preamble has been read in this session.
 *
 * Errors: the numbers that mean "try again" (a lost connection, a lock
 * wait or deadlock, too many connections) raise TransientFailure; any
 * other raises StatementFailed with the server's text, the database name
 * masked (it is often the hosting account's name).
 */
final class ImportSession implements Queries {

	/**
	 * Error numbers after which the same statement may succeed later.
	 */
	const TRANSIENT_ERRNOS = array( 1040, 1053, 1205, 1213, 2002, 2003, 2006, 2013 );

	/**
	 * Connection.
	 *
	 * @var \mysqli
	 */
	private $mysqli;

	/**
	 * Database name, masked in error texts.
	 *
	 * @var string
	 */
	private $database;

	/**
	 * Constructor.
	 *
	 * @param \mysqli $mysqli   Connection.
	 * @param string  $database Database name.
	 */
	private function __construct( \mysqli $mysqli, string $database ) {
		$this->mysqli   = $mysqli;
		$this->database = $database;
	}

	/**
	 * Connect and set up the session.
	 *
	 * @param Credentials $credentials Settings.
	 * @return self
	 * @throws TransientFailure When the database cannot be reached now.
	 */
	public static function open( Credentials $credentials ): self {
		try {
			$mysqli = Connection::connect( $credentials );
		} catch ( Failure $e ) {
			throw new TransientFailure( 'The restore could not open its own connection to the database: ' . $e->getMessage() );
		}
		$session = new self( $mysqli, $credentials->get( 'name' ) );
		$session->run( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO', SESSION foreign_key_checks = 0" );
		return $session;
	}

	/**
	 * Run one statement.
	 *
	 * @param string $sql SQL (one statement).
	 * @return int Affected rows.
	 * @throws TransientFailure When the server may accept it later.
	 * @throws StatementFailed When the server refuses it.
	 */
	public function run( string $sql ): int {
		return $this->quietly(
			function () use ( $sql ): int {
				if ( false === mysqli_query( $this->mysqli, $sql ) ) {
					throw $this->error();
				}
				return (int) mysqli_affected_rows( $this->mysqli );
			}
		);
	}

	/**
	 * Set the session's character set, on both sides: the server reads the
	 * statements in it and mysqli_real_escape_string() escapes for it. A
	 * SET NAMES sent as a statement would change only the server's side,
	 * and rows() would then escape for one character set while the server
	 * reads another.
	 *
	 * @param string $charset Character set (letters, digits, underscores).
	 * @return void
	 * @throws StatementFailed When the server does not know it.
	 */
	public function names( string $charset ): void {
		$this->quietly(
			function () use ( $charset ): bool {
				if ( '' === $charset || strspn( $charset, 'abcdefghijklmnopqrstuvwxyz0123456789_' ) !== strlen( $charset ) || ! mysqli_set_charset( $this->mysqli, $charset ) ) {
					throw new StatementFailed( sprintf( 'The database does not accept the character set %s.', $charset ), (int) mysqli_errno( $this->mysqli ) );
				}
				return true;
			}
		);
	}

	/**
	 * The session's character set (as the client and the server have it).
	 *
	 * @return string
	 */
	public function charset(): string {
		return strtolower( (string) mysqli_character_set_name( $this->mysqli ) );
	}

	/**
	 * Rows of a query, each "?" replaced by a quoted, escaped string (mysqli
	 * without mysqlnd has neither get_result() nor fetch_all(), so no
	 * prepared statements here).
	 *
	 * @param string   $sql    SQL with ? placeholders (none elsewhere in the text).
	 * @param string[] $params Values.
	 * @return array<int, array<int, string|null>>
	 * @throws \InvalidArgumentException When the placeholder count does not match.
	 * @throws TransientFailure When the server may answer later.
	 * @throws StatementFailed When the server refuses it.
	 */
	public function rows( string $sql, array $params = array() ): array {
		$parts = self::placeholders( $sql, $params );
		return $this->quietly(
			function () use ( $parts, $params ): array {
				$result = mysqli_query( $this->mysqli, $this->bind( $parts, $params ) );
				if ( false === $result ) {
					throw $this->error();
				}
				$rows = array();
				if ( $result instanceof \mysqli_result ) {
					$row = mysqli_fetch_row( $result );
					while ( is_array( $row ) ) {
						$rows[] = $row;
						$row    = mysqli_fetch_row( $result );
					}
					mysqli_free_result( $result );
				}
				return $rows;
			}
		);
	}

	/**
	 * Run one statement that changes rows, with values in place of "?" (as rows()).
	 *
	 * @param string   $sql    SQL with ? placeholders (none elsewhere in the text).
	 * @param string[] $params Values.
	 * @return int Affected rows.
	 * @throws \InvalidArgumentException When the placeholder count does not match.
	 * @throws TransientFailure When the server may accept it later.
	 * @throws StatementFailed When the server refuses it.
	 */
	public function write( string $sql, array $params ): int {
		$parts = self::placeholders( $sql, $params );
		return $this->quietly(
			function () use ( $parts, $params ): int {
				if ( false === mysqli_query( $this->mysqli, $this->bind( $parts, $params ) ) ) {
					throw $this->error();
				}
				return (int) mysqli_affected_rows( $this->mysqli );
			}
		);
	}

	/**
	 * The text around the placeholders.
	 *
	 * @param string   $sql    SQL with ? placeholders.
	 * @param string[] $params Values.
	 * @return string[]
	 * @throws \InvalidArgumentException When the placeholder count does not match.
	 */
	private static function placeholders( string $sql, array $params ): array {
		$parts = explode( '?', $sql );
		if ( count( $parts ) !== count( $params ) + 1 ) {
			throw new \InvalidArgumentException( 'Placeholder count does not match the arguments.' );
		}
		return $parts;
	}

	/**
	 * The statement with each value quoted and escaped for the connection's character set.
	 *
	 * @param string[] $parts  placeholders().
	 * @param string[] $params Values.
	 * @return string
	 */
	private function bind( array $parts, array $params ): string {
		$query = array_shift( $parts );
		foreach ( array_values( $params ) as $i => $value ) {
			$query .= "'" . mysqli_real_escape_string( $this->mysqli, (string) $value ) . "'" . $parts[ $i ];
		}
		return $query;
	}

	/**
	 * Start a transaction.
	 *
	 * @return void
	 */
	public function begin(): void {
		$this->run( 'START TRANSACTION' );
	}

	/**
	 * Commit.
	 *
	 * @return void
	 */
	public function commit(): void {
		$this->run( 'COMMIT' );
	}

	/**
	 * Roll back, quietly (after a failure, the failure is what matters).
	 *
	 * @return void
	 */
	public function rollback(): void {
		try {
			$this->run( 'ROLLBACK' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
	}

	/**
	 * Close.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->quietly(
			function (): bool {
				return mysqli_close( $this->mysqli );
			}
		);
	}

	/**
	 * The exception for the last error.
	 *
	 * @return \RuntimeException
	 */
	private function error(): \RuntimeException {
		$errno = (int) mysqli_errno( $this->mysqli );
		$text  = (string) mysqli_error( $this->mysqli );
		if ( '' !== $this->database ) {
			$text = str_replace( $this->database, '[database]', $text );
		}
		if ( in_array( $errno, self::TRANSIENT_ERRNOS, true ) ) {
			return new TransientFailure( sprintf( 'The database is busy or the connection was lost (%d): %s', $errno, $text ) );
		}
		return new StatementFailed( sprintf( 'The database refused a statement (%d): %s', $errno, $text ), $errno );
	}

	/**
	 * Run mysqli calls with its exceptions off, as they were afterwards, and its warnings kept here.
	 *
	 * @template T
	 * @param callable(): T $work Work.
	 * @return T
	 */
	private function quietly( callable $work ) {
		$driver = new \mysqli_driver();
		$mode   = $driver->report_mode;
		mysqli_report( MYSQLI_REPORT_OFF );
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- mysqli's warnings may name user@host.
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
}
