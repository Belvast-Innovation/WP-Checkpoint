<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Restore\ChunkReader;
use WPCheckpoint\Restore\ConstraintNames;
use WPCheckpoint\Restore\ImportTarget;
use WPCheckpoint\Restore\Refused;
use WPCheckpoint\Restore\Statement;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The reader decides where each statement ends by the grammar of its kind
 * and refuses every statement outside the few kinds a chunk holds.
 */
final class ChunkReaderTest extends TestCase {

	/** @var string[] */
	private $files = array();

	protected function tear_down(): void {
		foreach ( $this->files as $file ) {
			@unlink( $file );
		}
		parent::tear_down();
	}

	private function file( string $sql ): string {
		$path = tempnam( sys_get_temp_dir(), 'wpc-chunk-' );
		file_put_contents( $path, $sql );
		$this->files[] = $path;
		return $path;
	}

	private function target( $columns = null ): ImportTarget {
		return new ImportTarget( 'wp_posts', 'wcptmpabcdef_7_1a2b_posts', 'wp_posts', 3, new ConstraintNames( '1a2b' ), static function ( string $table ): string {
			return 'wp_posts' === $table ? 'wcptmpabcdef_7_1a2b_posts' : $table;
		}, $columns );
	}

	/**
	 * Every statement of a chunk.
	 *
	 * @return Statement[]
	 */
	private function read( string $sql, int $read_bytes = ChunkReader::READ_BYTES, $columns = null, int $offset = 0 ): array {
		$reader = new ChunkReader( $this->file( $sql ), $offset, $this->target( $columns ), 1, $read_bytes );
		$out    = array();
		try {
			while ( null !== ( $statement = $reader->next() ) ) {
				$out[] = $statement;
			}
		} finally {
			$reader->close();
		}
		return $out;
	}

	/**
	 * A chunk as the exporter writes it, with the values that make splitting SQL hard.
	 */
	private static function chunk(): string {
		$values = array(
			"a;b",
			"it's; fine",
			"back\\slash",
			"ends with a backslash \\",
			"\\';DROP TABLE wp_users;--",
			"quote '' doubled and ; after",
			"line\nbreak;\r\n-- not a comment",
			"/* not a comment; */",
			"`backtick`; \"double\"",
			"\x00\x1a",
			"ünïcödé; ✓",
		);
		$rows = array();
		foreach ( $values as $i => $value ) {
			$rows[] = '(' . ( $i + 1 ) . ',' . SqlWriter::quote( $value ) . ',' . ( 0 === $i % 3 ? 'NULL' : ( 1 === $i % 3 ? SqlWriter::hex( $value ) : '-1.5e3' ) ) . ')';
		}
		return "-- wpcheckpoint table=wp_posts chunk=1 pk_from=null\n"
			. "-- wpcheckpoint bound pk_max=[\"11\"]\n"
			. "/*!40101 SET NAMES utf8mb4 */;\n"
			. "/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
			. "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n"
			. "DROP TABLE IF EXISTS `wp_posts`;\n"
			. "CREATE TABLE `wp_posts` (\n  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `post_title` text NOT NULL COMMENT 'a; b ''c'' \\\\',\n  `raw` blob,\n  PRIMARY KEY (`ID`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='x;y';\n"
			. SqlWriter::insert_head( 'wp_posts', array( 'ID', 'post_title', 'raw' ) ) . implode( ',', array_slice( $rows, 0, 4 ) ) . ";\n"
			. "-- wpcheckpoint batch rows=4 pk=[\"4\"]\n"
			// Rows on several lines, spaces around the punctuation: still one statement.
			. SqlWriter::insert_head( 'wp_posts', array( 'ID', 'post_title', 'raw' ) ) . "\n" . implode( " ,\n  ", array_slice( $rows, 4 ) ) . "\n;\n"
			. "-- wpcheckpoint batch rows=7 pk=[\"11\"]\n"
			. "-- wpcheckpoint end table=wp_posts chunk=1 rows=11 pk_to=[\"11\"]\n";
	}

