<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\NotHere;
use WPCheckpoint\Jobs\SwapHost;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Restore\Queries;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * After a try of a carry back without WordPress the session's lock waits are put back (SwapStep::put_waits_back()),
 * and a failure to do it is not let out: it would replace the try's own failure (a 1205 made into another error would
 * put the site back without the carry). A NotHere still is.
 */
final class SwapStepWaitsBackTest extends TestCase {

	/**
	 * A connection whose every statement throws $failure (or none), recording what it is sent.
	 *
	 * @param \Throwable|null $failure Failure.
	 * @param string[]        $sent    What was sent (by reference).
	 * @return Queries
	 */
	private static function connection( $failure, array &$sent ): Queries {
		return new class( $failure, $sent ) implements Queries {
			private $failure;
			private $sent;
			public function __construct( $failure, array &$sent ) {
				$this->failure = $failure;
				$this->sent    = &$sent;
			}
			public function run( string $sql ): int {
				$this->sent[] = $sql;
				if ( null !== $this->failure ) {
					throw $this->failure;
				}
				return 0;
			}
			public function rows( string $sql, array $params = array() ): array {
				return array();
			}
			public function write( string $sql, array $params ): int {
				return 0;
			}
		};
	}

	/**
	 * Put the waits back on a connection.
	 *
	 * @param Queries $db   Connection.
	 * @param int     $wait The wait before.
	 * @return void
	 */
	private static function put_back( Queries $db, int $wait ): void {
		$method = new \ReflectionMethod( SwapStep::class, 'put_waits_back' );
		$method->setAccessible( true );
		$method->invoke( new SwapStep( null, array(), SwapHost::without_wordpress( 'wp_', false, 0, 'wp-checkpoint/wp-checkpoint.php' ) ), $db, $wait );
	}

	public function test_the_waits_are_put_back(): void {
		$sent = array();
		self::put_back( self::connection( null, $sent ), 50 );
		$this->assertSame( array( 'SET SESSION innodb_lock_wait_timeout = 50', 'SET SESSION lock_wait_timeout = ' . SwapStep::LOCK_WAIT ), $sent );
	}

	public function test_a_failure_to_put_them_back_is_not_let_out(): void {
		$sent = array();
		self::put_back( self::connection( new \RuntimeException( 'Gone away' ), $sent ), 50 );
		$this->assertNotSame( array(), $sent, 'the control: a statement was tried' );
	}

	public function test_a_not_here_is(): void {
		$sent = array();
		$this->expectException( NotHere::class );
		self::put_back( self::connection( new NotHere( 'Not here.' ), $sent ), 50 );
	}
}
