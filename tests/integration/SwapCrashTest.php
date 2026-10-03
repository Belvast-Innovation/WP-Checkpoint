<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The swap's state table, a process killed (SIGKILL) at each of its seams:
 * the next WP-CLI run decides from the cursor and from what is there, and
 * ends with the site as it was (killed before the tables were all renamed:
 * the job failed, a retry starting at the final check) or with the
 * restored site in place (killed after). Nothing of the killed run's
 * catch, finally or shutdown runs. Each case also looks at what the killed
 * run left, which shows that it died where the table says.
 */
final class SwapCrashTest extends SwapTestCase {

	/**
	 * Kill a run at a seam, look at what it left, then let WP-CLI run the job to its end.
	 *
	 * @param string        $seam    Seam.
	 * @param int|callable  $nth     Which time (or function( array $plan ): int).
	 * @param callable|null $inspect function( Job $job, array $plan ): void, right after the kill.
	 * @return array{0: Job, 1: array<string, mixed>, 2: array<string, mixed>} The job, the site before the swap, the plan.
	 */
	private function crash( string $seam, $nth = 1, $inspect = null ): array {
		$job    = $this->at_swap();
		$before = $this->site();
		$plan   = $this->plan_of( $job );
		$this->killed_at( $job, $seam, is_callable( $nth ) ? (int) $nth( $plan ) : (int) $nth );
		if ( null !== $inspect ) {
			$inspect( Plugin::instance()->jobs()->find( $job->id ), $plan );
		}
		return array( $this->cli_run( $job ), $before, $plan );
	}

	/**
	 * The job failed with the site as it was, a retry starting at the final check.
	 */
	private function assertPutBack( Job $done, array $before, string $why ): void {
		$this->assertSame( Job::FAILED, $done->status, $why . ': ' . $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state, $why );
		$this->assertSame( SwapCheckStep::ID, $done->cursor[ JobRepository::RETRY_FROM_KEY ] ?? null, $why );
		$this->assertStringContainsString( 'the site is as it was before the restore', (string) $done->last_error, $why );
		$this->assertSame( $before, $this->site(), $why . ': the site is as it was' );
	}

	/**
	 * The restored site is in place and the job completed.
	 */
	private function assertSwapped( Job $done, array $before, string $why ): void {
		$this->assertSame( Job::COMPLETED, $done->status, $why . ': ' . $done->last_error );
		$this->assertSame( Job::SITE_SWAPPED, $done->site_state, $why );
		$this->assertRestored( $before );
	}

	/**
	 * What the killed run recorded and left: its phase, its site state, and whether the maintenance file is up.
	 */
	private function assertLeft( Job $job, string $phase, int $state, bool $maintenance ): void {
		$this->assertSame( $phase, $job->cursor['phase'] ?? '', 'the killed run\'s last record' );
		$this->assertSame( $state, $job->site_state );
		$this->assertSame( $maintenance, file_exists( $this->abspath . '/.maintenance' ), 'the maintenance file' );
	}

	private static function there( string $path ): bool {
		clearstatcache( true, $path );
		return false !== @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test.
	}

	/**
	 * The index of the first directory unit that had no live directory.
	 */
	private static function first_new( array $plan ): int {
		foreach ( $plan['dirs'] as $i => $entry ) {
			if ( ! $entry['had_live'] ) {
				return $i;
			}
		}
		throw new \LogicException( 'the plan has no new unit' );
	}

	public function test_k1_killed_after_enter_was_recorded(): void {
		list( $done, $before ) = $this->crash(
			'entered',
			1,
			function ( Job $job ): void {
				$this->assertLeft( $job, 'enter', Job::SITE_CHANGING, false );
			}
		);
		$this->assertPutBack( $done, $before, 'K1' );
	}

	public function test_k2_killed_after_the_maintenance_file_was_put_up(): void {
		list( $done, $before ) = $this->crash(
			'maintenance',
			1,
			function ( Job $job ): void {
				$this->assertLeft( $job, 'enter', Job::SITE_CHANGING, true );
			}
		);
		$this->assertPutBack( $done, $before, 'K2' );
	}

	public function test_k3_killed_after_a_live_directory_was_moved_aside_first_middle_and_last(): void {
		foreach ( array( 'first', 'middle', 'last' ) as $which ) {
			$nth = static function ( array $plan ) use ( $which ): int {
				$live = array_keys( array_filter( array_column( $plan['dirs'], 'had_live' ) ) );
				$pick = 'first' === $which ? $live[0] : ( 'last' === $which ? $live[ count( $live ) - 1 ] : $live[ (int) floor( count( $live ) / 2 ) ] );
				return array_search( $pick, $live, true ) + 1;
			};
			list( $done, $before ) = $this->crash(
				'dir_aside',
				$nth,
				function ( Job $job, array $plan ) use ( $nth ): void {
					$live  = array_values( array_filter( $plan['dirs'], static function ( array $e ): bool {
						return $e['had_live'];
					} ) );
					$entry = $live[ $nth( $plan ) - 1 ];
					$this->assertLeft( $job, 'dirs', Job::SITE_CHANGING, true );
					$this->assertSame( 'a', $job->cursor['step'] );
					$this->assertFalse( self::there( $entry['live'] ), 'moved aside' );
					$this->assertTrue( self::there( $entry['old'] ) );
					$this->assertTrue( self::there( $entry['stage'] ), 'the staged copy not yet in' );
				}
			);
			$this->assertPutBack( $done, $before, 'K3 ' . $which );
			$this->undo( $done );
			$this->tear_down_swap();
			$this->set_up_swap();
		}
	}

