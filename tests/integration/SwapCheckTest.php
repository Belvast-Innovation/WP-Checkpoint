<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\RestoreVerifyStep;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\FileStagingStep;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ChunkWalk;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\AutoUpdateHold;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Restore\PluginCopy;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * The final check before the swap: what the restore wrote is still what it
 * wrote (temporary tables, staged files, this plugin's copy), the site did
 * not move under it, and the swap's plan is complete and only one attempt's.
 */
final class SwapCheckTest extends RestoreTestCase {

	/** @var ImportSession|null */
	private $db;

	public function tear_down(): void {
		AutoUpdateHold::set_unfinished( null );
		Options::delete( AutoUpdateHold::OPTION );
		if ( null !== $this->db ) {
			$this->db->close();
		}
		parent::tear_down();
	}

	/**
	 * The backup's files: a plugin, uploads, other content with a drop-in.
	 *
	 * @return array<string, string>
	 */
	private static function files(): array {
		return array(
			'wp-content/plugins/demo/demo.php'  => "<?php\n/* Plugin Name: Demo */\n",
			'wp-content/uploads/2026/09/a.txt'  => 'aaaa',
			'wp-content/uploads/2026/09/b.txt'  => 'bbbbbbbb',
			'wp-content/languages/de_DE.mo'     => 'mo',
			'wp-content/object-cache.php'       => "<?php\n// The backup's drop-in.\n",
		);
	}

	/**
	 * A backup of the site's tables (and these) with the files.
	 *
	 * @param string[] $more More tables.
	 */
	private function base( array $more = array() ): string {
		return $this->backup( array_merge( self::site_tables(), $more ), null, null, array( 'files' => self::files() ) );
	}

	/**
	 * A restore type whose final check has these parts (on top of the test's own).
	 *
	 * @param array<string, mixed> $parts Parts.
	 */
	private function type( array $parts ): string {
		$type = 'swap_check_' . bin2hex( random_bytes( 3 ) );
		$this->register( $type, self::restore_steps_with( new SwapCheckStep( null, $this->check_parts( $parts ) ) ) );
		return $type;
	}

	/**
	 * A restore whose final check calls $tamper( $job ) once, right after it took the ledger.
	 */
	private function tampered( string $base, callable $tamper, array $parts = array() ): Job {
		$job    = null;
		$done   = false;
		$type   = $this->type(
			$parts + array(
				'at' => static function ( string $point ) use ( $tamper, &$job, &$done ): void {
					if ( 'claim' === $point && ! $done ) {
						global $wpdb;
						$done = true;
						$wpdb->query( 'COMMIT' ); // The test's transaction began before the restore's tables were made.
						$tamper( Plugin::instance()->jobs()->find( $job->id ) );
						$wpdb->query( 'COMMIT' ); // Seen by the check's own connection.
					}
				},
			)
		);
		$job = Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) );
		$ran = $this->run_restore( $job );
		$this->assertTrue( $done, 'the control: the check ran and the tampering happened' );
		return $ran;
	}

	private function plan(): SwapPlan {
		global $wpdb;
		if ( null === $this->db ) {
			$this->db = ImportSession::open( Credentials::from_wordpress() );
		}
		return new SwapPlan( $this->db, $wpdb->base_prefix . SwapPlan::TABLE );
	}

	/**
	 * Every entry of the plan the check wrote: [kind, live, stage, old, had_live].
	 *
	 * @return array<int, array<int, mixed>>
	 */
	private function entries( Job $job ): array {
		$file = json_decode( (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::SWAP_PLAN ) ), true );
		$this->assertSame( $file['entries'], $this->plan()->complete_count( $job->id, $file['attempt'] ), 'the plan is complete' );
		$out = array();
		foreach ( $this->plan()->read( $job->id, $file['attempt'], -1, 100000 ) as $row ) {
			$out[] = array( $row['kind'], $row['live'], $row['stage'], $row['old'], $row['had_live'] );
		}
		return $out;
	}

	/**
	 * The path of a staged file.
	 */
	private function staged( Job $job, string $group, string $relative ): string {
		$staging = RestoreFilesPreflightStep::staging( $this->work( $job ) );
		return RestoreFilesPreflightStep::layout_of( $staging, $job )->stage_dir( $group ) . '/' . $relative;
	}

	private function assertFinal( Job $job, string $message ): void {
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind, (string) $job->last_error );
		$this->assertStringContainsString( $message, (string) $job->last_error );
	}

	public function test_a_restore_left_as_it_was_written_passes_and_its_plan_is_complete(): void {
		global $wpdb;
		$q = $wpdb->base_prefix;
		$this->create( $q . 'wpc_extra', '(id int NOT NULL PRIMARY KEY) ENGINE=InnoDB' ); // Not in the backup: moved away.
		$job = $this->run_restore( $this->start_restore( $this->base() ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$entries = $this->entries( $job );
		$kinds   = array_count_values( array_column( $entries, 0 ) );
		$dirs    = array_map(
			'basename',
			array_column(
				array_filter(
					$entries,
					static function ( array $e ): bool {
						return SwapPlan::DIR === $e[0];
					}
				),
				1
			)
		);
		$this->assertSame( array( 'plugins', 'themes', 'uploads', 'mu-plugins', 'languages' ), $dirs, 'every staged group, then each top-level entry of other content but the drop-in' );
		$this->assertSame( 5, $kinds[ SwapPlan::DIR ] ?? 0 );
		$this->assertNotContains( 'object-cache.php', array_map( 'basename', array_column( $entries, 1 ) ), 'the live drop-in stays' );
		$tables = array_values( array_filter( $entries, static function ( array $e ): bool {
			return SwapPlan::TABLE_OF === $e[0];
		} ) );
		$this->assertSame( array_column( $this->temporary_names_list( $job ), 'final' ), array_column( $tables, 1 ) );
		$this->assertContains( array( SwapPlan::MOVE, $q . 'wpc_extra', '', TempTables::old( $job->storage_token, $job->id, RestorePreflightStep::load_plan( $this->work( $job ) )['random'], 'wpc_extra' ), true ), $entries );
		foreach ( $entries as $entry ) {
			$this->assertNotSame( $q . 'wpcheckpoint_jobs', $entry[1], 'the jobs table is never in the plan' );
			$this->assertNotSame( $q . SwapPlan::TABLE, $entry[1] );
			if ( SwapPlan::DIR !== $entry[0] ) {
				$this->assertLessThanOrEqual( 60, strlen( $entry[3] ) );
				$this->assertStringStartsWith( TempTables::OLD_PREFIX, $entry[3] );
			}
		}
	}

	/**
	 * The plan of a restore: [table, temporary, final] per table.
	 *
	 * @return array<int, array<string, string>>
	 */
	private function temporary_names_list( Job $job ): array {
		return RestorePreflightStep::load_plan( $this->work( $job ) )['plan']->tables();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function engines(): array {
		return array(
			'InnoDB (counted by key ranges)' => array( 'InnoDB' ),
			'MyISAM (counted last)'          => array( 'MyISAM' ),
		);
	}

	/**
	 * @dataProvider engines
	 */
	public function test_a_row_removed_from_a_temporary_table_ends_the_restore( string $engine ): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'wpc_counted';
		$this->create( $table, '(id int NOT NULL AUTO_INCREMENT PRIMARY KEY, v text) ENGINE=' . $engine );
		for ( $i = 0; $i < 5; $i++ ) {
			$wpdb->insert( $table, array( 'v' => 'row ' . $i ) );
		}
		$job = $this->tampered(
			$this->base( array( $table ) ),
			function ( Job $job ) use ( $table ): void {
				global $wpdb;
				$temp = $this->temporary_names( $job )[ $table ];
				$this->assertSame( 1, (int) $wpdb->query( "DELETE FROM `{$temp}` ORDER BY id LIMIT 1" ), 'the control: a row was removed' );
			},
			array( 'sizes' => array( 'rows' => 2 ) )
		);
		$this->assertFinal( $job, 'holds 4 rows where the restore left 5' );
	}

	public function test_a_ledger_record_not_at_its_last_chunk_ends_the_restore(): void {
		$job = $this->tampered(
			$this->base(),
			function ( Job $job ): void {
				global $wpdb;
				$ledger = TempTables::ledger( $job->storage_token, $job->id, RestorePreflightStep::load_plan( $this->work( $job ) )['random'] );
				$this->assertSame( 1, (int) $wpdb->query( "UPDATE `{$ledger}` SET chunk = chunk + 1 WHERE n = 0" ), 'the control: the record was changed' );
			}
		);
		$this->assertFinal( $job, 'is not complete as recorded' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function changed_files(): array {
		return array(
			'content and size' => array( 'size', 'its size or modification time differs' ),
			'modified later'   => array( 'mtime', 'its size or modification time differs' ),
			'a file too many'  => array( 'extra', 'hold 3 entries where the restore staged 2' ),
			'a file missing'   => array( 'missing', 'it is missing' ),
			'a link now'       => array( 'link', 'it is not a regular file' ),
			'a link too many'  => array( 'extra_link', 'which the restore did not write (a link or a special file)' ),
		);
	}

	/**
	 * @dataProvider changed_files
	 */
	public function test_a_staged_file_that_changed_ends_the_restore( string $how, string $message ): void {
		$job = $this->tampered(
			$this->base(),
			function ( Job $job ) use ( $how ): void {
				$path = $this->staged( $job, 'uploads', '2026/09/a.txt' );
				switch ( $how ) {
					case 'size':
						file_put_contents( $path, 'aaaaa' );
						break;
					case 'mtime':
						touch( $path, filemtime( $path ) + 60 );
						break;
					case 'extra':
						file_put_contents( dirname( $path ) . '/c.txt', 'c' );
						break;
					case 'extra_link':
						symlink( $path, dirname( $path ) . '/c.txt' );
						break;
					case 'missing':
						unlink( $path );
						break;
					case 'link':
						unlink( $path );
						symlink( dirname( $path ) . '/b.txt', $path );
						break;
				}
			}
		);
		$this->assertFinal( $job, $message );
	}

	public function test_same_size_bytes_with_the_time_put_back_pass_the_stat_check_without_a_takeover(): void {
		// The one change stat cannot see: this is what the check hashes again where staging was taken over.
		$job = $this->tampered(
			$this->base(),
			function ( Job $job ): void {
				$path = $this->staged( $job, 'uploads', '2026/09/a.txt' );
				$time = filemtime( $path );
				file_put_contents( $path, 'zzzz' );
				touch( $path, $time );
				clearstatcache( true, $path );
				$this->assertSame( array( 'zzzz', $time ), array( file_get_contents( $path ), filemtime( $path ) ), 'the control: other bytes, the same size and time' );
			}
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
	}

	/**
	 * A restore whose staging is taken over once (killed after writing a piece, its lease run out): the suspect
	 * position is recorded. Then $tamper( $job, $path_at_the_position ) and the final check with $parts.
	 */
	private function taken_over( callable $tamper, array $parts = array(), array $files = array() ): Job {
		global $wpdb;
		$killed = false;
		$type   = 'swap_check_takeover_' . bin2hex( random_bytes( 3 ) );
		$job    = null;
		$steps  = self::restore_steps_with(
			new FileStagingStep(
				$this->staging_parts(
					array(
						'at' => static function ( string $point ) use ( &$killed ): void {
							if ( 'piece' === $point && ! $killed ) {
								$killed = true;
								throw new \WPCheckpoint\Jobs\LockLost( 'killed by the server (simulated)' );
							}
						},
					)
				)
			)
		);
		$done = false;
		foreach ( $steps as $i => $step ) {
			if ( SwapCheckStep::ID === $step->id() ) {
				$steps[ $i ] = new SwapCheckStep(
					null,
					$this->check_parts(
						$parts + array(
							'at' => function ( string $point ) use ( $tamper, &$job, &$done ): void {
								if ( 'claim' === $point && ! $done ) {
									$done = true;
									$job  = Plugin::instance()->jobs()->find( $job->id );
									$tamper( $job, $this->suspect_path( $job ) );
								}
							},
						)
					)
				);
			}
		}
		$this->register( $type, $steps );
		$base   = array() === $files ? $this->base() : $this->backup( self::site_tables(), null, null, array( 'files' => $files ) );
		$job    = Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) );
		$runner = Plugin::instance()->runner();
		for ( $i = 0; $i < 200 && ! $killed; $i++ ) {
			$runner->tick( $job->id, microtime( true ) );
		}
		$this->assertTrue( $killed, 'the control: the staging run was killed' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . JobRepository::table() . ' SET locked_until = 1 WHERE id = %d', $job->id ) ); // Its lease ran out.
		$ran = $this->run_restore( $job );
		$this->assertCount( 1, FileStagingStep::suspects( $this->work( $job ) ), 'the control: the takeover was recorded' );
		$this->assertTrue( $done, 'the control: the check ran' );
		return $ran;
	}

	/**
	 * The staged path of the file at the (first) recorded takeover position.
	 */
	private function suspect_path( Job $job ): string {
		$work   = $this->work( $job );
		$loaded = RestorePreflightStep::load_plan( $work );
		$walk   = new ChunkWalk( RestoreVerifyStep::index_path( $work, RestorePreflightStep::manifest( $work )->files_index() ), $loaded['volumes'], $loaded['chunk_bytes'], ChunkWalk::FILES );
		$chunk  = $walk->at( FileStagingStep::suspects( $work )[0]['at'] );
		$map    = RestoreFilesPreflightStep::layout_of( RestoreFilesPreflightStep::staging( $work ), $job )->map( (string) $chunk['line']['p'] );
		return $this->staged( $job, $map['group'], $map['relative'] );
	}

	/**
	 * Replace a file's bytes with others of the same length and put its modification time back.
	 */
	private static function same_size( string $path ): void {
		$time  = filemtime( $path );
		$bytes = (string) file_get_contents( $path );
		file_put_contents( $path, str_repeat( 'z', strlen( $bytes ) ) === $bytes ? str_repeat( 'y', strlen( $bytes ) ) : str_repeat( 'z', strlen( $bytes ) ) );
		touch( $path, $time );
	}

	public function test_after_a_takeover_the_files_from_its_position_are_hashed_again(): void {
		$job = $this->taken_over(
			static function ( Job $job, string $path ): void {
				self::same_size( $path );
			}
		);
		$this->assertFinal( $job, 'although its size and modification time are' );
	}

	public function test_the_files_past_the_window_after_a_takeover_are_not_hashed_again(): void {
		$other = null;
		$job   = $this->taken_over(
			function ( Job $job, string $path ) use ( &$other ): void {
				// A file of the backup other than the one at the position, and after it in the walk: uploads/…/b.txt
				// or a.txt, whichever the position is not.
				$other = $this->staged( $job, 'uploads', 'b.txt' === basename( $path ) ? '2026/09/a.txt' : '2026/09/b.txt' );
				self::same_size( $other );
			},
			array( 'window' => 1 )
		);
		$this->assertNotNull( $other );
		$this->assertSame( Job::COMPLETED, $job->status, 'a one-byte window hashes the file at the position only: ' . $job->last_error );

		$inside = $this->taken_over(
			static function ( Job $job, string $path ): void {
				self::same_size( $path );
			},
			array( 'window' => 1 )
		);
		$this->assertFinal( $inside, 'although its size and modification time are' );
	}

	public function test_the_window_is_what_a_run_can_write_between_checkpoints(): void {
		$this->assertSame( \WPCheckpoint\Jobs\JobContext::CHECKPOINT_BYTES + \WPCheckpoint\Archive\Limits::CONTENT_CHUNK_BYTES, SwapCheckStep::SUSPECT_WINDOW_BYTES );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function plugin_changes(): array {
		return array(
			'another version running'  => array( 'version', 'WP Checkpoint was updated while the restore ran (9.9.9-test was staged, 1.0.0-other is running)' ),
			'a running file changed'   => array( 'running', 'WP Checkpoint was updated while the restore ran (its file' ),
			'a file added to it'       => array( 'added', 'its files are not the ones staged' ),
			'the staged copy changed'  => array( 'staged', 'The staged copy of WP Checkpoint was changed' ),
		);
	}

	/**
	 * @dataProvider plugin_changes
	 */
	public function test_this_plugin_updated_since_it_was_staged_ends_the_restore( string $how, string $message ): void {
		$job = $this->tampered(
			$this->base(),
			function ( Job $job ) use ( $how ): void {
				if ( 'running' === $how ) {
					file_put_contents( $this->plugin_copy . '/readme.txt', "=== WP Checkpoint (a newer stand-in) ===\n" );
				} elseif ( 'added' === $how ) {
					file_put_contents( $this->plugin_copy . '/src/New.php', "<?php\n" );
				} elseif ( 'staged' === $how ) {
					$path = $this->staged( $job, 'plugins', 'wp-checkpoint/readme.txt' );
					$time = filemtime( $path );
					file_put_contents( $path, str_repeat( 'x', filesize( $path ) ) );
					touch( $path, $time );
				}
			},
			'version' === $how ? array( 'version' => '1.0.0-other' ) : array()
		);
		$this->assertFinal( $job, $message );
	}

	public function test_automatic_updates_of_this_plugin_are_held_while_a_restore_is_unfinished_or_cannot_be_ruled_out(): void {
		$ours  = (object) array( 'plugin' => plugin_basename( WPCHECKPOINT_FILE ) );
		$other = (object) array( 'plugin' => 'other/other.php' );
		AutoUpdateHold::set_unfinished(
			static function (): bool {
				return false;
			}
		);
		$this->assertTrue( AutoUpdateHold::filter( true, $ours ), 'the control: no restore, no hold' );
		$this->assertNull( AutoUpdateHold::state() );
		AutoUpdateHold::set_unfinished(
			static function (): bool {
				return true;
			}
		);
		$this->assertFalse( AutoUpdateHold::filter( true, $ours ) );
		$this->assertTrue( AutoUpdateHold::filter( true, $other ), 'another plugin is not held' );
		$this->assertSame( 'restore', AutoUpdateHold::state() );
		AutoUpdateHold::set_unfinished(
			static function () {
				return null;
			}
		);
		$this->assertFalse( AutoUpdateHold::filter( true, $ours ), 'no answer is no evidence that nothing is in progress' );
		$this->assertSame( 'unchecked', AutoUpdateHold::state() );
		$log = Plugin::instance()->directories()->logs() . '/storage.log';
		$this->assertStringContainsString( 'The automatic update of WP Checkpoint was held: the job table could not be read', (string) file_get_contents( $log ) );
		$notices = ( new \WPCheckpoint\Admin\Notices( Plugin::instance()->directories() ) )->notices();
		$this->assertArrayHasKey( 'auto_update_unchecked', $notices );
		$this->assertTrue( $notices['auto_update_unchecked']['dismissible'] );
		AutoUpdateHold::set_unfinished(
			static function (): bool {
				return false;
			}
		);
		$this->assertTrue( AutoUpdateHold::filter( true, $ours ) );
		$this->assertNull( AutoUpdateHold::state(), 'a check that can read the table clears it' );
		$this->assertArrayNotHasKey( 'auto_update_unchecked', ( new \WPCheckpoint\Admin\Notices( Plugin::instance()->directories() ) )->notices() );
		$this->assertTrue( has_filter( 'auto_update_plugin', array( AutoUpdateHold::class, 'filter' ) ) !== false, 'the filter is registered' );
	}

	public function test_a_table_left_in_place_that_references_a_replaced_one_refuses_the_swap(): void {
		global $wpdb;
		$q   = $wpdb->base_prefix;
		$job = $this->tampered(
			$this->base(),
			function () use ( $q ): void {
				$this->create( 'wpc_fk_child', "(id int NOT NULL PRIMARY KEY, option_id bigint(20) unsigned, CONSTRAINT wpc_fk_out FOREIGN KEY (option_id) REFERENCES `{$q}options` (option_id)) ENGINE=InnoDB" );
				$this->create( $q . 'wpc_fk_moved', "(id int NOT NULL PRIMARY KEY, option_id bigint(20) unsigned, CONSTRAINT wpc_fk_in FOREIGN KEY (option_id) REFERENCES `{$q}options` (option_id)) ENGINE=InnoDB" );
			}
		);
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertNotSame( Job::FAILURE_FINAL, $job->failure_kind, 'remove the reference and retry' );
		$this->assertStringContainsString( 'wpc_fk_out (wpc_fk_child → ' . $q . 'options)', (string) $job->last_error );
		$this->assertStringNotContainsString( 'wpc_fk_in', (string) $job->last_error, 'a table the swap moves away too is no problem' );
	}

	public function test_a_site_directory_that_moved_since_staging_refuses_the_swap(): void {
		$job = $this->tampered(
			$this->base(),
			static function (): void {},
			array(
				'site_dirs' => static function (): array {
					return array_merge( \WPCheckpoint\Files\ScanRoots::site_directories(), array( 'uploads' => sys_get_temp_dir() . '/wpc-elsewhere' ) );
				},
			)
		);
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertNotSame( Job::FAILURE_FINAL, $job->failure_kind );
		$this->assertStringContainsString( 'The uploads directory of the site moved since the restore staged its files', (string) $job->last_error );
	}

	/**
	 * @return array<string, array{0: bool, 1: string, 2: bool}>
	 */
	public function slow_counts(): array {
		return array(
			'a table without a key, by the web'  => array( false, 'counted_whole', false ),
			'a table without a key, by WP-CLI'   => array( true, 'counted_whole', true ),
			'a range of a keyed table, by WP-CLI' => array( true, 'counted', false ),
		);
	}

	/**
	 * @dataProvider slow_counts
	 */
	public function test_a_count_slower_than_the_budget_passes_only_where_nothing_ends_it( bool $cli, string $slow, bool $passes ): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'wpc_nokey';
		$this->create( $table, '(id int NOT NULL, v text) ENGINE=InnoDB' );
		$wpdb->insert(
			$table,
			array(
				'id' => 1,
				'v'  => 'x',
			)
		);
		$now    = microtime( true );
		$once   = false;
		$type   = $this->type(
			array(
				'at' => static function ( string $point ) use ( $slow, &$now, &$once ): void {
					if ( $slow === $point && ! $once ) {
						$once = true;
						$now += 60; // This unit took a minute.
					}
				},
			)
		);
		$runner = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array(
				'clock'  => static function () use ( &$now ): float {
					return $now;
				},
				'budget' => new Budget( 20, 32 * 1048576, false ),
				'cli'    => $cli,
			)
		);
		$job = Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $this->base( array( $table ) ) ) );
		for ( $i = 0; $i < 500; $i++ ) {
			$job = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			$runner->tick( $job->id, $now );
		}
		$wpdb->query( 'COMMIT' );
		$this->assertTrue( $once, 'the control: the slow unit ran' );
		if ( $passes ) {
			$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
			return;
		}
		$this->assertSame( Job::FAILED, $job->status );
		if ( 'counted_whole' === $slow ) {
			$this->assertStringContainsString( 'Run the restore with WP-CLI, where no such limit applies: wp wpcheckpoint job run ' . $job->id, (string) $job->last_error );
		} else {
			$this->assertStringContainsString( 'one unit of work took 60 seconds', (string) $job->last_error );
		}
	}

	public function test_the_plan_is_one_attempts_and_a_leftover_of_another_is_never_read(): void {
		$base = $this->base();
		$job  = $this->start_restore( $base );
		$old  = array(
			array(
				'kind' => SwapPlan::MOVE,
				'live' => 'left_over_table',
				'old'  => 'wcpold_left_over',
			),
		);
		$this->plan()->write( $job->id, 1, 0, $old );
		$this->plan()->complete( $job->id, 1, 1 );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$file = json_decode( (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::SWAP_PLAN ) ), true );
		$this->assertSame( 2, $file['attempt'], 'the attempt after the one there was' );
		$this->assertNull( $this->plan()->complete_count( $job->id, 1 ), 'the earlier attempt\'s rows are gone' );
		$count = count( $this->entries( $job ) );

		// A leftover written after the plan (a stale run): the plan as the swap reads it is still only this one.
		$this->plan()->write( $job->id, 1, 0, $old );
		$this->plan()->complete( $job->id, 1, 1 );
		$this->assertCount( $count, $this->entries( $job ) );
		$this->assertNotContains( 'left_over_table', array_column( $this->entries( $job ), 1 ) );
	}

	/**
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function plan_interruptions(): array {
		return array(
			'clearing other attempts' => array( 'plan_old', 1 ),
			'after the first batch'   => array( 'plan_batch', 1 ),
			'after the third batch'   => array( 'plan_batch', 3 ),
			'after the last batch'    => array( 'plan_batch', 0 ),
			'after the COMPLETE row'  => array( 'plan_complete', 1 ),
		);
	}

	/**
	 * @dataProvider plan_interruptions
	 */
	public function test_writing_the_plan_interrupted_ends_in_one_complete_plan_without_a_row_twice( string $point, int $pass ): void {
		global $wpdb;
		$base    = $this->base();
		$passes  = 0;
		$counter = $this->type(
			array(
				'sizes' => array( 'batch' => 2 ),
				'at'    => static function ( string $at ) use ( $point, &$passes ): void {
					if ( $at === $point ) {
						++$passes;
					}
				},
			)
		);
		$clean   = $this->run_restore( Plugin::instance()->jobs()->create( $counter, self::$admin_id, array(), array( 'base' => $base ) ) );
		$this->assertSame( Job::COMPLETED, $clean->status, (string) $clean->last_error );
		$want = 0 === $pass ? $passes : $pass;
		$this->assertGreaterThanOrEqual( $want, $passes, 'the control: the point is passed' );
		$hit  = 0;
		$type = $this->type(
			array(
				'sizes' => array( 'batch' => 2 ),
				'at'    => static function ( string $at ) use ( $point, $want, &$hit ): void {
					if ( $at === $point && $want === ++$hit ) {
						throw new \RuntimeException( 'simulated: the run is killed here' );
					}
				},
			)
		);
		$job  = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) ) );
		$this->assertSame( Job::FAILED, $job->status, $point );
		Plugin::instance()->job_actions()->retry( $job->id );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$attempts = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT attempt FROM ' . $wpdb->base_prefix . SwapPlan::TABLE . ' WHERE job_id = %d', $job->id ) );
		$this->assertSame( array( '1' ), $attempts, 'one attempt, fixed when the check began' );
		$entries = $this->entries( $job );
		$this->assertCount( count( $this->entries( $clean ) ), $entries, 'as many entries as an uninterrupted check wrote' );
		$this->assertSame( count( $entries ), (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . SwapPlan::TABLE . " WHERE job_id = %d AND kind <> 'complete'", $job->id ) ), 'no row twice' );
	}

	public function test_a_large_plan_is_written_in_batches_bounded_by_bytes(): void {
		global $wpdb;
		$q      = $wpdb->base_prefix;
		$tables = array();
		for ( $i = 0; $i < 300; $i++ ) {
			$name = sprintf( '%swpc_many_%03d_%s', $q, $i, str_repeat( 'x', 30 ) );
			$wpdb->query( "CREATE TABLE `{$name}` (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB" );
			$this->created[] = $name;
			$tables[]        = $name;
		}
		$batches = 0;
		$type    = $this->type(
			array(
				'sizes' => array( 'batch_bytes' => 2048 ),
				'at'    => static function ( string $at ) use ( &$batches ): void {
					if ( 'plan_batch' === $at ) {
						++$batches;
					}
				},
			)
		);
		$job     = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $this->base() ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$moved = array_column(
			array_filter(
				$this->entries( $job ),
				static function ( array $e ): bool {
					return SwapPlan::MOVE === $e[0];
				}
			),
			1
		);
		foreach ( $tables as $table ) {
			$this->assertContains( $table, $moved );
		}
		$this->assertGreaterThan( 10, $batches, 'written in many statements, each of a few kilobytes' );
	}

	public function test_a_plan_table_without_a_column_this_code_needs_stops_the_restore_with_the_reason(): void {
		global $wpdb;
		$plan = $wpdb->base_prefix . SwapPlan::TABLE;
		$wpdb->query( "ALTER TABLE `{$plan}` DROP COLUMN had_live" );
		try {
			$job = $this->run_restore( $this->start_restore( $this->base() ) );
		} finally {
			$wpdb->query( "ALTER TABLE `{$plan}` ADD COLUMN had_live TINYINT NOT NULL DEFAULT 0" );
		}
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'The table of the swap plan lacks columns this version of WP Checkpoint needs (had_live)', (string) $job->last_error );
	}

	public function test_the_plan_table_comes_with_the_schema_and_goes_with_the_plugin(): void {
		global $wpdb;
		$plan = $wpdb->base_prefix . SwapPlan::TABLE;
		$this->assertSame( $plan, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $plan ) ), 'created by the migration' );
		$this->assertSame( 8, \WPCheckpoint\Support\Schema::CURRENT );
		$this->assertStringContainsString( 'CREATE TABLE IF NOT EXISTS', SwapPlan::create_sql( $plan ) );
		$this->assertSame( PluginCopy::VERSION, $this->check_parts()['version'] );
	}

	public function test_a_jobs_plan_goes_with_its_work(): void {
		global $wpdb;
		$job = $this->run_restore( $this->start_restore( $this->base() ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$count = static function () use ( $wpdb, $job ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->base_prefix . SwapPlan::TABLE . ' WHERE job_id = %d', $job->id ) );
		};
		$this->assertGreaterThan( 0, $count(), 'the control: the check wrote a plan' );
		$this->assertTrue( Plugin::instance()->jobs()->reclaim_work( $job ) );
		$this->assertSame( 0, $count() );
	}

	public function test_a_table_left_out_of_the_restore_is_never_in_the_plan(): void {
		global $wpdb;
		$kept = $wpdb->base_prefix . 'wpc_kept';
		$this->create( $kept, '(id int NOT NULL PRIMARY KEY) ENGINE=InnoDB' );
		$job = $this->run_restore( $this->start_restore( $this->base( array( $kept ) ), array( 'exclude_tables' => array( $kept ) ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$lives = array_column( $this->entries( $job ), 1 );
		$this->assertContains( $wpdb->base_prefix . 'options', $lives, 'the control: the restored tables are in it' );
		$this->assertNotContains( $kept, $lives, 'neither replaced nor moved away: the live one stays' );
	}

	public function test_the_check_takes_the_ledger_from_any_run_of_the_import_still_holding_it(): void {
		global $wpdb;
		$stale = null;
		$steps = self::restore_steps_with( new SwapCheckStep( null, $this->check_parts() ) );
		$check = array_search( SwapCheckStep::ID, array_map( static function ( $step ) {
			return $step->id();
		}, $steps ), true );
		// Right before the check: every record held by an import run that outlived its lease.
		array_splice(
			$steps,
			(int) $check,
			0,
			array(
				new \WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep(
					'stale_holder',
					function ( \WPCheckpoint\Jobs\JobContext $ctx ) use ( &$stale ): \WPCheckpoint\Jobs\StepResult {
						global $wpdb;
						$job    = $ctx->job();
						$ledger = TempTables::ledger( $job->storage_token, $job->id, RestorePreflightStep::load_plan( $ctx->work_path() )['random'] );
						$wpdb->query( 'COMMIT' );
						$stale = (int) $wpdb->query( "UPDATE `{$ledger}` SET holder = 'a-stale-run'" );
						$wpdb->query( 'COMMIT' );
						// The control: the stale run can still move its records now (and back).
						$old   = new \WPCheckpoint\Restore\Ledger( $this->session(), $ledger, 'a-stale-run' );
						$state = $old->get( 0 );
						$old->advance( 0, $state['chunk'], $state['pos'], $state['chunk'], $state['pos'] + 1, 0 );
						$old->advance( 0, $state['chunk'], $state['pos'] + 1, $state['chunk'], $state['pos'], 0 );
						return \WPCheckpoint\Jobs\StepResult::done( 'held by a stale run' );
					}
				),
			)
		);
		$type = 'swap_check_stale_' . bin2hex( random_bytes( 3 ) );
		$this->register( $type, $steps );
		$job = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $this->base() ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertGreaterThan( 0, $stale, 'the control: records were held by the stale run' );
		$ledger = TempTables::ledger( $job->storage_token, $job->id, RestorePreflightStep::load_plan( $this->work( $job ) )['random'] );
		$this->assertSame( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ledger}` WHERE holder = 'a-stale-run'" ), 'the check holds every record' );
		$old   = new \WPCheckpoint\Restore\Ledger( $this->session(), $ledger, 'a-stale-run' );
		$state = $old->get( 0 );
		try {
			$old->advance( 0, $state['chunk'], $state['pos'], $state['chunk'], $state['pos'] + 1, 1 );
			$this->fail( 'the stale run recorded rows after the check took the ledger' );
		} catch ( \WPCheckpoint\Restore\ClaimLost $e ) {
			$this->assertStringContainsString( 'this one stops', $e->getMessage() );
		}
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, string>}>
	 */
	public function odd_keys(): array {
		return array(
			'bytes that are not UTF-8'      => array( 'varbinary(4)', array( "\x10\x00", "\xE9\x01", "\xE9\x02", "\xFF\x01", "\xFF\xFE", "\x7F" ) ),
			'characters outside the BMP'    => array( 'varchar(8) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin', array( 'a', "\u{1F600}", "\u{1F601}", 'b', 'é', 'z' ) ),
			'latin1, case-insensitive'      => array( 'varchar(8) CHARACTER SET latin1 COLLATE latin1_swedish_ci', array( 'A', "b\xE9", "C\xFF", 'd ', 'e', "\xE0z" ) ),
			'utf8mb3, case-insensitive'     => array( 'varchar(8) CHARACTER SET utf8 COLLATE utf8_general_ci', array( 'A', 'bé', 'Ç', 'd ', 'e', 'ñz' ) ),
		);
	}

	/**
	 * @dataProvider odd_keys
	 *
	 * @param string[] $keys Keys.
	 */
	public function test_a_table_keyed_by_bytes_or_characters_the_cursor_cannot_hold_is_counted_across_ticks( string $type, array $keys ): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'wpc_oddkey';
		$this->create( $table, "(k {$type} NOT NULL PRIMARY KEY, v int) ENGINE=InnoDB" );
		foreach ( $keys as $i => $key ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (k, v) VALUES (UNHEX(%s), %d)", bin2hex( $key ), $i ) );
		}
		$this->assertSame( count( $keys ), (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), 'the control: every key is there' );
		$type_id = $this->type( array( 'sizes' => array( 'rows' => 2 ) ) );
		$job     = $this->run_restore( Plugin::instance()->jobs()->create( $type_id, self::$admin_id, array(), array( 'base' => $this->base( array( $table ) ) ) ), true, 3000 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
	}

	public function test_a_table_keyed_by_times_or_by_two_columns_is_counted_across_ticks(): void {
		global $wpdb;
		$table = $wpdb->base_prefix . 'wpc_oddkey2';
		$this->create( $table, '(t datetime(6) NOT NULL, n int NOT NULL, s varchar(8) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL, PRIMARY KEY (t, n, s)) ENGINE=InnoDB' );
		foreach ( array( '2026-01-01 00:00:00.000001', '2026-01-01 00:00:00.000002', '2026-10-25 02:30:00.5', '2026-10-25 02:30:00.5', '0000-00-00 00:00:00' ) as $i => $time ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO `{$table}` (t, n, s) VALUES (%s, %d, %s)", $time, $i % 2, 0 === $i % 2 ? 'a' : 'Á' ) );
		}
		$this->assertSame( 5, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), 'the control: every row is there' );
		$type = $this->type( array( 'sizes' => array( 'rows' => 2 ) ) );
		$job  = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $this->base( array( $table ) ) ) ), true, 3000 );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
	}

	public function test_the_window_after_a_takeover_counts_only_what_staging_wrote(): void {
		// After the position: this plugin's copy in the backup (not staged, large), then a staged file changed with
		// its time put back. A window just long enough for the staged files up to it must reach it.
		$window = PHP_INT_MAX;
		// In path order and in this order alike: a staged file, this plugin's copy, then the staged files after it.
		$files  = array(
			'wp-content/plugins/demo/demo.php'                   => "<?php\n/* Plugin Name: Demo */\n",
			'wp-content/plugins/wp-checkpoint/wp-checkpoint.php' => (string) file_get_contents( $this->plugin_copy . '/wp-checkpoint.php' ),
			'wp-content/plugins/wp-checkpoint/zz-large.bin'      => str_repeat( 'L', 50000 ),
			'wp-content/uploads/2026/09/a.txt'                   => 'aaaa',
			'wp-content/uploads/2026/09/b.txt'                   => 'bbbbbbbb',
		);
		$parts  = array(
			'window' => static function () use ( &$window ): int {
				return $window;
			},
		);
		$target = null;
		$job    = $this->taken_over(
			function ( Job $job, string $path ) use ( &$window, &$target ): void {
				$work   = $this->work( $job );
				$loaded = RestorePreflightStep::load_plan( $work );
				$layout = RestoreFilesPreflightStep::layout_of( RestoreFilesPreflightStep::staging( $work ), $job );
				$plan   = json_decode( (string) file_get_contents( RestoreFiles::path( $work, RestoreFiles::STAGE_PLAN ) ), true );
				$walk   = new ChunkWalk( RestoreVerifyStep::index_path( $work, RestorePreflightStep::manifest( $work )->files_index() ), $loaded['volumes'], $loaded['chunk_bytes'], ChunkWalk::FILES );
				$at     = FileStagingStep::suspects( $work )[0]['at'];
				$bytes  = 0;
				$seen   = false;
				for ( $chunk = $walk->at( $at ); null !== $chunk; $chunk = $walk->at( $chunk['next'] ) ) {
					$hit = FileStagingStep::target( (string) $chunk['line']['p'], $chunk['entry'], $layout, (array) RestoreFilesPreflightStep::staging( $work )['staged'], array_keys( (array) $plan['self'] ), FileStagingStep::fold( (string) $plan['running'] ) );
					if ( null === $hit ) {
						$seen = $seen || false !== strpos( (string) $chunk['line']['p'], 'zz-large.bin' );
						continue;
					}
					$bytes += (int) $chunk['line']['b'];
					if ( $seen && 0 < (int) $chunk['line']['b'] ) {
						$target = $layout->root( $hit['group'] ) . '/' . $hit['relative'];
						break;
					}
				}
				$this->assertNotNull( $target, 'the control: a staged file follows the large one the staging skipped' );
				$window = $bytes;
				$this->assertLessThan( 50000, $window, 'the control: the window is shorter than the large file the staging skipped' );
				self::same_size( $target );
			},
			$parts,
			$files
		);
		$this->assertFinal( $job, 'although its size and modification time are' );
	}

	public function test_a_retry_after_a_refused_swap_checks_everything_again(): void {
		global $wpdb;
		$q      = $wpdb->base_prefix;
		$claims = 0;
		$type   = $this->type(
			array(
				'at' => function ( string $point ) use ( &$claims, $q ): void {
					if ( 'claim' === $point && 1 === ++$claims ) {
						global $wpdb;
						$wpdb->query( 'COMMIT' );
						$this->create( 'wpc_fk_child', "(id int NOT NULL PRIMARY KEY, option_id bigint(20) unsigned, CONSTRAINT wpc_fk_out FOREIGN KEY (option_id) REFERENCES `{$q}options` (option_id)) ENGINE=InnoDB" );
					}
				},
			)
		);
		$job = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $this->base() ) ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'wpc_fk_out', (string) $job->last_error );
		$wpdb->query( 'DROP TABLE wpc_fk_child' );
		Plugin::instance()->job_actions()->retry( $job->id );
		$this->assertSame( SwapCheckStep::ID, Plugin::instance()->jobs()->find( $job->id )->step );
		$this->assertSame( array(), Plugin::instance()->jobs()->find( $job->id )->cursor, 'from its start' );
		$job = $this->run_restore( $job );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertSame( 2, $claims, 'the retry took the ledger and checked everything again' );
	}

	public function test_a_file_of_this_plugin_that_is_a_directory_now_is_a_change(): void {
		$job = $this->tampered(
			$this->base(),
			function (): void {
				unlink( $this->plugin_copy . '/readme.txt' );
				mkdir( $this->plugin_copy . '/readme.txt' );
			}
		);
		rmdir( $this->plugin_copy . '/readme.txt' );
		$this->assertFinal( $job, 'WP Checkpoint was updated while the restore ran (its file readme.txt is gone)' );
	}

	public function test_a_rewrite_record_from_an_older_version_ends_the_restore_with_the_reason(): void {
		$job = $this->tampered(
			$this->base(),
			function ( Job $job ): void {
				\WPCheckpoint\Jobs\ExportPlan::write( $this->work( $job ), RestoreFiles::PREFIX_REPORT, array( 'identified' => true ) );
			}
		);
		$this->assertFinal( $job, 'rewritten by an older version of WP Checkpoint' );
	}

	public function test_a_restored_table_referencing_a_live_one_the_swap_moves_away_refuses_the_swap(): void {
		global $wpdb;
		$q = $wpdb->base_prefix;
		$this->create( $q . 'wpc_parent', '(id int NOT NULL PRIMARY KEY) ENGINE=InnoDB' );
		$wpdb->insert( $q . 'wpc_parent', array( 'id' => 1 ) );
		$this->create( $q . 'wpc_child', "(id int NOT NULL PRIMARY KEY, parent int, CONSTRAINT wpc_child_parent FOREIGN KEY (parent) REFERENCES `{$q}wpc_parent` (id)) ENGINE=InnoDB" );
		$base = $this->base( array( $q . 'wpc_child' ) ); // The parent stays out of the backup.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=0' );
		$wpdb->query( "DROP TABLE `{$q}wpc_child`, `{$q}wpc_parent`" ); // Not there when the restore starts: its preflight lets it pass.
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS=1' );
		$job = $this->tampered(
			$base,
			function () use ( $q ): void {
				// Made after the preflight: a live table the swap moves away, which the restored table references.
				$this->create( $q . 'wpc_parent', '(id int NOT NULL PRIMARY KEY) ENGINE=InnoDB' );
			}
		);
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( '→ ' . $q . 'wpc_parent)', (string) $job->last_error );
	}

	public function test_a_failed_restore_that_can_be_retried_holds_automatic_updates(): void {
		global $wpdb;
		$job = $this->run_restore( $this->start_restore( 'no-such-backup' ) );
		$this->assertSame( Job::FAILED, $job->status, 'the control: a restore that failed' );
		$this->assertTrue( JobRepository::restore_in_progress() );
		$wpdb->update( JobRepository::table(), array( 'work_expired_at' => 1 ), array( 'id' => $job->id ) );
		$this->assertFalse( JobRepository::restore_in_progress(), 'no longer retryable: nothing held' );
	}

	private function session(): ImportSession {
		if ( null === $this->db ) {
			$this->db = ImportSession::open( Credentials::from_wordpress() );
		}
		return $this->db;
	}
}
