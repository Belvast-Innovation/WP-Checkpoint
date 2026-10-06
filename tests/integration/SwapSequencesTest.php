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
 * - I3: a held file of this plugin is there only while a job that holds the site has something left to do and was
 *   not abandoned, and every WP-CLI command of the plugin says so.
 * - I4: a job that has ended (abandoned too) leaves none of its files held at the location it ended from.
 * - Abandoned: a job given up from another location never runs again and nothing of it is reclaimed.
 *
 * Besides one sequence per seam, a fixed few (ELSEWHERE) where, after the kill, the job is managed by another
 * installation and seen from a copy of the site (another WordPress directory with a copy of the file): nothing
 * released there while it holds the site, then the job taken back by the original (rebind) and run to the end (once
 * also put back and retried while the copy still holds its file: the retry's attempt must keep the job's mark); or
 * the job given up (abandon), at once or after a run that died between its two steps.
 * - I5: visitors' writes after the file was let go are kept where they wrote them.
 * - I6: whenever a held maintenance file of this plugin with mark M is there (at the site, or at a copy of it), its
 *   job's row already says M (Job::$site_mark): a held file nobody can tell as a job's would never come down.
 *
 * Fixed: the seams in order, one sequence each (WPCHECKPOINT_SWAP_SEQUENCES more are drawn with
 * WPCHECKPOINT_SWAP_SEQUENCES_SEED). Every seam and every kind of interleaving must occur, counted where it happened.
 */
final class SwapSequencesTest extends SwapTestCase {

	/** The seams of the swap going forward (a run killed there once, then WP-CLI to the end). */
	const FORWARD = array( 'entered', 'maintenance', 'dir_aside', 'dir_aside_recorded', 'dir_in', 'carry_written', 'carried', 'batch_recorded', 'batch_sent', 'committed', 'flushed', 'unheld_commit', 'rewrite', 'cron', 'done_recorded', 'exited' );

	/** The seams of the rollback (a run killed between two batches first, then the next one killed there). */
	const BACKWARD = array( 'rollback', 'table_back', 'dir_back', 'dirs_back', 'restored', 'unheld', 'reverted', 'maintenance_down' );

	/**
	 * The seams of a swap that stops before any rename because the backup's tables are judged otherwise once the
	 * maintenance file is up (another installation's tables appear in the killed run at "maintenance"): the file comes
	 * down, then the site is recorded as untouched.
	 */
	const JUDGE = array( 'judge_file_down', 'judge_recorded' );

	/** The renames a killed run makes, by the seam right after each. */
	const RENAMED = array( 'dir_aside', 'dir_in', 'batch_sent', 'table_back', 'dir_back' );

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

	/**
	 * Sequences where the job is managed elsewhere after the kill (seam, what is done from a copy of the site): the
	 * direction not recorded, recorded as put back, recorded as made, and an abandon that died between its steps; and
	 * the direction not recorded, put back by the original and retried to the end with the copy's file still held.
	 */
	const ELSEWHERE = array(
		array( 'dir_aside_recorded', 'elsewhere_release' ),
		array( 'dir_aside_recorded', 'elsewhere_retry' ),
		array( 'restored', 'elsewhere_release' ),
		array( 'committed', 'elsewhere_abandon' ),
		array( 'table_back', 'elsewhere_abandon_killed' ),
	);

	public function test_the_scan_for_renames_after_the_direction_is_recorded_finds_them(): void {
		$this->assertSame( 'dir_in', self::renamed_after_decision( array( 'carried', 'committed', 'flushed', 'dir_in' ) ), 'in the same run' );
		$this->assertSame( 'table_back', self::renamed_after_decision( array( 'table_back' ), true ), 'in a run after one that recorded it' );
		$this->assertSame( '', self::renamed_after_decision( array( 'dir_in', 'batch_sent', 'committed', 'flushed', 'unheld_commit' ) ), 'the control: renames before it are fine' );
		$this->assertSame( '', self::renamed_after_decision( array( 'table_back', 'dir_back', 'restored', 'unheld' ) ) );
	}

