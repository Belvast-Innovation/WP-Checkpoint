<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * A tear-down that ends the test's transaction rolls it back first. The WordPress test case keeps a transaction open
 * over each test; a COMMIT in a tear-down, or a statement the server commits before (DROP, CREATE, ALTER, TRUNCATE,
 * RENAME and the others of ENDS), commits whatever the test wrote and left uncommitted, onto the site the tests after
 * it run on.
 *
 * Checked, for every tear_down(), tearDown(), tear_down_after_class() and tearDownAfterClass() in tests/: of the
 * statements written as string literals in its body before the call of the parent's method, and in the bodies of the
 * methods it calls there (followed through $this->, self::, static::, parent:: and Name:: to the classes and traits
 * of tests/, by their short names and up their parents), the first that ends a transaction has a ROLLBACK before it.
 *
 * Not seen: a statement that is not a literal beginning with its keyword (built in a variable, returned by a
 * function), a call on another object ($other->method()), a plain function, and whatever the plugin's own code does
 * when a tear-down calls it. Two classes of tests/ with one short name are taken as the one read last. A ROLLBACK
 * inside a condition counts as one.
 */
final class TearDownCommitTest extends TestCase {

	/** The statements that end a transaction, as a literal begins with them. */
	private const ENDS = '/^\s*(COMMIT|DROP|CREATE|ALTER|TRUNCATE|RENAME|LOCK\s+TABLES?|UNLOCK\s+TABLES?|START\s+TRANSACTION|BEGIN|SET\s+autocommit|ANALYZE|OPTIMIZE|REPAIR|FLUSH)(?=\s|;|$)/i';

	private const ROLLBACK = '/^\s*ROLLBACK\s*;?\s*$/i';

	private const TEAR_DOWNS = array( 'tear_down', 'tearDown', 'tear_down_after_class', 'tearDownAfterClass' );

