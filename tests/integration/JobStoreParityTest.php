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
use WPCheckpoint\Support\Directories;
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
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
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

	public function test_both_executors_leave_the_same_row(): void {
		global $wpdb;
		$dirs  = new Directories( array( 'is_web_request' => false ) );
		$repo  = new JobRepository( $dirs );
		$a     = $repo->create( 'parity', 0, array( 'at' => 1 ), array( 'policy' => array() ) );
		$b     = $repo->create( 'parity', 0, array( 'at' => 1 ), array( 'policy' => array() ) );
		$token = (string) $dirs->state()['token'];
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		$this->session = ImportSession::open( Credentials::from_wordpress() );

		$now = 1800000000;
		$this->sequence( self::store( new WpdbExecutor(), $now ), $a->id, $now, $token );
		$now = 1800000000;
		$this->sequence( self::store( new SessionExecutor( $this->session ), $now ), $b->id, $now, $token );

		$rows = array();
		foreach ( array( $a->id, $b->id ) as $id ) {
			$row = (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::jobs_table() . ' WHERE id = %d', $id ), ARRAY_A );
			unset( $row['id'], $row['created_at'], $row['log_path'] );
			$rows[] = $row;
		}
		$this->assertNotSame( array(), $rows[0], 'the control: the rows are read' );
		$this->assertSame( Job::QUEUED, $rows[0]['status'], 'the control: the sequence ran to its end' );
		$this->assertStringContainsString( '"tables":3', (string) $rows[0]['options_json'], 'the control: the record was written' );
		$this->assertSame( $rows[0], $rows[1], 'column by column' );
		// The one read path: the row read through either executor hydrates to the same job.
		$by_wpdb    = self::store( new WpdbExecutor(), $now )->find( $a->id );
		$by_session = self::store( new SessionExecutor( $this->session ), $now )->find( $a->id );
		$by_wpdb->created_at    = 0;
		$by_session->created_at = 0;
		$this->assertEquals( $by_wpdb, $by_session );
	}

	public function test_an_update_in_the_stores_form_is_the_statement_wpdb_update_sends(): void {
		global $wpdb;
		$repo = new JobRepository( new Directories( array( 'is_web_request' => false ) ) );
		$job  = $repo->create( 'parity', 0 );
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
