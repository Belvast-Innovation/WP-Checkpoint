<?php
/**
 * A backup's CREATE TABLE statement: what it defines, and the same statement for the temporary table.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages are fixed text with names from the archive; the importer adds the table and chunk.

/**
 * Reads the statement as SHOW CREATE TABLE writes it (MySQL 5.7 to 8.4,
 * MariaDB 10.x to 12) from SqlLexer's tokens, and refuses anything else:
 *
 * - the head is CREATE TABLE and the name of the chunk's table (not
 *   TEMPORARY, not IF NOT EXISTS, not CREATE ... SELECT or LIKE);
 * - each item of the definition is a column, PRIMARY KEY, UNIQUE, KEY,
 *   INDEX, FULLTEXT, SPATIAL, CHECK or a named constraint (a foreign key,
 *   a CHECK, a unique or primary key); a foreign key must have a name
 *   (SHOW CREATE TABLE always writes one), reference a table of this
 *   database (no "db"."table"), and a column must not carry an inline
 *   REFERENCES (MariaDB would make it a foreign key the rules below never
 *   saw);
 * - the table options name an engine from ENGINES (MERGE, FEDERATED,
 *   CONNECT, SPIDER and the like reach other tables, files or servers) and
 *   none of DATA DIRECTORY, INDEX DIRECTORY, CONNECTION, UNION, SELECT, LIKE.
 *
 * What it does not judge: expressions in DEFAULT, generated columns and
 * CHECK constraints are the site's own definition and are evaluated by the
 * server on this table's rows only (the servers refuse subqueries and, for
 * generated columns, non-deterministic functions there); the INSERT
 * statements list every stored column, so no DEFAULT is evaluated while
 * importing. Whether the types, character sets and engine exist here is
 * the server's to say when the statement runs on the temporary table.
 *
 * The content of versioned comments ("/*!80023 INVISIBLE *\/") is read as
 * SQL, because the server executes it.
 *
 * rewrite() replaces, by byte position, the table's name, the name of each
 * foreign key and CHECK constraint (ConstraintNames), and the referenced
 * table of each foreign key whose target is restored too (it references
 * that table's temporary name; a target outside the restore keeps its
 * name). Every other byte is kept.
 */
final class CreateTable {

	/**
	 * Engines a restored table may use (lowercase): none of them reads or writes outside the table itself.
	 */
	const ENGINES = array( 'innodb', 'myisam', 'aria', 'memory', 'heap', 'archive', 'csv', 'blackhole', 'rocksdb', 'tokudb' );

	/**
	 * Words that never belong in the table options: they point the table at other tables, files or servers, or make it a copy of something.
	 */
	const REFUSED_OPTIONS = array( 'DIRECTORY', 'CONNECTION', 'UNION', 'SELECT', 'LIKE', 'AS', 'IGNORE', 'REPLACE' );

	/**
	 * Words that start an index item.
	 */
	const INDEX_WORDS = array( 'KEY', 'INDEX', 'UNIQUE', 'FULLTEXT', 'SPATIAL' );

	/**
	 * The statement (without the semicolon).
	 *
	 * @var string
	 */
	private $sql;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Byte range of the table's name in $sql.
	 *
	 * @var array{0: int, 1: int}
	 */
	private $table_span;

	/**
	 * Columns in order: name => whether generated.
	 *
	 * @var array<string, bool>
	 */
	private $columns = array();

	/**
	 * Primary key columns.
	 *
	 * @var string[]
	 */
	private $primary = array();

	/**
	 * Foreign keys.
	 *
	 * @var array<int, array{name: string, name_span: array{0: int, 1: int}, columns: string[], references: string, references_span: array{0: int, 1: int}, referenced_columns: string[]}>
	 */
	private $foreign = array();

	/**
	 * Named CHECK constraints.
	 *
	 * @var array<int, array{name: string, name_span: array{0: int, 1: int}}>
	 */
	private $checks = array();

