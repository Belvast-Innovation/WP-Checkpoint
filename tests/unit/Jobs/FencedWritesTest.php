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
 * every function that asserts the lock): every call that runs a statement there is given a literal that begins with
 * a read or DML (SELECT, SHOW, DELETE, INSERT, UPDATE, REPLACE), whatever the registry says of it. Anything else may
 * end the transaction — DDL, LOCK TABLES, COMMIT, ROLLBACK, FLUSH, ANALYZE, XA, autocommit, a statement built at
 * run time: the list of what commits implicitly is long and grows, so the rule names what cannot — and after it the
 * job row is released and nothing is fenced. A within() that is not given a closure is reported, since its
 * statements cannot be examined.
 *
 * One reading, by the tokens (analyse()): calls, regions, the registry's writes and "asserted before" all come from
 * the same walk, so comments and strings never count, every call is judged on its own (a nested read, two
 * statements on a line), the class is the one resolved against the file's namespace and imports as PHP resolves it
 * (imported, fully qualified, aliased, grouped, in any case, in order; another class's within() is not a fence),
 * an arrow function's parameters are skipped, and a closure nested in a function asserts the lock for itself, not
 * for the function around it. The fence is a transaction on the restore's own connection (ImportSession): the one
 * given to within() as its first argument, and the one a function asserts the lock on, read from the source; a
 * call on any other receiver (WordPress's $wpdb however reached, another object, the connection under another
 * name) is not on the fenced connection, so it can be neither locked nor allowed in a region, and a rebinding of
 * the fenced connection's name inside a region (an assignment, plain or compound; a foreach, list or [] target; a
 * global or static declaration; a parameter of a function nested there) is refused, since what it holds after that
 * is not known. The reading is static: it sees the source's spellings, not what runs; the fenced files hold the
 * connection as $this->db and are checked by the per-file control. A call named update(),
 * insert(), delete() or replace() on any object is recorded too: it must be registered (an exception with the
 * reason when it is not a statement). The regexes left are on
 * a literal's first characters only, and a regex that fails refuses. Fixed examples show each rule biting; a
 * generator builds sources with known regions and calls, in every one of these shapes, and checks that exactly the
 * violating lines are reported.
 */
final class FencedWritesTest extends TestCase {

	const CONDITIONED = 'conditioned';
	const LOCKED      = 'locked';
	const EXCEPTION   = 'exception';

	const FENCE = 'WPCheckpoint\Restore\JobFence';

	/** Inside a literal, before its keyword: whitespace and SQL comments. */
	const SQL_GAP = '(?:\s|\/\*.*?\*\/)*';

	/**
	 * A literal's first keyword: DML (a write, and what a locked write must be), what commits implicitly, a read or
	 * DML (allowed in a region), what run() and rows() are given that is not a write. With the u modifier, so that
	 * a literal the regex cannot read (invalid UTF-8) is a failure, and a failure refuses (begins()).
	 */
	const DML             = '/^' . self::SQL_GAP . '(?:DELETE|INSERT|UPDATE|REPLACE)\b/iu';
	const IMPLICIT_COMMIT = '/^' . self::SQL_GAP . '(?:CREATE|ALTER|DROP|RENAME|TRUNCATE|LOCK)\b/iu';
	const ALLOWED         = '/^' . self::SQL_GAP . '(?:SELECT|SHOW|DELETE|INSERT|UPDATE|REPLACE)\b/iu';
	const NOT_A_WRITE     = '/^' . self::SQL_GAP . '(?:SET SESSION|START TRANSACTION|COMMIT|ROLLBACK|SELECT)\b/iu';

	/** The methods that run a statement: on a Queries connection (and query() on any), and those $wpdb builds DML with. */
	const METHODS    = array( 'write', 'run', 'rows', 'query', 'update', 'insert', 'delete', 'replace' );
	const BUILDS_DML = array( 'update', 'insert', 'delete', 'replace' );

	const NOT_ALLOWED = 'inside a fenced transaction, a call that is not an allowed one: on the fenced connection (the one given to within(), or asserted on), by write(), run(), rows() or query(), a literal beginning with SELECT / SHOW / DELETE / INSERT / UPDATE / REPLACE (anything else, DDL, LOCK TABLES, COMMIT, FLUSH, a statement built at run time, may end the transaction and release the job row, and nothing after it is fenced); a call on any other receiver (WordPress\'s $wpdb however reached, another object, a receiver that cannot be read) is not on the fenced connection at all, so it is no fenced write';
	const REASSIGNED  = 'inside a fenced transaction, the fenced connection\'s name is rebound (an assignment, a foreach, list or [] target, a global or static declaration, a parameter of a nested function): what it holds after that is not known to be the fenced connection';
	const NOT_ON      = 'registered as locked, but the call is not on the fenced connection (the one given to within(), or asserted on): the fence is a transaction on the restore\'s own connection, and this is outside it';
	const BUILT       = 'registered as locked, but the method builds the statement itself (update(), insert(), delete(), replace()): its literal cannot be seen, and such a call is not one of the fenced connection\'s';
	const MISMATCH    = 'the connection given to within() and the one asserted on inside its closure differ: one of them is not the fenced connection';
	const NOT_CLOSURE = 'JobFence::within() is not given a closure (function () { ... }): its statements cannot be examined';

	/** Sources the generator builds per run. */
	const SOURCES = 120;

	/**
	 * The writes, by file: a substring of the call's text (from its receiver to the end of its statement), its kind,
	 * and for an exception the reason.
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
			array( '$hashes->update( $piece );', self::EXCEPTION, 'Not a statement: a hash context fed a piece of a file.' ),
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
	 * Whether a token is the given kind (and text).
	 *
	 * @param mixed  $token Token, or null past the end.
	 * @param int    $id    Token id.
	 * @param string $text  Its text, or '' for any.
	 * @return bool
	 */
	private static function is( $token, int $id, string $text = '' ): bool {
		return is_array( $token ) && $id === $token[0] && ( '' === $text || $text === $token[1] );
	}

