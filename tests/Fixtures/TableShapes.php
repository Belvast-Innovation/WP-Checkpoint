<?php

namespace WPCheckpoint\Tests\Fixtures;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestResult;
use PHPUnit\Framework\TestSuite;

/**
 * What the integration tests change in the shape of the test site's tables: for every base table whose name starts
 * with the table prefix, its SHOW CREATE TABLE (without "AUTO_INCREMENT=N", which rows move) before and after each test,
 * and at the start and end of each class (its class-level set-up and tear-down). A test after which a table's
 * statement differs, or a table is gone, fails, named, with both statements; a class the same, as a class. That covers
 * the engine, the character set and collation, the columns, the keys and the row format: a test that turns a table
 * MyISAM, or changes its collation, and does not put it back makes every test after it run on another table than the
 * one it was written for, without anyone seeing.
 *
 * WPCHECKPOINT_TABLE_SHAPES_RESTORE: unset or anything but "1" (the default), it only lists: the failures are reported,
 * nothing is changed. "1": a table whose statement changed is also made again as it was (its old statement under a
 * temporary name, the columns both have copied, the names swapped, the changed one dropped), and a table that is gone
 * is made again empty; only on wp-env's tests database (Leftovers::database_refusal(); anywhere else nothing is
 * changed and the reason is said).
 *
 * Read through a connection of its own: a temporary table a test makes through wpdb (as the core test case does) under
 * a base table's name would hide that table from wpdb's connection, and is not the base table's shape. Tables that a
 * test adds are not looked at here (Leftovers looks for those that are not WordPress's).
 *
 * Registered in phpunit.xml.dist; does nothing in the unit suite.
 */
final class TableShapes implements TestListener {
	use TestListenerDefaultImplementation;

	/** The environment variable that turns restoring on ("1"). */
	const RESTORE = 'WPCHECKPOINT_TABLE_SHAPES_RESTORE';

	/** The temporary name's start while a table is made again. */
	const REMAKE = 'wpcshape_';

	/**
	 * The test the registered listener is watching now ("Class::method"), '' when none: the control that the check
	 * runs at all (TableShapesCheckTest).
	 *
	 * @var string
	 */
	private static $watching = '';

	/**
	 * Tables read when the test the registered listener is watching started: the control that it read any.
	 *
	 * @var int
	 */
	private static $read = 0;

	/**
	 * Reads the shapes: function(): array<string, string> (table => statement); null while the suite is not the
	 * integration suite.
	 *
	 * @var callable|null
	 */
	private $shapes;

	/**
	 * Makes tables again: function( array<string, string> $was, array<string, string|null> $now ): string[] (what could
	 * not be, with why); null for the tests database.
	 *
	 * @var callable|null
	 */
	private $remaker;

	/** @var bool|null Whether to make tables again; null for the environment variable. */
	private $restore;

	/** @var bool Whether the shapes reader was given (a test of this class). */
	private $given;

	/** @var array<int, array<string, string>> The shapes when each open suite started, innermost last. */
	private $suites = array();

	/** @var array<string, string>|null The shapes when the running test started. */
	private $before = null;

	/** @var TestResult|null The run's result, for failures of a class. */
	private $result = null;

	/** @var \mysqli|null The connection of its own. */
	private $db = null;

	/**
	 * Constructor.
	 *
	 * @param callable|null $shapes  function(): array<string, string>; the tests database when null.
	 * @param callable|null $remaker function( array, array ): string[]; the tests database when null.
	 * @param bool|null     $restore Whether to make tables again; the environment variable when null.
	 */
	public function __construct( $shapes = null, $remaker = null, ?bool $restore = null ) {
		$this->shapes  = $shapes;
		$this->remaker = $remaker;
		$this->restore = $restore;
		$this->given   = null !== $shapes;
	}

	/**
	 * The test the registered listener is watching now.
	 *
	 * @return string
	 */
	public static function watching(): string {
		return self::$watching;
	}

	/**
	 * Tables read when the watched test started.
	 *
	 * @return int
	 */
	public static function read(): int {
		return self::$read;
	}

	/**
	 * A table's statement without what rows move: "AUTO_INCREMENT=N".
	 *
	 * @param string $statement SHOW CREATE TABLE's statement.
	 * @return string
	 */
	public static function shape( string $statement ): string {
		$out = preg_replace( '/ AUTO_INCREMENT=[0-9]+\b/', '', $statement );
		if ( null === $out ) {
			throw new \RuntimeException( 'A table\'s statement could not be read.' );
		}
		return $out;
	}

