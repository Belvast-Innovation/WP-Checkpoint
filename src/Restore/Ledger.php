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
 * the byte offset of the next statement to run, the rows inserted so far,
 * the offset in the first chunk where the rows begin (after CREATE TABLE),
 * and whether the table's engine has transactions.
 *
 * For a table with transactions, the rows of a batch of statements and the
 * row's new position are committed in one transaction, so there is no state
 * in which rows were written and the position not (or the reverse); the
 * position is the truth about what is in the table, whatever the job's
 * cursor says. A tick that dies mid-batch leaves neither.
 *
 * Every move is a compare-and-set from the position read before: a run
 * whose lease has expired but is still going (the engine's fence protects
 * the job's row, not the temporary tables) finds the position moved by the
 * new holder, rolls its batch back and stops with LockLost.
 *
 * A table without transactions (MyISAM, Aria, MEMORY...) keeps its rows
 * whatever happens after them. Its position moves after each statement and
 * records the rows inserted; a run that finds the table's row count
 * different from the ledger's knows a statement ran without being recorded,
 * empties the table (TRUNCATE, keeping the definition) and imports its rows
 * again from the first chunk.
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
	 * Constructor: creates the table when it is not there.
	 *
	 * @param ImportSession $db   Connection.
	 * @param string        $name Table name (TempTables::ledger()).
	 */
	public function __construct( ImportSession $db, string $name ) {
		$this->db   = $db;
		$this->name = $name;
		$db->run( 'CREATE TABLE IF NOT EXISTS ' . SqlWriter::identifier( $name ) . ' (n INT UNSIGNED NOT NULL PRIMARY KEY, chunk INT UNSIGNED NOT NULL, pos BIGINT UNSIGNED NOT NULL, row_count BIGINT UNSIGNED NOT NULL, data_offset BIGINT UNSIGNED NOT NULL, transactional TINYINT NOT NULL) ENGINE=InnoDB' );
	}

	/**
	 * A table's position, or null before its CREATE TABLE ran.
	 *
	 * @param int $number The table's number.
	 * @return array{chunk: int, pos: int, rows: int, data_offset: int, transactional: bool}|null
	 */
	public function get( int $number ) {
		$rows = $this->db->rows( 'SELECT chunk, pos, row_count, data_offset, transactional FROM ' . SqlWriter::identifier( $this->name ) . ' WHERE n = ?', array( (string) $number ) );
		if ( array() === $rows ) {
			return null;
		}
		return array(
			'chunk'         => (int) $rows[0][0],
			'pos'           => (int) $rows[0][1],
			'rows'          => (int) $rows[0][2],
			'data_offset'   => (int) $rows[0][3],
			'transactional' => '1' === (string) $rows[0][4],
		);
	}

	/**
	 * Record a table just created: its rows begin at $offset of chunk 1.
	 *
	 * @param int  $number        The table's number.
	 * @param int  $offset        Offset after CREATE TABLE.
	 * @param bool $transactional Whether its engine has transactions.
	 * @return void
	 * @throws LockLost When another run recorded it meanwhile.
	 */
	public function created( int $number, int $offset, bool $transactional ): void {
		try {
			$this->db->rows(
				'INSERT INTO ' . SqlWriter::identifier( $this->name ) . ' (n, chunk, pos, row_count, data_offset, transactional) VALUES (?, 1, ?, 0, ?, ?)',
				array( (string) $number, (string) $offset, (string) $offset, $transactional ? '1' : '0' )
			);
		} catch ( StatementFailed $e ) {
			if ( 1062 === $e->getCode() ) {
				throw new LockLost( 'Another run of this restore recorded the table first.' );
			}
			throw $e;
		}
	}

	/**
	 * Move a table's position, in the caller's transaction.
	 *
	 * @param int $number The table's number.
	 * @param int $chunk  Chunk read before.
	 * @param int $pos    Offset read before.
	 * @param int $to_chunk New chunk.
	 * @param int $to_pos   New offset.
	 * @param int $rows     Rows inserted since.
	 * @return void
	 * @throws LockLost When the position is not the one read before (another run moved it).
	 */
	public function advance( int $number, int $chunk, int $pos, int $to_chunk, int $to_pos, int $rows ): void {
		$changed = $this->db->run(
			sprintf(
				'UPDATE %s SET chunk = %d, pos = %d, row_count = row_count + %d WHERE n = %d AND chunk = %d AND pos = %d',
				SqlWriter::identifier( $this->name ),
				$to_chunk,
				$to_pos,
				$rows,
				$number,
				$chunk,
				$pos
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
	 * @throws LockLost When the position is not the one read before.
	 */
	public function restart( int $number, int $chunk, int $pos ): void {
		$changed = $this->db->run(
			sprintf(
				'UPDATE %s SET chunk = 1, pos = data_offset, row_count = 0 WHERE n = %d AND chunk = %d AND pos = %d',
				SqlWriter::identifier( $this->name ),
				$number,
				$chunk,
				$pos
			)
		);
		if ( 1 === $changed ) {
			return;
		}
		$now = $this->get( $number );
		// Nothing changed because it already is at the start (a statement ran unrecorded before the first was recorded).
		if ( null === $now || 1 !== $now['chunk'] || $now['pos'] !== $now['data_offset'] || 0 !== $now['rows'] || $pos !== $now['pos'] ) {
			throw new LockLost( 'Another run of this restore moved the import on; this one stops.' );
		}
	}
}
