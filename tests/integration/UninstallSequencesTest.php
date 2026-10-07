<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\UninstallFence;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Support\UninstallSetting;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * The uninstall against a swap that enters the site meanwhile, generated: each sequence is a real Uninstaller::run()
 * with one swap entering (its real statement, on a second connection) at one of the run's opportunities (any query of
 * the run, any point it reads whether a job holds the site, closes the fence, beats it, begins a step that removes
 * something or deletes a file of the storage directory), or at none. The fence is there ("fence": this version) or not
 * ("old": a site sharing the database runs an older version, whose swap enters without the fence); or it is there and
 * opened under the uninstall right before the swap enters ("reopen": healed as stale, or opened by hand). The data is
 * deleted or kept; the storage deletion beats before each file.
 *
 * - I1 (fence): no swap enters once the uninstall closed the fence.
 * - I2: no step that removes something runs after a read of whether a job holds the site that came after a swap
 *   entered (that read sees it and the uninstall stops). An entry after that read and before the step, possible only
 *   without the fence, is the known boundary of an older version.
 * - I3 (fence): once the uninstall ends, the fence is open (it stopped, or kept the data), or gone with the tables.
 * - I4: a job that entered is never cancelled.
 * - I5: every step that removes something comes right after a read of whether a job holds the site (the second line:
 *   with the fence too, for a site sharing the database that runs an older version), with the fence closed by this
 *   uninstall after a beat that follows that read; and each deletion of the storage directory right after a beat.
 * - I6 (reopen): once the fence was opened under the uninstall, nothing is removed after its next beat.
 *
 * Fixed seed; WPCHECKPOINT_UNINSTALL_SEQUENCES sequences (default 120), WPCHECKPOINT_UNINSTALL_SEQUENCES_SEED. Every
 * case must occur, counted where it happened.
 */
final class UninstallSequencesTest extends SwapTestCase {

	/** @var int The swap's job in the current sequence. */
	private $swapper = 0;

	public function tear_down(): void {
		global $wpdb;
		$this->seam( null );
		$this->beat_every( null );
		$wpdb->query( 'COMMIT' );
		Options::delete( Schema::OPTION );
		Schema::ensure();
		UninstallFence::create();
		UninstallFence::open();
		$wpdb->query( 'COMMIT' );
		Plugin::instance()->reset_directories();
		Plugin::instance()->directories()->base();
		parent::tear_down();
	}

	public function test_no_step_of_an_uninstall_removes_anything_once_a_swap_entered_before_it(): void {
		$count = max( 1, (int) ( getenv( 'WPCHECKPOINT_UNINSTALL_SEQUENCES' ) ?: 120 ) );
		$seed  = (int) ( getenv( 'WPCHECKPOINT_UNINSTALL_SEQUENCES_SEED' ) ?: 1 );
		$log   = Sandbox::make( 'uninstall-sequences' );
		$was   = ini_get( 'error_log' );
		ini_set( 'error_log', $log . '/php-error.log' );
		$this->beat_every( 0 );
		try {
			// The opportunities of each kind of run, from one run without a swap.
			$length = array();
			foreach ( array( 'fence', 'old', 'reopen' ) as $mode ) {
				foreach ( array( false, true ) as $delete ) {
					$length[ $mode . (int) $delete ] = $this->sequence( $mode, $delete, 0 )['opportunities'];
					$this->assertGreaterThan( 5, $length[ $mode . (int) $delete ], 'the control: the run has opportunities' );
				}
			}
			mt_srand( $seed );
			$cases = array_fill_keys( array( 'fence: entered before the close', 'fence: refused after the close', 'old: stopped by a read after the entry', 'old: entered after the last read before a step', 'old: entered right before the cancel', 'reopen: stopped by a beat before a step', 'reopen: stopped by a beat in the storage deletion', 'reopen: opened after the last beat before a step', 'none', 'deleted the data', 'kept the data' ), 0 );
			for ( $n = 0; $n < $count; $n++ ) {
				$mode   = array( 'fence', 'old', 'reopen' )[ $n % 3 ];
				$delete = 1 === intdiv( $n, 3 ) % 2;
				$at     = mt_rand( 1, $length[ $mode . (int) $delete ] + 1 ); // One past the last: no swap.
				$label  = sprintf( '#%d %s %s, at %d', $n, $mode, $delete ? 'deleting the data' : 'keeping it', $at );
				$run    = $this->sequence( $mode, $delete, $at );
				$this->check( $run, $mode, $label, $cases );
			}
			foreach ( $cases as $case => $times ) {
				$this->assertGreaterThan( 0, $times, 'the control: ' . $case . ' happened in some sequence' );
			}
		} finally {
			ini_set( 'error_log', (string) $was );
			Sandbox::remove( $log );
		}
	}

