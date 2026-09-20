<?php

namespace WPCheckpoint\Tests\Unit\Database;

use WPCheckpoint\Database\SqlWriter;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SqlWriterTest extends TestCase {

	public function test_column_kinds_from_show_columns_types(): void {
		foreach ( array( 'bigint(20) unsigned', 'int(11)', 'tinyint(1)', 'smallint', 'mediumint(9)', 'decimal(10,2)', 'numeric(5)', 'float', 'double', 'real', 'year(4)', 'boolean' ) as $t ) {
			$this->assertSame( 'numeric', SqlWriter::kind( $t ), $t );
		}
		foreach ( array( 'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary(16)', 'varbinary(255)', 'bit(1)' ) as $t ) {
			$this->assertSame( 'binary', SqlWriter::kind( $t ), $t );
		}
		foreach ( array( 'varchar(255)', 'text', 'longtext', 'datetime', 'timestamp', 'date', 'enum(\'a\',\'b\')', 'json', 'char(36)', 'integer_like_name', 'interval' ) as $t ) {
			$this->assertSame( 'text', SqlWriter::kind( $t ), $t );
		}
	}

	public function test_values_are_quoted_hexed_or_left_bare_by_kind(): void {
		$w = new SqlWriter( 'utf8mb4' );
		$this->assertFalse( $w->hex_all() );
		$this->assertSame( 'NULL', $w->value( null, 'text' ) );
		$this->assertSame( 'NULL', $w->value( null, 'numeric' ) );
		$this->assertSame( '42', $w->value( '42', 'numeric' ) );
		$this->assertSame( '-7', $w->value( '-7', 'numeric' ) );
		$this->assertSame( '3.25', $w->value( '3.25', 'numeric' ) );
		$this->assertSame( '1e10', $w->value( '1e10', 'numeric' ) );
		$this->assertSame( '18446744073709551615', $w->value( '18446744073709551615', 'numeric' ), 'a big unsigned value stays as the driver gave it' );
		$this->assertSame( "'42'", $w->value( '42', 'text' ), 'a numeric-looking text value is still text' );
		$this->assertSame( "'0000-00-00 00:00:00'", $w->value( '0000-00-00 00:00:00', 'text' ) );
		$this->assertSame( "'abc'", $w->value( 'abc', 'numeric' ), 'a non-numeric value in a numeric column is quoted, never emitted bare' );
		$this->assertSame( "X'0001ff'", $w->value( "\x00\x01\xff", 'binary' ) );
		$this->assertSame( "''", $w->value( '', 'binary' ) );
		$this->assertSame( "''", $w->value( '', 'text' ) );
		$this->assertSame( "'it\\'s a \\\\ path\\nline\\r\\0nul\\Zsub'", $w->value( "it's a \\ path\nline\r\0nul\x1asub", 'text' ) );
		$this->assertSame( "'😀 表 ü'", $w->value( '😀 表 ü', 'text' ), 'multibyte text is kept as is' );
		$this->assertSame( "'a\"b'", $w->value( 'a"b', 'text' ), 'double quotes need no escaping in single-quoted strings' );
	}

	public function test_a_charset_where_backslash_escaping_is_unsafe_writes_all_strings_as_hex(): void {
		$w = new SqlWriter( 'gbk' );
		$this->assertTrue( $w->hex_all() );
		$this->assertSame( "X'616263'", $w->value( 'abc', 'text' ) );
		$this->assertSame( '42', $w->value( '42', 'numeric' ), 'numbers stay numbers' );
		$this->assertSame( 'NULL', $w->value( null, 'text' ) );
		foreach ( array( 'utf8', 'utf8mb3', 'utf8mb4', 'latin1', 'binary', 'UTF8MB4' ) as $safe ) {
			$this->assertTrue( SqlWriter::backslash_safe( $safe ), $safe );
		}
		foreach ( array( 'gbk', 'big5', 'sjis', 'cp932', 'gb2312', '' ) as $unsafe ) {
			$this->assertFalse( SqlWriter::backslash_safe( $unsafe ), $unsafe );
		}
	}

	public function test_identifiers_and_statement_heads(): void {
		$this->assertSame( '`wp_posts`', SqlWriter::identifier( 'wp_posts' ) );
		$this->assertSame( '`weird``name`', SqlWriter::identifier( 'weird`name' ) );
		$this->assertSame( 'INSERT INTO `t` (`a`, `b c`) VALUES ', SqlWriter::insert_head( 't', array( 'a', 'b c' ) ) );
		$w = new SqlWriter( 'utf8mb4' );
		$this->assertSame( "(1,'x',NULL,X'00')", $w->tuple( array( '1', 'x', null, "\x00" ), array( 'numeric', 'text', 'text', 'binary' ) ) );
	}
}