	public function test_k4_killed_after_the_move_aside_was_recorded(): void {
		list( $done, $before ) = $this->crash(
			'dir_aside_recorded',
			2,
			function ( Job $job ): void {
				$this->assertLeft( $job, 'dirs', Job::SITE_CHANGING, true );
				$this->assertSame( 'b', $job->cursor['step'] );
			}
		);
		$this->assertPutBack( $done, $before, 'K4' );
	}

	public function test_k5_killed_after_the_staged_copy_took_the_place(): void {
		list( $done, $before ) = $this->crash(
			'dir_in',
			2,
			function ( Job $job, array $plan ): void {
				$this->assertLeft( $job, 'dirs', Job::SITE_CHANGING, true );
				$this->assertSame( 1, $job->cursor['i'] );
				$this->assertTrue( self::there( $plan['dirs'][1]['live'] ) );
				$this->assertFalse( self::there( $plan['dirs'][1]['stage'] ), 'the staged copy is in place' );
			}
		);
		$this->assertPutBack( $done, $before, 'K5' );
	}

	public function test_k5_prime_killed_after_a_unit_without_a_live_one_took_its_place(): void {
		list( $done, $before ) = $this->crash(
			'dir_in',
			static function ( array $plan ): int {
				return self::first_new( $plan ) + 1;
			},
			function ( Job $job, array $plan ): void {
				$entry = $plan['dirs'][ self::first_new( $plan ) ];
				$this->assertTrue( self::there( $entry['live'] ) );
				$this->assertFalse( self::there( $entry['old'] ), 'nothing was moved aside for it' );
			}
		);
		$this->assertPutBack( $done, $before, 'K5\'' );
		$this->assertArrayNotHasKey( 'upgrade/restored.txt', $this->site()['files'], 'the new unit went back to the staging root' );
	}

	public function test_k21_a_directory_made_in_the_sites_place_meanwhile_is_moved_out_of_the_way_and_kept(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$plan   = $this->plan_of( $job );
		$this->killed_at( $job, 'dir_aside', 1 );
		$entry = $plan['dirs'][0];
		mkdir( $entry['live'] );
		file_put_contents( $entry['live'] . '/someone.txt', 'made meanwhile' );
		$done = $this->cli_run( $job );
		$this->assertPutBack( $done, $before, 'K21' );
		$root  = dirname( dirname( $entry['old'] ) );
		$stray = glob( $root . '/stray/' . basename( $entry['live'] ) . '-*' );
		$this->assertCount( 1, $stray, 'moved out of the way, into the staging root' );
		$this->assertSame( 'made meanwhile', file_get_contents( $stray[0] . '/someone.txt' ) );
		$this->assertTrue( \WPCheckpoint\Jobs\Residue::keeps_stray( $root ), 'and the staging root is kept for it' );
	}

	public function test_k6_killed_inside_the_carry_before_it_was_committed(): void {
		list( $done, $before ) = $this->crash(
			'carry_written',
			1,
			function ( Job $job ): void {
				$this->assertLeft( $job, 'carry', Job::SITE_CHANGING, true );
			}
		);
		$this->assertPutBack( $done, $before, 'K6' );
	}

	public function test_k7_killed_after_the_carry_was_committed(): void {
		list( $done, $before ) = $this->crash(
			'carried',
			1,
			function ( Job $job ): void {
				$this->assertLeft( $job, 'carry', Job::SITE_CHANGING, true );
			}
		);
		$this->assertPutBack( $done, $before, 'K7' );
	}

	public function test_k8_killed_after_the_rename_was_recorded_before_it_was_sent(): void {
		list( $done, $before ) = $this->crash(
			'batch_recorded',
			1,
			function ( Job $job ): void {
				$this->assertLeft( $job, 'rename', Job::SITE_CHANGING, true );
				$this->assertSame( 'live', $this->site()['tables']['swt_keep'][0]['v'], 'no table renamed yet' );
			}
		);
		$this->assertPutBack( $done, $before, 'K8' );
	}

	public function test_k9_killed_after_every_table_was_renamed_before_it_was_recorded(): void {
		list( $done, $before ) = $this->crash(
			'batch_sent',
			1,
			function ( Job $job ): void {
				$this->assertLeft( $job, 'rename', Job::SITE_CHANGING, true );
				$this->assertSame( 'backup', $this->site()['tables']['swt_keep'][0]['v'], 'every table renamed' );
			}
		);
		$this->assertSwapped( $done, $before, 'K9' );
	}

	public function test_k10_double_prime_killed_between_two_batches(): void {
		$this->swap_parts['batch'] = 1; // Every entry a batch of its own.
		$this->register_type();
		list( $done, $before, $plan ) = $this->crash(
			'batch_sent',
			1,
			function ( Job $job ): void {
				$this->assertLeft( $job, 'rename', Job::SITE_CHANGING, true );
				$this->assertGreaterThan( 1, $job->cursor['batches'], 'the control: several batches' );
				$this->assertSame( 0, $job->cursor['batch'] );
			}
		);
		$this->assertGreaterThan( 2, count( $plan['tables'] ) );
		$this->assertPutBack( $done, $before, 'K10\'\'' );
	}

	public function test_k10_half_of_a_batch_renamed_by_a_server_that_renames_table_by_table(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$plan   = $this->plan_of( $job );
		$this->killed_at( $job, 'batch_recorded', 1 );
		// The first entry swapped, the others not: what a server without atomic DDL may leave of one statement.
		$first = $plan['tables'][0];
		$wpdb->query( "RENAME TABLE `{$first['live']}` TO `{$first['old']}`, `{$first['stage']}` TO `{$first['live']}`" );
		$done = $this->cli_run( $job );
		$this->assertPutBack( $done, $before, 'K10' );
	}

