<?php
/**
 * The restored site's check of itself after the swap: the row the check answers from, and the route's guard.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Support\StoredNames;

defined( 'ABSPATH' ) || exit;

/**
 * While a restore checks the site it swapped in, a row in that site's options table (the main site's on a network:
 * table()) under StoredNames::SELF_CHECK holds the job's id, a one-time value and the SHA-256 of a pass key. The
 * self-check route (Rest\SelfCheckController) lets through only a request whose pass header hashes to that hash
 * (passes()), and answers a challenge with HMAC-SHA256 keyed by the one-time value (answer()): an answer that
 * matches says the PHP that served the request read the table the swap put in place. The row is written and
 * deleted by the run that holds the job, in its fenced transaction (put(), remove()); the route reads it with plain
 * SQL, never through an object cache (stored()), and the run that checks keeps the one-time value it wrote (read()
 * reads it back on the restore's connection, for tests).
 */
final class SelfCheck {

	/**
	 * The route, under the plugin's namespace.
	 */
	const ROUTE = 'self-check';
	const PATH  = '/wp-checkpoint/v1/self-check';

	/**
	 * The request header that carries the pass key.
	 */
	const HEADER = 'X-WPCheckpoint-Pass';

	/**
	 * The form of a one-time value, a pass key, its hash and a challenge: 64 lowercase hex digits.
	 */
	const HEX = '/\A[0-9a-f]{64}\z/';

	/**
	 * A new value of HEX's form.
	 *
	 * @return string
	 */
	public static function random(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * The row's value.
	 *
	 * @param int    $job   Job id.
	 * @param string $nonce The one-time value (HEX).
	 * @param string $pass  The SHA-256 of the pass key (HEX).
	 * @return string
	 * @throws \InvalidArgumentException When a value is not of its form.
	 */
	public static function value( int $job, string $nonce, string $pass ): string {
		if ( $job < 1 || 1 !== preg_match( self::HEX, $nonce ) || 1 !== preg_match( self::HEX, $pass ) ) {
			throw new \InvalidArgumentException( 'Not a self-check row.' );
		}
		return $job . ':' . $nonce . ':' . $pass;
	}

	/**
	 * The row's value read back, or null when it is not one value() writes.
	 *
	 * @param string $value Stored value.
	 * @return array{job: int, nonce: string, pass: string}|null
	 */
	public static function parse( string $value ) {
		if ( 1 !== preg_match( '/\A([1-9][0-9]{0,18}):([0-9a-f]{64}):([0-9a-f]{64})\z/', $value, $m ) ) {
			return null;
		}
		return array(
			'job'   => (int) $m[1],
			'nonce' => $m[2],
			'pass'  => $m[3],
		);
	}

	/**
	 * Whether a pass header is the key whose hash the row holds: of HEX's form, and its SHA-256 equal to the hash
	 * (compared in constant time over two strings of the same length).
	 *
	 * @param string $header The header's value.
	 * @param string $pass   The hash the row holds.
	 * @return bool
	 */
	public static function passes( string $header, string $pass ): bool {
		return 1 === preg_match( self::HEX, $header ) && 1 === preg_match( self::HEX, $pass ) && hash_equals( $pass, hash( 'sha256', $header ) );
	}

	/**
	 * The answer to a challenge.
	 *
	 * @param string $nonce     The one-time value.
	 * @param string $challenge The challenge.
	 * @return string
	 */
	public static function answer( string $nonce, string $challenge ): string {
		return hash_hmac( 'sha256', $challenge, $nonce );
	}

	/**
	 * The options table the row is in: the site's, or on a network the main site's.
	 *
	 * @param string $base_prefix The base table prefix.
	 * @return string
	 */
	public static function table( string $base_prefix ): string {
		return $base_prefix . 'options';
	}

	/**
	 * What a backup never holds of it: the row, by its name in the main site's options table (TableExporter's
	 * exclude_rows). A restore that failed while it checked, and was not tried again, leaves the row; no backup made
	 * after that carries it.
	 *
	 * @return array<string, array{column: string, values: string[]}>
	 */
	public static function left_out(): array {
		global $wpdb;
		return array(
			self::table( (string) $wpdb->base_prefix ) => array(
				'column' => 'option_name',
				'values' => array( StoredNames::SELF_CHECK ),
			),
		);
	}

	/**
	 * Write the row (every row of its name deleted first), in the fenced transaction.
	 *
	 * @param Queries                                      $db    The restore's connection.
	 * @param array{table: string, id: int, token: string} $fence The job row and the run's token.
	 * @param string                                       $table The options table (table()).
	 * @param string                                       $value value().
	 * @return void
	 * @throws \WPCheckpoint\Jobs\LockLost When the job is no longer this run's: nothing is written.
	 */
	public static function put( Queries $db, array $fence, string $table, string $value ): void {
		JobFence::within(
			$db,
			$fence,
			static function () use ( $db, $table, $value ): void {
				JobFence::assert_locked( $db );
				// Every row of the name first, then one: an options table without its unique key on option_name (a
				// damaged or migrated site's) would otherwise gain a row per try, and the route read the first.
				$db->write( 'DELETE FROM ' . SqlWriter::identifier( $table ) . ' WHERE option_name IN (?)', array( StoredNames::SELF_CHECK ) );
				$db->write( 'INSERT INTO ' . SqlWriter::identifier( $table ) . ' (option_name, option_value, autoload) VALUES (?, ?, ?)', array( StoredNames::SELF_CHECK, $value, 'no' ) );
			}
		);
	}

	/**
	 * Delete the row, in the fenced transaction; none there is done.
	 *
	 * @param Queries                                      $db    The restore's connection.
	 * @param array{table: string, id: int, token: string} $fence The job row and the run's token.
	 * @param string                                       $table The options table (table()).
	 * @return void
	 * @throws \WPCheckpoint\Jobs\LockLost When the job is no longer this run's: nothing is deleted.
	 */
	public static function remove( Queries $db, array $fence, string $table ): void {
		JobFence::within(
			$db,
			$fence,
			static function () use ( $db, $table ): void {
				JobFence::assert_locked( $db );
				$db->write( 'DELETE FROM ' . SqlWriter::identifier( $table ) . ' WHERE option_name = ?', array( StoredNames::SELF_CHECK ) );
			}
		);
	}

	/**
	 * The row read back with plain SQL on the restore's connection, or null when there is none (or it is not one
	 * value() writes).
	 *
	 * @param Queries $db    Connection.
	 * @param string  $table The options table (table()).
	 * @return array{job: int, nonce: string, pass: string}|null
	 */
	public static function read( Queries $db, string $table ) {
		$rows = $db->rows( 'SELECT option_value FROM ' . SqlWriter::identifier( $table ) . ' WHERE option_name = ?', array( StoredNames::SELF_CHECK ) );
		return isset( $rows[0][0] ) ? self::parse( (string) $rows[0][0] ) : null;
	}

	/**
	 * The row read with plain SQL in a web request (WordPress's connection, no object cache), or null.
	 *
	 * @return array{job: int, nonce: string, pass: string}|null
	 */
	public static function stored() {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM ' . SqlWriter::identifier( self::table( $wpdb->base_prefix ) ) . ' WHERE option_name = %s', StoredNames::SELF_CHECK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- read past any cache on purpose; the table name from the prefix.
		return is_string( $value ) ? self::parse( $value ) : null;
	}
}
