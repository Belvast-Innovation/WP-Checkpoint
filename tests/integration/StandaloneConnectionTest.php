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
}
