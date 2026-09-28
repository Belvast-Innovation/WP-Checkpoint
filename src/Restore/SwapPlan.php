<?php
/**
 * The swap's plan: what the swap renames, one row per entry, kept in a table of its own.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\TransientFailure;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors; the presenter cleans them.

/**
 * The final check before the swap (Jobs\SwapCheckStep) writes the plan and
 * the swap reads it (and its rollback, which must not depend on the
 * storage directory, so the plan is in the database). A row is a directory
 * unit (DIR: the live path, the staged path, where the live one goes), a
 * table of the backup (TABLE: its final, temporary and old names) or a live
 * table the backup does not have (MOVE: its name and where it goes), each
 * with whether the live one was there; COMPLETE closes the plan and holds
 * how many entries it has.
 *
 * Rows belong to one attempt of the job: the check takes a new attempt
 * number each time it runs from its start, removes the rows of earlier
 * attempts (a bounded number at a time) and writes the entries in batches
 * by their sequence number; the swap reads the attempt it is told, and only
 * once its COMPLETE row is there and the count matches. A batch written
 * again after an interruption first removes what the attempt holds from
 * its first sequence number on, so no entry is there twice (the unique key
 * would refuse it anyway). Names and paths are stored as bytes (binary
 * columns): what was written is what is read, whatever the connection's
 * character set.
 *
 * Created by the schema's migration (Support\Schema, version 8) and, where
 * it is used, created again when missing and its columns read back: a
 * restore finds out here, not in a later write, that the table is not what
 * this code needs, and nothing else waits on it.
 */
final class SwapPlan {

	const TABLE = 'wpcheckpoint_swap_plan';

	const DIR      = 'dir';
	const TABLE_OF = 'table';
	const MOVE     = 'move';
	const COMPLETE = 'complete';

	/**
	 * Columns: name => definition (the one definition, used to create the table and to check it).
	 */
	const COLUMNS = array(
		'id'       => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
		'job_id'   => 'BIGINT UNSIGNED NOT NULL',
		'attempt'  => 'INT UNSIGNED NOT NULL',
		'seq'      => 'INT UNSIGNED NOT NULL',
		'kind'     => 'VARCHAR(16) NOT NULL',
		'live'     => 'BLOB NOT NULL',
		'stage'    => 'BLOB NOT NULL',
		'old'      => 'BLOB NOT NULL',
		'had_live' => 'TINYINT NOT NULL DEFAULT 0',
	);

	/**
	 * Rows removed per statement when earlier attempts are cleared.
	 */
	const DELETE_ROWS = 500;

	/**
	 * Connection.
	 *
	 * @var Queries
	 */
	private $db;

	/**
	 * Table name (with the site's base prefix).
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Open the plan: the table created when it is not there, and its columns read back.
	 *
	 * @param Queries $db   Connection (an ImportSession).
	 * @param string  $name Table name: the site's base prefix and TABLE.
	 * @throws TransientFailure When its columns could not be read.
	 * @throws \RuntimeException When the table lacks columns this code needs.
	 */
	public function __construct( Queries $db, string $name ) {
		$this->db   = $db;
		$this->name = $name;
		$db->run( self::create_sql( $name ) );
		$have = array();
		foreach ( $db->rows( 'SHOW COLUMNS FROM ' . SqlWriter::identifier( $name ) ) as $row ) {
			$have[] = (string) $row[0];
		}
		if ( array() === $have ) {
			throw new TransientFailure( 'The columns of the swap plan could not be read.' );
		}
		$missing = array_values( array_diff( array_keys( self::COLUMNS ), $have ) );
		if ( array() !== $missing ) {
			throw new \RuntimeException( sprintf( 'The table of the swap plan lacks columns this version of WP Checkpoint needs (%s); the database refused to change it. Remove the table (WP Checkpoint creates it again), then retry.', implode( ', ', $missing ) ) );
		}
	}

	/**
	 * The statement that creates the table when it is not there.
	 *
	 * @param string $name Table name.
	 * @return string
	 */
	public static function create_sql( string $name ): string {
		$columns = array();
		foreach ( self::COLUMNS as $column => $definition ) {
			$columns[] = $column . ' ' . $definition;
		}
		$columns[] = 'UNIQUE KEY job_attempt_seq (job_id, attempt, seq)';
		return 'CREATE TABLE IF NOT EXISTS ' . SqlWriter::identifier( $name ) . ' (' . implode( ', ', $columns ) . ') ENGINE=InnoDB';
	}

	/**
	 * The attempt number after the job's highest so far.
	 *
	 * @param int $job_id Job id.
	 * @return int
	 */
	public function next_attempt( int $job_id ): int {
		$rows = $this->db->rows( 'SELECT COALESCE(MAX(attempt), 0) FROM ' . SqlWriter::identifier( $this->name ) . ' WHERE job_id = ?', array( (string) $job_id ) );
		return (int) ( $rows[0][0] ?? 0 ) + 1;
	}

