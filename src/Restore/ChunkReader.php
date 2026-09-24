<?php
/**
 * Reads a database chunk one statement at a time, judging each one as it is read.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Archive\EnvironmentFailure;
use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Database\TableExporter;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are fixed text with offsets and names from the archive.
// phpcs:disable WordPress.WP.AlternativeFunctions -- a chunk file in the job's work directory, read in pieces.

/**
 * The only way the importer reads SQL: next() returns the next statement
 * already judged and rewritten for the temporary table, and the caller runs
 * it before asking for the one after. There is no list of statements and no
 * separate splitting step: what decides where a statement ends is the
 * grammar of the kind it is, so the part that reads and the part that judges
 * cannot disagree about which bytes form a statement.
 *
 * Between statements only spaces and "-- " comment lines are allowed (the
 * chunk's header, bound, batch and end lines). A statement is one of:
 *
 * - the session preamble, exactly as the exporter writes it:
 *   "/*!40101 SET NAMES <charset> *\/", "/*!40101 SET SQL_MODE=
 *   'NO_AUTO_VALUE_ON_ZERO' *\/", "/*!40014 SET FOREIGN_KEY_CHECKS=0 *\/";
 * - "DROP TABLE IF EXISTS" and the table's name (run on the temporary name);
 * - "CREATE TABLE" of the table, read by CreateTable, which also decides its
 *   end: the first semicolon outside strings, quoted names and comments;
 * - "INSERT INTO" the table with a column list and VALUES, whose rows hold
 *   literals only: NULL, a number, a quoted string, X'hex'. The rows are
 *   read by that grammar, one value at a time, so a semicolon or a quote
 *   inside a string (escaped with a backslash or doubled) is part of the
 *   string, a row may span lines, and the statement ends at the semicolon
 *   after the last row. Every row must have one value per listed column,
 *   and the list must be the table's stored columns.
 *
 * Anything else (another kind of statement, another table, a function call
 * or a subquery among the values, a comment inside a statement) is refused
 * before it runs, with the table, chunk and byte offset. Whatever the
 * reader decides, the executor runs each through mysqli_query(), which
 * sends the text as one statement (ImportSession): whatever the server
 * makes of it, it is one statement, never a second one. What the reader
 * and the server could read differently inside a statement (the character
 * sets below) is refused.
 *
 * The preamble comes before every other statement of a chunk; a reader
 * opened past the chunk's start refuses it (the importer runs a chunk's
 * preamble again from the head when it resumes).
 *
 * A statement is read into memory whole (it is sent whole), so its size is
 * bounded by MAX_STATEMENT_BYTES: the exporter cuts INSERTs at about 1 MB,
 * or one row when a row is larger, and a row is at most
 * TableExporter::MAX_ROW_BYTES.
 */
final class ChunkReader {

	/**
	 * Bytes read from the file at a time.
	 */
	const READ_BYTES = 1048576;

	/**
	 * Largest statement: one row at the exporter's limit, plus room for the head (a column list of up to 4096 quoted names).
	 */
	const MAX_STATEMENT_BYTES = TableExporter::MAX_ROW_BYTES + 1048576;

	/**
	 * The preamble statements, as the exporter writes them.
	 */
	const PREAMBLE = array(
		'/^\/\*!40101 SET NAMES [A-Za-z0-9_]+ \*\/\z/',
		'/^\/\*!40101 SET SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\' \*\/\z/',
		'/^\/\*!40014 SET FOREIGN_KEY_CHECKS=0 \*\/\z/',
	);

	/**
	 * Open file.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * File offset of the buffer's first byte.
	 *
	 * @var int
	 */
	private $offset;

	/**
	 * Bytes read and not yet consumed.
	 *
	 * @var string
	 */
	private $buffer = '';

	/**
	 * Whether the buffer holds the rest of the file.
	 *
	 * @var bool
	 */
	private $eof = false;

	/**
	 * The table.
	 *
	 * @var ImportTarget
	 */
	private $target;

