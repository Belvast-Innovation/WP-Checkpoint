<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\Undo;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * The swap's must-use plugin (Restore\Loader) through a swap, in the sandbox's must-use plugins directory: put up
 * before the maintenance file; however the swap ends (made, undone, put back), it is nowhere afterwards, not in the
 * directory in place, not where the swap moved that directory aside, not in the staged one. A site that had no such
 * directory has one after the preflight, and the swap goes as any other. A directory gone since the preflight, or
 * another file under the loader's name, leaves the swap to go on without the loader, said in the job's log, at the
 * terminal and by job status; the other file is never deleted, by the swap, the reaper, a deactivation or an
 * uninstall. WP-CLI run as another user than the files' owner is said the same way, the users' names at the terminal
 * only.
 */
final class LoaderSwapTest extends SwapTestCase {

	/** @var string[] What the swap said at the terminal. */
	private $said = array();

	/** @var bool Whether the must-use plugins directory goes right before the final check looks at the site. */
	private $drop_mu = false;

	/** @var array<string, string[]> Seam => where the loader was then (loader_in()). */
	private $where = array();

	/** @var array<string, string> Seam => what job status said of the fallback then. */
	private $status_at = array();

	/** @var Job|null The job the seams look at. */
	private $watched = null;

	public function set_up(): void {
		parent::set_up();
		$this->swap_parts['say'] = function ( string $text ): void {
			$this->said[] = $text;
		};
		$this->swap_parts['at']  = function ( string $point ): void {
			$this->seams[] = $point;
			if ( null !== $this->watched && in_array( $point, array( 'log_recorded', 'loader_recorded', 'maintenance', 'carried', 'committed' ), true ) ) {
				$this->where[ $point ]     = $this->loader_in( $this->watched );
				$this->status_at[ $point ] = (string) Plugin::instance()->job_presenter()->present( Plugin::instance()->jobs()->find( $this->watched->id ), false )['web_fallback'];
			}
		};
		$this->register_type();
	}

	protected function check_parts( array $more = array() ): array {
		$parts = parent::check_parts( $more );
		if ( isset( $parts['site_dirs'] ) ) {
			$inner              = $parts['site_dirs'];
			$parts['site_dirs'] = function () use ( $inner ): array {
				if ( $this->drop_mu ) {
					// After the preflight (which put a file there), before the plan is made: someone removes the directory.
					Sandbox::remove( $this->dirs['mu-plugins'] );
				}
				return (array) call_user_func( $inner );
			};
		}
		return $parts;
	}

	/**
	 * The places of the must-use plugins directory: in place, and, when the plan moves it, moved aside and staged.
	 *
	 * @param Job $job Job.
	 * @return array<string, string>
	 */
	private function places( Job $job ): array {
		$out = array( 'live' => $this->dirs['mu-plugins'] );
		foreach ( $this->plan_of( $job )['dirs'] as $entry ) {
			if ( 'mu-plugins' === basename( $entry['live'] ) ) {
				$out['old']   = $entry['old'];
				$out['stage'] = $entry['stage'];
			}
		}
		return $out;
	}

	/**
	 * Where a file under the loader's name is now.
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	private function loader_in( Job $job ): array {
		clearstatcache();
		$out = array();
		foreach ( $this->places( $job ) as $place => $dir ) {
			if ( file_exists( Loader::path( $dir ) ) ) {
				$out[] = $place;
			}
		}
		return $out;
	}

	/**
	 * What the swap recorded of the loader on the job.
	 *
	 * @param Job $job Job.
	 * @return mixed
	 */
	private static function recorded( Job $job, string $key ) {
		return Plugin::instance()->jobs()->find( $job->id )->options['recorded'][ $key ] ?? null;
	}

	/**
	 * Run the restore to the swap, then the swap by WP-CLI to its end, the seams watching the loader.
	 *
	 * @param callable|null $before function(): void, between the backup and the restore.
	 * @return Job
	 */
	private function swapped( $before = null ): Job {
		$job           = $this->at_swap( array(), $before );
		$this->watched = $job;
		return $this->cli_run( $job );
	}

