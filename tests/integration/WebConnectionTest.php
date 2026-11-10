<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\WebRun;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A run made without WordPress connects with its own timeouts (WebRun::CONNECT_SECONDS to connect, READ_SECONDS for a
 * read: a visitor waits for them), for the job's storage and the swap's connection alike; a connection it cannot open,
 * or a job it cannot read, is UNAVAILABLE with nothing written and the reason noted; a job is MISSING only when it is
 * positively not there.
 */
final class WebConnectionTest extends SwapTestCase {

	/**
	 * A private property's value.
	 *
	 * @param object $object Object.
	 * @param string $name   Property.
	 * @return mixed
	 */
	private static function prop( $object, string $name ) {
		$property = new \ReflectionProperty( $object, $name );
		$property->setAccessible( true );
		return $property->getValue( $object );
	}

	/**
	 * A run made without WordPress over these settings.
	 *
	 * @param Credentials $credentials Settings.
	 * @param string[]    $notes       What it notes.
	 * @return WebRun
	 */
	private function web_run( Credentials $credentials, array &$notes ): WebRun {
		return new WebRun(
			$credentials,
			$this->abspath,
			is_multisite(),
			plugin_basename( WPCHECKPOINT_FILE ),
			static function ( string $line ) use ( &$notes ): void {
				$notes[] = $line;
			}
		);
	}

	/**
	 * A job's row as stored.
	 *
	 * @param int $id Job id.
	 * @return array<string, string|null>
	 */
	private static function row( int $id ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . JobRepository::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	public function test_the_runs_connections_wait_three_seconds_to_connect_and_ten_for_a_read(): void {
		$notes   = array();
		$run     = $this->web_run( Credentials::from_wordpress(), $notes );
		$session = $run->connection();
		try {
			$this->assertSame(
				array(
					'connect' => 3,
					'read'    => SwapStep::LOCK_WAIT + 5,
				),
				$session->timeouts()
			);
			$this->assertSame( 10, SwapStep::LOCK_WAIT + 5, 'the numbers decided' );
			// The swap's own connection (the carry back's) is made the same way.
			$runner = $run->runner( $run->jobs( $session ) );
			$swap   = self::prop( self::prop( $runner, 'types' )->all()[ RestoreJob::ID ], 'swap' );
			$swap   = $swap instanceof SwapStep ? $swap : self::prop( $swap, 'swap' );
			$own    = call_user_func( self::prop( $swap, 'connect' ) );
			try {
				$this->assertSame( $session->timeouts(), $own->timeouts() );
			} finally {
				$own->close();
			}
			// The control: a connection opened otherwise says its own.
			$other = ImportSession::open( Credentials::from_wordpress() );
			$this->assertSame( array( 'connect' => 10, 'read' => 20 ), $other->timeouts() );
			$other->close();
		} finally {
			$session->close();
		}
	}

	public function test_a_read_longer_than_the_read_timeout_fails_by_it(): void {
		$session = ImportSession::open( Credentials::from_wordpress(), 3, 2 );
		try {
			$this->assertSame( array( array( '0' ) ), $session->rows( 'SELECT SLEEP(1)' ), 'the control: a shorter read is answered' );
			$started = microtime( true );
			$failed  = null;
			try {
				$session->rows( 'SELECT SLEEP(6)' );
			} catch ( \RuntimeException $e ) {
				$failed = $e;
			}
			$took = microtime( true ) - $started;
			$this->assertNotNull( $failed, 'the read timed out' );
			$this->assertLessThan( 5.0, $took, 'by the read timeout (2 s), not the statement\'s end (6 s)' );
		} finally {
			$session->close();
		}
	}

	public function test_a_connection_that_cannot_be_opened_writes_nothing_and_says_why(): void {
		$job    = $this->at_swap();
		$this->killed_at( $job, 'dir_in' );
		$before = self::row( $job->id );
		$wrong  = Credentials::from_values(
			array(
				'name'     => DB_NAME,
				'user'     => DB_USER,
				'password' => 'not-the-password-' . bin2hex( random_bytes( 4 ) ),
				'host'     => DB_HOST,
				'prefix'   => Credentials::from_wordpress()->get( 'prefix' ),
			)
		);
		$notes  = array();
		$result = $this->autocommit(
			function () use ( $wrong, &$notes, $job ): TickResult {
				return $this->web_run( $wrong, $notes )->tick( $job->id, microtime( true ) );
			}
		);
		$this->assertSame( TickResult::UNAVAILABLE, $result->status, $result->message );
		$this->assertStringContainsString( 'could not be opened', implode( ' | ', $notes ) );
		$this->assertStringNotContainsString( 'not-the-password-', implode( ' | ', $notes ) . $result->message, 'no password in what it says' );
		$this->assertSame( $before, self::row( $job->id ), 'nothing written' );
	}

	public function test_a_job_that_cannot_be_read_writes_nothing_and_a_job_not_there_is_missing(): void {
		global $wpdb;
		$job    = $this->at_swap();
		$this->killed_at( $job, 'dir_in' );
		$before = self::row( $job->id );
		$table  = JobRepository::table();
		$aside  = $table . '_aside';
		$notes  = array();
		$run    = $this->web_run( Credentials::from_wordpress(), $notes );
		$wpdb->query( "RENAME TABLE `{$table}` TO `{$aside}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's table.
		try {
			$result = $this->autocommit(
				static function () use ( $run, $job ): TickResult {
					return $run->tick( $job->id, microtime( true ) );
				}
			);
		} finally {
			$wpdb->query( "RENAME TABLE `{$aside}` TO `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- as above.
		}
		$this->assertSame( TickResult::UNAVAILABLE, $result->status, $result->message . ' ' . implode( ' | ', $notes ) );
		$this->assertStringContainsString( 'the job could not be read', implode( ' | ', $notes ) );
		$this->assertSame( $before, self::row( $job->id ), 'nothing written' );

		// The control: a job positively not there is missing.
		$missing = $this->autocommit(
			static function () use ( $run, $job ): TickResult {
				return $run->tick( $job->id + 1000, microtime( true ) );
			}
		);
		$this->assertSame( TickResult::MISSING, $missing->status );
		$this->assertSame( Plugin::instance()->jobs()->find( $job->id )->status, $before['status'] );
	}
}
