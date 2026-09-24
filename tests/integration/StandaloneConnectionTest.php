<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Standalone\Connection;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;

/**
 * Connecting without WordPress, on the real database server: the settings
 * of the WordPress under test connect; wrong ones fail by category with
 * messages that name nothing; DB_HOST is split as wpdb splits it; the
 * database is checked by what is in it.
 */
final class StandaloneConnectionTest extends WP_UnitTestCase {

	/**
	 * The test WordPress's settings, with some replaced.
	 *
	 * @param array<string, string> $changes Values to replace.
	 * @return Credentials
	 */
	private static function settings( array $changes = array() ): Credentials {
		global $table_prefix;
		return Credentials::from_values(
			array_merge(
				array(
					'name'     => DB_NAME,
					'user'     => DB_USER,
					'password' => DB_PASSWORD,
					'host'     => DB_HOST,
					'charset'  => defined( 'DB_CHARSET' ) ? DB_CHARSET : '',
					'collate'  => defined( 'DB_COLLATE' ) ? DB_COLLATE : '',
					'prefix'   => $table_prefix,
				),
				$changes
			)
		);
	}

	public function test_the_settings_of_wordpress_connect_and_the_database_is_checked_by_its_content(): void {
		$connection = Connection::open( self::settings() );
		$this->assertTrue( $connection->table_exists( 'options' ) );
		$this->assertTrue( $connection->row_exists( 'options', 'option_name', 'siteurl' ) );
		$this->assertFalse( $connection->row_exists( 'options', 'option_name', 'no-such-option-' . wp_generate_password( 8, false ) ) );
		$this->assertFalse( $connection->table_exists( 'no_such_table' ) );
		$this->assertFalse( $connection->row_exists( 'no_such_table', 'id', '1' ), 'a missing table has no rows' );
		$other = Connection::open( self::settings( array( 'prefix' => 'wrongprefix_' ) ) );
		$this->assertFalse( $other->table_exists( 'options' ), 'the same database under another prefix proves nothing' );
		$connection->close();
		$other->close();
		$this->assertSame( Credentials::from_wordpress()->get( 'name' ), DB_NAME, 'WordPress\'s own settings, when it is loaded' );
	}

	public function test_wrong_settings_fail_by_category_and_name_nothing(): void {
		$cases = array(
			Failure::ACCESS_DENIED    => array( 'password' => 'MARKER_PASSWORD_' . wp_generate_password( 8, false ) ),
			Failure::UNKNOWN_DATABASE => array( 'name' => 'marker_db_' . strtolower( wp_generate_password( 8, false ) ) ),
			Failure::UNREACHABLE      => array( 'host' => '127.0.0.1:1' ),
		);
		foreach ( $cases as $reason => $changes ) {
			$settings = self::settings( $changes );
			// The control: the changed value is what the connection is given.
			$this->assertSame( reset( $changes ), $settings->get( (string) key( $changes ) ) );
			try {
				Connection::open( $settings );
				$this->fail( $reason . ': connected' );
			} catch ( Failure $e ) {
				$this->assertSame( $reason, $e->reason() );
				foreach ( array( reset( $changes ), DB_USER, DB_NAME, DB_HOST, '127.0.0.1', '@' ) as $value ) {
					$this->assertStringNotContainsString( (string) $value, $e->getMessage(), $reason );
				}
			}
		}
	}

	public function test_db_host_is_split_as_wpdb_splits_it(): void {
		global $wpdb;
		foreach ( array( 'localhost', 'localhost:3307', '127.0.0.1', '127.0.0.1:3306', 'localhost:/tmp/mysql.sock', 'db.example:3306:/var/run/m.sock', '::1', '[::1]:3306', '[fe80::1]', 'mysql', ':3306' ) as $host ) {
			$expected = $wpdb->parse_db_host( $host );
			$this->assertSame( false === $expected ? null : $expected, Connection::parse_host( $host ), $host );
		}
	}
	public function test_an_unreachable_host_gives_up_within_the_timeout(): void {
		$start = microtime( true );
		try {
			Connection::open( self::settings( array( 'host' => '10.255.255.1:3306' ) ), 2 );
			$this->fail( 'connected to a host that does not answer' );
		} catch ( Failure $e ) {
			$this->assertContains( $e->reason(), array( Failure::UNREACHABLE, Failure::OTHER ) );
		}
		$this->assertLessThan( 6, microtime( true ) - $start, 'bounded by the connect timeout, not by PHP\'s socket timeout' );
	}

	public function test_mysqli_settings_and_error_handlers_are_left_as_they_were(): void {
		$driver = new \mysqli_driver();
		$before = $driver->report_mode;
		mysqli_report( MYSQLI_REPORT_ERROR );
		$seen = array();
		set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- observing what reaches another handler.
			static function ( int $errno, string $message ) use ( &$seen ): bool {
				$seen[] = $message;
				return true;
			}
		);
		try {
			try {
				Connection::open( self::settings( array( 'host' => 'marker-host.invalid' ) ), 2 );
			} catch ( Failure $e ) {
				$this->assertSame( Failure::UNREACHABLE, $e->reason() );
			}
			$this->assertSame( array(), $seen, 'no mysqli warning (it would name the host) reached another handler' );
			$this->assertSame( MYSQLI_REPORT_ERROR, $driver->report_mode, 'the report mode is the caller\'s again' );
			trigger_error( 'after', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- the control.
			$this->assertSame( array( 'after' ), $seen, 'the control: the caller\'s handler is active again and observes warnings' );
		} finally {
			restore_error_handler();
			mysqli_report( $before );
		}
	}
}
