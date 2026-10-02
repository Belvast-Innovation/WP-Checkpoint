<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\SwapCheckStep;
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
		foreach ( array( 'committed', 'flushed', 'rewrite', 'cron', 'exited' ) as $seam ) {
			list( $done, $before ) = $this->crash(
				$seam,
				1,
				function ( Job $job ) use ( $seam ): void {
					$this->assertSame( Job::SITE_SWAPPED, $job->site_state, $seam );
					$this->assertSame( 'committed', $job->cursor['phase'], $seam );
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
		foreach ( array( array( 'table_back', 1 ), array( 'dir_back', 1 ), array( 'dirs_back', 1 ), array( 'maintenance_down', 1 ), array( 'reverted', 1 ) ) as $case ) {
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
			$this->assertSame( 'reverted' === $case[0] ? 'reverted' : 'rollback', $left->cursor['phase'], $case[0] );
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

	public function test_k27_the_maintenance_file_is_refreshed_between_the_steps(): void {
		$clock = 1800000000;
		$times = array();
		$this->swap_parts['now'] = static function () use ( &$clock ): int {
			return ++$clock;
		};
		$this->swap_parts['at']  = function ( string $point ) use ( &$times, &$clock ): void {
			$this->seams[] = $point;
			if ( in_array( $point, array( 'dir_aside', 'dir_in', 'carried', 'batch_recorded' ), true ) ) {
				$file = new Maintenance( $this->abspath, $this->swap_job->cursor['mark'] ?? Plugin::instance()->jobs()->find( $this->swap_job->id )->cursor['mark'] );
				$time = $file->time_of( (string) file_get_contents( $this->abspath . '/.maintenance' ) );
				$this->assertNotNull( $time, 'this restore\'s file is up at ' . $point );
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
}