	/**
	 * The tokens of a source without its comments and whitespace.
	 *
	 * @param string $source A PHP source.
	 * @return array<int, array{0: string, 1: string, 2: int}> Kind (the token's name, or the character), text, line.
	 */
	private static function tokens( string $source ): array {
		$out  = array();
		$line = 1;
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) ) {
				$line = $token[2];
				if ( ! in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT, T_WHITESPACE ), true ) ) {
					$out[] = array( token_name( $token[0] ), $token[1], $line );
				}
				$line += substr_count( $token[1], "\n" );
			} else {
				$out[] = array( $token, $token, $line );
			}
		}
		return $out;
	}

	/**
	 * The last part of a name ("\A\B\C" is "C").
	 */
	private static function short( string $name ): string {
		$at = strrpos( $name, '\\' );
		return false === $at ? $name : substr( $name, $at + 1 );
	}

	/**
	 * The classes and traits of a source with their methods.
	 *
	 * @param string $source A PHP source.
	 * @return array<string, array{parent: string, methods: array<string, array{line: int, tokens: array<int, array{0: string, 1: string, 2: int}>}>}>
	 */
	private static function classes( string $source ): array {
		$tokens = self::tokens( $source );
		$count  = count( $tokens );
		$out    = array();
		$depth  = 0;
		$class  = null;
		$method = null;
		$inside = array( -1, -1 ); // The depth the class and the method were opened at.
		for ( $i = 0; $i < $count; $i++ ) {
			list( $kind, $text ) = $tokens[ $i ];
			if ( '}' === $kind ) {
				--$depth;
				if ( null !== $method && $depth === $inside[1] ) {
					$method = null;
					continue;
				}
				if ( null !== $class && $depth === $inside[0] ) {
					$class = null;
				}
			}
			if ( null !== $method ) {
				$out[ $class ]['methods'][ $method ]['tokens'][] = $tokens[ $i ];
			}
			if ( in_array( $kind, array( '{', 'T_CURLY_OPEN', 'T_DOLLAR_OPEN_CURLY_BRACES' ), true ) ) {
				++$depth;
				continue;
			}
			$next = $tokens[ $i + 1 ] ?? array( '', '', 0 );
			$last = $tokens[ $i - 1 ] ?? array( '', '', 0 );
			if ( null === $class && in_array( $kind, array( 'T_CLASS', 'T_TRAIT' ), true ) && 'T_STRING' === $next[0] && ! in_array( $last[0], array( 'T_DOUBLE_COLON', 'T_NEW' ), true ) ) {
				$class  = $next[1];
				$parent = '';
				for ( $j = $i + 2; $j < $count && '{' !== $tokens[ $j ][0]; $j++ ) {
					if ( 'T_EXTENDS' === $tokens[ $j ][0] ) {
						for ( $k = $j + 1; $k < $count && in_array( $tokens[ $k ][0], array( 'T_STRING', 'T_NS_SEPARATOR', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ), true ); $k++ ) {
							$parent = self::short( $tokens[ $k ][1] );
						}
					}
				}
				$out[ $class ] = array(
					'parent'  => $parent,
					'methods' => array(),
				);
				$inside[0]     = $depth;
				$i             = $j - 1;
				continue;
			}
			if ( null !== $class && null === $method && 'T_FUNCTION' === $kind && 'T_STRING' === $next[0] && $depth === $inside[0] + 1 ) {
				for ( $j = $i + 2; $j < $count && '{' !== $tokens[ $j ][0] && ';' !== $tokens[ $j ][0]; $j++ ) {
					continue;
				}
				if ( $j < $count && '{' === $tokens[ $j ][0] ) {
					$method                              = $next[1];
					$out[ $class ]['methods'][ $method ] = array(
						'line'   => $tokens[ $i ][2],
						'tokens' => array(),
					);
					$inside[1]                           = $depth;
					++$depth;
				}
				$i = $j;
			}
		}
		return $out;
	}

	/**
	 * A method of a class, its own or the nearest parent's.
	 *
	 * @param array<string, array<string, mixed>> $all   The classes.
	 * @param string                              $class Class.
	 * @param string                              $name  Method.
	 * @return array{0: string, 1: array<int, array{0: string, 1: string, 2: int}>}|null The class it is in, its tokens.
	 */
	private static function method( array $all, string $class, string $name ): ?array {
		for ( $i = 0; $i < 30 && isset( $all[ $class ] ); $i++ ) {
			if ( isset( $all[ $class ]['methods'][ $name ] ) ) {
				return array( $class, $all[ $class ]['methods'][ $name ]['tokens'] );
			}
			$class = $all[ $class ]['parent'];
		}
		return null;
	}

	/**
	 * The string literals of some tokens in their order, those of the methods called among them in the call's place.
	 *
	 * @param array<string, array<string, mixed>>             $all    The classes.
	 * @param string                                          $class  The class the tokens are in.
	 * @param array<int, array{0: string, 1: string, 2: int}> $tokens Tokens.
	 * @param array<string, bool>                             $seen   The methods being read (none is read in itself).
	 * @return string[]
	 */
	private static function literals( array $all, string $class, array $tokens, array $seen ): array {
		$out   = array();
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			list( $kind, $text ) = $tokens[ $i ];
			if ( 'T_CONSTANT_ENCAPSED_STRING' === $kind ) {
				$out[] = substr( $text, 1, -1 );
				continue;
			}
			if ( 'T_ENCAPSED_AND_WHITESPACE' === $kind && $i > 0 && in_array( $tokens[ $i - 1 ][0], array( '"', 'T_START_HEREDOC' ), true ) ) {
				$out[] = $text;
				continue;
			}
			if ( 'T_STRING' !== $kind || $i < 2 || '(' !== ( $tokens[ $i + 1 ][0] ?? '' ) ) {
				continue;
			}
			$by = $tokens[ $i - 1 ][0];
			$of = $tokens[ $i - 2 ];
			$in = null;
			if ( 'T_OBJECT_OPERATOR' === $by && '$this' === $of[1] ) {
				$in = $class;
			} elseif ( 'T_DOUBLE_COLON' === $by && ( 'T_STATIC' === $of[0] || 'self' === strtolower( $of[1] ) ) ) {
				$in = $class;
			} elseif ( 'T_DOUBLE_COLON' === $by && 'parent' === strtolower( $of[1] ) ) {
				$in = $all[ $class ]['parent'] ?? '';
			} elseif ( 'T_DOUBLE_COLON' === $by && in_array( $of[0], array( 'T_STRING', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED' ), true ) ) {
				$in = self::short( $of[1] );
			}
			$hit = null === $in ? null : self::method( $all, $in, $text );
			if ( null === $hit || isset( $seen[ $hit[0] . '::' . $text ] ) ) {
				continue;
			}
			$out = array_merge( $out, self::literals( $all, $hit[0], $hit[1], $seen + array( $hit[0] . '::' . $text => true ) ) );
		}
		return $out;
	}

	/**
	 * What each tear-down says to the database before its parent's, as far as this reads it: the statements that end
	 * a transaction and the rollbacks, in their order.
	 *
	 * @param array<string, string> $sources PHP sources by their paths.
	 * @return array<string, array{at: string, says: string[]}> By "Class::method"; "at" is "path:line".
	 */
	public static function said( array $sources ): array {
		$all   = array();
		$where = array();
		foreach ( $sources as $path => $source ) {
			foreach ( self::classes( $source ) as $name => $class ) {
				$all[ $name ]   = $class;
				$where[ $name ] = $path;
			}
		}
		$out = array();
		foreach ( $all as $name => $class ) {
			foreach ( $class['methods'] as $method => $body ) {
				if ( ! in_array( $method, self::TEAR_DOWNS, true ) ) {
					continue;
				}
				$tokens = $body['tokens'];
				$count  = count( $tokens );
				for ( $i = 0; $i + 2 < $count; $i++ ) {
					if ( 'parent' === strtolower( $tokens[ $i ][1] ) && 'T_DOUBLE_COLON' === $tokens[ $i + 1 ][0] && $method === $tokens[ $i + 2 ][1] ) {
						$tokens = array_slice( $tokens, 0, $i );
						break;
					}
				}
				$says = array();
				foreach ( self::literals( $all, $name, $tokens, array( $name . '::' . $method => true ) ) as $literal ) {
					if ( 1 === preg_match( self::ROLLBACK, $literal ) ) {
						$says[] = 'ROLLBACK';
					} elseif ( 1 === preg_match( self::ENDS, $literal, $m ) ) {
						$says[] = strtoupper( (string) preg_replace( '/\s+/', ' ', $m[1] ) );
					}
				}
				$out[ $name . '::' . $method ] = array(
					'at'   => $where[ $name ] . ':' . $body['line'],
					'says' => $says,
				);
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The tear-downs that end the test's transaction without rolling it back first.
	 *
	 * @param array<string, string> $sources PHP sources by their paths.
	 * @return string[] "path:line Class::method (the statement)".
	 */
	public static function found( array $sources ): array {
		$out = array();
		foreach ( self::said( $sources ) as $name => $it ) {
			if ( array() !== $it['says'] && 'ROLLBACK' !== $it['says'][0] ) {
				$out[] = $it['at'] . ' ' . $name . ' (' . $it['says'][0] . ')';
			}
		}
		return $out;
	}

	/**
	 * A test class whose tear-down runs some lines before its parent's.
	 *
	 * @param string $lines  The lines.
	 * @param string $method The tear-down's name.
	 * @param string $more   More methods of the class.
	 * @return array<string, string>
	 */
	private static function source( string $lines, string $method = 'tear_down', string $more = '' ): array {
		$static = false !== strpos( strtolower( $method ), 'class' ) ? 'static ' : '';
		return array( 'a.php' => "<?php\nclass A extends B {\n\tpublic {$static}function {$method}(): void {\n{$lines}\t\tparent::{$method}();\n\t}\n{$more}}\n" );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function ending(): array {
		$out = array();
		foreach ( array( 'COMMIT', 'DROP TABLE t', 'DROP TABLE IF EXISTS t', 'CREATE TABLE t ( id int )', 'ALTER TABLE t ENGINE=InnoDB', 'TRUNCATE TABLE t', 'TRUNCATE t', 'RENAME TABLE t TO u', 'LOCK TABLES t WRITE', 'UNLOCK TABLES', 'START TRANSACTION', 'BEGIN', 'SET autocommit = 1', 'ANALYZE TABLE t', 'OPTIMIZE TABLE t', 'REPAIR TABLE t', 'FLUSH TABLES', 'commit', ' drop table t', 'COMMIT;' ) as $statement ) {
			$out[ $statement ] = array( $statement );
		}
		return $out;
	}

	/**
	 * @dataProvider ending
	 */
	public function test_each_statement_that_ends_a_transaction_is_found_without_a_rollback_before_it( string $statement ): void {
		$query = "\t\t\$wpdb->query( '{$statement}' );\n";
		$roll  = "\t\t\$wpdb->query( 'ROLLBACK' );\n";
		foreach ( self::TEAR_DOWNS as $method ) {
			$this->assertCount( 1, self::found( self::source( $query, $method ) ), "in {$method}()" );
			$this->assertStringStartsWith( 'a.php:3 A::' . $method . ' (', self::found( self::source( $query, $method ) )[0] );
			$this->assertSame( array(), self::found( self::source( $roll . $query, $method ) ), 'rolled back first' );
			$this->assertCount( 1, self::found( self::source( $query . $roll, $method ) ), 'a rollback after it is none before it' );
		}
		// Built with a name after it, in double quotes, in a helper, in a helper of the parent, in another class.
		$this->assertCount( 1, self::found( self::source( "\t\t\$wpdb->query( '{$statement} ' . \$name );\n" ) ) );
		$this->assertCount( 1, self::found( self::source( "\t\t\$wpdb->query( \"{$statement} {\$name}\" );\n" ) ) );
		$helper = "\tprivate function put_away(): void {\n{$query}\t}\n";
		$this->assertCount( 1, self::found( self::source( "\t\t\$this->put_away();\n", 'tear_down', $helper ) ) );
		$this->assertCount( 1, self::found( self::source( "\t\tself::put_away();\n", 'tear_down', $helper ) ) );
		$this->assertCount( 1, self::found( self::source( "\t\tstatic::put_away();\n", 'tear_down', $helper ) ) );
		$this->assertSame( array(), self::found( self::source( $roll . "\t\t\$this->put_away();\n", 'tear_down', $helper ) ) );
		$this->assertSame( array(), self::found( self::source( "\t\t\$this->put_away();\n", 'tear_down', "\tprivate function put_away(): void {\n{$roll}{$query}\t}\n" ) ), 'the helper rolls back first itself' );
		$parent = array( 'b.php' => "<?php\nabstract class B {\n\tprotected function put_away(): void {\n\t\t\$this->deeper();\n\t}\n\tprivate function deeper(): void {\n{$query}\t}\n}\n" );
		$this->assertCount( 1, self::found( self::source( "\t\t\$this->put_away();\n" ) + $parent ), 'a helper of the parent that calls another' );
		$this->assertCount( 1, self::found( self::source( "\t\tparent::put_away();\n" ) + $parent ) );
		$other = array( 'c.php' => "<?php\nnamespace N;\nfinal class C {\n\tpublic static function off(): void {\n{$query}\t}\n}\n" );
		$this->assertCount( 1, self::found( self::source( "\t\tC::off();\n" ) + $other ) );
		$this->assertCount( 1, self::found( self::source( "\t\t\\N\\C::off();\n" ) + $other ) );
		$this->assertSame( array(), self::found( self::source( "\t\t\\N\\C::off();\n" ) + array( 'c.php' => str_replace( $query, $roll . $query, $other['c.php'] ) ) ) );
	}

	public function test_what_does_not_end_the_tests_transaction_is_not_found(): void {
		$commit = "\t\t\$wpdb->query( 'COMMIT' );\n";
		$this->assertCount( 1, self::found( self::source( $commit ) ), 'the control' );
		$this->assertSame( array(), self::found( array( 'a.php' => "<?php\nclass A extends B {\n\tpublic function tear_down(): void {\n\t\tparent::tear_down();\n{$commit}\t}\n}\n" ) ), 'after the parent\'s tear-down the transaction is rolled back already' );
		$this->assertSame( array(), self::found( self::source( '', 'tear_down', "\tpublic function write(): void {\n{$commit}\t}\n" ) ), 'a method the tear-down does not call' );
		$this->assertSame( array(), self::found( self::source( "\t\t// \$wpdb->query( 'COMMIT' );\n\t\t\$wpdb->query( 'DELETE FROM t' );\n\t\t\$a = array( 'dropped' => 'created', 'begin_at' => 'COMMITTED' );\n" ) ), 'a comment, a statement that ends nothing, words that only begin like one' );
		$this->assertSame( array(), self::found( self::source( "\t\t\$this->write( '}' );\n", 'tear_down', "\tprivate function write( string \$s ): void {\n\t\techo \"{\$s} {\";\n\t}\n\tpublic function other(): void {\n{$commit}\t}\n" ) ), 'a brace in a string ends no method' );
		$this->assertCount( 1, self::found( self::source( "\t\t\$f = function () {\n\t\t\treturn '}';\n\t\t};\n{$commit}" ) ), 'nor does a closure' );
		$this->assertSame( array(), self::found( self::source( "\t\t\$this->tear_down();\n\t\t\$this->again();\n", 'tear_down', "\tprivate function again(): void {\n\t\t\$this->again();\n\t}\n" ) ), 'a method that calls itself is read once' );
	}

	public function test_no_tear_down_ends_the_tests_transaction_without_rolling_it_back_first(): void {
		$tests   = dirname( __DIR__, 2 );
		$sources = array();
		$items   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $tests, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $path => $item ) {
			if ( $item->isFile() && '.php' === substr( (string) $path, -4 ) && __FILE__ !== (string) $path ) {
				$sources[ str_replace( '\\', '/', substr( (string) $path, strlen( $tests ) + 1 ) ) ] = (string) file_get_contents( (string) $path );
			}
		}
		$said = self::said( $sources );
		// The controls: the scan read the base test cases, followed their helpers, and found what they say.
		$this->assertSame( array( 'ROLLBACK', 'DROP' ), array_slice( $said['JobTestCase::tear_down']['says'] ?? array(), 0, 2 ) );
		$this->assertSame( array( 'ROLLBACK', 'COMMIT' ), array_slice( $said['SwapTestCase::tear_down']['says'] ?? array(), 0, 2 ), 'through tear_down_swap() and discard_abandoned()' );
		$this->assertSame( array( 'ROLLBACK', 'COMMIT' ), $said['SiteCheckHttpTest::tear_down']['says'] ?? array(), 'through ActivePlugin::off()' );
		$this->assertSame( array( 'ROLLBACK', 'DROP' ), $said['RestoreTearDownTest::tear_down_after_class']['says'] ?? array() );
		$ending = array_filter(
			$said,
			static function ( array $it ): bool {
				return count( $it['says'] ) > 1;
			}
		);
		$this->assertGreaterThan( 20, count( $ending ), 'tear-downs that end the transaction are there to be checked' );
		$this->assertSame( array(), self::found( $sources ), 'these tear-downs end the test\'s transaction before the parent\'s tear-down without rolling it back first' );
	}
}
