<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\HeldSite;
use WPCheckpoint\Jobs\InvalidTransition;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A restore that holds the site changed, managed by another installation (its held_by is another token: the site's
 * identity changed since it started), seen from here: taken over when it is this site's (rebind), its maintenance
 * file taken down from another WordPress directory (release), given up (abandon). The job is a real swap, killed in
 * a child process (SwapTestCase::killed_at()).
 */
final class HeldSiteTest extends SwapTestCase {

	const ELSEWHERE = 'ffffffffffff';

	/** @var string Another WordPress directory (a copy's), in the sandbox. */
	private $copy = '';

	/**
	 * A swap killed at a seam, then managed by another token.
	 */
	private function held( string $seam = 'dir_aside_recorded' ): Job {
		global $wpdb;
		$job = $this->at_swap();
		$this->killed_at( $job, $seam );
		$wpdb->update( JobRepository::table(), array( 'held_by' => self::ELSEWHERE ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$job = Plugin::instance()->jobs()->find( $job->id );
		$this->assertNotSame( Job::SITE_UNTOUCHED, $job->site_state, 'the control: it holds the site' );
		$this->assertFalse( Plugin::instance()->jobs()->manages( $job ), 'the control: managed elsewhere' );
		return $job;
	}

	/**
	 * Actions as seen from this site (its WordPress directory and directories), or from a copy's.
	 */
	private function actions( bool $copy = false ): JobActions {
		$parts = $copy
			? array(
				'abspath'   => $this->copy_dir(),
				'site_dirs' => function (): array {
					return array( 'uploads' => $this->copy_dir() . '/wp-content/uploads' );
				},
			)
			: array(
				'abspath'   => $this->abspath,
				'site_dirs' => function (): array {
					return $this->dirs;
				},
			);
		return new JobActions( Plugin::instance()->jobs(), Plugin::instance()->runner(), new Loopback( false ), new HeldSite( $parts ) );
	}

	private function copy_dir(): string {
		if ( '' === $this->copy ) {
			$this->copy = $this->sandbox . '/copy';
			mkdir( $this->copy . '/wp-content/uploads', 0755, true );
		}
		return $this->copy;
	}

	/**
	 * The job's row as stored (the columns an exit may change).
	 *
	 * @return array<string, mixed>
	 */
	private function row( int $id ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT status, site_state, held_by, cancel_requested, failure_kind, finished_at, cursor_json FROM ' . JobRepository::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public function test_a_job_that_is_this_sites_is_taken_over_and_goes_on(): void {
		$site = $this->site();
		$job  = $this->held();
		$acts = $this->actions();
		$held = $acts->held_elsewhere( $job->id );
		$this->assertSame( HeldSite::SITE, $held['assessment']['branch'], (string) $held['assessment']['why'] );
		$code  = HeldSite::code( HeldSite::REBIND, $job, '' );
		$lines = implode( "\n", array_map( array( Plugin::instance()->job_presenter(), 'clean' ), JobPresenter::held_lines( $held['job'], $held['assessment'] ) ) );
		$this->assertStringContainsString( 'wp wpcheckpoint job rebind ' . $job->id . ' --confirm=' . $code . ' --then=continue', $lines, 'the command, with its code, survives the masking' );
		$before = $this->row( $job->id );
		foreach ( array( '' => 'continue', 'nope' => 'continue', HeldSite::code( HeldSite::RELEASE, $job, $held['assessment']['recorded'] ) => 'continue', $code => '' ) as $confirm => $then ) {
			$this->assertFalse( $acts->rebind( $job->id, (string) $confirm, $then )['ok'], 'refused: ' . $confirm . ' / ' . $then );
			$this->assertSame( $before, $this->row( $job->id ), 'nothing changed' );
		}
		$outcome = $acts->rebind( $job->id, $code, 'continue' );
		$this->assertTrue( $outcome['ok'], $outcome['message'] );
		$this->assertSame( (string) Plugin::instance()->directories()->state()['token'], $this->row( $job->id )['held_by'] );
		$this->assertSame( $job->storage_token, Plugin::instance()->jobs()->find( $job->id )->storage_token, 'its names keep their token' );
		$this->assertNull( $acts->held_elsewhere( $job->id ), 'managed here now' );
		// It goes on as after any interruption: the swap cut off half way is put back, and the restore can be retried.
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertStringContainsString( 'the site is as it was before the restore', (string) $done->last_error );
		$this->assertSame( $site, $this->site() );
		Plugin::instance()->job_actions()->retry( $job->id );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertRestored( $site );
	}

	public function test_a_job_taken_over_with_rollback_puts_the_site_back(): void {
		$before = $this->site();
		$job    = $this->held();
		$acts   = $this->actions();
		$this->assertTrue( $acts->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'rollback' )['ok'] );
		$this->assertGreaterThan( 0, (int) $this->row( $job->id )['cancel_requested'], 'the cancel request, in the same statement' );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::CANCELLED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'the site as it was' );
	}

