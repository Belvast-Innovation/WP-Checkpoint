<?php
/**
 * How far the import of each table has come, recorded with the rows themselves.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\LockLost;

defined( 'ABSPATH' ) || exit;

/**
 * An InnoDB table of the restore (TempTables::ledger(), reclaimed with the
 * job's other temporary tables), one row per imported table: the chunk and
 * the byte offset of the next statement to run (chunk 0: claimed, the
 * table not created yet), the rows inserted so far, the offset in the
 * first chunk where the rows begin (after CREATE TABLE), whether the
 * table's engine has transactions, how many times it was imported again
 * from the start, the names its constraints were given, and the holder:
 * the lease token of the run that works on it.
 *
 * For a table with transactions, the rows of a batch of statements and the
 * row's new position are committed in one transaction, so there is no state
 * in which rows were written and the position not (or the reverse); the
 * position is the truth about what is in the table, whatever the job's
 * cursor says. A tick that dies mid-batch leaves neither.
 *
 * A run claims a table before it touches it (claim(): the holder changed
 * from the one it read to its own token), and every later write of that
 * row requires the holder to be still its own: a run whose lease expired
 * but is still going (the engine's fence protects the job's row, not the
 * temporary tables) cannot record anything after a newer run claimed the
 * table, not its CREATE TABLE (created() moves chunk 0 to 1 under its own
 * token only), not a batch (the batch's transaction is rolled back), not a
 * restart. It stops with LockLost.
 *
 * A table without transactions (MyISAM, Aria, MEMORY...) keeps its rows
 * whatever happens after them. Its position moves after each statement and
 * records the rows inserted; a run that finds the table's row count
 * different from the ledger's knows a statement ran without being recorded,
 * empties the table (TRUNCATE, keeping the definition) and imports its rows
 * again from the first chunk (restart()).
 */
final class Ledger {

	/**
	 * Connection.
	 *
	 * @var ImportSession
	 */
	private $db;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private $name;

	/**
	 * This run's lease token.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Constructor: creates the table when it is not there.
	 *
	 * @param ImportSession $db    Connection.
	 * @param string        $name  Table name (TempTables::ledger()).
	 * @param string        $token This run's lease token.
	 */
	public function __construct( ImportSession $db, string $name, string $token ) {
		$this->db    = $db;
		$this->name  = $name;
		$this->token = $token;
		$db->run( 'CREATE TABLE IF NOT EXISTS ' . SqlWriter::identifier( $name ) . " (n INT UNSIGNED NOT NULL PRIMARY KEY, chunk INT UNSIGNED NOT NULL, pos BIGINT UNSIGNED NOT NULL, row_count BIGINT UNSIGNED NOT NULL, data_offset BIGINT UNSIGNED NOT NULL, transactional TINYINT NOT NULL, restarts INT UNSIGNED NOT NULL DEFAULT 0, holder VARCHAR(64) NOT NULL DEFAULT '', constraint_names MEDIUMTEXT NULL) ENGINE=InnoDB" );
	}

	/**
	 * A table's record, or null before any run claimed it.
	 *
	 * @param int $number The table's number.
	 * @return array{chunk: int, pos: int, rows: int, data_offset: int, transactional: bool, restarts: int, holder: string, constraints: string}|null
	 */
	public function get( int $number ) {
		$rows = $this->db->rows( 'SELECT chunk, pos, row_count, data_offset, transactional, restarts, holder, constraint_names FROM ' . SqlWriter::identifier( $this->name ) . ' WHERE n = ?', array( (string) $number ) );
		if ( array() === $rows ) {
			return null;
		}
		return array(
			'chunk'         => (int) $rows[0][0],
			'pos'           => (int) $rows[0][1],
			'rows'          => (int) $rows[0][2],
			'data_offset'   => (int) $rows[0][3],
			'transactional' => '1' === (string) $rows[0][4],
			'restarts'      => (int) $rows[0][5],
			'holder'        => (string) $rows[0][6],
			'constraints'   => (string) $rows[0][7],
		);
	}