	public function test_a_swap_that_is_made_leaves_no_loader_in_place_aside_or_staged(): void {
		$done = $this->swapped();
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertSame( array(), $this->where['log_recorded'], 'not before its turn' );
		$this->assertSame( array( 'live' ), $this->where['loader_recorded'], 'put up before the maintenance file' );
		$this->assertSame( array( 'live' ), $this->where['maintenance'] );
		$this->assertSame( array( 'old' ), $this->where['carried'], 'the control: it went aside with the site\'s own must-use plugins' );
		$this->assertSame( array( 'old' ), $this->where['committed'], 'still there while the swap may be reversed: it would come back in place with the directory' );
		$this->assertSame( array(), $this->loader_in( $done ), 'taken out of there once the swap is done' );
		$places = $this->places( $done );
		$this->assertFileExists( $places['old'] . '/live-mu.php', 'the control: the site\'s own must-use plugins are kept aside for an undo' );
		$this->assertFileExists( $places['live'] . '/restored-mu.php' );
		$this->assertContains( self::recorded( $done, SwapStep::FALLBACK_RECORD ), array( null, '' ) );
		$this->assertSame( array(), $this->said );
		$this->assertSame( array( '' ), array_values( array_unique( $this->status_at ) ), 'job status says nothing of it' );
	}

	public function test_an_undo_puts_the_sites_own_must_use_plugins_back_without_the_loader(): void {
		$before = $this->site();
		$done   = $this->swapped();
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$actions = Plugin::instance()->job_actions();
		$actions->undo_gate(
			new Undo(
				Plugin::instance()->jobs(),
				array(
					'abspath'       => $this->abspath,
					'restore_types' => array( $this->type ),
				)
			)
		);
		$told = $actions->undo( $done->id, '' );
		$this->assertTrue( $told['ok'], $told['message'] );
		$this->assertTrue( $actions->undo( $done->id, $told['code'] )['ok'] );
		$undone = $this->cli_run( Plugin::instance()->jobs()->find( $done->id ) );
		$this->assertSame( Job::UNDONE, $undone->status, (string) $undone->last_error );
		$this->assertSame( array(), $this->loader_in( $undone ), 'not in place, not aside, not staged' );
		$this->assertSame( $before, $this->site(), 'the site as it was, its must-use plugins directory as it was' );
		$this->assertFileExists( $this->dirs['mu-plugins'] . '/live-mu.php', 'the control: the site\'s own are back in place' );
	}

	/**
	 * Where the WP-CLI run is killed, and where the loader is then.
	 *
	 * @return array<string, array{0: string, 1: int, 2: string[]}>
	 */
	public function kills(): array {
		return array(
			'the maintenance file up'   => array( 'maintenance', 1, array( 'live' ) ),
			'every directory swapped'   => array( 'carried', 1, array( 'old' ) ),
			'the renames gone through'  => array( 'committed', 1, array( 'old' ) ),
		);
	}

	/**
	 * @dataProvider kills
	 */
	public function test_a_swap_put_back_by_wp_cli_leaves_no_loader_either( string $seam, int $nth, array $there ): void {
		$before = $this->site();
		$job    = $this->at_swap();
		$this->killed_at( $job, $seam, $nth );
		$killed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( $there, $this->loader_in( $killed ), 'the control: where the killed run left it' );
		$done = $this->cli_run( $killed );
		if ( 'committed' === $seam ) {
			// Recorded as made: the next run goes on to its end, and an undo is not this test's.
			$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		} else {
			$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
			$this->assertSame( $before, $this->site() );
		}
		$this->assertSame( array(), $this->loader_in( $done ) );
	}

	/**
	 * Whether the backup holds must-use plugins.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public function backups(): array {
		return array(
			'the backup has must-use plugins' => array( true ),
			'the backup has none'             => array( false ),
		);
	}

	/**
	 * @dataProvider backups
	 */
	public function test_a_site_without_the_directory_has_it_after_the_preflight_and_the_swap_goes_as_any_other( bool $in_backup ): void {
		if ( ! $in_backup ) {
			// No file of the group in the backup: the restored directory is an empty one (the group is still the backup's).
			$this->backup_has = array_diff_key( self::backup_files(), array( 'wp-content/mu-plugins/restored-mu.php' => true ) );
		}
		$mu   = $this->dirs['mu-plugins'];
		$done = $this->swapped(
			function () use ( $mu ): void {
				Sandbox::remove( $mu );
				$this->assertDirectoryDoesNotExist( $mu, 'the control: the site has no must-use plugins directory' );
			}
		);
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertContains( 'loader_recorded', $this->seams );
		$this->assertSame( array( 'live' ), $this->where['loader_recorded'], 'the preflight made the directory and left it: the loader is put there' );
		$this->assertSame( array( 'live' ), $this->where['maintenance'] );
		$this->assertSame( array(), $this->loader_in( $done ), 'and is nowhere once the swap is made' );
		$this->assertContains( self::recorded( $done, SwapStep::FALLBACK_RECORD ), array( null, '' ) );
		$this->assertSame( array(), $this->said );
		$places = $this->places( $done );
		$this->assertSame( array( 'old' ), $this->where['carried'], 'the control: the directory the preflight made is in the plan (it was there when the plan was made), moved aside as any other' );
		$this->assertSame( array(), array_values( array_diff( (array) scandir( $places['old'] ), array( '.', '..' ) ) ), 'what went aside is the empty directory, the loader taken out of it' );
		$this->assertSame( $in_backup ? array( 'restored-mu.php' ) : array(), array_values( array_diff( (array) scandir( $mu ), array( '.', '..' ) ) ), 'the backup\'s in place' );
	}

