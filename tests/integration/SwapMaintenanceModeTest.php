<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Leftovers;
use WPCheckpoint\Tests\Fixtures\RealAbspath;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The site in maintenance mode while the swap has it half changed, as WordPress itself says it
 * (wp_is_maintenance_mode()): from the first change until the swap is made or rolled back the maintenance file never
 * lapses, a run that dies there leaves visitors the maintenance page however long it takes to come back, and once the
 * site is recorded as swapped or put back the file comes down as the last step.
 *
 * wp_is_maintenance_mode() reads ABSPATH/.maintenance only, and the maintenance code the data file next to it: these
 * tests put the swap's maintenance file and its data file in the test site's real ABSPATH (the registered exception),
 * every write and deletion of their own through RealAbspath, which holds the safeguards: ABSPATH must be the test
 * site's WordPress directory (not the repository, not the working directory), a maintenance file found there at the
 * start fails the test and is not written over, and the leftover check (Leftovers) fails a test that leaves one. Time
 * is not moved: the swap's clock (injected) is set back eleven minutes instead, which is the same to WordPress.
 */
final class SwapMaintenanceModeTest extends SwapTestCase {

	const ELEVEN_MINUTES = 660;

	public function set_up(): void {
		parent::set_up();
		$abspath                      = RealAbspath::claim();
		$this->abspath                = $abspath;
		$this->swap_parts['abspath'] = $abspath;
		$this->register_type();
	}

	public function tear_down(): void {
		// The swap's own file, if a failing test left it (its mark only); the leftover check fails such a test anyway.
		$job = null === $this->swap_job ? null : Plugin::instance()->jobs()->find( $this->swap_job->id );
		if ( null !== $job ) {
			$mark = $job->cursor['mark'] ?? '';
			if ( '' !== $mark ) {
				( new Maintenance( $this->abspath, $mark ) )->remove();
			}
		}
		parent::tear_down();
	}

	private function maintenance_mode(): bool {
		clearstatcache( true, ABSPATH . '.maintenance' );
		unset( $GLOBALS['upgrading'] );
		return wp_is_maintenance_mode();
	}

