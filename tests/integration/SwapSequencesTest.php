<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The swap's maintenance file and its direction, over sequences: a run killed (SIGKILL) at every seam of the swap
 * at least once, and between the kill and the next WP-CLI run what may happen meanwhile, given out in turn: visitors
 * writing to the site (always, at the seams where the file has been let go and the site serves visitors), the clock
 * moved on, a cancel, a cache that cannot be flushed once, a maintenance file that cannot be taken down once.
 * Checked at every kill and at the end:
 *
 * - I1: while the next run may still rename anything (the swap or its rollback under way), this restore's file is
 *   held.
 * - I2: once the swap is recorded as made ("committed") or the site as put back ("restored"), no run renames
 *   anything again: only the end is left, whatever visitors did.
 * - I3: a held file of this plugin is there only while a job that holds the site has something left to do, and
 *   every WP-CLI command of the plugin says so.
 * - I4: a job that has ended leaves none of its files held.
 * - I5: visitors' writes after the file was let go are kept where they wrote them.
 *
 * Fixed: the seams in order, one sequence each (WPCHECKPOINT_SWAP_SEQUENCES more are drawn with
 * WPCHECKPOINT_SWAP_SEQUENCES_SEED). Every seam and every kind of interleaving must occur, counted where it happened.
 */
final class SwapSequencesTest extends SwapTestCase {

	/** The seams of the swap going forward (a run killed there once, then WP-CLI to the end). */
	const FORWARD = array( 'entered', 'maintenance', 'dir_aside', 'dir_aside_recorded', 'dir_in', 'carry_written', 'carried', 'batch_recorded', 'batch_sent', 'committed', 'flushed', 'unheld_commit', 'rewrite', 'cron', 'done_recorded', 'exited' );

	/** The seams of the rollback (a run killed between two batches first, then the next one killed there). */
	const BACKWARD = array( 'rollback', 'table_back', 'dir_back', 'dirs_back', 'restored', 'unheld', 'reverted', 'maintenance_down' );

	/** The seams after which the file has been let go: visitors are on the site. */
	const PUBLIC_SEAMS = array( 'unheld_commit', 'rewrite', 'cron', 'done_recorded', 'exited', 'unheld', 'reverted', 'maintenance_down' );

	/**
	 * Seams of the finishing runs where a rename has just been made: the file must be held there (I1). The finishing
	 * runs only roll back or end; the forward renames are checked by what the killed run left (observe()).
	 */
	const AT_RENAME = array( 'table_back', 'dir_back' );

	/** @var array<string, int> I1 checks made inside the finishing runs, per seam. */
	private $held_checks = array();

	/** The seams that record the direction (I2: nothing that renames after them, in the same run or later). */
	const DECIDING = array( 'committed', 'restored' );

	/** Seams of a run that renames something (or is about to). */
	const RENAMING = array( 'entered', 'maintenance', 'dir_aside', 'dir_aside_recorded', 'dir_in', 'carry_written', 'carried', 'batch_recorded', 'batch_sent', 'rollback', 'table_back', 'dir_back', 'dirs_back' );

	/**
	 * What happens meanwhile, given out in turn (in this order, so that every kind meets a seam where it has an
	 * effect): a cancel; a table rename of the rollback that fails once with an error of the moment (the next run),
	 * which lands on a run killed between two batches, so the rollback has tables to put back; the maintenance file
	 * deleted (as a WordPress update does, whoever wrote it), which lands on a run killed at the rollback's start, so
	 * it has tables to put back; a file that cannot be taken down once (the next run); a directory rename of the
	 * rollback that fails once (the next run), which lands on runs killed after a directory was swapped; the clock
	 * (the killed run wrote its times eleven minutes ago), which lands on a run killed after the file was let go; a
	 * cache that cannot be flushed once (the next run).
	 */
	const MEANWHILE = array( 'cancel', 'rename_fail', 'file_removed', 'remove_fail', 'dir_rename_fail', 'clock', 'flush_fail' );

