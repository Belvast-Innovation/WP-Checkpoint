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

	/** The token of another installation on this database (another_installation()). */
	const ANOTHER = 'abababababab';

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

	/**
	 * A job's tables by the names it made: temporary, and moved aside by its swap.
	 *
	 * @return string[]
	 */
	private function tables_of( Job $job ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$out = array();
		foreach ( array( \WPCheckpoint\Jobs\TempTables::job_prefix( $job->storage_token, $job->id ), \WPCheckpoint\Jobs\TempTables::OLD_PREFIX . substr( $job->storage_token, 0, \WPCheckpoint\Jobs\TempTables::TOKEN_LEN ) . '_' . $job->id . '_' ) as $prefix ) {
			$out = array_merge( $out, (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) ) );
		}
		sort( $out );
		return $out;
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
		$mark = (string) $job->cursor['mark'];
		$this->assertSame( $mark, Plugin::instance()->jobs()->find( $job->id )->site_mark, 'the row says the mark of the file the swap holds the site with' );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertRestored( $site );
		$this->assertArrayNotHasKey( 'mark', $done->cursor, 'the control: the cursor no longer says it once it completed' );
		$this->assertSame( $mark, (string) $done->site_mark, 'a retry is a new attempt with the job\'s one mark: a file an earlier attempt holds elsewhere still carries it' );
		$this->assertNotSame( '', $done->site_mark, 'and the mark stays once the job ended' );
	}

	public function test_a_take_over_that_continues_clears_a_cancel_requested_before(): void {
		global $wpdb;
		$job = $this->held();
		// Whoever managed it asked for a cancel before the site's identity changed.
		$wpdb->update( JobRepository::table(), array( 'cancel_requested' => time() ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$this->assertNotSame( '0', (string) $this->row( $job->id )['cancel_requested'], 'the control: the request is seen through the row' );
		$job  = Plugin::instance()->jobs()->find( $job->id );
		$acts = $this->actions();
		$this->assertTrue( $acts->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'continue' )['ok'] );
		$this->assertSame( '0', (string) $this->row( $job->id )['cancel_requested'], 'cleared in the same statement' );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::FAILED, $done->status, 'it goes on as after any interruption, not as a cancel (' . $done->status . ' ' . $done->last_error . ')' );
		$this->assertStringContainsString( 'the site is as it was before the restore', (string) $done->last_error );
	}

	public function test_a_take_over_once_the_rollback_is_recorded_keeps_the_cancel_request(): void {
		global $wpdb;
		$this->swap_parts['batch'] = 1; // A batch per table: killed between two, the next run rolls back.
		$this->register_type();
		$before = $this->site();
		$job    = $this->at_swap();
		$this->killed_at( $job, 'batch_sent' );
		$this->killed_at( $job, 'restored' );
		$wpdb->update(
			JobRepository::table(),
			array(
				'held_by'          => self::ELSEWHERE,
				'cancel_requested' => time(), // Whoever managed it asked for the cancel.
			),
			array( 'id' => $job->id )
		);
		$wpdb->query( 'COMMIT' );
		$acts = $this->actions();
		$held = $acts->held_elsewhere( $job->id );
		$this->assertSame( 'restored', $held['assessment']['direction'], 'the control: the rollback is recorded' );
		$this->assertTrue( $acts->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $held['job'], '' ), '' )['ok'] );
		$this->assertNotSame( '0', (string) $this->row( $job->id )['cancel_requested'], 'the request is kept' );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::CANCELLED, $done->status, 'it finishes as the cancel it was (' . $done->last_error . ')' );
		$this->assertSame( $before, $this->site() );
	}

	public function test_a_take_over_read_before_a_cancel_request_writes_nothing(): void {
		global $wpdb;
		$job  = $this->held();
		$read = Plugin::instance()->jobs()->find( $job->id );
		$wpdb->update( JobRepository::table(), array( 'cancel_requested' => time() ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		try {
			Plugin::instance()->jobs()->take_over( $read, 'continue' );
			$this->fail( 'taken over with a choice made without the cancel request' );
		} catch ( \WPCheckpoint\Jobs\StaleJob $e ) {
			$this->assertSame( self::ELSEWHERE, $this->row( $job->id )['held_by'], 'nothing written' );
			$this->assertNotSame( '0', (string) $this->row( $job->id )['cancel_requested'] );
		}
		$now = Plugin::instance()->jobs()->take_over( Plugin::instance()->jobs()->find( $job->id ), 'continue' );
		$this->assertSame( 0, $now->cancel_requested, 'the control: read with it, the take-over writes' );
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

	public function test_a_job_that_is_not_this_sites_is_not_taken_over_and_nothing_is_released_while_it_holds_the_site(): void {
		$job      = $this->held();
		$original = $this->abspath . '/.maintenance';
		$this->assertFileExists( $original, 'the control: held where the swap runs' );
		copy( $original, $this->copy_dir() . '/.maintenance' ); // A copy of the site's files, its maintenance file too.
		$acts = $this->actions( true );
		$held = $acts->held_elsewhere( $job->id );
		$see  = $held['assessment'];
		$this->assertSame( HeldSite::OTHER, $see['branch'] );
		$this->assertTrue( $see['differs'] );
		$this->assertTrue( $see['file_here'], 'the control: the observation sees the file' );
		$this->assertFalse( $acts->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'continue' )['ok'], 'not this site\'s: not taken over' );
		$lines = implode( "\n", array_map( array( Plugin::instance()->job_presenter(), 'clean' ), JobPresenter::held_lines( $held['job'], $see ) ) );
		$this->assertStringContainsString( 'finish the restore or roll it back there; afterwards take its file down here with wp wpcheckpoint job release ' . $job->id, $lines, 'shared: there first, then release here' );
		$this->assertStringContainsString( 'wp wpcheckpoint job abandon ' . $job->id . ' --confirm=' . HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ), $lines, 'not shared: abandon' );
		$this->assertStringNotContainsString( 'job release ' . $job->id . ' --confirm=', $lines, 'no release offered while it holds the site' );
		$before = $this->row( $job->id );
		$outcome = $acts->release( $job->id, HeldSite::code( HeldSite::RELEASE, $job, $see['recorded'] ) );
		$this->assertFalse( $outcome['ok'], 'refused, whatever the code' );
		$this->assertStringContainsString( 'still holds the site', $outcome['message'] );
		$this->assertFileExists( $this->copy_dir() . '/.maintenance', 'the copy stays behind its maintenance page' );
		$this->assertFileExists( $original );
		$this->assertSame( $before, $this->row( $job->id ) );
	}

	public function test_once_the_job_ended_release_takes_down_only_its_own_file_here(): void {
		$job = $this->held();
		copy( $this->abspath . '/.maintenance', $this->copy_dir() . '/.maintenance' ); // A copy, its file too.
		$copy = $this->actions( true );
		$see  = $copy->held_elsewhere( $job->id )['assessment'];
		$code = HeldSite::code( HeldSite::RELEASE, $job, $see['recorded'] );
		$this->assertFalse( $copy->release( $job->id, $code )['ok'], 'the control: refused while it holds the site' );
		// The original takes it back and puts the site back: the job ends (cancelled), its file there comes down.
		$this->assertTrue( $this->actions()->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'rollback' )['ok'] );
		$ended = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::CANCELLED, $ended->status, (string) $ended->last_error );
		$this->assertFileDoesNotExist( $this->abspath . '/.maintenance' );
		$this->assertTrue( \WPCheckpoint\Restore\Maintenance::held_in( $this->copy_dir() ), 'the control: the copy\'s file stays, and does not lapse' );
		$code = HeldSite::code( HeldSite::RELEASE, $ended, ( new HeldSite( array( 'abspath' => $this->copy_dir() ) ) )->assess( $ended )['recorded'] );
		// Another restore's file in its place first: not this job's, it stays.
		$theirs = ( new \WPCheckpoint\Restore\Maintenance( $this->copy_dir(), \WPCheckpoint\Restore\Maintenance::new_mark() ) )->contents( \WPCheckpoint\Restore\Maintenance::held() );
		$ours   = (string) file_get_contents( $this->copy_dir() . '/.maintenance' );
		file_put_contents( $this->copy_dir() . '/.maintenance', $theirs );
		$this->assertTrue( $copy->release( $job->id, $code )['ok'] );
		$this->assertSame( $theirs, file_get_contents( $this->copy_dir() . '/.maintenance' ), 'another restore\'s file is not this job\'s' );
		file_put_contents( $this->copy_dir() . '/.maintenance', $ours );
		$this->assertFalse( $copy->release( $job->id, 'nope' )['ok'], 'a wrong code' );
		$this->assertFileExists( $this->copy_dir() . '/.maintenance' );
		$outcome = $copy->release( $job->id, $code );
		$this->assertTrue( $outcome['ok'], $outcome['message'] );
		$this->assertFileDoesNotExist( $this->copy_dir() . '/.maintenance', 'this job\'s file, by the mark its row kept' );
	}

	public function test_release_waits_until_the_job_has_ended_and_no_run_holds_it(): void {
		global $wpdb;
		$job = $this->held();
		copy( $this->abspath . '/.maintenance', $this->copy_dir() . '/.maintenance' );
		$this->assertTrue( $this->actions()->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'rollback' )['ok'] );
		$ended = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::CANCELLED, $ended->status, (string) $ended->last_error );
		$code = HeldSite::code( HeldSite::RELEASE, $ended, ( new HeldSite( array( 'abspath' => $this->copy_dir() ) ) )->assess( $ended )['recorded'] );
		$set  = static function ( array $columns ) use ( $wpdb, $job ): void {
			$wpdb->update( JobRepository::table(), $columns, array( 'id' => $job->id ) );
			$wpdb->query( 'COMMIT' );
		};
		// Queued again (a retry's attempt carries the same mark) or held by a live run: the file stays.
		foreach ( array(
			'queued'   => array( 'status' => Job::QUEUED ),
			'a run'    => array(
				'lock_token'   => 'live',
				'locked_until' => time() + 300,
			),
		) as $what => $columns ) {
			$set( $columns );
			$outcome = $this->actions( true )->release( $job->id, $code );
			$this->assertFalse( $outcome['ok'], $what . ': refused' );
			$this->assertStringContainsString( 'has not ended, or a run holds it', $outcome['message'], $what );
			$this->assertTrue( \WPCheckpoint\Restore\Maintenance::held_in( $this->copy_dir() ), $what . ': the file stays' );
			$set(
				array(
					'status'       => Job::CANCELLED,
					'lock_token'   => '',
					'locked_until' => 0,
				)
			);
		}
		// A run takes the job between the checks and the file: read again right before, the file stays.
		$racing = new JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new Loopback( false ),
			new HeldSite(
				array(
					'abspath' => $this->copy_dir(),
					'at'      => static function ( string $point ) use ( $set ): void {
						if ( 'release_confirmed' === $point ) {
							$set(
								array(
									'lock_token'   => 'live',
									'locked_until' => time() + 300,
								)
							);
						}
					},
				)
			)
		);
		$this->assertFalse( $racing->release( $job->id, $code )['ok'], 'a run took it meanwhile: refused' );
		$this->assertTrue( \WPCheckpoint\Restore\Maintenance::held_in( $this->copy_dir() ), 'the file stays' );
		$set(
			array(
				'lock_token'   => '',
				'locked_until' => 0,
			)
		);
		$outcome = $this->actions( true )->release( $job->id, $code );
		$this->assertTrue( $outcome['ok'], 'the control: ended, no run: released (' . $outcome['message'] . ')' );
		$this->assertFalse( \WPCheckpoint\Restore\Maintenance::held_in( $this->copy_dir() ) );
	}

	/**
	 * Another installation of the plugin on this database: a copy of the site in another WordPress directory, with a
	 * token of its own (this site's recorded as copied, as its detection records it), seen from that directory. The
	 * state is this request's only: the stored one stays this site's.
	 */
	private function another_installation( ?callable $at = null ): JobActions {
		$dirs = new \WPCheckpoint\Support\Directories();
		$dirs->base();
		$state                    = $dirs->state();
		$state['copied_tokens'][] = (string) $state['token'];
		$state['token']           = self::ANOTHER;
		$state['past_tokens']     = array();
		$this->replace_internal( $dirs, 'state', $state );
		$repo = new JobRepository( $dirs );
		$this->assertTrue( $repo->holds_own_token(), 'the control: a token of its own' );
		return new JobActions(
			$repo,
			Plugin::instance()->runner(),
			new Loopback( false ),
			new HeldSite(
				array_filter(
					array(
						'abspath'   => $this->copy_dir(),
						'site_dirs' => function (): array {
							return array( 'uploads' => $this->copy_dir() . '/wp-content/uploads' );
						},
						'at'        => $at,
					)
				)
			)
		);
	}

	public function test_a_job_abandoned_from_a_copy_on_a_shared_database_keeps_its_tables_and_the_site_takes_it_back(): void {
		$this->swap_parts['batch'] = 1; // A batch per table: killed after the first, a table is moved aside.
		$this->register_type();
		$before = $this->site();
		$job    = $this->at_swap();
		$this->killed_at( $job, 'batch_sent' );
		$job = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::SITE_CHANGING, $job->site_state, 'the control: half swapped' );
		$tables = $this->tables_of( $job );
		$this->assertNotSame( array(), preg_grep( '/\Awcpold/', $tables ), 'the control: a table was moved aside' );
		copy( $this->abspath . '/.maintenance', $this->copy_dir() . '/.maintenance' );
		// The copy, wrong that its database is not shared with this site, abandons the restore.
		$copy = $this->another_installation();
		$held = $copy->held_elsewhere( $job->id );
		$this->assertSame( HeldSite::OTHER, $held['assessment']['branch'] );
		$this->assertTrue( $held['assessment']['differs'] );
		$outcome = $copy->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $held['assessment']['recorded'] ) );
		$this->assertTrue( $outcome['ok'], $outcome['message'] );
		$this->assertStringContainsString( 'that site stays half swapped.', $outcome['message'] );
		$this->assertStringContainsString( 'uninstalling WP Checkpoint leaves everything in place', $outcome['message'], 'and what that does to an uninstall' );
		$this->assertStringNotContainsString( 'take the restore over', $outcome['message'], 'no promise of a later take-over' );
		$this->assertFalse( \WPCheckpoint\Restore\Maintenance::held_in( $this->copy_dir() ), 'the copy\'s file is down' );
		// Not cancelled, from either side: a cancel request on its row would turn a take-over's "continue" into a
		// rollback.
		foreach ( array(
			'the copy'  => $copy,
			'this site' => $this->actions(),
		) as $what => $acts ) {
			$cancel = $acts->cancel( $job->id );
			$this->assertSame( 'abandoned', $cancel['reason'], $what );
			$this->assertSame( '0', (string) $this->row( $job->id )['cancel_requested'], $what . ': no cancel request recorded' );
		}
		$this->assertSame( self::ANOTHER, $this->row( $job->id )['held_by'], 'abandoned by the copy' );
		$this->assertSame( $tables, $this->tables_of( $job ), 'its tables are kept, the ones moved aside among them' );
		Plugin::instance()->jobs()->reap_residue();
		$this->assertSame( $tables, $this->tables_of( $job ), 'nor taken by this site\'s reaper' );
		try {
			$copy->retry( $job->id );
			$this->fail( 'the copy that abandoned it runs it again' );
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'it is not run again', $e->getMessage() );
		}
		// This site: still behind its maintenance page and warned about; nothing released, not run as it is.
		$here = $this->actions();
		$this->assertTrue( \WPCheckpoint\Restore\Maintenance::held_in( $this->abspath ), 'the control: the file here is held' );
		$this->assertContains( $job->id, Plugin::instance()->jobs()->holding_site(), 'warned about here' );
		$abandoned = Plugin::instance()->jobs()->find( $job->id );
		$release   = $here->release( $job->id, HeldSite::code( HeldSite::RELEASE, $abandoned, $held['assessment']['recorded'] ) );
		$this->assertFalse( $release['ok'], 'not released at the WordPress directory its plan records' );
		$this->assertStringContainsString( 'This site may still be half swapped by it', $release['message'] );
		$this->assertTrue( \WPCheckpoint\Restore\Maintenance::held_in( $this->abspath ), 'the file here stays' );
		try {
			Plugin::instance()->job_actions()->retry( $job->id );
			$this->fail( 'retried here without being taken over' );
		} catch ( InvalidTransition $e ) {
			$this->assertStringContainsString( 'take it over here', $e->getMessage() );
		}
		// Taken back by this site, by the same evidence as any take-over, and rolled back.
		$back = $here->held_elsewhere( $job->id );
		$this->assertSame( HeldSite::SITE, $back['assessment']['branch'], (string) $back['assessment']['why'] );
		$lines = implode( "\n", JobPresenter::held_lines( $back['job'], $back['assessment'] ) );
		$this->assertStringContainsString( 'was abandoned from another installation', $lines );
		$code = HeldSite::code( HeldSite::REBIND, $back['job'], '' );
		$this->assertStringContainsString( 'wp wpcheckpoint job rebind ' . $job->id . ' --confirm=' . $code . ' --then=rollback', $lines );
		$outcome = $here->rebind( $job->id, $code, 'rollback' );
		$this->assertTrue( $outcome['ok'], $outcome['message'] );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( '', $now->failure_reason, 'the abandon is lifted' );
		$this->assertSame( '', (string) $now->last_error, 'and its message with it' );
		$this->assertNotSame( '0', (string) $this->row( $job->id )['cancel_requested'], 'the control: the rollback\'s cancel request is seen through the row' );
		$this->assertSame( (string) Plugin::instance()->directories()->state()['token'], $now->held_by );
		Plugin::instance()->job_actions()->retry( $job->id );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::CANCELLED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'the site as it was before the swap: the tables moved aside were there to put back' );
		$this->assertFalse( \WPCheckpoint\Restore\Maintenance::held_in( $this->abspath ) );
		$this->assertSame( array(), $this->tables_of( $done ), 'and its tables reclaimed once it ended here' );
	}

	public function test_a_job_abandoned_elsewhere_is_released_only_where_it_was_given_up(): void {
		global $wpdb;
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_aside_recorded' );
		$job = Plugin::instance()->jobs()->find( $job->id );
		$copy = $this->another_installation();
		$see  = $copy->held_elsewhere( $job->id )['assessment'];
		$this->assertTrue( $copy->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) )['ok'] );
		$abandoned = Plugin::instance()->jobs()->find( $job->id );
		$code      = HeldSite::code( HeldSite::RELEASE, $abandoned, $see['recorded'] );
		// A third WordPress directory with the file (another copy): the job holds the site there too, whatever the
		// directories say, until it is taken over or cleaned up.
		$third = $this->sandbox . '/third';
		mkdir( $third . '/wp-content/uploads', 0755, true );
		copy( $this->abspath . '/.maintenance', $third . '/.maintenance' );
		$there = new JobActions( Plugin::instance()->jobs(), Plugin::instance()->runner(), new Loopback( false ), new HeldSite( array( 'abspath' => $third ) ) );
		$from  = $there->held_elsewhere( $job->id );
		$this->assertTrue( $from['assessment']['differs'], 'the control: positively another directory' );
		$lines = implode( "\n", JobPresenter::held_lines( $from['job'], $from['assessment'] ) );
		$this->assertStringContainsString( 'This site may still be half swapped by it', $lines );
		$this->assertStringNotContainsString( 'job release', $lines, 'no release offered' );
		foreach ( array(
			'a third directory'          => array( $there, $third ),
			'the directory it was for'   => array( $this->actions(), $this->abspath ),
		) as $what => $at ) {
			$outcome = $at[0]->release( $job->id, $code );
			$this->assertFalse( $outcome['ok'], $what . ': refused' );
			$this->assertStringContainsString( 'This site may still be half swapped by it', $outcome['message'], $what );
			$this->assertTrue( \WPCheckpoint\Restore\Maintenance::held_in( $at[1] ), $what . ': the file stays' );
		}
		// Abandoned once: not again from another place (that would make it that installation's to have given up).
		$again = $there->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $abandoned, $see['recorded'] ) );
		$this->assertFalse( $again['ok'] );
		$this->assertStringContainsString( 'already abandoned', $again['message'] );
		$this->assertSame( self::ANOTHER, $this->row( $job->id )['held_by'], 'still the copy\'s to have given up' );
		// Where it was given up, it holds nothing: its file there comes down (the control, same command). Read again
		// right before the file goes: given up by another meanwhile, it stays.
		copy( $this->abspath . '/.maintenance', $this->copy_dir() . '/.maintenance' );
		$racing = $this->another_installation(
			static function ( string $point ) use ( $wpdb, $job ): void {
				if ( 'release_confirmed' === $point ) {
					$wpdb->update( JobRepository::table(), array( 'held_by' => self::ELSEWHERE ), array( 'id' => $job->id ) );
					$wpdb->query( 'COMMIT' );
				}
			}
		);
		$outcome = $racing->release( $job->id, $code );
		$this->assertFalse( $outcome['ok'], 'given up by another meanwhile: refused' );
		$this->assertStringContainsString( 'This site may still be half swapped by it', $outcome['message'] );
		$this->assertTrue( \WPCheckpoint\Restore\Maintenance::held_in( $this->copy_dir() ), 'the file stays' );
		$wpdb->update( JobRepository::table(), array( 'held_by' => self::ANOTHER ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$outcome = $copy->release( $job->id, $code );
		$this->assertTrue( $outcome['ok'], $outcome['message'] );
		$this->assertFalse( \WPCheckpoint\Restore\Maintenance::held_in( $this->copy_dir() ), 'released where it was given up' );
	}

	public function test_a_code_shown_before_another_take_over_confirms_nothing(): void {
		global $wpdb;
		$job  = $this->held();
		$acts = $this->actions( true );
		$see  = $acts->held_elsewhere( $job->id )['assessment'];
		$code = HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] );
		// Meanwhile another installation took it over.
		$wpdb->update( JobRepository::table(), array( 'held_by' => 'eeeeeeeeeeee' ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$this->assertFalse( $acts->abandon( $job->id, $code )['ok'], 'the code was for the job as managed then' );
		$this->assertSame( 'eeeeeeeeeeee', $this->row( $job->id )['held_by'] );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertTrue( $acts->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $now, $see['recorded'] ) )['ok'], 'the control: the code for the job as it is now' );
		$this->expectException( \WPCheckpoint\Jobs\StaleJob::class );
		Plugin::instance()->jobs()->take_over( $now, 'continue' ); // Read before the abandon: managed by another as read.
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
		$before  = $this->row( $job->id );
		$outcome = $acts->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) );
		$this->assertFalse( $outcome['ok'] );
		$this->assertStringContainsString( 'not positively another', $outcome['message'], 'refused for that reason' );
		$this->assertFileExists( $this->abspath . '/.maintenance' );
		$this->assertSame( $before, $this->row( $job->id ) );
	}

	public function test_the_same_directory_by_another_spelling_is_not_another(): void {
		global $wpdb;
		$job  = $this->held();
		$link = $this->sandbox . '/site-by-another-name';
		symlink( $this->abspath, $link );
		// The plan records the WordPress directory by another spelling of the same directory (a bind mount, a file
		// system that ignores case: here a link, which the plan would never record, to have two spellings).
		$table = $wpdb->base_prefix . \WPCheckpoint\Restore\SwapPlan::TABLE;
		$wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET live = %s WHERE job_id = %d AND kind = 'site'", $link, $job->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plan table.
		$wpdb->query( 'COMMIT' );
		$acts = new JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new Loopback( false ),
			new HeldSite(
				array(
					'abspath'   => $this->abspath,
					'site_dirs' => function (): array {
						return array( 'uploads' => $this->copy_dir() . '/wp-content/uploads' );
					},
				)
			)
		);
		$see = $acts->held_elsewhere( $job->id )['assessment'];
		$this->assertSame( $link, $see['recorded'], 'the control: two spellings' );
		$this->assertFalse( $see['differs'], 'one directory: not another' );
		$this->assertFalse( $acts->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) )['ok'] );
		$this->assertFileExists( $this->abspath . '/.maintenance' );
		$other = ( new HeldSite( array( 'abspath' => $this->copy_dir() ) ) )->assess( $job );
		$this->assertTrue( $other['differs'], 'the control: another directory is' );
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
		$tables = $this->tables_of( $job );
		$this->assertNotSame( array(), $tables, 'the control: it made tables' );
		$held = \WPCheckpoint\Support\Uninstaller::holding();
		$this->assertSame( array( 'all' => 1, 'abandoned' => 0 ), $held, 'the control: it holds the site, not abandoned' );
		$this->assertStringContainsString( 'while a restore holds the site changed;', \WPCheckpoint\Support\Uninstaller::held_back( $held ) );
		$this->assertStringContainsString( 'Reinstall WP Checkpoint to finish or undo the restore.', \WPCheckpoint\Support\Uninstaller::held_back( $held ), 'the control: said where a reinstall finishes it' );
		$outcome = $acts->abandon( $job->id, $code );
		$this->assertTrue( $outcome['ok'], $outcome['message'] );
		$this->assertStringContainsString( 'that site stays half swapped.', $outcome['message'] );
		$this->assertStringContainsString( 'Its tables are kept, the ones its swap moved aside (named wcpold…) among them', $outcome['message'] );
		$this->assertFileDoesNotExist( $this->copy_dir() . '/.maintenance' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $now->status );
		$this->assertSame( Job::FAILURE_FINAL, $now->failure_kind );
		$this->assertSame( Job::REASON_ABANDONED, $now->failure_reason );
		$this->assertSame( $job->site_state, $now->site_state, 'what it did stays recorded' );
		$this->assertSame( $site, $this->site(), 'the site it held is not touched: its paths, tables and file' );
		$this->assertNotContains( $job->id, Plugin::instance()->jobs()->holding_site(), 'no longer warned about' );
		// An uninstall is held back by every abandoned job, whoever gave it up: which installation this is cannot be
		// told there (it runs without the plugin; the stored state may be another installation's on a shared database).
		$held = \WPCheckpoint\Support\Uninstaller::holding();
		$this->assertSame( array( 'all' => 1, 'abandoned' => 1 ), $held, 'abandoned here: an uninstall is held back' );
		$this->assertStringContainsString( 'a restore was abandoned (1 of the 1 that hold a site changed): an abandoned restore keeps its tables', \WPCheckpoint\Support\Uninstaller::held_back( $held ), 'and the log says why' );
		$this->assertStringContainsString( 'They stay until the site the restore was started on takes it over; nothing cleans an abandoned restore up yet.', \WPCheckpoint\Support\Uninstaller::held_back( $held ) );
		$this->assertStringNotContainsString( 'Reinstall', \WPCheckpoint\Support\Uninstaller::held_back( $held ), 'a reinstall here does not finish it' );
		// Abandoned from another installation (its token in held_by): warned about here, and held back the same way.
		global $wpdb;
		$own = $this->row( $job->id )['held_by'];
		$wpdb->update( JobRepository::table(), array( 'held_by' => self::ELSEWHERE ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$this->assertContains( $job->id, Plugin::instance()->jobs()->holding_site(), 'abandoned elsewhere: warned about' );
		$this->assertSame( array( 'all' => 1, 'abandoned' => 1 ), \WPCheckpoint\Support\Uninstaller::holding(), 'abandoned elsewhere: held back' );
		$wpdb->update( JobRepository::table(), array( 'held_by' => $own ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$this->assertNull( $acts->held_elsewhere( $job->id ) );
		try {
			Plugin::instance()->job_actions()->retry( $job->id );
			$this->fail( 'an abandoned job is not retried' );
		} catch ( InvalidTransition $e ) {
			$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $job->id )->status );
		}
		$this->assertSame( $tables, $this->tables_of( $job ), 'its tables are kept' );
		Plugin::instance()->jobs()->reap_residue();
		$this->assertSame( $tables, $this->tables_of( $job ), 'by the reaper too' );
		$this->assertFalse( $acts->abandon( $job->id, $code )['ok'], 'once is enough' );
	}

	public function test_an_abandon_that_died_after_taking_the_file_down_is_finished_by_another(): void {
		$job = $this->held();
		copy( $this->abspath . '/.maintenance', $this->copy_dir() . '/.maintenance' );
		$acts = $this->actions( true );
		$see  = $acts->held_elsewhere( $job->id )['assessment'];
		// The first step of an abandon, and nothing after it (the process died there).
		$dying = new JobActions(
			Plugin::instance()->jobs(),
			Plugin::instance()->runner(),
			new Loopback( false ),
			new HeldSite(
				array(
					'abspath'   => $this->copy_dir(),
					'site_dirs' => function (): array {
						return array( 'uploads' => $this->copy_dir() . '/wp-content/uploads' );
					},
					'at'        => static function ( string $point ): void {
						throw new \RuntimeException( 'died at ' . $point );
					},
				)
			)
		);
		try {
			$dying->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) );
			$this->fail( 'the run died between the two steps' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'died at abandon_released', $e->getMessage() );
		}
		$this->assertFileDoesNotExist( $this->copy_dir() . '/.maintenance', 'the first step was done' );
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
		Plugin::instance()->jobs()->take_over( $read, 'continue' ); // Read before the abandon: its finished_at moved.
	}
}
