<?php
/**
 * The job row as the fence of the restore's writes outside the job row.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Database\SqlWriter;
use WPCheckpoint\Jobs\LockLost;
use WPCheckpoint\Jobs\TransientFailure;

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
	 * The connections inside within() now (by object id): the fenced writers assert it (assert_locked()), and a
	 * nested within() on the same connection is refused (its START TRANSACTION would commit the outer's writes).
	 *
	 * @var array<int, true>
	 */
	private static $within = array();

	/**
	 * Run $work in a transaction whose first statement locks the job row under the run's token: committed when
	 * $work returns, rolled back (quietly: the failure is what matters) when it throws. The one shape of a fenced
	 * write: a take-over rewrites the row, so it waits for the transaction, and a run that lost the job finds no row
	 * and writes nothing. What is read to set the lock up (the table's engine, the session's wait) is read before
	 * the transaction opens, so that nothing establishes a read view before the locking read: what $work then reads
	 * is the state as of the lock.
	 *
	 * @template T
	 * @param Queries                                      $db    Connection.
	 * @param array{table: string, id: int, token: string} $fence The job row and the run's token.
	 * @param callable(): T                                $work  The writes.
	 * @return T
	 * @throws LockLost When the row is not held under the token (or the run has no token): nothing was written.
	 * @throws PlatformUnsupported When the jobs table is not InnoDB: the fence cannot hold (before the transaction).
	 * @throws TransientFailure When the table's engine cannot be read (before the transaction).
	 * @throws \LogicException When called inside another within() on the same connection.
	 * @throws \Throwable Whatever stops $work, after the rollback.
	 */
	public static function within( Queries $db, array $fence, callable $work ) {
		$id = spl_object_id( $db );
		if ( isset( self::$within[ $id ] ) ) {
			throw new \LogicException( 'JobFence::within() inside another: the inner transaction would commit the outer\'s writes.' );
		}
		if ( '' === (string) $fence['token'] ) {
			throw new LockLost( sprintf( 'Job %d: no lock token to write under.', (int) $fence['id'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
		$table = (string) $fence['table'];
		self::require_innodb( $db, $table );
		$wait = self::session_wait( $db );
		$db->run( 'START TRANSACTION' );
		self::$within[ $id ] = true;
		try {
			self::locking_read( $db, $table, (int) $fence['id'], (string) $fence['token'], $wait );
			$result = $work();
			$db->run( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			try {
				$db->run( 'ROLLBACK' );
			} catch ( \Throwable $rolling_back ) {
				unset( $rolling_back ); // The failure is what matters; the connection may be gone.
			}
			throw $e;
		} finally {
			unset( self::$within[ $id ] );
		}
	}

	/**
	 * A fenced writer's guard: the connection is inside within() (the row locked, the transaction open).
	 *
	 * @param Queries $db Connection.
	 * @return void
	 * @throws \LogicException When it is not: a write that was meant to be fenced is being made outside the fence.
	 */
	public static function assert_locked( Queries $db ): void {
		if ( ! isset( self::$within[ spl_object_id( $db ) ] ) ) {
			throw new \LogicException( 'A fenced write outside JobFence::within(): the job row is not locked.' );
		}
	}

	/**
	 * The locking read of the job row, its wait bounded (WAIT seconds; $wait, the session's own, put back after).
	 *
	 * @param Queries $db    Connection, with a transaction open.
	 * @param string  $table The jobs table.
	 * @param int     $id    Job id.
	 * @param string  $token The run's lock token.
	 * @param int     $wait  The session's wait (0: unknown, left alone).
	 * @return void
	 * @throws LockLost When the row is not held under the token.
	 */
	private static function locking_read( Queries $db, string $table, int $id, string $token, int $wait ): void {
		if ( $wait > 0 ) {
			$db->run( 'SET SESSION innodb_lock_wait_timeout = ' . self::WAIT );
		}
		try {
			$rows = $db->rows( 'SELECT id FROM ' . SqlWriter::identifier( $table ) . ' WHERE id = ? AND lock_token = ? FOR UPDATE', array( (string) $id, $token ) );
		} finally {
			if ( $wait > 0 ) {
				$db->run( 'SET SESSION innodb_lock_wait_timeout = ' . $wait );
			}
		}
		if ( array() === $rows ) {
			throw new LockLost( sprintf( 'Job %d: the lock is no longer held; nothing was written.', $id ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
	}

	/**
	 * The session's lock wait, or 0 when it cannot be read (then it is left alone).
	 *
	 * @param Queries $db Connection.
	 * @return int
	 */
	private static function session_wait( Queries $db ): int {
		return (int) ( $db->rows( 'SELECT @@SESSION.innodb_lock_wait_timeout' )[0][0] ?? 0 );
	}

	/**
	 * A table's engine as the server names it, lower-cased, or null when it cannot be read (one place for the read:
	 * the import's transactional judgement uses it too).
	 *
	 * @param Queries $db    Connection.
	 * @param string  $table Table.
	 * @return string|null
	 */
	public static function engine_of( Queries $db, string $table ) {
		$rows   = $db->rows( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', array( $table ) );
		$engine = strtolower( trim( (string) ( $rows[0][0] ?? '' ) ) );
		return '' === $engine ? null : $engine;
	}

	/**
	 * Refuse a table that is not InnoDB: a locked read on another engine holds nothing. Read every time (one row of
	 * information_schema per fenced write); what cannot be read is no evidence either way.
	 *
	 * @param Queries $db    Connection.
	 * @param string  $table Table.
	 * @return void
	 * @throws PlatformUnsupported When it is not InnoDB.
	 * @throws TransientFailure When its engine cannot be read.
	 */
	public static function require_innodb( Queries $db, string $table ): void {
		$engine = self::engine_of( $db, $table );
		if ( null === $engine ) {
			throw new TransientFailure( 'The engine of the jobs table could not be read.' );
		}
		if ( 'innodb' !== $engine ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the engine name from the server.
			throw new PlatformUnsupported( sprintf( 'The jobs table is %s, not InnoDB: a run cannot lock its job row, so the restore cannot fence its writes. Convert the table to InnoDB (ALTER TABLE ... ENGINE=InnoDB).', $engine ) );
		}
	}

	/**
	 * Run a statement that may wait for the job row with the wait bounded (WAIT seconds), the session's own wait put
	 * back afterwards; when the session's wait cannot be read, nothing is changed.
	 *
	 * @template T
	 * @param Queries       $db   Connection.
	 * @param callable(): T $work The statement.
	 * @return T
	 */
	public static function bounded( Queries $db, callable $work ) {
		$before = self::session_wait( $db );
		if ( $before <= 0 ) {
			return $work();
		}
		$db->run( 'SET SESSION innodb_lock_wait_timeout = ' . self::WAIT );
		try {
			return $work();
		} finally {
			$db->run( 'SET SESSION innodb_lock_wait_timeout = ' . $before );
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