	public function test_a_swap_killed_half_way_keeps_the_site_in_maintenance_past_ten_minutes_until_it_is_put_back(): void {
		$this->clock_offset = -self::ELEVEN_MINUTES; // As if the swap ran eleven minutes ago.
		$job                = $this->at_swap();
		$before             = $this->site();
		$this->assertFalse( $this->maintenance_mode(), 'the control: not in maintenance before the swap' );
		$this->killed_at( $job, 'dir_in', 2 );
		$this->assertTrue( $this->maintenance_mode(), 'half swapped: still in maintenance eleven minutes on' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( SwapCheckStep::ID, $done->cursor[ JobRepository::RETRY_FROM_KEY ] ?? null );
		$this->assertFalse( $this->maintenance_mode(), 'put back: no longer in maintenance' );
		$this->assertSame( array(), RealAbspath::contents(), 'neither file is left' );
		$this->assertSame( $before, $this->site() );
	}

	public function test_before_the_first_change_the_file_lapses_as_before(): void {
		$this->clock_offset = -self::ELEVEN_MINUTES;
		$job                = $this->at_swap();
		$this->killed_at( $job, 'maintenance', 1 );
		$this->assertFileExists( ABSPATH . '.maintenance', 'the file is up' );
		$this->assertFalse( $this->maintenance_mode(), 'the control: nothing changed yet, the file lapses after ten minutes as it always did' );
		$this->clock_offset = 0;
		$this->assertSame( Job::FAILED, $this->cli_run( $job )->status );
		$this->assertSame( array(), RealAbspath::contents(), 'neither file is left' );
	}

	public function test_a_completed_swap_leaves_the_site_out_of_maintenance(): void {
		$job = $this->at_swap();
		$this->assertFalse( $this->maintenance_mode(), 'the control' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertFalse( $this->maintenance_mode() );
		$this->assertSame( array(), RealAbspath::contents(), 'neither file is left' );
	}

	public function test_killed_once_recorded_as_swapped_the_next_run_takes_the_file_down(): void {
		$job = $this->at_swap();
		$this->killed_at( $job, 'done_recorded', 1 );
		$left = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'done', $left->cursor['phase'] );
		$this->assertSame( Job::SITE_SWAPPED, $left->site_state );
		$this->assertTrue( $this->maintenance_mode(), 'the file is still up (refreshed after the swap was made)' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status );
		$this->assertFalse( $this->maintenance_mode() );
		$this->assertSame( array(), RealAbspath::contents(), 'neither file is left' );
	}

	public function test_killed_once_recorded_as_put_back_the_next_run_or_a_cancel_takes_the_file_down(): void {
		foreach ( array( 'run', 'cancel' ) as $then ) {
			$this->clock_offset = -self::ELEVEN_MINUTES;
			$job                = $this->at_swap();
			$before             = $this->site();
			$plan               = $this->plan_of( $job );
			$last               = $plan['dirs'][ count( $plan['dirs'] ) - 1 ];
			rename( $last['stage'], $last['stage'] . '-away' ); // Its rename fails: the swap rolls back.
			$this->killed_at( $job, 'reverted', 1 );
			rename( $last['stage'] . '-away', $last['stage'] );
			$left = Plugin::instance()->jobs()->find( $job->id );
			$this->assertSame( 'reverted', $left->cursor['phase'], $then );
			$this->assertSame( Job::SITE_UNTOUCHED, $left->site_state );
			$this->assertFileExists( ABSPATH . '.maintenance', 'up until the last step: ' . $then );
			$this->assertFalse( $this->maintenance_mode(), 'but let go before the site was recorded as put back: it lapses on its own (eleven minutes on)' );
			if ( 'run' === $then ) {
				$this->assertSame( Job::FAILED, $this->cli_run( $job )->status );
			} else {
				Plugin::instance()->job_actions()->cancel( $job->id );
				$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $job->id )->status );
			}
			$this->assertFalse( $this->maintenance_mode(), 'down after the ' . $then );
			$this->assertSame( array(), RealAbspath::contents(), 'neither file is left' );
			$this->assertSame( $before, $this->site() );
			$this->tear_down_swap();
			$this->set_up_swap();
			$this->abspath                = RealAbspath::claim();
			$this->swap_parts['abspath'] = $this->abspath;
			$this->register_type();
		}
	}

	public function test_a_cancel_takes_down_only_this_jobs_file(): void {
		$job  = $this->at_swap();
		$plan = $this->plan_of( $job );
		$last = $plan['dirs'][ count( $plan['dirs'] ) - 1 ];
		rename( $last['stage'], $last['stage'] . '-away' ); // Its rename fails: the swap rolls back.
		$this->killed_at( $job, 'reverted', 1 );
		rename( $last['stage'] . '-away', $last['stage'] );
		$this->assertSame( 'reverted', Plugin::instance()->jobs()->find( $job->id )->cursor['phase'] );
		$this->assertTrue( $this->maintenance_mode() );
		// Someone else's file in its place (an update started meanwhile). A time already past: WordPress ignores it,
		// so a failure below cannot leave the test site in maintenance mode.
		$someone = "<?php \$upgrading = " . ( time() - 3600 ) . "; // an update\n";
		RealAbspath::write( Maintenance::FILE, $someone );
		try {
			Plugin::instance()->job_actions()->cancel( $job->id );
			$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $job->id )->status );
			$this->assertSame( array( '.maintenance' => $someone ), RealAbspath::contents(), 'left as it was; this job\'s data file, read by nothing, is gone' );
			$this->assertContains( 'maintenance:' . rtrim( ABSPATH, '/' ) . '/.maintenance', Leftovers::listing(), 'the leftover check sees it' );
			Leftovers::removal( array( 'maintenance:' . rtrim( ABSPATH, '/' ) . '/.maintenance' ) );
			$this->assertSame( $someone, file_get_contents( ABSPATH . '.maintenance' ), 'and leaves what is not this plugin\'s' );
		} finally {
			// Not the plugin's to remove: the test removes the one it wrote itself.
			RealAbspath::remove( Maintenance::FILE );
		}
	}

	public function test_a_held_file_of_the_plugin_is_warned_of_whatever_the_jobs_say(): void {
		$this->assertSame( array(), Plugin::instance()->half_swapped_warnings(), 'the control: nothing to say' );
		$file = new Maintenance( $this->abspath, Maintenance::new_mark() );
		try {
			$file->put( time() );
			$this->assertSame( array(), Plugin::instance()->half_swapped_warnings(), 'the control: a file that lapses is not' );
			$file->hold();
			$this->assertTrue( $this->maintenance_mode() );
			$warnings = Plugin::instance()->half_swapped_warnings();
			$this->assertCount( 1, $warnings );
			$this->assertStringContainsString( 'maintenance file is up and does not lapse', $warnings[0] );
			$this->assertStringContainsString( 'No restore holds the site now; check with wp wpcheckpoint job list, and if none does, remove that file', $warnings[0] );
		} finally {
			$file->remove();
		}
	}

	public function test_the_leftover_check_lists_the_file_and_removes_only_this_plugins(): void {
		$ours  = ( new Maintenance( $this->abspath, Maintenance::new_mark() ) );
		$names = array( Maintenance::FILE, Maintenance::DATA, Maintenance::FILE . '.0123456789abcdef.tmp', Maintenance::DATA . '.0123456789abcdef.tmp' );
		$items = array_map(
			static function ( string $name ): string {
				return 'maintenance:' . rtrim( ABSPATH, '/' ) . '/' . $name;
			},
			$names
		);
		$ours->put( time() );
		try {
			// The two temporary names, as a run that died before its renames would leave them.
			RealAbspath::write( $names[2], Maintenance::CODE );
			RealAbspath::write( $names[3], $ours->data( time() ) );
			foreach ( $items as $item ) {
				$this->assertContains( $item, Leftovers::listing() );
			}
			Leftovers::removal( $items );
			$this->assertSame( array(), RealAbspath::contents(), 'this plugin\'s: removed, the temporary names too' );
			foreach ( $items as $item ) {
				$this->assertNotContains( $item, Leftovers::listing(), 'the control: not listed once gone' );
			}
			RealAbspath::write( Maintenance::DATA, "someone else's\n" );
			Leftovers::removal( array( $items[1] ) );
			$this->assertSame( array( Maintenance::DATA => "someone else's\n" ), RealAbspath::contents(), 'a data file not of its form: left' );
		} finally {
			$ours->remove(); // Whatever failed above: the files this test wrote do not stay.
			foreach ( RealAbspath::listing() as $left ) {
				RealAbspath::remove( basename( $left ) );
			}
		}
	}

	/**
	 * The status of a request for the test site's home page, through its web server: with the pass header, or none.
	 *
	 * @param string|null $key Pass key.
	 * @return int 0 when no answer came.
	 */
	private function home( $key = null ): int {
		$response = wp_remote_get(
			rtrim( (string) getenv( 'WPCHECKPOINT_TEST_LOOPBACK_HOST' ), '/' ) . '/?wpcheckpoint_nocache=' . bin2hex( random_bytes( 4 ) ),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => null === $key ? array() : array( Maintenance::PASS_HEADER => $key ),
			)
		);
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	public function test_the_file_lets_through_the_requests_of_the_run_that_checks_and_the_next_run_voids_the_last_ones_key(): void {
		if ( '' === (string) getenv( 'WPCHECKPOINT_TEST_LOOPBACK_HOST' ) ) {
			$this->markTestSkipped( 'Needs the web server of the test site (WPCHECKPOINT_TEST_LOOPBACK_HOST).' );
		}
		$this->assertNotSame( 503, $this->home(), 'the control: the site answers before the swap' );
		$keys  = array();
		$codes = array();
		$this->swap_parts['verify'] = function ( $context, array $pass ) use ( &$keys, &$codes ) {
			$keys[] = $pass['key'];
			$run    = count( $keys );
			$codes[ $run ] = array(
				'none' => $this->home(),
				'this' => $this->home( $pass['key'] ),
				'last' => $run > 1 ? $this->home( $keys[ $run - 2 ] ) : null,
				'hash' => $this->home( hash( 'sha256', $pass['key'] ) ),
			);
			if ( 1 === $run ) {
				throw new \RuntimeException( 'Stopped before its verdict.' );
			}
			return true;
		};
		$this->register_type();
		$job   = $this->at_swap();
		$first = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $first->status, 'the first run stopped in its check: ' . (string) $first->last_error );
		$this->assertSame( Job::SITE_SWAPPED, $first->site_state );
		$this->assertSame( 503, $this->home(), 'the site stays behind the file meanwhile' );
		Plugin::instance()->job_actions()->retry( $job->id );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertCount( 2, $keys, 'each run checked with a key of its own' );
		$this->assertNotSame( $keys[0], $keys[1] );
		$this->assertSame( 503, $codes[1]['none'], 'without the header: the maintenance page' );
		$this->assertSame( 503, $codes[1]['hash'], 'the hash the file holds is not the key' );
		$this->assertNotSame( 503, $codes[1]['this'], 'the run that checks: let through' );
		$this->assertNotSame( 0, $codes[1]['this'], 'and answered' );
		$this->assertSame( 503, $codes[2]['last'], 'the last run\'s key: void once the next run wrote its own' );
		$this->assertNotSame( 503, $codes[2]['this'] );
		$this->assertNotSame( 503, $this->home(), 'once it ended: no maintenance page' );
		$this->assertSame( array(), RealAbspath::contents(), 'neither file is left' );
	}

	public function test_a_run_killed_in_its_check_leaves_its_key_in_the_file_and_the_next_run_writes_its_own(): void {
		$job = $this->at_swap();
		$this->killed_at( $job, 'self_check_written', 1 );
		$this->assertTrue( $this->maintenance_mode(), 'held while it checks' );
		$left = RealAbspath::contents()[ Maintenance::DATA ] ?? '';
		$this->assertSame( 1, preg_match( Maintenance::DATA_FORM, $left, $found ), 'the killed run\'s data file: ' . $left );
		$this->assertNotSame( Maintenance::NO_PASS, $found[3], 'the control: it holds the hash of that run\'s key' );
		$seen = array();
		$this->swap_parts['verify'] = function ( $context, array $pass ) use ( &$seen, $found ) {
			$now    = RealAbspath::contents()[ Maintenance::DATA ] ?? '';
			$seen[] = array(
				'mark'   => 1 === preg_match( Maintenance::DATA_FORM, $now, $m ) ? $m[1] : '',
				'hash'   => $m[3] ?? '',
				'passes' => Maintenance::passes( $this->abspath, $pass['key'] ),
			);
			return true;
		};
		$this->retype( $job );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertCount( 1, $seen );
		$this->assertSame( $found[1], $seen[0]['mark'], 'the same restore\'s file' );
		$this->assertSame( true, $seen[0]['passes'], 'the new run\'s key is let through' );
		$this->assertNotSame( $found[3], $seen[0]['hash'], 'the killed run\'s hash is written over' );
		$this->assertSame( array(), RealAbspath::contents(), 'neither file is left' );
	}

	public function test_a_held_file_is_said_whatever_the_job_lines_say(): void {
		$job = $this->at_swap();
		$this->killed_at( $job, 'committed', 1 );
		$warnings = Plugin::instance()->half_swapped_warnings();
		$this->assertCount( 2, $warnings, 'the job at its last step, and the held file' );
		$this->assertStringContainsString( 'is at its last step (the restored site is swapped in)', $warnings[0] );
		$this->assertStringContainsString( 'visitors see the maintenance page until the restore above ends', $warnings[1] );
		$this->assertSame( Job::COMPLETED, $this->cli_run( $job )->status );
		$this->assertSame( array(), Plugin::instance()->half_swapped_warnings(), 'the control: nothing once it ended' );
	}

	public function test_the_notice_offers_release_for_a_job_abandoned_here_and_not_for_one_abandoned_elsewhere(): void {
		global $wpdb;
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_aside_recorded' );
		$job  = Plugin::instance()->jobs()->find( $job->id );
		$file = new Maintenance( $this->abspath, (string) $job->site_mark );
		try {
			$this->assertTrue( $file->is_held(), 'the control: the site here is behind its held file' );
			// Abandoned by this installation, from a copy of the site in another WordPress directory (the copy's file
			// is the copy's; the one here stays).
			$copy = $this->sandbox . '/copy';
			mkdir( $copy . '/wp-content/uploads', 0755, true );
			$acts = new \WPCheckpoint\Jobs\JobActions(
				Plugin::instance()->jobs(),
				Plugin::instance()->runner(),
				new \WPCheckpoint\Jobs\Loopback( false ),
				new \WPCheckpoint\Jobs\HeldSite( array( 'abspath' => $copy ) )
			);
			$wpdb->update( JobRepository::table(), array( 'held_by' => 'ffffffffffff' ), array( 'id' => $job->id ) ); // Managed elsewhere, so it can be given up.
			$wpdb->query( 'COMMIT' );
			$see = $acts->held_elsewhere( $job->id )['assessment'];
			$this->assertTrue( $acts->abandon( $job->id, \WPCheckpoint\Jobs\HeldSite::code( \WPCheckpoint\Jobs\HeldSite::ABANDON, Plugin::instance()->jobs()->find( $job->id ), $see['recorded'] ) )['ok'] );
			$this->assertTrue( $file->is_held(), 'the file here stays' );
			$none  = static function (): array {
				return array(); // The notice's own path (ended_owner()): no job listed as holding the site.
			};
			$paths = function () use ( $none ): array {
				return array(
					'the jobs read'          => implode( "\n", Plugin::instance()->half_swapped_warnings() ),
					'the notice\'s own path' => implode( "\n", Plugin::instance()->half_swapped_warnings( $none ) ),
				);
			};
			foreach ( $paths() as $what => $text ) {
				$this->assertStringContainsString( 'That job no longer holds the site; take its file down with: wp wpcheckpoint job release ' . $job->id, $text, $what . ': the control, abandoned here, release is offered' );
			}
			// Seen from another installation than the one that gave it up (its token in held_by), and from a WordPress
			// directory positively another than the one its plan records: it still holds the site.
			$wpdb->update( JobRepository::table(), array( 'held_by' => 'abababababab' ), array( 'id' => $job->id ) );
			$plan     = $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE;
			$recorded = (string) $wpdb->get_var( $wpdb->prepare( "SELECT live FROM `{$plan}` WHERE job_id = %d AND kind = 'site'", $job->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plan table.
			$wpdb->query( $wpdb->prepare( "UPDATE `{$plan}` SET live = %s WHERE job_id = %d AND kind = 'site'", (string) realpath( $copy ), $job->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plan table.
			$wpdb->query( 'COMMIT' );
			try {
				$this->assertTrue( ( new \WPCheckpoint\Jobs\HeldSite() )->assess( Plugin::instance()->jobs()->find( $job->id ) )['differs'], 'the control: positively another directory than the plan\'s' );
				foreach ( $paths() as $what => $text ) {
					$this->assertStringNotContainsString( 'job release', $text, $what . ': no release offered' );
				}
				$this->assertStringContainsString( 'This site may still be half swapped by it', $paths()['the jobs read'], 'abandoned elsewhere: this site may be half swapped' );
			} finally {
				$wpdb->query( $wpdb->prepare( "UPDATE `{$plan}` SET live = %s WHERE job_id = %d AND kind = 'site'", $recorded, $job->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plan table.
				$wpdb->query( 'COMMIT' );
			}
		} finally {
			$file->remove();
		}
	}

	public function test_the_advice_to_remove_a_held_file_needs_the_jobs_read(): void {
		$file = new Maintenance( $this->abspath, Maintenance::new_mark() );
		try {
			$file->hold();
			$none = Plugin::instance()->half_swapped_warnings(
				static function (): array {
					return array();
				}
			);
			$this->assertStringContainsString( 'if none does, remove that file', implode( "\n", $none ), 'the control: read, and none holds the site' );
			$unread = Plugin::instance()->half_swapped_warnings(
				static function (): array {
					throw new \RuntimeException( 'the jobs table is away' );
				}
			);
			$this->assertCount( 1, $unread );
			$this->assertStringContainsString( 'The jobs could not be read: do not remove that file', $unread[0] );
			$this->assertStringNotContainsString( 'if none does, remove that file', $unread[0] );
			$gone = Plugin::instance()->half_swapped_warnings(
				static function (): array {
					return array( 999999 ); // Listed, then not found.
				}
			);
			$this->assertStringNotContainsString( 'remove that file', implode( "\n", $gone ), 'not known what holds the site: no advice to remove it' );
		} finally {
			$file->remove();
		}
	}
}