	public function test_statements_end_where_their_grammar_ends_whatever_the_strings_hold(): void {
		$statements = $this->read( self::chunk() );
		$this->assertSame( array( 'set', 'set', 'set', 'drop', 'create', 'insert', 'insert' ), array_column( $statements, 'kind' ) );
		$this->assertSame( 'DROP TABLE IF EXISTS `wcptmpabcdef_7_1a2b_posts`', $statements[3]->sql );
		$this->assertStringStartsWith( 'CREATE TABLE `wcptmpabcdef_7_1a2b_posts` (', $statements[4]->sql );
		$this->assertStringEndsWith( "COMMENT='x;y'", $statements[4]->sql, 'the semicolon in the comment string did not end it' );
		$this->assertSame( array( 'ID', 'post_title', 'raw' ), $statements[4]->columns );
		$this->assertSame( array( 4, 7 ), array( $statements[5]->rows, $statements[6]->rows ) );
		$this->assertStringStartsWith( 'INSERT INTO `wcptmpabcdef_7_1a2b_posts` (`ID`, `post_title`, `raw`) VALUES (1,', $statements[5]->sql );
		$this->assertSame( strrpos( self::chunk(), "\n;\n" ) + 2, $statements[6]->end, 'the offset right after the last semicolon' );
	}

	public function test_every_read_size_gives_the_same_statements(): void {
		$whole = $this->read( self::chunk() );
		// Each read size puts the buffer's end at other places: inside an escape, between two quotes, in "--", in a comment line.
		for ( $size = 1; $size <= 64; $size++ ) {
			$this->assertEquals( $whole, $this->read( self::chunk(), $size ), 'read size ' . $size );
		}
	}

	public function test_it_resumes_at_a_statement_end(): void {
		$whole = $this->read( self::chunk() );
		$rest  = $this->read( self::chunk(), 7, null, $whole[4]->end );
		$this->assertEquals( array_slice( $whole, 5 ), $rest );
	}

	public function test_the_sql_it_returns_is_the_statement_as_written_but_the_table_name(): void {
		foreach ( $this->read( self::chunk() ) as $statement ) {
			if ( Statement::INSERT === $statement->kind ) {
				$original = str_replace( '`wcptmpabcdef_7_1a2b_posts`', '`wp_posts`', $statement->sql ) . ';';
				$this->assertStringContainsString( $original, self::chunk() );
			}
		}
	}

