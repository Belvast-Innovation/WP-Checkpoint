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
 * a network's sites other than the main one (their rows in the sites table, and every table of a site's prefix,
 * "{prefix}{id}_", whether its row is there or not: a test that creates a site with real tables commits it), the swap's
 * maintenance file (and its temporary names) in ABSPATH, storage directories in the test site's
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

	/** The database wp-env gives the tests site, and the host it is on: the only one whose other tables are dropped. */
	const TEST_DATABASE = 'tests-wordpress';
	const TEST_DB_HOST  = 'tests-mysql';

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
	 * Lists what is there: function(): string[] (tables as "table:{name}", a site's tables as "site-table:{name}", sites as
	 * "site:{id}", entries as "temp:{path}"); null while
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
	 * ("table:{name}"), reported and removed with the rest (a test of this class). When it is not given and the lister
	 * is not either, start_cleanup() drops them on the tests database itself.
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
	 * The other tables an earlier run may have left, at the start of the run: the given lister's, or none after
	 * start_cleanup() has dropped them (on wp-env's tests database only).
	 *
	 * @return string[]
	 */
	private function strays(): array {
		if ( null !== $this->strays ) {
			return array_values( (array) call_user_func( $this->strays ) );
		}
		if ( ! $this->given && isset( $GLOBALS['wpdb'] ) ) {
			// Dropped here, on the tests database only, each named before it goes (start_cleanup()); nothing to list after.
			self::start_cleanup(
				$GLOBALS['wpdb'],
				function ( string $text ): void {
					if ( null !== $this->notice ) {
						call_user_func( $this->notice, $text );
					} else {
						fwrite( STDERR, $text );
					}
				}
			);
		}
		return array();
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
						return 0 === strpos( $item, 'table:' ) || 0 === strpos( $item, 'site' ) || 0 === strpos( $item, 'maintenance:' );
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
		// The swap's maintenance file, its data file and their temporary names in ABSPATH (the maintenance file's tests
		// write there: RealAbspath).
		foreach ( RealAbspath::listing() as $path ) {
			$items[] = 'maintenance:' . $path;
		}
		foreach ( self::site_listing( $GLOBALS['wpdb'] ) as $item ) {
			$items[] = $item;
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
	 * A network's sites other than the main one ("site:{id}", from the sites table as this connection sees it) and every
	 * table of a site's prefix ("site-table:{name}", site_tables()); on a single site, only such tables (an earlier
	 * multisite run's). Base tables only: a site a test creates through the core test case has temporary tables, gone
	 * with the connection, and some servers list temporary tables in SHOW TABLES (MariaDB 12), others do not (MySQL).
	 *
	 * @param \wpdb $db Connection.
	 * @return string[]
	 */
	public static function site_listing( \wpdb $db ): array {
		$out  = array();
		$base = array();
		foreach ( (array) $db->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
			if ( isset( $row[0], $row[1] ) && 'BASE TABLE' === $row[1] ) {
				$base[] = (string) $row[0];
			}
		}
		foreach ( self::site_tables( $base, (string) $db->base_prefix ) as $table ) {
			$out[] = 'site-table:' . $table;
		}
		if ( is_multisite() ) {
			foreach ( (array) $db->get_col( $db->prepare( "SELECT blog_id FROM {$db->blogs} WHERE blog_id <> %d", get_main_site_id() ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the sites table.
				$out[] = 'site:' . (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Remove a site a test created with real tables (a test that removes the core test case's temporary-table filters
	 * commits it): its row in the sites table and its base tables, as removal() removes a leftover one (on wp-env's
	 * tests database only). Not wp_delete_site(): that also deletes the site's upload directory, in the test site.
	 *
	 * @param int $id Site id.
	 * @return void
	 */
	public static function remove_site( int $id ): void {
		global $wpdb;
		$tables = 'site-table:' . $wpdb->base_prefix . $id . '_';
		$items  = array();
		foreach ( self::site_listing( $wpdb ) as $item ) {
			if ( 'site:' . $id === $item || 0 === strpos( $item, $tables ) ) {
				$items[] = $item;
			}
		}
		self::removal( $items );
	}

	/**
	 * Of $all, the tables of a site's prefix: "{prefix}{id}_" and anything after it (WordPress's, or a plugin's per site).
	 *
	 * @param string[] $all         Every table in the database.
	 * @param string   $base_prefix The main table prefix.
	 * @return string[]
	 */
	public static function site_tables( array $all, string $base_prefix ): array {
		$site = '/\A' . preg_quote( $base_prefix, '/' ) . '[1-9][0-9]*_./';
		return array_values(
			array_filter(
				$all,
				static function ( string $table ) use ( $site ): bool {
					return 1 === preg_match( $site, $table );
				}
			)
		);
	}

	/**
	 * The tables in the tests database that are neither WordPress's nor this plugin's own (stray_tables()).
	 *
	 * @return string[] As "table:{name}".
	 */
	public static function stray_listing( \wpdb $db ): array {
		$all = array_map( 'strval', (array) $db->get_col( 'SHOW TABLES' ) );
		$out = array();
		foreach ( self::stray_tables( $all, (string) $db->base_prefix, array_values( $db->tables( 'global', false ) ), array_values( $db->tables( 'blog', false ) ) ) as $table ) {
			$out[] = 'table:' . $table;
		}
		return $out;
	}

	/**
	 * Why the tables of this database must not be dropped as an earlier run's, '' when they may: the connection's
	 * database and the one the server says it uses must both be wp-env's tests database, on its host (any port).
	 *
	 * @param string $name   The connection's database (wpdb's dbname).
	 * @param string $server The database the server says the connection uses (SELECT DATABASE()).
	 * @param string $host   The connection's host (wpdb's dbhost).
	 * @return string
	 */
	public static function database_refusal( string $name, string $server, string $host ): string {
		$bare = (string) preg_replace( '/:.*\z/s', '', $host );
		if ( self::TEST_DATABASE !== $name ) {
			return sprintf( 'the connection is to the database "%1$s", not to wp-env\'s tests database "%2$s"', $name, self::TEST_DATABASE );
		}
		if ( self::TEST_DATABASE !== $server ) {
			return sprintf( 'the server says the connection uses the database "%1$s", not "%2$s"', $server, self::TEST_DATABASE );
		}
		if ( self::TEST_DB_HOST !== $bare ) {
			return sprintf( 'the connection is to the host "%1$s", not to wp-env\'s tests database server "%2$s"', $host, self::TEST_DB_HOST );
		}
		return '';
	}

	/**
	 * At the start of a run: drop the tables an earlier run left that are neither WordPress's nor this plugin's
	 * (stray_listing()), on wp-env's tests database only (database_refusal(): anywhere else nothing is dropped and the
	 * reason is said). Their names are said (through $say) before the first is dropped.
	 *
	 * @param \wpdb    $db  Connection.
	 * @param callable $say function( string ): void.
	 * @return string[] The tables dropped.
	 */
	public static function start_cleanup( \wpdb $db, callable $say ): array {
		$refusal = self::database_refusal( (string) $db->dbname, (string) $db->get_var( 'SELECT DATABASE()' ), (string) $db->dbhost );
		if ( '' !== $refusal ) {
			$say( "\nAn earlier run's other tables are not looked for: {$refusal}. Nothing was dropped.\n" );
			return array();
		}
		$tables = array_map(
			static function ( string $item ): string {
				return substr( $item, 6 );
			},
			self::stray_listing( $db )
		);
		if ( array() === $tables ) {
			return array();
		}
		$say( "\nDropping the tables an earlier run left that are neither WordPress's nor this plugin's:\n  " . implode( "\n  ", $tables ) . "\n" );
		mysqli_query( $db->dbh, 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $tables as $table ) {
			mysqli_query( $db->dbh, 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $table ) . '`' );
		}
		mysqli_query( $db->dbh, 'SET FOREIGN_KEY_CHECKS=1' );
		return $tables;
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
	 * TEMPORARY TABLE while a test runs) and remove the entries (Sandbox::remove()). A site's tables and its row in the
	 * sites table look like any site's: they go on wp-env's tests database only (database_refusal(); anywhere else they
	 * are left and the reason is said).
	 *
	 * @param string[] $items Items as listing() names them.
	 * @return void
	 */
	public static function removal( array $items ): void {
		global $wpdb;
		$dbh   = $wpdb->dbh;
		$sites = array_values(
			array_filter(
				$items,
				static function ( string $item ): bool {
					return 0 === strpos( $item, 'site' );
				}
			)
		);
		if ( array() !== $sites ) {
			$refusal = self::database_refusal( (string) $wpdb->dbname, (string) $wpdb->get_var( 'SELECT DATABASE()' ), (string) $wpdb->dbhost );
			if ( '' !== $refusal ) {
				fwrite( STDERR, "\nThe sites and site tables listed are not removed: {$refusal}.\n" );
				$items = array_values( array_diff( $items, $sites ) );
			}
		}
		mysqli_query( $dbh, 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $items as $item ) {
			if ( 0 === strpos( $item, 'table:' ) || 0 === strpos( $item, 'site-table:' ) ) {
				mysqli_query( $dbh, 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', substr( $item, strpos( $item, ':' ) + 1 ) ) . '`' );
			} elseif ( 0 === strpos( $item, 'site:' ) ) {
				// Its row and its meta; committed at once (after a test the connection is left with autocommit off).
				$id = (int) substr( $item, 5 );
				mysqli_query( $dbh, 'DELETE FROM `' . str_replace( '`', '``', $wpdb->blogs ) . '` WHERE blog_id = ' . $id );
				if ( '' !== (string) $wpdb->blogmeta ) {
					mysqli_query( $dbh, 'DELETE FROM `' . str_replace( '`', '``', $wpdb->blogmeta ) . '` WHERE blog_id = ' . $id );
				}
				mysqli_query( $dbh, 'COMMIT' );
				clean_blog_cache( $id );
			} elseif ( 0 === strpos( $item, 'maintenance:' ) ) {
				RealAbspath::clear( substr( $item, 12 ) ); // Only what this plugin writes; anything else is reported.
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
