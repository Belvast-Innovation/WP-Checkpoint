<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every write of the swap phase to the plugin's tables and the temporary tables (the final check, the swap, the
 * count, the carry, the ledger, the plan) is one of three: its statement is conditioned on the run still holding the
 * job (lock_token or the ledger's holder in the statement), it is made inside the fenced transaction
 * (Restore\JobFence::within(): the file calls it, and the function the write is in asserts the lock before it,
 * JobFence::assert_locked(), which also refuses at run time), or it is listed here as an exception with the reason.
 * A write that is none of them fails this test; so does an entry that no longer matches a write.
 *
 * And one invariant of the fenced regions (the body of the closure given to JobFence::within(), and the body of
 * every function that asserts the lock; found by the tokens): every statement there is a literal that begins with a
 * read or DML (SELECT, SHOW, DELETE, INSERT, UPDATE, REPLACE), whatever the registry says of it. Anything else may
 * end the transaction — DDL, LOCK TABLES, COMMIT, ROLLBACK, FLUSH, ANALYZE, XA, autocommit, a statement built at
 * run time: the list of what commits implicitly is long and grows, so the rule names what cannot — and after it the
 * job row is released and nothing is fenced. A within() that is not given a closure is reported, since its
 * statements cannot be examined. The class is recognised by the call ("::within(", "::assert_locked("), however
 * its name is spelt (imported, fully qualified, aliased); a statement with several calls in it (a nested read, two
 * statements on a line) is allowed only when every call is; a closure nested in a function fences itself when it
 * asserts the lock, not the function around it (as asserted_before() rules). Fixed examples show each rule biting;
 * a generator builds sources with known regions and statements, in every one of these shapes, and checks that
 * exactly the violating lines are reported.
 */
final class FencedWritesTest extends TestCase {

	const CONDITIONED = 'conditioned';
	const LOCKED      = 'locked';
	const EXCEPTION   = 'exception';

	/**
	 * Between a call and its literal: whitespace and comment lines. Inside the literal, before its keyword:
	 * whitespace and SQL comments. A call that runs a statement.
	 */
	const GAP     = '\s*(?:\/\/[^\n]*\n\s*)*';
	const SQL_GAP = '(?:\s|\/\*.*?\*\/)*';
	const CALL    = '->(?:write|run|rows|query)\(';

	/**
	 * What a write statement looks like, judged at its first call (a read nested in it does not make it one, nor
	 * does it hide one): a call that changes rows (or runs a statement), not a SELECT, a session setting or a
	 * transaction statement.
	 */
	const FIRST = '/->(?:write|run|rows)\(|\$wpdb->(?:query|update|insert|delete|replace)\(/';
	const WRITE = '/^->(?:write|run)\(|^->rows\(' . self::GAP . '[\'"]' . self::SQL_GAP . '(?:DELETE|INSERT|UPDATE|REPLACE)\b|^\$wpdb->(?:query|update|insert|delete|replace)\(/i';
	const NOT   = '/^->(?:run|rows)\(' . self::GAP . '[\'"]' . self::SQL_GAP . '(?:SET SESSION|START TRANSACTION|COMMIT|ROLLBACK|SELECT)\b/i';

	/**
	 * A locked write of the registry must be seen to be DML: its statement a literal that begins with one of the
	 * four; one that commits implicitly is reported as such.
	 */
	const IMPLICIT_COMMIT = '/' . self::CALL . self::GAP . '[\'"]' . self::SQL_GAP . '(?:CREATE|ALTER|DROP|RENAME|TRUNCATE|LOCK)\b/i';
	const DML             = '/' . self::CALL . self::GAP . '[\'"]' . self::SQL_GAP . '(?:DELETE|INSERT|UPDATE|REPLACE)\b/i';

	/** Inside a fenced region: any statement, and the only statements allowed there. */
	const STATEMENT = '/' . self::CALL . '/';
	const ALLOWED   = '/' . self::CALL . self::GAP . '[\'"]' . self::SQL_GAP . '(?:SELECT|SHOW|DELETE|INSERT|UPDATE|REPLACE)\b/i';

	/** How many lines after a call's line a statement is read on (up to its semicolon). */
	const STATEMENT_LINES = 8;

	const NOT_ALLOWED = 'inside a fenced transaction, a statement that is not a literal beginning with SELECT / SHOW / DELETE / INSERT / UPDATE / REPLACE: anything else (DDL, LOCK TABLES, COMMIT, FLUSH, a statement built at run time) may end the transaction and release the job row, and nothing after it is fenced';
	const NOT_CLOSURE = 'JobFence::within() is not given a closure (function () { ... }): its statements cannot be examined';

	/** Sources the generator builds per run. */
	const SOURCES = 120;

	/**
	 * The writes, by file: a substring of the line, its kind, and for an exception the reason.
	 *
	 * @var array<string, array<int, array{0: string, 1: string, 2?: string}>>
	 */
	const WRITES = array(
		'src/Jobs/SwapStep.php'       => array(
			array( '$db->run( $sql );', self::EXCEPTION, 'RENAME TABLE is DDL and carries no condition: a lease check right before it (send()); the window between the two is known (ARCHITECTURE).' ),
			array( "'DELETE FROM ' . SqlWriter::identifier( \$entry['live'] )", self::EXCEPTION, 'After the swap is made: idempotent, the same row the new holder deletes, right after a lease check.' ),
			array( "'INSERT INTO ' . SqlWriter::identifier( self::base_prefix() . 'options' )", self::EXCEPTION, 'After the swap is made: an upsert of the after-swap mark, the same the new holder writes, right after a lease check.' ),
		),
		'src/Jobs/SwapCheckStep.php'  => array(
			array( '$swap->write( $job, $attempt, $from, $batch, $context->fence() );', self::EXCEPTION, 'Not a statement: SwapPlan::write(), whose statements are in the fenced transaction (registered there).' ),
		),
		'src/Restore/StateCarry.php'  => array(
			array( "'DELETE FROM ' . SqlWriter::identifier( \$temp ) . ' WHERE '", self::LOCKED ),
			array( "'INSERT INTO ' . SqlWriter::identifier( \$temp ) . ' ' . \$columns", self::LOCKED ),
			array( "VALUES ('\" . self::ACTIVE . \"', ?, 'yes')", self::LOCKED ),
			array( "SET option_value = ? WHERE option_name = '\" . self::ACTIVE . \"'", self::LOCKED ),
			array( "VALUES (?, '\" . self::ACTIVE_NETWORK . \"', ?)", self::LOCKED ),
			array( "SET meta_value = ? WHERE site_id = ? AND meta_key = '\" . self::ACTIVE_NETWORK . \"'", self::LOCKED ),
		),
		'src/Restore/Ledger.php'      => array(
			array( "'CREATE TABLE IF NOT EXISTS '", self::EXCEPTION, 'DDL, idempotent: the ledger of this job, created once.' ),
			array( "' WHERE (' . \$where . ') AND j.lock_token = ?'", self::CONDITIONED ),
			array( '(n, chunk, pos, row_count, data_offset, transactional, holder) VALUES (?, 0, 0, 0, 0, 1, ?)', self::EXCEPTION, 'The claim of a new record (claim()): the row did not exist, and a second claimant is refused by the key.' ),
			array( "SET holder = ? WHERE n = ? AND holder = ?'", self::EXCEPTION, 'The claim of a record from its previous holder (claim()): a compare-and-set on the holder.' ),
			array( 'SET chunk = 1, pos = ?, data_offset = ?, transactional = ?, constraint_names = ? WHERE n = ? AND chunk = 0 AND holder = ?', self::CONDITIONED ),
			array( 'SET chunk = ?, pos = ?, row_count = row_count + ? WHERE n = ? AND chunk = ? AND pos = ? AND holder = ?', self::CONDITIONED ),
			array( 'SET restarting = 1, restarts = restarts + 1 WHERE n = ? AND restarting = 0 AND holder = ?', self::CONDITIONED ),
			array( 'SET chunk = 1, pos = data_offset, row_count = 0 WHERE n = ? AND restarting = 1 AND holder = ?', self::CONDITIONED ),
			array( 'SET restarting = 0 WHERE n = ? AND restarting = 1 AND chunk = 1 AND pos = data_offset AND row_count = 0 AND holder = ?', self::CONDITIONED ),
		),
		'src/Restore/SwapPlan.php'    => array(
			array( '$db->run( self::create_sql( $name ) );', self::EXCEPTION, 'DDL, idempotent: the plan table, created once.' ),
			array( "WHERE job_id = ? AND attempt <> ? ORDER BY id LIMIT", self::EXCEPTION, 'Rows of attempts no run reads any more (an attempt is numbered anew each time the check writes): a run that lost the job removes what the next one would.' ),
			array( "WHERE job_id = ? AND attempt = ? AND seq >= ?'", self::LOCKED ),
			array( '(job_id, attempt, seq, kind, live, stage, old, had_live) VALUES', self::LOCKED ),
			array( '$this->write(', self::EXCEPTION, 'Not a statement: complete() calls write(), whose statements are in the fenced transaction (registered above).' ),
		),
		'src/Jobs/TempTableCount.php' => array(),
		'src/Restore/JobFence.php'    => array(),
	);

	/**
	 * A statement's text: the call's line and what follows it up to its semicolon, at most STATEMENT_LINES lines.
	 *
	 * @param string[] $lines Source lines.
	 * @param int      $i     Index of the call's line.
	 * @return string
	 */
	private static function statement( array $lines, int $i ): string {
		$statement = $lines[ $i ];
		for ( $j = $i + 1; $j < min( count( $lines ), $i + self::STATEMENT_LINES ) && false === strpos( $statement, ';' ); $j++ ) {
			$statement .= "\n" . $lines[ $j ];
		}
		return $statement;
	}

	/**
	 * Whether a line is code (not blank, not a comment line).
	 *
	 * @param string $line Line.
	 * @return bool
	 */
	private static function is_code( string $line ): bool {
		$text = trim( $line );
		return '' !== $text && '*' !== $text[0] && 0 !== strpos( $text, '//' ) && 0 !== strpos( $text, '/*' );
	}

	/**
	 * The write lines of a source: line number => the statement's text.
	 *
	 * @param string $source Source.
	 * @return array<int, string>
	 */
	public static function writes( string $source ): array {
		$lines = explode( "\n", $source );
		$out   = array();
		foreach ( $lines as $i => $line ) {
			if ( ! self::is_code( $line ) || 1 !== preg_match( self::FIRST, $line, $first, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}
			$statement = self::statement( $lines, $i );
			$head      = substr( $statement, (int) $first[0][1] );
			if ( 1 !== preg_match( self::WRITE, $head ) || 1 === preg_match( self::NOT, $head ) ) {
				continue;
			}
			$out[ $i + 1 ] = $statement;
		}
		return $out;
	}

	/**
	 * The fenced regions of a source, by the tokens (braces in strings and comments do not count): the body of the
	 * closure given to each JobFence::within() call, and the body of every function that calls
	 * JobFence::assert_locked(). A within() not given a closure (an arrow function, a variable, a callable) is a
	 * problem: its statements cannot be examined.
	 *
	 * @param string $source Source.
	 * @return array{regions: array<int, array{0: int, 1: int}>, problems: string[]} Regions as [first line, last
	 *                                                                            line], 1-based, inclusive; problems
	 *                                                                            as "line: text".
	 */
	public static function fenced_regions( string $source ): array {
		$tokens = token_get_all( $source );
		$n      = count( $tokens );
		$lines  = array();
		$line   = 1;
		foreach ( $tokens as $k => $token ) {
			$lines[ $k ] = $line;
			if ( is_array( $token ) ) {
				$line += substr_count( $token[1], "\n" );
			}
		}
		$at = static function ( int $k ) use ( $tokens ) {
			return $tokens[ $k ] ?? null;
		};
		$is = static function ( $token, int $id, string $text = '' ): bool {
			return is_array( $token ) && $id === $token[0] && ( '' === $text || $text === $token[1] );
		};
		$next = static function ( int $k ) use ( $tokens, $n ): int { // The next token that is not whitespace or a comment.
			for ( $k++; $k < $n; $k++ ) {
				if ( ! is_array( $tokens[ $k ] ) || ! in_array( $tokens[ $k ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					return $k;
				}
			}
			return $n;
		};
		$opens  = static function ( $t ): bool {
			return '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) );
		};
		$body   = static function ( int $k ) use ( $tokens, $n, $opens ) { // From a function token: [index of its {, index of its }], or null.
			for ( ; $k < $n; $k++ ) {
				if ( ';' === $tokens[ $k ] ) {
					return null;
				}
				if ( '{' === $tokens[ $k ] ) {
					break;
				}
			}
			$open  = $k;
			$depth = 0;
			for ( ; $k < $n; $k++ ) {
				if ( $opens( $tokens[ $k ] ) ) {
					++$depth;
				} elseif ( '}' === $tokens[ $k ] && 0 === --$depth ) {
					return array( $open, $k );
				}
			}
			return null;
		};
		$third  = static function ( int $k ) use ( $tokens, $n, $opens, $next ) { // From a call's (: the index of its third argument's first token, or null.
			$depth  = 0;
			$commas = 0;
			for ( ; $k < $n; $k++ ) {
				$t = $tokens[ $k ];
				if ( '(' === $t || '[' === $t || $opens( $t ) ) {
					++$depth;
				} elseif ( ')' === $t || ']' === $t || '}' === $t ) {
					if ( 0 === --$depth ) {
						return null;
					}
				} elseif ( ',' === $t && 1 === $depth && 2 === ++$commas ) {
					return $next( $k );
				}
			}
			return null;
		};
		$calls  = static function ( int $k, string $method ) use ( $tokens, $is, $at, $next ): bool { // At the '::', however the class is spelt.
			return $is( $tokens[ $k ], T_DOUBLE_COLON ) && $is( $at( $next( $k ) ), T_STRING, $method );
		};
		$arrow  = static function ( int $k ) use ( $tokens, $n, $opens ): int { // From an fn token: the index of its last token.
			for ( ; $k < $n && ! ( is_array( $tokens[ $k ] ) && T_DOUBLE_ARROW === $tokens[ $k ][0] ); $k++ ) {
				continue;
			}
			$depth = 0;
			for ( $k++; $k < $n; $k++ ) {
				$t = $tokens[ $k ];
				if ( '(' === $t || '[' === $t || $opens( $t ) ) {
					++$depth;
				} elseif ( ')' === $t || ']' === $t || '}' === $t ) {
					if ( 0 === $depth ) {
						return $k - 1;
					}
					--$depth;
				} elseif ( ( ';' === $t || ',' === $t ) && 0 === $depth ) {
					return $k - 1;
				}
			}
			return $n - 1;
		};
		$regions  = array();
		$problems = array();
		for ( $k = 0; $k < $n; $k++ ) {
			if ( $calls( $k, 'within' ) ) {
				$paren = $next( $next( $k ) );
				$arg   = '(' === $at( $paren ) ? $third( $paren ) : null;
				if ( null !== $arg && $is( $at( $arg ), T_STATIC ) ) {
					$arg = $next( $arg );
				}
				$range = null !== $arg && $is( $at( $arg ), T_FUNCTION ) ? $body( $arg ) : null;
				if ( null === $range ) {
					$problems[] = $lines[ $k ] . ': ' . self::NOT_CLOSURE;
				} else {
					$regions[] = array( $lines[ $range[0] ], $lines[ $range[1] ] );
				}
			}
			if ( $is( $tokens[ $k ], T_FUNCTION ) ) {
				$range = $body( $k );
				if ( null === $range ) {
					continue;
				}
				for ( $j = $range[0] + 1; $j < $range[1]; $j++ ) {
					if ( $is( $tokens[ $j ], T_FUNCTION ) ) { // A closure nested in it asserts for itself, not for it.
						$inner = $body( $j );
						if ( null !== $inner ) {
							$j = $inner[1];
						}
					} elseif ( $is( $tokens[ $j ], T_FN ) ) {
						$j = $arrow( $j );
					} elseif ( $calls( $j, 'assert_locked' ) ) {
						$regions[] = array( $lines[ $range[0] ], $lines[ $range[1] ] );
						break;
					}
				}
			}
		}
		return array(
			'regions'  => array_values( array_unique( $regions, SORT_REGULAR ) ), // The closure asserts the lock too: once.
			'problems' => $problems,
		);
	}

	/**
	 * Whether the function (method or closure) a line is in calls JobFence::assert_locked() before that line.
	 *
	 * @param string $source Source.
	 * @param int    $line   Line number (1-based).
	 * @return bool
	 */
	public static function asserted_before( string $source, int $line ): bool {
		$lines = explode( "\n", $source );
		for ( $i = $line - 2; $i >= 0; $i-- ) {
			if ( 1 === preg_match( '/\bfunction\b/', $lines[ $i ] ) ) {
				return false; // The function's own line, or a one-line closure in between: a guard on it is not this function's.
			}
			if ( false !== strpos( $lines[ $i ], '::assert_locked(' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every way the writes of a file fall short of the registry, and every statement of a fenced region outside
	 * the invariant: messages.
	 *
	 * @param string                                                  $file    Path, for the messages.
	 * @param string                                                  $source  Source.
	 * @param array<int, array{0: string, 1: string, 2?: string}>     $entries The registry's entries for it.
	 * @return string[]
	 */
	public static function problems( string $file, string $source, array $entries ): array {
		$problems = array();
		$writes   = self::writes( $source );
		$matched  = array_fill( 0, count( $entries ), 0 );
		foreach ( $writes as $line => $statement ) {
			$hits = array();
			foreach ( $entries as $k => $entry ) {
				if ( false !== strpos( $statement, $entry[0] ) ) {
					$hits[] = $k;
				}
			}
			if ( array() === $hits ) {
				$problems[] = "$file:$line: a write that is not registered: " . trim( explode( "\n", $statement )[0] );
				continue;
			}
			if ( 1 !== count( $hits ) ) {
				$problems[] = "$file:$line: matches " . count( $hits ) . ' entries';
				continue;
			}
			$entry = $entries[ $hits[0] ];
			++$matched[ $hits[0] ];
			if ( self::CONDITIONED === $entry[1] && 1 !== preg_match( '/lock_token|holder = \?/', $statement ) ) {
				$problems[] = "$file:$line: registered as conditioned, but the statement names neither lock_token nor the holder";
			}
			if ( self::LOCKED === $entry[1] ) {
				if ( 1 === preg_match( self::IMPLICIT_COMMIT, $statement ) ) {
					$problems[] = "$file:$line: registered as locked, but the statement commits implicitly (CREATE / ALTER / DROP / RENAME / TRUNCATE / LOCK TABLES): it releases the job row, and nothing after it is fenced";
				} elseif ( 1 !== preg_match( self::DML, $statement ) ) {
					$problems[] = "$file:$line: registered as locked, but the statement is not seen to be DML (a literal beginning with DELETE / INSERT / UPDATE / REPLACE)";
				} elseif ( 1 !== preg_match( '/::within\(\s*\$/', $source ) ) { // The call, not a docblock's mention.
					$problems[] = "$file:$line: registered as locked, but the file never runs a fenced transaction (JobFence::within())";
				} elseif ( ! self::asserted_before( $source, $line ) ) {
					$problems[] = "$file:$line: registered as locked, but the function it is in does not assert the lock before it (JobFence::assert_locked())";
				}
			}
			if ( self::EXCEPTION === $entry[1] && '' === trim( (string) ( $entry[2] ?? '' ) ) ) {
				$problems[] = "$file:$line: an exception without a reason";
			}
		}
		foreach ( $matched as $k => $times ) {
			if ( 1 !== $times ) {
				$problems[] = "$file: the entry '" . $entries[ $k ][0] . "' matches $times writes, not one";
			}
		}
		$lines  = explode( "\n", $source );
		$fenced = self::fenced_regions( $source );
		foreach ( $fenced['problems'] as $problem ) {
			$problems[] = "$file:$problem";
		}
		$flagged = array();
		foreach ( $fenced['regions'] as $region ) {
			for ( $i = $region[0] - 1; $i < $region[1]; $i++ ) {
				if ( ! self::is_code( $lines[ $i ] ) || 1 !== preg_match( self::STATEMENT, $lines[ $i ] ) ) {
					continue;
				}
				// Every call in the statement (a nested read, two statements on the line) must be an allowed literal:
				// the calls, less the comment lines, as many as the allowed ones.
				$code = (string) preg_replace( '/^\s*\/\/[^\n]*\n/m', '', self::statement( $lines, $i ) );
				if ( preg_match_all( '/' . self::CALL . '/', $code ) !== preg_match_all( self::ALLOWED, $code ) ) {
					$flagged[ $i + 1 ] = true;
				}
			}
		}
		ksort( $flagged );
		foreach ( array_keys( $flagged ) as $line ) {
			$problems[] = "$file:$line: " . self::NOT_ALLOWED;
		}
		return $problems;
	}

	public function test_every_write_of_the_swap_phase_is_conditioned_locked_or_an_exception_with_a_reason(): void {
		$root    = dirname( __DIR__, 3 );
		$all     = array();
		$found   = 0;
		$regions = 0;
		foreach ( self::WRITES as $file => $entries ) {
			$source = (string) file_get_contents( $root . '/' . $file );
			$this->assertNotSame( '', $source, $file );
			$all      = array_merge( $all, self::problems( $file, $source, $entries ) );
			$found   += count( self::writes( $source ) );
			$regions += count( self::fenced_regions( $source )['regions'] );
		}
		$this->assertSame( array(), $all );
		$this->assertGreaterThanOrEqual( 20, $found, 'the control: the scanner finds the writes' );
		$this->assertGreaterThanOrEqual( 4, $regions, 'the control: the scanner finds the fenced regions (two closures, the carry\'s functions)' );
	}

	public function test_the_scanner_finds_an_unregistered_write_and_the_rules_bite(): void {
		$source = "<?php\n\$db->write( 'UPDATE t SET a = 1 WHERE n = ?', array( '1' ) );\n\$db->run( 'SET SESSION x = 1' );\n\$db->rows( 'SELECT 1' );\n\$db->rows( 'delete from t' );\n\$db->rows(\n\t'DELETE FROM t2'\n);\n";
		$this->assertSame( array( 2, 5, 6 ), array_keys( self::writes( $source ) ), 'writes found (DML in lower case, and on the line after the call, among them), a setting and a read not' );
		$this->assertStringContainsString( 'not registered', implode( "\n", self::problems( 'x.php', $source, array() ) ) );
		$this->assertStringContainsString( 'names neither lock_token nor the holder', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::CONDITIONED ), array( 'delete from', self::EXCEPTION, 'r' ), array( 'DELETE FROM t2', self::EXCEPTION, 'r' ) ) ) ) );
		$one     = array( array( 'SET a = 1', self::LOCKED ), array( 'delete from', self::EXCEPTION, 'r' ), array( 'DELETE FROM t2', self::EXCEPTION, 'r' ) );
		$this->assertStringContainsString( 'never runs a fenced transaction', implode( "\n", self::problems( 'x.php', $source, $one ) ) );
		$this->assertStringContainsString( 'never runs a fenced transaction', implode( "\n", self::problems( 'x.php', "<?php\n// JobFence::within() is mentioned, not called.\n" . substr( $source, 6 ), $one ) ), 'a mention in a comment is not the call' );
		$within     = "<?php\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n";
		$unasserted = $within . "\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$asserted   = $within . "\tJobFence::assert_locked( \$db );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$elsewhere  = $within . "} );\nfunction other( \$db ) {\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n}\nfunction f( \$db ) { JobFence::assert_locked( \$db ); }\n";
		$this->assertStringContainsString( 'does not assert the lock before it', implode( "\n", self::problems( 'x.php', $unasserted, array( array( 'SET a = 1', self::LOCKED ) ) ) ), 'inside the transaction, without the guard' );
		$this->assertStringContainsString( 'does not assert the lock before it', implode( "\n", self::problems( 'x.php', $elsewhere, array( array( 'SET a = 1', self::LOCKED ) ) ) ), 'a guard in another function does not count' );
		$one_liner = $within . "\t\$cb = function () use ( \$db ) { JobFence::assert_locked( \$db ); };\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$this->assertStringContainsString( 'does not assert the lock before it', implode( "\n", self::problems( 'x.php', $one_liner, array( array( 'SET a = 1', self::LOCKED ) ) ) ), 'a guard inside a one-line closure in between does not count' );
		$this->assertSame( array(), self::problems( 'x.php', $asserted, array( array( 'SET a = 1', self::LOCKED ) ) ), 'the guard right before it, in the fenced transaction' );
		// A statement that commits implicitly is never locked, however well guarded: each of the six (outside a
		// fenced region, where the rule on the registry is the only one), and a statement that cannot be seen to be
		// DML (a variable, a call); in lower case too.
		foreach ( array( 'CREATE TABLE t2 (a INT)', 'ALTER TABLE t ADD b INT', 'DROP TABLE t2', 'RENAME TABLE t TO t2', 'TRUNCATE TABLE t', 'LOCK TABLES t WRITE', 'lock tables t write', ' /* why */ DROP TABLE t2' ) as $ddl ) {
			$this->assertStringContainsString( 'commits implicitly', implode( "\n", self::problems( 'x.php', "<?php\n\$db->run( '$ddl' );\n", array( array( $ddl, self::LOCKED ) ) ) ), $ddl );
			$this->assertSame( array(), self::problems( 'x.php', "<?php\n\$db->run( '$ddl' );\n", array( array( $ddl, self::EXCEPTION, 'DDL' ) ) ), "$ddl as an exception with a reason" );
		}
		$opaque = "<?php\n\$db->run( \$sql );\n\$db->run( self::create_sql( \$name ) );\n";
		$this->assertStringContainsString( 'not seen to be DML', implode( "\n", self::problems( 'x.php', $opaque, array( array( '$db->run( $sql );', self::LOCKED ), array( 'self::create_sql(', self::EXCEPTION, 'r' ) ) ) ), 'a variable' );
		$this->assertStringContainsString( 'not seen to be DML', implode( "\n", self::problems( 'x.php', $opaque, array( array( '$db->run( $sql );', self::EXCEPTION, 'r' ), array( 'self::create_sql(', self::LOCKED ) ) ) ), 'a call' );
		$lower = $within . "\tJobFence::assert_locked( \$db );\n\t\$db->write( 'update t set a = 1', array() );\n} );\n";
		$this->assertSame( array(), self::problems( 'x.php', $lower, array( array( 'set a = 1', self::LOCKED ) ) ), 'DML in lower case is DML' );
		$multiline = $within . "\tJobFence::assert_locked( \$db );\n\t\$db->write(\n\t\t// Why.\n\t\t// And why.\n\t\t// And why again.\n\t\t'DELETE FROM ' . \$t . ' WHERE a = 1',\n\t\tarray()\n\t);\n} );\n";
		$this->assertSame( array(), self::problems( 'x.php', $multiline, array( array( "'DELETE FROM ' . \$t", self::LOCKED ) ) ), 'the DML literal lines after the call' );
		$this->assertStringContainsString( 'without a reason', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::EXCEPTION ), array( 'delete from', self::EXCEPTION, 'r' ), array( 'DELETE FROM t2', self::EXCEPTION, 'r' ) ) ) ) );
		$this->assertSame( array(), self::problems( 'x.php', $source, array( array( 'SET a = 1', self::EXCEPTION, 'a reason' ), array( 'delete from', self::EXCEPTION, 'r' ), array( 'DELETE FROM t2', self::EXCEPTION, 'r' ) ) ) );
		$this->assertStringContainsString( 'matches 0 writes', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::EXCEPTION, 'r' ), array( 'delete from', self::EXCEPTION, 'r' ), array( 'DELETE FROM t2', self::EXCEPTION, 'r' ), array( 'gone', self::EXCEPTION, 'r' ) ) ) ), 'a stale entry' );
		$fenced = "<?php\n\$db->write( 'UPDATE t AS l JOIN j ON j.id = ? SET l.a = 1 WHERE (l.n = ?) AND j.lock_token = ?', array() );\n";
		$this->assertSame( array(), self::problems( 'x.php', $fenced, array( array( 'SET l.a = 1', self::CONDITIONED ) ) ) );
	}

	public function test_inside_a_fenced_transaction_only_a_literal_beginning_with_a_read_or_dml_is_allowed(): void {
		$within = "<?php\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n\tJobFence::assert_locked( \$db );\n";
		$locked = array( 'SET a = 1', self::LOCKED );
		foreach ( array( 'DROP TABLE t2', 'drop table t2', ' DROP TABLE t2', '/* why */ TRUNCATE TABLE t', 'COMMIT', 'ROLLBACK', 'START TRANSACTION', 'BEGIN', 'LOCK TABLES t WRITE', 'UNLOCK TABLES', 'SET autocommit = 1', 'SET @@autocommit = 1', 'SET GLOBAL autocommit = 1', 'FLUSH TABLES', 'ANALYZE TABLE t', 'OPTIMIZE TABLE t', 'XA START x', 'SELECTED', 'UPDATES t', 'SET SESSION innodb_lock_wait_timeout = 5' ) as $ending ) {
			$source   = $within . "\t\$db->run( '$ending' );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
			$problems = implode( "\n", self::problems( 'x.php', $source, array( array( "'$ending'", self::EXCEPTION, 'a reason' ), $locked ) ) );
			$this->assertStringContainsString( 'x.php:4: ' . self::NOT_ALLOWED, $problems, "$ending, registered as an exception, in the closure" );
		}
		// In a function that asserts the lock, called from the closure.
		$called = "<?php\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n\tf( \$db );\n} );\nfunction f( \$db ) {\n\tJobFence::assert_locked( \$db );\n\t\$db->run( 'TRUNCATE TABLE t' );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n}\n";
		$this->assertStringContainsString( 'x.php:7: ' . self::NOT_ALLOWED, implode( "\n", self::problems( 'x.php', $called, array( array( 'TRUNCATE', self::EXCEPTION, 'r' ), $locked ) ) ) );
		// A statement that is not a literal cannot be seen not to end it.
		$opaque = $within . "\t\$db->run( \$sql );\n\t\$db->run( self::sql( \$x ) );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$this->assertSame( array( 'x.php:4: ' . self::NOT_ALLOWED, 'x.php:5: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $opaque, array( array( '$db->run( $sql );', self::EXCEPTION, 'r' ), array( 'self::sql(', self::EXCEPTION, 'r' ), $locked ) ) );
		// A within() not given a closure is reported, and does not fence what follows it.
		foreach ( array( "fn () => \$db->run( 'DROP TABLE t' )", '$work', "array( \$this, 'work' )", "'work'" ) as $work ) {
			$source   = "<?php\nJobFence::within( \$db, array( 'a', 'b' ), $work );\nfunction later( \$db ) {\n\t\$db->run( 'DROP TABLE t2' );\n}\n";
			$fenced   = self::fenced_regions( $source );
			$this->assertSame( array(), $fenced['regions'], $work );
			$this->assertSame( array( '2: ' . self::NOT_CLOSURE ), $fenced['problems'], $work );
			$entries  = array( array( "'DROP TABLE t2'", self::EXCEPTION, 'r' ) );
			if ( false !== strpos( $work, '$db->run(' ) ) {
				$entries[] = array( 'fn () =>', self::EXCEPTION, 'r' ); // The arrow's own statement is a write of the file, registered like any.
			}
			$this->assertSame( array( 'x.php:2: ' . self::NOT_CLOSURE ), self::problems( 'x.php', $source, $entries ), "$work: later() is not fenced" );
		}
		$this->assertSame( array( array( 2, 4 ) ), self::fenced_regions( "<?php\nJobFence::within( \$db, \$f, static function () use ( \$db ) {\n\t\$db->run( 'SELECT 1' );\n} );\n" )['regions'], 'a static closure' );
		// The controls: the same statements in a function outside the fence are the registry's business only; a
		// fenced closure of reads and DML, with braces and comments in its strings, is clean; the regions are where
		// they are; a source ending in the identifier does not read past its end.
		$outside = $within . "\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\nfunction g( \$db ) {\n\t\$db->run( 'DROP TABLE t2' );\n\t\$db->run( 'COMMIT' );\n}\n";
		$this->assertSame( array(), self::problems( 'x.php', $outside, array( array( 'DROP TABLE', self::EXCEPTION, 'r' ), $locked ) ) );
		$clean = $within . "\t\$rows = \$db->rows( 'SELECT 1 FROM t WHERE a = ?', array( '{' ) );\n\t\$db->rows( ' /* { */ show tables' );\n\t\$db->write( \"UPDATE t SET a = '}' WHERE b = {\$x}\", array() );\n\t\$db->write(\n\t\t// Why.\n\t\t'replace into t (a) values (1)',\n\t\tarray()\n\t);\n} );\n";
		$this->assertSame( array(), self::problems( 'x.php', $clean, array( array( "SET a = '}'", self::LOCKED ), array( 'replace into', self::LOCKED ) ) ) );
		$this->assertSame( array( array( 2, 4 ), array( 5, 9 ) ), self::fenced_regions( $called )['regions'], 'the closure, then the function that asserts' );
		$this->assertSame( array( array( 2, 5 ) ), self::fenced_regions( $outside )['regions'], 'g() is not fenced' );
		$this->assertSame( array(), self::fenced_regions( "<?php\n// JobFence::within( \$db, \$f, function () { JobFence::assert_locked( \$db ); } );\nfunction h() {\n}\n" )['regions'], 'a mention in a comment is neither' );
		$this->assertSame( array( 'regions' => array(), 'problems' => array() ), self::fenced_regions( "<?php\nJobFence" ) );
		// The class however it is spelt: fully qualified, aliased; and a within() of another spelling is the fence
		// the registry asks for.
		foreach ( array( '\\WPCheckpoint\\Restore\\JobFence', 'Fence' ) as $class ) {
			$spelt = "<?php\n{$class}::within( \$db, \$f, function () use ( \$db ) {\n\t{$class}::assert_locked( \$db );\n\t\$db->run( 'DROP TABLE t' );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\nfunction f( \$db ) {\n\t{$class}::assert_locked( \$db );\n\t\$db->run( 'COMMIT' );\n}\n";
			$this->assertSame( array( array( 2, 6 ), array( 7, 10 ) ), self::fenced_regions( $spelt )['regions'], $class );
			$this->assertSame( array( 'x.php:4: ' . self::NOT_ALLOWED, 'x.php:9: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $spelt, array( array( "'DROP TABLE t'", self::EXCEPTION, 'r' ), $locked ) ), $class );
		}
		// Several calls in one statement: allowed only when every one is; a line in two regions is reported once.
		$several = $within . "\t\$db->run( \$sql ); \$db->rows( 'SELECT 1' );\n\t\$db->write( \$this->sql(), \$db->rows( 'SELECT 1' ) );\n\t\$db->write( 'UPDATE t SET a = ?', \$db->rows( 'SELECT 1' ) );\n\t\$db->write( 'UPDATE t SET a = ?', array( \$db->rows( \$q ) ) );\n\t\$cb = function () use ( \$db ) { JobFence::assert_locked( \$db ); \$db->run( 'DROP TABLE t' ); };\n} );\n";
		$entries = array( array( '$db->run( $sql ); $db->rows', self::EXCEPTION, 'r' ), array( '$this->sql()', self::EXCEPTION, 'r' ), array( "SET a = ?', \$db->rows", self::EXCEPTION, 'r' ), array( 'array( $db->rows( $q ) )', self::EXCEPTION, 'r' ), array( "'DROP TABLE t'", self::EXCEPTION, 'r' ) );
		$this->assertSame( array( 'x.php:4: ' . self::NOT_ALLOWED, 'x.php:5: ' . self::NOT_ALLOWED, 'x.php:7: ' . self::NOT_ALLOWED, 'x.php:8: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $several, $entries ) );
		$this->assertSame( array( array( 2, 9 ), array( 8, 8 ) ), self::fenced_regions( $several )['regions'] );
		// A closure nested in a function that is not fenced asserts for itself: the function's own statements are
		// not examined, the closure's are.
		$nested = "<?php\nfunction outer( \$db ) {\n\t\$db->run( 'DROP TABLE t' );\n\t\$cb = function () use ( \$db ) {\n\t\tJobFence::assert_locked( \$db );\n\t\t\$db->run( 'DROP TABLE t2' );\n\t};\n\t\$fn = fn () => JobFence::assert_locked( \$db );\n\t\$db->run( 'COMMIT' );\n}\n";
		$this->assertSame( array( array( 4, 7 ) ), self::fenced_regions( $nested )['regions'] );
		$this->assertSame( array( 'x.php:6: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $nested, array( array( "'DROP TABLE t'", self::EXCEPTION, 'r' ), array( "'DROP TABLE t2'", self::EXCEPTION, 'r' ) ) ) );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function seeds(): array {
		$seeds = array();
		for ( $seed = 1; $seed <= self::SOURCES; $seed++ ) {
			$seeds[ "seed $seed" ] = array( $seed );
		}
		return $seeds;
	}

	/**
	 * A source built from random blocks, with what the scanner must report of it.
	 *
	 * @param int $seed Seed.
	 * @return array{source: string, regions: array<int, array{0: int, 1: int}>, not_allowed: int[], not_closure: int[], seen: array<string, int>}
	 */
	public static function generate( int $seed ): array {
		mt_srand( $seed );
		$pick    = static function ( array $options ) {
			return $options[ mt_rand( 0, count( $options ) - 1 ) ];
		};
		$cases   = static function ( string $keyword ) use ( $pick ): string {
			return $pick( array( $keyword, strtolower( $keyword ), ucfirst( strtolower( $keyword ) ) ) );
		};
		$allowed = array( 'SELECT 1 FROM t', 'SHOW TABLES', 'DELETE FROM t WHERE a = 1', 'INSERT INTO t (a) VALUES (1)', 'UPDATE t SET a = 1', 'REPLACE INTO t (a) VALUES (1)' );
		$ending  = array( 'CREATE TABLE t2 (a INT)', 'ALTER TABLE t ADD b INT', 'DROP TABLE t2', 'RENAME TABLE t TO t2', 'TRUNCATE TABLE t', 'LOCK TABLES t WRITE', 'UNLOCK TABLES', 'COMMIT', 'ROLLBACK', 'START TRANSACTION', 'BEGIN', 'SET autocommit = 1', 'SET @@autocommit = 1', 'SET GLOBAL autocommit = 1', 'FLUSH TABLES', 'ANALYZE TABLE t', 'OPTIMIZE TABLE t', 'REPAIR TABLE t', 'CHECK TABLE t', 'XA START x', 'GRANT ALL ON t TO u', 'RESET QUERY CACHE', 'SET SESSION innodb_lock_wait_timeout = 5', 'SELECTED 1', 'UPDATES t', 'CALL p()' );
		$leads   = array( '', '', ' ', '  ', '/* why */ ', ' /* { */ ', '/* } */' );
		$lines   = array( '<?php' );
		$seen    = array();
		/*
		 * A statement: its lines, and whether a fenced region allows it. The lines of a filler are no statement.
		 */
		$fence     = $pick( array( 'JobFence', 'JobFence', '\\WPCheckpoint\\Restore\\JobFence', 'Fence' ) ); // How the file spells the class.
		$seen[ 'JobFence' === $fence ? 'spelt_imported' : ( 'Fence' === $fence ? 'spelt_aliased' : 'spelt_qualified' ) ] = 1;
		$statement = static function () use ( $pick, $cases, $allowed, $ending, $leads, &$seen ): array {
			$kind = $pick( array( 'literal', 'literal', 'literal', 'multiline', 'opaque', 'filler', 'comment', 'two_calls', 'nested_call' ) );
			if ( 'filler' === $kind ) {
				$seen['filler'] = 1;
				return array( 'lines' => array( $pick( array( '$x = 1;', "\$y = array( '{', '}' );", '$z = "a {$x} b";' ) ) ), 'allowed' => true, 'statement' => false );
			}
			if ( 'comment' === $kind ) {
				$seen['comment'] = 1;
				return array( 'lines' => array( "// \$db->run( 'DROP TABLE t' );" ), 'allowed' => true, 'statement' => false );
			}
			if ( 'opaque' === $kind ) {
				$seen['opaque'] = 1;
				return array( 'lines' => array( $pick( array( '$db->run( $sql );', '$db->run( self::sql( $x ) );', '$db->write( $sql, array() );' ) ) ), 'allowed' => false, 'statement' => true );
			}
			$ok    = 1 === mt_rand( 0, 1 );
			$text  = $ok ? $pick( $allowed ) : $pick( $ending );
			$lead  = $pick( $leads );
			$first = (string) strtok( $text, ' ' );
			$text  = $lead . $cases( $first ) . substr( $text, strlen( $first ) ) . $pick( array( '', ' -- {', " /* ' */" ) );
			$seen[ $ok ? 'allowed' : 'ending' ] = 1;
			if ( '' !== trim( $lead ) ) {
				$seen[ $ok ? 'allowed_after_comment' : 'ending_after_comment' ] = 1;
			}
			$quoted = false === strpos( $text, "'" ) ? "'$text'" : "\"$text\"";
			$call   = $pick( array( "\$db->run( $quoted );", "\$db->write( $quoted, array() );", "\$db->rows( $quoted );", "\$wpdb->query( $quoted );" ) );
			if ( 'two_calls' === $kind || 'nested_call' === $kind ) { // Several calls in one statement: the other one allowed, or not.
				$other_ok = 1 === mt_rand( 0, 1 );
				$other    = $other_ok ? "\$db->rows( 'SELECT 1' )" : $pick( array( '$db->rows( $q )', "\$db->run( 'FLUSH TABLES' )" ) );
				$seen[ $kind . ( $ok && $other_ok ? '_allowed' : '_refused' ) ] = 1;
				$line = 'two_calls' === $kind ? "$call $other;" : "\$db->write( $quoted, array( $other ) );";
				return array( 'lines' => array( $line ), 'allowed' => $ok && $other_ok, 'statement' => true );
			}
			if ( 'multiline' === $kind ) {
				$seen['multiline'] = 1;
				$lines = array( '$db->write(', '	// Why.', "	$quoted," );
				for ( $i = mt_rand( 0, 3 ); $i > 0; $i-- ) {
					$lines[] = '	// And why.';
				}
				$lines[] = '	array()';
				$lines[] = ');';
				return array( 'lines' => $lines, 'allowed' => $ok, 'statement' => true );
			}
			return array( 'lines' => array( $call ), 'allowed' => $ok, 'statement' => true );
		};
		$regions     = array();
		$not_allowed = array();
		$not_closure = array();
		/*
		 * The statements of a block (a closure, a function, top-level code), with a closure nested in it now and
		 * then: one that asserts the lock fences itself, one that does not is fenced only as its surroundings are.
		 */
		$fill = static function ( string $kind, bool $fenced, int $depth ) use ( &$fill, &$lines, &$regions, &$not_allowed, &$seen, $statement, $pick, $fence ): void {
			$indent = str_repeat( '	', $depth );
			$count  = mt_rand( 0, 4 );
			$assert = 'asserting' === $kind ? mt_rand( 0, $count ) : ( ( 'closure' === $kind || 'static' === $kind ) && 1 === mt_rand( 0, 1 ) ? 0 : -1 ); // A plain function or closure never asserts: that would fence it.
			for ( $i = 0; $i <= $count; $i++ ) {
				if ( $i === $assert ) {
					$lines[] = "$indent{$fence}::assert_locked( \$db );";
				}
				if ( $i === $count ) {
					break;
				}
				if ( $depth < 2 && 0 === mt_rand( 0, 5 ) ) { // A nested closure.
					$asserts = 1 === mt_rand( 0, 1 );
					$one     = 1 === mt_rand( 0, 2 );
					$seen[ 'nested_' . ( $asserts ? 'asserting' : 'plain' ) . ( $fenced ? '_in_fenced' : '_outside' ) ] = 1;
					if ( $one ) {
						$text = $pick( array( "\$db->run( 'SELECT 1' );", "\$db->run( 'DROP TABLE t' );", '$db->run( $sql );' ) );
						$lines[] = "$indent\$cb = function () use ( \$db ) { " . ( $asserts ? "{$fence}::assert_locked( \$db ); " : '' ) . "$text };";
						$line    = count( $lines );
						if ( $asserts ) {
							$regions[] = array( $line, $line );
						}
						if ( ( $asserts || $fenced ) && "\$db->run( 'SELECT 1' );" !== $text ) {
							$not_allowed[] = $line;
						}
						$seen['nested_one_line'] = 1;
						continue;
					}
					$lines[] = "$indent\$cb = function () use ( \$db ) {";
					$open    = count( $lines );
					$fill( $asserts ? 'asserting' : 'plain', $asserts || $fenced, $depth + 1 );
					$lines[] = "$indent};";
					if ( $asserts ) {
						$regions[] = array( $open, count( $lines ) );
					}
					continue;
				}
				$s    = $statement();
				$line = count( $lines ) + 1;
				foreach ( $s['lines'] as $text ) {
					$lines[] = $indent . $text;
				}
				if ( $fenced && $s['statement'] && ! $s['allowed'] ) {
					$not_allowed[] = $line;
					$seen[ "violation_in_$kind" ] = 1;
				}
			}
		};
		$blocks = mt_rand( 2, 6 );
		for ( $b = 0; $b < $blocks; $b++ ) {
			$kind   = $pick( array( 'closure', 'closure', 'static', 'arrow', 'variable', 'asserting', 'asserting', 'plain', 'top' ) );
			$fenced = in_array( $kind, array( 'closure', 'static', 'asserting' ), true );
			$seen[ $kind ] = 1;
			if ( 'arrow' === $kind || 'variable' === $kind ) {
				$lines[]       = 'arrow' === $kind ? "{$fence}::within( \$db, array( 'a', 'b' ), fn () => \$db->run( 'DROP TABLE t' ) );" : "{$fence}::within( \$db, \$f, \$work );";
				$not_closure[] = count( $lines );
				continue;
			}
			$open = count( $lines ) + 1;
			if ( 'closure' === $kind ) {
				$lines[] = "{$fence}::within( \$db, \$f, function () use ( \$db ) {";
			} elseif ( 'static' === $kind ) {
				$lines[] = "{$fence}::within( \$db, array( \$a, \$b ), static function () use ( \$db ) {";
			} elseif ( 'top' !== $kind ) {
				$lines[] = "function f$b( \$db ) {";
			}
			$fill( $kind, $fenced, 'top' === $kind ? 0 : 1 );
			if ( 'top' === $kind ) {
				continue;
			}
			$lines[] = 'asserting' === $kind || 'plain' === $kind ? '}' : '} );';
			if ( $fenced ) {
				$regions[] = array( $open, count( $lines ) );
			}
		}
		sort( $regions ); // The scanner reports them in order of their first line, then their last.
		$not_allowed = array_values( array_unique( $not_allowed ) );
		sort( $not_allowed );
		return array(
			'source'      => implode( "\n", $lines ) . "\n",
			'regions'     => $regions,
			'not_allowed' => $not_allowed,
			'not_closure' => $not_closure,
			'seen'        => $seen,
		);
	}

	/**
	 * @dataProvider seeds
	 */
	public function test_generated_sources_report_exactly_the_statements_outside_the_invariant( int $seed ): void {
		$made    = self::generate( $seed );
		$fenced  = self::fenced_regions( $made['source'] );
		$regions = $fenced['regions'];
		sort( $regions );
		$this->assertSame( $made['regions'], $regions, "regions of:\n" . $made['source'] );
		$reported = array();
		$closure  = array();
		foreach ( self::problems( 'x.php', $made['source'], array() ) as $problem ) {
			if ( false !== strpos( $problem, self::NOT_ALLOWED ) ) {
				$reported[] = (int) explode( ':', $problem )[1];
			} elseif ( false !== strpos( $problem, self::NOT_CLOSURE ) ) {
				$closure[] = (int) explode( ':', $problem )[1];
			}
		}
		$this->assertSame( $made['not_allowed'], $reported, "statements outside the invariant of:\n" . $made['source'] );
		$this->assertSame( $made['not_closure'], $closure, "within() calls without a closure of:\n" . $made['source'] );
	}

	public function test_the_generator_covered_every_situation(): void {
		$seen = array();
		for ( $seed = 1; $seed <= self::SOURCES; $seed++ ) {
			$seen = array_merge( $seen, self::generate( $seed )['seen'] );
		}
		foreach ( array( 'closure', 'static', 'arrow', 'variable', 'asserting', 'plain', 'top', 'filler', 'comment', 'opaque', 'allowed', 'ending', 'allowed_after_comment', 'ending_after_comment', 'multiline', 'violation_in_closure', 'violation_in_static', 'violation_in_asserting', 'spelt_imported', 'spelt_qualified', 'spelt_aliased', 'two_calls_allowed', 'two_calls_refused', 'nested_call_allowed', 'nested_call_refused', 'nested_asserting_in_fenced', 'nested_asserting_outside', 'nested_plain_in_fenced', 'nested_plain_outside', 'nested_one_line' ) as $situation ) {
			$this->assertArrayHasKey( $situation, $seen, "$situation never occurred in " . self::SOURCES . ' sources' );
		}
	}

	public function test_the_generator_catches_a_known_violation(): void {
		// The reverse check: a source the generator built, with one allowed statement of a region (the first one
		// found over the seeds) turned into one that ends the transaction, must be reported at that line and
		// nowhere else.
		$changed = null;
		for ( $seed = 1; $seed <= self::SOURCES && null === $changed; $seed++ ) {
			$made  = self::generate( $seed );
			$lines = explode( "\n", $made['source'] );
			foreach ( $made['regions'] as $region ) {
				for ( $i = $region[0] - 1; $i < $region[1] && null === $changed; $i++ ) {
					if ( ! in_array( $i + 1, $made['not_allowed'], true ) && 1 === preg_match( '/^(\t+)\$(?:db|wpdb)->(?:run|rows|query)\( [\'"]' . self::SQL_GAP . '(?:select|show|delete|insert|update|replace)\b[^;]*;$/i', $lines[ $i ], $m ) ) {
						$lines[ $i ] = $m[1] . "\$db->run( 'FLUSH TABLES' );";
						$changed     = $i + 1;
					}
				}
			}
		}
		$this->assertNotNull( $changed, 'the control: an allowed SELECT in a region to turn' );
		$expected = $made['not_allowed'];
		$expected[] = $changed;
		sort( $expected );
		$reported = array();
		foreach ( self::problems( 'x.php', implode( "\n", $lines ), array() ) as $problem ) {
			if ( false !== strpos( $problem, self::NOT_ALLOWED ) ) {
				$reported[] = (int) explode( ':', $problem )[1];
			}
		}
		$this->assertSame( $expected, $reported );
	}
}
