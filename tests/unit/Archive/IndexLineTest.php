<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\ChunkHasher;
use WPCheckpoint\Archive\EntryPath;
use WPCheckpoint\Archive\IndexLine;
use WPCheckpoint\Archive\IndexLineError;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class IndexLineTest extends TestCase {

	const CHUNK = 1048576;
	const H1    = 'a7e850497d2e33cb3465a56de6b29759bf85c550d47c11b1c4672fb2d1556a7a';
	const H2    = 'fb04dcb6970e4c3d1873de51fd5a50d7bb46b3383113602665c350ec40b5f990';
	const H3    = 'e0d2747b9ab7abb6eb65e0373fa1b428a28bd6d8a2380106dcc080f58005ee14';

	private function db( array $overrides = array(), array $drop = array() ): string {
		$line = array_merge(
			array(
				't' => 'wp_posts',
				'c' => 12,
				'p' => 'database/wp_posts.0012.sql',
				'b' => 500000,
				'h' => self::H1,
			),
			$overrides
		);
		foreach ( $drop as $key ) {
			unset( $line[ $key ] );
		}
		return json_encode( $line, JSON_UNESCAPED_SLASHES );
	}

	private function file( array $overrides = array(), array $drop = array() ): string {
		$line = array_merge(
			array(
				'p' => 'wp-content/uploads/a.jpg',
				'b' => 12345,
				'm' => 1726560000,
				'h' => self::H1,
			),
			$overrides
		);
		foreach ( $drop as $key ) {
			unset( $line[ $key ] );
		}
		return json_encode( $line, JSON_UNESCAPED_SLASHES );
	}

	public function test_valid_lines(): void {
		$this->assertSame(
			array(
				't' => 'wp_posts',
				'c' => 12,
				'p' => 'database/wp_posts.0012.sql',
				'b' => 500000,
				'h' => self::H1,
			),
			IndexLine::database( $this->db(), 16777216 )
		);
		$this->assertSame( 'database/wp_posts.12345.sql', IndexLine::database_path( 'wp_posts', 12345 ) );
		$this->assertSame( 'database/t.0001.sql', IndexLine::database_path( 't', 1 ) );

		$small = IndexLine::files( $this->file(), self::CHUNK );
		$this->assertSame( self::H1, $small['h'] );
		$this->assertNull( $small['hc'] );
		$plain = IndexLine::files( $this->file( array(), array( 'h' ) ), self::CHUNK );
		$this->assertNull( $plain['h'] );
		$this->assertNull( $plain['hc'] );
		$big = IndexLine::files( $this->file( array( 'b' => self::CHUNK * 2 + 1, 'h' => ChunkHasher::list_hash( array( self::H1, self::H2, self::H3 ) ), 'hc' => array( self::H1, self::H2, self::H3 ) ) ), self::CHUNK );
		$this->assertSame( array( self::H1, self::H2, self::H3 ), $big['hc'] );
		// Exactly chunk_bytes is small; one more byte needs two chunks.
		$this->assertNull( IndexLine::files( $this->file( array( 'b' => self::CHUNK ) ), self::CHUNK )['hc'] );
		$two = IndexLine::files( $this->file( array( 'b' => self::CHUNK + 1, 'h' => ChunkHasher::list_hash( array( self::H1, self::H2 ) ), 'hc' => array( self::H1, self::H2 ) ) ), self::CHUNK );
		$this->assertCount( 2, $two['hc'] );
		// A large file without a hash is fine: hashing is optional.
		$this->assertNull( IndexLine::files( $this->file( array( 'b' => self::CHUNK * 5 ), array( 'h' ) ), self::CHUNK )['h'] );
		// Unknown keys are ignored.
		$this->assertSame( 5, IndexLine::database( $this->db( array( 'c' => 5, 'p' => 'database/wp_posts.0005.sql', 'extra' => array( 1 ) ) ), self::CHUNK )['c'] );
	}

	/**
	 * @dataProvider rejected_database_lines
	 */
	public function test_rejected_database_lines( string $line, string $key ): void {
		try {
			IndexLine::database( $line, self::CHUNK );
		} catch ( IndexLineError $e ) {
			$this->assertSame( $key, $e->key(), $e->getMessage() );
			return;
		}
		$this->fail( 'Accepted: ' . $line );
	}

	public function rejected_database_lines(): array {
		return array(
			'empty'                 => array( '', '' ),
			'over-long'             => array( '{"t":"wp_posts","c":1,"x":"' . str_repeat( 'a', IndexLine::MAX_LINE_BYTES ) . '"}', '' ),
			'not an object'         => array( '[1,2]', '' ),
			'not json'              => array( '{"t":', '' ),
			'scalar'                => array( '"x"', '' ),
			'missing t'             => array( $this->db( array(), array( 't' ) ), 't' ),
			'empty t'               => array( $this->db( array( 't' => '' ) ), 't' ),
			'control char in t'     => array( $this->db( array( 't' => "wp\x01" ) ), 't' ),
			'long t'                => array( $this->db( array( 't' => str_repeat( 'a', 65 ) ) ), 't' ),
			'c zero'                => array( $this->db( array( 'c' => 0, 'p' => 'database/wp_posts.0000.sql' ) ), 'c' ),
			'c string'              => array( $this->db( array( 'c' => '12' ) ), 'c' ),
			'c float'               => array( '{"t":"wp_posts","c":12.0,"p":"database/wp_posts.0012.sql","b":1,"h":"' . self::H1 . '"}', 'c' ),
			'c too large'           => array( $this->db( array( 'c' => 100001 ) ), 'c' ),
			'p wrong table'         => array( $this->db( array( 'p' => 'database/wp_users.0012.sql' ) ), 'p' ),
			'p wrong chunk'         => array( $this->db( array( 'p' => 'database/wp_posts.0013.sql' ) ), 'p' ),
			'p no padding'          => array( $this->db( array( 'p' => 'database/wp_posts.12.sql' ) ), 'p' ),
			'p traversal'           => array( $this->db( array( 'p' => '../database/wp_posts.0012.sql' ) ), 'p' ),
			'p absolute'            => array( $this->db( array( 'p' => '/database/wp_posts.0012.sql' ) ), 'p' ),
			'p backslash'           => array( $this->db( array( 't' => 'a\\b', 'p' => 'database/a\\b.0012.sql' ) ), 'p' ),
			'b over chunk'          => array( $this->db( array( 'b' => self::CHUNK + 1 ) ), 'b' ),
			'b negative'            => array( $this->db( array( 'b' => -1 ) ), 'b' ),
			'h uppercase'           => array( $this->db( array( 'h' => strtoupper( self::H1 ) ) ), 'h' ),
			'h short'               => array( $this->db( array( 'h' => 'abc' ) ), 'h' ),
			'h missing'             => array( $this->db( array(), array( 'h' ) ), 'h' ),
			'nested too deep'       => array( '{"t":"wp_posts","c":1,"p":"database/wp_posts.0001.sql","b":1,"h":"' . self::H1 . '","x":{"y":{"z":{"w":1}}}}', '' ),
		);
	}

	/**
	 * @dataProvider rejected_file_lines
	 */
	public function test_rejected_file_lines( string $line, string $key ): void {
		try {
			IndexLine::files( $line, self::CHUNK );
		} catch ( IndexLineError $e ) {
			$this->assertSame( $key, $e->key(), $e->getMessage() );
			return;
		}
		$this->fail( 'Accepted: ' . $line );
	}

	public function rejected_file_lines(): array {
		$list = ChunkHasher::list_hash( array( self::H1, self::H2 ) );
		return array(
			'missing p'                    => array( $this->file( array(), array( 'p' ) ), 'p' ),
			'p traversal'                  => array( $this->file( array( 'p' => 'wp-content/../wp-config.php' ) ), 'p' ),
			'p with nul'                   => array( $this->file( array( 'p' => "a\x00b" ) ), 'p' ),
			'p scheme'                     => array( $this->file( array( 'p' => 'c:/x' ) ), 'p' ),
			'missing b'                    => array( $this->file( array(), array( 'b' ) ), 'b' ),
			'b bool'                       => array( $this->file( array( 'b' => true ) ), 'b' ),
			'missing m'                    => array( $this->file( array(), array( 'm' ) ), 'm' ),
			'm negative'                   => array( $this->file( array( 'm' => -5 ) ), 'm' ),
			'h not hex'                    => array( $this->file( array( 'h' => 'zz' ) ), 'h' ),
			'h null'                       => array( $this->file( array( 'h' => null ) ), 'h' ),
			'hc without h'                 => array( $this->file( array( 'b' => self::CHUNK + 1, 'hc' => array( self::H1, self::H2 ) ), array( 'h' ) ), 'hc' ),
			'hc on a small file'           => array( $this->file( array( 'hc' => array( self::H1 ) ) ), 'hc' ),
			'hc missing on a large file'   => array( $this->file( array( 'b' => self::CHUNK + 1 ) ), 'hc' ),
			'hc wrong count'               => array( $this->file( array( 'b' => self::CHUNK + 1, 'h' => $list, 'hc' => array( self::H1 ) ) ), 'hc' ),
			'hc not a list'                => array( $this->file( array( 'b' => self::CHUNK + 1, 'h' => $list, 'hc' => 'x' ) ), 'hc' ),
			'hc bad element'               => array( $this->file( array( 'b' => self::CHUNK + 1, 'h' => $list, 'hc' => array( self::H1, 'nope' ) ) ), 'hc[1]' ),
			'h is not the list hash'       => array( $this->file( array( 'b' => self::CHUNK + 1, 'h' => self::H3, 'hc' => array( self::H1, self::H2 ) ) ), 'h' ),
		);
	}

	public function test_platform_limits_are_named(): void {
		if ( PHP_INT_SIZE < 8 ) {
			$this->markTestSkipped( 'Only meaningful on 64-bit PHP: the value must decode as an integer here.' );
		}
		try {
			IndexLine::files( $this->file( array( 'b' => 9007199254740992 ) ), self::CHUNK );
			$this->fail( 'Above MAX_BYTES must be rejected.' );
		} catch ( IndexLineError $e ) {
			$this->assertSame( 'b', $e->key() );
		}
		$this->assertSame( 4294967296, IndexLine::files( $this->file( array( 'b' => 4294967296 ), array( 'h' ) ), self::CHUNK )['b'] );
	}

	/**
	 * The line limit is reachable: the longest legal line (the longest path,
	 * the most chunk hashes) decodes within a unit's memory budget, and one
	 * more chunk does not fit. The largest indexable file follows from it.
	 */
	public function test_the_longest_legal_line_decodes_within_budget_and_one_more_chunk_does_not_fit(): void {
		if ( PHP_INT_SIZE < 8 ) {
			$this->markTestSkipped( 'The largest indexable file does not fit a 32-bit integer.' );
		}
		$chunks = IndexLine::max_chunks();
		$this->assertSame( 15587, $chunks );
		$this->assertSame( 15587 * 16777216, IndexLine::max_indexable_bytes( 16777216 ), 'about 244 GiB at the default chunk size' );
		$path = str_repeat( 'a', EntryPath::MAX_BYTES );
		$this->assertNull( EntryPath::problem( $path ) );
		$build = static function ( int $count, string $path ): string {
			$hc = array();
			for ( $i = 0; $i < $count; $i++ ) {
				$hc[] = hash( 'sha256', (string) $i );
			}
			return json_encode( array( 'p' => $path, 'b' => $count * 16777216, 'm' => 9007199254740991, 'h' => ChunkHasher::list_hash( $hc ), 'hc' => $hc ), JSON_UNESCAPED_SLASHES );
		};
		$line = $build( $chunks, $path );
		$this->assertLessThanOrEqual( IndexLine::MAX_LINE_BYTES, strlen( $line ) );
		$before = memory_get_peak_usage();
		$parsed = IndexLine::files( $line, 16777216 );
		$this->assertLessThan( 32 * 1048576, memory_get_peak_usage() - $before, 'one line decodes within the unit memory budget' );
		$this->assertCount( $chunks, $parsed['hc'] );
		$this->assertSame( 15587 * 16777216, $parsed['b'] );
		try {
			IndexLine::files( $build( $chunks + 1, $path ), 16777216 );
			$this->fail( 'one more chunk must not fit' );
		} catch ( IndexLineError $e ) {
			$this->assertSame( '', $e->key() );
			$this->assertStringContainsString( 'over-long', $e->getMessage() );
		}
	}
}
