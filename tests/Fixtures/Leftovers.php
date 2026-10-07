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
 * What the integration tests leave behind: the restore's tables ("wcptmp", "wcpold", "wcpstray") in the database,
 * the swap's maintenance file (and its temporary names) in ABSPATH, storage directories in the test site's
 * wp-content (wp-checkpoint-*, other than the one the stored state names), and this plugin's and its tests' entries in
 * the run's own temporary directory. A test that leaves one fails, named, and what it left is removed so the next test
 * starts clean; a test class that leaves one in its class-level set-up fails as a class; and the run fails when any is
 * left at its end. What an earlier run left is reported when the run starts: its tables are removed (the tests database
 * is taken to be this run's while it runs), the restore's and every other table that is neither WordPress's (the main
 * site's and every site's of a network) nor this plugin's own (a test's fixture table, swt_* among them, left by a run
 * that was killed); anything else is left alone.
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

	/**
	 * Lists the other tables an earlier run may have left, read at the start of the run only: function(): string[]
	 * ("table:{name}"); stray_listing() when the lister is not given, none when it is and this is not.
	 *
	 * @var callable|null
	 */
	private $strays;

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
	 * @param callable|null $strays  function(): string[]; the tests database's other tables when null (and the lister
	 *                               is not given).
	 */
	public function __construct( $lister = null, $remover = null, $notice = null, $strays = null ) {
		$this->lister  = $lister;
		$this->remover = $remover;
		$this->notice  = $notice;
		$this->strays  = $strays;
		$this->given   = null !== $lister;
	}

	/**
	 * The other tables an earlier run may have left (stray_listing()), at the start of the run.
	 *
	 * @return string[]
	 */
	private function strays(): array {
		if ( null !== $this->strays ) {
			return array_values( (array) call_user_func( $this->strays ) );
		}
		return $this->given ? array() : self::stray_listing();
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
		if ( array() === $this->suites ) {
			$now = array_values( array_unique( array_merge( $now, $this->strays() ) ) );
			sort( $now );
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
						// Also the swap's maintenance file: removal() deletes only this plugin's (a held one would keep
						// the test site in maintenance for the whole run), and reports anything else.
						return 0 === strpos( $item, 'table:' ) || 0 === strpos( $item, 'maintenance:' );
					}
				)
			);
			$stale  = (string) getenv( 'WPCHECKPOINT_TEST_STALE_RUN_ID' );
			$which  = '' === $stale ? 'an earlier run' : 'an earlier run (' . $stale . ', which ended without releasing the lock)';
			$text   = "\nLeft by {$which} (tables removed, anything else left alone):\n  " . implode( "\n  ", $now ) . "\n";
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
		// The swap's maintenance file and its temporary names in ABSPATH (the maintenance mode tests write there:
		// wp_is_maintenance_mode() reads that file only).
		if ( defined( 'ABSPATH' ) ) {
			foreach ( (array) @scandir( ABSPATH ) as $entry ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test fixture.
				if ( '.maintenance' === $entry || 1 === preg_match( \WPCheckpoint\Jobs\Residue::MAINTENANCE_TMP_NAME, (string) $entry ) ) {
					$items[] = 'maintenance:' . rtrim( ABSPATH, '/' ) . '/' . $entry;
				}
			}
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
		// Storage directories in the test site's wp-content, other than the one the stored state names (the plugin's
		// own, kept from test to test): one a test left (a deletion stopped part way takes the owner marker first).
		foreach ( StorageDirs::listing() as $dir ) {
			$items[] = 'storage:' . $dir;
		}
		sort( $items );
		return $items;
	}

	/**
	 * The tables in the tests database that are neither WordPress's nor this plugin's own (stray_tables()).
	 *
	 * @return string[] As "table:{name}".
	 */
	public static function stray_listing(): array {
		global $wpdb;
		$all = array_map( 'strval', (array) $wpdb->get_col( 'SHOW TABLES' ) );
		$out = array();
		foreach ( self::stray_tables( $all, (string) $wpdb->base_prefix, array_values( $wpdb->tables( 'global', false ) ), array_values( $wpdb->tables( 'blog', false ) ) ) as $table ) {
			$out[] = 'table:' . $table;
		}
		return $out;
	}

	/**
	 * Of $all, the tables that are neither WordPress's (a global table of the main prefix, a site's table of the main
	 * prefix or of "{prefix}{site id}_"), this plugin's own (OwnTables::is_own(), of any installation), nor the
	 * restore's that tables() lists already.
	 *
	 * @param string[] $all         Every table in the database.
	 * @param string   $base_prefix The main table prefix.
	 * @param string[] $global      WordPress's global tables, without the prefix.
	 * @param string[] $blog        WordPress's tables of a site, without the prefix.
	 * @return string[]
	 */
	public static function stray_tables( array $all, string $base_prefix, array $global, array $blog ): array {
		$known = array();
		foreach ( array_merge( $global, $blog ) as $name ) {
			$known[ $base_prefix . $name ] = true;
		}
		$site  = '/\A' . preg_quote( $base_prefix, '/' ) . '[1-9][0-9]*_(?:' . implode( '|', array_map( static function ( string $name ): string {
			return preg_quote( $name, '/' );
		}, $blog ) ) . ')\z/';
		$out = array();
		foreach ( $all as $table ) {
			if ( isset( $known[ $table ] ) || 1 === preg_match( $site, $table ) || \WPCheckpoint\Database\OwnTables::is_own( $table ) ) {
				continue;
			}
			foreach ( self::TABLE_PREFIXES as $prefix ) {
				if ( 0 === strpos( $table, $prefix ) ) {
					continue 2; // Listed by tables().
				}
			}
			$out[] = $table;
		}
		return $out;
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
			} elseif ( 0 === strpos( $item, 'maintenance:' ) ) {
				$path = substr( $item, 12 );
				$text = (string) @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test fixture.
				// Only what this plugin writes: a restore's mark (or a temporary name of one); anything else is reported.
				if ( '.maintenance' !== basename( $path ) || 1 === preg_match( '/\/\/ WP Checkpoint restore [0-9a-f]{32}\n\z/', $text ) ) {
					\WPCheckpoint\Support\Deleter::delete_maintenance_file( dirname( $path ), basename( $path ) );
				} else {
					fwrite( STDERR, "\nA maintenance file that is not this plugin's was left in place: {$path}\n" );
				}
			} elseif ( 0 === strpos( $item, 'storage:' ) ) {
				StorageDirs::remove( array( substr( $item, 8 ) ) ); // A storage directory a test left in the site's wp-content.
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
