<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\StaleJob;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * A cancel of an undo (JobActions::cancel(), JobRepository::cancel_undo()) against everything that can happen to the
 * job's row between the cancel's read and its writes, exhaustively: every starting state of an undo that has not
 * changed the site, every event another process can write, at each of the cancel's two writes. Whatever happened,
 * what the cancel says is true of the row (ARCHITECTURE "4c-C2", C1–C5):
 *
 * - C1: "given up" — the row is swapped and at no position of an undo (it will never change the site); "requested" —
 *   at its first position, swapped, with a request (the write that starts changing the site requires none).
 * - C2: "must finish" — the row is changing the site, or asks how to go on; "ended" — the row is undone.
 * - C3: when it can be none of these, the cancel says the job changed meanwhile (StaleJob) and wrote nothing.
 * - C4: an abandon written meanwhile is not overwritten.
 * - C5: a request is written only on a row at the undo's first position, swapped.
 * - C6: no cancel ends an undo that changed the site as cancelled (which would reclaim the restored copy): after
 *   every case a second cancel is made, as REST makes one after a job that changed meanwhile.
 * - The "cancelled" note goes into the job's log only when this cancel's own write gave the undo up.
 *
 * The undo's row is made directly (no restore runs): what is tested is the cancel's decision on the row. Counts of
 * every starting state, event, point and outcome are the controls.
 */
final class UndoCancelSequencesTest extends JobTestCase {

	/** Starting states of an undo that has not changed the site. */
	const STARTS = array( 'queued', 'running', 'failed', 'expired' );

	/** What another process writes meanwhile. */
	const EVENTS = array( 'none', 'giving_up', 'given_up', 'changing', 'asking', 'reverted', 'ended', 'requested', 'abandoned', 'reclaimed', 'locked', 'failed', 'elsewhere', 'unreadable' );

	/** Before which of the cancel's writes (1: the give-up, 2: the request). */
	const POINTS = array( 1, 2 );