	public function test_k10_prime_a_listing_that_cannot_be_read_is_waited_out_then_decided(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', 1 );
		$reads = 0;
		$this->retype(
			$job,
			array(),
			static function () use ( &$reads ) {
				return new class( \WPCheckpoint\Restore\ImportSession::open( \WPCheckpoint\Standalone\Credentials::from_wordpress() ), $reads ) implements \WPCheckpoint\Restore\Queries {
					/** @var \WPCheckpoint\Restore\ImportSession */
					private $db;
					/** @var int */
					private $reads;
					public function __construct( $db, &$reads ) {
						$this->db    = $db;
						$this->reads = &$reads;
					}
					public function run( string $sql ): int {
						return $this->db->run( $sql );
					}
					public function rows( string $sql, array $params = array() ): array {
						if ( false !== strpos( $sql, 'information_schema.TABLES' ) ) {
							++$this->reads;
							throw new \WPCheckpoint\Jobs\TransientFailure( 'the listing is refused' );
						}
						return $this->db->rows( $sql, $params );
					}
					public function write( string $sql, array $params ): int {
						return $this->db->write( $sql, $params );
					}
				};
			}
		);
		$result = $this->cli_tick( $job );
		$this->assertSame( TickResult::WAITING, $result->status, $result->message );
		$this->assertGreaterThan( 0, $reads, 'the control: the listing was tried' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'rename', $now->cursor['phase'], 'nothing decided' );
		$this->assertSame( 'backup', $this->site()['tables']['swt_keep'][0]['v'], 'nothing renamed back' );
		$this->retype( $job );
		$this->assertSwapped( $this->cli_run( $job ), $before, 'K10\' after the listing could be read' );
	}

	public function test_k11_to_k15_killed_after_the_swap_was_made_it_goes_forward(): void {
		foreach ( array( 'committed', 'flushed', 'rewrite', 'cron', 'done_recorded', 'exited' ) as $seam ) {
			list( $done, $before ) = $this->crash(
				$seam,
				1,
				function ( Job $job ) use ( $seam ): void {
					$this->assertSame( Job::SITE_SWAPPED, $job->site_state, $seam );
					// Recorded as done before the maintenance file comes down, the last step.
					$this->assertSame( in_array( $seam, array( 'done_recorded', 'exited' ), true ) ? 'done' : 'committed', $job->cursor['phase'], $seam );
					$this->assertSame( 'exited' !== $seam, file_exists( $this->abspath . '/.maintenance' ), 'the file is up until the last step: ' . $seam );
				}
			);
			$this->assertSwapped( $done, $before, $seam );
			$this->undo( $done );
			$this->tear_down_swap();
			$this->set_up_swap();
		}
	}