	/**
	 * What differs between two readings: "changed" and "gone" tables, with both statements.
	 *
	 * @param array<string, string> $was Before.
	 * @param array<string, string> $now After.
	 * @return array<string, string|null> Table => its statement now, null when gone.
	 */
	public static function differences( array $was, array $now ): array {
		$out = array();
		foreach ( $was as $table => $statement ) {
			if ( ! isset( $now[ $table ] ) ) {
				$out[ $table ] = null;
			} elseif ( $now[ $table ] !== $statement ) {
				$out[ $table ] = $now[ $table ];
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The failure's text.
	 *
	 * @param string                     $who  "The test", or a class's name.
	 * @param array<string, string>      $was  Before.
	 * @param array<string, string|null> $diff differences().
	 * @param bool                       $made Whether the tables were made again.
	 * @param string[]                   $not  What could not be made again, with why.
	 * @return string
	 */
	public static function text( string $who, array $was, array $diff, bool $made, array $not ): string {
		$lines = array( sprintf( '%s changed the shape of tables of the test site (%s):', $who, $made ? 'made again as they were' : 'left as they are: ' . self::RESTORE . ' is not 1' ) );
		foreach ( $diff as $table => $now ) {
			$lines[] = '  ' . $table . ( null === $now ? ': gone' : ':' );
			$lines[] = '    was: ' . str_replace( "\n", ' ', $was[ $table ] );
			if ( null !== $now ) {
				$lines[] = '    now: ' . str_replace( "\n", ' ', $now );
			}
		}
		foreach ( $not as $line ) {
			$lines[] = '  not made again: ' . $line;
		}
		return implode( "\n", $lines );
	}

	/**
	 * Whether to make tables again.
	 *
	 * @return bool
	 */
	private function restoring(): bool {
		return null !== $this->restore ? $this->restore : '1' === getenv( self::RESTORE );
	}

	/**
	 * The shapes now; null while the suite is not the integration suite.
	 *
	 * @return array<string, string>|null
	 */
	private function now(): ?array {
		if ( $this->given ) {
			return (array) call_user_func( $this->shapes );
		}
		if ( 'integration' !== getenv( 'WPCHECKPOINT_TEST_SUITE' ) || ! isset( $GLOBALS['wpdb'] ) ) {
			return null;
		}
		return self::read_shapes( $this->connection(), (string) $GLOBALS['wpdb']->base_prefix );
	}

	/**
	 * The connection of its own, opened once.
	 *
	 * @return \mysqli
	 */
	private function connection(): \mysqli {
		if ( null === $this->db ) {
			$host = (string) DB_HOST;
			$port = null;
			if ( 1 === preg_match( '/\A(.*):([0-9]+)\z/', $host, $parts ) ) {
				$host = $parts[1];
				$port = (int) $parts[2];
			}
			$db = mysqli_init();
			if ( false === $db || ! @mysqli_real_connect( $db, $host, (string) DB_USER, (string) DB_PASSWORD, (string) DB_NAME, $port ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the failure is thrown with its reason.
				throw new \RuntimeException( 'The table-shape check could not connect to the tests database.' );
			}
			mysqli_set_charset( $db, 'utf8mb4' );
			$this->db = $db;
		}
		return $this->db;
	}

	/**
	 * Every base table whose name starts with the prefix, and its shape.
	 *
	 * @param \mysqli $db     Connection.
	 * @param string  $prefix Table prefix.
	 * @return array<string, string>
	 */
	public static function read_shapes( \mysqli $db, string $prefix ): array {
		$like   = str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $prefix ) . '%';
		$result = mysqli_query( $db, "SHOW FULL TABLES LIKE '" . mysqli_real_escape_string( $db, $like ) . "'" );
		if ( ! $result instanceof \mysqli_result ) {
			throw new \RuntimeException( 'The table-shape check could not list the tables: ' . mysqli_error( $db ) );
		}
		$tables = array();
		$row    = mysqli_fetch_row( $result );
		while ( is_array( $row ) ) {
			if ( isset( $row[0], $row[1] ) && 'BASE TABLE' === $row[1] ) {
				$tables[] = (string) $row[0];
			}
			$row = mysqli_fetch_row( $result );
		}
		mysqli_free_result( $result );
		$out = array();
		foreach ( $tables as $table ) {
			$shown = mysqli_query( $db, 'SHOW CREATE TABLE `' . str_replace( '`', '``', $table ) . '`' );
			if ( ! $shown instanceof \mysqli_result ) {
				continue; // Gone meanwhile.
			}
			$row = mysqli_fetch_row( $shown );
			mysqli_free_result( $shown );
			if ( is_array( $row ) && isset( $row[1] ) ) {
				$out[ $table ] = self::shape( (string) $row[1] );
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Make tables again as they were, on the tests database only.
	 *
	 * @param array<string, string>      $was  Before.
	 * @param array<string, string|null> $diff differences().
	 * @return string[] What could not be, with why.
	 */
	private function remake( array $was, array $diff ): array {
		if ( null !== $this->remaker ) {
			return (array) call_user_func( $this->remaker, $was, $diff );
		}
		$db      = $this->connection();
		$name    = mysqli_query( $db, 'SELECT DATABASE()' );
		$server  = $name instanceof \mysqli_result ? (string) ( mysqli_fetch_row( $name )[0] ?? '' ) : '';
		$refusal = Leftovers::database_refusal( (string) DB_NAME, $server, (string) DB_HOST );
		if ( '' !== $refusal ) {
			return array( 'every table: ' . $refusal );
		}
		$not = array();
		foreach ( $diff as $table => $now ) {
			$why = self::remake_one( $db, $table, $was[ $table ], null !== $now );
			if ( '' !== $why ) {
				$not[] = $table . ': ' . $why;
			}
		}
		return $not;
	}

	/**
	 * Make one table again from its old statement: under a temporary name, the columns both have copied, the names
	 * swapped, the changed one dropped; a table that is gone is made again empty.
	 *
	 * @param \mysqli $db        Connection.
	 * @param string  $table     Table.
	 * @param string  $statement Its old statement.
	 * @param bool    $there     Whether the table is there (changed) or gone.
	 * @return string '' when done, else why not.
	 */
	public static function remake_one( \mysqli $db, string $table, string $statement, bool $there ): string {
		$quoted = '`' . str_replace( '`', '``', $table ) . '`';
		$head   = 'CREATE TABLE ' . $quoted;
		if ( 0 !== strpos( $statement, $head ) ) {
			return 'its statement does not start with its name';
		}
		if ( ! $there ) {
			return mysqli_query( $db, $statement ) ? '' : mysqli_error( $db );
		}
		$temp  = self::REMAKE . substr( md5( $table ), 0, 12 );
		$aside = $temp . '_x';
		$tq    = '`' . $temp . '`';
		$aq    = '`' . $aside . '`';
		mysqli_query( $db, 'DROP TABLE IF EXISTS ' . $tq );
		if ( ! mysqli_query( $db, 'CREATE TABLE ' . $tq . substr( $statement, strlen( $head ) ) ) ) {
			return 'its old statement could not be run: ' . mysqli_error( $db );
		}
		$common = array_values( array_intersect( self::columns( $db, $table ), self::columns( $db, $temp ) ) );
		$list = implode( ', ', array_map( static function ( string $column ): string {
			return '`' . str_replace( '`', '``', $column ) . '`';
		}, $common ) );
		if ( '' !== $list && ! mysqli_query( $db, 'INSERT INTO ' . $tq . ' (' . $list . ') SELECT ' . $list . ' FROM ' . $quoted ) ) {
			$why = mysqli_error( $db );
			mysqli_query( $db, 'DROP TABLE IF EXISTS ' . $tq );
			return 'its rows could not be copied: ' . $why;
		}
		if ( ! mysqli_query( $db, 'RENAME TABLE ' . $quoted . ' TO ' . $aq . ', ' . $tq . ' TO ' . $quoted ) ) {
			$why = mysqli_error( $db );
			mysqli_query( $db, 'DROP TABLE IF EXISTS ' . $tq );
			return 'the names could not be swapped: ' . $why;
		}
		mysqli_query( $db, 'DROP TABLE IF EXISTS ' . $aq );
		return '';
	}

	/**
	 * A table's column names.
	 *
	 * @param \mysqli $db    Connection.
	 * @param string  $table Table.
	 * @return string[]
	 */
	private static function columns( \mysqli $db, string $table ): array {
		$out    = array();
		$result = mysqli_query( $db, 'SHOW COLUMNS FROM `' . str_replace( '`', '``', $table ) . '`' );
		if ( $result instanceof \mysqli_result ) {
			$row = mysqli_fetch_row( $result );
			while ( is_array( $row ) ) {
				$out[] = (string) $row[0];
				$row   = mysqli_fetch_row( $result );
			}
			mysqli_free_result( $result );
		}
		return $out;
	}

	/**
	 * Report a class's failure through a stand-in test named after it.
	 *
	 * @param string $name What failed.
	 * @param string $text Why.
	 * @return void
	 */
	private function fail_as( string $name, string $text ): void {
		if ( null === $this->result ) {
			return;
		}
		$stand_in = new LeftoversStandIn( $name );
		$this->result->startTest( $stand_in );
		$this->result->addFailure( $stand_in, new AssertionFailedError( $text ), 0.0 );
		$this->result->endTest( $stand_in, 0.0 );
	}

	/**
	 * Compare, report, and (when restoring) make the tables again.
	 *
	 * @param array<string, string> $was Before.
	 * @param array<string, string> $now After.
	 * @param string                $who "The test", or a class's name.
	 * @return string The failure's text, '' when nothing differs.
	 */
	private function check( array $was, array $now, string $who ): string {
		$diff = self::differences( $was, $now );
		if ( array() === $diff ) {
			return '';
		}
		$made = $this->restoring();
		$not  = $made ? $this->remake( $was, $diff ) : array();
		return self::text( $who, $was, $diff, $made, $not );
	}

	/**
	 * The start of a suite.
	 *
	 * @param TestSuite $suite Suite.
	 * @return void
	 */
	public function startTestSuite( TestSuite $suite ): void {
		$now = $this->now();
		if ( null !== $now ) {
			$this->suites[] = $now;
		}
	}

	/**
	 * The end of a suite: a class whose shapes differ from its start (its tests' were checked each) fails as a class.
	 *
	 * @param TestSuite $suite Suite.
	 * @return void
	 */
	public function endTestSuite( TestSuite $suite ): void {
		$now = $this->now();
		if ( null === $now || array() === $this->suites ) {
			return;
		}
		$start = (array) array_pop( $this->suites );
		$text  = $this->check( $start, $now, $suite->getName() );
		if ( '' !== $text ) {
			$this->fail_as( 'table shapes of ' . $suite->getName(), $text );
		}
	}

	/**
	 * The start of a test.
	 *
	 * @param Test $test Test.
	 * @return void
	 */
	public function startTest( Test $test ): void {
		if ( $test instanceof TestCase && null !== $test->getTestResultObject() ) {
			$this->result = $test->getTestResultObject();
		}
		$this->before = $this->now();
		if ( null !== $this->before && ! $this->given && $test instanceof TestCase ) {
			self::$watching = get_class( $test ) . '::' . $test->getName( false );
			self::$read     = count( $this->before );
		}
	}

	/**
	 * The end of a test: a table whose shape it changed fails it.
	 *
	 * @param Test  $test Test.
	 * @param float $time Its time.
	 * @return void
	 */
	public function endTest( Test $test, float $time ): void {
		if ( ! $this->given ) {
			self::$watching = '';
		}
		$before       = $this->before;
		$this->before = null;
		$now          = $this->now();
		if ( null === $before || null === $now ) {
			return;
		}
		$text = $this->check( $before, $now, 'The test' );
		if ( '' !== $text && null !== $this->result ) {
			$this->result->addFailure( $test, new AssertionFailedError( $text ), 0.0 );
		}
		if ( '' !== $text && $this->restoring() ) {
			// The open suites' starts are the shapes as they were: what the test changed was made again.
			return;
		}
		if ( '' !== $text ) {
			// Listed only: the open suites' ends compare with their starts, and would name the class (and the run) for
			// what the test did.
			foreach ( array_keys( $this->suites ) as $level ) {
				foreach ( self::differences( $before, $now ) as $table => $shape ) {
					if ( null === $shape ) {
						unset( $this->suites[ $level ][ $table ] );
					} else {
						$this->suites[ $level ][ $table ] = $shape;
					}
				}
			}
		}
	}
}