	public function test_the_scan_for_renames_after_the_direction_is_recorded_finds_them(): void {
		$this->assertSame( 'dir_in', self::renamed_after_decision( array( 'carried', 'committed', 'flushed', 'dir_in' ) ), 'in the same run' );
		$this->assertSame( 'table_back', self::renamed_after_decision( array( 'table_back' ), true ), 'in a run after one that recorded it' );
		$this->assertSame( '', self::renamed_after_decision( array( 'dir_in', 'batch_sent', 'committed', 'flushed', 'unheld_commit' ) ), 'the control: renames before it are fine' );
		$this->assertSame( '', self::renamed_after_decision( array( 'table_back', 'dir_back', 'restored', 'unheld' ) ) );
	}

	public function test_every_seam_and_every_interleaving_keeps_the_invariants(): void {
		$count = max( 1, (int) ( getenv( 'WPCHECKPOINT_SWAP_SEQUENCES' ) ?: 24 ) );
		$seed  = (int) ( getenv( 'WPCHECKPOINT_SWAP_SEQUENCES_SEED' ) ?: 1 );
		$seams = array_merge( self::FORWARD, self::BACKWARD );
		$this->assertSame( 24, count( $seams ), 'the default count is one sequence per seam' );
		$this->held_checks = array_fill_keys( self::AT_RENAME, 0 );
		mt_srand( $seed );
		$killed = array_fill_keys( $seams, 0 );
		$kinds  = array_fill_keys( array_merge( self::MEANWHILE, array( 'visitor' ) ), 0 );
		for ( $n = 0; $n < $count; $n++ ) {
			$seam  = $n < count( $seams ) ? $seams[ $n ] : $seams[ mt_rand( 0, count( $seams ) - 1 ) ];
			$kind  = self::MEANWHILE[ $n % count( self::MEANWHILE ) ];
			$label = sprintf( '#%d %s/%s', $n, $seam, $kind );
			$this->sequence( $seam, $kind, $label, $killed, $kinds );
			$this->tear_down_swap();
			$this->set_up_swap();
		}
		foreach ( $killed as $seam => $times ) {
			$this->assertGreaterThan( 0, $times, 'the control: a run was killed at ' . $seam );
		}
		foreach ( $kinds as $kind => $times ) {
			$this->assertGreaterThan( 0, $times, 'the control: ' . $kind . ' happened in some sequence' );
		}
		foreach ( $this->held_checks as $point => $times ) {
			$this->assertGreaterThan( 0, $times, 'the control: the file was checked held at ' . $point . ' inside a run' );
		}
	}

