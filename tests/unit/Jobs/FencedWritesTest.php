<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every write of the swap phase to the plugin's tables and the temporary tables (the final check, the swap, the
 * count, the carry, the ledger, the plan) is one of three: its statement is conditioned on the run still holding the
 * job (lock_token or the ledger's holder in the statement), it is made inside the fenced transaction
 * (Restore\JobFence::within(): the file calls it, and the function the write is in asserts the lock before it,
 * JobFence::assert_locked(), which also refuses at run time), or it is listed here as an exception with the reason.
 * A write that is none of them fails this test; so does an entry that no longer matches a write. And inside a
 * fenced transaction (the closure given to JobFence::within(), and every function that asserts the lock) no
 * statement may end it: DDL, LOCK TABLES, COMMIT, ROLLBACK, START TRANSACTION or autocommit release the job row,
 * and nothing after them is fenced, whatever the registry says of them; a statement there that is not a literal
 * cannot be seen not to, and is refused too. The scanner is shown to find a write, and each rule to bite.
 */
final class FencedWritesTest extends TestCase {

	const CONDITIONED = 'conditioned';
	const LOCKED      = 'locked';
	const EXCEPTION   = 'exception';

	/**
	 * What a write line looks like: a call that changes rows (or runs a statement), not a SELECT, a session setting
	 * or a transaction statement.
	 */
	const WRITE = '/->(?:write|run)\(|->rows\(\s*[\'"](?:DELETE|INSERT|UPDATE|REPLACE)\b|\$wpdb->(?:query|update|insert|delete|replace)\(/';
	const NOT   = '/->(?:run|rows)\(\s*[\'"](?:SET SESSION|START TRANSACTION|COMMIT|ROLLBACK|SELECT)\b/';

	/**
	 * A statement that commits implicitly (DDL, LOCK TABLES): it ends the fenced transaction and releases the job
	 * row, so nothing after it is inside the fence. Such a statement is never "locked"; and a locked write must be
	 * seen to be DML: its statement a literal that begins with one of the four (DML).
	 */
	const GAP             = '\s*(?:\/\/[^\n]*\n\s*)*'; // Between the call and its literal: whitespace and comment lines.
	const IMPLICIT_COMMIT = '/->(?:write|run|rows|query)\(' . self::GAP . '[\'"](?:CREATE|ALTER|DROP|RENAME|TRUNCATE|LOCK)\b/i';
	const DML             = '/->(?:write|run|rows|query)\(' . self::GAP . '[\'"](?:DELETE|INSERT|UPDATE|REPLACE)\b/i';

	/**
	 * Inside a fenced transaction: any statement at all, one that ends the transaction (releasing the job row), and
	 * one whose text is a literal (so that it can be seen not to).
	 */
	const STATEMENT  = '/->(?:write|run|rows|query)\(/';
	const ENDS_FENCE = '/->(?:write|run|rows|query)\(' . self::GAP . '[\'"](?:CREATE|ALTER|DROP|RENAME|TRUNCATE|LOCK|UNLOCK|COMMIT|ROLLBACK|START\s+TRANSACTION|BEGIN|SET\s+(?:SESSION\s+)?autocommit)\b/i';
	const LITERAL    = '/->(?:write|run|rows|query)\(' . self::GAP . '[\'"]/';

	/** How many lines after a call's line a statement is read on (up to its semicolon). */
	const STATEMENT_LINES = 8;

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
			array( "' AS j ON j.id = ? ' . \$set_where . ' AND j.lock_token = ?'", self::CONDITIONED ),
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
			if ( ! self::is_code( $line ) || 1 !== preg_match( self::WRITE, $line ) || 1 === preg_match( self::NOT, $line ) ) {
				continue;
			}
			$out[ $i + 1 ] = self::statement( $lines, $i );
		}
		return $out;
	}

	/**
	 * The line ranges of a source that are inside a fenced transaction: the body of the closure given to each
	 * JobFence::within() call, and the body of every function that calls JobFence::assert_locked() (by the tokens,
	 * so that braces in strings and comments do not count).
	 *
	 * @param string $source Source.
	 * @return array<int, array{0: int, 1: int}> [first line, last line], 1-based, inclusive.
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
		$body = static function ( int $k ) use ( $tokens, $n ) { // From a function token: [index of its {, index of its }], or null.
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
				$t = $tokens[ $k ];
				if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $t && 0 === --$depth ) {
					return array( $open, $k );
				}
			}
			return null;
		};
		$regions = array();
		for ( $k = 0; $k < $n; $k++ ) {
			if ( $is( $tokens[ $k ], T_STRING, 'JobFence' ) && $is( $tokens[ $next( $k ) ], T_DOUBLE_COLON ) && $is( $tokens[ $next( $next( $k ) ) ], T_STRING, 'within' ) ) {
				for ( $f = $k; $f < $n && ! $is( $tokens[ $f ], T_FUNCTION ); $f++ ) { // The closure given to it.
					continue;
				}
				$range = $f < $n ? $body( $f ) : null;
				if ( null !== $range ) {
					$regions[] = array( $lines[ $range[0] ], $lines[ $range[1] ] );
				}
			}
			if ( $is( $tokens[ $k ], T_FUNCTION ) ) {
				$range = $body( $k );
				if ( null === $range ) {
					continue;
				}
				for ( $j = $range[0]; $j < $range[1]; $j++ ) {
					if ( $is( $tokens[ $j ], T_STRING, 'JobFence' ) && $is( $tokens[ $next( $j ) ], T_DOUBLE_COLON ) && $is( $tokens[ $next( $next( $j ) ) ], T_STRING, 'assert_locked' ) ) {
						$regions[] = array( $lines[ $range[0] ], $lines[ $range[1] ] );
						break;
					}
				}
			}
		}
		return array_values( array_unique( $regions, SORT_REGULAR ) ); // The closure asserts the lock too: once.
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
			if ( false !== strpos( $lines[ $i ], 'JobFence::assert_locked(' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every way the writes of a file fall short of the registry: messages.
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
				} elseif ( 1 !== preg_match( '/JobFence::within\(\s*\$/', $source ) ) { // The call, not a docblock's mention.
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
		$lines = explode( "\n", $source );
		foreach ( self::fenced_regions( $source ) as $region ) {
			for ( $i = $region[0] - 1; $i < $region[1]; $i++ ) {
				if ( ! self::is_code( $lines[ $i ] ) || 1 !== preg_match( self::STATEMENT, $lines[ $i ] ) ) {
					continue;
				}
				$statement = self::statement( $lines, $i );
				$line      = $i + 1;
				if ( 1 === preg_match( self::ENDS_FENCE, $statement ) ) {
					$problems[] = "$file:$line: inside a fenced transaction, a statement that ends it (DDL, LOCK TABLES, COMMIT, ROLLBACK, START TRANSACTION, autocommit): the job row is released, and nothing after it is fenced";
				} elseif ( 1 !== preg_match( self::LITERAL, $statement ) ) {
					$problems[] = "$file:$line: inside a fenced transaction, a statement that is not a literal: it cannot be seen not to end the transaction";
				}
			}
		}
		return $problems;
	}

	public function test_every_write_of_the_swap_phase_is_conditioned_locked_or_an_exception_with_a_reason(): void {
		$root = dirname( __DIR__, 3 );
		$all  = array();
		foreach ( self::WRITES as $file => $entries ) {
			$source = (string) file_get_contents( $root . '/' . $file );
			$this->assertNotSame( '', $source, $file );
			$all = array_merge( $all, self::problems( $file, $source, $entries ) );
		}
		$this->assertSame( array(), $all );
		$found = 0;
		foreach ( self::WRITES as $file => $entries ) {
			$found += count( self::writes( (string) file_get_contents( $root . '/' . $file ) ) );
		}
		$this->assertGreaterThanOrEqual( 20, $found, 'the control: the scanner finds the writes' );
	}

	public function test_the_scanner_finds_an_unregistered_write_and_the_rules_bite(): void {
		$source = "<?php\n\$db->write( 'UPDATE t SET a = 1 WHERE n = ?', array( '1' ) );\n\$db->run( 'SET SESSION x = 1' );\n\$db->rows( 'SELECT 1' );\n";
		$this->assertSame( array( 2 ), array_keys( self::writes( $source ) ), 'a write found, a setting and a read not' );
		$this->assertStringContainsString( 'not registered', implode( "\n", self::problems( 'x.php', $source, array() ) ) );
		$this->assertStringContainsString( 'names neither lock_token nor the holder', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::CONDITIONED ) ) ) ) );
		$this->assertStringContainsString( 'never runs a fenced transaction', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::LOCKED ) ) ) ) );
		$this->assertStringContainsString( 'never runs a fenced transaction', implode( "\n", self::problems( 'x.php', "<?php\n// JobFence::within() is mentioned, not called.\n" . substr( $source, 6 ), array( array( 'SET a = 1', self::LOCKED ) ) ) ), 'a mention in a comment is not the call' );
		$within  = "<?php\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n";
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
		foreach ( array( 'CREATE TABLE t2 (a INT)', 'ALTER TABLE t ADD b INT', 'DROP TABLE t2', 'RENAME TABLE t TO t2', 'TRUNCATE TABLE t', 'LOCK TABLES t WRITE', 'lock tables t write' ) as $ddl ) {
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
		$this->assertStringContainsString( 'without a reason', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::EXCEPTION ) ) ) ) );
		$this->assertSame( array(), self::problems( 'x.php', $source, array( array( 'SET a = 1', self::EXCEPTION, 'a reason' ) ) ) );
		$this->assertStringContainsString( 'matches 0 writes', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::EXCEPTION, 'r' ), array( 'gone', self::EXCEPTION, 'r' ) ) ) ), 'a stale entry' );
		$fenced = "<?php\n\$db->write( 'UPDATE t AS l JOIN j ON j.id = ? SET l.a = 1 WHERE j.lock_token = ?', array() );\n";
		$this->assertSame( array(), self::problems( 'x.php', $fenced, array( array( 'SET l.a = 1', self::CONDITIONED ) ) ) );
	}

	public function test_inside_a_fenced_transaction_no_statement_may_end_it_whatever_the_registry_says(): void {
		$within = "<?php\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n\tJobFence::assert_locked( \$db );\n";
		$locked = array( 'SET a = 1', self::LOCKED );
		foreach ( array( 'DROP TABLE t2', 'drop table t2', 'COMMIT', 'ROLLBACK', 'START TRANSACTION', 'BEGIN', 'LOCK TABLES t WRITE', 'UNLOCK TABLES', 'SET autocommit = 1', 'SET SESSION autocommit = 1', 'TRUNCATE TABLE t' ) as $ending ) {
			$source   = $within . "\t\$db->run( '$ending' );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
			$problems = implode( "\n", self::problems( 'x.php', $source, array( array( "'$ending'", self::EXCEPTION, 'a reason' ), $locked ) ) );
			$this->assertStringContainsString( 'a statement that ends it', $problems, "$ending, registered as an exception, in the closure" );
		}
		// In a function that asserts the lock, called from the closure.
		$called = "<?php\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n\tf( \$db );\n} );\nfunction f( \$db ) {\n\tJobFence::assert_locked( \$db );\n\t\$db->run( 'TRUNCATE TABLE t' );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n}\n";
		$this->assertStringContainsString( 'a statement that ends it', implode( "\n", self::problems( 'x.php', $called, array( array( 'TRUNCATE', self::EXCEPTION, 'r' ), $locked ) ) ) );
		// A statement that is not a literal cannot be seen not to end it.
		$opaque = $within . "\t\$db->run( \$sql );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$this->assertStringContainsString( 'not a literal', implode( "\n", self::problems( 'x.php', $opaque, array( array( '$db->run( $sql );', self::EXCEPTION, 'r' ), $locked ) ) ) );
		// The controls: the same DDL in a function outside the fence is the registry's business only; a fenced
		// closure of reads and DML, with braces in its strings, is clean; the regions are where they are.
		$outside = $within . "\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\nfunction g( \$db ) {\n\t\$db->run( 'DROP TABLE t2' );\n\t\$db->run( 'COMMIT' );\n}\n";
		$this->assertSame( array(), self::problems( 'x.php', $outside, array( array( 'DROP TABLE', self::EXCEPTION, 'r' ), $locked ) ) );
		$clean = $within . "\t\$rows = \$db->rows( 'SELECT 1 FROM t WHERE a = ?', array( '{' ) );\n\t\$db->write( \"UPDATE t SET a = '}' WHERE b = {\$x}\", array() );\n} );\n";
		$this->assertSame( array(), self::problems( 'x.php', $clean, array( array( "SET a = '}'", self::LOCKED ) ) ) );
		$this->assertSame( array( array( 2, 4 ), array( 5, 9 ) ), self::fenced_regions( $called ), 'the closure, then the function that asserts' );
		$this->assertSame( array( array( 2, 5 ) ), self::fenced_regions( $outside ), 'g() is not fenced' );
		$this->assertSame( array(), self::fenced_regions( "<?php\n// JobFence::within( \$db, \$f, function () { JobFence::assert_locked( \$db ); } );\nfunction h() {\n}\n" ), 'a mention in a comment is neither' );
	}
}