	/**
	 * Remove up to DELETE_ROWS rows of the job's attempts other than this one.
	 *
	 * @param int $job_id  Job id.
	 * @param int $attempt The attempt to keep.
	 * @return int Rows removed (fewer than DELETE_ROWS: none are left).
	 */
	public function delete_others( int $job_id, int $attempt ): int {
		return $this->db->write( 'DELETE FROM ' . SqlWriter::identifier( $this->name ) . ' WHERE job_id = ? AND attempt <> ? ORDER BY id LIMIT ' . self::DELETE_ROWS, array( (string) $job_id, (string) $attempt ) );
	}

	/**
	 * Write a batch of entries from a sequence number on, after removing what the attempt holds from there.
	 *
	 * @param int                              $job_id  Job id.
	 * @param int                              $attempt Attempt.
	 * @param int                              $from    Sequence number of the first entry.
	 * @param array<int, array<string, mixed>> $entries Entries: kind, live, stage, old, had_live.
	 * @return void
	 */
	public function write( int $job_id, int $attempt, int $from, array $entries ): void {
		$this->db->write( 'DELETE FROM ' . SqlWriter::identifier( $this->name ) . ' WHERE job_id = ? AND attempt = ? AND seq >= ?', array( (string) $job_id, (string) $attempt, (string) $from ) );
		if ( array() === $entries ) {
			return;
		}
		$values = array();
		$params = array();
		foreach ( array_values( $entries ) as $i => $entry ) {
			$values[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
			array_push( $params, (string) $job_id, (string) $attempt, (string) ( $from + $i ), (string) $entry['kind'], (string) $entry['live'], (string) ( $entry['stage'] ?? '' ), (string) ( $entry['old'] ?? '' ), empty( $entry['had_live'] ) ? '0' : '1' );
		}
		$this->db->write( 'INSERT INTO ' . SqlWriter::identifier( $this->name ) . ' (job_id, attempt, seq, kind, live, stage, old, had_live) VALUES ' . implode( ', ', $values ), $params );
	}

	/**
	 * Close the plan: its COMPLETE row, after the entries (removing any row from there on first).
	 *
	 * @param int $job_id  Job id.
	 * @param int $attempt Attempt.
	 * @param int $count   Number of entries.
	 * @return void
	 */
	public function complete( int $job_id, int $attempt, int $count ): void {
		$this->write(
			$job_id,
			$attempt,
			$count,
			array(
				array(
					'kind' => self::COMPLETE,
					'live' => (string) $count,
				),
			)
		);
	}

	/**
	 * The number of entries of a complete plan, or null when the attempt has no COMPLETE row, or its rows do not
	 * add up to it (one entry per sequence number below it, nothing else).
	 *
	 * @param int $job_id  Job id.
	 * @param int $attempt Attempt.
	 * @return int|null
	 */
	public function complete_count( int $job_id, int $attempt ) {
		$table = SqlWriter::identifier( $this->name );
		$mark  = $this->db->rows( 'SELECT seq, live FROM ' . $table . ' WHERE job_id = ? AND attempt = ? AND kind = ?', array( (string) $job_id, (string) $attempt, self::COMPLETE ) );
		if ( 1 !== count( $mark ) || (string) $mark[0][0] !== (string) $mark[0][1] ) {
			return null;
		}
		$count = (int) $mark[0][0];
		$rows  = $this->db->rows( 'SELECT COUNT(*), COALESCE(MAX(seq), -1) FROM ' . $table . ' WHERE job_id = ? AND attempt = ? AND kind <> ?', array( (string) $job_id, (string) $attempt, self::COMPLETE ) );
		return (int) ( $rows[0][0] ?? -1 ) === $count && (int) ( $rows[0][1] ?? -2 ) === $count - 1 ? $count : null;
	}

	/**
	 * Entries of an attempt in sequence order, after a sequence number (a page).
	 *
	 * @param int $job_id  Job id.
	 * @param int $attempt Attempt.
	 * @param int $after   Last sequence number read (-1 before the first).
	 * @param int $limit   Page size.
	 * @return array<int, array{seq: int, kind: string, live: string, stage: string, old: string, had_live: bool}>
	 */
	public function read( int $job_id, int $attempt, int $after, int $limit ): array {
		$out = array();
		foreach ( $this->db->rows( 'SELECT seq, kind, live, stage, old, had_live FROM ' . SqlWriter::identifier( $this->name ) . ' WHERE job_id = ? AND attempt = ? AND seq > ? AND kind <> ? ORDER BY seq LIMIT ' . max( 1, $limit ), array( (string) $job_id, (string) $attempt, (string) $after, self::COMPLETE ) ) as $row ) {
			$out[] = array(
				'seq'      => (int) $row[0],
				'kind'     => (string) $row[1],
				'live'     => (string) $row[2],
				'stage'    => (string) $row[3],
				'old'      => (string) $row[4],
				'had_live' => '1' === (string) $row[5],
			);
		}
		return $out;
	}
}
