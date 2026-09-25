<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\DatabaseImportStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * Only the run that holds the job changes the restore's temporary tables:
 * the lease is confirmed right before a table is claimed, dropped or
 * emptied, and a table without transactions must hold exactly the rows the
 * ledger recorded when its last chunk is done.
 */
final class RestoreLeaseTest extends RestoreTestCase {

	/** @var string */
	private $p;

	/** @var int */
	private $job_id = 0;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->p = $wpdb->base_prefix . 'wpcl_';
		$this->create( $this->p . 'big', '(`id` bigint unsigned NOT NULL AUTO_INCREMENT, `v` longtext, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
		$this->create( $this->p . 'isam', '(`id` int NOT NULL AUTO_INCREMENT, `v` text, PRIMARY KEY (`id`)) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4' );
		$this->create( $this->p . 'last', '(`id` int NOT NULL, `v` varchar(10), PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' );
		for ( $batch = 0; $batch < 5; $batch++ ) {
			$rows = array();
			for ( $i = 0; $i < 100; $i++ ) {
				$rows[] = $wpdb->prepare( '(%s)', str_repeat( chr( 97 + ( $i % 26 ) ), 5000 ) . $batch . '-' . $i );
			}
			$wpdb->query( "INSERT INTO `{$this->p}big` (`v`) VALUES " . implode( ',', $rows ) );
			$wpdb->query( "INSERT INTO `{$this->p}isam` (`v`) VALUES " . implode( ',', $rows ) );
		}
		$wpdb->query( "INSERT INTO `{$this->p}last` VALUES (1, 'a'), (2, 'b')" );
		$this->assertSame( '', $wpdb->last_error );
	}

	/**
	 * The tables of the backup.
	 *
	 * @return string[]
	 */
	private function tables(): array {
		return array_merge( self::site_tables(), array( $this->p . 'big', $this->p . 'isam', $this->p . 'last' ) );
	}

