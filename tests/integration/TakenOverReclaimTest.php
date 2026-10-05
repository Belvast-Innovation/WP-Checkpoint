<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\HeldSite;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\Loopback;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * What a restore taken over from another installation leaves, as the evidence allows (JobRepository::reclaim_scope()):
 * a site's restore killed in its swap, then the site moved to another release and the new directory kept (it holds
 * a token of its own; the job's is copied). From there the job is another installation's: taken over (rebind) and
 * run to its end, or given up (abandon). Its names carry the token it was started with.
 */
final class TakenOverReclaimTest extends SwapTestCase {

	/** @var array<string, mixed> The moved site's Directories context. */
	private $moved = array();

	/** @var string The token the job was started with. */
	private $token = '';

	/**
	 * The restore, started at release 1 and killed at $seam; then the move to release 2, the new directory kept.
	 */
	private function taken( string $seam ): Job {
		$root = $this->sandbox . '/install';
		foreach ( array( 'releases/1/wp-includes', 'releases/2/wp-includes', 'content' ) as $dir ) {
			mkdir( $root . '/' . $dir, 0755, true );
		}
		$context       = static function ( string $release ) use ( $root ): array {
			return array(
				'is_web_request' => false,
				'document_root'  => '',
				'abspath'        => $root . '/releases/' . $release . '/',
				'content_dir'    => $root . '/content',
				'custom_dir'     => '',
			);
		};
		$this->storage = $context( '1' );
		$job           = $this->at_swap();
		$this->killed_at( $job, $seam );
		$this->storage = array();
		$this->token   = $job->storage_token;
		$this->moved   = $context( '2' );
		$moved         = new Directories( $this->moved );
		$this->assertNotSame( '', $moved->base(), $moved->last_error() );
		$this->assertTrue( (bool) $moved->state()['clone_detected'], 'the control: the move is detected' );
		$moved->acknowledge_clone(); // The new directory kept: the job's token is copied, not held.
		$job = $this->repo()->find( $job->id );
		$this->assertFalse( $this->repo()->manages( $job ), 'the control: from the moved site it is another installation\'s' );
		$this->assertNotSame( $this->token, (string) ( new Directories( $this->moved ) )->state()['token'] );
		return $job;
	}

	private function repo(): JobRepository {
		$repo = new JobRepository( new Directories( $this->moved ) );
		$repo->with_site_dirs(
			function (): array {
				return $this->dirs;
			}
		);
		return $repo;
	}

	private function actions( bool $copy = false ): JobActions {
		$parts = $copy
			? array(
				'abspath'   => $this->sandbox . '/install/releases/2',
				'site_dirs' => function (): array {
					return array( 'uploads' => $this->sandbox . '/install/content' );
				},
			)
			: array(
				'abspath'   => $this->abspath,
				'site_dirs' => function (): array {
					return $this->dirs;
				},
			);
		$repo = $this->repo();
		return new JobActions( $repo, $this->runner(), new Loopback( false ), new HeldSite( $parts ) );
	}

	private function runner(): Runner {
		return new Runner( $this->repo(), Plugin::instance()->job_types(), new Redactor( Redactor::installation_secrets() ), array( 'cli' => true ) );
	}