	public function test_every_seam_and_every_interleaving_keeps_the_invariants(): void {
		$count = max( 1, (int) ( getenv( 'WPCHECKPOINT_SWAP_SEQUENCES' ) ?: 26 ) );
		$seed  = (int) ( getenv( 'WPCHECKPOINT_SWAP_SEQUENCES_SEED' ) ?: 1 );
		$seams = array_merge( self::FORWARD, self::BACKWARD, self::JUDGE );
		$this->assertSame( 26, count( $seams ), 'the default count is one sequence per seam' );
		$this->held_checks = array_fill_keys( self::AT_RENAME, 0 );
		mt_srand( $seed );
		$killed = array_fill_keys( $seams, 0 );
		$kinds  = array_fill_keys( array_merge( self::MEANWHILE, array( 'visitor' ), array_column( self::ELSEWHERE, 1 ) ), 0 );
		for ( $n = 0; $n < $count + count( self::ELSEWHERE ); $n++ ) {
			$seam  = $n < count( $seams ) ? $seams[ $n ] : ( $n < $count ? $seams[ mt_rand( 0, count( $seams ) - 1 ) ] : self::ELSEWHERE[ $n - $count ][0] );
			$kind  = $n < $count ? self::MEANWHILE[ $n % count( self::MEANWHILE ) ] : self::ELSEWHERE[ $n - $count ][1];
			$label = sprintf( '#%d %s/%s', $n, $seam, $kind );
			$this->sequence( $seam, $kind, $label, $killed, $kinds );
			$this->release_backups();
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
		$this->assertGreaterThan( 0, $this->mark_checks, 'the control: I6 met held files' );
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
		if ( in_array( $seam, self::JUDGE, true ) ) {
			$this->child_sql = array(
				'seam' => 'maintenance',
				'sql'  => self::neighbour_sql(),
			);
		}
		if ( 'clock' === $kind ) {
			$this->clock_offset = -660; // The killed run wrote its times eleven minutes ago.
		}
		$this->killed_at( $job, $seam, 1 );
		$passed = array_values( array_filter( explode( "\n", (string) file_get_contents( $this->trace ) ) ) );
		$this->assertSame( $seam, end( $passed ), $label . ': the run died at its seam' );
		++$killed[ $seam ];
		$this->child_sql = array();
		if ( in_array( $seam, self::JUDGE, true ) ) {
			$this->assertSame( array(), array_values( array_intersect( $passed, self::RENAMED ) ), $label . ': judged otherwise once the file was up, nothing renamed' );
		}
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

		if ( 0 === strpos( $kind, 'elsewhere_' ) && ! $this->elsewhere( $job, $kind, $label, $kinds ) ) {
			// Abandoned: it never runs again, and the site it held stays as it is; undone here, as at the end.
			$this->copy = '';
			$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}swt_gone`" );
			$this->trace = '';
			return;
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
					$this->marked( $job, $label . ' at ' . $point );
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
		$done     = null;
		$runs     = array();
		$renamed  = false;
		$at_retry = null;
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
			if ( 'elsewhere_retry' === $kind && Job::FAILED === $done->status && 0 === $kinds[ $kind ] ) {
				// Put back by the original; retried while the copy still holds the file the killed run left there.
				$this->assertTrue( $done->retry_useful(), $label . ': put back, it may be retried (' . $done->last_error . ')' );
				$this->assertTrue( Maintenance::held_in( $this->copy ), $label . ': the control, the copy still holds its file' );
				$this->assertSame( Job::QUEUED, Plugin::instance()->job_actions()->retry( $job->id )->status, $label . ': retried' );
				$at_retry = $this->copy_checks;
				++$kinds[ $kind ];
				$this->observe( $job, $label . ' (retried)', $decided, $public );
				// A new attempt: the direction the last one recorded (put back) is not this one's (I2 starts again).
				$decided = '';
				$public  = false;
				continue;
			}
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
		if ( 'elsewhere_retry' === $kind ) {
			$this->assertNotNull( $at_retry, $label . ': the control, it was retried' );
			$this->assertSame( Job::COMPLETED, $done->status, $label . ': the retry ran to the end (' . $done->last_error . ')' );
			$this->assertGreaterThan( $at_retry, $this->copy_checks, $label . ': I6 met the copy\'s held file during the retry' );
		}
		if ( $cancelled ) {
			$this->assertSame( Job::CANCELLED, $done->status, $label . ': a cancel that was taken ends the job cancelled' );
		}
		// I4: an ended job leaves none of its files held.
		$this->assertFalse( Maintenance::held_in( $this->abspath ), $label . ': no held file once the job ended (' . $done->status . ')' );
		$this->marked( $job, $label . ' (ended)' );
		if ( '' !== $this->copy ) {
			// The copy's file, the job ended: released by the mark the job's row kept, whether the database is shared.
			$ended = Plugin::instance()->jobs()->find( $job->id );
			$this->assertNotSame( '', $ended->site_mark, $label . ': the mark kept once the job ended' );
			$release = $this->from_copy()->release( $job->id, \WPCheckpoint\Jobs\HeldSite::code( \WPCheckpoint\Jobs\HeldSite::RELEASE, $ended, (string) ( new \WPCheckpoint\Jobs\HeldSite( array( 'abspath' => $this->copy ) ) )->assess( $ended )['recorded'] ) );
			$this->assertTrue( $release['ok'], $label . ': ' . $release['message'] );
			$this->assertFalse( Maintenance::held_in( $this->copy ), $label . ': the copy\'s file is down once the job ended' );
			$this->copy = '';
		}
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
		foreach ( self::NEIGHBOUR as $marker ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}swt_{$marker}`" );
		}
		$this->trace = '';
	}