	public function test_once_the_direction_is_recorded_a_take_over_only_finishes(): void {
		$job  = $this->held( 'committed' );
		$acts = $this->actions();
		$held = $acts->held_elsewhere( $job->id );
		$this->assertSame( 'committed', $held['assessment']['direction'], 'the control' );
		$code = HeldSite::code( HeldSite::REBIND, $job, '' );
		$this->assertFalse( $acts->rebind( $job->id, $code, 'rollback' )['ok'], 'no rollback once committed' );
		$this->assertFalse( $acts->rebind( $job->id, $code, 'continue' )['ok'] );
		$this->assertSame( self::ELSEWHERE, $this->row( $job->id )['held_by'] );
		$this->assertTrue( $acts->rebind( $job->id, $code, '' )['ok'] );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
	}

	public function test_a_job_that_is_not_this_sites_is_not_taken_over_and_its_file_here_is_released(): void {
		$job      = $this->held();
		$original = $this->abspath . '/.maintenance';
		$this->assertFileExists( $original, 'the control: held where the swap runs' );
		copy( $original, $this->copy_dir() . '/.maintenance' ); // A copy of the site's files, its maintenance file too.
		file_put_contents( $this->copy_dir() . '/other.txt', 'a file next to it' );
		$acts = $this->actions( true );
		$held = $acts->held_elsewhere( $job->id );
		$see  = $held['assessment'];
		$this->assertSame( HeldSite::OTHER, $see['branch'] );
		$this->assertTrue( $see['differs'] );
		$this->assertTrue( $see['file_here'], 'the control: the observation sees the file' );
		$this->assertFalse( $acts->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'continue' )['ok'], 'not this site\'s: not taken over' );
		$lines = implode( "\n", array_map( array( Plugin::instance()->job_presenter(), 'clean' ), JobPresenter::held_lines( $held['job'], $see ) ) );
		$code  = HeldSite::code( HeldSite::RELEASE, $job, $see['recorded'] );
		$this->assertStringContainsString( 'wp wpcheckpoint job release ' . $job->id . ' --confirm=' . $code, $lines );
		$this->assertStringContainsString( 'its restore stays half swapped', $lines );
		$before = $this->row( $job->id );
		$this->assertFalse( $acts->release( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) )['ok'], 'another command\'s code' );
		$this->assertFileExists( $this->copy_dir() . '/.maintenance' );
		$outcome = $acts->release( $job->id, $code );
		$this->assertTrue( $outcome['ok'], $outcome['message'] );
		$this->assertFileDoesNotExist( $this->copy_dir() . '/.maintenance', 'this job\'s file here: down' );
		$this->assertFileExists( $this->copy_dir() . '/other.txt' );
		$this->assertFileExists( $original, 'the site it was started on keeps its file' );
		$this->assertSame( $before, $this->row( $job->id ), 'the job is not changed' );
		$after = $acts->held_elsewhere( $job->id );
		$this->assertFalse( $after['assessment']['file_here'] );
		$light = implode( "\n", JobPresenter::held_lines( $after['job'], $after['assessment'] ) );
		$this->assertStringContainsString( 'holds no maintenance file of it', $light, 'the lighter wording once released' );
		$this->assertStringNotContainsString( 'job release', $light );
	}

	public function test_nothing_is_released_or_abandoned_from_the_wordpress_directory_the_plan_records(): void {
		$job  = $this->held();
		$acts = new JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new Loopback( false ),
			new HeldSite(
				array(
					'abspath'   => $this->abspath, // The same directory...
					'site_dirs' => function (): array {
						return array( 'uploads' => $this->copy_dir() . '/wp-content/uploads' ); // ...but not this site's directories.
					},
				)
			)
		);
		$see = $acts->held_elsewhere( $job->id )['assessment'];
		$this->assertSame( HeldSite::OTHER, $see['branch'] );
		$this->assertFalse( $see['differs'] );
		$before = $this->row( $job->id );
		foreach ( array( $acts->release( $job->id, HeldSite::code( HeldSite::RELEASE, $job, $see['recorded'] ) ), $acts->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) ) ) as $outcome ) {
			$this->assertFalse( $outcome['ok'] );
			$this->assertStringContainsString( 'not positively another', $outcome['message'], 'refused for that reason' );
		}
		$this->assertFileExists( $this->abspath . '/.maintenance' );
		$this->assertSame( $before, $this->row( $job->id ) );
		// The release itself refuses too, whoever calls it.
		try {
			( new HeldSite( array( 'abspath' => $this->abspath ) ) )->release( Plugin::instance()->jobs()->find( $job->id ), $see );
			$this->fail( 'not released from the directory the plan records' );
		} catch ( \RuntimeException $e ) {
			$this->assertFileExists( $this->abspath . '/.maintenance' );
		}
	}

	public function test_an_abandoned_job_takes_its_file_here_down_never_runs_and_leaves_the_site_it_held(): void {
		$job = $this->held();
		copy( $this->abspath . '/.maintenance', $this->copy_dir() . '/.maintenance' );
		$site = $this->site();
		$acts = $this->actions( true );
		$see  = $acts->held_elsewhere( $job->id )['assessment'];
		$code = HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] );
		$before = $this->row( $job->id );
		foreach ( array( '', 'nope', HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] . '-elsewhere' ), HeldSite::code( HeldSite::RELEASE, $job, $see['recorded'] ) ) as $confirm ) {
			$this->assertFalse( $acts->abandon( $job->id, $confirm )['ok'], 'refused: ' . $confirm );
			$this->assertSame( $before, $this->row( $job->id ) );
			$this->assertFileExists( $this->copy_dir() . '/.maintenance' );
		}
		$outcome = $acts->abandon( $job->id, $code );
		$this->assertTrue( $outcome['ok'], $outcome['message'] );
		$this->assertStringContainsString( 'its restore stays half swapped', $outcome['message'] );
		$this->assertFileDoesNotExist( $this->copy_dir() . '/.maintenance' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $now->status );
		$this->assertSame( Job::FAILURE_FINAL, $now->failure_kind );
		$this->assertSame( Job::REASON_ABANDONED, $now->failure_reason );
		$this->assertSame( $job->site_state, $now->site_state, 'what it did stays recorded' );
		$this->assertSame( $site, $this->site(), 'the site it held is not touched: its paths, tables and file' );
		$this->assertNotContains( $job->id, Plugin::instance()->jobs()->holding_site(), 'no longer warned about' );
		$this->assertNull( $acts->held_elsewhere( $job->id ) );
		try {
			Plugin::instance()->job_actions()->retry( $job->id );
			$this->fail( 'an abandoned job is not retried' );
		} catch ( InvalidTransition $e ) {
			$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $job->id )->status );
		}
		$this->assertFalse( Plugin::instance()->jobs()->reclaim_work( $now ), 'nothing of it is reclaimed' );
		$this->assertFalse( $acts->abandon( $job->id, $code )['ok'], 'once is enough' );
	}

	public function test_an_abandon_that_died_after_taking_the_file_down_is_finished_by_another(): void {
		$job = $this->held();
		copy( $this->abspath . '/.maintenance', $this->copy_dir() . '/.maintenance' );
		$acts = $this->actions( true );
		$see  = $acts->held_elsewhere( $job->id )['assessment'];
		// The first step of an abandon, and nothing after it (the process died).
		$this->assertTrue( $acts->release( $job->id, HeldSite::code( HeldSite::RELEASE, $job, $see['recorded'] ) )['ok'] );
		$this->assertContains( $job->id, Plugin::instance()->jobs()->holding_site(), 'still holding, still warned about' );
		$this->assertTrue( $acts->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) )['ok'] );
		$this->assertSame( Job::REASON_ABANDONED, Plugin::instance()->jobs()->find( $job->id )->failure_reason );
	}

	public function test_the_warning_and_the_admin_say_what_can_be_done_with_a_job_managed_elsewhere(): void {
		global $wpdb;
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_aside_recorded' );
		$here = implode( "\n", Plugin::instance()->half_swapped_warnings() );
		$this->assertStringContainsString( 'Resolve it with: wp wpcheckpoint job run ' . $job->id, $here, 'the control: managed here, run here' );
		$this->assertSame( '', Plugin::instance()->job_presenter()->present( Plugin::instance()->jobs()->find( $job->id ), false )['held_elsewhere'], 'the control' );
		$wpdb->update( JobRepository::table(), array( 'held_by' => self::ELSEWHERE ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$job  = Plugin::instance()->jobs()->find( $job->id );
		$see  = ( new HeldSite() )->assess( $job ); // As this site sees it: not this site's (the plan swapped the sandbox).
		$code = HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] );
		$warn = implode( "\n", Plugin::instance()->half_swapped_warnings() );
		$this->assertStringContainsString( 'managed by another installation', $warn );
		$this->assertStringContainsString( 'wp wpcheckpoint job abandon ' . $job->id . ' --confirm=' . $code, $warn );
		$this->assertStringNotContainsString( 'Resolve it with: wp wpcheckpoint job run ' . $job->id, $warn, 'not advised to run what is never run here' );
		$admin = Plugin::instance()->job_presenter()->present( $job, false )['held_elsewhere'];
		$this->assertStringContainsString( 'wp wpcheckpoint job abandon ' . $job->id . ' --confirm=' . $code, $admin );
	}

	public function test_an_abandon_takes_the_file_down_before_it_records_the_job_abandoned(): void {
		$job = $this->held();
		copy( $this->abspath . '/.maintenance', $this->copy_dir() . '/.maintenance' );
		$seen = array();
		$acts = new JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new Loopback( false ),
			new HeldSite(
				array(
					'abspath'   => $this->copy_dir(),
					'site_dirs' => function (): array {
						return array( 'uploads' => $this->copy_dir() . '/wp-content/uploads' );
					},
					'at'        => function ( string $point ) use ( $job, &$seen ): void {
						$seen[ $point ] = array( file_exists( $this->copy_dir() . '/.maintenance' ), Plugin::instance()->jobs()->find( $job->id )->failure_reason );
					},
				)
			)
		);
		$see = $acts->held_elsewhere( $job->id )['assessment'];
		$this->assertTrue( $acts->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) )['ok'] );
		$this->assertSame( array( 'abandon_released' => array( false, '' ) ), $seen, 'between the two: the file down, the job not yet abandoned' );
	}

	public function test_a_retry_read_in_the_second_the_job_failed_writes_nothing_after_an_abandon(): void {
		global $wpdb;
		$job = $this->held();
		$wpdb->update( JobRepository::table(), array( 'status' => Job::FAILED, 'finished_at' => time() ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$read = Plugin::instance()->jobs()->find( $job->id ); // Read for a retry, in the same second as the failure.
		$acts = $this->actions( true );
		$see  = $acts->held_elsewhere( $job->id )['assessment'];
		$this->assertTrue( $acts->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) )['ok'] );
		try {
			Plugin::instance()->jobs()->transition( $read, Job::QUEUED );
			$this->fail( 'a retry read before the abandon writes nothing' );
		} catch ( \WPCheckpoint\Jobs\StaleJob $e ) {
			$this->assertSame( Job::REASON_ABANDONED, Plugin::instance()->jobs()->find( $job->id )->failure_reason );
		}
	}

	public function test_an_abandon_or_take_over_read_before_the_other_writes_nothing(): void {
		$job  = $this->held();
		$acts = $this->actions( true );
		$see  = $acts->held_elsewhere( $job->id )['assessment'];
		$read = Plugin::instance()->jobs()->find( $job->id );
		$this->assertTrue( $acts->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) )['ok'] );
		$this->expectException( \WPCheckpoint\Jobs\StaleJob::class );
		Plugin::instance()->jobs()->take_over( $read, false ); // Read before the abandon: its finished_at moved.
	}
}