	/**
	 * WP-CLI ticks from the moved site until the job ends or waits.
	 */
	private function run_here( int $id ): Job {
		$runner = $this->runner();
		for ( $i = 0; $i < 50; $i++ ) {
			$result = $this->autocommit(
				static function () use ( $runner, $id ): TickResult {
					return $runner->tick( $id, microtime( true ) );
				}
			);
			$now = $this->repo()->find( $id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) || TickResult::WAITING === $result->status ) {
				return $now;
			}
		}
		$this->fail( 'the job did not end' );
	}

	/**
	 * What of the job is left: its tables (its own names), staging roots and probes next to the site, plan rows.
	 *
	 * @return array{tables: string[], staging: string[], plan: int}
	 */
	private function left( int $id ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$tables  = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( TempTables::job_prefix( $this->token, $id ) ) . '%' ) );
		$staging = array();
		foreach ( Residue::scan_site( Residue::site_dirs( $this->dirs ), array( $this->token ) ) as $entry ) {
			if ( $entry['id'] === $id ) {
				$staging[] = $entry['kind'];
			}
		}
		$plan = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `' . $wpdb->base_prefix . 'wpcheckpoint_swap_plan` WHERE job_id = %d', $id ) );
		return array(
			'tables'  => array_map( 'strval', $tables ),
			'staging' => $staging,
			'plan'    => $plan,
		);
	}

	public function test_a_site_whose_token_is_a_copied_one_takes_nothing_over(): void {
		$job   = $this->taken( 'dir_aside_recorded' );
		$this->assertSame( '', $job->held_by, 'the control: nobody took it over yet' );
		// A request of a copy that holds no token of its own yet: the token it carries is recorded as copied (between
		// a detection and the copy's own directory; resolving the storage again would take it back, so the request's
		// state is set, not the stored one).
		$dirs = new Directories( $this->moved );
		$dirs->base();
		$state                    = $dirs->state();
		$state['copied_tokens'][] = (string) $state['token'];
		$this->replace_internal( $dirs, 'state', $state );
		$repo = new JobRepository( $dirs );
		$this->assertFalse( $repo->manages( $job ), 'the control' );
		try {
			$repo->take_over( $job, false );
			$this->fail( 'taken over with a token this site does not hold' );
		} catch ( \WPCheckpoint\Jobs\StaleJob $e ) {
			$this->assertSame( '', $repo->find( $job->id )->held_by, 'nothing written' );
		}
	}

	public function test_uninstall_drops_the_tables_of_a_job_taken_over_by_their_names(): void {
		$job = $this->taken( 'dir_aside_recorded' );
		$this->assertTrue( $this->actions()->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'continue' )['ok'] );
		$done = $this->run_here( $job->id ); // Put back; final here, its tables not yet reaped.
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertNotSame( array(), $this->left( $job->id )['tables'], 'the control: its tables are there' );
		\WPCheckpoint\Support\Schema::drop();
		$this->assertSame( array(), $this->left( $job->id )['tables'], 'dropped by uninstall, by the names it made' );
		\WPCheckpoint\Support\Schema::ensure(); // The test's tables back for what follows.
	}

	public function test_a_take_over_or_abandon_read_before_another_take_over_writes_nothing(): void {
		global $wpdb;
		$job = $this->taken( 'dir_aside_recorded' );
		$this->assertTrue( $this->actions()->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'continue' )['ok'] );
		$read = $this->repo()->find( $job->id );
		// Another installation took it over meanwhile: only held_by changed.
		$wpdb->update( JobRepository::table(), array( 'held_by' => 'eeeeeeeeeeee' ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		foreach ( array( 'take_over', 'abandon_held' ) as $write ) {
			try {
				'take_over' === $write ? $this->repo()->take_over( $read, false ) : $this->repo()->abandon_held( $read, 'x' );
				$this->fail( $write . ': written over the other take-over' );
			} catch ( \WPCheckpoint\Jobs\StaleJob $e ) {
				$this->assertSame( 'eeeeeeeeeeee', $this->repo()->find( $job->id )->held_by, $write );
				$this->assertSame( '', $this->repo()->find( $job->id )->failure_reason, $write );
			}
		}
	}

	public function test_a_job_taken_over_and_rolled_back_leaves_nothing(): void {
		$before = $this->site();
		$job    = $this->taken( 'dir_aside_recorded' );
		$this->assertNotSame( array(), $this->left( $job->id )['tables'], 'the control: it made tables, by its own names' );
		$this->assertNotSame( array(), $this->left( $job->id )['staging'], 'the control: and staging' );
		$acts = $this->actions();
		$this->assertSame( HeldSite::SITE, $acts->held_elsewhere( $job->id )['assessment']['branch'] );
		$this->assertTrue( $acts->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'rollback' )['ok'] );
		$done = $this->run_here( $job->id );
		$this->assertSame( Job::CANCELLED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site(), 'the site as it was' );
		$this->assertSame(
			array(
				'tables'  => array(),
				'staging' => array(),
				'plan'    => 0,
			),
			$this->left( $job->id ),
			'nothing of it left, by the names it made'
		);
	}

	public function test_a_job_taken_over_and_put_back_ends_final_and_the_reaper_leaves_nothing(): void {
		$before = $this->site();
		$job    = $this->taken( 'dir_aside_recorded' );
		$acts   = $this->actions();
		$this->assertTrue( $acts->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), 'continue' )['ok'] );
		$done = $this->run_here( $job->id );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertSame( Job::FAILURE_FINAL, $done->failure_kind, 'no retry that could not work' );
		$this->assertStringContainsString( 'the site stays as it is. Start the restore again from here.', (string) $done->last_error );
		$this->assertSame( $before, $this->site() );
		$this->assertNotSame( array(), $this->left( $job->id )['tables'], 'the control: left until the reaper' );
		$this->repo()->reap_residue();
		$left = $this->left( $job->id );
		$this->assertSame( array(), $left['tables'] );
		$this->assertSame( array(), $left['staging'] );
	}

	public function test_a_job_taken_over_and_finished_leaves_what_a_restore_finished_here_leaves(): void {
		$job  = $this->taken( 'committed' );
		$acts = $this->actions();
		$this->assertTrue( $acts->rebind( $job->id, HeldSite::code( HeldSite::REBIND, $job, '' ), '' )['ok'] );
		$done = $this->run_here( $job->id );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$taken = $this->left( $job->id );
		$this->assertSame( JobRepository::RECLAIM_ALL, JobRepository::reclaim_scope( $done, Directories::own_tokens( ( new Directories( $this->moved ) )->state() ) ), 'this installation\'s to reclaim, by the names it made' );
		$this->undo( $done );
		// The same restore finished by the installation that started it: what it keeps for the undo, of the same kinds.
		$this->release_backups();
		$this->tear_down_swap();
		$this->set_up_swap();
		$local = $this->cli_run( $this->at_swap() );
		$this->assertSame( Job::COMPLETED, $local->status, (string) $local->last_error );
		global $wpdb;
		$here = array(
			'tables'  => (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( TempTables::job_prefix( $local->storage_token, $local->id ) ) . '%' ) ),
			'staging' => array_column(
				array_filter(
					Residue::scan_site( Residue::site_dirs( $this->dirs ), array( $local->storage_token ) ),
					static function ( array $entry ) use ( $local ): bool {
						return $entry['id'] === $local->id;
					}
				),
				'kind'
			),
		);
		$this->assertSame( count( $here['tables'] ), count( $taken['tables'] ), 'as many tables kept as a restore finished here keeps' );
		$this->assertSame( $here['staging'], $taken['staging'] );
		$this->undo( $local );
	}

	public function test_an_abandoned_job_leaves_no_table_and_touches_no_file_at_the_paths_its_plan_records(): void {
		$job = $this->taken( 'dir_aside_recorded' );
		$site    = $this->site();
		$staging = $this->left( $job->id )['staging'];
		$this->assertNotSame( array(), $staging, 'the control: its staging is there, at the paths its plan records' );
		$acts = $this->actions( true );
		$see  = $acts->held_elsewhere( $job->id )['assessment'];
		$this->assertSame( HeldSite::OTHER, $see['branch'] );
		$this->assertTrue( $see['differs'] );
		$this->assertTrue( $acts->abandon( $job->id, HeldSite::code( HeldSite::ABANDON, $job, $see['recorded'] ) )['ok'] );
		$left = $this->left( $job->id );
		$this->assertSame( array(), $left['tables'], 'its tables, by the names it made, are gone' );
		$this->assertSame( $staging, $left['staging'], 'nothing at the paths its plan records is touched' );
		$this->assertGreaterThan( 0, $left['plan'], 'its plan stays' );
		$this->assertSame( $site, $this->site(), 'the site it held is not touched' );
		$this->repo()->reap_residue();
		$this->assertSame( $staging, $this->left( $job->id )['staging'], 'nor by the reaper' );
	}
}
