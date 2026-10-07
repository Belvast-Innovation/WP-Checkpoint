<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\LockFile;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\UninstallFence;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Support\UninstallSetting;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * The uninstall fence (UninstallFence): an uninstall closes its one row with one UPDATE before it removes anything,
 * and a swap enters the site with one UPDATE of its job's row and the fence row together, on the condition that the
 * fence is open. Both write the fence row, so they cannot interleave: shown here with two real connections. Also the
 * uninstall's second line (a read before each step that removes something), its log, and its cancel.
 */
final class UninstallFenceTest extends SwapTestCase {

	/** @var int[] Job rows this test committed. */
	private $committed = array();

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		UninstallFence::create(); // The table and its row, made again if a test dropped them.
		UninstallFence::open();
		UninstallSetting::save( false ); // A setting a test committed does not delete a later test's data.
		$this->seam( null );
		$this->beat_every( null );
		foreach ( $this->committed as $id ) {
			$wpdb->delete( JobRepository::table(), array( 'id' => $id ) );
		}
		$wpdb->query( 'COMMIT' );
		Plugin::instance()->reset_directories(); // The storage directory, made again if a test's uninstall removed it.
		Plugin::instance()->directories()->base();
		parent::tear_down();
	}

	/**
	 * A job that runs with a lock and has not changed the site, committed so another connection sees it.
	 *
	 * @param array<string, mixed> $columns More columns.
	 */
	private function running_job( array $columns = array() ): Job {
		global $wpdb;
		$job = Plugin::instance()->jobs()->create( 'export' );
		$wpdb->update(
			JobRepository::table(),
			array_merge(
				array(
					'status'       => Job::RUNNING,
					'lock_token'   => 'fence-test-' . $job->id,
					'locked_until' => time() + 300,
				),
				$columns
			),
			array( 'id' => $job->id )
		);
		$wpdb->query( 'COMMIT' );
		$this->committed[] = $job->id;
		return Plugin::instance()->jobs()->find( $job->id );
	}

	/**
	 * The statement by which that job enters the site, as the runner writes it.
	 */
	private static function entering( Job $job ): string {
		return JobRepository::entering_sql( $job->id, (string) $job->lock_token, array( 'site_state' => Job::SITE_CHANGING, 'updated_at' => time() ), array( '%d', '%d' ), time() );
	}

	/**
	 * Another connection to the same database.
	 */
	private static function other(): \wpdb {
		$other = new \wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		$other->suppress_errors( true );
		return $other;
	}

	/**
	 * Run $work with the uninstall's PHP error log in a sandbox file, autocommitting as an uninstall does; the log.
	 */
	private function logged( callable $work ): string {
		$dir = Sandbox::make( 'uninstall-log' );
		$was = ini_get( 'error_log' );
		ini_set( 'error_log', $dir . '/php-error.log' );
		try {
			$this->autocommit( $work );
		} finally {
			ini_set( 'error_log', (string) $was );
			$log = (string) @file_get_contents( $dir . '/php-error.log' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- read before the sandbox goes.
			Sandbox::remove( $dir );
		}
		return $log;
	}

	public function test_a_swap_entering_holds_the_fence_and_the_uninstall_waits_for_it(): void {
		global $wpdb;
		UninstallSetting::save( false );
		$job   = $this->running_job();
		$other = self::other();
		try {
			$other->query( 'START TRANSACTION' );
			$this->assertGreaterThan( 0, (int) $other->query( self::entering( $job ) ), 'the control: entered, not committed' );
			// The uninstall's close waits on the row the swap holds: here for a second, then it gives up (1205).
			$result = null;
			$waited = 0.0;
			$this->autocommit(
				static function () use ( &$result, &$waited ): void {
					global $wpdb;
					$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 1' );
					try {
						$start  = microtime( true );
						$result = UninstallFence::close( 'test-run' );
						$waited = microtime( true ) - $start;
					} finally {
						$wpdb->query( 'SET SESSION innodb_lock_wait_timeout = 50' );
					}
				}
			);
			$this->assertSame( UninstallFence::FAILED, $result, 'it cannot pass the swap that is entering' );
			$this->assertGreaterThanOrEqual( 0.9, $waited, 'it waited for the row' );
			$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'not closed' );
			$other->query( 'COMMIT' );
			// Once the swap has committed, the uninstall sees the job hold the site and stops.
			$log = $this->logged(
				static function (): void {
					Uninstaller::run();
				}
			);
			$this->assertStringContainsString( 'while a restore holds the site changed;', $log );
			$this->assertTrue( Schema::table_exists(), 'nothing removed' );
			$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status, 'nothing cancelled' );
			$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'the fence stays open' );
		} finally {
			$other->query( 'ROLLBACK' );
			$other->close();
			$wpdb->update( JobRepository::table(), array( 'site_state' => Job::SITE_UNTOUCHED ), array( 'id' => $job->id ) );
		}
	}

	public function test_an_uninstall_waits_on_the_close_and_then_sees_the_swap_that_entered(): void {
		global $wpdb;
		UninstallSetting::save( false );
		$job  = $this->running_job();
		$dir  = Sandbox::make( 'fence-child' );
		$flag = $dir . '/entered';
		// Another process enters the site and commits two and a half seconds later.
		file_put_contents(
			$dir . '/child.php',
			'<?php' . "\n" .
			'$c = json_decode( file_get_contents( $argv[1] ), true );' . "\n" .
			'$db = mysqli_init();' . "\n" .
			'mysqli_real_connect( $db, $c["host"], $c["user"], $c["password"], $c["name"], $c["port"] );' . "\n" .
			'mysqli_query( $db, "START TRANSACTION" );' . "\n" .
			'mysqli_query( $db, $c["sql"] );' . "\n" .
			'file_put_contents( $c["flag"], (string) mysqli_affected_rows( $db ) );' . "\n" .
			'usleep( 2500000 );' . "\n" .
			'mysqli_query( $db, "COMMIT" );' . "\n"
		);
		$host  = explode( ':', DB_HOST );
		$child = null;
		try {
			file_put_contents(
				$dir . '/config.json',
				(string) wp_json_encode(
					array(
						'host'     => $host[0],
						'port'     => isset( $host[1] ) && is_numeric( $host[1] ) ? (int) $host[1] : 3306,
						'user'     => DB_USER,
						'password' => DB_PASSWORD,
						'name'     => DB_NAME,
						'sql'      => self::entering( $job ),
						'flag'     => $flag,
					)
				)
			);
			$child = proc_open( array( PHP_BINARY, $dir . '/child.php', $dir . '/config.json' ), array(), $pipes );
			$this->assertIsResource( $child );
			for ( $i = 0; $i < 100 && ! is_file( $flag ); $i++ ) {
				usleep( 100000 );
			}
			$this->assertGreaterThan( 0, (int) @file_get_contents( $flag ), 'the control: the other process entered, not committed yet' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test.
			$start = microtime( true );
			$log   = $this->logged(
				static function (): void {
					Uninstaller::run(); // Its first read does not see the uncommitted change; its close waits.
				}
			);
			$this->assertGreaterThanOrEqual( 1.0, microtime( true ) - $start, 'the close waited for the other process' );
			$this->assertStringContainsString( 'while a restore holds the site changed;', $log, 'the read after the close saw the job' );
			$this->assertSame( Job::SITE_CHANGING, Plugin::instance()->jobs()->find( $job->id )->site_state );
			$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status, 'nothing cancelled' );
			$this->assertTrue( Schema::table_exists() );
			$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'opened again when it stopped' );
		} finally {
			if ( is_resource( $child ) ) {
				proc_close( $child );
			}
			Sandbox::remove( $dir );
			$wpdb->update( JobRepository::table(), array( 'site_state' => Job::SITE_UNTOUCHED ), array( 'id' => $job->id ) );
		}
	}

	public function test_a_swap_does_not_enter_while_the_fence_is_closed_or_missing_and_goes_on_once_it_is_open(): void {
		global $wpdb;
		$before = $this->site();
		$job    = $this->at_swap();
		foreach ( array( 'closed', 'missing' ) as $how ) {
			$this->autocommit(
				static function () use ( $how ): void {
					global $wpdb;
					if ( 'closed' === $how ) {
						UninstallFence::close( 'test-run' );
					} else {
						UninstallFence::open();
						$wpdb->query( 'DROP TABLE IF EXISTS ' . UninstallFence::name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
					}
				}
			);
			$tick = $this->cli_tick( Plugin::instance()->jobs()->find( $job->id ) );
			$now  = Plugin::instance()->jobs()->find( $job->id );
			$this->assertSame( TickResult::WAITING, $tick->status, $how . ': the swap waits (' . $tick->message . ')' );
			$this->assertSame( \WPCheckpoint\Jobs\Runner::FENCE_WAIT_SECONDS, $tick->retry_after, $how . ': a minute, not the back-off of a failure' );
			$this->assertStringContainsString( 'the restore does not start changing the site', (string) $tick->message, $how );
			$this->assertSame( Job::SITE_UNTOUCHED, $now->site_state, $how . ': not entered' );
			$this->assertNotSame( 'enter', $now->cursor['phase'] ?? '', $how . ': the cursor is the one last written, not the refused one' );
			$this->assertSame( 0, (int) ( $now->cursor['__runner']['retries'] ?? 0 ), $how . ': not counted as a try' );
			$this->assertStringContainsString( 'the restore does not start changing the site', (string) $now->progress_message, $how . ': the reason on the job while it waits' );
			$this->assertFalse( Maintenance::held_in( $this->abspath ), $how . ': no maintenance file' );
			$this->assertSame( $before, $this->site(), $how . ': the site is untouched' );
		}
		$this->autocommit( array( UninstallFence::class, 'create' ) );
		$done = $this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::COMPLETED, $done->status, 'the control: once open, it goes on (' . $done->last_error . ')' );
		$this->assertRestored( $before );
		$this->undo( $done );
		$wpdb->query( 'COMMIT' );
	}

	public function test_a_fence_closed_longer_than_an_hour_is_opened_again_and_logged(): void {
		global $wpdb;
		$this->autocommit(
			static function (): void {
				UninstallFence::close( 'test-run' );
			}
		);
		Schema::ensure( true );
		$this->assertSame( UninstallFence::CLOSED, UninstallFence::row()['state'], 'the control: closed just now, it stays closed' );
		$wpdb->update( UninstallFence::name(), array( 'closed_at' => time() - UninstallFence::STALE_SECONDS - 60 ), array( 'id' => UninstallFence::ROW ) );
		$wpdb->query( 'COMMIT' );
		Schema::ensure( true );
		$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'an uninstall that did not finish: opened again' );
		$log = Plugin::instance()->directories()->base() . '/logs/storage.log';
		$this->assertStringContainsString( 'The uninstall fence was closed for more than an hour', (string) file_get_contents( $log ) );
	}

	public function test_the_log_says_only_what_was_left(): void {
		$held = array(
			'all'       => 1,
			'abandoned' => 0,
		);
		$this->assertStringContainsString( 'its staging next to the site, its tables and its storage directory were left in place', Uninstaller::held_back( $held ), 'the control: found at the start, everything left' );
		$after = Uninstaller::held_back( $held, array( 'cancelled the jobs that had not changed the site', 'removed its staging next to the site', 'removed its storage directory' ) );
		$this->assertStringContainsString( 'after it had cancelled the jobs that had not changed the site, removed its staging next to the site, removed its storage directory', $after );
		$this->assertStringContainsString( 'left its temporary tables, its jobs table, swap plan and uninstall fence, and its settings in place', $after );
		$this->assertStringNotContainsString( 'its storage directory were left', $after, 'not said to be left' );
	}

	public function test_cancel_unlocks_only_the_jobs_it_cancelled_and_leaves_one_that_changed_the_site(): void {
		$base     = Plugin::instance()->directories()->base();
		$plain    = $this->running_job( array( 'storage_path' => $base ) );
		$changing = $this->running_job(
			array(
				'storage_path' => $base,
				'site_state'   => Job::SITE_CHANGING,
			)
		);
		foreach ( array( $plain, $changing ) as $job ) {
			$this->assertTrue( LockFile::write( $base, $job->id, (string) $job->lock_token, time() + 300 ), 'the control: its lock file' );
		}
		$this->autocommit(
			static function (): void {
				Uninstaller::cancel_jobs();
			}
		);
		$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $plain->id )->status );
		$this->assertFileDoesNotExist( LockFile::path( $base, $plain->id ), 'its lock file goes' );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $changing->id )->status, 'one that changed the site is left alone' );
		$this->assertFileExists( LockFile::path( $base, $changing->id ), 'and so is its lock file' );
		LockFile::remove( $base, $changing->id );
	}

	public function test_cancel_on_a_table_without_site_state_cancels_every_live_job(): void {
		global $wpdb;
		$job   = $this->running_job();
		$table = JobRepository::table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN site_state" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the jobs table.
		try {
			$this->autocommit(
				static function (): void {
					Uninstaller::cancel_jobs();
				}
			);
			$status = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $job->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the jobs table.
			$this->assertSame( Job::CANCELLED, $status, 'a table made before the column: none changed the site' );
		} finally {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN site_state " . Schema::COLUMNS['site_state'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- the jobs table and its definition.
			$wpdb->query( 'COMMIT' );
		}
	}

	/**
	 * Set the uninstall's test seam (null: none).
	 */
	private function seam( ?callable $at ): void {
		$property = new \ReflectionProperty( Uninstaller::class, 'at' );
		$property->setAccessible( true );
		$property->setValue( null, $at );
	}

	public function test_an_uninstall_opens_again_only_a_fence_it_closed(): void {
		UninstallSetting::save( false );
		$found = array();
		$this->seam(
			static function ( string $point ) use ( &$found ): void {
				if ( 0 === strpos( $point, 'closed:' ) ) {
					$found[] = substr( $point, 7 );
				}
			}
		);
		try {
			// The control: it closes the fence itself, keeps the data, and opens it again.
			$this->logged( array( Uninstaller::class, 'run' ) );
			$this->assertSame( array( UninstallFence::DONE ), $found );
			$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'] );
			// Closed already by another uninstall under way (a site sharing this database): this one removes nothing and
			// leaves the fence to it.
			$job = $this->running_job();
			$this->autocommit(
				static function (): void {
					UninstallFence::close( 'another-uninstall' );
				}
			);
			$log = $this->logged( array( Uninstaller::class, 'run' ) );
			$this->assertSame( array( UninstallFence::DONE, UninstallFence::HELD ), $found );
			$this->assertStringContainsString( 'while it was being uninstalled on a site that shares this database', $log );
			$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status, 'nothing cancelled' );
			$this->assertSame( UninstallFence::CLOSED, UninstallFence::row()['state'], 'not opened under the other uninstall' );
			// Closed for more than an hour (an uninstall that did not finish): opened first, then this one closes it.
			global $wpdb;
			$wpdb->update( UninstallFence::name(), array( 'closed_at' => time() - UninstallFence::STALE_SECONDS - 60 ), array( 'id' => UninstallFence::ROW ) );
			$wpdb->query( 'COMMIT' );
			$this->logged( array( Uninstaller::class, 'run' ) );
			$this->assertSame( array( UninstallFence::DONE, UninstallFence::HELD, UninstallFence::DONE ), $found );
			$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'and opens it again when it is done' );
		} finally {
			$this->seam( null );
		}
	}

	public function test_a_step_that_fails_opens_the_fence_again(): void {
		UninstallSetting::save( false );
		$this->seam(
			static function ( string $point ): void {
				if ( 'unit:staging' === $point ) {
					throw new \RuntimeException( 'the staging cannot be listed' );
				}
			}
		);
		$closed = '';
		try {
			$this->logged( array( Uninstaller::class, 'run' ) );
			$this->fail( 'the failure is passed on' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'the staging cannot be listed', $e->getMessage() );
			$closed = (string) UninstallFence::row()['state'];
		} finally {
			$this->seam( null );
		}
		$this->assertSame( UninstallFence::OPEN, $closed, 'opened again' );
	}

	/**
	 * The reads the cancel makes before it changes anything.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function cancel_reads(): array {
		return array(
			'the column'    => array( "LIKE 'site_state'" ),
			'the job table' => array( 'SHOW TABLES LIKE' ),
		);
	}

	/**
	 * @dataProvider cancel_reads
	 */
	public function test_a_cancel_that_cannot_tell_which_jobs_changed_the_site_stops_the_uninstall( string $read ): void {
		UninstallSetting::save( true );
		$job    = $this->running_job();
		$broken = false;
		$this->seam(
			static function ( string $point ) use ( &$broken ): void {
				if ( 'unit:cancel' === $point ) {
					$broken = true;
				}
			}
		);
		$filter = static function ( $sql ) use ( &$broken, $read ) {
			if ( $broken && false !== strpos( (string) $sql, $read ) ) {
				$broken = false;
				return 'SELECT * FROM a_table_that_is_not_there_for_this_test'; // The read fails, once.
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			$log = $this->logged( array( Uninstaller::class, 'run' ) );
		} finally {
			remove_filter( 'query', $filter );
			$this->seam( null );
		}
		$this->assertFalse( $broken, 'the control: the column check after the cancel began did fail' );
		$this->assertStringContainsString( 'it could not tell whether a restore holds the site changed', $log );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status, 'nothing cancelled' );
		$this->assertTrue( Schema::table_exists(), 'nothing removed' );
		$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'the fence open again' );
	}

	public function test_a_cancel_that_fails_stops_the_uninstall(): void {
		UninstallSetting::save( false );
		$job    = $this->running_job();
		$broken = false;
		$filter = static function ( $sql ) use ( &$broken ) {
			if ( ! $broken && false !== strpos( (string) $sql, "SET status = 'cancelled'" ) ) {
				$broken = true;
				return 'UPDATE a_table_that_is_not_there_for_this_test SET x = 1'; // The cancel fails, once.
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		try {
			$log = $this->logged( array( Uninstaller::class, 'run' ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertTrue( $broken, 'the control: the cancel was the statement that failed' );
		$this->assertStringContainsString( 'it could not tell whether a restore holds the site changed', $log );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status );
		$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'] );
	}

	public function test_once_the_fence_is_closed_no_swap_enters(): void {
		$job   = $this->running_job();
		$other = self::other();
		$this->autocommit(
			static function (): void {
				UninstallFence::close( 'test-run' );
			}
		);
		$this->assertSame( UninstallFence::CLOSED, UninstallFence::row()['state'] );
		$this->assertSame( 0, (int) $other->query( self::entering( $job ) ), 'closed: the swap does not enter' );
		$this->assertSame( Job::SITE_UNTOUCHED, Plugin::instance()->jobs()->find( $job->id )->site_state );
		$this->autocommit( array( UninstallFence::class, 'open' ) );
		$this->assertGreaterThan( 0, (int) $other->query( self::entering( $job ) ), 'the control: open, it enters' );
		$other->close();
		global $wpdb;
		$wpdb->update( JobRepository::table(), array( 'site_state' => Job::SITE_UNTOUCHED ), array( 'id' => $job->id ) );
	}

	/**
	 * Set how often the uninstall's storage deletion beats (null: UninstallFence::BEAT_SECONDS).
	 */
	private function beat_every( ?int $seconds ): void {
		$property = new \ReflectionProperty( Uninstaller::class, 'beat_seconds' );
		$property->setAccessible( true );
		$property->setValue( null, $seconds );
	}

	public function test_an_uninstall_that_beats_is_not_healed_while_it_runs(): void {
		global $wpdb;
		UninstallSetting::save( false );
		$checks = 0;
		$healed = null;
		$after  = null;
		$this->seam(
			static function ( string $point ) use ( &$checks, &$healed, &$after, $wpdb ): void {
				if ( 'check' === $point && 3 === ++$checks ) {
					// Before the read for the staging step, its last beat more than an hour ago: an uninstall that runs
					// that long.
					$wpdb->update( UninstallFence::name(), array( 'closed_at' => time() - UninstallFence::STALE_SECONDS - 60 ), array( 'id' => UninstallFence::ROW ) );
				}
				if ( 'unit:staging' === $point ) {
					$after  = UninstallFence::row();
					$healed = UninstallFence::heal(); // What a request on a site sharing this database does meanwhile.
				}
			}
		);
		$this->logged( array( Uninstaller::class, 'run' ) );
		$this->assertSame( 3, $checks, 'the control: the third read came before the staging step' );
		$this->assertSame( UninstallFence::CLOSED, $after['state'] ?? null );
		$this->assertGreaterThanOrEqual( time() - 60, $after['closed_at'] ?? 0, 'the beat after the read made closed_at now' );
		$this->assertFalse( $healed, 'a fence whose uninstall beats is not healed' );
		$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'opened by that uninstall when it was done' );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( 'SELECT beats FROM ' . UninstallFence::name() ), 'its beats counted' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
	}

	/**
	 * What happens to the fence after the read: opened, or opened and closed again by another uninstall.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public function after_the_read(): array {
		return array(
			'opened'                            => array( false ),
			'closed again by another uninstall' => array( true ),
		);
	}

	/**
	 * @dataProvider after_the_read
	 */
	public function test_a_fence_opened_after_a_read_stops_the_uninstall_before_its_next_step( bool $closed_again ): void {
		UninstallSetting::save( true );
		$job    = null;
		$other  = self::other();
		$beats  = 0;
		$events = array();
		$this->seam(
			function ( string $point ) use ( &$beats, &$events, &$job, $other, $closed_again ): void {
				$events[] = $point;
				if ( 'beat' === $point && 2 === ++$beats ) {
					// After the read before the staging step: the fence opened (by hand, or healed), and a swap (one
					// that began after the cancel) enters; then, perhaps, another uninstall closes it.
					$job = $this->running_job();
					$other->query( $other->prepare( 'UPDATE ' . UninstallFence::name() . ' SET state = %s WHERE id = %d', UninstallFence::OPEN, UninstallFence::ROW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
					$other->query( self::entering( $job ) );
					if ( $closed_again ) {
						$other->query( $other->prepare( UninstallFence::close_template( UninstallFence::name() ), UninstallFence::CLOSED, 'another-uninstall', UninstallFence::ROW, UninstallFence::OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own statement.
					}
				}
			}
		);
		$log = $this->logged( array( Uninstaller::class, 'run' ) );
		$other->close();
		$this->assertSame( 2, $beats, 'the control: it beat before the cancel and again before the staging step' );
		$this->assertInstanceOf( Job::class, $job );
		$this->assertContains( 'unit:cancel', $events, 'the control: the first step ran' );
		$this->assertNotContains( 'unit:staging', $events, 'the next step did not' );
		$this->assertStringContainsString( 'when the fence it had closed could not be confirmed to be still its own', $log );
		$this->assertStringContainsString( 'after it had cancelled the jobs that had not changed the site; it stopped there and left its staging next to the site, its storage directory', $log );
		$this->assertSame( Job::SITE_CHANGING, Plugin::instance()->jobs()->find( $job->id )->site_state, 'the control: the swap entered' );
		$this->assertTrue( Schema::table_exists(), 'nothing more removed' );
		$this->assertDirectoryExists( Plugin::instance()->directories()->base() );
		$this->assertSame( $closed_again ? UninstallFence::CLOSED : UninstallFence::OPEN, UninstallFence::row()['state'], 'the fence left as the other side left it' );
		global $wpdb;
		$wpdb->update( JobRepository::table(), array( 'site_state' => Job::SITE_UNTOUCHED ), array( 'id' => $job->id ) );
	}

	public function test_an_uninstall_does_not_open_a_fence_another_uninstall_closed_meanwhile(): void {
		UninstallSetting::save( true );
		$job    = null;
		$other  = self::other();
		$checks = 0;
		$this->seam(
			function ( string $point ) use ( &$checks, &$job, $other ): void {
				if ( 'check' === $point && 2 === ++$checks ) {
					// Before the read for the cancel: the fence opened, a swap enters, and another uninstall closes it.
					$job = $this->running_job();
					$other->query( $other->prepare( 'UPDATE ' . UninstallFence::name() . ' SET state = %s WHERE id = %d', UninstallFence::OPEN, UninstallFence::ROW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
					$other->query( self::entering( $job ) );
					$other->query( $other->prepare( UninstallFence::close_template( UninstallFence::name() ), UninstallFence::CLOSED, 'another-uninstall', UninstallFence::ROW, UninstallFence::OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the plugin's own statement.
				}
			}
		);
		$log = $this->logged( array( Uninstaller::class, 'run' ) );
		$other->close();
		$this->assertSame( 2, $checks, 'the control: the read before the cancel' );
		$this->assertInstanceOf( Job::class, $job );
		$this->assertStringContainsString( 'a restore holds the site changed', $log, 'the control: the read saw the swap' );
		$this->assertSame( UninstallFence::CLOSED, UninstallFence::row()['state'], 'the other uninstall\'s fence stays closed' );
		global $wpdb;
		$this->assertSame( 'another-uninstall', (string) $wpdb->get_var( 'SELECT closed_by FROM ' . UninstallFence::name() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
		$wpdb->update( JobRepository::table(), array( 'site_state' => Job::SITE_UNTOUCHED ), array( 'id' => $job->id ) );
	}

	/**
	 * Files in the storage directory, so that its deletion has several to delete.
	 */
	private function storage_files( int $count ): void {
		$dir = Plugin::instance()->directories()->base() . '/backups';
		$this->assertDirectoryExists( $dir, 'the control: the storage directory' );
		for ( $i = 0; $i < $count; $i++ ) {
			file_put_contents( $dir . '/fence-test-' . $i . '.txt', 'x' );
		}
	}

	/**
	 * How many files and directories the storage directory holds, itself included (0: gone).
	 */
	private static function storage_entries( string $base ): int {
		if ( ! is_dir( $base ) ) {
			return 0;
		}
		$count = 1;
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST ) as $entry ) {
			++$count;
		}
		return $count;
	}

	public function test_a_fence_opened_while_the_storage_is_deleted_stops_the_deletion_before_its_next_file(): void {
		UninstallSetting::save( true );
		$this->storage_files( 6 );
		$base   = Plugin::instance()->directories()->base();
		$before = self::storage_entries( $base );
		$this->beat_every( 0 );
		$deletes = 0;
		$beats   = 0;
		$events  = array();
		$this->seam(
			static function ( string $point ) use ( &$deletes, &$beats, &$events ): void {
				$events[] = $point;
				if ( 'delete' === $point ) {
					++$deletes;
				}
				if ( 'beat' === $point && $deletes > 0 && 1 === ++$beats ) {
					// Between the first deletion and the next: the fence opened.
					$other = self::other();
					$other->query( $other->prepare( 'UPDATE ' . UninstallFence::name() . ' SET state = %s WHERE id = %d', UninstallFence::OPEN, UninstallFence::ROW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
					$other->close();
				}
			}
		);
		$log = $this->logged( array( Uninstaller::class, 'run' ) );
		$this->assertSame( 1, $deletes, 'one deletion, and none after the beat that found the fence open' );
		$this->assertSame( 1, $beats, 'the control: it beat between the two' );
		$this->assertGreaterThan( 8, $before, 'the control: the storage directory had more to delete' );
		$this->assertSame( $before - 1, self::storage_entries( $base ), 'one entry went, the rest is there' );
		$this->assertNotContains( 'unit:temporary', $events, 'no later step' );
		$this->assertTrue( Schema::table_exists() );
		$this->assertStringContainsString( 'after it had cancelled the jobs that had not changed the site, removed its staging next to the site, begun to remove its storage directory; it stopped there and left the rest of its storage directory, its temporary tables', $log );
		// The rest: the owner marker may have been the one deletion, so the directory is registered and deleted here;
		// tear_down() makes it again.
		$roots = Deleter::replace_roots( array() );
		Deleter::replace_roots( $roots );
		try {
			Deleter::allow( $base );
			Deleter::delete_tree( dirname( $base ), $base );
		} finally {
			Deleter::replace_roots( $roots ); // Registered for this deletion only.
		}
		$this->assertSame( 0, self::storage_entries( $base ) );
	}

	public function test_the_storage_deletion_beats_no_more_often_than_every_beat_seconds(): void {
		UninstallSetting::save( true );
		$this->storage_files( 6 );
		$events = array();
		$this->seam(
			static function ( string $point ) use ( &$events ): void {
				$events[] = $point;
				if ( 'check' === $point && in_array( 'unit:storage', $events, true ) ) {
					throw new \RuntimeException( 'stop after the storage step' );
				}
			}
		);
		try {
			$this->logged( array( Uninstaller::class, 'run' ) );
			$this->fail( 'the control: stopped after the storage step' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'stop after the storage step', $e->getMessage() );
		}
		$start = (int) array_search( 'unit:storage', $events, true );
		$this->assertSame( 'beat', $events[ $start - 1 ] ?? '', 'the control: a beat is seen, right before the step' );
		$during = array_slice( $events, $start + 1 );
		$this->assertGreaterThan( 6, count( array_keys( $during, 'delete', true ) ), 'the control: each deletion was seen' );
		$this->assertSame( array(), array_keys( $during, 'beat', true ), 'no beat within the first BEAT_SECONDS' );
		$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'the failure opened it again' );
	}

	public function test_a_heartbeat_the_database_refuses_stops_the_uninstall_and_opens_its_fence(): void {
		UninstallSetting::save( true );
		$beats  = 0;
		$events = array();
		$filter = static function ( $sql ) use ( &$beats ) {
			if ( false !== strpos( (string) $sql, 'beats = beats + 1' ) && 2 === ++$beats ) {
				return 'UPDATE a_table_that_is_not_there_for_this_test SET x = 1'; // The second beat fails, once.
			}
			return $sql;
		};
		$this->seam(
			static function ( string $point ) use ( &$events ): void {
				$events[] = $point;
			}
		);
		add_filter( 'query', $filter );
		try {
			$log = $this->logged( array( Uninstaller::class, 'run' ) );
		} finally {
			remove_filter( 'query', $filter );
		}
		$this->assertSame( 2, $beats, 'the control: the second beat was the statement that failed' );
		$this->assertContains( 'unit:cancel', $events, 'the control: the first step ran' );
		$this->assertNotContains( 'unit:staging', $events, 'the next step did not' );
		$this->assertStringContainsString( 'or the database refused the heartbeat', $log );
		$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'its own fence opened again, not left closed for an hour' );
		$this->assertTrue( Schema::table_exists() );
	}

	public function test_a_healed_fence_has_no_owner_so_a_version_twelve_close_is_no_ones_to_beat(): void {
		global $wpdb;
		$this->autocommit(
			static function (): void {
				UninstallFence::close( 'run-a' );
			}
		);
		$this->assertTrue( UninstallFence::beat( 'run-a' ), 'the control: closed by run-a, it beats' );
		$wpdb->update( UninstallFence::name(), array( 'closed_at' => time() - UninstallFence::STALE_SECONDS - 60 ), array( 'id' => UninstallFence::ROW ) );
		$this->assertTrue( UninstallFence::heal() );
		$this->assertSame( '', (string) $wpdb->get_var( 'SELECT closed_by FROM ' . UninstallFence::name() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
		// An uninstall of version 12 closes it, writing no owner.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . UninstallFence::name() . ' SET state = %s, closed_at = %d WHERE id = %d AND state = %s', UninstallFence::CLOSED, time(), UninstallFence::ROW, UninstallFence::OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
		$this->assertFalse( UninstallFence::beat( 'run-a' ), 'run-a does not take it for its own' );
		UninstallFence::open( 'run-a' );
		$this->assertSame( UninstallFence::CLOSED, UninstallFence::row()['state'], 'nor opens it' );
	}

	public function test_an_uninstall_adds_the_fence_columns_the_migration_could_not_and_stops_while_it_cannot(): void {
		global $wpdb;
		$fence = UninstallFence::name();
		$drop  = static function () use ( $wpdb, $fence ): void {
			$wpdb->query( "ALTER TABLE {$fence} DROP COLUMN closed_by, DROP COLUMN beats" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the fence table.
		};
		$columns = static function () use ( $wpdb, $fence ): array {
			return $wpdb->get_col( "SHOW COLUMNS FROM {$fence}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the fence table.
		};
		$refused = 0;
		$filter  = static function ( $sql ) use ( &$refused ) {
			if ( false !== strpos( (string) $sql, 'wpcheckpoint_fence` ADD COLUMN' ) ) {
				++$refused;
				return 'ALTER TABLE a_table_that_is_not_there_for_this_test ADD COLUMN x int'; // The ALTER is refused.
			}
			return $sql;
		};
		UninstallSetting::save( false );
		try {
			$job = $this->running_job(); // Before the columns go: making a job checks the schema, which adds them.
			$drop();
			$this->assertNotContains( 'closed_by', $columns(), 'the control: version 13 recorded, the columns missing' );
			add_filter( 'query', $filter );
			try {
				$log = $this->logged( array( Uninstaller::class, 'run' ) );
			} finally {
				remove_filter( 'query', $filter );
			}
			$this->assertStringContainsString( 'lacks columns this version adds and the database did not let them be added', $log );
			$this->assertGreaterThan( 0, $refused, 'the control: the uninstall tried to add them' );
			$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status, 'nothing cancelled' );
			$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'], 'not closed' );
			$this->assertNotContains( 'closed_by', $columns() );
			// The check of the schema adds them once the database lets it.
			Schema::ensure( true );
			$this->assertContains( 'closed_by', $columns(), 'added by the check of the schema' );
			$this->assertContains( 'beats', $columns() );
			// And the uninstall adds them itself, and goes on.
			$drop();
			$log = $this->logged( array( Uninstaller::class, 'run' ) );
			$this->assertContains( 'closed_by', $columns(), 'added by the uninstall' );
			$this->assertStringNotContainsString( 'lacks columns', $log );
			$this->assertSame( Job::CANCELLED, Plugin::instance()->jobs()->find( $job->id )->status, 'the control: it went on' );
			$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'] );
		} finally {
			UninstallFence::upgrade();
			$wpdb->query( 'COMMIT' );
		}
	}

	public function test_a_fence_opened_by_hand_has_no_owner_so_a_version_twelve_close_is_no_ones_to_beat(): void {
		global $wpdb;
		$this->autocommit(
			static function (): void {
				UninstallFence::close( 'run-a' );
			}
		);
		$this->assertTrue( UninstallFence::beat( 'run-a' ), 'the control: closed by run-a, it beats' );
		$this->assertTrue( UninstallFence::open(), 'opened for repair' );
		$this->assertSame( '', (string) $wpdb->get_var( 'SELECT closed_by FROM ' . UninstallFence::name() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
		// An uninstall of version 12 closes it, writing no owner.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . UninstallFence::name() . ' SET state = %s, closed_at = %d WHERE id = %d AND state = %s', UninstallFence::CLOSED, time(), UninstallFence::ROW, UninstallFence::OPEN ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the fence table.
		$this->assertFalse( UninstallFence::beat( 'run-a' ), 'run-a does not take it for its own' );
		UninstallFence::open( 'run-a' );
		$this->assertSame( UninstallFence::CLOSED, UninstallFence::row()['state'], 'nor opens it' );
	}

	public function test_a_stale_fence_on_a_table_without_the_new_columns_is_opened_and_so_is_one_opened_for_repair(): void {
		global $wpdb;
		$fence = UninstallFence::name();
		$stale = static function () use ( $wpdb, $fence ): void {
			$wpdb->query( $wpdb->prepare( "UPDATE {$fence} SET state = %s, closed_at = %d WHERE id = %d", UninstallFence::CLOSED, time() - UninstallFence::STALE_SECONDS - 60, UninstallFence::ROW ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the fence table.
		};
		try {
			$wpdb->query( "ALTER TABLE {$fence} DROP COLUMN closed_by, DROP COLUMN beats" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the fence table.
			$this->assertNotContains( 'closed_by', $wpdb->get_col( "SHOW COLUMNS FROM {$fence}" ), 'the control: the columns missing' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the fence table.
			$stale();
			$this->assertTrue( UninstallFence::heal(), 'left closed by an uninstall that died: opened all the same' );
			$this->assertSame( UninstallFence::OPEN, UninstallFence::row()['state'] );
			$stale();
			$this->assertTrue( UninstallFence::open(), 'opened for repair' );
		} finally {
			UninstallFence::upgrade();
			$wpdb->query( 'COMMIT' );
		}
	}
}