	/**
	 * A job row at the undo's first position (SwapStep::undo_cursor()), managed by this installation.
	 */
	private function undo_row( string $start ): Job {
		global $wpdb;
		$job    = Plugin::instance()->jobs()->create( 'export', self::$admin_id );
		$now    = time();
		$values = array(
			'step'          => SwapStep::ID,
			'status'        => Job::QUEUED,
			'site_state'    => Job::SITE_SWAPPED,
			'cursor_json'   => wp_json_encode( SwapStep::undo_cursor( 1, 'mark' ) ),
			'committed_at'  => $now - 60,
			'finished_at'   => 0,
			'lock_token'    => '',
			'locked_until'  => 0,
			'storage_token' => (string) Plugin::instance()->directories()->state()['token'],
			'held_by'       => '',
		);
		if ( 'running' === $start ) {
			$values['status']       = Job::RUNNING;
			$values['lock_token']   = 'a-live-run';
			$values['locked_until'] = $now + 300;
		} elseif ( 'failed' === $start ) {
			$values['status']       = Job::FAILED;
			$values['finished_at']  = $now - 30;
			$values['failure_kind'] = Job::stamp_failure( Job::FAILURE_TEMPORARY, $now - 30 );
			$values['last_error']   = 'The database server went away.';
		} elseif ( 'expired' === $start ) {
			$values['status']       = Job::RUNNING;
			$values['lock_token']   = 'a-run-that-died';
			$values['locked_until'] = $now - 60;
		}
		$wpdb->update( Schema::jobs_table(), $values, array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		return Plugin::instance()->jobs()->find( $job->id );
	}

	/**
	 * Write an event on another connection.
	 */
	private static function happen( string $event, int $id ): void {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$table = Schema::jobs_table();
		$now   = time();
		$undo  = SwapStep::undo_cursor( 1, 'mark' );
		switch ( $event ) {
			case 'giving_up': // The run's give-up checkpoint, before it completes (SwapStep::give_up_undo()).
				$other->query( $other->prepare( "UPDATE {$table} SET cursor_json = %s, cancel_requested = 0 WHERE id = %d", wp_json_encode( array( 'phase' => 'done', 'attempt' => 1, 'mark' => 'mark' ) ), $id ) );
				break;
			case 'given_up': // Given up and completed (by its run, or by another cancel at once).
				$other->query( $other->prepare( "UPDATE {$table} SET status = %s, cursor_json = '[]', cancel_requested = 0, lock_token = '', locked_until = 0 WHERE id = %d", Job::COMPLETED, $id ) );
				break;
			case 'changing': // The run's write that starts changing the site.
				$other->query( $other->prepare( "UPDATE {$table} SET site_state = %d, cursor_json = %s WHERE id = %d AND cancel_requested = 0", Job::SITE_CHANGING, wp_json_encode( array( 'phase' => 'reverse' ) + $undo ), $id ) );
				break;
			case 'asking': // The undo asks how to go on after its carry back failed (site swapped, nothing put back).
				$other->query( $other->prepare( "UPDATE {$table} SET cursor_json = %s WHERE id = %d", wp_json_encode( array( 'phase' => 'carry_ask' ) + $undo ), $id ) );
				break;
			case 'reverted': // Put back, its maintenance file still to come down (the site recorded as untouched).
				$other->query( $other->prepare( "UPDATE {$table} SET site_state = %d, committed_at = 0, cursor_json = %s WHERE id = %d", Job::SITE_UNTOUCHED, wp_json_encode( array( 'phase' => 'reverted' ) + $undo ), $id ) );
				break;
			case 'elsewhere': // Taken over by another installation.
				$other->query( $other->prepare( "UPDATE {$table} SET held_by = %s WHERE id = %d", 'another0another0another0another0', $id ) );
				break;
			case 'unreadable': // A cursor this code did not write.
				$other->query( $other->prepare( "UPDATE {$table} SET cursor_json = %s WHERE id = %d", '{not json', $id ) );
				break;
			case 'ended': // The undo ran to its end.
				$other->query( $other->prepare( "UPDATE {$table} SET status = %s, site_state = %d, committed_at = 0, cursor_json = %s, lock_token = '', locked_until = 0 WHERE id = %d", Job::UNDONE, Job::SITE_UNTOUCHED, wp_json_encode( array( 'phase' => 'reverted' ) + $undo ), $id ) );
				break;
			case 'requested': // Another cancel's request.
				$other->query( $other->prepare( "UPDATE {$table} SET cancel_requested = %d WHERE id = %d AND cancel_requested = 0", $now, $id ) );
				break;
			case 'abandoned': // wp wpcheckpoint job abandon (JobRepository::abandon_held()): max( now, finished_at + 1 ).
				$read = (int) $other->get_var( $other->prepare( "SELECT finished_at FROM {$table} WHERE id = %d", $id ) );
				$at   = max( $now, $read + 1 );
				$other->query( $other->prepare( "UPDATE {$table} SET status = %s, failure_kind = %s, finished_at = %d, lock_token = '', locked_until = 0 WHERE id = %d", Job::FAILED, Job::stamp_failure( Job::FAILURE_FINAL . ':' . Job::REASON_ABANDONED, $at ), $at, $id ) );
				break;
			case 'reclaimed': // Given up, then claimed for an undo anew.
				$other->query( $other->prepare( "UPDATE {$table} SET status = %s, cursor_json = %s, cancel_requested = 0, finished_at = 0, lock_token = '', locked_until = 0 WHERE id = %d", Job::QUEUED, wp_json_encode( $undo ), $id ) );
				break;
			case 'locked': // A run took it.
				$other->query( $other->prepare( "UPDATE {$table} SET status = %s, lock_token = 'a-new-run', locked_until = %d WHERE id = %d", Job::RUNNING, $now + 300, $id ) );
				break;
			case 'failed': // A run stopped at the undo's first position.
				$at = $now + 3;
				$other->query( $other->prepare( "UPDATE {$table} SET status = %s, failure_kind = %s, finished_at = %d, lock_token = '', locked_until = 0 WHERE id = %d", Job::FAILED, Job::stamp_failure( Job::FAILURE_TEMPORARY, $at ), $at, $id ) );
				break;
		}
		$other->close();
	}

	/**
	 * The cancel, with $event written right before its $point-th write.
	 *
	 * @return array{outcome: string, ran: bool}
	 */
	private function cancel( Job $job, string $event, int $point ): array {
		$seen   = 0;
		$ran    = false;
		$filter = static function ( $query ) use ( &$seen, &$ran, $event, $point, $job ) {
			if ( false !== strpos( (string) $query, 'cursor_json LIKE' ) && ++$seen === $point && 'none' !== $event ) {
				$ran = true;
				self::happen( $event, $job->id );
			}
			return $query;
		};
		add_filter( 'query', $filter );
		try {
			$outcome = $this->autocommit(
				static function () use ( $job ): string {
					try {
						return Plugin::instance()->job_actions()->cancel( $job->id )['reason'];
					} catch ( StaleJob $e ) {
						return 'changed';
					}
				}
			);
		} finally {
			remove_filter( 'query', $filter );
		}
		return array(
			'outcome' => $outcome,
			'ran'     => $ran,
		);
	}

	public function test_what_a_cancel_of_an_undo_says_is_true_of_the_row_whatever_happened_meanwhile(): void {
		global $wpdb;
		$counts = array();
		foreach ( self::STARTS as $start ) {
			foreach ( self::EVENTS as $event ) {
				foreach ( self::POINTS as $point ) {
					$label = $start . ' / ' . $event . ' before write ' . $point;
					$job   = $this->undo_row( $start );
					$out   = $this->cancel( $job, $event, $point );
					$now   = Plugin::instance()->jobs()->find( $job->id );
					$wpdb->query( 'COMMIT' );
					$phase = (string) ( $now->cursor['phase'] ?? '' );
					switch ( $out['outcome'] ) {
						case 'undo_given_up': // C1.
							$this->assertSame( Job::SITE_SWAPPED, $now->site_state, $label );
							$this->assertSame( array(), $now->unreadable_columns, $label . ': a row that can be read (an unreadable cursor reads as none)' );
							$this->assertFalse( SwapStep::undoing( $now->cursor ), $label . ': at no position of an undo' );
							break;
						case 'undo_requested': // C1.
							$this->assertSame( Job::SITE_SWAPPED, $now->site_state, $label );
							$this->assertSame( 'undo', $phase, $label );
							$this->assertGreaterThan( 0, $now->cancel_requested, $label );
							break;
						case 'undoing': // C2.
							$this->assertTrue( Job::SITE_CHANGING === $now->site_state || ( Job::SITE_SWAPPED === $now->site_state && SwapStep::undoing( $now->cursor ) && 'undo' !== $phase ) || ( Job::SITE_UNTOUCHED === $now->site_state && SwapStep::undoing( $now->cursor ) && Job::UNDONE !== $now->status ), $label . ': changing the site, asking, or put back and ending' );
							break;
						case 'elsewhere':
							$this->assertFalse( Plugin::instance()->jobs()->manages( $now ), $label );
							break;
						case 'undo_ended': // C2.
							$this->assertSame( Job::UNDONE, $now->status, $label );
							break;
						case 'changed': // C3.
							$this->assertTrue( ( 'undo' === $phase && 0 === $now->cancel_requested ) || array() !== $now->unreadable_columns, $label . ': at the first position with no request, or not readable' );
							$this->assertSame( 0, $now->cancel_requested, $label . ': nothing written' );
							break;
						case 'abandoned':
							$this->assertSame( Job::REASON_ABANDONED, $now->failure_reason, $label );
							break;
						default:
							$this->fail( $label . ': an outcome no cancel of an undo has: ' . $out['outcome'] );
					}
					if ( 'abandoned' === $event && $out['ran'] ) { // C4 (when it happened: a first write that gave up never reaches the second).
						$this->assertSame( Job::REASON_ABANDONED, $now->failure_reason, $label . ': the abandon is kept' );
						$this->assertSame( Job::FAILED, $now->status, $label );
					}
					if ( 0 !== $now->cancel_requested ) { // C5.
						$this->assertSame( 'undo', $phase, $label . ': a request only at the undo\'s first position' );
						$this->assertSame( Job::SITE_SWAPPED, $now->site_state, $label );
					}
					if ( 'none' !== $event ) {
						$this->assertTrue( $out['ran'] || 2 === $point, $label . ': the event was written before the first write' );
					}
					$note = false !== strpos( Plugin::instance()->job_presenter()->log_tail( $now ), 'The undo was cancelled before it changed the site' );
					if ( $out['ran'] && 1 === $point && in_array( $event, array( 'giving_up', 'given_up', 'changing', 'reverted', 'ended' ), true ) ) {
						$this->assertFalse( $note, $label . ': no cancel note when this cancel wrote nothing' );
					}
					if ( 'undo_given_up' === $out['outcome'] && ! $out['ran'] ) {
						$this->assertTrue( $note, $label . ': the control: the note, when this cancel gave it up' );
					}
					// C6: a second cancel, as REST makes after "changed meanwhile".
					$undoing = SwapStep::undoing( $now->cursor ) || Job::UNDONE === $now->status;
					$this->autocommit(
						static function () use ( $job ): void {
							try {
								Plugin::instance()->job_actions()->cancel( $job->id );
							} catch ( \RuntimeException | \LogicException $e ) {
								unset( $e ); // Refused, changed, or already finished: what counts is what the row is after it.
							}
						}
					);
					$after = Plugin::instance()->jobs()->find( $job->id );
					if ( $undoing && Job::SITE_SWAPPED !== $now->site_state ) {
						$this->assertNotSame( Job::CANCELLED, $after->status, $label . ': an undo that changed the site is never ended cancelled' );
					}
					$counts[ 'start ' . $start ]     = ( $counts[ 'start ' . $start ] ?? 0 ) + 1;
					$counts[ 'outcome ' . $out['outcome'] ] = ( $counts[ 'outcome ' . $out['outcome'] ] ?? 0 ) + 1;
					if ( $out['ran'] ) {
						$counts[ 'event ' . $event . ' at ' . $point ] = ( $counts[ 'event ' . $event . ' at ' . $point ] ?? 0 ) + 1;
					}
					$wpdb->delete( Schema::jobs_table(), array( 'id' => $job->id ) );
					$wpdb->query( 'COMMIT' );
				}
			}
		}
		// The controls: every starting state ran, every event happened before the first write, the ones a first write
		// that misses leaves to the second happened there too, and every outcome came out.
		foreach ( self::STARTS as $start ) {
			$this->assertSame( count( self::EVENTS ) * count( self::POINTS ), $counts[ 'start ' . $start ] ?? 0, $start );
		}
		foreach ( self::EVENTS as $event ) {
			if ( 'none' !== $event ) {
				$this->assertGreaterThan( 0, $counts[ 'event ' . $event . ' at 1' ] ?? 0, $event . ' before the first write' );
				$this->assertGreaterThan( 0, $counts[ 'event ' . $event . ' at 2' ] ?? 0, $event . ' before the second write' );
			}
		}
		foreach ( array( 'undo_given_up', 'undo_requested', 'undoing', 'undo_ended', 'changed', 'abandoned', 'elsewhere' ) as $outcome ) {
			$this->assertGreaterThan( 0, $counts[ 'outcome ' . $outcome ] ?? 0, 'the control: some cancel said ' . $outcome );
		}
	}
}
