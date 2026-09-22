<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\IndexAudit;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class IndexAuditTest extends TestCase {

	/** @var string */
	private $dir;

	protected function set_up(): void {
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
}
