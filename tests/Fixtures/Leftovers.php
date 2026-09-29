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
 * What the integration tests leave behind: the restore's tables ("wcptmp", "wcpold") in the database, and this
 * plugin's and its tests' entries in the temporary directory. A test that leaves one fails, named, and what it left is
 * removed so the next test starts clean; a test class that leaves one in its class-level set-up fails as a class; and
 * the suite ends with none. Leftovers of an earlier run are reported and removed when the suite starts.
 *
 * Registered in phpunit.xml.dist; does nothing in the unit suite.
 */
final class Leftovers implements TestListener {
	use TestListenerDefaultImplementation;

	/** The table names looked for, by their start. */
	const TABLE_PREFIXES = array( 'wcptmp', 'wcpold' );

	/** The temporary directory's entries looked for, by their start. */
	const TEMP_PREFIXES = array( 'wpc-', 'wpcheckpoint-', 'wp-checkpoint-' );

	/**
	 * Lists what is there: function(): string[] (tables as "table:{name}", entries as "temp:{path}"); null while
	 * the suite is not the integration suite.
	 *
	 * @var callable|null
	 */
	private $lister;

	/**
	 * Removes what a test left: function( string[] $items ): void.
	 *
	 * @var callable|null
	 */
	private $remover;

	/**
	 * Writes a notice: function( string ): void.
	 *
	 * @var callable|null
	 */
	private $notice;

	/** @var bool Whether the lister and remover were given (a test of this class). */
	private $given;

	/** @var array<int, string[]> What was there when each open suite started, innermost last. */
	private $suites = array();

	/** @var string[]|null What was there when the running test started. */
	private $before = null;

	/** @var TestResult|null The run's result, for failures of a class or of the suite. */
	private $result = null;

	/**
	 * Constructor.
	 *
	 * @param callable|null $lister  function(): string[]; the database and the temporary directory when null.
	 * @param callable|null $remover function( string[] ): void; drops and removes when null.
	 * @param callable|null $notice  function( string ): void; standard error when null.
	 */
	public function __construct( $lister = null, $remover = null, $notice = null ) {
		$this->lister  = $lister;
		$this->remover = $remover;
		$this->notice  = $notice;
		$this->given   = null !== $lister;
	}

	/**
	 * What is there now, or null outside the integration suite.
	 *
	 * @return string[]|null
	 */
	private function now(): ?array {
		if ( $this->given ) {
			return array_values( (array) call_user_func( $this->lister ) );
		}
		if ( 'integration' !== getenv( 'WPCHECKPOINT_TEST_SUITE' ) || ! isset( $GLOBALS['wpdb'] ) ) {
			return null;
		}
		return self::listing();
	}

	/**
	 * Remove items.
	 *
	 * @param string[] $items Items as now() names them.
	 * @return void
	 */
	private function remove( array $items ): void {
		if ( $this->given ) {
			if ( null !== $this->remover ) {
				call_user_func( $this->remover, $items );
			}
			return;
		}
		self::removal( $items );
	}

	/**
	 * The start of a suite: the outermost one reports and removes what an earlier run left.
	 *
	 * @param TestSuite $suite Suite.
	 * @return void
	 */
	public function startTestSuite( TestSuite $suite ): void {
		$now = $this->now();
		if ( null === $now ) {
			return;
		}
		if ( array() === $this->suites && array() !== $now ) {
			$text = "\nLeft by an earlier run, removed before this one:\n  " . implode( "\n  ", $now ) . "\n";
			if ( null !== $this->notice ) {
				call_user_func( $this->notice, $text );
			} else {
				fwrite( STDERR, $text );
			}
			$this->remove( $now );
			$now = (array) $this->now();
		}
		$this->suites[] = $now;
	}

	/**
	 * The end of a suite: what is there that was not when it started fails it (a test's leftovers were removed at
	 * its end, so these are its class-level set-up's, or, for the outermost suite, the run's).
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
		$left  = array_values( array_diff( $now, $start ) );
		if ( array() !== $left && null !== $this->result ) {
			$this->result->addFailure( $suite, new AssertionFailedError( sprintf( '%s left behind (removed now):' . "\n  %s", $suite->getName(), implode( "\n  ", $left ) ) ), 0.0 );
			$this->remove( $left );
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
	}

	/**
	 * The end of a test: what it left fails it, and is removed.
	 *
	 * @param Test  $test Test.
	 * @param float $time Its time.
	 * @return void
	 */
	public function endTest( Test $test, float $time ): void {
		$before       = $this->before;
		$this->before = null;
		$now          = $this->now();
		if ( null === $before || null === $now ) {
			return;
		}
		$left = array_values( array_diff( $now, $before ) );
		if ( array() === $left ) {
			return;
		}
		if ( null !== $this->result ) {
			$this->result->addFailure( $test, new AssertionFailedError( "The test left behind (removed now):\n  " . implode( "\n  ", $left ) ), $time );
		}
		$this->remove( $left );
	}

	/**
	 * The restore's tables in the database ("wcptmp", "wcpold").
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		global $wpdb;
		$tables = array();
		foreach ( self::TABLE_PREFIXES as $prefix ) {
			$tables = array_merge( $tables, (array) $wpdb->get_col( "SHOW TABLES LIKE '{$prefix}%'" ) );
		}
		return $tables;
	}

	/**
	 * The restore's tables in the database and the entries in the temporary directories.
	 *
	 * @return string[]
	 */
	public static function listing(): array {
		$items = array();
		foreach ( self::tables() as $table ) {
			$items[] = 'table:' . $table;
		}
		$dirs = array( rtrim( sys_get_temp_dir(), '/\\' ) );
		if ( function_exists( 'get_temp_dir' ) ) {
			$dirs[] = rtrim( get_temp_dir(), '/\\' );
		}
		$cwd = realpath( (string) getcwd() ); // The run's own (bin/test-integration.sh), not a leftover.
		foreach ( array_unique( $dirs ) as $dir ) {
			foreach ( (array) scandir( $dir ) as $entry ) {
				if ( false !== $cwd && realpath( $dir . '/' . $entry ) === $cwd ) {
					continue;
				}
				foreach ( self::TEMP_PREFIXES as $prefix ) {
					if ( 0 === strpos( (string) $entry, $prefix ) ) {
						$items[] = 'temp:' . $dir . '/' . $entry;
						break;
					}
				}
			}
		}
		sort( $items );
		return $items;
	}

	/**
	 * Drop the tables (on the connection itself: the core test case turns a DROP TABLE through wpdb into DROP
	 * TEMPORARY TABLE while a test runs) and remove the entries (Sandbox::remove()).
	 *
	 * @param string[] $items Items as listing() names them.
	 * @return void
	 */
	public static function removal( array $items ): void {
		global $wpdb;
		$dbh = $wpdb->dbh;
		mysqli_query( $dbh, 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $items as $item ) {
			if ( 0 === strpos( $item, 'table:' ) ) {
				mysqli_query( $dbh, 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', substr( $item, 6 ) ) . '`' );
			} elseif ( 0 === strpos( $item, 'temp:' ) ) {
				try {
					Sandbox::remove( substr( $item, 5 ) );
				} catch ( \LogicException $e ) {
					fwrite( STDERR, "\n" . $e->getMessage() . "\n" );
				}
			}
		}
		mysqli_query( $dbh, 'SET FOREIGN_KEY_CHECKS=1' );
	}
}