	public function test_k16_k17_killed_while_the_swap_was_rolled_back(): void {
		global $wpdb;
		foreach ( array( array( 'table_back', 1 ), array( 'dir_back', 1 ), array( 'dirs_back', 1 ), array( 'restored', 1 ), array( 'unheld', 1 ), array( 'reverted', 1 ), array( 'maintenance_down', 1 ) ) as $case ) {
			$this->swap_parts['batch'] = 1;
			$this->register_type();
			$job    = $this->at_swap();
			$before = $this->site();
			// A table made under a restored name since the check: the last batch fails after the others were sent,
			// and the swap rolls back in the same tick.
			$this->create( $wpdb->prefix . 'swt_new', '(id INT UNSIGNED NOT NULL PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB' );
			$wpdb->query( 'COMMIT' );
			$before['tables']['swt_new'] = array();
			$this->killed_at( $job, $case[0], $case[1] );
			$left = Plugin::instance()->jobs()->find( $job->id );
			// Recorded as put back before the maintenance file comes down, the last step.
			$phases = array(
				'restored'         => 'restored',
				'unheld'           => 'restored',
				'reverted'         => 'reverted',
				'maintenance_down' => 'reverted',
			);
			$this->assertSame( $phases[ $case[0] ] ?? 'rollback', $left->cursor['phase'], $case[0] );
			$this->assertSame( 'maintenance_down' !== $case[0], file_exists( $this->abspath . '/.maintenance' ), 'up until the last step: ' . $case[0] );
			$done = $this->cli_run( $job );
			$this->assertPutBack( $done, $before, 'killed at ' . $case[0] );
			$this->undo( $done );
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}swt_new`" );
			$this->tear_down_swap();
			$this->set_up_swap();
		}
	}

	public function test_k23_a_run_that_lost_the_job_stops_at_its_next_lease_check_and_the_next_one_rolls_back(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$taken  = false;
		$this->retype(
			$job,
			array(
				'at' => function ( string $point ) use ( $job, &$taken ): void {
					$this->seams[] = $point;
					if ( 'dir_in' === $point && ! $taken ) {
						global $wpdb;
						$taken = true;
						// Another run took the job over (its lease ran out): a new token.
						$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET lock_token = %s, locked_until = %d WHERE id = %d', 'someone-else', time() - 1, $job->id ) );
					}
				},
			)
		);
		$result = $this->cli_tick( $job );
		$this->assertTrue( $taken, 'the control: the job was taken over during the swap' );
		$this->assertSame( TickResult::LOST, $result->status, $result->message );
		$this->assertSame( 1, count( array_keys( $this->seams, 'dir_in', true ) ), 'no rename after the lease was lost' );
		$this->assertNotContains( 'rollback', $this->seams, 'the run that lost the job did not roll back' );
		$this->retype( $job );
		$this->assertPutBack( $this->cli_run( $job ), $before, 'K23' );
	}

	public function test_k24_the_rollback_needs_neither_the_work_directory_nor_the_storage_directory(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		$work = $this->work( $job );
		\WPCheckpoint\Support\Deleter::delete_tree( dirname( $work ), $work );
		$this->assertDirectoryDoesNotExist( $work, 'the control: the work directory is gone' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET storage_path = %s WHERE id = %d', $this->sandbox . '/storage-that-is-not-there', $job->id ) );
		$this->assertPutBack( $this->cli_run( $job ), $before, 'K24' );
	}

	public function test_k25_no_other_driver_moves_or_ends_a_swap_under_way(): void {
		$job    = $this->at_swap();
		$this->killed_at( $job, 'dir_in', 2 );
		$left   = Plugin::instance()->jobs()->find( $job->id );
		$site   = $this->site();
		$result = Plugin::instance()->runner()->tick( $job->id, microtime( true ) );
		$this->assertSame( TickResult::CLI, $result->status );
		Plugin::instance()->jobs()->reap();
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( $left->cursor, $now->cursor );
		$this->assertSame( $left->status, $now->status );
		$this->assertSame( Job::SITE_CHANGING, $now->site_state );
		$this->assertSame( $site, $this->site(), 'nothing changed' );
		$this->assertSame( Job::FAILED, $this->cli_run( $job )->status, 'the control: WP-CLI rolls it back' );
	}

	public function test_k26_a_cancel_during_the_swap_is_a_request_the_rollback_answers(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		$this->assertSame( 'requested', Plugin::instance()->job_actions()->cancel( $job->id )['reason'] ?? null );
		$asked = Plugin::instance()->jobs()->find( $job->id );
		$this->assertNotSame( 0, $asked->cancel_requested );
		$this->assertSame( Job::SITE_CHANGING, $asked->site_state, 'only asked: nothing was undone by the cancel itself' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::CANCELLED, $done->status, (string) $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site() );
	}

	public function test_k26_a_cancel_after_the_swap_was_made_is_refused(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'cron', 1 );
		$refused = Plugin::instance()->job_actions()->cancel( $job->id );
		$this->assertSame( 'swapped', $refused['reason'] ?? null );
		$this->assertSame( 0, Plugin::instance()->jobs()->find( $job->id )->cancel_requested );
		$this->assertSwapped( $this->cli_run( $job ), $before, 'K26 after the swap' );
	}

	public function test_k27_the_maintenance_file_is_held_while_the_site_is_half_swapped_and_refreshed_before_and_after(): void {
		$clock = 1800000000;
		$times = array();
		$this->swap_parts['now'] = static function () use ( &$clock ): int {
			return ++$clock;
		};
		$this->swap_parts['at']  = function ( string $point ) use ( &$times, &$clock ): void {
			$this->seams[] = $point;
			if ( in_array( $point, array( 'maintenance', 'dir_aside', 'dir_in', 'carried', 'batch_recorded', 'flushed', 'rewrite', 'cron' ), true ) ) {
				$file = new Maintenance( $this->abspath, $this->swap_job->cursor['mark'] ?? Plugin::instance()->jobs()->find( $this->swap_job->id )->cursor['mark'] );
				$text = (string) file_get_contents( $this->abspath . '/.maintenance' );
				$time = $file->time_of( $text );
				$this->assertNotNull( $time, 'this restore\'s file is up at ' . $point );
				$this->assertStringNotContainsString( (string) Plugin::instance()->directories()->state()['token'], $text, 'the file in the web root tells nothing of the storage directory' );
				$this->assertStringContainsString( 'WP Checkpoint restore ', $text, 'the control: the mark is read' );
				$times[] = array( $point, $time, $clock );
			}
		};
		$this->register_type();
		$job  = $this->at_swap();
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertGreaterThan( 5, count( $times ), 'the control: the seams were seen' );
		$last = 0;
		foreach ( $times as $seen ) {
			if ( in_array( $seen[0], array( 'dir_aside', 'dir_in', 'carried', 'batch_recorded', 'flushed' ), true ) ) {
				$this->assertSame( Maintenance::held(), $seen[1], 'held while the site is half swapped, and until the cache is flushed: ' . $seen[0] );
				continue;
			}
			// Before the first change and after the swap is made: refreshed with the time, as always.
			$this->assertLessThan( Maintenance::held(), $seen[1], $seen[0] );
			$this->assertGreaterThan( $last, $seen[1], 'refreshed before ' . $seen[0] );
			$last = $seen[1];
		}
	}

	public function test_a_table_made_under_a_moved_aside_name_is_moved_out_of_the_way_and_kept(): void {
		global $wpdb;
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$plan   = $this->plan_of( $job );
		$moves  = array_keys( array_filter( array_column( $plan['tables'], 'kind' ), static function ( string $kind ): bool {
			return \WPCheckpoint\Restore\SwapPlan::MOVE === $kind;
		} ) );
		$this->assertNotSame( array(), $moves, 'the control: the plan moves swt_gone aside' );
		$this->killed_at( $job, 'batch_sent', $moves[0] + 1 );
		$this->assertNull( $this->site()['tables']['swt_gone'], 'moved aside' );
		$this->create( $wpdb->prefix . 'swt_gone', '(id INT UNSIGNED NOT NULL PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB' );
		$wpdb->query( "INSERT INTO `{$wpdb->prefix}swt_gone` (id, v) VALUES (5, 'someone')" );
		$wpdb->query( 'COMMIT' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'the old site\'s table is back in its place' );
		$stray = (array) $wpdb->get_col( "SHOW TABLES LIKE 'wcpstray%'" );
		$this->assertCount( 1, $stray, 'the table in the way was moved, not dropped' );
		$this->assertTrue( \WPCheckpoint\Database\OwnTables::generated( $stray[0] ), 'under a name of the grammar' );
		$this->assertSame( array( array( 'id' => '5', 'v' => 'someone' ) ), $wpdb->get_results( "SELECT id, v FROM `{$stray[0]}`", ARRAY_A ) );
		$this->assertStringContainsString( 'moved out of the way and kept', (string) file_get_contents( $done->storage_path . '/' . ( '' !== $done->log_path ? $done->log_path : 'logs/job-' . $done->id . '.log' ) ), 'and logged' );
	}

	public function test_a_swap_whose_plan_is_gone_keeps_its_retry_which_puts_the_site_back_once_the_plan_is_there(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		$table = $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE;
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE job_id = %d", $job->id ), ARRAY_A );
		$this->assertNotSame( array(), $rows );
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE job_id = %d", $job->id ) );
		$wpdb->query( 'COMMIT' );
		$failed = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertStringContainsString( 'The swap\'s plan is gone', (string) $failed->last_error );
		$this->assertNotSame( Job::FAILURE_FINAL, $failed->failure_kind, 'not final: the retry is what puts the site back' );
		$this->assertSame( Job::SITE_CHANGING, $failed->site_state );
		foreach ( $rows as $row ) {
			$wpdb->insert( $table, $row );
		}
		$wpdb->query( 'COMMIT' );
		Plugin::instance()->job_actions()->retry( $job->id );
		$this->assertPutBack( $this->cli_run( $job ), $before, 'after the retry' );
	}

	/**
	 * Retry a job that failed with a retry from the final check, then run it to its end.
	 */
	private function retried( Job $job ): Job {
		Plugin::instance()->job_actions()->retry( $job->id );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$runner = Plugin::instance()->runner(); // The final check runs on any driver; the swap stops it for WP-CLI.
		for ( $i = 0; $i < 200; $i++ ) {
			$result = $this->autocommit(
				static function () use ( $runner, $job ) {
					return $runner->tick( $job->id, microtime( true ) );
				}
			);
			if ( TickResult::CLI === $result->status || ! in_array( Plugin::instance()->jobs()->find( $job->id )->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
		}
		return $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
	}

	public function test_a_retry_after_a_rollback_that_came_after_the_carry_completes_the_restore(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		// The live site has one of this plugin's rows the backup has not: the carry adds it to the restored options.
		add_option( \WPCheckpoint\Support\StoredNames::reclaim_message( 987654 ), 'carried over' );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->killed_at( $job, 'carried', 1 );
		$done = $this->cli_run( $job );
		$this->assertPutBack( $done, $before, 'K7' );
		$ledger = TempTables::ledger( $done->storage_token, $done->id, (string) \WPCheckpoint\Jobs\RestorePreflightStep::load_plan( $this->work( $done ) )['random'] );
		$this->assertNotSame( '0', (string) $GLOBALS['wpdb']->get_var( "SELECT SUM(carried) FROM `{$ledger}`" ), 'the control: the carry changed the rows of the restored options' );
		$this->assertSwapped( $this->retried( $done ), $before, 'the retry, from the final check' );
		delete_option( \WPCheckpoint\Support\StoredNames::reclaim_message( 987654 ) );
	}

	public function test_a_retry_after_the_database_was_busy_completes_the_restore(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$other  = \WPCheckpoint\Restore\ImportSession::open( \WPCheckpoint\Standalone\Credentials::from_wordpress() );
		$other->run( 'LOCK TABLES `' . $wpdb->prefix . 'swt_keep` READ' );
		try {
			$done = $this->cli_run( $job );
		} finally {
			$other->run( 'UNLOCK TABLES' );
			$other->close();
		}
		$this->assertStringContainsString( 'The database is busy', (string) $done->last_error );
		$this->assertContains( 'carried', $this->seams, 'the control: the carry ran before the tables were tried' );
		$this->assertSwapped( $this->retried( $done ), $before, 'the retry once the lock is gone' );
	}

	public function test_the_cache_is_flushed_after_the_swap_twice_and_after_a_rollback(): void {
		$flushed = 0;
		$this->swap_parts['flush'] = static function () use ( &$flushed ): void {
			++$flushed;
		};
		$this->register_type();
		$job  = $this->at_swap();
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertSame( 2, $flushed, 'right after the swap, and again before the maintenance file comes down' );
		$this->undo( $done );
		$this->tear_down_swap();
		$this->set_up_swap();
		$flushed = 0;
		$this->swap_parts['flush'] = static function () use ( &$flushed ): void {
			++$flushed;
		};
		$this->register_type();
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in', 2 );
		$this->assertSame( Job::FAILED, $this->cli_run( $job )->status );
		$this->assertSame( 1, $flushed, 'after the rollback' );
	}

	public function test_a_run_that_lost_the_job_during_the_rollback_leaves_the_maintenance_file_up(): void {
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in', 2 );
		$taken = false;
		$this->retype(
			$job,
			array(
				'at' => function ( string $point ) use ( $job, &$taken ): void {
					$this->seams[] = $point;
					if ( 'dirs_back' === $point ) {
						global $wpdb;
						$taken = true;
						$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET lock_token = %s, locked_until = %d WHERE id = %d', 'someone-else', time() + 60, $job->id ) );
					}
				},
			)
		);
		$result = $this->cli_tick( $job );
		$this->assertTrue( $taken, 'the control: the job was taken over at the end of the rollback' );
		$this->assertSame( TickResult::LOST, $result->status );
		$this->assertFileExists( $this->abspath . '/.maintenance', 'the run that lost the job does not take it down' );
	}

	public function test_something_made_in_the_place_of_a_new_unit_is_never_written_over(): void {
		$job    = $this->at_swap();
		$plan   = $this->plan_of( $job );
		$entry  = $plan['dirs'][ self::first_new( $plan ) ];
		$before = $this->site();
		file_put_contents( $entry['live'], 'made since the plan' );
		$before['files'][ substr( $entry['live'], strlen( $this->dirs['other-content'] ) + 1 ) ] = 'made since the plan';
		ksort( $before['files'] );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'that was not there when the swap planned', (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'kept, and the site as it was' );
	}

	public function test_a_plan_entry_the_check_does_not_write_renames_nothing(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$plan   = $this->plan_of( $job );
		$table  = $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE;
		// An entry pointed at a file elsewhere (a forged row): the swap must not move it.
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET live = %s WHERE job_id = %d AND seq = %d", $this->dirs['mu-plugins'] . '/live-mu.php', $job->id, $plan['dirs'][0]['seq'] ) );
		$wpdb->query( 'COMMIT' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'an entry the final check does not write', (string) $done->last_error );
		$this->assertNotSame( Job::FAILURE_FINAL, $done->failure_kind );
		$this->assertSame( $before, $this->site(), 'nothing renamed' );
		$this->assertNotContains( 'entered', $this->seams );
	}

	public function test_a_cache_that_cannot_be_flushed_after_the_rollback_is_waited_out_with_the_site_back(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		$this->retype(
			$job,
			array(
				'flush' => static function (): void {
					throw new \RuntimeException( 'the cache server is away' );
				},
			)
		);
		$result = $this->cli_tick( $job );
		$this->assertSame( TickResult::WAITING, $result->status, $result->message );
		$this->assertStringContainsString( 'The object cache could not be flushed', $result->message );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'rollback', $now->cursor['phase'], 'not yet recorded as put back' );
		$this->assertTrue( file_exists( $this->abspath . '/.maintenance' ), 'the file stays up meanwhile' );
		$this->retype( $job );
		$this->assertPutBack( $this->cli_run( $job ), $before, 'once the cache is back' );
	}

	public function test_a_plan_entry_of_the_right_shape_that_is_not_the_checks_stops_the_swap_before_it_starts(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$plan   = $this->plan_of( $job );
		$entry  = null;
		foreach ( $plan['dirs'] as $dir ) {
			if ( false !== strpos( $dir['stage'], '/other-content/' ) ) {
				$entry = $dir;
			}
		}
		$this->assertNotNull( $entry, 'the control: the plan has an entry of other content' );
		// Next to the staging root, so of the right shape, but not the entry the check builds for that staged copy.
		$forged = dirname( $entry['live'] ) . '/live-only.txt';
		$this->assertSame( '', \WPCheckpoint\Restore\SwapRules::invalid( array( 'live' => $forged ) + $entry, $job->storage_token, $job->id ), 'the control: the shape alone lets it through' );
		$table = $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE;
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET live = %s WHERE job_id = %d AND seq = %d", $forged, $job->id, $entry['seq'] ) );
		$wpdb->query( 'COMMIT' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'a directory entry the final check does not write', (string) $done->last_error );
		$this->assertSame( SwapCheckStep::ID, $done->cursor[ JobRepository::RETRY_FROM_KEY ] ?? null );
		$this->assertNotContains( 'entered', $this->seams );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_table_whose_restored_copy_went_before_the_swap_is_left_as_it_is_and_said(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$plan   = $this->plan_of( $job );
		$this->killed_at( $job, 'batch_recorded', 1 );
		$keep = null;
		foreach ( $plan['tables'] as $entry ) {
			if ( $wpdb->prefix . 'swt_keep' === $entry['live'] ) {
				$keep = $entry;
			}
		}
		$this->assertNotNull( $keep );
		$wpdb->query( "DROP TABLE `{$keep['stage']}`" ); // The restored copy goes before the rename.
		$wpdb->query( 'COMMIT' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertSame( $before['tables']['swt_keep'], $this->site()['tables']['swt_keep'], 'the live table stays where it is' );
		$log = (string) file_get_contents( $done->storage_path . '/' . ( '' !== $done->log_path ? $done->log_path : 'logs/job-' . $done->id . '.log' ) );
		$this->assertStringContainsString( 'A table of the plan was left as it is', $log );
		$this->assertStringContainsString( 'swt_keep', $log );
	}

	public function test_killed_after_the_hold_was_let_go_the_file_lapses_and_the_next_run_finishes(): void {
		// Rolled back: once the site is whole again, the file is rewritten with the time before that is recorded.
		$job    = $this->at_swap();
		$before = $this->site();
		$plan   = $this->plan_of( $job );
		$last   = $plan['dirs'][ count( $plan['dirs'] ) - 1 ];
		rename( $last['stage'], $last['stage'] . '-away' );
		$this->killed_at( $job, 'unheld', 1 );
		rename( $last['stage'] . '-away', $last['stage'] );
		$left = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'restored', $left->cursor['phase'], 'recorded as put back before the file was let go' );
		$file = new Maintenance( $this->abspath, $left->cursor['mark'] );
		$this->assertSame( Maintenance::OURS, $file->state(), 'up' );
		$this->assertFalse( $file->is_held(), 'but no longer held: it lapses on its own' );
		$this->assertPutBack( $this->cli_run( $job ), $before, 'after unheld' );
		$this->tear_down_swap();
		$this->set_up_swap();
		// Swapped in: the same before the swap is recorded as made.
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'unheld_commit', 1 );
		$left = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'committed', $left->cursor['phase'], 'recorded as made before the file was let go' );
		$this->assertFalse( ( new Maintenance( $this->abspath, $left->cursor['mark'] ) )->is_held() );
		$this->assertSwapped( $this->cli_run( $job ), $before, 'after unheld_commit' );
		$this->undo( Plugin::instance()->jobs()->find( $job->id ) );
		$this->tear_down_swap();
		$this->set_up_swap();
		// Swapped in, found made by the next run: it lets the file go too before it records the swap as made.
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', 1 );
		$this->assertTrue( ( new Maintenance( $this->abspath, Plugin::instance()->jobs()->find( $job->id )->cursor['mark'] ) )->is_held(), 'the control: held when killed' );
		$this->killed_at( $job, 'unheld_commit', 1 );
		$left = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'committed', $left->cursor['phase'] );
		$this->assertFalse( ( new Maintenance( $this->abspath, $left->cursor['mark'] ) )->is_held() );
		$this->assertSwapped( $this->cli_run( $job ), $before, 'after unheld_commit on the next run' );
	}

	public function test_the_warning_tells_a_failed_job_to_be_retried_and_one_in_progress_apart(): void {
		global $wpdb;
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in', 2 );
		$this->assertStringContainsString( 'Resolve it with: wp wpcheckpoint job run ' . $job->id, implode( "\n", Plugin::instance()->half_swapped_warnings() ), 'the control: a job that waits for WP-CLI' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET locked_until = %d, lock_token = %s WHERE id = %d', time() + 120, 'another-process', $job->id ) );
		$this->assertStringContainsString( 'is at work on the site in another process', implode( "\n", Plugin::instance()->half_swapped_warnings() ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . " SET locked_until = 0, lock_token = '', status = %s WHERE id = %d", Job::FAILED, $job->id ) );
		$failed = implode( "\n", Plugin::instance()->half_swapped_warnings() );
		$this->assertStringContainsString( 'wp wpcheckpoint job retry ' . $job->id . ', then wp wpcheckpoint job run ' . $job->id, $failed );
		// What the warning says, done: retried, then run by WP-CLI, which puts the site back from where it stopped.
		Plugin::instance()->job_actions()->retry( $job->id );
		$wpdb->query( 'COMMIT' );
		$back = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $back->status, (string) $back->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $back->site_state, 'and the site is put back' );
	}

	public function test_every_command_of_the_plugin_says_first_that_the_site_is_half_swapped(): void {
		$job = $this->at_swap();
		$this->assertSame( array(), Plugin::instance()->half_swapped_warnings(), 'the control: nothing to say before the swap' );
		$this->killed_at( $job, 'dir_in', 2 );
		$warnings = Plugin::instance()->half_swapped_warnings();
		$this->assertCount( 1, $warnings );
		$this->assertStringContainsString( 'half swapped by restore job ' . $job->id, $warnings[0] );
		$this->assertStringContainsString( 'wp wpcheckpoint job run ' . $job->id, $warnings[0] );
		$this->assertSame( Job::FAILED, $this->cli_run( $job )->status );
		$this->assertSame( array(), Plugin::instance()->half_swapped_warnings(), 'put back: nothing to say' );
	}

	public function test_a_swap_whose_end_was_written_but_not_its_completion_is_not_started_again(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$before = $this->site();
		$done   = $this->cli_run( $job );
		$this->assertSwapped( $done, $before, 'the swap' );
		// A run that died between the Runner's two last writes: the step's empty cursor written, the job not yet
		// completed (site_state stays that of the swap).
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . " SET status = %s, step = %s, cursor_json = '[]', finished_at = 0 WHERE id = %d", Job::RUNNING, \WPCheckpoint\Jobs\SwapStep::ID, $job->id ) );
		$wpdb->query( 'COMMIT' );
		$left = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( array(), $left->cursor );
		$this->assertSame( Job::SITE_SWAPPED, $left->site_state );
		$this->assertStringContainsString( 'Restore job ' . $job->id . ' is at its last step (the restored site is swapped in)', implode( "\n", Plugin::instance()->half_swapped_warnings() ), 'WP-CLI says what is left' );
		$this->seams = array();
		$again       = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $again->status, (string) $again->last_error );
		$this->assertSame( array(), $this->seams, 'nothing of the swap ran again' );
		$this->assertRestored( $before );
	}

	public function test_a_flush_that_keeps_failing_after_the_swap_keeps_the_file_held_and_a_retry_completes(): void {
		$this->swap_parts['flush'] = static function (): void {
			throw new \RuntimeException( 'the cache server is away' );
		};
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$result = $this->cli_tick( $job );
		$this->assertSame( TickResult::WAITING, $result->status, $result->message );
		$this->assertStringContainsString( 'get the object cache (its drop-in or its server) working, then retry the job', $result->message );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'committed', $now->cursor['phase'] );
		$this->assertSame( 'cache', $now->cursor['post'], 'the flush comes first, behind the held file' );
		$this->assertTrue( ( new Maintenance( $this->abspath, $now->cursor['mark'] ) )->is_held(), 'held while the flush waits' );
		$this->assertStringContainsString( 'is at its last step (the restored site is swapped in)', implode( "\n", Plugin::instance()->half_swapped_warnings() ) );
		for ( $i = 0; $i < 10 && Job::FAILED !== Plugin::instance()->jobs()->find( $job->id )->status; $i++ ) {
			$this->cli_tick( $job );
		}
		$failed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $failed->status, 'the retries ran out' );
		$this->assertNotSame( Job::FAILURE_FINAL, $failed->failure_kind );
		$this->assertSame( Job::SITE_SWAPPED, $failed->site_state );
		$this->assertTrue( ( new Maintenance( $this->abspath, $failed->cursor['mark'] ) )->is_held(), 'still held: never a half-flushed site served' );
		$this->assertStringContainsString( 'wp wpcheckpoint job retry ' . $job->id . ', then wp wpcheckpoint job run ' . $job->id, implode( "\n", Plugin::instance()->half_swapped_warnings() ) );
		// The cache is back: retried, then run.
		$this->retype( $job, array( 'flush' => static function (): void {} ) );
		Plugin::instance()->job_actions()->retry( $job->id );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertSwapped( $this->cli_run( $job ), $before, 'after the retry' );
		$this->assertFalse( Maintenance::held_in( $this->abspath ) );
	}

	public function test_a_half_swapped_job_whose_position_is_lost_is_not_to_be_run_blindly(): void {
		global $wpdb;
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in', 2 );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . " SET cursor_json = '[]' WHERE id = %d", $job->id ) );
		$wpdb->query( 'COMMIT' );
		$warnings = implode( "\n", Plugin::instance()->half_swapped_warnings() );
		$this->assertStringContainsString( 'its position is lost (the job row was changed): do not run it', $warnings );
		$this->assertStringNotContainsString( 'wp wpcheckpoint job run', $warnings, 'no command to run it' );
		$this->seams = array();
		$done        = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertNotSame( Job::FAILURE_FINAL, $done->failure_kind, 'not final: the job holds the site' );
		$this->assertStringContainsString( 'no position recorded while the site is recorded as being changed', (string) $done->last_error );
		$this->assertSame( array(), $this->seams, 'nothing ran' );
		// The tear-down puts the site back from the plan (SwapTestCase::undo()).
	}

	public function test_an_unknown_position_of_a_job_that_holds_the_site_is_not_final(): void {
		global $wpdb;
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in', 2 );
		$cursor          = Plugin::instance()->jobs()->find( $job->id )->cursor;
		$cursor['phase'] = 'no-such-phase';
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET cursor_json = %s WHERE id = %d', wp_json_encode( $cursor ), $job->id ) );
		$wpdb->query( 'COMMIT' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'The position of the swap is not one this version wrote', (string) $done->last_error );
		$this->assertNotSame( Job::FAILURE_FINAL, $done->failure_kind, 'the job holds the site: its retry stays' );
		$this->assertSame( Job::SITE_CHANGING, $done->site_state );
	}

	public function test_a_directory_rename_of_the_rollback_that_keeps_failing_leaves_a_job_to_retry_with_the_file_held(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 1 ); // A directory swapped: the rollback has one to put back.
		$tries = 0;
		$this->retype(
			$job,
			array(
				'rename' => static function ( string $from, string $to ) use ( &$tries ): bool {
					++$tries;
					trigger_error( sprintf( 'rename(%1$s,%2$s): Permission denied', $from, $to ), E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- what PHP says.
					return false;
				},
			)
		);
		$results = array();
		for ( $i = 0; $i <= Runner::MAX_RETRIES; $i++ ) {
			$results[] = $this->cli_tick( $job )->status;
		}
		$this->assertSame( array_merge( array_fill( 0, Runner::MAX_RETRIES, TickResult::WAITING ), array( TickResult::FAILED ) ), $results, 'waited out after each try, failed once the tries ran out' );
		$this->assertSame( Runner::MAX_RETRIES + 1, $tries, 'one rename tried per run, each after the evidence was read again' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $now->status );
		$this->assertSame( Job::FAILURE_TEMPORARY, $now->failure_kind, 'not final: one to retry' );
		$this->assertTrue( $now->retry_useful() );
		$this->assertSame( Job::SITE_CHANGING, $now->site_state, 'still holds the site' );
		$this->assertSame( 'rollback', $now->cursor['phase'] ?? '', 'not recorded as put back' );
		$this->assertTrue( ( new Maintenance( $this->abspath, (string) $now->cursor['mark'] ) )->is_held(), 'the file stays held' );
		// The reason, as WP-CLI gives it: masked. The control: the job's own error has the path and PHP's words.
		$this->assertStringContainsString( sys_get_temp_dir(), (string) $now->last_error );
		$this->assertStringContainsString( 'Permission denied', (string) $now->last_error );
		$said = implode( "\n", Plugin::instance()->half_swapped_warnings() );
		$this->assertStringContainsString( sprintf( 'wp wpcheckpoint job retry %1$d, then wp wpcheckpoint job run %1$d', $job->id ), $said );
		$this->assertStringContainsString( 'Its last try failed:', $said );
		$this->assertStringContainsString( 'Permission denied', $said );
		$this->assertStringContainsString( '{tmp}', $said );
		$this->assertStringNotContainsString( sys_get_temp_dir(), $said );
		$this->assertStringNotContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $said );
		// Retried, with a rename that works: put back as it was.
		$this->retype( $job );
		Plugin::instance()->job_actions()->retry( $job->id );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertPutBack( $this->cli_run( $job ), $before, 'after the retry' );
	}

	public function test_a_directory_rename_of_the_rollback_made_but_reported_as_failed_is_not_made_again(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 1 );
		$calls = array();
		$lie   = 1;
		$this->retype(
			$job,
			array(
				'rename' => static function ( string $from, string $to ) use ( &$calls, &$lie ): bool {
					$calls[] = array( $from, $to );
					$moved   = rename( $from, $to );
					if ( $moved && $lie > 0 ) {
						--$lie; // Made, but reported as failed (as NFS may).
						trigger_error( 'rename(): Input/output error', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- what PHP says.
						return false;
					}
					return $moved;
				},
			)
		);
		$this->assertSame( TickResult::WAITING, $this->cli_tick( $job )->status );
		$this->assertCount( 1, $calls, 'one rename tried' );
		$made = $calls[0];
		$this->assertFalse( self::there( $made[0] ), 'the control: it was made (nothing left where it was)' );
		$this->assertTrue( self::there( $made[1] ) );
		$done = $this->cli_run( $job );
		$this->assertNotContains( $made, array_slice( $calls, 1 ), 'not made a second time' );
		$this->assertGreaterThan( 1, count( $calls ), 'the control: the next run renamed what was left' );
		$this->assertPutBack( $done, $before, 'after a rename reported as failed' );
	}
}
