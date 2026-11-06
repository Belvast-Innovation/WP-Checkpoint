<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobStore;
use WPCheckpoint\Jobs\SessionExecutor;
use WPCheckpoint\Jobs\StoreExecutor;
use WPCheckpoint\Jobs\UninstallFence;
use WPCheckpoint\Jobs\UpdateSql;
use WPCheckpoint\Jobs\WpdbExecutor;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\StorageDirs;

/**
 * The store's statements (JobStore) give the same rows whichever executor sends them: through wpdb, as in
 * WordPress, or through a connection made without WordPress (SessionExecutor). And an update in the store's form
 * (UpdateSql) is the statement wpdb::update() sends.
 */
final class JobStoreParityTest extends WP_UnitTestCase {

	/** @var string[]|null The storage directories in wp-content when the test started (StorageDirs); null before set_up(). */
	private $storage_before = null;

	/** @var ImportSession|null */
	private $session = null;

	public function set_up(): void {
		parent::set_up();
		\WPCheckpoint\Tests\Fixtures\RunTables::note();
		$this->storage_before = StorageDirs::listing();
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Schema::ensure();
	}

	public function tear_down(): void {
		global $wpdb;
		if ( null !== $this->session ) {
			$this->session->close();
		}
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() ); // With the row the other connection committed.
		Options::delete( Schema::OPTION );
		\WPCheckpoint\Tests\Fixtures\RunTables::put_back();
		parent::tear_down();
		if ( null !== $this->storage_before ) {
			StorageDirs::remove_made_since( $this->storage_before );
		}
	}

	/**
	 * A store on this executor, with the same clock and the same encoder for both.
	 *
	 * @param StoreExecutor $db Executor.
	 * @param int           $now The clock.
	 * @return JobStore
	 */
	private static function store( StoreExecutor $db, int &$now ): JobStore {
		return new JobStore(
			$db,
			Schema::jobs_table(),
			UninstallFence::name(),
			static function () use ( &$now ): int {
				return $now;
			},
			'wp_json_encode',
			static function ( string $text ): string {
				return $text;
			},
			static function ( string $text ): string {
				return $text;
			},
			static function ( array $value ): void {
				JobRepository::assert_cursor_has_no_secrets( $value );
			},
			static function (): bool {
				return false;
			}
		);
	}

	/**
	 * A run's statements on a job: taken, kept, written, let go, failed with a step to retry from, retried.
	 *
	 * @param JobStore $store Store.
	 * @param int      $id    Job id.
	 * @param int      $now   The clock (moved along).
	 * @param string   $storage_token The job's storage token.
	 * @return void
	 */
	private function sequence( JobStore $store, int $id, int &$now, string $storage_token ): void {
		list( $managing, $params ) = JobRepository::managing_condition( $storage_token, array( $storage_token ) );
		$this->assertTrue( $store->claim( $id, 'token-one', 120, $managing, $params ), 'taken' );
		$job = $store->find( $id );
		$now += 10;
		$this->assertNotNull( $store->heartbeat( $job, 'token-one', 120 ) );
		$store->save_record( $job, 'token-one', 'tables', 3 );
		$now += 5;
		$store->save_progress( $job, 'token-one', 'stage', array( 'phase' => 'rollback', 'name' => '中文/表', 'deep' => array( 'a' => array( 1, 2 ) ) ), 40, 'Putting the site back as it was' );
		$this->assertTrue( $store->release( $job, 'token-one' ) );
		$now += 30;
		$this->assertTrue( $store->claim( $id, 'token-two', 120, $managing, $params ), 'taken again' );
		$job = $store->find( $id );
		$store->save_progress( $job, 'token-two', 'stage', array( 'phase' => 'rollback', 'n' => 2 ), 60, 'Putting the site back as it was', false );
		$job->cursor['__retry_from'] = 'stage';
		$store->write_transition( $job, Job::FAILED, 'It failed.', 'token-two', array( 'cursor_json' => wp_json_encode( $job->cursor ) ), array( '%s' ), Job::FAILURE_TEMPORARY, 0, '__retry_from' );
		$now += 60;
		$store->write_transition( $store->find( $id ), Job::QUEUED, '', '', array(), array(), '', 0, '__retry_from' );
	}

	/**
	 * A queued job's row, as JobRepository::create() writes one, through this executor (each run of the test touches
	 * only its own row: the one wpdb writes stays in the test's transaction, the other connection commits its own).
	 *
	 * @param StoreExecutor $db    Executor.
	 * @param string        $token Storage token.
	 * @return int Its id.
	 */
	private function insert( StoreExecutor $db, string $token ): int {
		$marker = 'parity-' . bin2hex( random_bytes( 4 ) );
		$this->assertSame(
			1,
			$db->execute(
				'INSERT INTO ' . Schema::jobs_table() . ' (site_id, type, status, cursor_json, options_json, storage_token, storage_path, owner_user, created_at, updated_at, progress_at, log_path) VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %d, %d, %d, %s)',
				array( 1, 'parity', Job::QUEUED, '{"at":1}', '{"policy":[]}', $token, '', 0, 1800000000, 1800000000, 1800000000, $marker )
			)
		);
		// Read through the connection that wrote it: wpdb's transaction reads a snapshot that may predate the other's row.
		$rows = $db->select( 'SELECT id FROM ' . Schema::jobs_table() . ' WHERE log_path = %s', array( $marker ) );
		return (int) ( $rows[0]['id'] ?? 0 );
	}

	public function test_both_executors_leave_the_same_row(): void {
		global $wpdb;
		$token         = 'aaaaaaaaaaaa';
		$this->session = ImportSession::open( Credentials::from_wordpress() );
		$wpdb_db       = new WpdbExecutor();
		$session_db    = new SessionExecutor( $this->session );
		$a             = $this->insert( $wpdb_db, $token );
		$b             = $this->insert( $session_db, $token );
		$this->assertGreaterThan( 0, $a );
		$this->assertGreaterThan( 0, $b, 'the control: the other connection\'s row is seen' );

		$now = 1800000000;
		$this->sequence( self::store( $wpdb_db, $now ), $a, $now, $token );
		$now = 1800000000;
		$this->sequence( self::store( $session_db, $now ), $b, $now, $token );

		$rows = array();
		foreach ( array( array( $wpdb_db, $a ), array( $session_db, $b ) ) as $read ) {
			$row = (array) ( $read[0]->select( 'SELECT * FROM ' . Schema::jobs_table() . ' WHERE id = %d', array( $read[1] ) )[0] ?? array() );
			unset( $row['id'], $row['log_path'] );
			$rows[] = $row;
		}
		$this->assertNotSame( array(), $rows[0], 'the control: the rows are read' );
		$this->assertSame( Job::QUEUED, $rows[0]['status'], 'the control: the sequence ran to its end' );
		$this->assertStringContainsString( '"tables":3', (string) $rows[0]['options_json'], 'the control: the record was written' );
		$this->assertSame( $rows[0], $rows[1], 'column by column' );
		// The one read path: each row read through its executor hydrates to the same job (but its id and log).
		$by_wpdb              = self::store( $wpdb_db, $now )->find( $a );
		$by_session           = self::store( $session_db, $now )->find( $b );
		$by_session->id       = $by_wpdb->id;
		$by_session->log_path = $by_wpdb->log_path;
		$this->assertEquals( $by_wpdb, $by_session );
	}

	public function test_an_update_in_the_stores_form_is_the_statement_wpdb_update_sends(): void {
		global $wpdb;
		$job     = new Job();
		$job->id = $this->insert( new WpdbExecutor(), 'aaaaaaaaaaaa' );
		$data = array(
			'step'             => 'stage',
			'cursor_json'      => '{"a":"100% \'q\'"}',
			'progress'         => 5,
			'progress_message' => null,
		);
		list( $template, $params ) = UpdateSql::template( Schema::jobs_table(), $data, array( '%s', '%s', '%d', '%s' ), array( 'id' => $job->id, 'lock_token' => '' ), array( '%d', '%s' ) );
		$wpdb->update( Schema::jobs_table(), $data, array( 'id' => $job->id, 'lock_token' => '' ), array( '%s', '%s', '%d', '%s' ), array( '%d', '%s' ) );
		$this->assertStringStartsWith( 'UPDATE `', (string) $wpdb->last_query, 'the control: the update was sent' );
		$this->assertSame( $wpdb->remove_placeholder_escape( $wpdb->prepare( $template, $params ) ), $wpdb->remove_placeholder_escape( (string) $wpdb->last_query ) );
	}
}
