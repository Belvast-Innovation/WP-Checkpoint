<?php

namespace WPCheckpoint\Tests\Unit\Database;

use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Database\TableExporter;
use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Tests\Fixtures\Database\FakeConnection;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class TableExporterTest extends TestCase {

	const PREAMBLE = "/*!40101 SET NAMES utf8mb4 */;\n/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n";

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
		$this->assertStringStartsWith( "-- wpcheckpoint table=wp_posts chunk=1 pk_from=null\n-- wpcheckpoint bound pk_max=[\"7\"]\n" . self::PREAMBLE . "DROP TABLE IF EXISTS `wp_posts`;\nCREATE TABLE `wp_posts`", $sql );
		$this->assertStringContainsString( "INSERT INTO `wp_posts` (`ID`, `post_title`, `post_content`, `post_date`, `menu_order`) VALUES (1,'title 1','", $sql );
		$this->assertStringContainsString( ",NULL,30)", $sql, 'NULL dates and bare numbers' );
		$this->assertStringContainsString( "\n-- wpcheckpoint batch rows=7 pk=[\"7\"]\n", $sql );
		$this->assertStringEndsWith( "-- wpcheckpoint end table=wp_posts chunk=1 rows=7 pk_to=[\"7\"]\n", $sql );
		$this->assertSame( strlen( $sql ), $closed[0]['bytes'] );
		$this->assertSame( hash( 'sha256', $sql ), $closed[0]['hash'] );
		$this->assertSame( $closed[0]['bytes'], $state['total'] );
		$this->assertStringContainsString( "WHERE ((`ID` > ?)) AND ((`ID` < ?) OR (`ID` = ?)) ORDER BY `ID` LIMIT", $db->log[ count( $db->log ) - 1 ], 'the closing fetch continues after the last key' );
		$this->assertStringStartsWith( 'SELECT `ID`, LENGTH(`ID`), LENGTH(`post_title`), LENGTH(`post_content`), LENGTH(`post_date`), LENGTH(`menu_order`) FROM `wp_posts`', $db->log[ count( $db->log ) - 1 ], 'sizes are read before rows' );
		$this->assertStringStartsWith( 'SELECT `ID`, `post_title`, `post_content`, `post_date`, `menu_order` FROM `wp_posts` WHERE ((`ID` < ?) OR (`ID` = ?)) ORDER BY `ID` LIMIT 7', $db->log[ count( $db->log ) - 2 ], 'rows are read by explicit column names, as many as the sizes allow' );
		$this->assertStringContainsString( self::PREAMBLE, $this->chunk( 'wp_posts', 1 ) );
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

	private function post_row( int $id, string $title = 'new' ): array {
		return array( (string) $id, $title . ' ' . $id, 'body', '2026-09-20 10:00:00', '0' );
	}

	/**
	 * Keys in the order a table's chunks hold them.
	 *
	 * @return string[]
	 */
	private function exported_titles( string $table, int $chunks ): array {
		$sql = '';
		for ( $c = 1; $c <= $chunks; $c++ ) {
			$sql .= $this->chunk( $table, $c );
		}
		preg_match_all( "/\\(\\d+,'([^']*)'/", $sql, $m );
		return $m[1];
	}

	public function test_rows_added_while_a_table_is_exported_are_left_out_and_the_table_ends(): void {
		$db = new FakeConnection();
		$this->posts( $db, 20 );
		// Small batches, and a row added after every unit: without a bound the export would chase the table forever.
		$exporter = new TableExporter( $db, $this->dir, 65536, 300 );
		$state    = TableExporter::initial_state( 'wp_posts' );
		$next     = 21;
		for ( $units = 0; empty( $state['done'] ); $units++ ) {
			$this->assertLessThan( 100, $units, 'the table ends although it grows by a row per unit' );
			$state = $exporter->step( $state );
			$db->insert_rows( 'wp_posts', array( $this->post_row( $next++ ) ) );
		}
		$this->assertSame( 20, $state['rows'], 'exactly the rows there when its export started' );
		$this->assertStringContainsString( "\n-- wpcheckpoint bound pk_max=[\"20\"]\n", $this->chunk( 'wp_posts', 1 ) );
		$this->assertStringNotContainsString( "'new ", $this->chunk( 'wp_posts', 1 ) );
		$this->assertGreaterThan( 3, $units, 'several units, each followed by a new row' );
	}

	public function test_a_string_key_that_sorts_inside_the_bound_is_still_read(): void {
		// Some plugin tables use string keys: a new row can sort before the bound and after what was read.
		$db   = new FakeConnection();
		$rows = array();
		foreach ( array( 'b', 'd', 'f' ) as $k ) {
			$rows[] = array( $k, 'old' );
		}
		$db->add_table( 'wp_sessions', array( array( 'k', 'varchar(20)' ), array( 'v', 'text' ) ), array( 'k' ), $rows, false );
		$exporter = new TableExporter( $db, $this->dir, 65536, 16 ); // A batch or two per unit.
		$state    = $exporter->step( TableExporter::initial_state( 'wp_sessions' ) );
		$this->assertLessThan( 3, $state['rows'], 'the table is not read in one unit' );
		$db->insert_rows( 'wp_sessions', array( array( 'e', 'new' ), array( 'z', 'new' ) ) );
		list( $state ) = $this->run_all_from( $exporter, $state );
		$sql = $this->chunk( 'wp_sessions', 1 );
		$this->assertStringContainsString( "('e','new')", $sql, 'a new key before the bound "f" and after the position read' );
		$this->assertStringNotContainsString( "('z','new')", $sql, 'a new key after the bound is not' );
		$this->assertSame( 4, $state['rows'] );
	}

	public function test_a_table_emptied_during_its_export_ends_with_the_rows_read_before(): void {
		$db = new FakeConnection();
		$this->posts( $db, 30 );
		$exporter = new TableExporter( $db, $this->dir, 65536, 300 );
		$state    = $exporter->step( TableExporter::initial_state( 'wp_posts' ) );
		$read     = (int) $state['rows'];
		$this->assertGreaterThan( 0, $read );
		$this->assertLessThan( 30, $read );
		$db->replace_rows( 'wp_posts', array() );
		list( $state ) = $this->run_all_from( $exporter, $state );
		$this->assertSame( $read, $state['rows'], 'no failure, no re-export: the table ends where it was emptied' );
	}

	public function test_a_table_emptied_and_refilled_during_its_export_mixes_old_and_new_rows(): void {
		// A cache rebuild: TRUNCATE resets the auto-increment and the table fills again from 1.
		$db = new FakeConnection();
		$this->posts( $db, 10 );
		$exporter = new TableExporter( $db, $this->dir, 65536, 300 );
		$state    = $exporter->step( TableExporter::initial_state( 'wp_posts' ) );
		$read     = (int) $state['rows'];
		$this->assertGreaterThan( 0, $read );
		$this->assertLessThan( 10, $read );
		$fresh = array();
		for ( $i = 1; $i <= 20; $i++ ) {
			$fresh[] = $this->post_row( $i, 'rebuilt' );
		}
		$db->replace_rows( 'wp_posts', $fresh );
		list( $state ) = $this->run_all_from( $exporter, $state );
		$titles = $this->exported_titles( 'wp_posts', (int) $state['chunks'] );
		$this->assertSame( 10, count( $titles ), 'up to the bound, 10' );
		$this->assertSame( 'title 1', $titles[0], 'the old rows read before the rebuild' );
		$this->assertSame( 'rebuilt ' . ( $read + 1 ), $titles[ $read ], 'then rows of the rebuilt table' );
		$this->assertSame( 'rebuilt 10', $titles[9] );
	}

	public function test_an_empty_table_is_exported_with_a_null_bound_and_no_rows(): void {
		// An empty table ends in its first unit, so nothing added later can reach it: only its header is tested.
		$db = new FakeConnection();
		$this->posts( $db, 0 );
		list( $state ) = $this->run_all( new TableExporter( $db, $this->dir ), 'wp_posts' );
		$this->assertSame( 0, $state['rows'] );
		$this->assertStringContainsString( "\n-- wpcheckpoint bound pk_max=null\n", $this->chunk( 'wp_posts', 1 ) );
	}

	public function test_a_keyless_tables_bound_counts_only_the_rows_it_will_read(): void {
		// Rows left out as oversized are not counted: otherwise rows added during the export could take their place.
		$db   = new FakeConnection();
		$rows = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$rows[] = array( 'k' . $i, $i < 2 ? str_repeat( 'x', 3000 ) : 'small' );
		}
		$db->add_table( 'wp_nokey', array( array( 'k', 'varchar(10)' ), array( 'v', 'text' ) ), array(), $rows );
		// A row limit of 1000 bytes: the two 3000-byte values are oversized.
		$exporter = new TableExporter( $db, $this->dir, 5096, 128, array( 'wp_nokey' ) );
		$this->assertLessThan( 3000, $exporter->row_limit() );
		$state = TableExporter::initial_state( 'wp_nokey' );
		for ( $units = 0; empty( $state['done'] ); $units++ ) {
			$this->assertLessThan( 100, $units );
			$state = $exporter->step( $state );
			$db->insert_rows( 'wp_nokey', array( array( 'added', 'during' ) ) );
		}
		$this->assertStringContainsString( "\n-- wpcheckpoint bound rows_max=8\n", $this->chunk( 'wp_nokey', 1 ) );
		$this->assertSame( 8, $state['rows'] );
		$this->assertStringNotContainsString( "'added'", $this->chunk( 'wp_nokey', 1 ) );
	}

	public function test_a_keyless_table_is_bounded_by_its_row_count(): void {
		$db   = new FakeConnection();
		$rows = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$rows[] = array( 'k' . $i, 'v' . $i );
		}
		$db->add_table( 'wp_nokey', array( array( 'k', 'varchar(10)' ), array( 'v', 'text' ) ), array(), $rows );
		$exporter = new TableExporter( $db, $this->dir, 65536, 128 );
		$state    = TableExporter::initial_state( 'wp_nokey' );
		for ( $units = 0; empty( $state['done'] ); $units++ ) {
			$this->assertLessThan( 100, $units );
			$state = $exporter->step( $state );
			$db->insert_rows( 'wp_nokey', array( array( 'added', 'during' ) ) );
		}
		$this->assertSame( 40, $state['rows'] );
		$this->assertStringNotContainsString( "'added'", $this->chunk( 'wp_nokey', 1 ) );
	}

	public function test_a_resumed_or_replayed_export_uses_the_bound_it_started_with(): void {
		$db = new FakeConnection();
		$this->posts( $db, 400 );
		$exporter = new TableExporter( $db, $this->dir, 8192, 1024 ); // Several chunks.
		$state    = TableExporter::initial_state( 'wp_posts' );
		$saved    = array();
		while ( (int) $state['chunk'] < 3 ) {
			$state   = $exporter->step( $state );
			$saved[] = json_decode( (string) json_encode( $state ), true );
			$this->assertLessThan( 200, count( $saved ) );
		}
		$db->insert_rows( 'wp_posts', array( $this->post_row( 401 ), $this->post_row( 402 ) ) );
		// A fresh process resumes from saved cursors (in the first chunk, and after two chunks closed): the
		// bound is read back from the chunk, not queried again.
		foreach ( array( $saved[1], $saved[ count( $saved ) - 1 ] ) as $cursor ) {
			list( $done ) = $this->run_all_from( new TableExporter( $db, $this->dir, 8192, 1024 ), $cursor );
			$this->assertSame( 400, $done['rows'] );
			for ( $c = 1; $c <= (int) $done['chunks']; $c++ ) {
				$this->assertStringContainsString( "\n-- wpcheckpoint bound pk_max=[\"400\"]\n", $this->chunk( 'wp_posts', $c ), 'every chunk carries the bound: ' . $c );
			}
		}
		// A first chunk whose header was never committed: nothing depends on the bound yet, so it is queried again.
		list( $fresh ) = $this->run_all_from( $exporter, TableExporter::initial_state( 'wp_posts' ) );
		$this->assertSame( 402, $fresh['rows'] );
		$this->assertStringContainsString( "\n-- wpcheckpoint bound pk_max=[\"402\"]\n", $this->chunk( 'wp_posts', 1 ) );
	}

	public function test_a_chunk_begun_before_bounds_existed_goes_on_without_one(): void {
		// An export running while the plugin was updated: its chunk has no bound line.
		$db = new FakeConnection();
		$this->posts( $db, 10 );
		$exporter = new TableExporter( $db, $this->dir, 65536, 300 );
		$state    = $exporter->step( TableExporter::initial_state( 'wp_posts' ) );
		$path     = $exporter->chunk_path( 'wp_posts', 1 );
		$old      = preg_replace( "/\\n-- wpcheckpoint bound [^\\n]*/", '', (string) file_get_contents( $path ), 1 );
		file_put_contents( $path, $old );
		$state['bytes'] = strlen( $old );
		$db->insert_rows( 'wp_posts', array( $this->post_row( 11 ) ) );
		list( $state ) = $this->run_all_from( $exporter, $state );
		$this->assertSame( 11, $state['rows'], 'no bound: read to the end as it was begun, not failed' );
		$this->assertStringNotContainsString( 'wpcheckpoint bound', $this->chunk( 'wp_posts', 1 ) );
	}

	public function test_a_malformed_bound_line_fails_the_export(): void {
		foreach ( array(
			'bound pk_max=["10"' => 'A primary key in a chunk file is malformed',
			'bound pk_limit=10'  => 'Chunk 1 of table wp_posts has a malformed bound line',
		) as $replacement => $message ) {
			$db = new FakeConnection();
			$this->posts( $db, 10 );
			$exporter = new TableExporter( $db, $this->dir, 65536, 300 );
			$state    = $exporter->step( TableExporter::initial_state( 'wp_posts' ) );
			$path     = $exporter->chunk_path( 'wp_posts', 1 );
			$damaged  = str_replace( 'bound pk_max=["10"]', $replacement, (string) file_get_contents( $path ) );
			$this->assertNotSame( (string) file_get_contents( $path ), $damaged );
			file_put_contents( $path, $damaged );
			$state['bytes'] = strlen( $damaged );
			try {
				$exporter->step( $state );
				$this->fail( 'a damaged bound must fail the export: ' . $replacement );
			} catch ( \RuntimeException $e ) {
				$this->assertStringStartsWith( $message, $e->getMessage() );
			}
		}
	}

	public function test_marker_lines_parse_exactly_or_fail_closed(): void {
		$this->assertNull( TableExporter::parse_marker( 'INSERT ... -- wpcheckpoint batch rows=1 pk=["999"]' ), 'marker text inside a line is not a marker' );
		$this->assertNull( TableExporter::parse_marker( '-- wpcheckpoint end table=t chunk=1 rows=1 pk_to=["1"]' ) );
		$this->assertSame( array( '7', 'x y' ), TableExporter::parse_marker( '-- wpcheckpoint batch rows=3 pk=["7","x y"]' ) );
		$this->assertSame( array( "\xff\x00", 'a' ), TableExporter::parse_marker( '-- wpcheckpoint batch rows=3 pk=[{"h":"ff00"},"a"]' ), 'binary key values come back byte for byte' );
		$this->assertSame( array(), TableExporter::parse_marker( '-- wpcheckpoint batch rows=3 offset=30' ) );
		foreach ( array(
			'torn'            => '-- wpcheckpoint batch rows=1 pk=[',
			'float'           => '-- wpcheckpoint batch rows=1 pk=[1.5]',
			'object'          => '-- wpcheckpoint batch rows=1 pk={"a":1}',
			'null element'    => '-- wpcheckpoint batch rows=1 pk=[null]',
			'empty key'       => '-- wpcheckpoint batch rows=1 pk=[]',
			'odd hex'         => '-- wpcheckpoint batch rows=1 pk=[{"h":"abc"}]',
			'upper hex'       => '-- wpcheckpoint batch rows=1 pk=[{"h":"AB"}]',
			'extra hex field' => '-- wpcheckpoint batch rows=1 pk=[{"h":"ab","x":1}]',
			'no rows'         => '-- wpcheckpoint batch pk=["1"]',
		) as $case => $line ) {
			try {
				TableExporter::parse_marker( $line );
				$this->fail( $case . ' should fail closed' );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'malformed', $e->getMessage(), $case );
			}
		}
	}

	public function test_a_damaged_last_marker_fails_the_export_instead_of_re_exporting_rows(): void {
		$db = new FakeConnection();
		$this->posts( $db, 100, 50 );
		$exporter = new TableExporter( $db, $this->dir, 65536, 2048 );
		$state    = $exporter->step( TableExporter::initial_state( 'wp_posts' ) );
		$state    = $exporter->step( $state );
		$path     = $this->dir . '/wp_posts.0001.sql';
		$sql      = (string) file_get_contents( $path );
		$this->assertSame( 2, substr_count( $sql, "\n-- wpcheckpoint batch " ) );
		// The last marker corrupted in place (same length): the committed part no longer ends with a marker.
		$pos  = strrpos( $sql, "\n-- wpcheckpoint batch " ) + 1;
		$torn = substr( $sql, 0, $pos ) . str_repeat( '#', strlen( $sql ) - $pos - 1 ) . "\n";
		file_put_contents( $path, $torn );
		try {
			( new TableExporter( $db, $this->dir, 65536, 2048 ) )->step( $state );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'does not end with a batch marker', $e->getMessage() );
		}
		// The last marker present but malformed: the same.
		file_put_contents( $path, substr( $sql, 0, $pos ) . '-- wpcheckpoint batch rows=1 pk=[nul' . "\n" );
		$state['bytes'] = (int) filesize( $path );
		clearstatcache( true, $path );
		$state['bytes'] = strlen( (string) file_get_contents( $path ) );
		try {
			( new TableExporter( $db, $this->dir, 65536, 2048 ) )->step( $state );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'malformed', $e->getMessage() );
		}
		$this->assertSame( $sql, $sql, 'no chunk was rewritten from an earlier key' );
	}

	public function test_binary_and_non_utf8_keys_round_trip_through_the_markers(): void {
		$db   = new FakeConnection();
		$rows = array();
		foreach ( array( "\xff\x01", "\x00", 'abc', "\xc3\xa9", "\xe9" ) as $i => $key ) {
			$rows[] = array( $key, 'v' . $i );
		}
		$db->add_table( 'wp_bin', array( array( 'k', 'varbinary(16)' ), array( 'v', 'text' ) ), array( 'k' ), $rows, false );
		$exporter = new TableExporter( $db, $this->dir, 65536, 32 );
		list( $state, , $units ) = $this->run_all( $exporter, 'wp_bin', true );
		$this->assertSame( 5, $state['rows'], 'every row once' );
		$this->assertGreaterThanOrEqual( 3, $units );
		$sql = $this->chunk( 'wp_bin', 1 );
		$this->assertStringContainsString( 'pk=[{"h":"ff01"}]', $sql );
		$this->assertStringContainsString( 'pk=["abc"]', $sql );
		$this->assertStringContainsString( 'pk=["é"]', $sql, 'valid UTF-8 stays readable' );
		$this->assertStringContainsString( 'pk=[{"h":"e9"}]', $sql, 'invalid UTF-8 is hex' );
		$this->assertSame( 5, substr_count( $sql, "(X'" ), 'binary keys are written as hex values' );
		$this->assertStringEndsWith( "pk_to=[{\"h\":\"ff01\"}]\n", $sql, 'string order: 00 < ab < c3 < e9 < ff' );
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
		$this->assertStringContainsString( 'WHERE ((`object_id` > ?) OR (`object_id` = ? AND `term_taxonomy_id` > ?)) AND ((`object_id` < ?) OR (`object_id` = ? AND `term_taxonomy_id` < ?) OR (`object_id` = ? AND `term_taxonomy_id` = ?)) ORDER BY `object_id`, `term_taxonomy_id` LIMIT', $db->log[ count( $db->log ) - 1 ] );
		list( $where, $args ) = TableExporter::up_to_key( array( 'a', 'b' ), array( '1', '2' ) );
		$this->assertSame( '(`a` < ?) OR (`a` = ? AND `b` < ?) OR (`a` = ? AND `b` = ?)', $where );
		$this->assertSame( array( '1', '1', '2', '1', '2' ), $args );
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
		$this->assertStringStartsWith( "-- wpcheckpoint table=wp_nokey chunk=1 offset=0\n-- wpcheckpoint bound rows_max=120\n", $sql );
		$this->assertStringContainsString( "-- wpcheckpoint batch rows=", $sql );
		$this->assertStringEndsWith( "rows=120 offset=120\n", $sql );
		$this->assertStringContainsString( 'LIMIT 2 OFFSET 118', $db->log[ count( $db->log ) - 1 ], 'the last read asks for no more than the rows left under the bound' );
		$this->assertSame( array(), preg_grep( '/OFFSET 120/', $db->log ), 'and none past it' );
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
			$this->assertMatchesRegularExpression( '/Table wp_options has a row of about 7\\d{4} bytes \\(as SQL\\) at primary key \\["2"\\]/', $e->getMessage() );
			$this->assertStringContainsString( 'larger than the ' . ( 65536 - 4096 ) . ' bytes a single row may take', $e->getMessage() );
			$this->assertStringContainsString( 'must be reduced or excluded', $e->getMessage() );
		}
		$this->assertStringNotContainsString( '`option_id`, `option_name`, `option_value` FROM `wp_options` ORDER BY `option_id` LIMIT 2', implode( "\n", $db->log ), 'the row was never fetched' );
	}

	public function test_the_estimate_bounds_ordinary_rows_and_the_exact_check_catches_the_rest(): void {
		$writer = new \WPCheckpoint\Database\SqlWriter( 'utf8mb4' );
		$kinds  = array( 'numeric', 'text', 'binary', 'text', 'numeric' );
		foreach ( array(
			array( '42', "it's a 'quoted' \\ text\n", "\x00\xff\x01", null, '3.5' ),
			array( '1', str_repeat( 'x', 10000 ), '', '', null ),
			array( '-7', '😀 中文', str_repeat( "\xff", 500 ), 'plain', '0' ),
		) as $row ) {
			$lengths = array_map( static function ( $v ) {
				return null === $v ? null : strlen( $v );
			}, $row );
			$this->assertGreaterThanOrEqual( strlen( $writer->tuple( $row, $kinds ) ), TableExporter::estimate_row_bytes( $lengths, $kinds, false ) );
			$this->assertGreaterThanOrEqual( strlen( ( new \WPCheckpoint\Database\SqlWriter( 'gbk' ) )->tuple( $row, $kinds ) ), TableExporter::estimate_row_bytes( $lengths, $kinds, true ) );
		}
		// Text made of backslashes doubles when escaped: it passes the estimate and is stopped by the exact size after formatting.
		$db = new FakeConnection();
		$db->add_table( 'wp_options', array( array( 'option_id', 'bigint(20)' ), array( 'option_value', 'longtext' ) ), array( 'option_id' ), array(
			array( '1', 'small' ),
			array( '2', str_repeat( '\\', 40000 ) ),
		) );
		$exporter = new TableExporter( $db, $this->dir, 65536, 8192 );
		$this->assertLessThan( $exporter->row_limit(), TableExporter::estimate_row_bytes( array( 1, 40000 ), array( 'numeric', 'text' ), false ) );
		try {
			$this->run_all( $exporter, 'wp_options' );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'has a row of about 80006 bytes (as SQL) at primary key ["2"]', $e->getMessage() );
		}
		// A string key is not printed: the position is a row number.
		$db = new FakeConnection();
		$db->add_table( 'wp_sessions', array( array( 'session_key', 'varchar(64)' ), array( 'data', 'longtext' ) ), array( 'session_key' ), array(
			array( 'a-secret-session-key', 'small' ),
			array( 'b-secret-session-key', str_repeat( 'y', 70000 ) ),
		), false );
		try {
			$this->run_all( new TableExporter( $db, $this->dir, 65536, 8192 ), 'wp_sessions' );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( ' at row 2,', $e->getMessage() );
			$this->assertStringNotContainsString( 'secret', $e->getMessage() );
		}
	}

	public function test_a_run_of_large_rows_after_small_ones_is_fetched_one_at_a_time_within_the_budget(): void {
		$db    = new FakeConnection();
		$rows  = array();
		$large = str_repeat( 'L', 700 * 1024 );
		for ( $i = 1; $i <= 600; $i++ ) {
			$rows[] = array( (string) $i, 'small ' . $i );
		}
		for ( $i = 601; $i <= 660; $i++ ) {
			$rows[] = array( (string) $i, $large );
		}
		$db->add_table( 'wp_mixed', array( array( 'id', 'bigint(20)' ), array( 'v', 'longtext' ) ), array( 'id' ), $rows );
		unset( $rows );
		$exporter = new TableExporter( $db, $this->dir, 1048576, 262144 );
		gc_collect_cycles();
		$before = memory_get_peak_usage( true );
		list( $state ) = $this->run_all( $exporter, 'wp_mixed' );
		$delta = memory_get_peak_usage( true ) - $before;
		$this->assertSame( 660, $state['rows'] );
		$this->assertLessThanOrEqual( 32 * 1048576, $delta, sprintf( 'peaked at %.1f MiB above the baseline', $delta / 1048576 ) );
		$fetches = preg_grep( '/\\ASELECT `id`, `v` FROM/', $db->log );
		$this->assertGreaterThanOrEqual( 61, count( $fetches ) );
		$large_fetches = 0;
		foreach ( $fetches as $sql ) {
			if ( 1 === preg_match( '/ LIMIT (\\d+)\\z/', $sql, $m ) && (int) $m[1] === 1 ) {
				++$large_fetches;
			}
		}
		$this->assertGreaterThanOrEqual( 59, $large_fetches, 'each large row was fetched on its own (the first one may share a batch with the last small rows) although the look-ahead after 600 small rows was hundreds of rows' );
		$this->assertStringContainsString( ' LIMIT 500', $db->log[4], 'the first look-ahead (after describe and the bound) is INITIAL_ROWS' );
	}

	public function test_generated_columns_are_left_out_and_invisible_columns_are_read_by_name(): void {
		$db = new FakeConnection();
		$db->add_table(
			'wp_cols',
			array( array( 'id', 'int(11)' ), array( 'a', 'varchar(20)' ), array( 'twice', 'int(11)', 'STORED GENERATED' ), array( 'hidden', 'varchar(20)', 'INVISIBLE' ) ),
			array( 'id' ),
			array( array( '1', 'one', '2', 'h1' ), array( '2', 'two', '4', 'h2' ) ),
			true,
			array( 'hidden' )
		);
		list( $state ) = $this->run_all( new TableExporter( $db, $this->dir ), 'wp_cols' );
		$this->assertSame( 2, $state['rows'] );
		$sql = $this->chunk( 'wp_cols', 1 );
		$this->assertStringContainsString( "INSERT INTO `wp_cols` (`id`, `a`, `hidden`) VALUES (1,'one','h1'),(2,'two','h2');", $sql );
		$this->assertStringNotContainsString( 'twice', $sql, 'a generated column is computed on import, never inserted' );
		$this->assertStringContainsString( 'SELECT `id`, `a`, `hidden` FROM `wp_cols`', implode( "\n", $db->log ), 'columns are read by name, so an invisible column is included and nothing shifts' );
		$this->assertStringNotContainsString( 'SELECT * ', implode( "\n", $db->log ) );
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
			'quoted utf8mb4' => array( 'utf8mb4', (int) floor( ( TableExporter::MAX_ROW_BYTES - 16 ) / 1.1 ) ),
			'hex gbk'        => array( 'gbk', (int) floor( ( TableExporter::MAX_ROW_BYTES - 16 ) / 2.2 ) ),
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