	/** The tables of another installation under "{prefix}swt_" (JUDGE). */
	const NEIGHBOUR = array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships' );

	/**
	 * The statements that make another installation's tables under "{prefix}swt_": the backup's swt_ tables become
	 * ones that may be either installation's.
	 *
	 * @return string[]
	 */
	private static function neighbour_sql(): array {
		global $wpdb;
		$out = array();
		foreach ( self::NEIGHBOUR as $marker ) {
			$out[] = "CREATE TABLE `{$wpdb->prefix}swt_{$marker}` (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB";
		}
		return $out;
	}

	/**
	 * After the kill, the job managed by another installation and seen from a copy of the site (another WordPress
	 * directory, with a copy of this job's file when the site had it): released there and taken back by the original,
	 * or abandoned (at once, or after a run that died between the two steps). False when it was abandoned (the
	 * sequence ends: the job is checked never to run again, and undone by the test).
	 *
	 * @param Job                $job   Job.
	 * @param string             $kind  One of the kinds of ELSEWHERE.
	 * @param string             $label For the messages.
	 * @param array<string, int> $kinds Interleavings per kind (counted where they happened).
	 * @return bool
	 */
	private function elsewhere( Job $job, string $kind, string $label, array &$kinds ): bool {
		global $wpdb;
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertNotSame( Job::SITE_UNTOUCHED, $now->site_state, $label . ': the control, it holds the site' );
		$this->assertContains( $now->status, array( Job::QUEUED, Job::RUNNING ), $label . ': the control, not ended' );
		$wpdb->update( \WPCheckpoint\Jobs\JobRepository::table(), array( 'held_by' => 'ffffffffffff' ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$copy       = $this->sandbox . '/copy';
		$this->copy = $copy;
		mkdir( $copy . '/wp-content/uploads', 0755, true );
		$file = new Maintenance( $this->abspath, (string) ( $now->cursor['mark'] ?? '' ) );
		$had  = Maintenance::OURS === $file->state();
		if ( $had ) {
			copy( $file->path(), $copy . '/.maintenance' );
		}
		$there = new \WPCheckpoint\Jobs\JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new \WPCheckpoint\Jobs\Loopback( false ),
			new \WPCheckpoint\Jobs\HeldSite(
				array(
					'abspath'   => $copy,
					'site_dirs' => static function () use ( $copy ): array {
						return array( 'uploads' => $copy . '/wp-content/uploads' );
					},
				)
			)
		);
		$held = $there->held_elsewhere( $job->id );
		$this->assertNotNull( $held, $label . ': managed elsewhere' );
		$see = $held['assessment'];
		$this->assertSame( \WPCheckpoint\Jobs\HeldSite::OTHER, $see['branch'], $label . ': not the copy\'s' );
		$this->assertSame( $had, $see['file_here'], $label . ': the copy has the file the site had' );
		$row = static function () use ( $job ): array {
			$job = Plugin::instance()->jobs()->find( $job->id );
			return array( $job->status, $job->site_state, $job->held_by, $job->cursor );
		};
		if ( in_array( $kind, array( 'elsewhere_release', 'elsewhere_retry' ), true ) ) {
			// While the job holds the site, nothing is released from the copy: it stays behind its maintenance page.
			$before = $row();
			$this->assertFalse( $there->release( $job->id, \WPCheckpoint\Jobs\HeldSite::code( \WPCheckpoint\Jobs\HeldSite::RELEASE, $held['job'], $see['recorded'] ) )['ok'], $label . ': nothing released while it holds the site' );
			$this->assertSame( $had, Maintenance::held_in( $copy ), $label . ': the copy keeps its file' );
			$this->assertSame( $before, $row(), $label . ': the job is not changed' );
		}
		if ( 'elsewhere_abandon_killed' === $kind ) {
			// An abandon that died between its two steps: the file down, the job not yet abandoned.
			$dying = new \WPCheckpoint\Jobs\JobActions(
				Plugin::instance()->jobs(),
				Plugin::instance()->runner(),
				new \WPCheckpoint\Jobs\Loopback( false ),
				new \WPCheckpoint\Jobs\HeldSite(
					array(
						'abspath'   => $copy,
						'site_dirs' => static function () use ( $copy ): array {
							return array( 'uploads' => $copy . '/wp-content/uploads' );
						},
						'at'        => static function ( string $point ): void {
							throw new \RuntimeException( 'died at ' . $point );
						},
					)
				)
			);
			try {
				$dying->abandon( $job->id, \WPCheckpoint\Jobs\HeldSite::code( \WPCheckpoint\Jobs\HeldSite::ABANDON, Plugin::instance()->jobs()->find( $job->id ), $see['recorded'] ) );
				$this->fail( $label . ': the abandon died between its steps' );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'died at abandon_released', $e->getMessage(), $label );
			}
			$this->assertFalse( Maintenance::held_in( $copy ), $label . ': the first step was done' );
			$this->assertContains( $job->id, Plugin::instance()->jobs()->holding_site(), $label . ': still holding, still warned about' );
		}
		if ( ! in_array( $kind, array( 'elsewhere_release', 'elsewhere_retry' ), true ) ) {
			$staged = $this->staging_of( $job );
			$tables = $this->job_tables( $job );
			$this->assertTrue( $there->abandon( $job->id, \WPCheckpoint\Jobs\HeldSite::code( \WPCheckpoint\Jobs\HeldSite::ABANDON, Plugin::instance()->jobs()->find( $job->id ), $see['recorded'] ) )['ok'], $label . ': abandoned' );
			++$kinds[ $kind ];
			$this->assertFalse( Maintenance::held_in( $copy ), $label . ': I4, no held file at the location it ended from' );
			$ended = Plugin::instance()->jobs()->find( $job->id );
			$this->assertSame( Job::REASON_ABANDONED, $ended->failure_reason, $label );
			$this->assertNotContains( $job->id, Plugin::instance()->jobs()->holding_site(), $label . ': I3, no longer said to hold the site' );
			$this->seams = array();
			$this->cli_tick( $ended );
			$this->assertSame( array(), $this->seams, $label . ': an abandoned job never runs' );
			$this->assertNotSame( array(), $tables, $label . ': the control, it made tables' );
			$this->assertSame( $tables, $this->job_tables( $ended ), $label . ': its tables are kept (the site it was started on may need them)' );
			$this->assertSame( $staged, $this->staging_of( $ended ), $label . ': nothing at the paths its plan records is touched' );
			return false;
		}
		if ( 'elsewhere_release' === $kind ) {
			++$kinds[ $kind ]; // The retry is counted where it was made.
		}
		// Taken back by the original: this site's, its directories and prefix.
		$here = new \WPCheckpoint\Jobs\JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new \WPCheckpoint\Jobs\Loopback( false ),
			new \WPCheckpoint\Jobs\HeldSite(
				array(
					'abspath'   => $this->abspath,
					'site_dirs' => function (): array {
						return $this->dirs;
					},
				)
			)
		);
		$back = $here->held_elsewhere( $job->id );
		$this->assertSame( \WPCheckpoint\Jobs\HeldSite::SITE, $back['assessment']['branch'], $label . ': the original\'s: ' . $back['assessment']['why'] );
		$then = '' === $back['assessment']['direction'] ? 'continue' : '';
		$this->assertTrue( $here->rebind( $job->id, \WPCheckpoint\Jobs\HeldSite::code( \WPCheckpoint\Jobs\HeldSite::REBIND, $back['job'], '' ), $then )['ok'], $label . ': taken back' );
		return true;
	}

	/** @var string The copy of the site of an ELSEWHERE sequence ('' for none). */
	private $copy = '';

	/**
	 * Actions as the copy of the site sees them.
	 *
	 * @return \WPCheckpoint\Jobs\JobActions
	 */
	private function from_copy(): \WPCheckpoint\Jobs\JobActions {
		$copy = $this->copy;
		return new \WPCheckpoint\Jobs\JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new \WPCheckpoint\Jobs\Loopback( false ),
			new \WPCheckpoint\Jobs\HeldSite(
				array(
					'abspath'   => $copy,
					'site_dirs' => static function () use ( $copy ): array {
						return array( 'uploads' => $copy . '/wp-content/uploads' );
					},
				)
			)
		);
	}

	/**
	 * I6: every held maintenance file of this plugin there (at the site, at a copy of it) carries the mark its job's row
	 * says. Counted where a held file was there (the control: the check met one).
	 *
	 * @param Job    $job   Job.
	 * @param string $label For the messages.
	 * @return void
	 */
	private function marked( Job $job, string $label ): void {
		foreach ( array_filter( array( $this->abspath, $this->copy ) ) as $dir ) {
			$mark = Maintenance::mark_in( $dir );
			if ( '' === $mark || ! ( new Maintenance( $dir, $mark ) )->is_held() ) {
				continue;
			}
			++$this->mark_checks;
			if ( $dir === $this->copy ) {
				++$this->copy_checks;
			}
			$this->assertSame( $mark, Plugin::instance()->jobs()->find( $job->id )->site_mark, $label . ': I6, the held file\'s mark is its job\'s' );
		}
	}

	/**
	 * A job's tables by the names it made: temporary, and moved aside by its swap.
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	private function job_tables( Job $job ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$out = array();
		foreach ( array( \WPCheckpoint\Jobs\TempTables::job_prefix( $job->storage_token, $job->id ), \WPCheckpoint\Jobs\TempTables::OLD_PREFIX . substr( $job->storage_token, 0, \WPCheckpoint\Jobs\TempTables::TOKEN_LEN ) . '_' . $job->id . '_' ) as $prefix ) {
			$out = array_merge( $out, (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) );
		}
		sort( $out );
		return $out;
	}

	/** @var int I6 checks that met a held file. */
	private $mark_checks = 0;

	/** @var int I6 checks that met a held file at the copy of the site. */
	private $copy_checks = 0;

	/**
	 * The kinds of the staging a job left next to the site's directories (by the names it made).
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	private function staging_of( Job $job ): array {
		$out = array();
		foreach ( \WPCheckpoint\Jobs\Residue::scan_site( \WPCheckpoint\Jobs\Residue::site_dirs( $this->dirs ), array( $job->storage_token ) ) as $entry ) {
			if ( $entry['id'] === $job->id ) {
				$out[] = $entry['kind'] . ' ' . $entry['path'];
			}
		}
		sort( $out );
		return $out;
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
		$this->marked( $job, $label );
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