	/**
	 * Make this run the table's holder; its record as it is then.
	 *
	 * @param int $number The table's number.
	 * @return array{chunk: int, pos: int, rows: int, data_offset: int, transactional: bool, restarts: int, holder: string, constraints: string}
	 * @throws LockLost When another run claimed it in the meantime.
	 */
	public function claim( int $number ): array {
		$state = $this->get( $number );
		if ( null === $state ) {
			try {
				$this->db->rows(
					'INSERT INTO ' . SqlWriter::identifier( $this->name ) . ' (n, chunk, pos, row_count, data_offset, transactional, holder) VALUES (?, 0, 0, 0, 0, 1, ?)',
					array( (string) $number, $this->token )
				);
			} catch ( StatementFailed $e ) {
				if ( 1062 !== $e->getCode() ) {
					throw $e;
				}
				throw new LockLost( 'Another run of this restore claimed the table first.' );
			}
		} elseif ( $state['holder'] !== $this->token ) {
			$changed = $this->db->run(
				sprintf(
					"UPDATE %s SET holder = '%s' WHERE n = %d AND holder = '%s'",
					SqlWriter::identifier( $this->name ),
					self::token_text( $this->token ),
					$number,
					self::token_text( $state['holder'] )
				)
			);
			if ( 1 !== $changed ) {
				throw new LockLost( 'Another run of this restore claimed the table first.' );
			}
		}
		$claimed = $this->get( $number );
		if ( null === $claimed || $claimed['holder'] !== $this->token ) {
			throw new LockLost( 'Another run of this restore claimed the table first.' );
		}
		return $claimed;
	}

	/**
	 * Record a table just created: its rows begin at $offset of chunk 1.
	 *
	 * @param int    $number        The table's number.
	 * @param int    $offset        Offset after CREATE TABLE.
	 * @param bool   $transactional Whether its engine has transactions.
	 * @param string $constraints   The names its constraints were given (JSON).
	 * @return void
	 * @throws LockLost When this run is no longer the table's holder.
	 */
	public function created( int $number, int $offset, bool $transactional, string $constraints ): void {
		$this->db->rows(
			'UPDATE ' . SqlWriter::identifier( $this->name ) . ' SET chunk = 1, pos = ?, data_offset = ?, transactional = ?, constraint_names = ? WHERE n = ? AND chunk = 0 AND holder = ?',
			array( (string) $offset, (string) $offset, $transactional ? '1' : '0', $constraints, (string) $number, $this->token )
		);
		$now = $this->get( $number );
		if ( null === $now || 1 !== $now['chunk'] || $offset !== $now['pos'] || $now['holder'] !== $this->token ) {
			throw new LockLost( 'Another run of this restore claimed the table; this one stops.' );
		}
	}

	/**
	 * Move a table's position, in the caller's transaction.
	 *
	 * @param int $number   The table's number.
	 * @param int $chunk    Chunk read before.
	 * @param int $pos      Offset read before.
	 * @param int $to_chunk New chunk.
	 * @param int $to_pos   New offset.
	 * @param int $rows     Rows inserted since.
	 * @return void
	 * @throws LockLost When the position is not the one read before, or this run is no longer the holder.
	 */
	public function advance( int $number, int $chunk, int $pos, int $to_chunk, int $to_pos, int $rows ): void {
		$changed = $this->db->run(
			sprintf(
				"UPDATE %s SET chunk = %d, pos = %d, row_count = row_count + %d WHERE n = %d AND chunk = %d AND pos = %d AND holder = '%s'",
				SqlWriter::identifier( $this->name ),
				$to_chunk,
				$to_pos,
				$rows,
				$number,
				$chunk,
				$pos,
				self::token_text( $this->token )
			)
		);
		if ( 1 !== $changed ) {
			throw new LockLost( 'Another run of this restore moved the import on; this one stops.' );
		}
	}

	/**
	 * Start a table without transactions over: its rows from the first chunk again.
	 *
	 * @param int $number The table's number.
	 * @param int $chunk  Chunk read before.
	 * @param int $pos    Offset read before.
	 * @return void
	 * @throws LockLost When the position is not the one read before, or this run is no longer the holder.
	 */
	public function restart( int $number, int $chunk, int $pos ): void {
		$changed = $this->db->run(
			sprintf(
				"UPDATE %s SET chunk = 1, pos = data_offset, row_count = 0, restarts = restarts + 1 WHERE n = %d AND chunk = %d AND pos = %d AND holder = '%s'",
				SqlWriter::identifier( $this->name ),
				$number,
				$chunk,
				$pos,
				self::token_text( $this->token )
			)
		);
		if ( 1 !== $changed ) {
			throw new LockLost( 'Another run of this restore moved the import on; this one stops.' );
		}
	}

	/**
	 * A lease token for SQL text: hex only, or nothing.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function token_text( string $token ): string {
		return 1 === preg_match( '/\A[0-9a-zA-Z]{0,64}\z/', $token ) ? $token : '';
	}
}
