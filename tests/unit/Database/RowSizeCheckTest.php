<?php

namespace WPCheckpoint\Tests\Unit\Database;

use WPCheckpoint\Database\RowSizeCheck;
use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Tests\Fixtures\Database\FakeConnection;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The pre-flight's row size check over the in-memory connection, and the
 * agreement between the PHP estimate and the SQL predicate on the same
 * rows (the fake evaluates the predicate's terms; the integration test
 * lets MariaDB do it).
 */
final class RowSizeCheckTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-rowsize-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700, true );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
	}

	/**
	 * An options-like table: id, name, value; row $i's value is $sizes[$i] bytes (null for NULL).
	 *
	 * @param array<int, int|null> $sizes Value sizes.
	 */
	private function options_table( FakeConnection $db, array $sizes, string $name = 'wp_options', bool $numeric_pk = true ): void {
		$rows = array();
		foreach ( $sizes as $i => $size ) {
			$rows[] = array( $numeric_pk ? (string) ( $i + 1 ) : 'k' . str_pad( (string) $i, 6, '0', STR_PAD_LEFT ), 'opt_' . $i, null === $size ? null : str_repeat( 'v', $size ) );
		}
		$db->add_table( $name, array( array( 'option_id', $numeric_pk ? 'bigint(20)' : 'varchar(20)' ), array( 'option_name', 'varchar(191)' ), array( 'option_value', 'longtext' ) ), array( 'option_id' ), $rows, $numeric_pk );
	}

	private function check( FakeConnection $db, string $table, array $stats, int $chunk = 65536 ): array {
		$exporter = new TableExporter( $db, $this->dir, $chunk );
		return ( new RowSizeCheck( $db, $exporter ) )->check( $table, $stats );
	}

	public function test_a_small_table_is_counted_exactly_with_the_exporter_predicate(): void {
		$db = new FakeConnection();
		// Limit with a 64 KiB chunk is 61440 bytes as SQL; 60000 bytes of text estimate to about 66000, 50000 to 55000.
		$this->options_table( $db, array( 100, 60000, 50000, null, 60000 ) );
		$result = $this->check( $db, 'wp_options', array( 'rows' => 5, 'data_bytes' => 170100, 'avg_row_bytes' => 34020 ) );
		$this->assertTrue( $result['exact'] );
		$this->assertSame( 2, $result['count'] );
		$this->assertTrue( $result['likely'] );
		$this->assertSame( 61440, $result['limit'] );
		$this->assertMatchesRegularExpression( '/\ASELECT COUNT\(\*\) FROM `wp_options` WHERE \(\(2 \+ CASE WHEN `option_id` IS NULL THEN 5 ELSE CEIL\(LENGTH\(`option_id`\) \* 11 \/ 10\) \+ 4 END \+ CASE WHEN `option_name`.+ END\) > 61440\)\z/', $db->log[ count( $db->log ) - 1 ] );

		$clean = new FakeConnection();
		$this->options_table( $clean, array( 100, 200, null ) );
		$result = $this->check( $clean, 'wp_options', array( 'rows' => 3, 'data_bytes' => 300, 'avg_row_bytes' => 100 ) );
		$this->assertTrue( $result['exact'] );
		$this->assertSame( 0, $result['count'] );
		$this->assertFalse( $result['likely'] );
	}

	public function test_a_large_table_with_a_numeric_key_is_sampled_in_eight_index_ranges(): void {
		$db    = new FakeConnection();
		$sizes = array_fill( 0, 3000, 10 );
		$sizes[2999] = 60000; // Only the last row is oversized.
		$this->options_table( $db, $sizes );
		$db->log = array();
		$result  = $this->check( $db, 'wp_options', array( 'rows' => 3000000, 'data_bytes' => 5 * 1073741824, 'avg_row_bytes' => 100 ) );
		$this->assertFalse( $result['exact'] );
		$this->assertNull( $result['count'], 'a sampled table has no count' );
		$this->assertTrue( $result['likely'], 'a window meets the oversized row' );
		$this->assertContains( 'SELECT MIN(`option_id`), MAX(`option_id`) FROM `wp_options`', $db->log );

		$quiet = new FakeConnection();
		$this->options_table( $quiet, array_fill( 0, 3000, 10 ) );
		$quiet->log = array();
		$result     = $this->check( $quiet, 'wp_options', array( 'rows' => 3000000, 'data_bytes' => 5 * 1073741824, 'avg_row_bytes' => 100 ) );
		$this->assertFalse( $result['likely'] );
		$windows = preg_grep( '/\ASELECT COUNT\(\*\) FROM \(SELECT `option_id`, `option_name`, `option_value` FROM `wp_options` WHERE `option_id` >= \? ORDER BY `option_id` LIMIT 5000\) AS w WHERE \(\(2 \+ CASE/', $quiet->log );
		$this->assertCount( 8, $windows, 'eight windows, each an index range with a LIMIT, no OFFSET anywhere' );
		$this->assertSame( 0, count( preg_grep( '/OFFSET/', $quiet->log ) ) );
		$loud = $this->check( $quiet, 'wp_options', array( 'rows' => 3000000, 'data_bytes' => 5 * 1073741824, 'avg_row_bytes' => 40000 ) );
		$this->assertTrue( $loud['likely'], 'an average row within a factor of two of the limit is a signal by itself' );
	}

	public function test_a_large_table_without_a_numeric_key_samples_the_first_window_only(): void {
		$db    = new FakeConnection();
		$sizes = array_fill( 0, 6000, 10 );
		$sizes[5999] = 60000;
		$this->options_table( $db, $sizes, 'wp_meta', false );
		$db->log = array();
		$result  = $this->check( $db, 'wp_meta', array( 'rows' => 3000000, 'data_bytes' => 5 * 1073741824, 'avg_row_bytes' => 100 ) );
		$this->assertFalse( $result['exact'] );
		$this->assertFalse( $result['likely'], 'the oversized row is beyond the first window; a deep OFFSET would be a scan, so it is not read' );
		$this->assertCount( 1, preg_grep( '/\ASELECT COUNT\(\*\) FROM \(SELECT .+ FROM `wp_meta` ORDER BY `option_id` LIMIT 5000\) AS w WHERE/', $db->log ) );
		$this->assertSame( 0, count( preg_grep( '/OFFSET|MIN\(/', $db->log ) ) );
	}

	public function test_the_php_estimate_and_the_sql_predicate_call_the_same_rows_oversized(): void {
		foreach ( array( 'utf8mb4', 'gbk' ) as $charset ) {
			$db   = new FakeConnection( $charset );
			$rows = array();
			// Two long columns, NULLs, binary data, numbers, borderline sizes around the 61440-byte limit of a 64 KiB chunk.
			foreach ( array( array( 100, 100, 100 ), array( 30000, 30000, 0 ), array( 25000, 25000, 5000 ), array( null, 55000, 0 ), array( 60000, null, null ), array( 0, 0, 27000 ), array( 20000, 20000, 20000 ), array( 55850, 0, 0 ), array( 55840, 0, 0 ) ) as $i => $sizes ) {
				$rows[] = array(
					(string) ( $i + 1 ),
					null === $sizes[0] ? null : str_repeat( 'a', $sizes[0] ),
					null === $sizes[1] ? null : str_repeat( 'b', $sizes[1] ),
					null === $sizes[2] ? null : str_repeat( "\xff", $sizes[2] ),
					(string) ( $i * 1000 ),
				);
			}
			$kinds = array( 'numeric', 'text', 'text', 'binary', 'numeric' );
			$db->add_table( 'wp_mixed', array( array( 'id', 'bigint(20)' ), array( 'a', 'longtext' ), array( 'b', 'longtext' ), array( 'c', 'longblob' ), array( 'n', 'int(11)' ) ), array( 'id' ), $rows );
			$exporter  = new TableExporter( $db, $this->dir, 65536 );
			$by_php    = array();
			foreach ( $rows as $row ) {
				$lengths = array_map( static function ( $v ) {
					return null === $v ? null : strlen( $v );
				}, $row );
				if ( TableExporter::estimate_row_bytes( $lengths, $kinds, $exporter->hex_all() ) > $exporter->row_limit() ) {
					$by_php[] = $row[0];
				}
			}
			$this->assertNotEmpty( $by_php, $charset );
			$this->assertLessThan( count( $rows ), count( $by_php ), $charset );
			$count = ( new RowSizeCheck( $db, $exporter ) )->check( 'wp_mixed', array( 'rows' => 9, 'data_bytes' => 1000, 'avg_row_bytes' => 100 ) )['count'];
			$this->assertSame( count( $by_php ), $count, $charset . ': the predicate counts what the estimate refuses' );
			// And the exclusion leaves out exactly those rows.
			$excluding = new TableExporter( $db, $this->dir, 65536, 8192, array( 'wp_mixed' ) );
			$state     = TableExporter::initial_state( 'wp_mixed' );
			while ( empty( $state['done'] ) ) {
				$state = $excluding->step( $state );
			}
			$sql = '';
			foreach ( glob( $this->dir . '/wp_mixed.*.sql' ) ?: array() as $chunk ) {
				$sql .= (string) file_get_contents( $chunk );
			}
			preg_match_all( '/\((\d+),/', $sql, $m );
			$this->assertSame( array_values( array_diff( array_column( $rows, 0 ), $by_php ) ), $m[1], $charset . ': exported ids' );
			$this->assertSame( count( $rows ) - count( $by_php ), $state['rows'] );
			$this->assertCount( 1, preg_grep( '/rows larger than the single-row limit of 61440 bytes \(as SQL\) were left out, as chosen/', $state['warnings'] ) );
			exec( 'rm -f ' . escapeshellarg( $this->dir ) . '/wp_mixed.*.sql' );
		}
	}

	public function test_without_the_exclusion_an_oversized_row_still_fails_and_null_rows_are_never_dropped(): void {
		$db = new FakeConnection();
		$this->options_table( $db, array( 10, null, 60000 ) );
		$plain = new TableExporter( $db, $this->dir, 65536 );
		try {
			$state = TableExporter::initial_state( 'wp_options' );
			while ( empty( $state['done'] ) ) {
				$state = $plain->step( $state );
			}
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'primary key ["3"]', $e->getMessage() );
		}
		$excluding = new TableExporter( $db, $this->dir, 65536, 8192, array( 'wp_options' ) );
		$state     = TableExporter::initial_state( 'wp_options' );
		while ( empty( $state['done'] ) ) {
			$state = $excluding->step( $state );
		}
		$sql = (string) file_get_contents( $this->dir . '/wp_options.0001.sql' );
		$this->assertStringContainsString( "(1,'opt_0','vvvvvvvvvv'),(2,'opt_1',NULL)", $sql, 'the NULL row is kept: the predicate uses COALESCE-like CASE terms, not LENGTH(NULL)' );
		$this->assertStringNotContainsString( '(3,', $sql );
		$this->assertStringContainsString( 'WHERE NOT ((2 + CASE WHEN', $db->log[ count( $db->log ) - 1 ] );
	}
}
