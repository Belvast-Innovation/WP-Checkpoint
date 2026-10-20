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
 * statements on a line), the class is the one resolved against the file's namespace and imports (imported, fully
 * qualified, aliased; another class's within() is not a fence), an arrow function's parameters are skipped, and a
 * closure nested in a function asserts the lock for itself, not for the function around it. The regexes left are
 * on a literal's first characters only, and a regex that fails refuses. Fixed examples show each rule biting; a
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

	/** The methods that run a statement: on a Queries connection (and query() on any), and those only $wpdb has. */
	const METHODS   = array( 'write', 'run', 'rows', 'query', 'update', 'insert', 'delete', 'replace' );
	const WPDB_ONLY = array( 'update', 'insert', 'delete', 'replace' );

	const NOT_ALLOWED = 'inside a fenced transaction, a call that is not given a literal beginning with SELECT / SHOW / DELETE / INSERT / UPDATE / REPLACE: anything else (DDL, LOCK TABLES, COMMIT, FLUSH, a statement built at run time) may end the transaction and release the job row, and nothing after it is fenced';
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
	 * parenthesis, and the semicolon when one follows), and whether it is fenced and asserted before. A region: the
	 * closure given to the fence's within(), and every function whose body (not a function nested in it) calls
	 * the fence's assert_locked(). The fence is the class resolved against the namespace and the imports (plain,
	 * aliased, grouped) the walk has met so far, as PHP resolves it.
	 *
	 * @param string $source Source.
	 * @return array{calls: array<int, array{line: int, method: string, receiver: string, literal: string|null, text: string, fenced: bool, asserted: bool}>, regions: array<int, array{0: int, 1: int}>, fence_calls: int, assert_calls: int, problems: string[]}
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
		$import    = static function ( int $k ) use ( $tokens, $n, $skip, &$imports ): void { // From a use token.
			$text = '';
			for ( $k++; $k < $n && ';' !== $tokens[ $k ]; $k++ ) {
				$text .= $skip( $tokens[ $k ] ) ? ' ' : ( is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ] );
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
				$imports[ $alias ] = $class;
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
				$first    = explode( '\\', $name )[0];
				$resolved = isset( $imports[ $first ] ) ? $imports[ $first ] . substr( $name, strlen( $first ) ) : ( '' === $namespace ? $name : $namespace . '\\' . $name );
			}
			return self::FENCE === $resolved;
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
		$functions    = array(); // [open, close, kind] in order of appearance; kind 'function' or 'fn'.
		$asserts      = array(); // Token indexes of the fence's assert_locked() calls.
		$regions      = array(); // [open, close] token ranges.
		$problems     = array();
		$fence_calls  = 0;
		$calls        = array();
		$depth        = 0;
		for ( $k = 0; $k < $n; $k++ ) {
			$t = $tokens[ $k ];
			if ( self::opens( $t ) ) {
				++$depth;
			} elseif ( '}' === $t ) {
				--$depth;
			} elseif ( self::is( $t, T_NAMESPACE ) && ! self::is( $at( $next( $k ) ), T_NS_SEPARATOR ) ) {
				$namespace = ltrim( $name_at( $next( $k ), true ), '\\' );
				$imports   = array();
			} elseif ( self::is( $t, T_USE ) && 0 === $depth && '(' !== $at( $next( $k ) ) ) {
				$import( $k );
			} elseif ( self::is( $t, T_FUNCTION ) ) {
				$range = $body( $k );
				if ( null !== $range ) {
					$functions[] = array( $range[0], $range[1], 'function' );
				}
			} elseif ( self::is( $t, T_FN ) ) {
				$functions[] = array( $k, $arrow( $k ), 'fn' );
			} elseif ( self::is( $t, T_DOUBLE_COLON ) && self::is( $at( $next( $k ) ), T_STRING ) && '(' === $at( $next( $next( $k ) ) ) && $is_fence( $k ) ) {
				$method = (string) $tokens[ $next( $k ) ][1];
				if ( 'assert_locked' === $method ) {
					$asserts[] = $k;
				} elseif ( 'within' === $method ) {
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
						$regions[] = array( $range[0], $range[1] );
					}
				}
			} elseif ( self::is( $t, T_OBJECT_OPERATOR ) && self::is( $at( $next( $k ) ), T_STRING ) && in_array( $tokens[ $next( $k ) ][1], self::METHODS, true ) && '(' === $at( $next( $next( $k ) ) ) ) {
				// A call: its receiver chain (variables, properties, static members, array offsets), its first
				// argument, its closing parenthesis and the semicolon after it.
				$method = (string) $tokens[ $next( $k ) ][1];
				$start  = $k;
				for ( $j = $prev( $k ); $j >= 0; $j = $prev( $j ) ) {
					if ( ']' === $tokens[ $j ] ) {
						$j = $open_of( $j );
					} elseif ( ! ( self::is( $tokens[ $j ], T_VARIABLE ) || self::is( $tokens[ $j ], T_STRING ) || self::is( $tokens[ $j ], T_OBJECT_OPERATOR ) || self::is( $tokens[ $j ], T_DOUBLE_COLON ) ) ) {
						break;
					}
					$start = $j;
				}
				$receiver = '';
				for ( $j = $start; $j < $k; $j++ ) {
					$receiver .= $skip( $tokens[ $j ] ) ? '' : ( is_array( $tokens[ $j ] ) ? $tokens[ $j ][1] : $tokens[ $j ] );
				}
				if ( in_array( $method, self::WPDB_ONLY, true ) && ! self::is_wpdb( $receiver ) ) {
					continue; // Not a statement: another object's update(), delete() ...
				}
				$paren = $next( $next( $k ) );
				$end   = $close( $paren );
				if ( ';' === $at( $next( $end ) ) ) {
					$end = $next( $end );
				}
				$calls[] = array(
					'index'    => $k,
					'line'     => $lines[ $k ],
					'method'   => $method,
					'receiver' => $receiver,
					'literal'  => $literal_at( $next( $paren ) ),
					'text'     => substr( $source, $offsets[ $start ], $offsets[ $end + 1 ] - $offsets[ $start ] ),
					'fenced'   => false,
					'asserted' => false,
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
				if ( $inside( $a, $f ) && ! $nested( $a, $f ) ) {
					$regions[] = array( $f[0], $f[1] );
					break;
				}
			}
		}
		$regions = array_values( array_unique( $regions, SORT_REGULAR ) );
		// Each call: fenced when inside a region; asserted when its innermost function (a closure counts, an arrow
		// function does not) calls assert_locked() before it, outside the functions nested in that one.
		foreach ( $calls as $c => $call ) {
			foreach ( $regions as $r ) {
				if ( $inside( $call['index'], $r ) ) {
					$calls[ $c ]['fenced'] = true;
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
				if ( $a < $call['index'] && $inside( $a, $own ) && ! $nested( $a, $own ) ) {
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
			'problems'     => $problems,
		);
	}

	/**
	 * Whether a receiver is WordPress's connection: $wpdb, however reached ($this->wpdb, $GLOBALS['wpdb']).
	 *
	 * @param string $receiver Receiver text, without whitespace.
	 * @return bool
	 */
	private static function is_wpdb( string $receiver ): bool {
		return 1 === preg_match( '/wpdb(?:[\'"]\])?$/', $receiver );
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
	 * transaction statement; rows() with DML; $wpdb's methods that build DML.
	 *
	 * @param array{method: string, receiver: string, literal: string|null} $call Call.
	 * @return bool
	 */
	private static function is_write( array $call ): bool {
		if ( in_array( $call['method'], self::WPDB_ONLY, true ) ) {
			return true; // Recorded only on $wpdb (analyse()).
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
	 * Whether a fenced call is allowed there: given a literal that begins with a read or DML, or one of $wpdb's
	 * methods that build DML themselves.
	 *
	 * @param array{method: string, receiver: string, literal: string|null} $call Call.
	 * @return bool
	 */
	private static function is_allowed( array $call ): bool {
		return in_array( $call['method'], self::WPDB_ONLY, true ) || self::begins( self::ALLOWED, $call['literal'] );
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
	public static function problems( string $file, string $source, array $entries, array $analysis = null ): array {
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
				$builds_dml = in_array( $call['method'], self::WPDB_ONLY, true ); // $wpdb->update() and the like: DML by construction.
				if ( self::begins( self::IMPLICIT_COMMIT, $call['literal'] ) ) {
					$problems[] = "$file:$line: registered as locked, but the statement commits implicitly (CREATE / ALTER / DROP / RENAME / TRUNCATE / LOCK TABLES): it releases the job row, and nothing after it is fenced";
				} elseif ( ! $builds_dml && ! self::begins( self::DML, $call['literal'] ) ) {
					$problems[] = "$file:$line: registered as locked, but the statement is not seen to be DML (a literal beginning with DELETE / INSERT / UPDATE / REPLACE)";
				} elseif ( 0 === $analysis['fence_calls'] ) {
					$problems[] = "$file:$line: registered as locked, but the file never runs a fenced transaction (JobFence::within())";
				} elseif ( ! $call['asserted'] ) {
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
		foreach ( $analysis['problems'] as $problem ) {
			$problems[] = "$file:$problem";
		}
		$flagged = array();
		foreach ( $analysis['calls'] as $call ) {
			if ( $call['fenced'] && ! self::is_allowed( $call ) ) {
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
			// The control, per file: every within() and assert_locked() written in it is one the resolver
			// recognised as the fence (a spelling it did not would silently leave the file unfenced).
			$this->assertSame( preg_match_all( '/::within\(\s*\$/', $source ), $analysis['fence_calls'], "$file: every within() call is the fence's" );
			$this->assertSame( preg_match_all( '/::assert_locked\(\s*\$/', $source ), $analysis['assert_calls'], "$file: every assert_locked() call is the fence's" );
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
		foreach ( array( "namespace WPCheckpoint\\Restore;\n" => 'JobFence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Restore\\JobFence;\n" => 'JobFence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Restore\\JobFence as Fence;\n" => 'Fence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Restore\\{JobFence, Ledger};\n" => 'JobFence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Restore\\{ Ledger, JobFence as Fence };\n" => 'Fence', "namespace WPCheckpoint\\Jobs;\nuse WPCheckpoint\\Support\\Deleter, WPCheckpoint\\Restore\\JobFence as Fence;\n" => 'Fence', "namespace WPCheckpoint\\Jobs;\n" => '\\WPCheckpoint\\Restore\\JobFence', "namespace WPCheckpoint;\n" => 'Restore\\JobFence', '' => '\\WPCheckpoint\\Restore\\JobFence' ) as $header => $class ) {
			$spelt = "<?php\n{$header}{$class}::within( \$db, \$f, function () use ( \$db ) {\n\t{$class}::assert_locked( \$db );\n\t\$db->run( 'DROP TABLE t' );\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n} );\nfunction f( \$db ) {\n\t{$class}::assert_locked( \$db );\n\t\$db->run( 'COMMIT' );\n}\n";
			$shift = substr_count( $header, "\n" );
			$this->assertSame( array( array( 2 + $shift, 6 + $shift ), array( 7 + $shift, 10 + $shift ) ), self::fenced_regions( $spelt )['regions'], $class );
			$this->assertSame( array( 'x.php:' . ( 4 + $shift ) . ': ' . self::NOT_ALLOWED, 'x.php:' . ( 9 + $shift ) . ': ' . self::NOT_ALLOWED ), self::problems( 'x.php', $spelt, array( array( "'DROP TABLE t'", self::EXCEPTION, 'r' ), $locked ) ), $class );
		}
		foreach ( array( "namespace WPCheckpoint\\Jobs;\n" => 'JobFence', "namespace WPCheckpoint\\Restore;\nuse WPCheckpoint\\Support\\Deleter as JobFence;\n" => 'JobFence', "namespace WPCheckpoint\\Restore;\n" => 'Deleter', "namespace WPCheckpoint\\Restore;\n" => 'self', "namespace WPCheckpoint\\Jobs;\nuse function WPCheckpoint\\Restore\\JobFence;\n" => 'JobFence' ) as $header => $class ) {
			$other = "<?php\n{$header}if ( {$class}::within( \$a, \$b ) ) {\n\t\$db->write( 'UPDATE t SET a = 1', array() );\n}\nfunction f( \$db ) {\n\t{$class}::assert_locked( \$db );\n\t\$db->run( 'COMMIT' );\n}\n";
			$this->assertSame( array( 'regions' => array(), 'problems' => array() ), self::fenced_regions( $other ), "$class is not the fence here" );
			$this->assertStringContainsString( 'never runs a fenced transaction', implode( "\n", self::problems( 'x.php', $other, array( $locked, array( "'COMMIT'", self::EXCEPTION, 'r' ) ) ) ), "$class is not the fence here" );
		}
		// An import after the call, a namespace after it, a trait's use: not the fence where the call is, as PHP
		// resolves it.
		$late = "<?php\nnamespace WPCheckpoint\\Jobs;\nJobFence::within( \$db, \$f, function () use ( \$db ) {\n\t\$db->run( 'DROP TABLE t' );\n} );\nuse WPCheckpoint\\Restore\\JobFence;\nnamespace WPCheckpoint\\Restore;\nclass X {\n\tuse JobFence;\n\tpublic function f( \$db ) {\n\t\tJobFence::assert_locked( \$db );\n\t\t\$db->run( 'COMMIT' );\n\t}\n}\n";
		$this->assertSame( array( 'regions' => array( array( 10, 13 ) ), 'problems' => array() ), self::fenced_regions( $late ), 'the closure before the import is not fenced; the method in the fence\'s namespace is, by the namespace, not the trait' );
		$this->assertSame( array( 'x.php:12: ' . self::NOT_ALLOWED ), self::problems( 'x.php', $late, array( array( "'DROP TABLE t'", self::EXCEPTION, 'r' ) ) ), 'the COMMIT is no write to register, but is refused in the region' );
		// The receiver however it is reached: $wpdb split from its arrow, $this->wpdb, $GLOBALS['wpdb'] are
		// $wpdb; another object's update() or delete() is not a statement; two writes in one statement are each
		// registered by their own text; escapes and heredocs are read.
		$reached = $within . "\t\$wpdb\n\t\t->query( 'DELETE FROM t' );\n\t\$this->wpdb->query( 'DELETE FROM t2' );\n\t\$GLOBALS['wpdb']->update( 't3', array() );\n\t\$hashes->update( \$piece );\n\t\$this->cache->delete( 'k' );\n\t\$x = \$db->write( 'UPDATE a', array() ) ?: \$db->write( 'UPDATE b', array() );\n\t\$db->write( \"\\n UPDATE c SET a = 1\", array() );\n\t\$db->rows( <<<SQL\n\t\tSELECT 1\n\tSQL );\n\t\$db->rows( <<<'SQL'\n\t\t/* why */ show tables\n\tSQL );\n} );\n";
		$this->assertSame( array( 6, 7, 8, 11, 11, 12 ), array_column( self::writes( $reached ), 'line' ) );
		$this->assertSame( "\$db->write( 'UPDATE a', array() )", self::writes( $reached )[3]['text'], 'bounded at its parenthesis: the second write is not in it' );
		$this->assertSame( array(), self::problems( 'x.php', $reached, array( array( "'DELETE FROM t'", self::LOCKED ), array( "'DELETE FROM t2'", self::LOCKED ), array( "\$GLOBALS['wpdb']->update(", self::LOCKED ), array( "'UPDATE a'", self::LOCKED ), array( "'UPDATE b'", self::LOCKED ), array( 'UPDATE c', self::LOCKED ) ) ) );
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
		$seen    = array();
		// How the file spells the class: the same namespace, imported, aliased, fully qualified; and what else
		// the file has: another class's within() and assert_locked(), which are not the fence.
		$spelling = $pick( array( 'same', 'import', 'alias', 'qualified', 'group', 'group_alias' ) );
		$seen[ "spelt_$spelling" ] = 1;
		$fence  = array( 'same' => 'JobFence', 'import' => 'JobFence', 'alias' => 'Fence', 'qualified' => '\\WPCheckpoint\\Restore\\JobFence', 'group' => 'JobFence', 'group_alias' => 'Fence' )[ $spelling ];
		$lines  = array( '<?php', 'same' === $spelling ? 'namespace WPCheckpoint\\Restore;' : 'namespace WPCheckpoint\\Jobs;' );
		if ( 'import' === $spelling ) {
			$lines[] = 'use WPCheckpoint\\Restore\\JobFence;';
		} elseif ( 'alias' === $spelling ) {
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
		$statement = static function () use ( $pick, $cases, $allowed, $ending, $leads, &$seen ): array {
			$kind = $pick( array( 'literal', 'literal', 'literal', 'multiline', 'opaque', 'filler', 'comment', 'two_calls', 'nested_call', 'nested_lines', 'other_within', 'trailing_comment', 'escaped', 'heredoc', 'split' ) );
			if ( 'filler' === $kind ) {
				$seen['filler'] = 1;
				return array( 'lines' => array( $pick( array( '$x = 1;', "\$y = array( '{', '}' );", '$z = "a {$x} b";', "\$fn = fn ( \$o = array( 'a' => 1 ) ) => \$o;", '$hashes->update( $piece );', "\$this->cache->delete( 'k' );", "\$list->insert( 0, 'a' );" ) ) ), 'allowed' => true, 'statement' => false );
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
			$seen[ $ok ? 'allowed' : 'ending' ] = 1;
			if ( '' !== trim( $lead ) ) {
				$seen[ $ok ? 'allowed_after_comment' : 'ending_after_comment' ] = 1;
			}
			$quoted   = false === strpos( $text, "'" ) ? "'$text'" : "\"$text\"";
			$receiver = $pick( array( '$db', '$db', '$this->db', '$wpdb', '$this->wpdb', "\$GLOBALS['wpdb']" ) );
			$seen[ 'receiver_' . preg_replace( '/[^a-z]/', '', strtolower( $receiver ) ) ] = 1;
			$call     = $pick( array( "{$receiver}->run( $quoted );", "{$receiver}->write( $quoted, array() );", "{$receiver}->rows( $quoted );", "{$receiver}->query( $quoted );" ) );
			if ( 'escaped' === $kind ) { // A double-quoted literal whose escape comes before the keyword.
				$seen['escaped'] = 1;
				return array( 'lines' => array( "\$db->write( \"\\n\\t " . str_replace( '"', '\\"', $text ) . "\", array() );" ), 'allowed' => $ok, 'statement' => true );
			}
			if ( 'heredoc' === $kind ) {
				$seen['heredoc'] = 1;
				$marker = $pick( array( 'SQL', "'SQL'" ) );
				return array( 'lines' => array( "\$db->rows( <<<$marker", "\t$text", 'SQL );' ), 'allowed' => $ok, 'statement' => true );
			}
			if ( 'split' === $kind ) { // The receiver on a line of its own: the call is on the arrow's line.
				$seen['split'] = 1;
				return array( 'lines' => array( $receiver, "\t->query( $quoted );" ), 'allowed' => $ok, 'statement' => true, 'call_line' => 1 );
			}
			if ( 'trailing_comment' === $kind ) {
				$seen['trailing_comment'] = 1;
				return array( 'lines' => array( "$call // \$db->rows( \$q ) read" ), 'allowed' => $ok, 'statement' => true );
			}
			if ( 'two_calls' === $kind || 'nested_call' === $kind || 'nested_lines' === $kind ) { // Several calls in one statement: the other one allowed, or not.
				$other_ok = 1 === mt_rand( 0, 1 );
				$other    = $other_ok ? "\$db->rows( 'SELECT 1' )" : $pick( array( '$db->rows( $q )', "\$db->run( 'FLUSH TABLES' )" ) );
				$seen[ $kind . ( $ok && $other_ok ? '_allowed' : '_refused' ) ] = 1;
				if ( 'nested_lines' === $kind ) { // The nested call on a line of its own: judged there.
					return array( 'lines' => array( '$db->write(', "	$quoted,", "	array( $other )", ');' ), 'allowed' => $ok, 'statement' => true, 'nested' => array( 2, $other_ok ) );
				}
				$line = 'two_calls' === $kind ? "$call $other;" : "\$db->write( $quoted, array( $other ) );";
				return array( 'lines' => array( $line ), 'allowed' => $ok && $other_ok, 'statement' => true );
			}
			if ( 'multiline' === $kind ) {
				$seen['multiline'] = 1;
				$lines = array( '$db->write(', '	// Why: $db->run( $sql ).', "	$quoted," );
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
						$text    = $pick( array( "\$db->run( 'SELECT 1' );", "\$db->run( 'DROP TABLE t' );", '$db->run( $sql );' ) );
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
				$line = count( $lines ) + 1 + ( $s['call_line'] ?? 0 );
				foreach ( $s['lines'] as $text ) {
					$lines[] = $indent . $text;
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
		sort( $regions );
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
	public function test_generated_sources_report_exactly_the_calls_outside_the_invariant( int $seed ): void {
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
		$this->assertSame( $made['not_allowed'], $reported, "calls outside the invariant of:\n" . $made['source'] );
		$this->assertSame( $made['not_closure'], $closure, "within() calls without a closure of:\n" . $made['source'] );
	}

	public function test_the_generator_covered_every_situation(): void {
		$seen = array();
		for ( $seed = 1; $seed <= self::SOURCES; $seed++ ) {
			$seen = array_merge( $seen, self::generate( $seed )['seen'] );
		}
		foreach ( array( 'closure', 'static', 'arrow', 'variable', 'asserting', 'plain', 'top', 'filler', 'comment', 'opaque', 'allowed', 'ending', 'allowed_after_comment', 'ending_after_comment', 'multiline', 'violation_in_closure', 'violation_in_static', 'violation_in_asserting', 'spelt_same', 'spelt_import', 'spelt_alias', 'spelt_qualified', 'other_within', 'trailing_comment', 'two_calls_allowed', 'two_calls_refused', 'nested_call_allowed', 'nested_call_refused', 'nested_lines_allowed', 'nested_lines_refused', 'nested_asserting_in_fenced', 'nested_asserting_outside', 'nested_plain_in_fenced', 'nested_plain_outside', 'nested_one_line', 'spelt_group', 'spelt_group_alias', 'receiver_db', 'receiver_thisdb', 'receiver_wpdb', 'receiver_thiswpdb', 'receiver_globalswpdb', 'escaped', 'heredoc', 'split', 'split_in_fenced' ) as $situation ) {
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