	public function test_a_directory_gone_since_the_preflight_is_not_made_again_and_the_swap_goes_on_without_the_loader(): void {
		$this->drop_mu = true;
		$mu            = $this->dirs['mu-plugins'];
		$done          = $this->swapped();
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertContains( 'loader_recorded', $this->seams, 'the control: the swap came by' );
		$this->assertSame( array(), $this->where['loader_recorded'], 'no loader' );
		$this->assertSame( array(), $this->where['maintenance'] );
		$this->assertFileExists( $mu . '/restored-mu.php', 'the restored directory was renamed into the free place' );
		$this->assertSame( array(), $this->loader_in( $done ) );
		// Said in three places.
		$this->assertSame( Loader::MISSING, self::recorded( $done, SwapStep::FALLBACK_RECORD ) );
		$this->assertStringContainsString( 'The fallback for visitors\' requests is not available', $this->status_at['maintenance'], 'job status' );
		$this->assertStringContainsString( 'must-use plugins directory is no longer there', $this->status_at['maintenance'] );
		$this->assertCount( 1, $this->said, 'the terminal' );
		$this->assertStringContainsString( 'The fallback for visitors\' requests is not available', $this->said[0] );
		$this->assertStringContainsString( 'The fallback for visitors\' requests is not available', Plugin::instance()->job_presenter()->log_tail( $done ), 'the job\'s log' );
	}

	public function test_another_file_under_the_loaders_name_is_kept_by_the_swap_and_said(): void {
		$other = "<?php\n// a must-use plugin of the site's own under this name\n";
		$mu    = $this->dirs['mu-plugins'];
		$done  = $this->swapped(
			static function () use ( $mu, $other ): void {
				file_put_contents( Loader::path( $mu ), $other );
			}
		);
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertSame( Loader::TAKEN, self::recorded( $done, SwapStep::FALLBACK_RECORD ) );
		$this->assertStringContainsString( 'another file has the name', $this->status_at['maintenance'] );
		$this->assertCount( 1, $this->said );
		$places = $this->places( $done );
		$this->assertSame( array( 'old' ), $this->loader_in( $done ), 'it went aside with the site\'s own must-use plugins, and was not taken out' );
		$this->assertSame( $other, file_get_contents( Loader::path( $places['old'] ) ), 'as it was' );
		$this->assertStringContainsString( 'not one this plugin wrote', Plugin::instance()->job_presenter()->log_tail( $done ) );
	}

	/**
	 * The ways a loader left behind is removed outside a swap, each given the directory.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function removals(): array {
		return array(
			'the reaper'     => array( 'reap' ),
			'a deactivation' => array( 'deactivate' ),
			'an uninstall'   => array( 'uninstall' ),
		);
	}

	/**
	 * @dataProvider removals
	 */
	public function test_outside_a_swap_only_the_loaders_own_code_is_removed( string $how ): void {
		$mu     = $this->dirs['mu-plugins'];
		$remove = function () use ( $how, $mu ): void {
			if ( 'deactivate' === $how ) {
				Plugin::remove_loader( $mu );
				return;
			}
			$before = Residue::replace_maintenance_dir( $this->abspath );
			try {
				if ( 'uninstall' === $how ) {
					Uninstaller::delete_site_residue( null, $mu );
					return;
				}
				$jobs = new JobRepository( Plugin::instance()->directories() );
				$jobs->with_site_dirs(
					function (): array {
						return $this->dirs;
					}
				);
				$jobs->reap_residue();
			} finally {
				Residue::replace_maintenance_dir( $before );
			}
		};
		$old = time() - 2 * Residue::VERIFY_TTL;
		// Another file under the name, and a temporary file of a write that died: the first is kept.
		$other = "<?php\n// a must-use plugin of the site's own under this name\n";
		file_put_contents( Loader::path( $mu ), $other );
		touch( Loader::path( $mu ), $old );
		$remove();
		$this->assertSame( $other, file_get_contents( Loader::path( $mu ) ), 'someone else\'s file is kept' );
		$this->assertFileExists( $mu . '/live-mu.php' );
		// The control: the loader's own code under the name goes.
		Sandbox::remove( Loader::path( $mu ) );
		$this->assertSame( '', Loader::put( $mu ) );
		touch( Loader::path( $mu ), $old );
		$remove();
		clearstatcache();
		$this->assertFileDoesNotExist( Loader::path( $mu ), 'the loader left behind is removed' );
		$this->assertFileExists( $mu . '/live-mu.php', 'and nothing else' );
	}