	/**
	 * Engine as written (empty when the statement names none).
	 *
	 * @var string
	 */
	private $engine = '';

	/**
	 * Read a CREATE TABLE statement.
	 *
	 * @param string                                                  $buffer Buffer holding the statement.
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $tokens SqlLexer::tokens() of it, the last one "end".
	 * @param string                                                  $table  The chunk's table: the statement must create this one.
	 * @return self
	 * @throws Refused When the statement is not one the restore runs.
	 */
	public static function read( string $buffer, array $tokens, string $table ): self {
		$start = $tokens[0][1];
		$end   = $tokens[ count( $tokens ) - 1 ][1];
		$self  = new self();

		$self->sql = substr( $buffer, $start, $end - $start );
		// Positions relative to the statement, without the versioned-comment markers (their content is SQL).
		$list = array();
		foreach ( $tokens as $token ) {
			if ( 'vopen' !== $token[0] && 'vclose' !== $token[0] && 'end' !== $token[0] ) {
				$list[] = array( $token[0], $token[1] - $start, $token[2] - $start, $token[3] );
			}
		}
		if ( count( $list ) < 5 || ! self::is_word( $list[0], 'CREATE' ) || ! self::is_word( $list[1], 'TABLE' ) ) {
			throw new Refused( 'The statement is not CREATE TABLE followed by the table\'s name.' );
		}
		if ( 'id' !== $list[2][0] || $list[2][3] !== $table ) {
			throw new Refused( 'CREATE TABLE names another table.' );
		}
		$self->table      = $table;
		$self->table_span = array( $list[2][1], $list[2][2] );
		if ( 'p' !== $list[3][0] || '(' !== $list[3][3] ) {
			throw new Refused( 'CREATE TABLE is not followed by a column list.' );
		}
		$depth = 1;
		$item  = array();
		$i     = 4;
		for ( $count = count( $list ); $i < $count; $i++ ) {
			$token = $list[ $i ];
			if ( 'p' === $token[0] && '(' === $token[3] ) {
				++$depth;
			} elseif ( 'p' === $token[0] && ')' === $token[3] ) {
				--$depth;
				if ( 0 === $depth ) {
					$self->item( $item );
					break;
				}
			} elseif ( 'p' === $token[0] && ',' === $token[3] && 1 === $depth ) {
				$self->item( $item );
				$item = array();
				continue;
			}
			$item[] = $token;
		}
		if ( 0 !== $depth ) {
			throw new Refused( 'The column list of CREATE TABLE is not closed.' );
		}
		$self->options( array_slice( $list, $i + 1 ) );
		if ( array() === $self->columns ) {
			throw new Refused( 'CREATE TABLE defines no columns.' );
		}
		return $self;
	}

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return $this->table;
	}

	/**
	 * The columns an INSERT must list: every column that is not generated, in order.
	 *
	 * @return string[]
	 */
	public function stored_columns(): array {
		return array_keys(
			array_filter(
				$this->columns,
				static function ( bool $generated ): bool {
					return ! $generated;
				}
			)
		);
	}

	/**
	 * Every column in order, generated ones included.
	 *
	 * @return string[]
	 */
	public function columns(): array {
		return array_keys( $this->columns );
	}

	/**
	 * Primary key columns (empty when the table has none).
	 *
	 * @return string[]
	 */
	public function primary_key(): array {
		return $this->primary;
	}

	/**
	 * Foreign keys: name, columns, referenced table and columns.
	 *
	 * @return array<int, array{name: string, columns: string[], references: string, referenced_columns: string[]}>
	 */
	public function foreign_keys(): array {
		$out = array();
		foreach ( $this->foreign as $key ) {
			$out[] = array(
				'name'               => $key['name'],
				'columns'            => $key['columns'],
				'references'         => $key['references'],
				'referenced_columns' => $key['referenced_columns'],
			);
		}
		return $out;
	}

	/**
	 * Names of the named CHECK constraints.
	 *
	 * @return string[]
	 */
	public function check_names(): array {
		return array_column( $this->checks, 'name' );
	}

	/**
	 * Engine as written, or '' when none is named.
	 *
	 * @return string
	 */
	public function engine(): string {
		return $this->engine;
	}

	/**
	 * The statement for the temporary table.
	 *
	 * @param string                $temporary  The table's temporary name.
	 * @param string                $final_name  The table's final name.
	 * @param int                   $number     The table's position in the backup (0-based).
	 * @param ConstraintNames       $names      Constraint names.
	 * @param array<string, string> $restored   Temporary name of every table the restore creates, by its name in the backup.
	 * @return array{sql: string, constraints: array<int, array{kind: string, name: string, intended: string, shortened: bool}>}
	 */
	public function rewrite( string $temporary, string $final_name, int $number, ConstraintNames $names, array $restored ): array {
		$replace     = array( array( $this->table_span, $temporary ) );
		$constraints = array();
		foreach ( $this->foreign as $key ) {
			$chosen        = $names->choose( $key['name'], 'ibfk', $this->table, $temporary, $final_name, $number );
			$replace[]     = array( $key['name_span'], $chosen['name'] );
			$constraints[] = array( 'kind' => 'foreign' ) + $chosen;
			if ( isset( $restored[ $key['references'] ] ) ) {
				$replace[] = array( $key['references_span'], $restored[ $key['references'] ] );
			}
		}
		foreach ( $this->checks as $check ) {
			$chosen        = $names->choose( $check['name'], 'chk', $this->table, $temporary, $final_name, $number );
			$replace[]     = array( $check['name_span'], $chosen['name'] );
			$constraints[] = array( 'kind' => 'check' ) + $chosen;
		}
		usort(
			$replace,
			static function ( array $a, array $b ): int {
				return $b[0][0] <=> $a[0][0];
			}
		);
		$sql = $this->sql;
		foreach ( $replace as $piece ) {
			$sql = substr_replace( $sql, SqlWriter::identifier( $piece[1] ), $piece[0][0], $piece[0][1] - $piece[0][0] );
		}
		return array(
			'sql'         => $sql,
			'constraints' => $constraints,
		);
	}

	/**
	 * Read one item of the definition.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $item Tokens.
	 * @return void
	 * @throws Refused When the item is not one the restore runs.
	 */
	private function item( array $item ): void {
		if ( array() === $item ) {
			throw new Refused( 'CREATE TABLE has an empty item.' );
		}
		$first = $item[0];
		if ( 'id' === $first[0] ) {
			$this->column( $item );
			return;
		}
		if ( 'word' !== $first[0] ) {
			throw new Refused( 'CREATE TABLE has an item that is neither a column nor a key.' );
		}
		$word = strtoupper( $first[3] );
		if ( 'PRIMARY' === $word ) {
			$this->primary_key_item( $item, 0 );
			return;
		}
		if ( in_array( $word, self::INDEX_WORDS, true ) ) {
			return;
		}
		if ( 'CHECK' === $word ) {
			return; // Unnamed: the server names it for this table.
		}
		if ( 'FOREIGN' === $word ) {
			throw new Refused( 'CREATE TABLE has a foreign key without a name.' );
		}
		if ( 'CONSTRAINT' !== $word ) {
			throw new Refused( 'CREATE TABLE has an item of a kind the restore does not create (' . $word . ').' );
		}
		$at   = 1;
		$name = null;
		if ( isset( $item[1] ) && 'id' === $item[1][0] ) {
			$name = $item[1];
			$at   = 2;
		}
		$kind = isset( $item[ $at ] ) && 'word' === $item[ $at ][0] ? strtoupper( $item[ $at ][3] ) : '';
		if ( 'FOREIGN' === $kind ) {
			if ( null === $name ) {
				throw new Refused( 'CREATE TABLE has a foreign key without a name.' );
			}
			$this->foreign_key( $item, $at, $name );
			return;
		}
		if ( 'CHECK' === $kind ) {
			if ( null !== $name ) {
				$this->checks[] = array(
					'name'      => $name[3],
					'name_span' => array( $name[1], $name[2] ),
				);
			}
			return;
		}
		if ( 'PRIMARY' === $kind ) {
			$this->primary_key_item( $item, $at );
			return;
		}
		if ( 'UNIQUE' === $kind ) {
			return;
		}
		throw new Refused( 'CREATE TABLE has a constraint of a kind the restore does not create.' );
	}

	/**
	 * Read a column definition.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $item Tokens.
	 * @return void
	 * @throws Refused When the column carries a reference or repeats a name.
	 */
	private function column( array $item ): void {
		$name = $item[0][3];
		if ( isset( $this->columns[ $name ] ) ) {
			throw new Refused( 'CREATE TABLE defines a column twice.' );
		}
		$generated = false;
		$depth     = 0;
		foreach ( $item as $i => $token ) {
			if ( 'p' === $token[0] && '(' === $token[3] ) {
				++$depth;
			} elseif ( 'p' === $token[0] && ')' === $token[3] ) {
				--$depth;
			} elseif ( 0 === $depth && 'word' === $token[0] ) {
				$word = strtoupper( $token[3] );
				if ( 'REFERENCES' === $word ) {
					throw new Refused( 'CREATE TABLE has a column with an inline REFERENCES.' );
				}
				if ( 'AS' === $word || 'GENERATED' === $word ) {
					$generated = true;
				}
				if ( 'CONSTRAINT' === $word && isset( $item[ $i + 1 ] ) && 'id' === $item[ $i + 1 ][0] ) {
					$this->checks[] = array(
						'name'      => $item[ $i + 1 ][3],
						'name_span' => array( $item[ $i + 1 ][1], $item[ $i + 1 ][2] ),
					);
				}
			}
		}
		$this->columns[ $name ] = $generated;
	}

	/**
	 * Read PRIMARY KEY (...) from $at.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $item Tokens.
	 * @param int                                                     $at   Position of PRIMARY.
	 * @return void
	 * @throws Refused When it is not PRIMARY KEY with a column list, or a second one.
	 */
	private function primary_key_item( array $item, int $at ): void {
		if ( ! isset( $item[ $at + 1 ] ) || ! self::is_word( $item[ $at + 1 ], 'KEY' ) ) {
			throw new Refused( 'CREATE TABLE has PRIMARY without KEY.' );
		}
		if ( array() !== $this->primary ) {
			throw new Refused( 'CREATE TABLE has two primary keys.' );
		}
		$open = $at + 2;
		while ( isset( $item[ $open ] ) && ! ( 'p' === $item[ $open ][0] && '(' === $item[ $open ][3] ) ) {
			++$open; // USING BTREE and the like.
		}
		list( $columns ) = self::name_list( $item, $open );
		if ( array() === $columns ) {
			throw new Refused( 'CREATE TABLE has a primary key without columns.' );
		}
		$this->primary = $columns;
	}

	/**
	 * Read FOREIGN KEY [name] (columns) REFERENCES table (columns) ... from $at.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $item Tokens.
	 * @param int                                                     $at   Position of FOREIGN.
	 * @param array{0: string, 1: int, 2: int, 3: string}             $name The constraint's name token.
	 * @return void
	 * @throws Refused When the shape is not that, or the reference names another database.
	 */
	private function foreign_key( array $item, int $at, array $name ): void {
		if ( ! isset( $item[ $at + 1 ] ) || ! self::is_word( $item[ $at + 1 ], 'KEY' ) ) {
			throw new Refused( 'CREATE TABLE has FOREIGN without KEY.' );
		}
		$open = $at + 2;
		if ( isset( $item[ $open ] ) && 'id' === $item[ $open ][0] ) {
			++$open; // The index name.
		}
		list( $columns, $next ) = self::name_list( $item, $open );
		if ( ! isset( $item[ $next ] ) || ! self::is_word( $item[ $next ], 'REFERENCES' ) || ! isset( $item[ $next + 1 ] ) || 'id' !== $item[ $next + 1 ][0] ) {
			throw new Refused( 'CREATE TABLE has a foreign key without REFERENCES and a table.' );
		}
		$target = $item[ $next + 1 ];
		if ( isset( $item[ $next + 2 ] ) && 'p' === $item[ $next + 2 ][0] && '.' === $item[ $next + 2 ][3] ) {
			throw new Refused( 'CREATE TABLE has a foreign key to a table in another database.' );
		}
		list( $referenced ) = self::name_list( $item, $next + 2 );
		if ( array() === $columns || count( $columns ) !== count( $referenced ) ) {
			throw new Refused( 'CREATE TABLE has a foreign key whose column lists do not match.' );
		}
		$this->foreign[] = array(
			'name'               => $name[3],
			'name_span'          => array( $name[1], $name[2] ),
			'columns'            => $columns,
			'references'         => $target[3],
			'references_span'    => array( $target[1], $target[2] ),
			'referenced_columns' => $referenced,
		);
	}

	/**
	 * Read the table options (and partitioning) after the definition.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $options Tokens.
	 * @return void
	 * @throws Refused When an option points the table elsewhere or names an engine outside ENGINES.
	 */
	private function options( array $options ): void {
		foreach ( $options as $i => $token ) {
			if ( 'word' !== $token[0] ) {
				continue;
			}
			$word = strtoupper( $token[3] );
			if ( in_array( $word, self::REFUSED_OPTIONS, true ) ) {
				throw new Refused( 'CREATE TABLE has the table option ' . $word . ', which the restore does not run.' );
			}
			if ( 'ENGINE' !== $word && 'TYPE' !== $word ) {
				continue;
			}
			$value = $options[ $i + 1 ] ?? null;
			if ( null !== $value && 'p' === $value[0] && '=' === $value[3] ) {
				$value = $options[ $i + 2 ] ?? null;
			}
			if ( null === $value || 'word' !== $value[0] || ! in_array( strtolower( $value[3] ), self::ENGINES, true ) ) {
				throw new Refused( 'CREATE TABLE names an engine the restore does not create tables with' . ( null !== $value ? ' (' . $value[3] . ')' : '' ) . '.' );
			}
			if ( '' === $this->engine ) {
				$this->engine = $value[3];
			}
		}
	}

	/**
	 * Read "(`a`, `b`(10), ...)" starting at $open: the names, and the position after the closing parenthesis.
	 *
	 * @param array<int, array{0: string, 1: int, 2: int, 3: string}> $item Tokens.
	 * @param int                                                     $open Position of "(".
	 * @return array{0: string[], 1: int}
	 * @throws Refused When there is no list there.
	 */
	private static function name_list( array $item, int $open ): array {
		if ( ! isset( $item[ $open ] ) || 'p' !== $item[ $open ][0] || '(' !== $item[ $open ][3] ) {
			throw new Refused( 'CREATE TABLE has a key without a column list.' );
		}
		$names = array();
		$depth = 0;
		for ( $i = $open, $count = count( $item ); $i < $count; $i++ ) {
			$token = $item[ $i ];
			if ( 'p' === $token[0] && '(' === $token[3] ) {
				++$depth;
			} elseif ( 'p' === $token[0] && ')' === $token[3] ) {
				--$depth;
				if ( 0 === $depth ) {
					return array( $names, $i + 1 );
				}
			} elseif ( 1 === $depth && 'id' === $token[0] ) {
				$names[] = $token[3];
			}
		}
		throw new Refused( 'CREATE TABLE has a column list that is not closed.' );
	}

	/**
	 * Whether a token is this word (any case).
	 *
	 * @param array{0: string, 1: int, 2: int, 3: string} $token Token.
	 * @param string                                      $word  Uppercase word.
	 * @return bool
	 */
	private static function is_word( array $token, string $word ): bool {
		return 'word' === $token[0] && strtoupper( $token[3] ) === $word;
	}
}