	public function test_an_insert_must_list_the_tables_stored_columns(): void {
		$this->assertCount( 7, $this->read( self::chunk(), ChunkReader::READ_BYTES, array( 'ID', 'post_title', 'raw' ) ), 'the control: the right list passes' );
		$this->assert_refused( self::chunk(), 'lists other columns', array( 'ID', 'post_title' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function refused_statements(): array {
		$head = "INSERT INTO `wp_posts` (`ID`, `post_title`) VALUES ";
		return array(
			'another kind'                  => array( "UPDATE `wp_posts` SET `post_title` = 'x';\n", 'does not run (UPDATE)' ),
			'truncate'                      => array( "TRUNCATE TABLE `wp_users`;\n", 'does not run (TRUNCATE)' ),
			'grant'                         => array( "GRANT ALL ON *.* TO 'x'@'%';\n", 'does not run (GRANT)' ),
			'insert into another table'     => array( "INSERT INTO `wp_users` (`ID`) VALUES (1);\n", 'another table' ),
			'insert with a function'        => array( $head . "(1,NOW());\n", 'literal values' ),
			'insert with a subquery'        => array( $head . "(1,(SELECT user_pass FROM wp_users LIMIT 1));\n", 'literal values' ),
			'insert select'                 => array( "INSERT INTO `wp_posts` (`ID`, `post_title`) SELECT 1, 2;\n", 'does not read "VALUES"' ),
			'insert with on duplicate'      => array( $head . "(1,'a') ON DUPLICATE KEY UPDATE `ID`=2;\n", 'after its rows' ),
			'insert and a second statement' => array( $head . "(1,'a'); DROP TABLE `wp_users`;\n", 'does not read "DROP TABLE IF EXISTS"' ),
			'drop another table'            => array( "DROP TABLE IF EXISTS `wp_users`;\n", 'another table' ),
			'drop two tables'               => array( "DROP TABLE IF EXISTS `wp_posts`, `wp_users`;\n", 'more than the table' ),
			'a comment inside an insert'    => array( $head . "(1,/* x */'a');\n", 'literal values' ),
			'a double-quoted value'         => array( $head . "(1,\"a\");\n", 'literal values' ),
			'a short row'                   => array( $head . "(1);\n", 'a row of 1 values for 2 columns' ),
			'a long row'                    => array( $head . "(1,'a',3);\n", 'a row of 3 values for 2 columns' ),
			'a bare word'                   => array( $head . "(1,abc);\n", 'literal values' ),
			'a variable'                    => array( $head . "(1,@x);\n", 'literal values' ),
			'a hex value with a non-hex'    => array( $head . "(1,X'4g');\n", 'not hexadecimal' ),
			'set names with more'           => array( "/*!40101 SET NAMES utf8mb4, @x=1 */;\n", 'not the session preamble' ),
			'set something else'            => array( "/*!40101 SET GLOBAL general_log=1 */;\n", 'not the session preamble' ),
			'a plain set'                   => array( "SET FOREIGN_KEY_CHECKS=0;\n", 'does not run (SET)' ),
			'a block comment'               => array( "/* x */ DROP TABLE `wp_users`;\n", 'does not run' ),
			'a hash comment'                => array( "# x\nDROP TABLE `wp_users`;\n", 'does not run' ),
			'no semicolon at the end'       => array( $head . "(1,'a')", 'ends inside a statement' ),
			'a string never closed'         => array( $head . "(1,'a);\n", 'ends inside a statement' ),
			'create another table'          => array( "CREATE TABLE `wp_users` (`ID` int);\n", 'names another table' ),
		);
	}

	/**
	 * @dataProvider refused_statements
	 */
	public function test_statements_outside_the_chunk_grammar_are_refused_before_they_run( string $sql, string $reason ): void {
		$this->assert_refused( "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n" . $sql, $reason );
	}

	public function test_the_preamble_only_at_the_head_and_heads_only_stops_at_values(): void {
		$this->assert_refused( "DROP TABLE IF EXISTS `wp_posts`;\n/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\n", 'preamble after other statements' );
		$whole  = $this->read( self::chunk() );
		$reader = new ChunkReader( $this->file( substr( self::chunk(), 0, $whole[4]->end + 80 ) ), 0, $this->target(), 1, ChunkReader::READ_BYTES, true );
		$kinds  = array();
		while ( null !== ( $statement = $reader->next() ) ) {
			$kinds[] = $statement->kind;
			if ( Statement::INSERT === $statement->kind ) {
				$this->assertSame( array( 'ID', 'post_title', 'raw' ), $statement->columns );
				$this->assertSame( '', $statement->sql, 'a head is never run' );
				break;
			}
		}
		$reader->close();
		$this->assertSame( array( 'set', 'set', 'set', 'drop', 'create', 'insert' ), $kinds, 'the first INSERT\'s columns without its rows, from a cut-off chunk' );
	}

	public function test_the_refusal_names_the_table_chunk_and_offset(): void {
		try {
			$this->read( "/*!40014 SET FOREIGN_KEY_CHECKS=0 */;\nUPDATE x;" );
			$this->fail( 'refused' );
		} catch ( Refused $e ) {
			$this->assertSame( 'Table wp_posts, chunk 1, byte 38: A statement of a kind the restore does not run (UPDATE).', $e->getMessage() );
		}
	}

	public function test_a_statement_larger_than_the_limit_is_refused_and_one_at_the_limit_is_read_within_the_step_budget(): void {
		$head = SqlWriter::insert_head( 'wp_posts', array( 'ID', 'post_title' ) );
		$room = ChunkReader::MAX_STATEMENT_BYTES - strlen( $head ) - strlen( "(1,'');" );
		// Written in pieces, so the fixture never sits in memory before the measurement.
		$path   = $this->file( '' );
		$handle = fopen( $path, 'wb' );
		fwrite( $handle, $head . "(1,'" );
		for ( $left = $room; $left > 0; $left -= 65536 ) {
			fwrite( $handle, str_repeat( 'a', min( 65536, $left ) ) );
		}
		fwrite( $handle, "');\n" );
		fclose( $handle );
		$this->assertSame( ChunkReader::MAX_STATEMENT_BYTES + 1, filesize( $path ) );

		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
			$before = memory_get_usage();
		} else {
			$before = memory_get_peak_usage();
		}
		$reader    = new ChunkReader( $path, 0, $this->target(), 1 );
		$statement = $reader->next();
		$reader->close();
		$this->assertSame( 1, $statement->rows, 'the largest statement is read' );
		$this->assertLessThan( 32 * 1048576, memory_get_peak_usage() - $before, 'within the 32 MB step budget' );
		unset( $statement );

		$this->assert_refused( $head . "(1,'" . str_repeat( 'a', $room + 1 ) . "');\n", 'larger than' );
	}

	private function assert_refused( string $sql, string $reason, $columns = null ): void {
		foreach ( array( ChunkReader::READ_BYTES, 3 ) as $size ) {
			try {
				$this->read( $sql, $size, $columns );
				$this->fail( 'refused: ' . $reason );
			} catch ( Refused $e ) {
				$this->assertStringContainsString( $reason, $e->getMessage() );
			}
		}
	}
}
