<?php
/**
 * The job row as the fence of the restore's writes outside the job row.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\LockLost;

defined( 'ABSPATH' ) || exit;

/**
 * A run that outlived its lease must not write after the job was taken over, and "confirm, then write" leaves a
 * window between the two. The writes that cannot carry the condition in their own statement (a batch of them, or
 * ones to tables of their own) are made inside a transaction that first locks the job row under the run's token
 * (lock()): a take-over rewrites that row, so it waits for the transaction to end, and a run that lost the job finds
 * no row and writes nothing. holds() is the plain read for a write that found no row: whether the token still holds.
 *
 * Pure PHP.
 */
final class JobFence {

	/**
	 * Seconds the lock waits for the row: another run's fenced write holds it for a few statements at most, and a
	 * take-over's rewrite of the row is one autocommitted statement. Longer than that is something else holding the
	 * row, which is waited out as a transient failure, not for the server's default of fifty seconds.
	 */
	const WAIT = 5;

	/**
	 * Lock the job row under the run's token, inside the connection's open transaction: the wait for the row is
	 * bounded (WAIT), and the session's own wait is put back afterwards.
	 *
	 * @param Queries $db    Connection, with a transaction open.
	 * @param string  $table The jobs table.
	 * @param int     $id    Job id.
	 * @param string  $token The run's lock token.
	 * @return void
	 * @throws LockLost When the row is not held under the token: the caller rolls back and writes nothing.
	 */
	public static function lock( Queries $db, string $table, int $id, string $token ): void {
		if ( '' === $token ) {
			throw new LockLost( sprintf( 'Job %d: no lock token to write under.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
		$rows = self::bounded(
			$db,
			static function () use ( $db, $table, $id, $token ): array {
				return $db->rows( 'SELECT id FROM ' . SqlWriter::identifier( $table ) . ' WHERE id = ? AND lock_token = ? FOR UPDATE', array( (string) $id, $token ) );
			}
		);
		if ( array() === $rows ) {
			throw new LockLost( sprintf( 'Job %d: the lock is no longer held; nothing was written.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
	}

	/**
	 * Run a statement that may wait for the job row with the wait bounded (WAIT seconds), the session's own wait put
	 * back afterwards.
	 *
	 * @template T
	 * @param Queries       $db   Connection.
	 * @param callable(): T $work The statement.
	 * @return T
	 */
	public static function bounded( Queries $db, callable $work ) {
		$before = (int) ( $db->rows( 'SELECT @@SESSION.innodb_lock_wait_timeout' )[0][0] ?? 0 );
		$db->run( 'SET SESSION innodb_lock_wait_timeout = ' . self::WAIT );
		try {
			return $work();
		} finally {
			if ( $before > 0 ) {
				$db->run( 'SET SESSION innodb_lock_wait_timeout = ' . $before );
			}
		}
	}

	/**
	 * Whether the job row is held under the token now (a plain read).
	 *
	 * @param Queries $db    Connection.
	 * @param string  $table The jobs table.
	 * @param int     $id    Job id.
	 * @param string  $token The run's lock token.
	 * @return bool
	 */
	public static function holds( Queries $db, string $table, int $id, string $token ): bool {
		return '' !== $token && array() !== $db->rows( 'SELECT id FROM ' . SqlWriter::identifier( $table ) . ' WHERE id = ? AND lock_token = ?', array( (string) $id, $token ) );
	}
}