	/**
	 * Whether a token opens a brace (a plain one, or the two kinds inside a string).
	 *
	 * @param mixed $token Token.
	 * @return bool
	 */
	private static function opens( $token ): bool {
		return '{' === $token || ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) );
	}

	/**
	 * One walk over a source: every call that runs a statement, the fenced regions, the fence's calls, and what
	 * the walk itself finds wrong (a within() not given a closure).
	 *
	 * A call: its line, method, receiver (its text without whitespace), the literal it is given (its text with
	 * escapes read, or null when its first argument is not a literal), its text (from its receiver to its closing
	 * parenthesis, and the semicolon when one follows), the connections of the regions it is in, and whether it is
	 * asserted before. A region: the closure given to the fence's within(), its connection the call's first
	 * argument; and every function whose body (not a function nested in it) calls the fence's assert_locked(), its
	 * connection what is asserted on. An assignment to a region's connection inside it is a problem. The fence is
	 * the class resolved against the namespace and the imports (plain, aliased, grouped) the walk has met so far,
	 * as PHP resolves it.
	 *
	 * @param string $source Source.
	 * @return array{calls: array<int, array{line: int, method: string, receiver: string, literal: string|null, text: string, connections: string[], asserted: bool}>, regions: array<int, array{0: int, 1: int}>, fence_calls: int, assert_calls: int, named_calls: int, problems: string[]}
	 */
	public static function analyse( string $source ): array {
		$tokens  = token_get_all( $source );
		$n       = count( $tokens );
		$lines   = array();
		$offsets = array();
		$line    = 1;
		$offset  = 0;
		foreach ( $tokens as $k => $token ) {
			$lines[ $k ]   = $line;
			$offsets[ $k ] = $offset;
			$text          = is_array( $token ) ? $token[1] : $token;
			$line         += substr_count( $text, "\n" );
			$offset       += strlen( $text );
		}
		$offsets[ $n ] = $offset;
		$at   = static function ( int $k ) use ( $tokens ) {
			return $tokens[ $k ] ?? null;
		};
		$skip = static function ( $token ): bool {
			return is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
		};
		$text_of = static function ( $t ) use ( $skip ): string { // A token's text; whitespace and comments as nothing.
			return $skip( $t ) ? '' : ( is_array( $t ) ? $t[1] : $t );
		};
		$next = static function ( int $k ) use ( $tokens, $n, $skip ): int { // The next token that is not whitespace or a comment.
			for ( $k++; $k < $n && $skip( $tokens[ $k ] ); $k++ ) {
				continue;
			}
			return $k;
		};
		$prev = static function ( int $k ) use ( $tokens, $skip ): int { // The previous one, or -1.
			for ( $k--; $k >= 0 && $skip( $tokens[ $k ] ); $k-- ) {
				continue;
			}
			return $k;
		};
		$opener = static function ( $t ): bool {
			return '(' === $t || '[' === $t || self::opens( $t );
		};
		$closer = static function ( $t ): bool {
			return ')' === $t || ']' === $t || '}' === $t;
		};
		// From an opening token: the index of its closing token (n - 1 when the source ends first).
		$close = static function ( int $k ) use ( $tokens, $n, $opener, $closer ): int {
			$depth = 0;
			for ( ; $k < $n; $k++ ) {
				if ( $opener( $tokens[ $k ] ) ) {
					++$depth;
				} elseif ( $closer( $tokens[ $k ] ) && 0 === --$depth ) {
					return $k;
				}
			}
			return $n - 1;
		};
		// From a closing bracket: the index of its opening one (0 when the source began first).
		$open_of = static function ( int $k ) use ( $tokens, $opener, $closer ): int {
			$depth = 0;
			for ( ; $k >= 0; $k-- ) {
				if ( $closer( $tokens[ $k ] ) ) {
					++$depth;
				} elseif ( $opener( $tokens[ $k ] ) && 0 === --$depth ) {
					return $k;
				}
			}
			return 0;
		};
		// From index k, the end of the expression there: the index before the ; , ) or ] of its own level that
		// ends it (brackets jumped over), or the last token.
		$expression_end = static function ( int $k ) use ( $tokens, $n, $opener, $closer, $close ): int {
			for ( ; $k < $n; $k++ ) {
				$t = $tokens[ $k ];
				if ( $opener( $t ) ) {
					$k = $close( $k );
				} elseif ( ';' === $t || ',' === $t || $closer( $t ) ) {
					return $k - 1;
				}
			}
			return $n - 1;
		};
		// From a function token: [index of its {, index of its }], or null (no body).
		$body = static function ( int $k ) use ( $tokens, $n, $close ) {
			for ( ; $k < $n; $k++ ) {
				if ( ';' === $tokens[ $k ] ) {
					return null;
				}
				if ( '{' === $tokens[ $k ] ) {
					return array( $k, $close( $k ) );
				}
			}
			return null;
		};
		// From an fn token: the index of its body's last token: its parameters skipped, then its expression.
		$arrow = static function ( int $k ) use ( $tokens, $n, $close, $expression_end ): int {
			for ( ; $k < $n && '(' !== $tokens[ $k ]; $k++ ) {
				continue;
			}
			$k = $close( $k );
			for ( ; $k < $n && ! self::is( $tokens[ $k ], T_DOUBLE_ARROW ); $k++ ) {
				continue;
			}
			return $expression_end( $k + 1 );
		};
		// A name's tokens (either PHP version), read forward or backward.
		$name_ids = array_filter( array( T_STRING, T_NS_SEPARATOR, defined( 'T_NAME_QUALIFIED' ) ? T_NAME_QUALIFIED : 0, defined( 'T_NAME_FULLY_QUALIFIED' ) ? T_NAME_FULLY_QUALIFIED : 0 ) );
		$name_at  = static function ( int $k, bool $forward ) use ( $tokens, $name_ids ): string {
			$text = '';
			for ( ; isset( $tokens[ $k ] ) && is_array( $tokens[ $k ] ) && in_array( $tokens[ $k ][0], $name_ids, true ); $k += $forward ? 1 : -1 ) {
				$text = $forward ? $text . $tokens[ $k ][1] : $tokens[ $k ][1] . $text;
			}
			return $text;
		};
		// The namespace and the imports met so far (an import at the top level, not a closure's use nor a trait's),
		// and whether the class before a :: resolves to the fence.
		$namespace = '';
		$imports   = array();
		$import    = static function ( int $k ) use ( $tokens, $n, $skip, $text_of, &$imports ): void { // From a use token.
			$text = '';
			for ( $k++; $k < $n && ';' !== $tokens[ $k ]; $k++ ) {
				$text .= $skip( $tokens[ $k ] ) ? ' ' : $text_of( $tokens[ $k ] );
			}
			$text = trim( $text );
			if ( 1 === preg_match( '/^(?:function|const)\s/i', $text ) ) {
				return;
			}
			$prefix = '';
			if ( false !== strpos( $text, '{' ) ) {
				$prefix = ltrim( trim( substr( $text, 0, (int) strpos( $text, '{' ) ) ), '\\' );
				$text   = substr( $text, (int) strpos( $text, '{' ) + 1, (int) strrpos( $text, '}' ) - (int) strpos( $text, '{' ) - 1 );
			}
			foreach ( explode( ',', $text ) as $item ) {
				$item = trim( $item );
				if ( '' === $item ) {
					continue;
				}
				$parts = preg_split( '/\s+as\s+/i', $item );
				$class = $prefix . ltrim( trim( (string) $parts[0] ), '\\' );
				$alias = isset( $parts[1] ) ? trim( (string) $parts[1] ) : (string) substr( strrchr( '\\' . $class, '\\' ), 1 );
				$imports[ strtolower( $alias ) ] = $class; // Names are case-insensitive in PHP.
			}
		};
		$is_fence = static function ( int $colon ) use ( $prev, $name_at, &$namespace, &$imports ): bool {
			$name = $name_at( $prev( $colon ), false );
			if ( '' === $name ) {
				return false;
			}
			if ( '\\' === $name[0] ) {
				$resolved = substr( $name, 1 );
			} else {
				$first    = strtolower( explode( '\\', $name )[0] );
				$resolved = isset( $imports[ $first ] ) ? $imports[ $first ] . substr( $name, strlen( $first ) ) : ( '' === $namespace ? $name : $namespace . '\\' . $name );
			}
			return strtolower( self::FENCE ) === strtolower( $resolved );
		};
		// The text a call is given first: a literal's text with its escapes read, or null.
		$literal_at = static function ( int $k ) use ( $tokens ) {
			$t = $tokens[ $k ] ?? null;
			if ( self::is( $t, T_CONSTANT_ENCAPSED_STRING ) ) {
				$inner = substr( $t[1], 1, -1 );
				return '"' === $t[1][0] ? stripcslashes( $inner ) : str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $inner );
			}
			if ( '"' === $t && self::is( $tokens[ $k + 1 ] ?? null, T_ENCAPSED_AND_WHITESPACE ) ) {
				return stripcslashes( $tokens[ $k + 1 ][1] );
			}
			if ( self::is( $t, T_START_HEREDOC ) && self::is( $tokens[ $k + 1 ] ?? null, T_ENCAPSED_AND_WHITESPACE ) ) {
				return false === strpos( $t[1], "'" ) ? stripcslashes( $tokens[ $k + 1 ][1] ) : $tokens[ $k + 1 ][1];
			}
			return null;
		};
		// The walk: function ranges, the fence's calls, the regions, the calls.
		// A token of a receiver chain: a variable, a name, -> or ::.
		$chain    = static function ( $t ) use ( $name_ids ): bool {
			return self::is( $t, T_VARIABLE ) || self::is( $t, T_STRING ) || self::is( $t, T_OBJECT_OPERATOR ) || self::is( $t, T_DOUBLE_COLON ) || ( is_array( $t ) && in_array( $t[0], $name_ids, true ) );
		};
		// The chain of tokens from k on, without whitespace: [text, index of its last token]; '' when k begins none.
		$chain_at = static function ( int $k ) use ( $tokens, $n, $skip, $chain, $next ): array {
			$text = '';
			$last = $k - 1;
			for ( ; $k < $n && ( $skip( $tokens[ $k ] ) || $chain( $tokens[ $k ] ) ); $k++ ) {
				if ( ! $skip( $tokens[ $k ] ) ) {
					$text .= $tokens[ $k ][1];
					$last  = $k;
				}
			}
			return array( $text, $last );
		};
		// The text of the tokens from k up to (not including) the first , or the closer of the level: a call's
		// first argument, without whitespace, and without one pair of parentheses around the whole of it.
		$argument = static function ( int $k ) use ( $tokens, $n, $opener, $closer, $close, $text_of ): string {
			$text = '';
			for ( ; $k < $n; $k++ ) {
				$t = $tokens[ $k ];
				if ( ',' === $t || $closer( $t ) ) {
					break;
				}
				if ( $opener( $t ) ) {
					$end = $close( $k );
					for ( ; $k <= $end; $k++ ) {
						$text .= $text_of( $tokens[ $k ] );
					}
					$k = $end;
					continue;
				}
				$text .= $text_of( $t );
			}
			return 1 === preg_match( '/^\((.*)\)$/', $text, $m ) && false === strpos( $m[1], '(' ) ? $m[1] : $text;
		};
		$functions    = array(); // [open, close, kind] in order of appearance; kind 'function' or 'fn'.
		$asserts      = array(); // [token index, the connection asserted on] of the fence's assert_locked() calls.
		$regions      = array(); // [open, close, connection] token ranges.
		$problems     = array();
		$fence_calls  = 0;
		$named_calls  = 0; // Every ::within( and ::assert_locked( written, resolved to the fence or not.
		$calls        = array();
		$depth        = 0;
		$scope        = 0; // The brace depth at which imports are read: 0, or inside a braced namespace its depth.
		$ns_brace     = -1;
		for ( $k = 0; $k < $n; $k++ ) {
			$t = $tokens[ $k ];
			if ( self::opens( $t ) ) {
				++$depth;
				if ( $k === $ns_brace ) {
					$scope = $depth;
				}
			} elseif ( '}' === $t ) {
				--$depth;
				if ( $depth < $scope ) {
					$scope = 0;
				}
			} elseif ( self::is( $t, T_NAMESPACE ) && ! self::is( $at( $next( $k ) ), T_NS_SEPARATOR ) ) {
				$namespace = ltrim( $name_at( $next( $k ), true ), '\\' );
				$imports   = array();
				for ( $j = $next( $k ); $j < $n && ';' !== $tokens[ $j ] && '{' !== $tokens[ $j ]; $j++ ) { // The braced form: imports at the depth inside it.
					continue;
				}
				$ns_brace = $j < $n && '{' === $tokens[ $j ] ? $j : -1;
			} elseif ( self::is( $t, T_USE ) && $depth === $scope && '(' !== $at( $next( $k ) ) ) {
				$import( $k );
			} elseif ( self::is( $t, T_FUNCTION ) ) {
				$range = $body( $k );
				if ( null !== $range ) {
					$functions[] = array( $range[0], $range[1], 'function' );
				}
			} elseif ( self::is( $t, T_FN ) ) {
				$functions[] = array( $k, $arrow( $k ), 'fn' );
			} elseif ( self::is( $t, T_DOUBLE_COLON ) && self::is( $at( $next( $k ) ), T_STRING ) && '(' === $at( $next( $next( $k ) ) ) ) {
				// A static call named within() or assert_locked(): counted whatever its class (the control), the
				// fence's acted on.
				$method = strtolower( (string) $tokens[ $next( $k ) ][1] );
				if ( ! in_array( $method, array( 'within', 'assert_locked' ), true ) ) {
					continue;
				}
				++$named_calls;
				if ( ! $is_fence( $k ) ) {
					continue;
				}
				if ( 'assert_locked' === $method ) {
					$asserts[] = array( $k, $argument( $next( $next( $k ) ) + 1 ) );
				} else {
					++$fence_calls;
					$paren  = $next( $next( $k ) );
					$end    = $close( $paren );
					$arg    = null; // Its third argument: after the second comma of the call's own level.
					$commas = 0;
					for ( $j = $paren + 1; $j < $end; $j++ ) {
						if ( $opener( $tokens[ $j ] ) ) {
							$j = $close( $j );
						} elseif ( ',' === $tokens[ $j ] && 2 === ++$commas ) {
							$arg = $next( $j );
							break;
						}
					}
					if ( null !== $arg && self::is( $at( $arg ), T_STATIC ) ) {
						$arg = $next( $arg );
					}
					$range = null !== $arg && self::is( $at( $arg ), T_FUNCTION ) ? $body( $arg ) : null;
					if ( null === $range ) {
						$problems[] = $lines[ $k ] . ': ' . self::NOT_CLOSURE;
					} else {
						$regions[] = array( $range[0], $range[1], $argument( $paren + 1 ) );
					}
				}
			} elseif ( self::is( $t, T_OBJECT_OPERATOR ) && self::is( $at( $next( $k ) ), T_STRING ) && in_array( strtolower( $tokens[ $next( $k ) ][1] ), self::METHODS, true ) && '(' === $at( $next( $next( $k ) ) ) ) {
				// A call (its method name in any case, as PHP takes it): its receiver chain (variables, properties,
				// static members, array offsets, a call's result), its first argument, its closing parenthesis and
				// the semicolon after it.
				$method = strtolower( (string) $tokens[ $next( $k ) ][1] );
				$start  = $k;
				$inner  = null; // A receiver that is a parenthesised chain, ( $db ): read without its parentheses.
				for ( $j = $prev( $k ); $j >= 0; $j = $prev( $j ) ) {
					if ( ']' === $tokens[ $j ] ) {
						$j = $open_of( $j );
					} elseif ( ')' === $tokens[ $j ] ) {
						$open   = $open_of( $j );
						$before = $open > 0 ? $tokens[ $prev( $open ) ] : null;
						if ( self::is( $before, T_STRING ) || self::is( $before, T_VARIABLE ) || ( is_array( $before ) && in_array( $before[0], $name_ids, true ) ) ) {
							$j = $open; // A call's result: what precedes its ( names the call.
						} elseif ( ']' === $before || '}' === $before || ')' === $before ) {
							break; // A call's result too, its callee an expression: the receiver cannot be read.
						} elseif ( $j === $prev( $k ) ) {
							$text = '';
							for ( $i = $open + 1; $i < $j; $i++ ) {
								if ( ! $skip( $tokens[ $i ] ) && ! $chain( $tokens[ $i ] ) ) {
									$text = null;
									break;
								}
								$text .= $text_of( $tokens[ $i ] );
							}
							if ( null === $text ) {
								break; // Not a chain in the parentheses: the receiver cannot be read.
							}
							$inner = $text;
							$start = $open;
							break;
						} else {
							break;
						}
					} elseif ( ! $chain( $tokens[ $j ] ) ) {
						break;
					}
					$start = $j;
				}
				$receiver = $inner ?? '';
				if ( null === $inner ) {
					for ( $j = $start; $j < $k; $j++ ) {
						$receiver .= $text_of( $tokens[ $j ] );
					}
				}
				$paren = $next( $next( $k ) );
				$end   = $close( $paren );
				if ( ';' === $at( $next( $end ) ) ) {
					$end = $next( $end );
				}
				$calls[] = array(
					'index'       => $k,
					'line'        => $lines[ $k ],
					'method'      => $method,
					'receiver'    => $receiver,
					'literal'     => $literal_at( $next( $paren ) ),
					'text'        => substr( $source, $offsets[ $start ], $offsets[ $end + 1 ] - $offsets[ $start ] ),
					'connections' => array(),
					'asserted'    => false,
				);
			}
		}
		// A function is a region when its body, less the functions nested in it, calls assert_locked().
		$inside = static function ( int $k, array $range ): bool {
			return $k > $range[0] && $k < $range[1];
		};
		$nested = static function ( int $k, array $range ) use ( $functions, $inside ): bool { // Whether index k lies in a function nested in the range.
			foreach ( $functions as $g ) {
				if ( $g[0] !== $range[0] && $inside( $g[0], $range ) && $k >= $g[0] && $k <= $g[1] ) {
					return true;
				}
			}
			return false;
		};
		foreach ( $functions as $f ) {
			if ( 'function' !== $f[2] ) {
				continue;
			}
			foreach ( $asserts as $a ) {
				if ( $inside( $a[0], $f ) && ! $nested( $a[0], $f ) ) {
					$regions[] = array( $f[0], $f[1], $a[1] );
					break;
				}
			}
		}
		$unique = array();
		foreach ( $regions as $r ) { // The closure given to within() asserts the lock too: the within() call's connection stands, and the two must agree.
			$key = $r[0] . ':' . $r[1];
			if ( ! isset( $unique[ $key ] ) ) {
				$unique[ $key ] = $r;
			} elseif ( $unique[ $key ][2] !== $r[2] ) {
				$problems[] = $lines[ $r[0] ] . ': ' . self::MISMATCH;
			}
		}
		$regions = array_values( $unique );
		// Inside a region, a rebinding of its connection's name (a line in two regions, once): the chain equal to
		// the connection followed by an assignment (plain or compound); the variable as a foreach's, list()'s or
		// []'s target; after global or static; a parameter of a function nested in the region.
		$rebound   = array();
		$rebinding = static function ( int $j ) use ( $tokens, $at, $next, $prev, $open_of, $close, $closer ): bool { // At the chain's last token.
			$after = $at( $next( $j ) );
			if ( '=' === $after || ( is_array( $after ) && 1 === preg_match( '/_EQUAL$/', token_name( $after[0] ) ) && T_IS_EQUAL !== $after[0] && T_IS_NOT_EQUAL !== $after[0] && T_IS_IDENTICAL !== $after[0] && T_IS_NOT_IDENTICAL !== $after[0] && T_IS_SMALLER_OR_EQUAL !== $after[0] && T_IS_GREATER_OR_EQUAL !== $after[0] ) ) {
				return true;
			}
			$before = $at( $prev( $j ) );
			if ( self::is( $before, T_AS ) || self::is( $before, T_DOUBLE_ARROW ) && self::is( $at( $prev( $prev( $prev( $j ) ) ) ), T_AS ) || self::is( $before, T_GLOBAL ) || self::is( $before, T_STATIC ) ) {
				return true;
			}
			// Inside list( … ) =, [ … ] = or a function's parameters ( … ): the bracket enclosing the variable.
			for ( $i = $prev( $j ); $i >= 0; $i = $prev( $i ) ) {
				if ( $closer( $tokens[ $i ] ) ) {
					$i = $open_of( $i );
					continue;
				}
				if ( '[' === $tokens[ $i ] ) { // A [] target, nested or not: what follows the outermost ] is =.
					$after = $at( $next( $close( $i ) ) );
					if ( '=' === $after ) {
						return true;
					}
					if ( ']' === $after || ',' === $after ) {
						continue;
					}
					return false;
				}
				if ( '(' === $tokens[ $i ] ) {
					$opener_before = $at( $prev( $i ) );
					return self::is( $opener_before, T_LIST ) || self::is( $opener_before, T_FUNCTION ) || self::is( $opener_before, T_FN ) || ( self::is( $opener_before, T_STRING ) && self::is( $at( $prev( $prev( $i ) ) ), T_FUNCTION ) );
				}
				if ( ';' === $tokens[ $i ] || '{' === $tokens[ $i ] || '}' === $tokens[ $i ] ) {
					return false;
				}
			}
			return false;
		};
		foreach ( $regions as $r ) {
			for ( $j = $r[0] + 1; $j < $r[1]; $j++ ) {
				if ( ! self::is( $tokens[ $j ], T_VARIABLE ) ) {
					continue;
				}
				list( $text, $last ) = $chain_at( $j );
				if ( $text === $r[2] && $rebinding( $last ) && ! isset( $rebound[ $lines[ $j ] ] ) ) {
					$rebound[ $lines[ $j ] ] = true;
					$problems[]              = $lines[ $j ] . ': ' . self::REASSIGNED;
				}
				$j = $last;
			}
		}
		// Each call: the connections of the regions it is in; asserted when its innermost function (a closure
		// counts, an arrow function does not) calls assert_locked() before it, outside the functions nested in
		// that one.
		foreach ( $calls as $c => $call ) {
			foreach ( $regions as $r ) {
				if ( $inside( $call['index'], $r ) ) {
					$calls[ $c ]['connections'][] = $r[2];
				}
			}
			$own = null;
			foreach ( $functions as $f ) {
				if ( 'function' === $f[2] && $inside( $call['index'], $f ) && ( null === $own || $f[0] > $own[0] ) ) {
					$own = $f;
				}
			}
			if ( null === $own ) {
				continue;
			}
			foreach ( $asserts as $a ) {
				if ( $a[0] < $call['index'] && $inside( $a[0], $own ) && ! $nested( $a[0], $own ) ) {
					$calls[ $c ]['asserted'] = true;
					break;
				}
			}
		}
		$by_line = array();
		foreach ( $regions as $r ) {
			$by_line[] = array( $lines[ $r[0] ], $lines[ $r[1] ] );
		}
		foreach ( $calls as $c => $call ) {
			unset( $calls[ $c ]['index'] );
		}
		return array(
			'calls'        => $calls,
			'regions'      => array_values( array_unique( $by_line, SORT_REGULAR ) ),
			'fence_calls'  => $fence_calls,
			'assert_calls' => count( $asserts ),
			'named_calls'  => $named_calls,
			'problems'     => $problems,
		);
	}

	/**
	 * Whether a literal's first keyword matches; a regex that fails refuses (no match).
	 *
	 * @param string      $pattern Pattern.
	 * @param string|null $literal The literal, or null when the call is not given one.
	 * @return bool
	 */
	private static function begins( string $pattern, $literal ): bool {
		return null !== $literal && 1 === preg_match( $pattern, $literal );
	}

	/**
	 * Whether a call is a write: write(), and run() and query() with anything but a read, a setting or a
	 * transaction statement; rows() with DML; update(), insert(), delete() and replace() on any object ($wpdb
	 * builds DML with them; another object's are registered as exceptions, since a connection under another name
	 * cannot be told from them).
	 *
	 * @param array{method: string, receiver: string, literal: string|null} $call Call.
	 * @return bool
	 */
	private static function is_write( array $call ): bool {
		if ( in_array( $call['method'], self::BUILDS_DML, true ) ) {
			return true;
		}
		if ( 'write' === $call['method'] ) {
			return true;
		}
		if ( 'run' === $call['method'] || 'query' === $call['method'] ) {
			return ! self::begins( self::NOT_A_WRITE, $call['literal'] );
		}
		return self::begins( self::DML, $call['literal'] );
	}

	/**
	 * Whether a fenced call is allowed there: on the fenced connection of a region it is in, by a method that runs
	 * the literal it is given, a literal that begins with a read or DML.
	 *
	 * @param array{method: string, receiver: string, literal: string|null, connections: string[]} $call Call.
	 * @return bool
	 */
	private static function is_allowed( array $call ): bool {
		return '' !== $call['receiver'] && in_array( $call['receiver'], $call['connections'], true ) && ! in_array( $call['method'], self::BUILDS_DML, true ) && self::begins( self::ALLOWED, $call['literal'] );
	}

	/**
	 * The writes of a source: each with its line and its text.
	 *
	 * @param string $source Source.
	 * @return array<int, array{line: int, text: string}>
	 */
	public static function writes( string $source ): array {
		$out = array();
		foreach ( self::analyse( $source )['calls'] as $call ) {
			if ( self::is_write( $call ) ) {
				$out[] = array(
					'line' => $call['line'],
					'text' => $call['text'],
				);
			}
		}
		return $out;
	}

	/**
	 * The fenced regions of a source, as line ranges, and what finding them turned up.
	 *
	 * @param string $source Source.
	 * @return array{regions: array<int, array{0: int, 1: int}>, problems: string[]}
	 */
	public static function fenced_regions( string $source ): array {
		$analysis = self::analyse( $source );
		return array(
			'regions'  => $analysis['regions'],
			'problems' => $analysis['problems'],
		);
	}

	/**
	 * Every way the writes of a file fall short of the registry, and every fenced call outside the invariant:
	 * messages.
	 *
	 * @param string                                                  $file    Path, for the messages.
	 * @param string                                                  $source  Source.
	 * @param array<int, array{0: string, 1: string, 2?: string}>     $entries The registry's entries for it.
	 * @param array<string, mixed>|null                               $analysis Its analysis, when made already.
	 * @return string[]
	 */
	public static function problems( string $file, string $source, array $entries, ?array $analysis = null ): array {
		$analysis = $analysis ?? self::analyse( $source );
		$problems = array();
		$matched  = array_fill( 0, count( $entries ), 0 );
		foreach ( $analysis['calls'] as $call ) {
			if ( ! self::is_write( $call ) ) {
				continue;
			}
			$line = $call['line'];
			$hits = array();
			foreach ( $entries as $k => $entry ) {
				if ( false !== strpos( $call['text'], $entry[0] ) ) {
					$hits[] = $k;
				}
			}
			if ( array() === $hits ) {
				$problems[] = "$file:$line: a write that is not registered: " . trim( explode( "\n", $call['text'] )[0] );
				continue;
			}
			if ( 1 !== count( $hits ) ) {
				$problems[] = "$file:$line: matches " . count( $hits ) . ' entries';
				continue;
			}
			$entry = $entries[ $hits[0] ];
			++$matched[ $hits[0] ];
			if ( self::CONDITIONED === $entry[1] && 1 !== preg_match( '/lock_token|holder = \?/', $call['text'] ) ) {
				$problems[] = "$file:$line: registered as conditioned, but the statement names neither lock_token nor the holder";
			}
			if ( self::LOCKED === $entry[1] ) {
				// The statement itself first, then the file, the function, the connection.
				if ( in_array( $call['method'], self::BUILDS_DML, true ) ) {
					$problems[] = "$file:$line: " . self::BUILT;
				} elseif ( self::begins( self::IMPLICIT_COMMIT, $call['literal'] ) ) {
					$problems[] = "$file:$line: registered as locked, but the statement commits implicitly (CREATE / ALTER / DROP / RENAME / TRUNCATE / LOCK TABLES): it releases the job row, and nothing after it is fenced";
				} elseif ( ! self::begins( self::DML, $call['literal'] ) ) {
					$problems[] = "$file:$line: registered as locked, but the statement is not seen to be DML (a literal beginning with DELETE / INSERT / UPDATE / REPLACE)";
				} elseif ( 0 === $analysis['fence_calls'] ) {
					$problems[] = "$file:$line: registered as locked, but the file never runs a fenced transaction (JobFence::within())";
				} elseif ( ! $call['asserted'] ) {
					$problems[] = "$file:$line: registered as locked, but the function it is in does not assert the lock before it (JobFence::assert_locked())";
				} elseif ( '' === $call['receiver'] || ! in_array( $call['receiver'], $call['connections'], true ) ) {
					$problems[] = "$file:$line: " . self::NOT_ON;
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
		foreach ( $analysis['problems'] as $problem ) {
			$problems[] = "$file:$problem";
		}
		$flagged = array();
		foreach ( $analysis['calls'] as $call ) {
			if ( array() !== $call['connections'] && ! self::is_allowed( $call ) ) {
				$flagged[ $call['line'] ] = true;
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
			$analysis = self::analyse( $source );
			$all      = array_merge( $all, self::problems( $file, $source, $entries, $analysis ) );
			$found   += count( array_filter( $analysis['calls'], array( __CLASS__, 'is_write' ) ) );
			$regions += count( $analysis['regions'] );
			// The control, per file: every ::within( and ::assert_locked( written in it (by the tokens, so a
			// docblock's example does not count) is one the resolver took for the fence; a spelling it did not
			// would otherwise leave the file unfenced with the test green.
			$this->assertSame( $analysis['named_calls'], $analysis['fence_calls'] + $analysis['assert_calls'], "$file: every within() and assert_locked() call is the fence's" );
		}
		$this->assertSame( array(), $all );
		$this->assertGreaterThanOrEqual( 20, $found, 'the control: the scanner finds the writes' );
		$this->assertGreaterThanOrEqual( 4, $regions, 'the control: the scanner finds the fenced regions (two closures, the carry\'s functions)' );
	}

	public function test_the_scanner_finds_an_unregistered_write_and_the_rules_bite(): void {
		$source = "<?php\n\$db->write( 'UPDATE t SET a = 1 WHERE n = ?', array( '1' ) );\n\$db->run( 'SET SESSION x = 1' );\n\$db->rows( 'SELECT 1' );\n\$db->rows( 'delete from t' );\n\$db->rows(\n\t'DELETE FROM t2'\n);\n\$r = \$db->rows( 'SELECT 1' ); \$db->write( 'UPDATE t3 SET b = 2', array() ); // \$db->run( 'DROP TABLE t' );\n\$x = \$db->rows( 'SELECT 1' ) ? \$db->write( 'DELETE FROM t4', array() ) : null;\n\$db->write( 'UPDATE t5 SET c = 3', array() ); \$db->write( 'DELETE FROM t6', array() );\n";
		$this->assertSame( array( 2, 5, 6, 9, 10, 11, 11 ), array_column( self::writes( $source ), 'line' ), 'writes found (DML in lower case, on the line after the call, after a read on the line, in an expression, two on one line), a setting, a read and a comment not' );
		$this->assertSame( "\$db->write( 'UPDATE t3 SET b = 2', array() );", self::writes( $source )[3]['text'], 'a write\'s text: from its receiver to its semicolon' );
		$this->assertSame( "\$db->write( 'DELETE FROM t4', array() )", self::writes( $source )[4]['text'], 'bounded at its parenthesis when no semicolon follows' );
		$rest = array( array( 'delete from', self::EXCEPTION, 'r' ), array( 'DELETE FROM t2', self::EXCEPTION, 'r' ), array( 'UPDATE t3', self::EXCEPTION, 'r' ), array( 'DELETE FROM t4', self::EXCEPTION, 'r' ), array( 'UPDATE t5', self::EXCEPTION, 'r' ), array( 'DELETE FROM t6', self::EXCEPTION, 'r' ) );
		$this->assertStringContainsString( 'not registered', implode( "\n", self::problems( 'x.php', $source, array() ) ) );
		$this->assertStringContainsString( 'names neither lock_token nor the holder', implode( "\n", self::problems( 'x.php', $source, array_merge( array( array( 'SET a = 1', self::CONDITIONED ) ), $rest ) ) ) );
		$one = array_merge( array( array( 'SET a = 1', self::LOCKED ) ), $rest );
		$this->assertStringContainsString( 'never runs a fenced transaction', implode( "\n", self::problems( 'x.php', $source, $one ) ) );
		$this->assertStringContainsString( 'never runs a fenced transaction', implode( "\n", self::problems( 'x.php', "<?php\n// JobFence::within() is mentioned, not called.\n" . substr( $source, 6 ), $one ) ), 'a mention in a comment is not the call' );
		$within     = "<?php\nnamespace WPCheckpoint\\Restore;\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n";
		$unasserted = $within . "\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$asserted   = $within . "\tJobFence::assert_locked( \$db );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$elsewhere  = $within . "} );\nfunction other( \$db ) {\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n}\nfunction f( \$db ) { JobFence::assert_locked( \$db ); }\n";
		$this->assertStringContainsString( 'does not assert the lock before it', implode( "\n", self::problems( 'x.php', $unasserted, array( array( 'SET a = 1', self::LOCKED ) ) ) ), 'inside the transaction, without the guard' );
		$this->assertStringContainsString( 'does not assert the lock before it', implode( "\n", self::problems( 'x.php', $elsewhere, array( array( 'SET a = 1', self::LOCKED ) ) ) ), 'a guard in another function does not count' );
		$one_liner = $within . "\t\$cb = function () use ( \$db ) { JobFence::assert_locked( \$db ); };\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$this->assertStringContainsString( 'does not assert the lock before it', implode( "\n", self::problems( 'x.php', $one_liner, array( array( 'SET a = 1', self::LOCKED ) ) ) ), 'a guard inside a one-line closure in between does not count' );
		$an_arrow = $within . "\t\$fn = fn () => JobFence::assert_locked( \$db );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$this->assertStringContainsString( 'does not assert the lock before it', implode( "\n", self::problems( 'x.php', $an_arrow, array( array( 'SET a = 1', self::LOCKED ) ) ) ), 'a guard inside an arrow function in between does not count' );
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
		$this->assertStringContainsString( 'without a reason', implode( "\n", self::problems( 'x.php', $source, array_merge( array( array( 'SET a = 1', self::EXCEPTION ) ), $rest ) ) ) );
		$this->assertSame( array(), self::problems( 'x.php', $source, array_merge( array( array( 'SET a = 1', self::EXCEPTION, 'a reason' ) ), $rest ) ) );
		$this->assertStringContainsString( 'matches 0 writes', implode( "\n", self::problems( 'x.php', $source, array_merge( array( array( 'SET a = 1', self::EXCEPTION, 'r' ) ), $rest, array( array( 'gone', self::EXCEPTION, 'r' ) ) ) ) ), 'a stale entry' );
		$fenced = "<?php\n\$db->write( 'UPDATE t AS l JOIN j ON j.id = ? SET l.a = 1 WHERE (l.n = ?) AND j.lock_token = ?', array() );\n";
		$this->assertSame( array(), self::problems( 'x.php', $fenced, array( array( 'SET l.a = 1', self::CONDITIONED ) ) ) );
	}

	public function test_inside_a_fenced_transaction_only_a_call_given_a_literal_beginning_with_a_read_or_dml_is_allowed(): void {
		$within = "<?php\nnamespace WPCheckpoint\\Restore;\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n\tJobFence::assert_locked( \$db );\n";
		$locked = array( 'SET a = 1', self::LOCKED );
		foreach ( array( 'DROP TABLE t2', 'drop table t2', ' DROP TABLE t2', '/* why */ TRUNCATE TABLE t', 'COMMIT', 'ROLLBACK', 'START TRANSACTION', 'BEGIN', 'LOCK TABLES t WRITE', 'UNLOCK TABLES', 'SET autocommit = 1', 'SET @@autocommit = 1', 'SET GLOBAL autocommit = 1', 'FLUSH TABLES', 'ANALYZE TABLE t', 'OPTIMIZE TABLE t', 'XA START x', 'SELECTED', 'UPDATES t', 'SET SESSION innodb_lock_wait_timeout = 5' ) as $ending ) {
			$source   = $within . "\t\$db->run( '$ending' );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
			$problems = implode( "\n", self::problems( 'x.php', $source, array( array( "'$ending'", self::EXCEPTION, 'a reason' ), $locked ) ) );
			$this->assertStringContainsString( 'x.php:5: ' . self::NOT_ALLOWED, $problems, "$ending, registered as an exception, in the closure" );
		}
		// In a function that asserts the lock, called from the closure.
		$called = "<?php\nnamespace WPCheckpoint\\Restore;\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n\tf( \$db );\n} );\nfunction f( \$db ) {\n\tJobFence::assert_locked( \$db );\n\t\$db->run( 'TRUNCATE TABLE t' );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n}\n";
		$this->assertStringContainsString( 'x.php:8: ' . self::NOT_ALLOWED, implode( "\n", self::problems( 'x.php', $called, array( array( 'TRUNCATE', self::EXCEPTION, 'r' ), $locked ) ) ) );
		// A call that is not given a literal cannot be seen not to end it.
		$opaque = $within . "\t\$db->run( \$sql );\n\t\$db->run( self::sql( \$x ) );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$this->assertSame( array( 'x.php:5: ' . self::NOT_ALLOWED, 'x.php:6: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $opaque, array( array( '$db->run( $sql );', self::EXCEPTION, 'r' ), array( 'self::sql(', self::EXCEPTION, 'r' ), $locked ) ) );
		// A within() not given a closure is reported, and does not fence what follows it.
		foreach ( array( "fn () => \$db->run( 'DROP TABLE t' )", '$work', "array( \$this, 'work' )", "'work'" ) as $work ) {
			$source = "<?php\nnamespace WPCheckpoint\\Restore;\nJobFence::within( \$db, array( 'a', 'b' ), $work );\nfunction later( \$db ) {\n\t\$db->run( 'DROP TABLE t2' );\n}\n";
			$fenced = self::fenced_regions( $source );
			$this->assertSame( array(), $fenced['regions'], $work );
			$this->assertSame( array( '3: ' . self::NOT_CLOSURE ), $fenced['problems'], $work );
			$entries = array( array( "'DROP TABLE t2'", self::EXCEPTION, 'r' ) );
			if ( false !== strpos( $work, '$db->run(' ) ) {
				$entries[] = array( "'DROP TABLE t'", self::EXCEPTION, 'r' ); // The arrow's own statement is a write of the file, registered like any.
			}
			$this->assertSame( array( 'x.php:3: ' . self::NOT_CLOSURE ), self::problems( 'x.php', $source, $entries ), "$work: later() is not fenced" );
		}
		$this->assertSame( array( array( 3, 5 ) ), self::fenced_regions( "<?php\nnamespace WPCheckpoint\\Restore;\nJobFence::within( \$db, \$f, static function () use ( \$db ) {\n\t\$db->run( 'SELECT 1' );\n} );\n" )['regions'], 'a static closure' );
		// The controls: the same statements in a function outside the fence are the registry's business only; a
		// fenced closure of reads and DML, with braces and comments in its strings and a comment naming a call,
		// is clean; the regions are where they are; a source ending in the identifier does not read past its end.
		$outside = $within . "\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\nfunction g( \$db ) {\n\t\$db->run( 'DROP TABLE t2' );\n\t\$db->run( 'COMMIT' );\n}\n";
		$this->assertSame( array(), self::problems( 'x.php', $outside, array( array( 'DROP TABLE', self::EXCEPTION, 'r' ), $locked ) ) );
		$clean = $within . "\t\$rows = \$db->rows( 'SELECT 1 FROM t WHERE a = ?', array( '{' ) ); // the row \$db->rows( \$q ) read\n\t\$db->rows( ' /* { */ show tables' );\n\t\$db->write( \"UPDATE t SET a = '}' WHERE b = {\$x}\", array() );\n\t\$db->write(\n\t\t// Why: \$db->run( \$sql ).\n\t\t/* and \$db->run( 'DROP TABLE t' ) */\n\t\t'replace into t (a) values (1)',\n\t\tarray()\n\t);\n} );\n";
		$this->assertSame( array(), self::problems( 'x.php', $clean, array( array( "SET a = '}'", self::LOCKED ), array( 'replace into', self::LOCKED ) ) ) );
		$this->assertSame( array( array( 3, 5 ), array( 6, 10 ) ), self::fenced_regions( $called )['regions'], 'the closure, then the function that asserts' );
		$this->assertSame( array( array( 3, 6 ) ), self::fenced_regions( $outside )['regions'], 'g() is not fenced' );
		$this->assertSame( array(), self::fenced_regions( "<?php\n// JobFence::within( \$db, \$f, function () { JobFence::assert_locked( \$db ); } );\nfunction h() {\n}\n" )['regions'], 'a mention in a comment is neither' );
		$this->assertSame( array( 'regions' => array(), 'problems' => array() ), self::fenced_regions( "<?php\nJobFence" ) );
		$this->assertSame( array( 'regions' => array(), 'problems' => array() ), self::fenced_regions( "<?php\nJobFence::" ) );
		// The class, resolved: the same namespace, imported, imported under an alias, fully qualified; and not
		// another class's within(), nor the fence named but not imported from another namespace.
		foreach ( array( "namespace WPCheckpoint\\Restore;\n" => 'JobFence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Restore\\JobFence;\n" => 'JobFence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Restore\\JobFence as Fence;\n" => 'Fence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Restore\\JobFence as Fence;\n " => 'fence', "namespace wpcheckpoint\\restore;\n" => 'jobfence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Restore\\{JobFence, Ledger};\n" => 'JobFence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Restore\\{ Ledger, JobFence as Fence };\n" => 'Fence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Support\\Deleter, WPCheckpoint\\Restore\\JobFence as Fence;\n" => 'Fence', "namespace WPCheckpoint\\Jobs;\n" => '\\WPCheckpoint\\Restore\\JobFence', "namespace WPCheckpoint;\n" => 'Restore\\JobFence', '' => '\\WPCheckpoint\\Restore\\JobFence' ) as $header => $class ) {
			$spelt = "<?php\n{$header}{$class}::within( \$db, \$f, function () use ( \$db ) {\n\t{$class}::assert_locked( \$db );\n\t\$db->run( 'DROP TABLE t' );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\nfunction f( \$db ) {\n\t{$class}::assert_locked( \$db );\n\t\$db->run( 'COMMIT' );\n}\n";
			$shift = substr_count( $header, "\n" );
			$this->assertSame( array( array( 2 + $shift, 6 + $shift ), array( 7 + $shift, 10 + $shift ) ), self::fenced_regions( $spelt )['regions'], $class );
			$this->assertSame( array( 'x.php:' . ( 4 + $shift ) . ': ' . self::NOT_ALLOWED, 'x.php:' . ( 9 + $shift ) . ': ' . self::NOT_ALLOWED ), self::problems( 'x.php', $spelt, array( array( "'DROP TABLE t'", self::EXCEPTION, 'r' ), $locked ) ), $class );
		}
		foreach ( array( array( "namespace WPCheckpoint\\Jobs;\n", 'JobFence' ), array( "namespace WPCheckpoint\\Restore;\nuse WPCheckpoint\\Support\\Deleter as JobFence;\n", 'JobFence' ), array( "namespace WPCheckpoint\\Restore;\n", 'Deleter' ), array( "namespace WPCheckpoint\\Restore;\n", 'self' ), array( "namespace WPCheckpoint\\Jobs;\nuse function WPCheckpoint\\Restore\\JobFence;\n", 'JobFence' ) ) as $case ) {
			list( $header, $class ) = $case;
			$other = "<?php\n{$header}if ( {$class}::within( \$a, \$b ) ) {\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n}\nfunction f( \$db ) {\n\t{$class}::assert_locked( \$db );\n\t\$db->run( 'COMMIT' );\n}\n";
			$this->assertSame( array( 'regions' => array(), 'problems' => array() ), self::fenced_regions( $other ), "$class is not the fence here" );
			$this->assertStringContainsString( 'never runs a fenced transaction', implode( "\n", self::problems( 'x.php', $other, array( $locked, array( "'COMMIT'", self::EXCEPTION, 'r' ) ) ) ), "$class is not the fence here" );
		}
		// A braced namespace: its imports are read; what the resolver did not take for the fence is still counted
		// for the per-file control.
		$braced = "<?php\nnamespace WPCheckpoint\\Jobs {\n\tuse WPCheckpoint\\Restore\\JobFence;\n\tJobFence::within( \$db, \$f, function () use ( \$db ) {\n\t\t\$db->run( 'DROP TABLE t' );\n\t} );\n}\nnamespace Other {\n\tJobFence::within( \$a, \$b, function () {} );\n\tJobFence::assert_locked( \$db );\n}\n";
		$this->assertSame( array( array( 4, 6 ) ), self::fenced_regions( $braced )['regions'] );
		$this->assertSame( array( 'x.php:5: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $braced, array( array( "'DROP TABLE t'", self::EXCEPTION, 'r' ) ) ) );
		$counted = self::analyse( $braced );
		$this->assertSame( array( 3, 1, 0 ), array( $counted['named_calls'], $counted['fence_calls'], $counted['assert_calls'] ), 'three written, one the fence\'s: the control would fail a file like this' );
		// An import after the call, a namespace after it, a trait's use: not the fence where the call is, as PHP
		// resolves it.
		$late = "<?php\nnamespace WPCheckpoint\\Jobs;\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n\t\$db->run( 'DROP TABLE t' );\n} );\nuse WPCheckpoint\\Restore\\JobFence;\nnamespace WPCheckpoint\\Restore;\nclass X {\n\tuse JobFence;\n\tpublic function f( \$db ) {\n\t\tJobFence::assert_locked( \$db );\n\t\t\$db->run( 'COMMIT' );\n\t}\n}\n";
		$this->assertSame( array( 'regions' => array( array( 10, 13 ) ), 'problems' => array() ), self::fenced_regions( $late ), 'the closure before the import is not fenced; the method in the fence\'s namespace is, by the namespace, not the trait' );
		$this->assertSame( array( 'x.php:12: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $late, array( array( "'DROP TABLE t'", self::EXCEPTION, 'r' ) ) ), 'the COMMIT is no write to register, but is refused in the region' );
		// Two writes in one statement are each registered by their own text; escapes and heredocs are read.
		$reached = $within . "\t\$x = \$db->write( 'UPDATE a', array() ) ?: \$db->write( 'UPDATE b', array() );\n\t\$db->write( \"\\n UPDATE c SET a = 1\", array() );\n\t\$db->rows( <<<SQL\n\t\tSELECT 1\n\tSQL );\n\t\$db->rows( <<<'SQL'\n\t\t/* why */ show tables\n\tSQL );\n} );\n";
		$this->assertSame( array( 5, 5, 6 ), array_column( self::writes( $reached ), 'line' ) );
		$this->assertSame( "\$db->write( 'UPDATE a', array() )", self::writes( $reached )[0]['text'], 'bounded at its parenthesis: the second write is not in it' );
		$this->assertSame( array(), self::problems( 'x.php', $reached, array( array( "'UPDATE a'", self::LOCKED ), array( "'UPDATE b'", self::LOCKED ), array( 'UPDATE c', self::LOCKED ) ) ) );
		// WordPress's connection however it is reached ($wpdb split from its arrow, $this->wpdb, $GLOBALS['wpdb'])
		// and any object's update(), insert(), delete() or replace() are writes to register; outside the fence
		// they are the registry's business (an exception with the reason), inside a region they are refused, and
		// registered as locked they are refused: the fence is a transaction on the restore's own connection.
		$others = "\t\$wpdb\n\t\t->query( 'DELETE FROM t' );\n\t\$this->wpdb->Query( 'DELETE FROM t2' );\n\t\$GLOBALS['wpdb']->update( 't3', array() );\n\t\$wpdb\n\t\t->delete( 't4', array() );\n\t\$hashes->update( \$piece );\n\t\$this->cache->DELETE( 'k' );\n\t\$list->insert( 0, 'a' );\n\twpdb()->query( 'DELETE FROM t5' );\n\t( \$wpdb )->query( 'DELETE FROM t6' );\n\t\\wpdb()->query( 'DELETE FROM t7' );\n\t\$w->query( 'DELETE FROM t8' );\n\t\$run['db']->run( 'DELETE FROM t9' );\n";
		$entries = array( array( "'DELETE FROM t'", self::EXCEPTION, 'r' ), array( "'DELETE FROM t2'", self::EXCEPTION, 'r' ), array( "->update( 't3'", self::EXCEPTION, 'r' ), array( "->delete( 't4'", self::EXCEPTION, 'r' ), array( '$hashes->update(', self::EXCEPTION, 'Not a statement.' ), array( '$this->cache->DELETE(', self::EXCEPTION, 'Not a statement.' ), array( '$list->insert(', self::EXCEPTION, 'Not a statement.' ), array( "wpdb()->query( 'DELETE FROM t5'", self::EXCEPTION, 'r' ), array( "'DELETE FROM t6'", self::EXCEPTION, 'r' ), array( "\\wpdb()->query( 'DELETE FROM t7'", self::EXCEPTION, 'r' ), array( "'DELETE FROM t8'", self::EXCEPTION, 'r' ), array( "\$run['db']->run( 'DELETE FROM t9'", self::EXCEPTION, 'r' ) );
		$outside = "<?php\nnamespace WPCheckpoint\\Restore;\nfunction reach( \$db, \$wpdb ) {\n" . $others . "}\n";
		$this->assertSame( array( 5, 6, 7, 9, 10, 11, 12, 13, 14, 15, 16, 17 ), array_column( self::writes( $outside ), 'line' ), 'all of them writes to register (the split receiver\'s on its arrow\'s line; a method in any case; a call\'s result as the receiver, qualified or not)' );
		$this->assertSame( array(), self::problems( 'x.php', $outside, $entries ) );
		$refused = array();
		foreach ( array( 6, 7, 8, 10, 11, 12, 13, 14, 15, 16, 17, 18 ) as $line ) {
			$refused[] = "x.php:$line: " . self::NOT_ALLOWED;
		}
		$this->assertSame( $refused, self::problems( 'x.php', $within . $others . "} );\n", $entries ), 'every one refused in the region: none is on the fenced connection' );
		$locked_others = array();
		foreach ( $entries as $entry ) {
			$locked_others[] = array( $entry[0], self::LOCKED );
		}
		$locked_messages = implode( "\n", self::problems( 'x.php', $within . $others . "} );\n", $locked_others ) );
		$this->assertSame( array( 7, 5 ), array( substr_count( $locked_messages, self::NOT_ON ), substr_count( $locked_messages, self::BUILT ) ), 'none of them can be locked: seven not on the fenced connection, five building their statement' );
		$this->assertSame( "\$db->run( 'DELETE FROM t' );", self::writes( "<?php\nif ( \$x ) \$db->run( 'DELETE FROM t' );\n" )[0]['text'], 'a control structure\'s parentheses are not the receiver' );
		// The fenced connection is the one given to within() (or asserted on), read from the source: a region on
		// $this->db allows $this->db and refuses $db; a parenthesised chain is that chain; a call's result is a
		// receiver of its own; a control structure before the call is not part of it; an assignment to the
		// connection inside the region is refused, a comparison is not.
		$on_this = "<?php\nnamespace WPCheckpoint\\Restore;\nJobFence::within( \$this->db, \$f, function () {\n\tJobFence::assert_locked( \$this->db );\n\t\$this->db->run( 'DELETE FROM t' );\n\t\$db->run( 'DELETE FROM t2' );\n\t( \$this->db )->run( 'DELETE FROM t3' );\n\t\$fn( \$x )->run( 'DELETE FROM t4' );\n\tif ( \$x ) \$this->db->rows( 'SELECT 1' );\n\tif ( \$this->db == \$x ) {\n\t\t\$this->db->rows( 'SELECT 2' );\n\t}\n\t\$this->db = \$wpdb;\n\t\$this->db->run( 'DELETE FROM t5' );\n} );\nfunction f( \$db ) {\n\tJobFence::assert_locked( \$db );\n\t\$db = \$GLOBALS['wpdb'];\n\t\$db->run( 'DELETE FROM t6' );\n}\n";
		$entries = array( array( "'DELETE FROM t'", self::LOCKED ), array( "'DELETE FROM t2'", self::EXCEPTION, 'r' ), array( "'DELETE FROM t3'", self::LOCKED ), array( "'DELETE FROM t4'", self::EXCEPTION, 'r' ), array( "'DELETE FROM t5'", self::LOCKED ), array( "'DELETE FROM t6'", self::LOCKED ) );
		$this->assertSame( array( 'x.php:13: ' . self::REASSIGNED, 'x.php:18: ' . self::REASSIGNED, 'x.php:6: ' . self::NOT_ALLOWED, 'x.php:8: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $on_this, $entries ) );
		$fenced_calls = array();
		foreach ( self::analyse( $on_this )['calls'] as $call ) {
			$fenced_calls[] = $call['receiver'] . ' ' . implode( ',', $call['connections'] );
		}
		$this->assertSame( array( '$this->db $this->db', '$db $this->db', '$this->db $this->db', '$fn($x) $this->db', '$this->db $this->db', '$this->db $this->db', '$this->db $this->db', '$db $db' ), $fenced_calls, 'the receivers as read, and the connections of their regions' );
		$this->assertSame( "( \$this->db )->run( 'DELETE FROM t3' );", self::analyse( $on_this )['calls'][2]['text'] );
		$unreadable = $within . "\t( \$x ? \$db : \$w )->run( 'DELETE FROM t' );\n\t\$handlers['x']( \$db )->run( 'DELETE FROM t2' );\n\t\$this->{\$m}( \$db )->run( 'DELETE FROM t3' );\n\tfoo()( \$db )->run( 'DELETE FROM t4' );\n} );\n";
		$this->assertSame( array( 'x.php:5: ' . self::NOT_ALLOWED, 'x.php:6: ' . self::NOT_ALLOWED, 'x.php:7: ' . self::NOT_ALLOWED, 'x.php:8: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $unreadable, array( array( "'DELETE FROM t'", self::EXCEPTION, 'r' ), array( "'DELETE FROM t2'", self::EXCEPTION, 'r' ), array( "'DELETE FROM t3'", self::EXCEPTION, 'r' ), array( "'DELETE FROM t4'", self::EXCEPTION, 'r' ) ) ), 'a receiver that cannot be read (a conditional, a callee that is an expression) is refused' );
		$this->assertSame( array( '', '', '', '' ), array_column( self::analyse( $unreadable )['calls'], 'receiver' ) );
		// The connection given to within() in parentheses is the connection; within() and the assertion naming
		// different connections is reported; a method that builds its statement has its own refusal.
		$parens = "<?php\nnamespace WPCheckpoint\\Restore;\nJobFence::within( ( \$db ), \$f, function () use ( \$db ) {\n\tJobFence::assert_locked( \$db );\n\t\$db->run( 'DELETE FROM t' );\n\t\$db->delete( 't', array() );\n} );\n";
		$this->assertSame( array( 'x.php:6: ' . self::BUILT, 'x.php:6: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $parens, array( array( "'DELETE FROM t'", self::LOCKED ), array( "->delete( 't'", self::LOCKED ) ) ), 'the parenthesised connection is the connection; the DML-building method is refused in the region and cannot be locked' );
		$mismatch = "<?php\nnamespace WPCheckpoint\\Restore;\nJobFence::within( \$this->db, \$f, function () use ( \$db ) {\n\tJobFence::assert_locked( \$db );\n\t\$db->run( 'DELETE FROM t' );\n} );\n";
		$this->assertSame( array( 'x.php:3: ' . self::MISMATCH, 'x.php:5: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $mismatch, array( array( "'DELETE FROM t'", self::EXCEPTION, 'r' ) ) ) );
		// Every way the connection's name is rebound inside a region is refused; a comparison, a chain split by
		// whitespace that is not an assignment, and a use clause are not.
		$rebound = "<?php\nnamespace WPCheckpoint\\Restore;\nJobFence::within( \$this->db, \$f, function () {\n\tJobFence::assert_locked( \$this->db );\n\t\$this\n\t\t->db = \$wpdb;\n\t\$this->db ??= \$wpdb;\n\t\$this->db .= 'x';\n\tif ( \$this->db === \$x || \$this\n\t\t->db <= 1 ) {\n\t}\n} );\nfunction f( \$db ) {\n\tJobFence::assert_locked( \$db );\n\tforeach ( \$list as \$db ) {\n\t}\n\tforeach ( \$list as \$k => \$db ) {\n\t}\n\t[ \$db ] = \$pair;\n\t[ \$a, [ \$db ] ] = \$pairs;\n\tlist( \$db ) = \$pair;\n\tlist( \$a, \$db ) = \$pair;\n\tglobal \$db;\n\tstatic \$db;\n\t\$cb = function ( \$db ) use ( \$x ) {\n\t};\n\t\$fn = fn ( \$db ) => \$db;\n\t\$cb = function () use ( \$db ) {\n\t};\n\t\$y = array( \$db );\n\t\$z = \$list[ \$db ];\n}\n";
		$expected = array();
		foreach ( array( 5, 7, 8, 15, 17, 19, 20, 21, 22, 23, 24, 25, 27 ) as $line ) {
			$expected[] = "x.php:$line: " . self::REASSIGNED;
		}
		$this->assertSame( $expected, self::problems( 'x.php', $rebound, array() ) );
		$cased = $within . "\t\$db->RUN( 'DROP TABLE t' );\n\t\$db->Write( 'UPDATE t SET a = 1', array() );\n} );\n";
		$this->assertSame( array( 'x.php:5: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $cased, array( array( "'DROP TABLE t'", self::EXCEPTION, 'r' ), array( 'SET a = 1', self::LOCKED ) ) ), 'a method in another case is the method' );
		// Several calls in one statement: each judged on its own, a line reported once; the nested call on its
		// own line reported there.
		$several = $within . "\t\$db->run( \$sql ); \$db->rows( 'SELECT 1' );\n\t\$db->write( \$this->sql(), \$db->rows( 'SELECT 1' ) );\n\t\$db->write( 'UPDATE t SET a = ?', \$db->rows( 'SELECT 1' ) );\n\t\$db->write( 'UPDATE t SET a = ?', array( \$db->rows( \$q ) ) );\n\t\$cb = function () use ( \$db ) { JobFence::assert_locked( \$db ); \$db->run( 'DROP TABLE t' ); };\n\t\$db->write(\n\t\t'UPDATE t SET a = ?',\n\t\tarray( \$db->rows( \$q ) )\n\t);\n} );\n";
		$entries = array( array( '$db->run( $sql );', self::EXCEPTION, 'r' ), array( '$this->sql()', self::EXCEPTION, 'r' ), array( "SET a = ?', \$db->rows", self::EXCEPTION, 'r' ), array( 'array( $db->rows( $q ) ) );', self::EXCEPTION, 'r' ), array( "'DROP TABLE t'", self::EXCEPTION, 'r' ), array( "SET a = ?',\n", self::EXCEPTION, 'r' ) );
		$this->assertSame( array( 'x.php:5: ' . self::NOT_ALLOWED, 'x.php:6: ' . self::NOT_ALLOWED, 'x.php:8: ' . self::NOT_ALLOWED, 'x.php:9: ' . self::NOT_ALLOWED, 'x.php:12: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $several, $entries ) );
		$this->assertSame( array( array( 3, 14 ), array( 9, 9 ) ), self::fenced_regions( $several )['regions'] );
		// A closure nested in a function that is not fenced asserts for itself: the function's own statements are
		// not examined, the closure's are; an arrow function with a default parameter fences nothing.
		$nested = "<?php\nnamespace WPCheckpoint\\Restore;\nfunction outer( \$db ) {\n\t\$db->run( 'DROP TABLE t' );\n\t\$cb = function () use ( \$db ) {\n\t\tJobFence::assert_locked( \$db );\n\t\t\$db->run( 'DROP TABLE t2' );\n\t};\n\t\$fn = fn ( \$o = array( 'a' => 1 ) ) => JobFence::assert_locked( \$db );\n\t\$db->run( 'COMMIT' );\n}\n";
		$this->assertSame( array( array( 5, 8 ) ), self::fenced_regions( $nested )['regions'] );
		$this->assertSame( array( 'x.php:7: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $nested, array( array( "'DROP TABLE t'", self::EXCEPTION, 'r' ), array( "'DROP TABLE t2'", self::EXCEPTION, 'r' ) ) ) );
		// A literal the regex cannot read (a byte that is not UTF-8) is refused, not let through: in a region, and
		// as a locked write of the registry; the control is the same literal without the byte.
		$bytes = $within . "\t\$db->run( '\xff SELECT 1' );\n\t\$db->write( '\xffUPDATE t SET a = 1', array() );\n} );\n";
		$this->assertSame( array( 'x.php:6: registered as locked, but the statement is not seen to be DML (a literal beginning with DELETE / INSERT / UPDATE / REPLACE)', 'x.php:5: ' . self::NOT_ALLOWED, 'x.php:6: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $bytes, array( array( 'SELECT 1', self::EXCEPTION, 'r' ), $locked ) ) );
		$this->assertSame( array(), self::problems( 'x.php', str_replace( "\xff", '', $bytes ), array( $locked ) ), 'the control (the SELECT, readable, is no write to register)' );
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
	 * @return array{source: string, regions: array<int, array{0: int, 1: int}>, not_allowed: int[], not_closure: int[], reassigned: int[], seen: array<string, int>}
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
		$seen    = array();
		// How the file spells the class: the same namespace, imported, aliased, fully qualified; and what else
		// the file has: another class's within() and assert_locked(), which are not the fence.
		$spelling = $pick( array( 'same', 'import', 'alias', 'alias_cased', 'qualified', 'group', 'group_alias', 'braced' ) );
		$seen[ "spelt_$spelling" ] = 1;
		$fence  = array( 'same' => 'JobFence', 'import' => 'JobFence', 'alias' => 'Fence', 'alias_cased' => 'fence', 'qualified' => '\\WPCheckpoint\\Restore\\JobFence', 'group' => 'JobFence', 'group_alias' => 'Fence', 'braced' => 'JobFence' )[ $spelling ];
		$lines  = array( '<?php', 'same' === $spelling ? 'namespace WPCheckpoint\\Restore;' : ( 'braced' === $spelling ? 'namespace WPCheckpoint\\Jobs {' : 'namespace WPCheckpoint\\Jobs;' ) );
		if ( 'import' === $spelling || 'braced' === $spelling ) {
			$lines[] = 'use WPCheckpoint\\Restore\\JobFence;';
		} elseif ( 'alias' === $spelling || 'alias_cased' === $spelling ) {
			$lines[] = 'use WPCheckpoint\\Restore\\JobFence as Fence;';
		} elseif ( 'group' === $spelling ) {
			$lines[] = 'use WPCheckpoint\\Restore\\{JobFence, Ledger};';
		} elseif ( 'group_alias' === $spelling ) {
			$lines[] = 'use WPCheckpoint\\Restore\\{ Ledger, JobFence as Fence };';
		}
		$lines[] = 'use WPCheckpoint\\Support\\Deleter;';
		/*
		 * A statement: its lines, whether a fenced region allows it (every call in it), and whether it is a call at
		 * all (a filler, a comment, another class's within() are not).
		 */
		$statement = static function ( string $conn ) use ( $pick, $cases, $allowed, $ending, $leads, &$seen ): array {
			$kind = $pick( array( 'literal', 'literal', 'literal', 'multiline', 'opaque', 'filler', 'comment', 'two_calls', 'nested_call', 'nested_lines', 'other_within', 'trailing_comment', 'escaped', 'heredoc', 'split', 'foreign', 'control_before', 'reassign', 'compare' ) );
			if ( 'filler' === $kind ) {
				$seen['filler'] = 1;
				return array( 'lines' => array( $pick( array( '$x = 1;', "\$y = array( '{', '}' );", '$z = "a {$x} b";', "\$fn = fn ( \$o = array( 'a' => 1 ) ) => \$o;" ) ) ), 'allowed' => true, 'statement' => false );
			}
			if ( 'reassign' === $kind ) { // The connection's name rebound: refused in a region.
				$forms = array(
					'assign'   => "$conn = " . $pick( array( '$wpdb', "\$GLOBALS['wpdb']", '$db2' ) ) . ';',
					'compound' => "$conn " . $pick( array( '??=', '.=', '+=' ) ) . ' $x;',
					'foreach'  => 'foreach ( $list as ' . $pick( array( '', '$k => ' ) ) . "$conn ) {",
					'list'     => $pick( array( "list( $conn ) = \$pair;", "list( \$a, $conn ) = \$pair;", "[ $conn ] = \$pair;", "[ \$a, [ $conn ] ] = \$pairs;" ) ),
					'global'   => $pick( array( 'global', 'static' ) ) . " $conn;",
					'param'    => $pick( array( "\$cb = function ( $conn ) use ( \$x ) {", "\$fn = fn ( $conn ) => \$x;" ) ),
				);
				if ( '$this->db' === $conn ) {
					unset( $forms['foreach'], $forms['list'], $forms['global'], $forms['param'] ); // Not variables.
				}
				$form = $pick( array_keys( $forms ) );
				$seen[ "rebind_$form" ] = 1;
				$lines = array( $forms[ $form ] );
				if ( '{' === substr( $forms[ $form ], -1 ) ) {
					$lines[] = '}';
				} elseif ( 'param' === $form && false !== strpos( $forms[ $form ], 'function' ) ) {
					$lines[] = '};';
				}
				return array( 'lines' => $lines, 'allowed' => true, 'statement' => false, 'reassigns' => true );
			}
			if ( 'compare' === $kind ) { // A comparison is no assignment.
				$seen['compare'] = 1;
				return array( 'lines' => array( "if ( $conn " . $pick( array( '==', '===', '!=' ) ) . " \$x ) {", "\t{$conn}->rows( 'SELECT 1' );", '}' ), 'allowed' => true, 'statement' => true );
			}
			if ( 'control_before' === $kind ) { // A control structure's parentheses before the call are not its receiver.
				$seen['control_before'] = 1;
				$ok = 1 === mt_rand( 0, 1 );
				return array( 'lines' => array( "if ( \$x ) {$conn}->rows( '" . ( $ok ? 'SELECT 1' : 'FLUSH TABLES' ) . "' );" ), 'allowed' => $ok, 'statement' => true );
			}
			if ( 'foreign' === $kind ) { // Another object's update(), delete() ...: a connection under another name cannot be told from it.
				$seen['foreign'] = 1;
				return array( 'lines' => array( $pick( array( '$hashes->update( $piece );', "\$this->cache->delete( 'k' );", "\$list->insert( 0, 'a' );", "\$wpdb->replace( 't', array() );" ) ) ), 'allowed' => false, 'statement' => true );
			}
			if ( 'comment' === $kind ) {
				$seen['comment'] = 1;
				return array( 'lines' => array( $pick( array( "// \$db->run( 'DROP TABLE t' );", "/* \$db->run( \$sql ); */" ) ) ), 'allowed' => true, 'statement' => false );
			}
			if ( 'other_within' === $kind ) {
				$seen['other_within'] = 1;
				return array( 'lines' => array( $pick( array( 'Deleter::within( $a, $b );', 'Deleter::assert_locked( $db );', 'Other::within( $db, $f, $work );' ) ) ), 'allowed' => true, 'statement' => false );
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
			$seen[ $ok ? 'allowed' : 'ending' ] = 1; // The literal's keyword; what is emitted is counted below.
			if ( '' !== trim( $lead ) ) {
				$seen[ $ok ? 'allowed_after_comment' : 'ending_after_comment' ] = 1;
			}
			$quoted = false === strpos( $text, "'" ) ? "'$text'" : "\"$text\"";
			if ( 'escaped' === $kind ) { // A double-quoted literal whose escape comes before the keyword.
				$seen['escaped'] = 1;
				return array( 'lines' => array( "{$conn}->write( \"\\n\\t " . str_replace( '"', '\\"', $text ) . "\", array() );" ), 'allowed' => $ok, 'statement' => true );
			}
			if ( 'heredoc' === $kind ) {
				$seen['heredoc'] = 1;
				$marker = $pick( array( 'SQL', "'SQL'" ) );
				return array( 'lines' => array( "{$conn}->rows( <<<$marker", "\t$text", 'SQL );' ), 'allowed' => $ok, 'statement' => true );
			}
			if ( 'multiline' === $kind ) {
				$seen['multiline'] = 1;
				$lines = array( "{$conn}->write(", '	// Why: $db->run( $sql ).', "	$quoted," );
				for ( $i = mt_rand( 0, 3 ); $i > 0; $i-- ) {
					$lines[] = '	// And why.';
				}
				$lines[] = '	array()';
				$lines[] = ');';
				return array( 'lines' => $lines, 'allowed' => $ok, 'statement' => true );
			}
			if ( 'nested_call' === $kind || 'nested_lines' === $kind ) { // A call nested in the statement: allowed, or not.
				$other_ok = 1 === mt_rand( 0, 1 );
				$other    = $other_ok ? "{$conn}->rows( 'SELECT 1' )" : $pick( array( "{$conn}->rows( \$q )", "{$conn}->run( 'FLUSH TABLES' )", "\$wpdb->rows( 'SELECT 1' )" ) );
				if ( 'nested_lines' === $kind ) { // The nested call on a line of its own: judged there.
					$seen[ $kind . ( $ok && $other_ok ? '_allowed' : '_refused' ) ] = 1;
					return array( 'lines' => array( "{$conn}->write(", "	$quoted,", "	array( $other )", ');' ), 'allowed' => $ok, 'statement' => true, 'nested' => array( 2, $other_ok ) );
				}
				$seen[ $kind . ( $ok && $other_ok ? '_allowed' : '_refused' ) ] = 1;
				return array( 'lines' => array( "{$conn}->write( $quoted, array( $other ) );" ), 'allowed' => $ok && $other_ok, 'statement' => true );
			}
			// The shapes on a picked receiver, with a method in any case: only the block's connection is allowed
			// (as itself, or parenthesised), so what is emitted on any other is refused whatever its literal.
			$receivers = array( array( 'conn', $conn ), array( 'conn', $conn ), array( 'conn', $conn ), array( 'conn_parens', "( $conn )" ), array( 'db', '$db' ), array( 'thisdb', '$this->db' ), array( 'wpdb', '$wpdb' ), array( 'thiswpdb', '$this->wpdb' ), array( 'globalswpdb', "\$GLOBALS['wpdb']" ), array( 'wpdbcall', 'wpdb()' ), array( 'wpdbparens', '( $wpdb )' ), array( 'wpdbqualified', '\\wpdb()' ), array( 'other', '$w' ), array( 'offset', "\$run['db']" ), array( 'call', '$fn( $x )' ) );
			$picked    = $pick( $receivers );
			$receiver  = $picked[1];
			$seen[ 'receiver_' . $picked[0] ] = 1;
			if ( in_array( $receiver, array( '$db', '$this->db' ), true ) && $receiver !== $conn ) {
				$seen['receiver_other_connection'] = 1; // The other connection's spelling in this block: refused.
			}
			$ok_call   = $ok && in_array( $receiver, array( $conn, "( $conn )" ), true );
			if ( 'split' === $kind ) { // The receiver on a line of its own: the call is on the arrow's line.
				$seen['split'] = 1;
				return array( 'lines' => array( $receiver, "\t->" . $pick( array( 'query', 'run' ) ) . "( $quoted );" ), 'allowed' => $ok_call, 'statement' => true, 'call_line' => 1 );
			}
			$method = $cases( $pick( array( 'run', 'write', 'rows', 'query' ) ) );
			$call   = 'write' === strtolower( $method ) ? "{$receiver}->{$method}( $quoted, array() );" : "{$receiver}->{$method}( $quoted );";
			if ( 'trailing_comment' === $kind ) {
				$seen['trailing_comment'] = 1;
				return array( 'lines' => array( "$call // \$db->rows( \$q ) read" ), 'allowed' => $ok_call, 'statement' => true );
			}
			if ( 'two_calls' === $kind ) {
				$other_ok = 1 === mt_rand( 0, 1 );
				$other    = $other_ok ? "{$conn}->rows( 'SELECT 1' )" : $pick( array( "{$conn}->rows( \$q )", "{$conn}->run( 'FLUSH TABLES' )" ) );
				$seen[ $kind . ( $ok_call && $other_ok ? '_allowed' : '_refused' ) ] = 1;
				return array( 'lines' => array( "$call $other;" ), 'allowed' => $ok_call && $other_ok, 'statement' => true );
			}
			$seen[ $ok_call ? 'emitted_allowed' : 'emitted_refused' ] = 1;
			return array( 'lines' => array( $call ), 'allowed' => $ok_call, 'statement' => true );
		};
		$regions     = array();
		$not_allowed = array();
		$not_closure = array();
		$reassigned  = array();
		/*
		 * The statements of a block (a closure, a function, top-level code) on its connection, with a closure
		 * nested in it now and then: one that asserts the lock fences itself, one that does not is fenced only as
		 * its surroundings are.
		 */
		$fill = static function ( string $kind, bool $fenced, int $depth, string $conn ) use ( &$fill, &$lines, &$regions, &$not_allowed, &$reassigned, &$seen, $statement, $pick, $fence ): void {
			$indent = str_repeat( '	', $depth );
			$count  = mt_rand( 0, 4 );
			$assert = 'asserting' === $kind ? mt_rand( 0, $count ) : ( ( 'closure' === $kind || 'static' === $kind ) && 1 === mt_rand( 0, 1 ) ? 0 : -1 ); // A plain function or closure never asserts: that would fence it.
			for ( $i = 0; $i <= $count; $i++ ) {
				if ( $i === $assert ) {
					$lines[] = "$indent{$fence}::assert_locked( $conn );";
				}
				if ( $i === $count ) {
					break;
				}
				if ( $depth < 2 && 0 === mt_rand( 0, 5 ) ) { // A nested closure.
					$asserts = 1 === mt_rand( 0, 1 );
					$one     = 1 === mt_rand( 0, 2 );
					$seen[ 'nested_' . ( $asserts ? 'asserting' : 'plain' ) . ( $fenced ? '_in_fenced' : '_outside' ) ] = 1;
					if ( $one ) {
						$text    = $pick( array( "{$conn}->run( 'SELECT 1' );", "{$conn}->run( 'DROP TABLE t' );", "{$conn}->run( \$sql );" ) );
						$lines[] = "$indent\$cb = function () use ( \$db ) { " . ( $asserts ? "{$fence}::assert_locked( $conn ); " : '' ) . "$text };";
						$line    = count( $lines );
						if ( $asserts ) {
							$regions[] = array( $line, $line );
						}
						if ( ( $asserts || $fenced ) && "{$conn}->run( 'SELECT 1' );" !== $text ) {
							$not_allowed[] = $line;
						}
						$seen['nested_one_line'] = 1;
						continue;
					}
					$lines[] = "$indent\$cb = function () use ( \$db ) {";
					$open    = count( $lines );
					$fill( $asserts ? 'asserting' : 'plain', $asserts || $fenced, $depth + 1, $conn );
					$lines[] = "$indent};";
					if ( $asserts ) {
						$regions[] = array( $open, count( $lines ) );
					}
					continue;
				}
				$s    = $statement( $conn );
				$line = count( $lines ) + 1 + ( $s['call_line'] ?? 0 );
				foreach ( $s['lines'] as $text ) {
					$lines[] = $indent . $text;
				}
				if ( $fenced && isset( $s['reassigns'] ) ) {
					$reassigned[] = $line;
					$seen['reassign_in_fenced'] = 1;
				}
				if ( $fenced && $s['statement'] && ! $s['allowed'] ) {
					$not_allowed[] = $line;
					$seen[ "violation_in_$kind" ] = 1;
				}
				if ( $fenced && isset( $s['nested'] ) && ! $s['nested'][1] ) {
					$not_allowed[] = $line + $s['nested'][0];
				}
				if ( $fenced && $s['statement'] && isset( $s['call_line'] ) ) {
					$seen['split_in_fenced'] = 1;
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
			$conn = $pick( array( '$db', '$db', '$this->db' ) ); // The block's connection: given to within(), or asserted on.
			$seen[ '$db' === $conn ? 'conn_db' : 'conn_thisdb' ] = 1;
			if ( 'closure' === $kind ) {
				$lines[] = "{$fence}::within( $conn, \$f, function () use ( \$db ) {";
			} elseif ( 'static' === $kind ) {
				$lines[] = "{$fence}::within( $conn, array( \$a, \$b ), static function () use ( \$db ) {";
			} elseif ( 'top' !== $kind ) {
				$lines[] = "function f$b( \$db ) {";
			}
			$fill( $kind, $fenced, 'top' === $kind ? 0 : 1, $conn );
			if ( 'top' === $kind ) {
				continue;
			}
			$lines[] = 'asserting' === $kind || 'plain' === $kind ? '}' : '} );';
			if ( $fenced ) {
				$regions[] = array( $open, count( $lines ) );
			}
		}
		if ( 'braced' === $spelling ) {
			$lines[] = '}';
		}
		sort( $regions );
		$not_allowed = array_values( array_unique( $not_allowed ) );
		sort( $not_allowed );
		sort( $reassigned );
		return array(
			'source'      => implode( "\n", $lines ) . "\n",
			'regions'     => $regions,
			'not_allowed' => $not_allowed,
			'not_closure' => $not_closure,
			'reassigned'  => $reassigned,
			'seen'        => $seen,
		);
	}

	/**
	 * @dataProvider seeds
	 */
	public function test_generated_sources_report_exactly_the_calls_outside_the_invariant( int $seed ): void {
		$made    = self::generate( $seed );
		$fenced  = self::fenced_regions( $made['source'] );
		$regions = $fenced['regions'];
		sort( $regions );
		$this->assertSame( $made['regions'], $regions, "regions of:\n" . $made['source'] );
		$reported   = array();
		$closure    = array();
		$reassigned = array();
		foreach ( self::problems( 'x.php', $made['source'], array() ) as $problem ) {
			if ( false !== strpos( $problem, self::NOT_ALLOWED ) ) {
				$reported[] = (int) explode( ':', $problem )[1];
			} elseif ( false !== strpos( $problem, self::NOT_CLOSURE ) ) {
				$closure[] = (int) explode( ':', $problem )[1];
			} elseif ( false !== strpos( $problem, self::REASSIGNED ) ) {
				$reassigned[] = (int) explode( ':', $problem )[1];
			}
		}
		sort( $reassigned );
		$this->assertSame( $made['not_allowed'], $reported, "calls outside the invariant of:\n" . $made['source'] );
		$this->assertSame( $made['not_closure'], $closure, "within() calls without a closure of:\n" . $made['source'] );
		$this->assertSame( $made['reassigned'], $reassigned, "assignments to the connection of:\n" . $made['source'] );
	}

	public function test_the_generator_covered_every_situation(): void {
		$seen = array();
		for ( $seed = 1; $seed <= self::SOURCES; $seed++ ) {
			$seen = array_merge( $seen, self::generate( $seed )['seen'] );
		}
		foreach ( array( 'closure', 'static', 'arrow', 'variable', 'asserting', 'plain', 'top', 'filler', 'comment', 'opaque', 'allowed', 'ending', 'allowed_after_comment', 'ending_after_comment', 'multiline', 'violation_in_closure', 'violation_in_static', 'violation_in_asserting', 'spelt_same', 'spelt_import', 'spelt_alias', 'spelt_qualified', 'other_within', 'trailing_comment', 'two_calls_allowed', 'two_calls_refused', 'nested_call_allowed', 'nested_call_refused', 'nested_lines_allowed', 'nested_lines_refused', 'nested_asserting_in_fenced', 'nested_asserting_outside', 'nested_plain_in_fenced', 'nested_plain_outside', 'nested_one_line', 'spelt_group', 'spelt_group_alias', 'spelt_alias_cased', 'spelt_braced', 'receiver_conn', 'receiver_conn_parens', 'receiver_db', 'receiver_thisdb', 'receiver_wpdb', 'receiver_thiswpdb', 'receiver_globalswpdb', 'receiver_wpdbcall', 'receiver_wpdbparens', 'receiver_wpdbqualified', 'receiver_other', 'receiver_offset', 'receiver_call', 'conn_db', 'conn_thisdb', 'emitted_allowed', 'emitted_refused', 'escaped', 'heredoc', 'split', 'split_in_fenced', 'foreign', 'control_before', 'reassign_in_fenced', 'rebind_assign', 'rebind_compound', 'rebind_foreach', 'rebind_list', 'rebind_global', 'rebind_param', 'receiver_other_connection', 'compare' ) as $situation ) {
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
					if ( ! in_array( $i + 1, $made['not_allowed'], true ) && 1 === preg_match( '/^(\t+)(\$db|\$this->db)->(?:run|rows|query)\( [\'"]' . self::SQL_GAP . '(?:select|show|delete|insert|update|replace)\b[^;]*;$/i', $lines[ $i ], $m ) ) {
						$lines[ $i ] = $m[1] . $m[2] . "->run( 'FLUSH TABLES' );";
						$changed     = $i + 1;
					}
				}
			}
		}
		$this->assertNotNull( $changed, 'the control: an allowed statement in a region to turn' );
		$expected   = $made['not_allowed'];
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
