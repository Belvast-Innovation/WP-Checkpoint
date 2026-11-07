<?php
/**
 * A connection whose statements wait for locks no later than a deadline.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Jobs\TransientFailure;

defined( 'ABSPATH' ) || exit;

/**
 * Another connection's statements, each sent only while time is left before the deadline, and with the session's
 * lock waits (innodb_lock_wait_timeout for rows, lock_wait_timeout for metadata) set just before it to the whole
 * seconds left, at least one: however many statements wait for locks, together they wait no later than the deadline
 * (and its last second). With less than a second left a statement is not sent: it fails as a lock wait that timed
 * out would (TransientFailure, 1205), so that the caller takes it as the database being busy. Statements that wait
 * for no lock pass as they are: SET, SELECT @@…, START TRANSACTION, COMMIT, ROLLBACK. The session's waits are left as
 * the last statement set them: the caller puts them back.
 *
 * What a carry back sends in a run made without WordPress (SwapStep): its unit is bounded by SwapStep::LOCK_WAIT
 * whatever the number of its statements. Pure PHP.
 */
final class DeadlineQueries implements Queries {

	/**
	 * The connection.
	 *
	 * @var Queries
	 */
	private $db;

	/**
	 * The time now, in seconds: function(): float.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * When the statements must be done, on the clock's scale.
	 *
	 * @var float
	 */
	private $deadline;

	/**
	 * Constructor.
	 *
	 * @param Queries  $db       The connection.
	 * @param callable $clock    function(): float, the time now in seconds.
	 * @param float    $deadline When the statements must be done, on the clock's scale.
	 */
	public function __construct( Queries $db, callable $clock, float $deadline ) {
		$this->db       = $db;
		$this->clock    = $clock;
		$this->deadline = $deadline;
	}

	/**
	 * Run one statement that returns no rows, bounded (bound()).
	 *
	 * @param string $sql SQL.
	 * @return int Affected rows.
	 * @throws TransientFailure With 1205 when less than a second is left (nothing is sent).
	 */
	public function run( string $sql ): int {
		$this->bound( $sql );
		return $this->db->run( $sql );
	}

	/**
	 * Run one query, bounded (bound()).
	 *
	 * @param string   $sql    SQL with ? placeholders.
	 * @param string[] $params Values.
	 * @return array<int, array<int, string|null>>
	 * @throws TransientFailure With 1205 when less than a second is left (nothing is sent).
	 */
	public function rows( string $sql, array $params = array() ): array {
		$this->bound( $sql );
		return $this->db->rows( $sql, $params );
	}

	/**
	 * Run one statement that changes rows, bounded (bound()).
	 *
	 * @param string   $sql    SQL with ? placeholders.
	 * @param string[] $params Values.
	 * @return int Affected rows.
	 * @throws TransientFailure With 1205 when less than a second is left (nothing is sent).
	 */
	public function write( string $sql, array $params ): int {
		$this->bound( $sql );
		return $this->db->write( $sql, $params );
	}

	/**
	 * Before a statement that may wait for a lock: the session's waits set to the whole seconds left (at least one),
	 * or, with less than a second left, a failure as a timed-out lock wait (nothing sent).
	 *
	 * @param string $sql The statement.
	 * @return void
	 * @throws TransientFailure With 1205 when less than a second is left.
	 */
	private function bound( string $sql ): void {
		if ( self::waits_for_nothing( $sql ) ) {
			return;
		}
		$left = $this->deadline - (float) call_user_func( $this->clock );
		if ( $left < 1.0 ) {
			throw new TransientFailure( 'The carry had less than a second left before its deadline: the statement was not sent.', 1205 );
		}
		$seconds = max( 1, (int) floor( $left ) );
		$this->db->run( 'SET SESSION innodb_lock_wait_timeout = ' . $seconds );
		$this->db->run( 'SET SESSION lock_wait_timeout = ' . $seconds );
	}

	/**
	 * Whether a statement waits for no lock: SET, SELECT @@…, START TRANSACTION, COMMIT, ROLLBACK (what cannot be
	 * read is taken as one that may wait).
	 *
	 * @param string $sql The statement.
	 * @return bool
	 */
	public static function waits_for_nothing( string $sql ): bool {
		return 1 === preg_match( '/\A\s*(?:SET\s|SELECT\s+@@|START\s+TRANSACTION\b|COMMIT\b|ROLLBACK\b)/i', $sql );
	}
}
