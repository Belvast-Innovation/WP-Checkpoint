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
 * plugin's and its tests' entries in the run's own temporary directory. A test that leaves one fails, named, and what
 * it left is removed so the next test starts clean; a test class that leaves one in its class-level set-up fails as a
 * class; and the run fails when any is left at its end. What an earlier run left is reported when the run starts; its
 * tables are removed (the tests database is taken to be this run's while it runs), anything else is left alone.
 *
 * The temporary directory is looked at only when it is the run's own (bin/test-integration.sh gives each run one and
 * names it in WPCHECKPOINT_TEST_RUN_TMP): in a directory other runs share, their entries would be blamed on this
 * run's tests and removed under them. Failures of a class or of the run are reported through a stand-in test named
 * after them, so every result printer and log records them like a test's.
 *
 * Registered in phpunit.xml.dist; does nothing in the unit suite.
 */
final class Leftovers implements TestListener {
	use TestListenerDefaultImplementation;

	/** The table names looked for, by their start. */
	const TABLE_PREFIXES = array( 'wcptmp', 'wcpold', 'wcpstray' );

	/** The temporary directory's entries looked for, by their start. */
	const TEMP_PREFIXES = array( 'wpc-', 'wpcheckpoint-', 'wp-checkpoint-' );

	/**
	 * The test the registered listener is watching now ("Class::method"), '' when none: the control that the check
	 * runs at all (LeftoversCheckTest).
	 *
	 * @var string
	 */
	private static $watching = '';

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

	/** @var TestResult|null The run's result, for failures of a class or of the run. */
	private $result = null;

	/**
	 * Constructor.
	 *
	 * @param callable|null $lister  function(): string[]; the database and the run's temporary directory when null.
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
	 * The test the registered listener is watching now, '' when none.
	 *
	 * @return string
	 */
	public static function watching(): string {
		return self::$watching;
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
	 * Report a failure of a class or of the run through a stand-in test named after it.
	 *
	 * @param string $name What failed.
	 * @param string $text Why.
	 * @return void
	 */
	private function fail_as( string $name, string $text ): void {
		if ( null === $this->result ) {
			return;
		}
		// Its start and end reach this listener too, and find nothing new: what is left is removed after.
		$stand_in = new LeftoversStandIn( $name );
		$this->result->startTest( $stand_in );
		$this->result->addFailure( $stand_in, new AssertionFailedError( $text ), 0.0 );
		$this->result->endTest( $stand_in, 0.0 );
	}

	/**
	 * The start of a suite: the outermost one reports what an earlier run left (and leaves it: not this run's).
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
			// The tables go: they carry no site's prefix, and the tests database is taken to be this run's while it
			// runs (a second run would collide on the core library's reinstall of its tables anyway; the site that
			// shares the database, tests-wordpress, is not restoring meanwhile). Temporary entries stay: another run's
			// directory is never this run's to empty.
			$tables = array_values(
				array_filter(
					$now,
					static function ( string $item ): bool {
						return 0 === strpos( $item, 'table:' );
					}
				)
			);
			$text   = "\nLeft by an earlier run (tables removed, anything else left alone):\n  " . implode( "\n  ", $now ) . "\n";
			if ( null !== $this->notice ) {
				call_user_func( $this->notice, $text );
			} else {
				fwrite( STDERR, $text );
			}
			if ( array() !== $tables ) {
				$this->remove( $tables );
				$now = (array) $this->now();
			}
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
		if ( array() !== $left ) {
			$this->fail_as( 'leftovers of ' . $suite->getName(), sprintf( "%s left behind (removed now):\n  %s", $suite->getName(), implode( "\n  ", $left ) ) );
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
		if ( null !== $this->before && ! $this->given && $test instanceof TestCase ) {
			self::$watching = get_class( $test ) . '::' . $test->getName( false );
		}
	}

	/**
	 * The end of a test: what it left fails it, and is removed.
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
		$left = array_values( array_diff( $now, $before ) );
		if ( array() === $left ) {
			return;
		}
		if ( null !== $this->result ) {
			// No time of its own: the test's was counted when it ended.
			$this->result->addFailure( $test, new AssertionFailedError( "The test left behind (removed now):\n  " . implode( "\n  ", $left ) ), 0.0 );
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
	 * The run's own temporary directory: sys_get_temp_dir() when it is the one bin/test-integration.sh made for this
	 * run, else '' (a directory other runs share is not looked at).
	 *
	 * @return string
	 */
	public static function run_temp_dir(): string {
		$own = (string) getenv( 'WPCHECKPOINT_TEST_RUN_TMP' );
		$dir = realpath( sys_get_temp_dir() );
		if ( '' === $own || false === $dir || realpath( $own ) !== $dir ) {
			return '';
		}
		return $dir;
	}

	/**
	 * The restore's tables in the database and the entries in the run's own temporary directory.
	 *
	 * @return string[]
	 */
	public static function listing(): array {
		$items = array();
		foreach ( self::tables() as $table ) {
			$items[] = 'table:' . $table;
		}
		$dir = self::run_temp_dir();
		if ( '' !== $dir ) {
			foreach ( (array) scandir( $dir ) as $entry ) {
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