	/**
	 * Chunk number (for messages).
	 *
	 * @var int
	 */
	private $chunk;

	/**
	 * Bytes read at a time.
	 *
	 * @var int
	 */
	private $read_bytes;

	/**
	 * Whether an INSERT is read only up to VALUES (the preflight's look at a chunk's head).
	 *
	 * @var bool
	 */
	private $heads_only;

	/**
	 * Whether a statement other than the preamble has been read (or the reader started past the head).
	 *
	 * @var bool
	 */
	private $past_preamble;

	/**
	 * The session's character set as the statements are read ('' when unknown: the site's own).
	 *
	 * @var string
	 */
	private $charset;

	/**
	 * Open a chunk file at an offset (0, or the end of a statement returned earlier).
	 *
	 * @param string       $path   Chunk file.
	 * @param int          $offset Offset.
	 * @param ImportTarget $target The table.
	 * @param int          $chunk  Chunk number.
	 * @param int          $read_bytes Bytes read at a time (tests use tiny reads to put every boundary everywhere).
	 * @param bool         $heads_only Read each INSERT only up to VALUES and return it with no SQL (to be looked at, never run).
	 * @param string       $charset    The session's character set where the reader starts (a resumed chunk: the one its preamble set).
	 * @throws EnvironmentFailure When the file cannot be opened or positioned.
	 */
	public function __construct( string $path, int $offset, ImportTarget $target, int $chunk, int $read_bytes = self::READ_BYTES, bool $heads_only = false, string $charset = '' ) {
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
		if ( false === $handle ) {
			throw new EnvironmentFailure( 'A database chunk extracted for the restore cannot be opened.' );
		}
		if ( 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			throw new EnvironmentFailure( 'A database chunk extracted for the restore cannot be positioned for reading.' );
		}
		$this->handle = $handle;
		$this->offset = $offset;
		$this->target = $target;
		$this->chunk  = $chunk;

		$this->read_bytes    = max( 1, $read_bytes );
		$this->heads_only    = $heads_only;
		$this->past_preamble = $offset > 0;
		$this->charset       = strtolower( $charset );
	}

	/**
	 * The session's character set after the statements read so far ('' when none was set).
	 *
	 * @return string
	 */
	public function charset(): string {
		return $this->charset;
	}

	/**
	 * Close the file.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	/**
	 * The file offset up to which statements (and the space and comments after them) have been read.
	 *
	 * @return int
	 */
	public function offset(): int {
		return $this->offset;
	}

	/**
	 * The next statement, or null when only space and comments are left.
	 *
	 * @return Statement|null
	 * @throws Refused When the next statement is not one the restore runs.
	 * @throws EnvironmentFailure When the file cannot be read.
	 */
	public function next() {
		while ( true ) {
			try {
				$start = SqlLexer::between( $this->buffer, 0, $this->eof );
				if ( $start >= strlen( $this->buffer ) ) {
					$this->consume( strlen( $this->buffer ) );
					if ( $this->eof ) {
						return null;
					}
					$this->fill();
					continue;
				}
				$this->consume( $start );
				$statement = $this->statement();
				if ( $statement->end > self::MAX_STATEMENT_BYTES ) {
					throw new Refused( sprintf( 'A statement is larger than %d bytes, more than the restore reads at once.', self::MAX_STATEMENT_BYTES ) );
				}
				if ( Statement::SET === $statement->kind && $this->past_preamble ) {
					throw new Refused( 'The session preamble after other statements.' );
				}
				$this->past_preamble = $this->past_preamble || Statement::SET !== $statement->kind;
				if ( Statement::SET === $statement->kind && 1 === preg_match( '/SET NAMES ([A-Za-z0-9_]+)/', $statement->sql, $names ) ) {
					$this->charset      = strtolower( $names[1] );
					$statement->charset = $this->charset;
				}
				$this->consume( $statement->end );
				$statement->end = $this->offset;
				return $statement;
			} catch ( NeedMoreBytes $e ) {
				if ( $this->eof ) {
					throw $this->refused( 'The chunk ends inside a statement.' );
				}
				if ( strlen( $this->buffer ) >= self::MAX_STATEMENT_BYTES ) {
					throw $this->refused( sprintf( 'A statement is larger than %d bytes, more than the restore reads at once.', self::MAX_STATEMENT_BYTES ) );
				}
				$this->fill();
			} catch ( Refused $e ) {
				throw $this->refused( $e->getMessage() );
			}
		}
	}

