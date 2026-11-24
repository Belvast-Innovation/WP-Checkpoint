<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexAudit;
use WPCheckpoint\Archive\Manifest;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class IndexAuditTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
		parent::set_up();
		$this->dir = sys_get_temp_dir() . '/wpcheckpoint-audit-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir, 0700, true );
	}

	protected function tear_down(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->dir ) );
	}

	private function lines( string $name, array $lines ): string {
		$path = $this->dir . '/' . $name;
		file_put_contents( $path, implode( "\n", array_map( 'json_encode', $lines ) ) . "\n" );
		return $path;
	}

	public function test_every_files_line_needs_a_hash_and_the_totals_add_up(): void {
		$h    = str_repeat( 'ab', 32 );
		$path = $this->lines( 'files.index.jsonl', array(
			array( 'p' => 'wp-content/a.txt', 'b' => 10, 'm' => 1, 'h' => $h ),
			array( 'p' => 'wp-content/b.txt', 'b' => 20, 'm' => 1, 'h' => ChunkHasher::list_hash( array( $h, $h ) ), 'hc' => array( $h, $h ) ),
		) );
		$this->assertSame( array( 'count' => 2, 'bytes' => 30 ), IndexAudit::files( $path, 16 ) );

		$path = $this->lines( 'files.index.jsonl', array(
			array( 'p' => 'wp-content/a.txt', 'b' => 10, 'm' => 1, 'h' => $h ),
			array( 'p' => 'wp-content/b.txt', 'b' => 20, 'm' => 1 ),
		) );
		try {
			IndexAudit::files( $path, 1048576 );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'line 2 has no content hash', $e->getMessage() );
		}
		file_put_contents( $path, "{\"p\":\"wp-content/a.txt\",\"b\":10,\"m\":1,\"h\":\"" . $h . "\"}\nnot json\n" );
		try {
			IndexAudit::files( $path, 1048576 );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'line 2 is malformed', $e->getMessage() );
		}
		try {
			IndexAudit::files( $this->dir . '/missing.jsonl', 1048576 );
			$this->fail();
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'missing', $e->getMessage() );
		}
	}

	public function test_the_database_index_must_be_in_lockstep_with_the_summary(): void {
		$h1     = str_repeat( '11', 32 );
		$h2     = str_repeat( '22', 32 );
		$h3     = str_repeat( '33', 32 );
		$tables = array(
			array( 'name' => 'wp_options', 'chunks' => 2, 'sha256' => ChunkHasher::list_hash( array( $h1, $h2 ) ) ),
			array( 'name' => 'wp_posts', 'chunks' => 1, 'sha256' => ChunkHasher::list_hash( array( $h3 ) ) ),
		);
		$good   = array(
			array( 't' => 'wp_options', 'c' => 1, 'p' => 'database/wp_options.0001.sql', 'b' => 5, 'h' => $h1 ),
			array( 't' => 'wp_options', 'c' => 2, 'p' => 'database/wp_options.0002.sql', 'b' => 5, 'h' => $h2 ),
			array( 't' => 'wp_posts', 'c' => 1, 'p' => 'database/wp_posts.0001.sql', 'b' => 5, 'h' => $h3 ),
		);
		$this->assertSame( 3, IndexAudit::database( $this->lines( 'database.index.jsonl', $good ), $tables, 1048576 ) );

		$cases = array(
			'a chunk missing'     => array( array( $good[0], $good[2] ), 'names table wp_posts where the export summary expects wp_options' ),
			'last chunk missing'  => array( array( $good[0], $good[1] ), 'ends after 1 of 2 tables' ),
			'tables swapped'      => array( array( $good[2], $good[0], $good[1] ), 'names table wp_posts where the export summary expects wp_options' ),
			'chunk out of order'  => array( array( $good[1], $good[0], $good[2] ), 'is chunk 2 of table wp_options where chunk 1 was expected' ),
			'extra table'         => array( array_merge( $good, array( array( 't' => 'wp_extra', 'c' => 1, 'p' => 'database/wp_extra.0001.sql', 'b' => 5, 'h' => $h3 ) ) ), 'which the export summary does not list' ),
			'wrong hash'          => array( array( $good[0], array_merge( $good[1], array( 'h' => $h3 ) ), $good[2] ), 'do not add up to the export summary' ),
			'table missing'       => array( array( $good[0], array_merge( $good[1], array( 'c' => 2 ) ) ), 'ends after 1 of 2 tables' ),
		);
		foreach ( $cases as $case => list( $lines, $message ) ) {
			try {
				IndexAudit::database( $this->lines( 'database.index.jsonl', $lines ), $tables, 1048576 );
				$this->fail( $case );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( $message, $e->getMessage(), $case );
			}
		}
	}

	public function test_tables_with_no_chunks_have_no_lines_wherever_they_stand(): void {
		$h1    = str_repeat( '11', 32 );
		$h2    = str_repeat( '22', 32 );
		$empty = static function ( string $name ): array {
			return array( 'name' => $name, 'chunks' => 0, 'bytes' => 0, 'sha256' => hash( 'sha256', '' ) );
		};
		$full  = static function ( string $name, string $h ): array {
			return array( 'name' => $name, 'chunks' => 1, 'bytes' => 5, 'sha256' => ChunkHasher::list_hash( array( $h ) ) );
		};
		$line  = static function ( string $name, string $h ): array {
			return array( 't' => $name, 'c' => 1, 'p' => 'database/' . $name . '.0001.sql', 'b' => 5, 'h' => $h );
		};
		$lines = $this->lines( 'database.index.jsonl', array( $line( 'wp_a', $h1 ), $line( 'wp_c', $h2 ) ) );
		$this->assertSame( 2, IndexAudit::database( $lines, array( $full( 'wp_a', $h1 ), $empty( 'wp_b' ), $full( 'wp_c', $h2 ) ), 1048576 ), 'in the middle' );
		$this->assertSame( 2, IndexAudit::database( $lines, array( $full( 'wp_a', $h1 ), $full( 'wp_c', $h2 ), $empty( 'wp_d' ) ), 1048576 ), 'last' );
		$this->assertSame( 2, IndexAudit::database( $lines, array( $empty( 'wp_0' ), $full( 'wp_a', $h1 ), $full( 'wp_c', $h2 ) ), 1048576 ), 'first' );
		file_put_contents( $this->dir . '/empty.jsonl', '' );
		$this->assertSame( 0, IndexAudit::database( $this->dir . '/empty.jsonl', array( $empty( 'wp_only' ) ), 1048576 ), 'the only table: no lines at all' );
		$this->assertTrue( Manifest::is_empty_table( $empty( 'x' ) ), 'the same predicate the reader uses' );
		try {
			IndexAudit::database( $lines, array( $full( 'wp_a', $h1 ), array( 'name' => 'wp_b', 'chunks' => 0, 'bytes' => 9, 'sha256' => $h2 ), $full( 'wp_c', $h2 ) ), 1048576 );
			$this->fail( 'no chunks but bytes must be refused' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'declares no chunks but bytes or a hash', $e->getMessage() );
		}
	}

	/**
	 * @param string[] $paths Archive paths.
	 */
	private function files_index( array $paths ): string {
		$h     = str_repeat( 'ab', 32 );
		$lines = array();
		foreach ( $paths as $p ) {
			$lines[] = array( 'p' => $p, 'b' => 3, 'm' => 1, 'h' => $h );
		}
		return $this->lines( 'files.index.jsonl', $lines );
	}

	/**
	 * @param array<string, mixed>  $state  State.
	 * @param array<string, string> $groups Prefix => group.
	 * @return array<string, mixed> The final state.
	 */
	private function audit( string $path, array $state, array $groups, int &$units = 0 ): array {
		while ( empty( $state['done'] ) ) {
			$state = IndexAudit::files_step( $path, $state, 1048576, $groups );
			++$units;
			// As the cursor keeps it between units.
			$state = json_decode( (string) json_encode( $state ), true );
		}
		return $state;
	}

	public function test_the_files_of_each_group_are_counted_by_the_root_the_scan_listed_them_under(): void {
		$groups = array(
			'wp-content'            => 'other-content',
			'wp-content/plugins'    => 'plugins',
			'wp-content/app-mu'     => 'mu-plugins', // A directory with a name of its own.
			'uploads'               => 'uploads',    // In the WordPress directory.
		);
		$path   = $this->files_index( array(
			'wp-content/plugins/a/a.php',
			'wp-content/plugins/b.php',
			'wp-content/plugins-extra/c.php', // A name that only starts alike: the content directory's.
			'wp-content/app-mu/loader.php',
			'wp-content/languages/de.mo',
			'wp-content/index.php',
			'uploads/2026/a.jpg',
			'uploads/2026/b.jpg',
			'uploads/2026/c.jpg',
		) );
		$state  = $this->audit( $path, IndexAudit::files_start( true ), $groups );
		$this->assertSame( 9, $state['count'] );
		$this->assertSame( array( 'plugins' => 2, 'other-content' => 3, 'mu-plugins' => 1, 'uploads' => 3 ), $state['groups'] );
		$this->assertSame( $state['count'], array_sum( $state['groups'] ) );

		// The control: the same index, audited without the counts (a state an earlier version began), says nothing of groups.
		$plain = $this->audit( $path, IndexAudit::files_start(), $groups );
		$this->assertSame( 9, $plain['count'] );
		$this->assertArrayNotHasKey( 'groups', $plain );
	}

	public function test_a_line_under_no_root_of_the_scan_stops_the_audit_that_counts_by_group(): void {
		$path = $this->files_index( array( 'wp-content/plugins/a.php', 'uploads/2026/a.jpg' ) );
		try {
			$this->audit( $path, IndexAudit::files_start( true ), array( 'wp-content/plugins' => 'plugins' ) );
			$this->fail( 'counted a line under no root' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'line 2 is under no content directory the scan listed', $e->getMessage() );
		}
		// The control: without the counts the same index is audited to its end.
		$this->assertSame( 2, $this->audit( $path, IndexAudit::files_start(), array( 'wp-content/plugins' => 'plugins' ) )['count'] );
	}

	public function test_the_counts_are_the_same_however_many_units_the_audit_takes(): void {
		$paths = array();
		for ( $i = 0; $i < IndexAudit::LINES_PER_UNIT + 7; $i++ ) {
			$paths[] = ( 0 === $i % 3 ? 'wp-content/uploads/' : ( 1 === $i % 3 ? 'wp-content/plugins/' : 'wp-content/' ) ) . 'f' . $i;
		}
		$groups = array( 'wp-content' => 'other-content', 'wp-content/plugins' => 'plugins', 'wp-content/uploads' => 'uploads' );
		$units  = 0;
		$state  = $this->audit( $this->files_index( $paths ), IndexAudit::files_start( true ), $groups, $units );
		$this->assertGreaterThan( 1, $units, 'the index takes more than one unit' );
		$expect = array( 'uploads' => 0, 'plugins' => 0, 'other-content' => 0 );
		foreach ( $paths as $i => $p ) {
			++$expect[ 0 === $i % 3 ? 'uploads' : ( 1 === $i % 3 ? 'plugins' : 'other-content' ) ];
		}
		$this->assertSame( $expect, $state['groups'] );
	}
}
