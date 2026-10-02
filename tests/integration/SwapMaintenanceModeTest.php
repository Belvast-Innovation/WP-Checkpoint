<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Leftovers;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The site in maintenance mode while the swap has it half changed, as WordPress itself says it
 * (wp_is_maintenance_mode()): from the first change until the swap is made or rolled back the maintenance file never
 * lapses, a run that dies there leaves visitors the maintenance page however long it takes to come back, and once the
 * site is recorded as swapped or put back the file comes down as the last step.
 *
 * wp_is_maintenance_mode() reads ABSPATH/.maintenance only: these tests, and only these, put the swap's maintenance
 * file in the test site's real ABSPATH (one file name, one directory). Guarded: ABSPATH must be the test site's
 * WordPress directory (not the repository, not the working directory), a maintenance file found there at the start
 * fails the test and is not written over, and the leftover check (Leftovers) fails a test that leaves one. Time is not
 * moved: the swap's clock (injected) is set back eleven minutes instead, which is the same to WordPress.
 */
final class SwapMaintenanceModeTest extends SwapTestCase {

	const ELEVEN_MINUTES = 660;

	public function set_up(): void {
		parent::set_up();
		$abspath = realpath( ABSPATH );
		$this->assertNotFalse( $abspath, 'ABSPATH resolves' );
		$this->assertFileExists( $abspath . '/wp-settings.php', 'ABSPATH is a WordPress directory' );
		$repository = (string) realpath( dirname( __DIR__, 2 ) );
		$this->assertNotSame( $repository, $abspath, 'not the repository' );
		$this->assertStringStartsNotWith( $repository . '/', $abspath . '/', 'not inside the repository' );
		$this->assertNotSame( (string) realpath( (string) getcwd() ), $abspath, 'not the working directory' );
		$this->assertFileDoesNotExist( $abspath . '/.maintenance', 'a maintenance file was already there: not written over' );
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
		$this->assertFileDoesNotExist( ABSPATH . '.maintenance' );
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
		$this->assertFileDoesNotExist( ABSPATH . '.maintenance' );
	}

	public function test_a_completed_swap_leaves_the_site_out_of_maintenance(): void {
		$job = $this->at_swap();
		$this->assertFalse( $this->maintenance_mode(), 'the control' );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertFalse( $this->maintenance_mode() );
		$this->assertFileDoesNotExist( ABSPATH . '.maintenance' );
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
		$this->assertFileDoesNotExist( ABSPATH . '.maintenance' );
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
			$this->assertFileDoesNotExist( ABSPATH . '.maintenance' );
			$this->assertSame( $before, $this->site() );
			$this->tear_down_swap();
			$this->set_up_swap();
			$this->abspath                = (string) realpath( ABSPATH );
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
		file_put_contents( ABSPATH . '.maintenance', $someone );
		try {
			Plugin::instance()->job_actions()->cancel( $job->id );
			$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $job->id )->status );
			$this->assertSame( $someone, file_get_contents( ABSPATH . '.maintenance' ), 'left as it was' );
			$this->assertContains( 'maintenance:' . rtrim( ABSPATH, '/' ) . '/.maintenance', Leftovers::listing(), 'the leftover check sees it' );
			Leftovers::removal( array( 'maintenance:' . rtrim( ABSPATH, '/' ) . '/.maintenance' ) );
			$this->assertSame( $someone, file_get_contents( ABSPATH . '.maintenance' ), 'and leaves what is not this plugin\'s' );
		} finally {
			// Not the plugin's to remove: the test removes the one it wrote itself, through the Deleter's one entry there.
			\WPCheckpoint\Support\Deleter::delete_maintenance_file( ABSPATH, '.maintenance' );
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
		} finally {
			$file->remove();
		}
	}

	public function test_the_leftover_check_lists_the_file_and_removes_only_this_plugins(): void {
		$ours = ( new Maintenance( $this->abspath, Maintenance::new_mark() ) );
		$ours->put( time() );
		try {
			$this->assertContains( 'maintenance:' . rtrim( ABSPATH, '/' ) . '/.maintenance', Leftovers::listing() );
			Leftovers::removal( array( 'maintenance:' . rtrim( ABSPATH, '/' ) . '/.maintenance' ) );
			$this->assertFileDoesNotExist( ABSPATH . '.maintenance', 'this plugin\'s: removed' );
			$this->assertNotContains( 'maintenance:' . rtrim( ABSPATH, '/' ) . '/.maintenance', Leftovers::listing(), 'the control: not listed once gone' );
		} finally {
			$ours->remove(); // Whatever failed above: the file this test wrote does not stay.
		}
	}
}