	public function test_the_reaper_leaves_a_loader_that_is_new_or_that_a_restore_may_use(): void {
		$mu   = $this->dirs['mu-plugins'];
		$reap = function (): void {
			$before = Residue::replace_maintenance_dir( $this->abspath );
			try {
				$jobs = new JobRepository( Plugin::instance()->directories() );
				$jobs->with_site_dirs(
					function (): array {
						return $this->dirs;
					}
				);
				$jobs->reap_residue();
			} finally {
				Residue::replace_maintenance_dir( $before );
			}
		};
		Loader::put( $mu );
		$reap();
		$this->assertFileExists( Loader::path( $mu ), 'new: kept' );
		touch( Loader::path( $mu ), time() - 2 * Residue::VERIFY_TTL );
		// A maintenance file of a restore is up: kept, however old.
		$this->put_maintenance( \WPCheckpoint\Restore\Maintenance::new_mark(), \WPCheckpoint\Restore\Maintenance::held() );
		$reap();
		clearstatcache();
		$this->assertFileExists( Loader::path( $mu ), 'a restore\'s maintenance file is up: kept' );
		$this->remove_maintenance();
		$reap();
		clearstatcache();
		$this->assertFileDoesNotExist( Loader::path( $mu ), 'the control: old, and nothing that would use it' );
	}

	public function test_wp_cli_run_as_another_user_than_the_files_owner_is_said_without_the_users_names_but_at_the_terminal(): void {
		$this->swap_parts['users'] = static function (): array {
			return array( 1001, 33, 'deploy-user-x', 'web-user-y' );
		};
		$this->register_type();
		$done = $this->swapped();
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertTrue( self::recorded( $done, SwapStep::OWNER_RECORD ) );
		$this->assertStringContainsString( 'The user running WP-CLI is not the owner of the site\'s files', $this->status_at['maintenance'], 'job status' );
		$log = Plugin::instance()->job_presenter()->log_tail( $done );
		$this->assertStringContainsString( 'The user running WP-CLI is not the owner of the site\'s files', $log, 'the job\'s log' );
		$this->assertCount( 1, $this->said, 'the terminal' );
		$this->assertStringContainsString( 'deploy-user-x', $this->said[0], 'the terminal names both users' );
		$this->assertStringContainsString( 'web-user-y', $this->said[0] );
		// Nowhere else: the log, job status, the job's row.
		global $wpdb;
		$row = wp_json_encode( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . JobRepository::table() . ' WHERE id = %d', $done->id ), ARRAY_A ) );
		foreach ( array( 'deploy-user-x', 'web-user-y' ) as $name ) {
			$this->assertStringNotContainsString( $name, $log );
			$this->assertStringNotContainsString( $name, implode( "\n", $this->status_at ) );
			$this->assertStringNotContainsString( $name, (string) $row );
		}
		$this->assertStringContainsString( 'owner_differs', (string) $row, 'the control: the row was read, and holds the record' );
	}

	public function test_wp_cli_run_as_the_files_owner_says_nothing_of_it(): void {
		$this->swap_parts['users'] = static function (): array {
			return array( 33, 33, 'web-user-y', 'web-user-y' );
		};
		$this->register_type();
		$done = $this->swapped();
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertContains( self::recorded( $done, SwapStep::OWNER_RECORD ), array( null, false ) );
		$this->assertSame( array(), $this->said );
		$this->assertSame( array( '' ), array_values( array_unique( $this->status_at ) ) );
		$this->assertStringNotContainsString( 'not the owner', Plugin::instance()->job_presenter()->log_tail( $done ) );
		$this->assertContains( 'loader_recorded', $this->seams, 'the control: the swap came by where it would say it' );
	}
}