	/**
	 * A job type with the restore's steps and this seam in the import.
	 */
	private function start( string $base, callable $seam ): Job {
		$type  = 'restore_lease_' . bin2hex( random_bytes( 3 ) );
		$steps = Plugin::instance()->job_types()->get( 'restore' )->steps();

		$steps[2] = new DatabaseImportStep( null, $seam );
		$this->register( $type, $steps );
		$job          = Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) );
		$this->job_id = $job->id;
		return $job;
	}

	/**
	 * Tick until the job stops running or loses its lease.
	 */
	private function tick_until_lost_or_done( Job $job ): string {
		for ( $i = 0; $i < 200; $i++ ) {
			$result = Plugin::instance()->runner()->tick( $job->id, microtime( true ) )->status;
			if ( 'lost' === $result || ! in_array( Plugin::instance()->jobs()->find( $job->id )->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				$GLOBALS['wpdb']->query( 'COMMIT' );
				return $result;
			}
		}
		$this->fail( 'the restore neither ended nor lost its lease' );
	}

	/**
	 * Another driver takes the job over: its lease is no longer this run's.
	 */
	private function take_over(): void {
		global $wpdb;
		$wpdb->update(
			Schema::jobs_table(),
			array(
				'lock_token'   => str_repeat( 'f', 32 ),
				'locked_until' => time() + 3600,
			),
			array( 'id' => $this->job_id )
		);
	}

	/**
	 * A table's temporary name in the running job's plan.
	 */
	private function temporary( string $table ): string {
		$job = Plugin::instance()->jobs()->find( $this->job_id );
		return RestorePreflightStep::load_plan( Residue::work_dir( $job->storage_path, $job->id ) )['plan']->find( $table )['temporary'];
	}

	/**
	 * The ledger's rows: table number => [chunk, rows, holder].
	 *
	 * @return array<int, array{0: int, 1: int, 2: string}>
	 */
	private function ledger(): array {
		global $wpdb;
		$job    = Plugin::instance()->jobs()->find( $this->job_id );
		$random = RestorePreflightStep::load_plan( Residue::work_dir( $job->storage_path, $job->id ) )['random'];
		$name   = TempTables::ledger( $job->storage_token, $job->id, $random );
		$out    = array();
		foreach ( (array) $wpdb->get_results( "SELECT n, chunk, row_count, holder FROM `{$name}`", ARRAY_N ) as $row ) {
			$out[ (int) $row[0] ] = array( (int) $row[1], (int) $row[2], (string) $row[3] );
		}
		return $out;
	}

	/**
	 * A table's number in the running job's plan.
	 */
	private function number( string $table ): int {
		$job = Plugin::instance()->jobs()->find( $this->job_id );
		return (int) RestorePreflightStep::load_plan( Residue::work_dir( $job->storage_path, $job->id ) )['plan']->find( $table )['number'];
	}

	public function test_a_run_that_no_longer_holds_the_job_claims_no_table(): void {
		$last = $this->p . 'last';
		$job  = $this->start(
			$this->backup( $this->tables() ),
			function ( string $point, string $table = '' ) use ( $last ): void {
				if ( 'claiming' === $point && $table === $last ) {
					$this->take_over();
				}
			}
		);
		$this->assertSame( 'lost', $this->tick_until_lost_or_done( $job ) );
		$ledger = $this->ledger();
		$this->assertArrayHasKey( $this->number( $this->p . 'big' ), $ledger, 'the control: tables before it were claimed and recorded' );
		$this->assertArrayNotHasKey( $this->number( $last ), $ledger, 'the table was not claimed' );
	}

	public function test_a_run_that_no_longer_holds_the_job_drops_no_table(): void {
		global $wpdb;
		$last     = $this->p . 'last';
		$sentinel = '';
		$job      = $this->start(
			$this->backup( $this->tables() ),
			function ( string $point, string $table = '' ) use ( $last, &$sentinel ): void {
				global $wpdb;
				if ( 'claiming' === $point && '' === $sentinel ) {
					// What another run could have made of this table already: it must survive a run that lost the job.
					$sentinel = $this->temporary( $last );
					$wpdb->query( "CREATE TABLE `{$sentinel}` (`id` int NOT NULL, `v` varchar(10), PRIMARY KEY (`id`)) ENGINE=InnoDB" );
					$wpdb->query( "INSERT INTO `{$sentinel}` VALUES (99, 'kept')" );
					$this->created[] = $sentinel;
				}
				if ( 'claimed' === $point && $table === $last ) {
					$this->take_over();
				}
			}
		);
		$this->assertSame( 'lost', $this->tick_until_lost_or_done( $job ) );
		$this->assertSame( 'kept', $wpdb->get_var( "SELECT v FROM `{$sentinel}` WHERE id = 99" ), 'the table was not dropped' );
	}

	public function test_a_run_that_no_longer_holds_the_job_empties_no_table(): void {
		global $wpdb;
		$isam    = $this->p . 'isam';
		$phase   = 'first';
		$statements = 0;
		$job     = $this->start(
			$this->backup( $this->tables() ),
			function ( string $point, string $table = '' ) use ( $isam, &$phase, &$statements ): void {
				if ( $table !== $isam ) {
					return;
				}
				// The table's first statement is its CREATE, the second its first INSERT: stop after that ran, before its record.
				if ( 'first' === $phase && 'statement' === $point && 2 === ++$statements ) {
					$phase = 'stopped';
					throw new \RuntimeException( 'simulated: the run stops between a statement and its record' );
				}
				if ( 'retry' === $phase && 'claimed' === $point ) {
					$this->take_over();
				}
			}
		);
		$this->tick_until_lost_or_done( $job );
		$this->assertSame( Job::FAILED, Plugin::instance()->jobs()->find( $job->id )->status, 'the first run stopped between a statement and its record' );
		// Rows the ledger does not know: the next run finds the count off and must empty the table, but only while it holds the job.
		$temporary = $this->temporary( $isam );
		$before    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$temporary}`" );
		$this->assertGreaterThan( 0, $before );
		$phase = 'retry';
		Plugin::instance()->job_actions()->retry( $job->id );
		$this->assertSame( 'lost', $this->tick_until_lost_or_done( $job ) );
		$this->assertSame( $before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$temporary}`" ), 'the table was not emptied' );
	}

	public function test_rows_a_table_without_transactions_holds_beyond_the_ledgers_fail_it_at_its_end(): void {
		global $wpdb;
		$isam  = $this->p . 'isam';
		$added = false;
		$job   = $this->start(
			$this->backup( $this->tables() ),
			function ( string $point, string $table = '', int $chunk = 0 ) use ( $isam, &$added ): void {
				global $wpdb;
				if ( $added || 'statement' !== $point || $table !== $isam || $chunk < 2 ) {
					return;
				}
				// A row that no statement of this run wrote, after this tick counted the table.
				$wpdb->query( 'INSERT INTO `' . $this->temporary( $isam ) . "` (`v`) VALUES ('second writer')" );
				$added = true;
			}
		);
		$this->tick_until_lost_or_done( $job );
		$job = Plugin::instance()->jobs()->find( $job->id );
		$this->assertTrue( $added, 'the control: the row was added during the import' );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( "The table {$isam} holds", (string) $job->last_error );
		$this->assertStringContainsString( 'rows were added or removed outside the import while it ran', (string) $job->last_error );
	}

	public function test_rows_a_table_without_transactions_lost_against_the_ledger_fail_it_at_its_end(): void {
		$isam    = $this->p . 'isam';
		$removed = false;
		$job     = $this->start(
			$this->backup( $this->tables() ),
			function ( string $point, string $table = '', int $chunk = 0 ) use ( $isam, &$removed ): void {
				global $wpdb;
				if ( $removed || 'statement' !== $point || $table !== $isam || $chunk < 2 ) {
					return;
				}
				// A recorded row gone, after this tick counted the table.
				$wpdb->query( 'DELETE FROM `' . $this->temporary( $isam ) . '` ORDER BY `id` LIMIT 1' );
				$removed = 1 === (int) $wpdb->rows_affected;
			}
		);
		$this->tick_until_lost_or_done( $job );
		$job = Plugin::instance()->jobs()->find( $job->id );
		$this->assertTrue( $removed, 'the control: a row was removed during the import' );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( "The table {$isam} holds", (string) $job->last_error );
	}

	/**
	 * A table with transactions: a batch's rows and the ledger's record of them are committed together, and when
	 * the record is refused (another run claimed the table while the batch ran), the batch's rows are rolled back
	 * with it. Nothing the ledger does not record stays in the table, which is why such a table needs no count.
	 *
	 * The run still holds the job: it waits the back-off with the job released, not taken over (a lost lease
	 * would keep the job locked until it runs out and count a takeover), and the next run claims the table back
	 * and finishes it.
	 */
	public function test_a_batch_whose_record_is_refused_leaves_no_row_behind(): void {
		global $wpdb;
		$big     = $this->p . 'big';
		$claimed = false;
		$job     = $this->start(
			$this->backup( $this->tables() ),
			function ( string $point, string $table = '', int $chunk = 0 ) use ( $big, &$claimed ): void {
				global $wpdb;
				if ( $claimed || 'statement' !== $point || $table !== $big || $chunk < 2 ) {
					return;
				}
				// A batch has run and is not committed yet; another run claims the table now.
				$job    = Plugin::instance()->jobs()->find( $this->job_id );
				$random = RestorePreflightStep::load_plan( Residue::work_dir( $job->storage_path, $job->id ) )['random'];
				$ledger = TempTables::ledger( $job->storage_token, $job->id, $random );
				$wpdb->query( 'COMMIT' ); // The test's snapshot predates the ledger.
				$wpdb->query( $wpdb->prepare( "UPDATE `{$ledger}` SET holder = %s WHERE n = %d", str_repeat( 'e', 32 ), $this->number( $big ) ) );
				$claimed = 1 === (int) $wpdb->rows_affected;
				$wpdb->query( 'COMMIT' ); // The other run's claim is committed, as a real one is.
			}
		);
		for ( $i = 0; $i < 200 && ! $claimed; $i++ ) {
			$result = Plugin::instance()->runner()->tick( $job->id, microtime( true ) );
		}
		$wpdb->query( 'COMMIT' );
		$this->assertTrue( $claimed, 'the control: the table was claimed by another run mid-batch' );
		$this->assertSame( TickResult::WAITING, $result->status, 'the run stopped when its record was refused, to be retried: ' . $result->message );
		$this->assertStringContainsString( 'Another run of this restore', $result->message );
		$recorded = $this->ledger()[ $this->number( $big ) ][1];
		$held     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->temporary( $big ) . '`' );
		$this->assertGreaterThan( 0, $recorded, 'the control: earlier batches were recorded' );
		$this->assertSame( $recorded, $held, 'the refused batch left no row behind' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertFalse( $now->is_locked( time() ), 'the job is released' );

		$this->assertSame( TickResult::COMPLETED, $this->tick_until_lost_or_done( $job ), 'the next run takes the table back and finishes' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::COMPLETED, $now->status, (string) $now->last_error );
		$this->assertSame( 0, (int) $now->takeovers, 'no takeover' );
		$this->assertSame( (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$big}`" ), (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . $this->temporary( $big ) . '`' ) );
	}
}