	/**
	 * Read the statement at the start of the buffer.
	 *
	 * @return Statement Its end is the buffer position after the semicolon (next() makes it a file offset).
	 * @throws NeedMoreBytes When the buffer ends first.
	 * @throws Refused When it is not a statement the restore runs.
	 */
	private function statement(): Statement {
		$buffer = $this->buffer;
		if ( strlen( $buffer ) < 3 && ! $this->eof ) {
			throw new NeedMoreBytes(); // Not enough to tell "/*!" from anything else.
		}
		if ( 0 === strncmp( $buffer, '/*!', 3 ) ) {
			return $this->preamble();
		}
		$word = strspn( $buffer, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz' );
		if ( $word >= strlen( $buffer ) ) {
			throw new NeedMoreBytes();
		}
		$head = strtoupper( substr( $buffer, 0, $word ) );
		if ( 'INSERT' === $head ) {
			return $this->insert();
		}
		if ( 'CREATE' === $head ) {
			$tokens = SqlLexer::tokens( $buffer, 0, $this->eof );
			$this->single_byte_view( $tokens );
			$create = CreateTable::read( $buffer, $tokens, $this->target->table );
			$sql    = $create->rewrite( $this->target->temporary, $this->target->final_name, $this->target->number, $this->target->names, $this->target->reference );

			$statement              = new Statement( Statement::CREATE, $sql['sql'], $tokens[ count( $tokens ) - 1 ][2] );
			$statement->create      = $create;
			$statement->columns     = $create->stored_columns();
			$statement->constraints = $sql['constraints'];
			return $statement;
		}
		if ( 'DROP' === $head ) {
			$at = $this->words( $buffer, 0, array( 'DROP', 'TABLE', 'IF', 'EXISTS' ) );
			if ( ! isset( $buffer[ $at ] ) ) {
				throw new NeedMoreBytes();
			}
			if ( '`' !== $buffer[ $at ] ) {
				throw new Refused( 'DROP TABLE without a quoted table name.' );
			}
			list( $name, $at ) = SqlLexer::identifier( $buffer, $at );
			$this->plain_name( $name );
			if ( $name !== $this->target->table ) {
				throw new Refused( 'DROP TABLE names another table.' );
			}
			$at = SqlLexer::spaces( $buffer, $at );
			if ( ! isset( $buffer[ $at ] ) ) {
				throw new NeedMoreBytes();
			}
			if ( ';' !== $buffer[ $at ] ) {
				throw new Refused( 'DROP TABLE drops more than the table.' );
			}
			return new Statement( Statement::DROP, 'DROP TABLE IF EXISTS ' . SqlWriter::identifier( $this->target->temporary ), $at + 1 );
		}
		throw new Refused( 'A statement of a kind the restore does not run' . ( $word > 0 && $word <= 20 ? ' (' . $head . ')' : '' ) . '.' );
	}

	/**
	 * Read one of the preamble statements.
	 *
	 * @return Statement
	 * @throws NeedMoreBytes When the buffer ends first.
	 * @throws Refused When it is not exactly one of PREAMBLE.
	 */
	private function preamble(): Statement {
		$close = strpos( $this->buffer, '*/', 3 );
		if ( false === $close || ! isset( $this->buffer[ $close + 2 ] ) ) {
			if ( strlen( $this->buffer ) < 256 ) {
				throw new NeedMoreBytes();
			}
			throw new Refused( 'A versioned comment that is not the session preamble.' );
		}
		$text = substr( $this->buffer, 0, $close + 2 );
		if ( ';' !== $this->buffer[ $close + 2 ] ) {
			throw new Refused( 'A versioned comment that is not the session preamble.' );
		}
		foreach ( self::PREAMBLE as $pattern ) {
			if ( 1 === preg_match( $pattern, $text ) ) {
				return new Statement( Statement::SET, $text, $close + 3 );
			}
		}
		throw new Refused( 'A versioned comment that is not the session preamble.' );
	}

	/**
	 * Read an INSERT statement: head, column list, rows of literals.
	 *
	 * @return Statement
	 * @throws NeedMoreBytes When the buffer ends first.
	 * @throws Refused When it is not an INSERT of literal rows into the table with its columns.
	 */
	private function insert(): Statement {
		$buffer = $this->buffer;
		$at     = $this->words( $buffer, 0, array( 'INSERT', 'INTO' ) );
		if ( ! isset( $buffer[ $at ] ) ) {
			throw new NeedMoreBytes();
		}
		if ( '`' !== $buffer[ $at ] ) {
			throw new Refused( 'INSERT without a quoted table name.' );
		}
		list( $name, $at ) = SqlLexer::identifier( $buffer, $at );
		$this->plain_name( $name );
		if ( $name !== $this->target->table ) {
			throw new Refused( 'INSERT into another table.' );
		}
		$rest    = $at;
		$columns = array();
		$at      = $this->expect( $buffer, SqlLexer::spaces( $buffer, $at ), '(', 'INSERT without a column list.' );
		while ( true ) {
			$at = SqlLexer::spaces( $buffer, $at );
			if ( ! isset( $buffer[ $at ] ) ) {
				throw new NeedMoreBytes();
			}
			if ( '`' !== $buffer[ $at ] ) {
				throw new Refused( 'INSERT with a column list that is not quoted names.' );
			}
			list( $column, $at ) = SqlLexer::identifier( $buffer, $at );
			$this->plain_name( $column );
			$columns[] = $column;
			$at        = SqlLexer::spaces( $buffer, $at );
			if ( ! isset( $buffer[ $at ] ) ) {
				throw new NeedMoreBytes();
			}
			if ( ',' === $buffer[ $at ] ) {
				++$at;
				continue;
			}
			$at = $this->expect( $buffer, $at, ')', 'INSERT with a column list that is not quoted names.' );
			break;
		}
		if ( null !== $this->target->columns && $columns !== $this->target->columns ) {
			throw new Refused( 'INSERT lists other columns than the table defines (' . implode( ', ', $columns ) . ').' );
		}
		$at = $this->words( $buffer, SqlLexer::spaces( $buffer, $at ), array( 'VALUES' ) );
		if ( $this->heads_only ) {
			$statement          = new Statement( Statement::INSERT, '', $at );
			$statement->columns = $columns;
			return $statement;
		}
		$rows = 0;
		$want = count( $columns );
		while ( true ) {
			$at    = $this->expect( $buffer, SqlLexer::spaces( $buffer, $at ), '(', 'INSERT with something other than a row of values.' );
			$count = 0;
			while ( true ) {
				$at = $this->value( $buffer, SqlLexer::spaces( $buffer, $at ) );
				++$count;
				$at = SqlLexer::spaces( $buffer, $at );
				if ( ! isset( $buffer[ $at ] ) ) {
					throw new NeedMoreBytes();
				}
				if ( ',' === $buffer[ $at ] ) {
					++$at;
					continue;
				}
				$at = $this->expect( $buffer, $at, ')', 'INSERT with something other than literal values.' );
				break;
			}
			if ( $count !== $want ) {
				throw new Refused( sprintf( 'INSERT with a row of %d values for %d columns.', $count, $want ) );
			}
			++$rows;
			$at = SqlLexer::spaces( $buffer, $at );
			if ( ! isset( $buffer[ $at ] ) ) {
				throw new NeedMoreBytes();
			}
			if ( ',' === $buffer[ $at ] ) {
				++$at;
				continue;
			}
			if ( ';' !== $buffer[ $at ] ) {
				throw new Refused( 'INSERT with something after its rows.' );
			}
			break;
		}
		$statement          = new Statement( Statement::INSERT, 'INSERT INTO ' . SqlWriter::identifier( $this->target->temporary ) . substr( $buffer, $rest, $at - $rest ), $at + 1 );
		$statement->columns = $columns;
		$statement->rows    = $rows;
		return $statement;
	}

	/**
	 * Read one literal: NULL, a number, a quoted string, X'hex'.
	 *
	 * @param string $buffer Buffer.
	 * @param int    $at     Position.
	 * @return int Position after it.
	 * @throws NeedMoreBytes When the buffer ends first.
	 * @throws Refused When there is no literal there.
	 */
	private function value( string $buffer, int $at ): int {
		if ( ! isset( $buffer[ $at ] ) ) {
			throw new NeedMoreBytes();
		}
		$byte = $buffer[ $at ];
		if ( '\'' === $byte ) {
			if ( $this->multibyte_session() ) {
				throw new Refused( sprintf( 'A quoted string under the character set %s, where the backup writes every string as hexadecimal.', $this->charset ) );
			}
			return SqlLexer::quoted( $buffer, $at );
		}
		if ( 'X' === $byte || 'x' === $byte ) {
			if ( ! isset( $buffer[ $at + 1 ] ) ) {
				throw new NeedMoreBytes();
			}
			if ( '\'' !== $buffer[ $at + 1 ] ) {
				throw new Refused( 'INSERT with something other than literal values.' );
			}
			$end = $at + 2 + strspn( $buffer, '0123456789abcdefABCDEF', $at + 2 );
			if ( ! isset( $buffer[ $end ] ) ) {
				throw new NeedMoreBytes();
			}
			if ( '\'' !== $buffer[ $end ] ) {
				throw new Refused( 'INSERT with a hexadecimal value that is not hexadecimal.' );
			}
			return $end + 1;
		}
		if ( 'N' === $byte ) {
			if ( strlen( $buffer ) < $at + 4 ) {
				throw new NeedMoreBytes();
			}
			if ( 'NULL' !== substr( $buffer, $at, 4 ) ) {
				throw new Refused( 'INSERT with something other than literal values.' );
			}
			return $at + 4;
		}
		$length = strspn( $buffer, '-+.0123456789eE', $at );
		if ( $at + $length >= strlen( $buffer ) ) {
			throw new NeedMoreBytes();
		}
		if ( 0 === $length || 1 !== preg_match( '/\A-?(?:\d+|\d*\.\d+)(?:[eE][-+]?\d+)?\z/', substr( $buffer, $at, $length ) ) ) {
			throw new Refused( 'INSERT with something other than literal values.' );
		}
		return $at + $length;
	}

	/**
	 * Whether the session's character set has multibyte characters whose
	 * second byte can be a backslash or a backtick (gbk, big5, sjis and the
	 * like): there the server may read a byte this reader takes for an
	 * escape or a closing backtick as part of a character, and the two
	 * would disagree about where a string or a name ends. The exporter
	 * writes every string as hexadecimal under such a character set
	 * (SqlWriter), so a statement read under one may hold no quoted string
	 * in its rows, no backslash in any string, and no byte from 0x80 in a
	 * name.
	 *
	 * @return bool
	 */
	private function multibyte_session(): bool {
		return '' !== $this->charset && ! SqlWriter::backslash_safe( $this->charset );
	}

	/**
	 * Refuse a name with a byte from 0x80 under such a character set.
	 *
	 * @param string $name Name.
	 * @return void
	 * @throws Refused When it has one.
	 */
	private function plain_name( string $name ): void {
		if ( $this->multibyte_session() && 1 === preg_match( '/[\x80-\xFF]/', $name ) ) {
			throw new Refused( sprintf( 'A name with a non-ASCII byte under the character set %s, which this reader and the server could read differently.', $this->charset ) );
		}
	}

	/**
	 * Refuse, under such a character set, a CREATE TABLE whose strings hold a backslash or whose names or words a byte from 0x80.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $tokens Tokens.
	 * @return void
	 * @throws Refused When one does.
	 */
	private function single_byte_view( array $tokens ): void {
		if ( ! $this->multibyte_session() ) {
			return;
		}
		foreach ( $tokens as $token ) {
			if ( 'str' === $token[0] && false !== strpos( $token[3], '\\' ) ) {
				throw new Refused( sprintf( 'A backslash in a string under the character set %s, which this reader and the server could read differently.', $this->charset ) );
			}
			if ( 'id' === $token[0] || 'word' === $token[0] ) {
				$this->plain_name( $token[3] );
			}
		}
	}

	/**
	 * Read words separated by spaces (the exporter's uppercase), then spaces.
	 *
	 * @param string   $buffer Buffer.
	 * @param int      $at     Position.
	 * @param string[] $words  Words.
	 * @return int Position after the spaces that follow the last word.
	 * @throws NeedMoreBytes When the buffer ends first.
	 * @throws Refused When the words are not there.
	 */
	private function words( string $buffer, int $at, array $words ): int {
		foreach ( $words as $word ) {
			$length = strlen( $word );
			if ( strlen( $buffer ) < $at + $length + 1 ) {
				throw new NeedMoreBytes();
			}
			if ( substr( $buffer, $at, $length ) !== $word ) {
				throw new Refused( 'A statement that does not read "' . implode( ' ', $words ) . '".' );
			}
			$next = SqlLexer::spaces( $buffer, $at + $length );
			if ( $next === $at + $length ) {
				throw new Refused( 'A statement that does not read "' . implode( ' ', $words ) . '".' );
			}
			if ( $next >= strlen( $buffer ) ) {
				throw new NeedMoreBytes();
			}
			$at = $next;
		}
		return $at;
	}

	/**
	 * Read one byte that must be there.
	 *
	 * @param string $buffer  Buffer.
	 * @param int    $at      Position.
	 * @param string $byte    The byte.
	 * @param string $message Refusal when it is another.
	 * @return int Position after it.
	 * @throws NeedMoreBytes When the buffer ends first.
	 * @throws Refused When another byte is there.
	 */
	private function expect( string $buffer, int $at, string $byte, string $message ): int {
		if ( ! isset( $buffer[ $at ] ) ) {
			throw new NeedMoreBytes();
		}
		if ( $byte !== $buffer[ $at ] ) {
			throw new Refused( $message );
		}
		return $at + 1;
	}

	/**
	 * Read more of the file into the buffer: at least the read size, and as
	 * much as the buffer already holds, so a statement that needs several
	 * reads is started again only a logarithmic number of times (each
	 * attempt parses it from its beginning).
	 *
	 * @return void
	 * @throws EnvironmentFailure When the file cannot be read.
	 */
	private function fill(): void {
		$length = max( $this->read_bytes, strlen( $this->buffer ) );
		$length = max( 1, min( $length, self::MAX_STATEMENT_BYTES + 1 - strlen( $this->buffer ) ) ); // One byte past the limit is enough to know.
		$more   = fread( $this->handle, $length );
		if ( false === $more ) {
			throw new EnvironmentFailure( 'A database chunk extracted for the restore cannot be read.' );
		}
		$this->buffer .= $more;
		if ( '' === $more || feof( $this->handle ) ) {
			$this->eof = true;
		}
	}

	/**
	 * Drop bytes from the front of the buffer.
	 *
	 * @param int $length Bytes.
	 * @return void
	 */
	private function consume( int $length ): void {
		if ( $length <= 0 ) {
			return;
		}
		$this->buffer  = (string) substr( $this->buffer, $length );
		$this->offset += $length;
	}

	/**
	 * A refusal with the table, the chunk and the offset.
	 *
	 * @param string $message Message.
	 * @return Refused
	 */
	private function refused( string $message ): Refused {
		return new Refused( sprintf( 'Table %s, chunk %d, byte %d: %s', $this->target->table, $this->chunk, $this->offset, $message ) );
	}
}
