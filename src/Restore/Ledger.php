<?php
/**
 * How far the import of each table has come, recorded with the rows themselves.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;

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
 * step of starting over. It stops with ClaimLost, and so does the run that
 * lost the table in the other order (see ClaimLost).
 *
 * What the ledger cannot stop is a statement such a run sends before it
 * writes here: a DROP, a TRUNCATE, an INSERT into a table without
 * transactions. The import step confirms the job's lease right before each
 * claim, DROP and TRUNCATE, which leaves only the time of one statement,
 * and counts the rows of a table without transactions against the ledger's
 * at its end.
 *
 * A table without transactions (MyISAM, Aria, MEMORY...) keeps its rows
 * whatever happens after them. Its position moves after each statement and
 * records the rows inserted; a run that finds the table's row count
 * different from the ledger's knows a statement ran without being recorded,
 * and imports the table's rows again from the first chunk. Starting over
 * takes several writes, so it is marked first (mark_restarting()): the
 * caller empties the table (TRUNCATE, keeping the definition), reset()
 * moves the position back, the job's position goes back to the table's
 * first chunk, and restarted() removes the mark there. A run that reads
 * the mark does all of it again from the start, whichever step a stopped
 * run had reached; each of them can be repeated.
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
		$db->run( 'CREATE TABLE IF NOT EXISTS ' . SqlWriter::identifier( $name ) . " (n INT UNSIGNED NOT NULL PRIMARY KEY, chunk INT UNSIGNED NOT NULL, pos BIGINT UNSIGNED NOT NULL, row_count BIGINT UNSIGNED NOT NULL, data_offset BIGINT UNSIGNED NOT NULL, transactional TINYINT NOT NULL, restarts INT UNSIGNED NOT NULL DEFAULT 0, restarting TINYINT NOT NULL DEFAULT 0, holder VARCHAR(64) NOT NULL DEFAULT '', constraint_names MEDIUMTEXT NULL) ENGINE=InnoDB" );
	}

	/**
	 * A table's record, or null before any run claimed it.
	 *
	 * @param int $number The table's number.
	 * @return array{chunk: int, pos: int, rows: int, data_offset: int, transactional: bool, restarts: int, holder: string, constraints: string, restarting: bool}|null
	 */
	public function get( int $number ) {
		$rows = $this->db->rows( 'SELECT chunk, pos, row_count, data_offset, transactional, restarts, holder, constraint_names, restarting FROM ' . SqlWriter::identifier( $this->name ) . ' WHERE n = ?', array( (string) $number ) );
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
			'restarting'    => '1' === (string) $rows[0][8],
		);
	}

	/**
	 * Make this run the table's holder; its record as it is then.
	 *
	 * @param int $number The table's number.
	 * @return array{chunk: int, pos: int, rows: int, data_offset: int, transactional: bool, restarts: int, holder: string, constraints: string, restarting: bool}
	 * @throws ClaimLost When another run claimed it in the meantime.
	 */
	public function claim( int $number ): array {
		$state = $this->get( $number );
		if ( null === $state ) {
			try {
				$this->db->write(
					'INSERT INTO ' . SqlWriter::identifier( $this->name ) . ' (n, chunk, pos, row_count, data_offset, transactional, holder) VALUES (?, 0, 0, 0, 0, 1, ?)',
					array( (string) $number, $this->token )
				);
			} catch ( StatementFailed $e ) {
				if ( 1062 !== $e->getCode() ) {
					throw $e;
				}
				throw new ClaimLost( 'Another run of this restore claimed the table first.' );
			}
		} elseif ( $state['holder'] !== $this->token ) {
			$changed = $this->db->write(
				'UPDATE ' . SqlWriter::identifier( $this->name ) . ' SET holder = ? WHERE n = ? AND holder = ?',
				array( $this->token, (string) $number, $state['holder'] )
			);
			if ( 1 !== $changed ) {
				throw new ClaimLost( 'Another run of this restore claimed the table first.' );
			}
		}
		$claimed = $this->get( $number );
		if ( null === $claimed || $claimed['holder'] !== $this->token ) {
			throw new ClaimLost( 'Another run of this restore claimed the table first.' );
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
	 * @throws ClaimLost When this run is no longer the table's holder.
	 */
	public function created( int $number, int $offset, bool $transactional, string $constraints ): void {
		$this->db->write(
			'UPDATE ' . SqlWriter::identifier( $this->name ) . ' SET chunk = 1, pos = ?, data_offset = ?, transactional = ?, constraint_names = ? WHERE n = ? AND chunk = 0 AND holder = ?',
			array( (string) $offset, (string) $offset, $transactional ? '1' : '0', $constraints, (string) $number, $this->token )
		);
		$now = $this->get( $number );
		if ( null === $now || 1 !== $now['chunk'] || $offset !== $now['pos'] || $now['holder'] !== $this->token ) {
			throw new ClaimLost( 'Another run of this restore claimed the table; this one stops.' );
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
	 * @throws ClaimLost When the position is not the one read before, or this run is no longer the holder.
	 */
	public function advance( int $number, int $chunk, int $pos, int $to_chunk, int $to_pos, int $rows ): void {
		$changed = $this->db->write(
			'UPDATE ' . SqlWriter::identifier( $this->name ) . ' SET chunk = ?, pos = ?, row_count = row_count + ? WHERE n = ? AND chunk = ? AND pos = ? AND holder = ?',
			array( (string) $to_chunk, (string) $to_pos, (string) $rows, (string) $number, (string) $chunk, (string) $pos, $this->token )
		);
		if ( 1 !== $changed ) {
			throw new ClaimLost( 'Another run of this restore moved the import on; this one stops.' );
		}
	}

	/**
	 * Start a table without transactions over, first step: mark it as being
	 * imported again (and count the time). Nothing is removed yet. Marking a
	 * table already marked changes nothing, not the count either.
	 *
	 * @param int $number The table's number.
	 * @return void
	 * @throws ClaimLost When this run is no longer the table's holder.
	 */
	public function mark_restarting( int $number ): void {
		$this->db->write(
			'UPDATE ' . SqlWriter::identifier( $this->name ) . ' SET restarting = 1, restarts = restarts + 1 WHERE n = ? AND restarting = 0 AND holder = ?',
			array( (string) $number, $this->token )
		);
		$now = $this->get( $number );
		if ( null === $now || ! $now['restarting'] || $now['holder'] !== $this->token ) {
			throw new ClaimLost( 'Another run of this restore claimed the table; this one stops.' );
		}
	}

	/**
	 * A marked table, emptied: its position back at the start of its rows in
	 * the first chunk, no rows inserted. The mark stays. The same again
	 * changes nothing.
	 *
	 * @param int $number The table's number.
	 * @return void
	 * @throws ClaimLost When the table is not marked, or this run is no longer its holder.
	 */
	public function reset( int $number ): void {
		$this->db->write(
			'UPDATE ' . SqlWriter::identifier( $this->name ) . ' SET chunk = 1, pos = data_offset, row_count = 0 WHERE n = ? AND restarting = 1 AND holder = ?',
			array( (string) $number, $this->token )
		);
		$now = $this->get( $number );
		if ( null === $now || ! $now['restarting'] || 1 !== $now['chunk'] || $now['data_offset'] !== $now['pos'] || 0 !== $now['rows'] || $now['holder'] !== $this->token ) {
			throw new ClaimLost( 'Another run of this restore claimed the table; this one stops.' );
		}
	}

	/**
	 * The last step of starting over: the job's position is on the table's
	 * first chunk again (the caller's chunk is the evidence), so the mark goes.
	 *
	 * @param int $number The table's number.
	 * @return void
	 * @throws ClaimLost When the table is not reset, or this run is no longer its holder.
	 */
	public function restarted( int $number ): void {
		$this->db->write(
			'UPDATE ' . SqlWriter::identifier( $this->name ) . ' SET restarting = 0 WHERE n = ? AND restarting = 1 AND chunk = 1 AND pos = data_offset AND row_count = 0 AND holder = ?',
			array( (string) $number, $this->token )
		);
		$now = $this->get( $number );
		if ( null === $now || $now['restarting'] || $now['holder'] !== $this->token ) {
			throw new ClaimLost( 'Another run of this restore claimed the table; this one stops.' );
		}
	}
}