	/**
	 * One sequence: a fresh state, then the uninstall with a swap entering at opportunity $at (0: none).
	 *
	 * @return array{events: string[], opportunities: int, fence: array{state: string, closed_at: int}|null, fence_absent: bool, swapper: Job|null, tables: bool}
	 */
	private function sequence( string $mode, bool $delete, int $at ): array {
		global $wpdb;
		$this->reset( $mode, $delete );
		$base   = Plugin::instance()->directories()->base();
		$other  = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$events = array();
		$i      = 0;
		$swap   = function () use ( $other, $mode, &$events ): void {
			$job = Plugin::instance()->jobs()->find( $this->swapper );
			if ( null === $job ) {
				$events[] = 'inject:gone';
				return;
			}
			// With the token of its own run (a cancel clears the row's: then it does not enter, as a real run would not).
			$token = 'sequence-' . $job->id;
			if ( 'reopen' === $mode ) {
				$other->query( $other->prepare( 'UPDATE ' . UninstallFence::name() . ' SET state = %s WHERE id = %d', UninstallFence::OPEN, UninstallFence::ROW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
				$events[] = 'inject:reopened';
			}
			$sql      = 'old' !== $mode
				? JobRepository::entering_sql( $job->id, $token, array( 'site_state' => Job::SITE_CHANGING, 'updated_at' => time() ), array( '%d', '%d' ), time() )
				: $other->prepare( 'UPDATE ' . JobRepository::table() . ' SET site_state = %d, updated_at = %d WHERE id = %d AND lock_token = %s', Job::SITE_CHANGING, time(), $job->id, $token ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the jobs table.
			$events[] = (int) $other->query( $sql ) > 0 ? 'inject:entered' : 'inject:refused';
		};
		$opportunity = function () use ( &$i, $at, $swap ): void {
			if ( ++$i === $at ) {
				$swap();
			}
		};
		$filter = static function ( $sql ) use ( $opportunity ) {
			$opportunity();
			return $sql;
		};
		$this->seam(
			static function ( string $point ) use ( &$events, $opportunity ): void {
				$opportunity();
				$events[] = $point;
			}
		);
		add_filter( 'query', $filter );
		try {
			$this->autocommit(
				static function (): void {
					Uninstaller::run();
				}
			);
		} finally {
			remove_filter( 'query', $filter );
			$this->seam( null );
			$other->close();
			// A deletion stopped part way leaves the directory without its owner marker: registered here and deleted,
			// so no later test finds it.
			if ( in_array( 'unit:storage', $events, true ) && is_dir( $base ) ) {
				Deleter::allow( $base );
				Deleter::delete_tree( dirname( $base ), $base );
			}
		}
		$wpdb->query( 'COMMIT' );
		return array(
			'events'        => $events,
			'opportunities' => $i,
			'fence'         => UninstallFence::row(),
			'fence_absent'  => UninstallFence::absent(),
			'swapper'       => Schema::table_exists() ? Plugin::instance()->jobs()->find( $this->swapper ) : null,
			'tables'        => Schema::table_exists(),
		);
	}

	/**
	 * The invariants after one sequence, and the cases it was.
	 *
	 * @param array<string, mixed> $run   sequence().
	 * @param array<string, int>   $cases Cases seen.
	 */
	private function check( array $run, string $mode, string $label, array &$cases ): void {
		$events   = $run['events'];
		$entered  = array_search( 'inject:entered', $events, true );
		$closed   = array_search( 'closed:' . UninstallFence::DONE, $events, true );
		$reopened = array_search( 'inject:reopened', $events, true );
		$reopened = false !== $reopened && false !== $closed && $reopened > $closed ? $reopened : false; // Before the close it changes nothing.
		$label  .= ' (' . implode( ', ', $events ) . ')';
		if ( 'fence' === $mode ) {
			// I1.
			$this->assertFalse( false !== $entered && false !== $closed && $entered > $closed, $label . ': I1, entered after the close' );
			if ( false !== $closed && in_array( 'inject:refused', $events, true ) ) {
				++$cases['fence: refused after the close'];
			}
			if ( false !== $entered ) {
				++$cases['fence: entered before the close'];
			}
		}
		// I2: every step that removes something follows a read that no entry preceded. I5: right after a read (and a
		// beat, with the fence), each deletion right after a beat. I6: nothing removed after a beat that followed the
		// fence opened under the uninstall.
		$last_check = -1;
		$last_beat  = -1;
		$previous   = array( '', '' );
		$in_storage = false;
		foreach ( $events as $index => $event ) {
			if ( 'check' === $event ) {
				$last_check = $index;
				$in_storage = false;
			} elseif ( 'beat' === $event ) {
				$last_beat = $index;
			} elseif ( 0 === strpos( $event, 'unit:' ) || 'delete' === $event ) {
				$this->assertFalse( false !== $reopened && $reopened < $last_beat, $label . ': I6, ' . $event . ' after a beat that came after the fence was opened' );
				if ( 'delete' === $event ) {
					$this->assertTrue( $in_storage, $label . ': a deletion of the storage directory outside its step' );
					$this->assertSame( 'beat', $previous[0], $label . ': I5, ' . $event . ' right after a beat' );
					if ( false !== $reopened && $reopened > $last_beat ) {
						++$cases['reopen: opened after the last beat before a step'];
					}
					$previous = array( $event, $previous[0] );
					continue;
				}
				$this->assertFalse( false !== $entered && $entered < $last_check, $label . ': I2, ' . $event . ' after a read that came after the entry' );
				if ( 'old' === $mode ) {
					$this->assertSame( 'check', $previous[0], $label . ': I5, ' . $event . ' right after a read' );
				} else {
					$this->assertSame( array( 'beat', 'check' ), $previous, $label . ': I5, ' . $event . ' right after a read and a beat' );
				}
				if ( 'old' === $mode && false !== $entered && $entered > $last_check ) {
					++$cases['old: entered after the last read before a step'];
					if ( 'unit:cancel' === $event ) {
						++$cases['old: entered right before the cancel'];
					}
				}
				if ( false !== $reopened && $reopened > $last_beat ) {
					++$cases['reopen: opened after the last beat before a step'];
				}
				$in_storage = 'unit:storage' === $event;
			}
			if ( 0 !== strpos( $event, 'inject:' ) ) {
				$previous = array( $event, $previous[0] );
			}
		}
		if ( false !== $reopened ) {
			$after = array_slice( $events, (int) $reopened );
			$beat  = array_search( 'beat', $after, true );
			if ( false !== $beat ) {
				// Stopped by that beat: where it was.
				$before = array_slice( $events, 0, (int) $reopened );
				$step   = array_values( array_filter( $before, static function ( string $event ): bool {
					return 0 === strpos( $event, 'unit:' ) || 'check' === $event;
				} ) );
				++$cases[ 'unit:storage' === end( $step ) ? 'reopen: stopped by a beat in the storage deletion' : 'reopen: stopped by a beat before a step' ];
			}
		}
		if ( false !== $entered && 'old' === $mode && ! in_array( 'unit:cancel', array_slice( $events, (int) $entered ), true ) ) {
			++$cases['old: stopped by a read after the entry'];
		}
		$deleted = in_array( 'unit:tables', $events, true );
		// I3.
		if ( 'old' !== $mode ) {
			if ( $deleted ) {
				$this->assertTrue( $run['fence_absent'], $label . ': I3, the fence went with the tables' );
			} else {
				$this->assertSame( UninstallFence::OPEN, $run['fence']['state'] ?? null, $label . ': I3, the fence is open again' );
			}
		}
		// I4.
		if ( false !== $entered && $run['tables'] ) {
			$this->assertNotSame( Job::CANCELLED, $run['swapper']->status ?? '', $label . ': I4, a job that entered was cancelled' );
		}
		if ( ! in_array( 'inject:entered', $events, true ) && ! in_array( 'inject:refused', $events, true ) ) {
			++$cases['none'];
		}
		++$cases[ $deleted ? 'deleted the data' : 'kept the data' ];
	}

	/**
	 * A fresh state: the tables, the storage directory, the fence there (open) or not, two jobs (one about to enter
	 * the site, one plain), committed; the data deleted on uninstall or kept.
	 */
	private function reset( string $mode, bool $delete ): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		if ( ! Schema::table_exists() ) {
			Options::delete( Schema::OPTION );
			Schema::ensure();
		}
		$wpdb->query( 'DELETE FROM ' . JobRepository::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the jobs table.
		Plugin::instance()->reset_directories();
		$base = Plugin::instance()->directories()->base();
		foreach ( array( 'swapper', 'plain' ) as $which ) {
			$job = Plugin::instance()->jobs()->create( 'export' );
			$wpdb->update(
				JobRepository::table(),
				array(
					'status'       => Job::RUNNING,
					'lock_token'   => 'sequence-' . $job->id,
					'locked_until' => time() + 300,
					'storage_path' => $base,
				),
				array( 'id' => $job->id )
			);
			if ( 'swapper' === $which ) {
				$this->swapper = $job->id;
			}
		}
		UninstallSetting::save( $delete );
		for ( $i = 0; $i < 4; $i++ ) {
			file_put_contents( $base . '/backups/sequence-' . $i . '.txt', 'x' ); // More for the storage deletion to delete.
		}
		// The fence last: making the jobs may make it again (Schema::ensure()).
		if ( 'old' !== $mode ) {
			UninstallFence::create();
			UninstallFence::open();
		} else {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . UninstallFence::name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
		}
		$wpdb->query( 'COMMIT' );
		$this->assertSame( 'old' === $mode, UninstallFence::absent(), 'the control: the fence is there only for this version' );
	}

	/**
	 * Set how often the uninstall's storage deletion beats (null: UninstallFence::BEAT_SECONDS).
	 */
	private function beat_every( ?int $seconds ): void {
		$property = new \ReflectionProperty( Uninstaller::class, 'beat_seconds' );
		$property->setAccessible( true );
		$property->setValue( null, $seconds );
	}

	/**
	 * Set the uninstall's test seam (null: none).
	 */
	private function seam( ?callable $at ): void {
		$property = new \ReflectionProperty( Uninstaller::class, 'at' );
		$property->setAccessible( true );
		$property->setValue( null, $at );
	}
}