	/**
	 * One sequence: the swap up to the seam (through the rollback for its seams), killed there, what happens
	 * meanwhile, then WP-CLI to the end; the invariants at the kill and at the end.
	 *
	 * @param string             $seam   Seam.
	 * @param string             $kind   What happens meanwhile (one of MEANWHILE).
	 * @param string             $label  For the messages.
	 * @param array<string, int> $killed Kills per seam (counted where they happened).
	 * @param array<string, int> $kinds  Interleavings per kind (counted where they happened).
	 * @return void
	 */
	private function sequence( string $seam, string $kind, string $label, array &$killed, array &$kinds ): void {
		global $wpdb;
		$this->swap_parts['batch'] = 1; // A batch per table: a run can be killed between two.
		$this->register_type();
		$this->trace = $this->sandbox . '/trace';
		$job         = $this->at_swap();
		$before      = $this->site();
		$decided     = '';
		$public      = false;
		if ( in_array( $seam, self::BACKWARD, true ) ) {
			// Some tables swapped, the rest not: the next run rolls back.
			$this->killed_at( $job, 'batch_sent', 1 );
			$this->observe( $job, $label . ' (between batches)', $decided, $public );
		}
		file_put_contents( $this->trace, '' );
		if ( 'clock' === $kind ) {
			$this->clock_offset = -660; // The killed run wrote its times eleven minutes ago.
		}
		$this->killed_at( $job, $seam, 1 );
		$passed = array_values( array_filter( explode( "\n", (string) file_get_contents( $this->trace ) ) ) );
		$this->assertSame( $seam, end( $passed ), $label . ': the run died at its seam' );
		++$killed[ $seam ];
		$this->assertSame( '', self::renamed_after_decision( $passed, '' !== $decided ), $label . ': I2, nothing renamed once the direction was recorded (killed run: ' . implode( ', ', $passed ) . ')' );
		$this->observe( $job, $label, $decided, $public );
		$left = Plugin::instance()->jobs()->find( $job->id );
		$file = new Maintenance( $this->abspath, (string) ( $left->cursor['mark'] ?? '' ) );
		$written = Maintenance::OURS === $file->state() ? $file->time_of( (string) file_get_contents( $file->path() ) ) : null;
		if ( 'clock' === $kind && null !== $written && $written <= time() - 600 ) {
			++$kinds['clock']; // The killed run left a file WordPress already takes as lapsed.
		}
		$this->clock_offset = 0;
		$removed = false;
		if ( 'file_removed' === $kind && Maintenance::OURS === $file->state() ) {
			$this->assertTrue( \WPCheckpoint\Support\Deleter::delete_maintenance_file( $this->abspath, '.maintenance' ) );
			$this->assertSame( Maintenance::NONE, $file->state(), $label . ': the file is gone' );
			$removed = true;
		}

		$visited = false;
		if ( in_array( $seam, self::PUBLIC_SEAMS, true ) ) {
			// The file is let go: visitors write to the site, whichever it is now; a plugin of the restored site makes
			// its table again (one the swap moved aside, so the tables no longer look swapped).
			$this->assertTrue( $public, $label . ': the file was let go at this seam' );
			$wpdb->query( "INSERT INTO `{$wpdb->prefix}swt_keep` (id, v) VALUES (77, 'visitor')" );
			$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}swt_gone` (id INT UNSIGNED NOT NULL PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB" );
			$wpdb->query( "INSERT INTO `{$wpdb->prefix}swt_gone` (id, v) VALUES (78, 'visitor')" );
			file_put_contents( $this->dirs['uploads'] . '/visitor.txt', 'written by a visitor' );
			$wpdb->query( 'COMMIT' );
			$visited = true;
			++$kinds['visitor'];
		}
		$cancelled = false;
		if ( 'cancel' === $kind ) {
			$asked = Plugin::instance()->job_actions()->cancel( $job->id );
			$wpdb->query( 'COMMIT' );
			$cancelled = in_array( $asked['reason'] ?? '', array( 'requested', 'cleaned', 'holder' ), true );
			if ( $cancelled ) {
				++$kinds['cancel'];
			}
		}
		$fails = array(
			'flush'  => 'flush_fail' === $kind ? 1 : 0,
			'remove' => 'remove_fail' === $kind ? 1 : 0,
			'rename' => 'rename_fail' === $kind ? 1 : 0,
			'dir'    => 'dir_rename_fail' === $kind ? 1 : 0,
		);
		$connect = null;
		if ( 'rename_fail' === $kind ) {
			// The rollback's first table rename fails once, as when the connection drops (an error of the moment).
			$connect = static function () use ( &$fails, $job ) {
				return new class( \WPCheckpoint\Restore\ImportSession::open( \WPCheckpoint\Standalone\Credentials::from_wordpress() ), $fails, $job->id ) implements \WPCheckpoint\Restore\Queries {
					/** @var \WPCheckpoint\Restore\ImportSession */
					private $db;
					/** @var array<string, int> */
					private $fails;
					/** @var int */
					private $job;
					public function __construct( $db, array &$fails, int $job ) {
						$this->db    = $db;
						$this->fails = &$fails;
						$this->job   = $job;
					}
					public function run( string $sql ): int {
						if ( $this->fails['rename'] > 0 && 0 === strpos( $sql, 'RENAME TABLE' ) && 'rollback' === ( Plugin::instance()->jobs()->find( $this->job )->cursor['phase'] ?? '' ) ) {
							--$this->fails['rename'];
							throw new \WPCheckpoint\Jobs\TransientFailure( 'Lost connection to MySQL server during query', 2013 );
						}
						return $this->db->run( $sql );
					}
					public function rows( string $sql, array $params = array() ): array {
						return $this->db->rows( $sql, $params );
					}
					public function write( string $sql, array $params ): int {
						return $this->db->write( $sql, $params );
					}
				};
			};
		}
		$this->retype(
			$job,
			array(
				'at'     => function ( string $point ) use ( $job, $label ): void {
					$this->seams[] = $point;
					if ( in_array( $point, self::AT_RENAME, true ) ) {
						// I1, inside the run: at a rename, this restore's file is held (or someone else's is there).
						$mark = (string) ( Plugin::instance()->jobs()->find( $job->id )->cursor['mark'] ?? '' );
						$file = new Maintenance( $this->abspath, $mark );
						$this->assertTrue( $file->is_held() || Maintenance::OTHER === $file->state(), $label . ': I1, held at ' . $point );
						++$this->held_checks[ $point ];
					}
				},
				'flush'  => static function () use ( &$fails ): void {
					if ( $fails['flush'] > 0 ) {
						--$fails['flush'];
						throw new \RuntimeException( 'the cache is away for a moment' );
					}
				},
				'rename' => static function ( string $from, string $to ) use ( &$fails, $job ): bool {
					if ( $fails['dir'] > 0 && 'rollback' === ( Plugin::instance()->jobs()->find( $job->id )->cursor['phase'] ?? '' ) ) {
						--$fails['dir'];
						return false; // The rollback's first directory rename fails once, nothing moved.
					}
					return rename( $from, $to );
				},
				'remove' => static function ( Maintenance $file, callable $confirm ) use ( &$fails ): bool {
					if ( $fails['remove'] > 0 ) {
						--$fails['remove'];
						return false;
					}
					return $file->remove( $confirm );
				},
			),
			$connect
		);
		$done    = null;
		$runs    = array();
		$renamed = false;
		for ( $i = 0; $i < 6; $i++ ) {
			// Each run against what was recorded before it.
			$this->seams = array();
			$done        = $this->cli_run( $job );
			$this->assertSame( '', self::renamed_after_decision( $this->seams, '' !== $decided ), $label . ': I2, nothing renamed once the direction was recorded (next run ' . $i . ': ' . implode( ', ', $this->seams ) . ')' );
			if ( ( 'rename_fail' === $kind && 0 === $fails['rename'] || 'dir_rename_fail' === $kind && 0 === $fails['dir'] ) && ! $renamed ) {
				// The run whose rename failed: waited out with the file held, nothing recorded as put back.
				$renamed = true;
				++$kinds[ $kind ];
				// Waited out by the Runner's back-off: still running (the lease let go), the try counted, not failed.
				$this->assertSame( Job::RUNNING, $done->status, $label . ': a failed rename of the rollback is waited out (' . $done->status . ' ' . $done->last_error . ')' );
				$this->assertSame( 1, $done->cursor['__runner']['retries'] ?? null, $label . ': as a try to be made again' );
				$this->assertSame( 'rollback', $done->cursor['phase'] ?? '', $label . ': not recorded as put back' );
				$this->assertSame( Job::SITE_CHANGING, $done->site_state, $label . ': still recorded as changing the site' );
				$held = new Maintenance( $this->abspath, (string) ( $done->cursor['mark'] ?? '' ) );
				$this->assertTrue( $held->is_held(), $label . ': the file stays held after the failed rename' );
				$this->assertNotSame( array(), Plugin::instance()->half_swapped_warnings(), $label . ': and WP-CLI says so' );
			}
			$runs = array_merge( $runs, $this->seams );
			if ( ! in_array( $done->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			$this->observe( $job, $label . ' (waiting)', $decided, $public );
		}
		if ( 'flush_fail' === $kind && 0 === $fails['flush'] ) {
			++$kinds['flush_fail'];
		}
		if ( 'remove_fail' === $kind && 0 === $fails['remove'] ) {
			++$kinds['remove_fail'];
		}
		if ( $removed && in_array( 'table_back', $runs, true ) ) {
			++$kinds['file_removed']; // The file was gone when the rollback had tables to put back (checked held there).
		}
		$this->assertNotContains( $done->status, array( Job::QUEUED, Job::RUNNING ), $label . ': ended' );
		if ( $cancelled ) {
			$this->assertSame( Job::CANCELLED, $done->status, $label . ': a cancel that was taken ends the job cancelled' );
		}
		// I4: an ended job leaves none of its files held.
		$this->assertFalse( Maintenance::held_in( $this->abspath ), $label . ': no held file once the job ended (' . $done->status . ')' );
		// The outcome, and I5: what visitors wrote after the file was let go is where they wrote it.
		$site     = $this->site();
		$restored = Job::COMPLETED === $done->status;
		$this->assertSame( $restored ? 'restored upload' : null, $site['files']['uploads/2026/10/restored.txt'] ?? null, $label . ': ' . $done->status . ' ' . $done->last_error );
		if ( $visited ) {
			$this->assertSame( 'written by a visitor', $site['files']['uploads/visitor.txt'] ?? null, $label . ': the visitor\'s file stayed' );
			$this->assertContains( array( 'id' => '77', 'v' => 'visitor' ), (array) $site['tables']['swt_keep'], $label . ': the visitor\'s row stayed' );
			$this->assertContains( array( 'id' => '78', 'v' => 'visitor' ), (array) $site['tables']['swt_gone'], $label . ': the visitor\'s table stayed live' );
		} elseif ( ! $restored ) {
			$this->assertSame( $before, $site, $label . ': put back as it was' );
		}
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
		$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}swt_gone`" );
		$this->trace = '';
	}

