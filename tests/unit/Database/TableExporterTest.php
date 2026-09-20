<?php

namespace WPCheckpoint\Tests\Unit\Database;

use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Tests\Fixtures\Database\FakeConnection;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class TableExporterTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-export-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700, true );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
	}

	/**
	 * A posts-like table with $n rows of about $bytes bytes each.
	 */
	private function posts( FakeConnection $db, int $n, int $bytes = 100, string $name = 'wp_posts' ): void {
		$rows = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$rows[] = array( (string) $i, 'title ' . $i, str_repeat( chr( 97 + $i % 26 ), $bytes ), $i % 3 === 0 ? null : '2026-09-20 10:00:00', (string) ( $i * 10 ) );
		}
		$db->add_table( $name, array( array( 'ID', 'bigint(20) unsigned' ), array( 'post_title', 'text' ), array( 'post_content', 'longtext' ), array( 'post_date', 'datetime' ), array( 'menu_order', 'int(11)' ) ), array( 'ID' ), $rows );
	}

	/**
	 * Run a table to the end, one unit at a time; returns the final state and the closed chunks in order.
	 *
	 * @return array{0: array<string, mixed>, 1: array<int, array{chunk: int, bytes: int, hash: string}>, 2: int}
	 */
	private function run_all( TableExporter $exporter, string $table, bool $roundtrip = false ): array {
		$state  = TableExporter::initial_state( $table );
		$closed = array();
		$units  = 0;
		while ( empty( $state['done'] ) ) {
			$state = $exporter->step( $state );
			++$units;
			if ( null !== $state['closed'] ) {
				$closed[] = $state['closed'];
			}
			if ( $roundtrip ) {
				$state = json_decode( (string) json_encode( $state ), true );
			}
			$this->assertLessThan( 10000, $units );
		}
		return array( $state, $closed, $units );
	}

	private function chunk( string $table, int $chunk ): string {
		return (string) file_get_contents( $this->dir . '/' . basename( IndexLine::database_path( $table, $chunk ) ) );
	}

	public function test_a_small_table_becomes_one_chunk_with_create_inserts_markers_and_an_end_line(): void {
		$db = new FakeConnection();
		$this->posts( $db, 7 );
		$exporter = new TableExporter( $db, $this->dir );
		list( $state, $closed, $units ) = $this->run_all( $exporter, 'wp_posts' );
		$this->assertSame( 7, $state['rows'] );
		$this->assertSame( 1, $state['chunks'] );
		$this->assertCount( 1, $closed );
		$this->assertSame( 2, $units, 'one batch, then the empty fetch that closes the table' );
		$sql = $this->chunk( 'wp_posts', 1 );
		$this->assertStringStartsWith( "-- wpcheckpoint table=wp_posts chunk=1 pk_from=null\nDROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts`", $sql );
		$this->assertStringContainsString( "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`, `post_date`, `menu_order`) VALUES (1,'title 1','", $sql );
		$this->assertStringContainsString( ",NULL,30)", $sql, 'NULL dates and bare numbers' );
		$this->assertStringContainsString( "\n-- wpcheckpoint batch rows=7 pk=[\"7\"]\n", $sql );
		$this->assertStringEndsWith( "-- wpcheckpoint end table=wp_posts chunk=1 rows=7 pk_to=[\"7\"]\n", $sql );
		$this->assertSame( strlen( $sql ), $closed[0]['bytes'] );
		$this->assertSame( hash( 'sha256', $sql ), $closed[0]['hash'] );
		$this->assertSame( $closed[0]['bytes'], $state['total'] );
		$this->assertStringContainsString( "WHERE (`ID` > ?) ORDER BY `ID` LIMIT", $db->log[ count( $db->log ) - 1 ], 'the closing fetch continues after the last key' );
	}

	public function test_chunks_close_at_the_size_bound_and_a_resumed_export_is_byte_identical(): void {
		$db = new FakeConnection();
		$this->posts( $db, 900, 300 );
		$straight = new TableExporter( $db, $this->dir, 65536, 8192 );
		list( $state, $closed ) = $this->run_all( $straight, 'wp_posts' );
		$this->assertGreaterThan( 3, $state['chunks'] );
		$this->assertSame( 900, $state['rows'] );
		$files = array();
		foreach ( $closed as $i => $c ) {
			$this->assertSame( $i + 1, $c['chunk'] );
			$this->assertLessThanOrEqual( 65536, $c['bytes'] );
			$sql = $this->chunk( 'wp_posts', $c['chunk'] );
			$this->assertSame( $c['bytes'], strlen( $sql ) );
			$this->assertStringStartsWith( '-- wpcheckpoint table=wp_posts chunk=' . $c['chunk'] . ' pk_from=', $sql );
			$this->assertMatchesRegularExpression( '/\n-- wpcheckpoint end table=wp_posts chunk=' . $c['chunk'] . ' rows=\d+ pk_to=\["\d+"\]\n\z/', $sql );
			$files[ $c['chunk'] ] = $sql;
			if ( $c['chunk'] > 1 ) {
				$this->assertStringNotContainsString( 'CREATE TABLE', $sql );
			}
		}
		// The whole export replayed from the products of a step-by-step run with the state round-tripped through JSON.
		$dir2 = $this->dir . '/again';
		mkdir( $dir2 );
		list( $state2, $closed2 ) = $this->run_all( new TableExporter( $db, $dir2, 65536, 8192 ), 'wp_posts', true );
		$this->assertSame( $state['rows'], $state2['rows'] );
		$this->assertSame( array_column( $closed, 'hash' ), array_column( $closed2, 'hash' ), 'the same bytes chunk by chunk' );
		// Every row exactly once, in key order, across all chunks.
		$ids = array();
		foreach ( $files as $sql ) {
			preg_match_all( '/VALUES \((\d+),|\),\((\d+),/', $sql, $m );
			foreach ( array_merge( array_filter( $m[1] ), array_filter( $m[2] ) ) as $id ) {
				$ids[] = (int) $id;
			}
		}
		sort( $ids );
		$this->assertSame( range( 1, 900 ), $ids );
	}

	public function test_resume_from_a_truncated_chunk_redoes_only_the_uncommitted_batch(): void {
		$db = new FakeConnection();
		$this->posts( $db, 400, 200 );
		$exporter = new TableExporter( $db, $this->dir, 65536, 8192 );
		$state    = TableExporter::initial_state( 'wp_posts' );
		for ( $i = 0; $i < 4; $i++ ) {
			$state = $exporter->step( $state );
		}
		$this->assertFalse( $state['done'] );
		$committed = $state;
		$path      = $this->dir . '/wp_posts.0001.sql';
		$before    = (string) file_get_contents( $path );
		$this->assertSame( $committed['bytes'], strlen( $before ) );

		// A crash after two more batches that were never checkpointed, with a torn marker at the end.
		$exporter->step( $exporter->step( $state ) );
		file_put_contents( $path, "-- wpcheckpoint batch rows=9 pk=[\"", FILE_APPEND );
		clearstatcache( true, $path );
		$this->assertGreaterThan( $committed['bytes'], filesize( $path ) );

		$fresh = new TableExporter( $db, $this->dir, 65536, 8192 );
		$state = $fresh->step( $committed );
		$this->assertSame( $before, substr( (string) file_get_contents( $path ), 0, strlen( $before ) ), 'the committed part is untouched' );
		list( $final ) = $this->run_all_from( $fresh, $state );
		$this->assertSame( 400, $final['rows'] );
		// The result equals an uninterrupted export.
		$dir2 = $this->dir . '/again';
		mkdir( $dir2 );
		list( , $closed2 ) = $this->run_all( new TableExporter( $db, $dir2, 65536, 8192 ), 'wp_posts' );
		foreach ( $closed2 as $c ) {
			$this->assertSame( file_get_contents( $dir2 . '/' . basename( IndexLine::database_path( 'wp_posts', $c['chunk'] ) ) ), $this->chunk( 'wp_posts', $c['chunk'] ), 'chunk ' . $c['chunk'] );
		}
	}

	public function test_resume_from_before_a_chunk_closed_reproduces_the_same_chunk(): void {
		$db = new FakeConnection();
		$this->posts( $db, 400, 200 );
		$exporter = new TableExporter( $db, $this->dir, 65536, 8192 );
		$state    = TableExporter::initial_state( 'wp_posts' );
		$previous = $state;
		while ( null === $state['closed'] ) {
			$previous = $state;
			$state    = $exporter->step( $state );
			$this->assertFalse( $state['done'] );
		}
		$first = $state['closed'];
		$this->assertSame( 1, $first['chunk'] );
		$this->assertSame( 1, $previous['chunk'], 'the cursor still points into chunk 1' );
		$this->assertFileExists( $this->dir . '/wp_posts.0002.sql', 'chunk 2 was started before the crash' );
		// The crash came after the chunk closed but before the checkpoint: resume from the earlier state.
		$fresh = new TableExporter( $db, $this->dir, 65536, 8192 );
		$again = $fresh->step( $previous );
		$this->assertSame( $first, $again['closed'], 'the chunk is closed again with the same bytes and hash' );
		list( $final, $closed ) = $this->run_all_from( $fresh, $again );
		$this->assertSame( 400, $final['rows'] );
		$this->assertSame( count( $closed ) + 1, $final['chunks'] );
	}

	/**
	 * @return array{0: array<string, mixed>, 1: array<int, array{chunk: int, bytes: int, hash: string}>}
	 */
	private function run_all_from( TableExporter $exporter, array $state ): array {
		$closed = array();
		while ( empty( $state['done'] ) ) {
			$state = $exporter->step( $state );
			if ( null !== $state['closed'] ) {
				$closed[] = $state['closed'];
			}
		}
		return array( $state, $closed );
	}

	public function test_a_torn_or_forged_marker_is_skipped_and_the_previous_one_used(): void {
		$db = new FakeConnection();
		$this->posts( $db, 100, 50 );
		$exporter = new TableExporter( $db, $this->dir, 65536, 2048 );
		$state    = $exporter->step( TableExporter::initial_state( 'wp_posts' ) );
		$state    = $exporter->step( $state );
		$path     = $this->dir . '/wp_posts.0001.sql';
		$sql      = (string) file_get_contents( $path );
		$markers  = substr_count( $sql, "\n-- wpcheckpoint batch " );
		$this->assertSame( 2, $markers );
		// A row whose value contains the marker text, mid-line: never a resume point.
		$this->assertNull( TableExporter::parse_marker( 'INSERT ... -- wpcheckpoint batch rows=1 pk=["999"]' ) );
		$this->assertNull( TableExporter::parse_marker( '-- wpcheckpoint batch rows=1 pk=[' ), 'torn' );
		$this->assertNull( TableExporter::parse_marker( '-- wpcheckpoint batch rows=1 pk=[1.5]' ), 'a float is not a key' );
		$this->assertNull( TableExporter::parse_marker( '-- wpcheckpoint batch rows=1 pk={"a":1}' ), 'an object is not a key' );
		$this->assertSame( array( '7', 'x y' ), TableExporter::parse_marker( '-- wpcheckpoint batch rows=3 pk=["7","x y"]' ) );
		$this->assertSame( array(), TableExporter::parse_marker( '-- wpcheckpoint batch rows=3 offset=30' ) );

		// Corrupt the last marker in place (same length) and resume: the scan must fall back to the earlier one.
		$pos     = strrpos( $sql, "\n-- wpcheckpoint batch " ) + 1;
		$torn    = substr( $sql, 0, $pos ) . str_repeat( '#', strlen( $sql ) - $pos - 1 ) . "\n";
		file_put_contents( $path, $torn );
		$db->log = array();
		$fresh   = new TableExporter( $db, $this->dir, 65536, 2048 );
		$fresh->step( $state );
		$this->assertSame( 1, preg_match( '/\n(-- wpcheckpoint batch [^\n]*)\n/', $sql, $mm ) );
		$first_key = TableExporter::parse_marker( $mm[1] );
		$this->assertNotNull( $first_key );
		$this->assertStringContainsString( 'WHERE (`ID` > ?)', $db->log[ count( $db->log ) - 1 ] );
		$after = substr( (string) file_get_contents( $path ), strlen( $torn ) );
		$this->assertStringStartsWith( 'INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`, `post_date`, `menu_order`) VALUES (' . ( (int) $first_key[0] + 1 ) . ',', $after, 'the export continues after the last trusted marker' );
	}

	public function test_composite_keys_use_the_expanded_comparison_and_string_keys_are_ordered_as_strings(): void {
		$db   = new FakeConnection();
		$rows = array();
		foreach ( array( 'b', 'a', 'B', '10', '9' ) as $object ) {
			foreach ( array( 'z', 'y' ) as $term ) {
				$rows[] = array( $object, $term, 'v' );
			}
		}
		$db->add_table( 'wp_term_relationships', array( array( 'object_id', 'varchar(20)' ), array( 'term_taxonomy_id', 'varchar(20)' ), array( 'v', 'text' ) ), array( 'object_id', 'term_taxonomy_id' ), $rows, false );
		$exporter = new TableExporter( $db, $this->dir, 65536, 64 );
		list( $state ) = $this->run_all( $exporter, 'wp_term_relationships' );
		$this->assertSame( 10, $state['rows'] );
		$this->assertStringContainsString( 'WHERE (`object_id` > ?) OR (`object_id` = ? AND `term_taxonomy_id` > ?) ORDER BY `object_id`, `term_taxonomy_id` LIMIT', $db->log[ count( $db->log ) - 1 ] );
		list( $where, $args ) = TableExporter::after_key( array( 'a', 'b', 'c' ), array( '1', '2', '3' ) );
		$this->assertSame( '(`a` > ?) OR (`a` = ? AND `b` > ?) OR (`a` = ? AND `b` = ? AND `c` > ?)', $where );
		$this->assertSame( array( '1', '1', '2', '1', '2', '3' ), $args );
		$sql = $this->chunk( 'wp_term_relationships', 1 );
		$this->assertStringContainsString( "pk_to=[\"b\",\"z\"]", $sql, 'string order: "10" < "9" < "B" < "a" < "b"' );
		preg_match_all( '/\((\'[^\']*\'),(\'[^\']*\')/', $sql, $m );
		$this->assertSame( "'10'", $m[1][0] );
		$this->assertSame( "'b'", $m[1][9] );
	}

	public function test_a_keyless_table_uses_offsets_and_carries_a_warning(): void {
		$db   = new FakeConnection();
		$rows = array();
		for ( $i = 0; $i < 120; $i++ ) {
			$rows[] = array( 'k' . $i, 'v' . $i );
		}
		$db->add_table( 'wp_nokey', array( array( 'k', 'varchar(10)' ), array( 'v', 'text' ) ), array(), $rows );
		$exporter = new TableExporter( $db, $this->dir, 65536, 256 );
		list( $state ) = $this->run_all( $exporter, 'wp_nokey', true );
		$this->assertSame( 120, $state['rows'] );
		$this->assertCount( 1, $state['warnings'] );
		$this->assertStringContainsString( 'no primary key', $state['warnings'][0] );
		$sql = $this->chunk( 'wp_nokey', 1 );
		$this->assertStringStartsWith( "-- wpcheckpoint table=wp_nokey chunk=1 offset=0\n", $sql );
		$this->assertStringContainsString( "-- wpcheckpoint batch rows=", $sql );
		$this->assertStringEndsWith( "rows=120 offset=120\n", $sql );
		$this->assertStringContainsString( 'LIMIT ', $db->log[ count( $db->log ) - 1 ] );
		$this->assertStringContainsString( 'OFFSET 120', $db->log[ count( $db->log ) - 1 ] );
		$this->assertSame( 120, substr_count( $sql, "('k" ) );
	}

	public function test_a_row_larger_than_a_chunk_fails_with_table_position_and_size(): void {
		$db = new FakeConnection();
		$db->add_table( 'wp_options', array( array( 'option_id', 'bigint(20)' ), array( 'option_name', 'varchar(191)' ), array( 'option_value', 'longtext' ) ), array( 'option_id' ), array(
			array( '1', 'small', 'x' ),
			array( '2', 'huge_transient', str_repeat( 'y', 70000 ) ),
		) );
		$exporter = new TableExporter( $db, $this->dir, 65536, 8192 );
		try {
			$this->run_all( $exporter, 'wp_options' );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Table wp_options has a row of 70023 bytes (as SQL)', $e->getMessage() );
			$this->assertStringContainsString( 'after primary key ["1"]', $e->getMessage() );
			$this->assertStringContainsString( 'larger than the ' . ( 65536 - 4096 ) . ' bytes a single row may take', $e->getMessage() );
			$this->assertStringContainsString( 'must be reduced or excluded', $e->getMessage() );
		}
	}

	/**
	 * MAX_ROW_BYTES must be reachable under the baseline: one unit may add
	 * at most 32 MB, and the largest legal row is held while it is
	 * fetched, escaped and written. A quoted row and a hex row (a binary
	 * or non-UTF-8 connection doubles the text) are both measured.
	 *
	 * @dataProvider largest_rows
	 */
	public function test_the_largest_row_is_exported_within_the_step_memory_budget( string $charset, int $value_bytes ): void {
		$db = new FakeConnection( $charset );
		$db->add_table( 'wp_options', array( array( 'option_id', 'bigint(20)' ), array( 'option_value', 'longtext' ) ), array( 'option_id' ), array(
			array( '1', 'small' ),
			array( '2', str_repeat( 'x', $value_bytes ) ),
			array( '3', 'small' ),
		) );
		$exporter = new TableExporter( $db, $this->dir );
		gc_collect_cycles();
		$before = memory_get_peak_usage( true );
		$this->assertGreaterThanOrEqual( memory_get_usage( true ), $before );
		list( $state ) = $this->run_all( $exporter, 'wp_options' );
		$delta = memory_get_peak_usage( true ) - $before;
		$this->assertSame( 3, $state['rows'] );
		$this->assertLessThanOrEqual( 32 * 1048576, $delta, sprintf( 'Exporting a %d-byte row peaked at %.1f MiB above the baseline.', $value_bytes, $delta / 1048576 ) );
		$expected = 'gbk' === $charset ? "(2,X'" . str_repeat( '78', $value_bytes ) . "')" : "(2,'" . str_repeat( 'x', $value_bytes ) . "')";
		$this->assertLessThanOrEqual( TableExporter::MAX_ROW_BYTES, strlen( $expected ) );
		$this->assertStringContainsString( $expected, $this->chunk( 'wp_options', 1 ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function largest_rows(): array {
		return array(
			'quoted utf8mb4' => array( 'utf8mb4', TableExporter::MAX_ROW_BYTES - 6 ),
			'hex gbk'        => array( 'gbk', ( TableExporter::MAX_ROW_BYTES - 7 ) >> 1 ),
		);
	}

	public function test_database_errors_are_classified(): void {
		$db = new FakeConnection();
		$this->posts( $db, 3 );
		$exporter = new TableExporter( $db, $this->dir );
		$db->fail_next = array( 2006, 'MySQL server has gone away' );
		try {
			$exporter->step( TableExporter::initial_state( 'wp_posts' ) );
			$this->fail();
		} catch ( TransientFailure $e ) {
			$this->assertStringContainsString( '(2006)', $e->getMessage() );
		}
		$db->fail_next = array( 1146, "Table 'wp_posts' doesn't exist" );
		try {
			$exporter->step( TableExporter::initial_state( 'wp_posts' ) );
			$this->fail();
		} catch ( TransientFailure $e ) {
			$this->fail( 'a missing table is not transient' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( '(1146)', $e->getMessage() );
		}
	}

	public function test_a_gbk_connection_writes_strings_as_hex_and_adaptive_batches_stay_bounded(): void {
		$db = new FakeConnection( 'gbk' );
		$this->posts( $db, 5 );
		$exporter = new TableExporter( $db, $this->dir );
		$this->assertTrue( $exporter->hex_all() );
		$this->run_all( $exporter, 'wp_posts' );
		$this->assertStringContainsString( "(1,X'7469746c652031',", $this->chunk( 'wp_posts', 1 ) );
		$this->assertSame( TableExporter::MIN_ROWS, TableExporter::adapt( 500, 1048576 * 4, 100, 1048576 ), 'huge rows: the floor' );
		$this->assertSame( TableExporter::MAX_ROWS, TableExporter::adapt( 500, 10, 100, 1048576 ), 'tiny rows: the cap' );
		$this->assertSame( 2048, TableExporter::adapt( 500, 512 * 100, 100, 1048576 ) );
		$this->assertSame( 500, TableExporter::adapt( 500, 0, 0, 1048576 ), 'no data: unchanged' );
	}
}
