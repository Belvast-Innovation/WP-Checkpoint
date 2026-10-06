<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\LockFile;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\UninstallFence;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
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
		foreach ( $this->committed as $id ) {
			$wpdb->delete( JobRepository::table(), array( 'id' => $id ) );
		}
		$wpdb->query( 'COMMIT' );
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
						$result = UninstallFence::close();
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
						UninstallFence::close();
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
				UninstallFence::close();
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
			$this->autocommit( array( UninstallFence::class, 'close' ) );
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
				UninstallFence::close();
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
}