	/**
	 * What a killed run left: I1 and I3, and whether the direction is decided and the site is public.
	 *
	 * @param Job    $job     Job.
	 * @param string $label   For the messages.
	 * @param string $decided The decided direction so far ('', "committed", "restored"), updated.
	 * @param bool   $public  Whether the file has been let go since the swap began, updated.
	 * @return void
	 */
	private function observe( Job $job, string $label, string &$decided, bool &$public ): void {
		$now   = Plugin::instance()->jobs()->find( $job->id );
		$phase = (string) ( $now->cursor['phase'] ?? '' );
		$mark  = (string) ( $now->cursor['mark'] ?? '' );
		$file  = '' === $mark ? null : new Maintenance( $this->abspath, $mark );
		$held  = null !== $file && $file->is_held();
		if ( in_array( $phase, array( 'dirs', 'carry', 'rename', 'rollback' ), true ) ) {
			$this->assertTrue( $held, $label . ': I1, the file is held while the next run may rename (' . $phase . ')' );
		}
		if ( Maintenance::held_in( $this->abspath ) ) {
			$this->assertNotSame( Job::SITE_UNTOUCHED, $now->site_state, $label . ': I3, a held file only while a job holds the site (' . $phase . ')' );
			$this->assertNotSame( array(), Plugin::instance()->half_swapped_warnings(), $label . ': I3, and WP-CLI says so' );
		}
		if ( in_array( $phase, array( 'committed', 'done' ), true ) ) {
			$decided = 'committed';
		} elseif ( in_array( $phase, array( 'restored', 'reverted' ), true ) ) {
			$decided = 'restored';
		}
		if ( '' !== $decided && null !== $file && ! $held ) {
			$public = true;
		}
	}

	/**
	 * I2: the first seam that renames after the direction was recorded ("committed", "restored") in a list of seams,
	 * or after it was recorded before the list began; '' when there is none.
	 *
	 * @param string[] $passed  Seams passed, in order.
	 * @param bool     $decided Whether the direction was recorded before these.
	 * @return string
	 */
	private static function renamed_after_decision( array $passed, bool $decided = false ): string {
		foreach ( $passed as $point ) {
			if ( in_array( $point, self::DECIDING, true ) ) {
				$decided = true;
			} elseif ( $decided && in_array( $point, self::RENAMING, true ) ) {
				return $point;
			}
		}
		return '';
	}
}
