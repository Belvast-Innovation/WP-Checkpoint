<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\PrefixRewriteStep;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * A backup made with another table prefix: the rows WordPress names after the prefix renamed for this
 * site's in the restored tables, a plugin's per-user settings copied, rows already under this site's
 * prefix removed, and the rest reported; the same through every interruption, and nothing by a run that
 * lost the job.
 */
final class PrefixRewriteTest extends RestoreTestCase {

	/**
	 * This site's prefix.
	 */
	private static function q(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	/**
	 * Tables of a site with prefix $p, as a backup of it would hold them; the backup's name.
	 *
	 * @param string                               $p        The backup's prefix.
	 * @param array<int, array{0: int, 1: string, 2: string}> $usermeta Rows: user, key, value.
	 * @param array<string, string>                $options  The main site's options: name => value.
	 * @param array<int, array<string, string>>    $sites    Other sites of a network: id => their options.
	 */
	private function backup_with_prefix( string $p, array $usermeta, array $options, array $sites = array() ): string {
		global $wpdb;
		$q = self::q();
		$this->create( $p . 'options', "LIKE `{$q}options`" );
		$wpdb->query( "INSERT INTO `{$p}options` SELECT * FROM `{$q}options` WHERE option_name IN ('siteurl', 'home', 'active_plugins')" );
		foreach ( $options as $name => $value ) {
			$wpdb->insert( $p . 'options', array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'on' ) );
		}
		$this->create( $p . 'usermeta', "LIKE `{$q}usermeta`" );
		foreach ( $usermeta as $row ) {
			$wpdb->insert( $p . 'usermeta', array( 'user_id' => $row[0], 'meta_key' => $row[1], 'meta_value' => $row[2] ) );
		}
		$tables = array( $p . 'options', $p . 'usermeta' );
		if ( is_multisite() ) {
			$this->create( $p . 'sitemeta', "LIKE `{$q}sitemeta`" );
			$wpdb->query( "INSERT INTO `{$p}sitemeta` SELECT * FROM `{$q}sitemeta`" );
			$this->create( $p . 'blogs', "LIKE `{$q}blogs`" );
			$wpdb->insert( $p . 'blogs', array( 'blog_id' => 1, 'site_id' => 1, 'domain' => 'example.org', 'path' => '/' ) );
			$tables[] = $p . 'sitemeta';
			$tables[] = $p . 'blogs';
			foreach ( $sites as $id => $site_options ) {
				$wpdb->insert( $p . 'blogs', array( 'blog_id' => $id, 'site_id' => 1, 'domain' => 'example.org', 'path' => '/s' . $id . '/' ) );
				$this->create( $p . $id . '_options', "LIKE `{$q}options`" );
				foreach ( $site_options as $name => $value ) {
					$wpdb->insert( $p . $id . '_options', array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'on' ) );
				}
				$tables[] = $p . $id . '_options';
			}
		}
		return $this->backup(
			$tables,
			null,
			static function ( array $site ) use ( $p ): array {
				$site['table_prefix'] = $p;
				return $site;
			}
		);
	}

	/**
	 * A restored table's rows: usermeta as sorted [user, key, value], options as name => value.
	 *
	 * @return array<int|string, mixed>
	 */
	private function restored( Job $job, string $table ): array {
		global $wpdb;
		$temp = $this->temporary_names( $job )[ $table ];
		$GLOBALS['wpdb']->query( 'COMMIT' );
		if ( 'usermeta' === substr( $table, -8 ) ) {
			$rows = (array) $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM `{$temp}` ORDER BY user_id, meta_key, meta_value", ARRAY_N );
			return array_map(
				static function ( array $row ): array {
					return array( (int) $row[0], (string) $row[1], (string) $row[2] );
				},
				$rows
			);
		}
		return array_column( (array) $wpdb->get_results( "SELECT option_name, option_value FROM `{$temp}` ORDER BY option_name", ARRAY_N ), 1, 0 );
	}

	private function report( Job $job ): array {
		return json_decode( (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::PREFIX_REPORT ) ), true );
	}

	/**
	 * The standard backup: every kind of row the rewrite treats.
	 */
	private function standard(): string {
		$p = 'wpx_';
		$q = self::q();
		return $this->backup_with_prefix(
			$p,
			array(
				array( 1, $p . 'capabilities', 'admin' ),
				array( 1, $p . 'user_level', '10' ),
				array( 1, $p . 'user-settings', 'libraryContent=browse' ),
				array( 1, $p . 'persisted_preferences', 'prefs' ),
				array( 1, $p . 'usersettings', 'old name' ),
				array( 1, 'nickname', 'admin' ),
				array( 1, $p . 'myplugin_pref', 'mine' ),
				array( 2, $p . 'other', 'from the backup' ),
				array( 2, $q . 'other', 'already there' ),
				array( 3, $q . 'capabilities', 'inert' ),
				array( 3, $p . 'capabilities', 'subscriber' ),
				array( 1, $p . '5_capabilities', 'site five' ),
				array( 1, $p . '9_capabilities', 'no such site' ),
				array( 1, 'wpxAother', 'decoy' ), // Matches "wpx_%" only where "_" is a wildcard.
			),
			array(
				$p . 'user_roles'     => 'roles of the backup',
				$q . 'user_roles'     => 'inert roles',
				$p . 'plugin_setting' => 'x',
				'blogname'            => 'Site',
			),
			array( 5 => array( $p . '5_user_roles' => 'roles of site five' ) )
		);
	}

	public function test_the_rows_named_after_the_prefix_are_renamed_copied_cleared_and_reported(): void {
		$q   = self::q();
		$job = $this->run_restore( $this->start_restore( $this->standard() ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$meta = $this->restored( $job, 'wpx_usermeta' );
		$want = array(
			array( 1, 'nickname', 'admin' ),
			array( 1, 'wpxAother', 'decoy' ),
			array( 1, $q . 'capabilities', 'admin' ),
			array( 1, $q . 'myplugin_pref', 'mine' ),
			array( 1, $q . 'persisted_preferences', 'prefs' ),
			array( 1, $q . 'user-settings', 'libraryContent=browse' ),
			array( 1, $q . 'user_level', '10' ),
			array( 1, $q . 'usersettings', 'old name' ),
			array( 1, 'wpx_myplugin_pref', 'mine' ),
			array( 2, $q . 'other', 'already there' ),
			array( 2, 'wpx_other', 'from the backup' ),
			array( 3, $q . 'capabilities', 'subscriber' ),
		);
		if ( is_multisite() ) {
			$want[] = array( 1, $q . '5_capabilities', 'site five' );
			$want[] = array( 1, $q . '9_capabilities', 'no such site' );
			$want[] = array( 1, 'wpx_9_capabilities', 'no such site' );
		} else {
			// No network: "5_" and "9_" name no site, so both are a plugin's keys to this restore: copied.
			$want[] = array( 1, $q . '5_capabilities', 'site five' );
			$want[] = array( 1, $q . '9_capabilities', 'no such site' );
			$want[] = array( 1, 'wpx_5_capabilities', 'site five' );
			$want[] = array( 1, 'wpx_9_capabilities', 'no such site' );
		}
		usort( $want, static function ( array $a, array $b ): int { return array( $a[0], $a[1], $a[2] ) <=> array( $b[0], $b[1], $b[2] ); } );
		usort( $meta, static function ( array $a, array $b ): int { return array( $a[0], $a[1], $a[2] ) <=> array( $b[0], $b[1], $b[2] ); } );
		$this->assertSame( $want, $meta );

		$options = $this->restored( $job, 'wpx_options' );
		$this->assertSame( 'roles of the backup', $options[ $q . 'user_roles' ] );
		$this->assertArrayNotHasKey( 'wpx_user_roles', $options );
		$this->assertSame( 'x', $options['wpx_plugin_setting'], 'an unknown option: reported, left' );
		$this->assertSame( 'Site', $options['blogname'] );
		if ( is_multisite() ) {
			$this->assertSame( array( $q . '5_user_roles' => 'roles of site five' ), $this->restored( $job, 'wpx_5_options' ) );
		}

		$report = $this->report( $job );
		$this->assertSame( array( 'count' => 1, 'first' => array( 'wpx_plugin_setting' ) ), $report['options'][ $q . 'options' ] );
		$this->assertSame( array( 'rows' => 1, 'copied' => 1, 'existing' => 0 ), $report['copy']['keys']['wpx_myplugin_pref'] );
		$this->assertSame( array( 'rows' => 1, 'copied' => 0, 'existing' => 1 ), $report['copy']['keys']['wpx_other'], 'a name the user already had: left alone, reported' );
		$this->assertSame( 1, $report['cleared'][ $q . 'capabilities' ] );
		$this->assertSame( 1, $report['cleared'][ $q . 'user_roles' ] );
		$log = (string) file_get_contents( $job->storage_path . '/' . $job->log_path );
		$this->assertStringContainsString( 'were removed from the restored tables', $log );
		$this->assertStringContainsString( 'were left as they are (reported, not renamed)', $log );
	}

	public function test_an_empty_backup_prefix_renames_the_exact_names_only(): void {
		$q   = self::q();
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix(
					'',
					array(
						array( 1, 'capabilities', 'admin' ),
						array( 1, 'myplugin_pref', 'mine' ),
					),
					array(
						'user_roles'     => 'roles',
						'plugin_setting' => 'x',
					)
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$meta = $this->restored( $job, 'usermeta' );
		sort( $meta );
		$this->assertSame( array( array( 1, 'myplugin_pref', 'mine' ), array( 1, $q . 'capabilities', 'admin' ) ), $meta, 'nothing copied: no prefix to tell a plugin\'s key by' );
		$options = $this->restored( $job, 'options' );
		$this->assertSame( 'roles', $options[ $q . 'user_roles' ] );
		$this->assertSame( 'x', $options['plugin_setting'] );
		$this->assertFalse( $this->report( $job )['identified'] );
		$this->assertStringContainsString( 'The backup has no table prefix', (string) file_get_contents( $job->storage_path . '/' . $job->log_path ) );
	}

	public function test_one_sites_new_name_being_another_sites_old_one_is_renamed_right(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network\'s sites only.' );
		}
		$q   = self::q();
		$p   = $q . '77_'; // The main site's "{P}capabilities" is site 77's "{Q}77_capabilities".
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix(
					$p,
					array(
						array( 1, $p . 'capabilities', 'main' ),
						array( 1, $p . '77_capabilities', 'site 77' ),
					),
					array( $p . 'user_roles' => 'main roles' ),
					array( 77 => array( $p . '77_user_roles' => 'site 77 roles' ) )
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertSame( array( array( 1, $q . '77_capabilities', 'site 77' ), array( 1, $q . 'capabilities', 'main' ) ), $this->restored( $job, $p . 'usermeta' ) );
	}

	public function test_an_interrupted_rewrite_ends_as_one_that_ran_through(): void {
		$base  = $this->standard();
		$clean = $this->run_restore( $this->start_restore( $base ) );
		$want  = array( $this->restored( $clean, 'wpx_usermeta' ), $this->restored( $clean, 'wpx_options' ), $this->report( $clean ) );
		foreach ( array( 'options_report', 'copied', 'copy', 'a', 'a_options', 'count', 'clear', 'clear_options', 'b', 'b_options' ) as $point ) {
			$hit  = 0;
			$type = 'restore_prefix_' . bin2hex( random_bytes( 3 ) );
			$this->register(
				$type,
				self::restore_steps_with(
					new PrefixRewriteStep(
						null,
						static function ( string $at ) use ( $point, &$hit ): void {
							if ( $at === $point && 1 === ++$hit ) {
								throw new \RuntimeException( 'simulated: the run is killed here' );
							}
						}
					)
				)
			);
			$job = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) ) );
			$this->assertSame( Job::FAILED, $job->status, $point );
			$this->assertSame( 1, $hit, 'the control: killed at ' . $point );
			Plugin::instance()->job_actions()->retry( $job->id );
			$job = $this->run_restore( $job );
			$this->assertSame( Job::COMPLETED, $job->status, $point . ': ' . $job->last_error );
			$this->assertSame( $want, array( $this->restored( $job, 'wpx_usermeta' ), $this->restored( $job, 'wpx_options' ), $this->report( $job ) ), $point );
		}
	}

	public function test_a_run_whose_claim_was_taken_changes_nothing_more(): void {
		$base   = $this->standard();
		$stolen = false;
		$type   = 'restore_prefix_lost';
		$work   = function ( Job $job ): string {
			return $this->work( $job );
		};
		$this->register(
			$type,
			self::restore_steps_with(
				new PrefixRewriteStep(
					null,
					static function ( string $at ) use ( &$stolen, &$job, $work ): void {
						global $wpdb;
						if ( 'a_options' === $at && ! $stolen ) {
							// Another run claims the rewrite right after the first phase's statements.
							$stolen = true;
							$ledger = TempTables::ledger( $job->storage_token, $job->id, (string) RestorePreflightStep::load_plan( $work( $job ) )['random'] );
							$wpdb->query( 'COMMIT' ); // The test's transaction began before the ledger was created.
							$taken = $wpdb->query( $wpdb->prepare( "UPDATE `{$ledger}` SET holder = 'another-run' WHERE n = %d", PrefixRewriteStep::CLAIM ) );
							$wpdb->query( 'COMMIT' ); // Seen by the import's own connection.
							if ( 1 !== (int) $taken ) {
								throw new \LogicException( 'The claim was not taken: ' . $wpdb->last_error );
							}
						}
					}
				)
			)
		);
		$job    = Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) );
		$runner = Plugin::instance()->runner();
		for ( $i = 0; $i < 500 && ! $stolen; $i++ ) {
			$runner->tick( $job->id, microtime( true ) );
			$job = Plugin::instance()->jobs()->find( $job->id );
		}
		$this->assertTrue( $stolen, 'the control: the claim was taken in the middle of the rewrite' );
		$meta = $this->restored( $job, 'wpx_usermeta' );
		$this->assertContains( array( 3, self::q() . 'capabilities', 'inert' ), $meta, 'the clearing did not run for the run whose claim was taken' );
		$this->assertNotContains( array( 1, self::q() . 'capabilities', 'admin' ), $meta, 'nor did the renaming' );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status, 'it stopped to be retried, not failed' );
	}

	public function test_a_rewrite_moves_on_in_every_tick_with_no_time_left(): void {
		$job    = $this->start_restore( $this->standard() );
		$runner = new Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), new Redactor( Redactor::installation_secrets() ), array( 'budget' => new Budget( 20, 32 * 1048576, false ) ) );
		$ticks  = 0;
		for ( $i = 0; $i < 5000; $i++ ) {
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			if ( PrefixRewriteStep::ID === $now->step ) {
				++$ticks;
			}
			$runner->tick( $job->id, microtime( true ) - 3600 );
		}
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertSame( Job::COMPLETED, $now->status, (string) $now->last_error );
		$this->assertGreaterThan( 5, $ticks, 'the control: the rewrite crossed ticks, a statement each' );
		$this->assertContains( array( 1, self::q() . 'capabilities', 'admin' ), $this->restored( $now, 'wpx_usermeta' ) );
	}
}
