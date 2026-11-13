<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The maintenance file and the run without WordPress: the data file names the runtime's hash (a fifth line; the four
 * lines of the earlier form are read as ever, and a refresh keeps the hash), the code runs the runtime only in a request
 * it holds whose data file names one, when the visitor file's time has come and no one else holds its lock, setting
 * that time five seconds on before the runtime opens anything, never in WP-CLI, in its own check, nor twice, and the
 * request still ends with the maintenance page; the take-down adds the runtime log to the job's log first, then takes
 * down .maintenance, the data file, the visitor file, the runtime and the log, in that order, and does not wait on the
 * log. Each request is a process of php-cgi (a request's SAPI, which the test site's image has: on the command line the
 * code runs no runtime); nothing here needs WordPress or the database.
 */
final class MaintenanceWebTest extends TestCase {

	const MARK = 'WP Checkpoint restore 0123456789abcdef0123456789abcdef';

	const OTHER = 'WP Checkpoint restore ffffffffffffffffffffffffffffffff';

	/**
	 * Stand-in for ABSPATH.
	 *
	 * @var string
	 */
	private $dir = '';

	/**
	 * A sandbox for what is not the stand-in's (PHP's log, the request's script).
	 *
	 * @var string
	 */
	private $aux = '';

	protected function set_up(): void {
		parent::set_up();
		$this->dir = Sandbox::make( 'maintenance-web' );
		$this->aux = Sandbox::make( 'maintenance-web-aux' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			@chmod( $this->dir . '/' . Maintenance::LOG, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- made unreadable by a test, or not there.
			Sandbox::remove( $this->dir );
		}
		if ( '' !== $this->aux ) {
			Sandbox::remove( $this->aux );
		}
		parent::tear_down();
	}

	/**
	 * Remove a file of a sandbox when it is there.
	 */
	private static function gone( string $path ): void {
		clearstatcache();
		if ( file_exists( $path ) ) {
			Sandbox::remove( $path );
		}
	}

	private function names(): array {
		$names = array_values( array_diff( (array) scandir( $this->dir ), array( '.', '..' ) ) );
		sort( $names );
		return $names;
	}

	/**
	 * A web file naming a restore.
	 */
	private function web( string $name, string $mark = self::MARK, string $more = '' ): void {
		file_put_contents( $this->dir . '/' . $name, "<?php exit; ?>\n# " . $mark . "\n" . $more );
	}

	public function test_the_data_file_names_the_runtime_and_a_refresh_keeps_it(): void {
		$hash = str_repeat( 'c', 64 );
		( new Maintenance( $this->dir, self::MARK, $hash ) )->put( 1800000000 );
		$this->assertSame( "<?php exit; ?>\n" . self::MARK . "\n1800000000\n-\n" . $hash . "\n", file_get_contents( $this->dir . '/' . Maintenance::DATA ) );
		$this->assertSame( $hash, ( new Maintenance( $this->dir, self::MARK ) )->runtime_hash() );
		// A refresh by an object that names none (another phase of the swap) keeps it.
		( new Maintenance( $this->dir, self::MARK ) )->hold( null, str_repeat( 'd', 64 ) );
		$this->assertSame( "<?php exit; ?>\n" . self::MARK . "\n" . Maintenance::held() . "\n" . str_repeat( 'd', 64 ) . "\n" . $hash . "\n", file_get_contents( $this->dir . '/' . Maintenance::DATA ) );
		$this->assertTrue( ( new Maintenance( $this->dir, self::MARK ) )->is_held() );
		// The four lines of the earlier form: read as ever, and a refresh writes four again.
		file_put_contents( $this->dir . '/' . Maintenance::DATA, "<?php exit; ?>\n" . self::MARK . "\n1800000000\n-\n" );
		$file = new Maintenance( $this->dir, self::MARK );
		$this->assertSame( Maintenance::OURS, $file->state() );
		$this->assertSame( '', $file->runtime_hash() );
		$file->put( 1800000060 );
		$this->assertSame( "<?php exit; ?>\n" . self::MARK . "\n1800000060\n-\n", file_get_contents( $this->dir . '/' . Maintenance::DATA ) );
		// Another restore's data file names nothing of this one's.
		( new Maintenance( $this->dir, self::OTHER, $hash ) )->remove();
		$this->assertSame( '', ( new Maintenance( $this->dir, self::OTHER ) )->runtime_hash() );
	}

	public function test_the_take_down_adds_the_log_first_then_goes_in_order(): void {
		( new Maintenance( $this->dir, self::MARK, str_repeat( 'c', 64 ) ) )->hold();
		$this->web( Maintenance::VISITOR, self::MARK, "{}\n" );
		$this->web( Maintenance::RUNTIME );
		$this->web( Maintenance::LOG, self::MARK, "2026-11-08 10:00:00 Job 7 is not taken on here: one\n2026-11-08 10:00:05 two\n" );
		$seen    = array();
		$merged  = null;
		$confirm = function () use ( &$seen, &$merged ): void {
			$this->assertNotNull( $merged, 'the log is added before anything is taken down' );
			$seen[] = $this->names();
		};
		$this->assertTrue(
			( new Maintenance( $this->dir, self::MARK ) )->remove(
				$confirm,
				function ( array $lines ) use ( &$merged ): void {
					$this->assertFileExists( $this->dir . '/.maintenance', 'nothing taken down yet' );
					$merged = $lines;
				}
			)
		);
		$this->assertSame( array( '2026-11-08 10:00:00 Job 7 is not taken on here: one', '2026-11-08 10:00:05 two' ), $merged );
		$all = array( '.maintenance', '.wpcheckpoint-maintenance.php', '.wpcheckpoint-runtime-log.php', '.wpcheckpoint-runtime.php', '.wpcheckpoint-visitor.php' );
		$this->assertSame(
			array(
				$all,
				array_values( array_diff( $all, array( '.maintenance' ) ) ),
				array( '.wpcheckpoint-runtime-log.php', '.wpcheckpoint-runtime.php', '.wpcheckpoint-visitor.php' ),
				array( '.wpcheckpoint-runtime-log.php', '.wpcheckpoint-runtime.php' ),
				array( '.wpcheckpoint-runtime-log.php' ),
			),
			$seen,
			'what is there right before each deletion: .maintenance, the data file, the visitor file, the runtime, the log'
		);
		$this->assertSame( array(), $this->names() );
	}

	public function test_a_log_that_cannot_be_added_goes_to_phps_log_and_the_take_down_goes_on(): void {
		$php_log = $this->aux . '/php.log';
		$was     = ini_set( 'error_log', $php_log );
		try {
			( new Maintenance( $this->dir, self::MARK ) )->hold();
			$this->web( Maintenance::LOG, self::MARK, "2026-11-08 10:00:00 refused at /x\n" );
			$this->assertTrue(
				( new Maintenance( $this->dir, self::MARK ) )->remove(
					null,
					static function (): void {
						throw new \RuntimeException( 'The job\'s log cannot be written.' );
					}
				)
			);
			$this->assertSame( array(), $this->names(), 'all taken down' );
			$said = (string) file_get_contents( $php_log );
			$this->assertStringContainsString( 'could not be added to the job\'s log', $said );
			$this->assertStringContainsString( '2026-11-08 10:00:00 refused at /x', $said, 'its lines are kept there' );
			// No merge given (a caller without the job's log): the same.
			file_put_contents( $php_log, '' );
			( new Maintenance( $this->dir, self::MARK ) )->hold();
			$this->web( Maintenance::LOG, self::MARK, "2026-11-08 10:00:00 three\n" );
			$this->assertTrue( ( new Maintenance( $this->dir, self::MARK ) )->remove() );
			$this->assertStringContainsString( '2026-11-08 10:00:00 three', (string) file_get_contents( $php_log ) );
			$this->assertSame( array(), $this->names() );
		} finally {
			ini_set( 'error_log', (string) $was );
		}
	}

	public function test_a_log_that_cannot_be_read_is_taken_down_all_the_same(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'root reads any file' );
		}
		if ( 'Windows' === PHP_OS_FAMILY ) {
			$this->markTestSkipped( 'chmod() makes a file read-only on Windows, not unreadable (and a read-only file cannot be deleted there).' );
		}
		( new Maintenance( $this->dir, self::MARK ) )->hold();
		$this->web( Maintenance::LOG, self::MARK, "2026-11-08 10:00:00 four\n" );
		chmod( $this->dir . '/' . Maintenance::LOG, 0 );
		$called = false;
		$this->assertTrue(
			( new Maintenance( $this->dir, self::MARK ) )->remove(
				null,
				static function () use ( &$called ): void {
					$called = true;
				}
			)
		);
		$this->assertFalse( $called, 'nothing read, nothing added' );
		$this->assertSame( array(), $this->names(), 'taken down all the same' );
	}

	public function test_web_files_of_another_restore_stay(): void {
		( new Maintenance( $this->dir, self::MARK ) )->hold();
		$this->web( Maintenance::RUNTIME, self::OTHER );
		$this->web( Maintenance::VISITOR );
		$this->assertTrue( ( new Maintenance( $this->dir, self::MARK ) )->remove() );
		$this->assertSame( array( Maintenance::RUNTIME ), $this->names(), 'this restore\'s visitor file went, the other\'s runtime stays' );
	}

	/**
	 * A runtime that records its call (and the visitor file's time then) instead of running a job.
	 *
	 * @param bool $throws Whether its run throws.
	 */
	private function fake_runtime( bool $throws = false ): void {
		file_put_contents(
			$this->dir . '/' . Maintenance::RUNTIME,
			"<?php\n# " . self::MARK . "\nnamespace WPCheckpoint\\Jobs {\nfinal class WebEntry {\npublic static function run( \$dir, \$mark, \$expected, \$said ) {\nclearstatcache();\nfile_put_contents( \$dir . '/called.json', json_encode( array( \$mark, \$expected, \$said, filemtime( \$dir . '/.wpcheckpoint-visitor.php' ), time(), defined( 'WPCHECKPOINT_WEB_ENTRY' ) ) ) );\n"
			. ( $throws ? "throw new \\RuntimeException( 'the run failed' );\n" : '' )
			. "}\n}\n}\nnamespace {\nreturn 'said-hash';\n}\n"
		);
	}

	/**
	 * Put up a held data file (naming the runtime when $hash is given), CODE, and the visitor file with its time.
	 */
	private function held( string $hash, int $visitor_time ): void {
		file_put_contents( $this->dir . '/' . Maintenance::DATA, ( new Maintenance( $this->dir, self::MARK ) )->data( Maintenance::held(), Maintenance::NO_PASS, $hash ) );
		file_put_contents( $this->dir . '/' . Maintenance::FILE, Maintenance::CODE );
		$this->web( Maintenance::VISITOR, self::MARK, "{}\n" );
		touch( $this->dir . '/' . Maintenance::VISITOR, $visitor_time );
	}

	/**
	 * A request WordPress makes of the maintenance file: in a process of its own, as wp_is_maintenance_mode() requires
	 * it ($upgrading global), with $before run first.
	 *
	 * @param string $before PHP run first.
	 * @param int    $times  How many times it is required.
	 * @param string $scope  PHP run in the scope that requires it, right before.
	 * @return array{upgrading: mixed, type: string, called: array<int, mixed>|null, err: string}
	 */
	private function request( string $before = '', int $times = 1, string $scope = '' ): array {
		self::gone( $this->dir . '/called.json' ); // The last request's, or none.
		$script = $this->aux . '/request.php';
		file_put_contents( $script, '<?php ' . $before . ' $upgrading = null; for ( $i = 0; $i < ' . $times . '; $i++ ) { ( static function () { global $upgrading; ' . $scope . ' require ' . var_export( $this->dir . '/.maintenance', true ) . '; } )(); } echo json_encode( array( $upgrading, gettype( $upgrading ) ) );' );
		$pipes   = array();
		$cgi = dirname( PHP_BINARY ) . '/php-cgi';
		$this->assertTrue( is_executable( $cgi ), 'php-cgi is next to the PHP binary (the test site\'s image has it): a request\'s SAPI is needed' );
		$process = proc_open( array( $cgi, $script ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$out     = (string) stream_get_contents( $pipes[1] );
		$err     = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		proc_close( $process );
		self::gone( $script );
		$parts  = explode( "\r\n\r\n", $out, 2 ); // php-cgi puts its headers first.
		$said   = json_decode( trim( (string) end( $parts ) ), true );
		$called = is_file( $this->dir . '/called.json' ) ? json_decode( (string) file_get_contents( $this->dir . '/called.json' ), true ) : null;
		return array(
			'upgrading' => is_array( $said ) ? $said[0] : null,
			'type'      => is_array( $said ) ? $said[1] : '',
			'called'    => $called,
			'err'       => $err . $out,
		);
	}

	public function test_a_held_request_runs_the_runtime_when_its_time_has_come_and_sets_the_time_on_first(): void {
		$this->fake_runtime();
		$this->held( str_repeat( 'c', 64 ), time() - 60 );
		$before = time();
		$out    = $this->request();
		$this->assertNotNull( $out['called'], 'the runtime ran: ' . $out['err'] );
		$this->assertSame( array( self::MARK, str_repeat( 'c', 64 ), 'said-hash' ), array_slice( $out['called'], 0, 3 ), 'the mark, the hash the data file names, the one the runtime said' );
		$this->assertGreaterThanOrEqual( $before + 5, $out['called'][3], 'the visitor file\'s time was set five seconds on before the runtime ran' );
		$this->assertTrue( $out['called'][5], 'the entry\'s constant was defined first' );
		$this->assertSame( 'double', $out['type'], 'the request still ends with the maintenance page (a float time)' );
		$this->assertSame( (float) Maintenance::held(), (float) $out['upgrading'] );

		// Its time not come (the five seconds just set): it does not run.
		$this->assertNull( $this->request()['called'], 'throttled' );
		// Come again: it runs.
		touch( $this->dir . '/' . Maintenance::VISITOR, time() - 1 );
		$this->assertNotNull( $this->request()['called'] );
	}

	public function test_a_lock_someone_else_holds_on_the_visitor_file_keeps_the_runtime_from_running(): void {
		$this->fake_runtime();
		$this->held( str_repeat( 'c', 64 ), time() - 60 );
		$lock = fopen( $this->dir . '/' . Maintenance::VISITOR, 'r+' );
		$this->assertTrue( flock( $lock, LOCK_EX ) );
		try {
			$out = $this->request();
			$this->assertNull( $out['called'], 'another request holds it: ' . $out['err'] );
			clearstatcache();
			$this->assertLessThan( time(), filemtime( $this->dir . '/' . Maintenance::VISITOR ), 'and its time is as it was' );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		$this->assertNotNull( $this->request()['called'], 'the control: let go, it runs' );
	}

	public function test_the_runtime_does_not_run_without_its_hash_in_wp_cli_in_the_codes_check_twice_or_when_let_through(): void {
		$this->fake_runtime();
		$this->held( str_repeat( 'c', 64 ), time() - 60 );
		$this->assertNull( $this->request( 'define( "WP_CLI", true );' )['called'], 'in WP-CLI' );
		$this->assertNull( $this->request( '', 1, '$wpcheckpoint_check = true;' )['called'], 'in the scope of the code\'s check (Maintenance::check_code())' );
		$this->assertNotNull( $this->request( '$wpcheckpoint_check = true;' )['called'], 'the control: set elsewhere (not where WordPress requires it), it is no check' );
		touch( $this->dir . '/' . Maintenance::VISITOR, time() - 60 ); // The control's run set the time on.
		$twice = $this->request( '', 2 );
		$this->assertNotNull( $twice['called'], 'required twice: it ran once, and the second require did not fail: ' . $twice['err'] );
		$this->assertStringNotContainsString( 'Fatal', $twice['err'] );

		// Let through by the pass: the request goes on, and the runtime does not run.
		$key = str_repeat( 'e', 64 );
		file_put_contents( $this->dir . '/' . Maintenance::DATA, ( new Maintenance( $this->dir, self::MARK ) )->data( Maintenance::held(), hash( 'sha256', $key ), str_repeat( 'c', 64 ) ) );
		touch( $this->dir . '/' . Maintenance::VISITOR, time() - 60 );
		$pass = $this->request( '$_SERVER["HTTP_X_WPCHECKPOINT_PASS"] = "' . $key . '";' );
		$this->assertSame( 0, $pass['upgrading'], 'let through' );
		$this->assertNull( $pass['called'] );

		// A data file of the earlier form (no hash): blocked, the runtime does not run.
		file_put_contents( $this->dir . '/' . Maintenance::DATA, ( new Maintenance( $this->dir, self::MARK ) )->data( Maintenance::held() ) );
		$four = $this->request();
		$this->assertNull( $four['called'] );
		$this->assertSame( 'double', $four['type'] );

		// No visitor file: blocked, nothing runs.
		file_put_contents( $this->dir . '/' . Maintenance::DATA, ( new Maintenance( $this->dir, self::MARK ) )->data( Maintenance::held(), Maintenance::NO_PASS, str_repeat( 'c', 64 ) ) );
		self::gone( $this->dir . '/' . Maintenance::VISITOR );
		$none = $this->request();
		$this->assertNull( $none['called'] );
		$this->assertSame( 'double', $none['type'] );
	}

	public function test_a_runtime_that_fails_or_is_not_there_leaves_the_request_blocked(): void {
		$this->held( str_repeat( 'c', 64 ), time() - 60 );
		$missing = $this->request();
		$this->assertNull( $missing['called'] );
		$this->assertSame( 'double', $missing['type'], $missing['err'] );
		$this->fake_runtime( true );
		touch( $this->dir . '/' . Maintenance::VISITOR, time() - 60 );
		$failed = $this->request();
		$this->assertNotNull( $failed['called'], 'the control: it ran' );
		$this->assertSame( 'double', $failed['type'], 'what it threw went no further: ' . $failed['err'] );
		$this->assertSame( (float) Maintenance::held(), (float) $failed['upgrading'] );
	}

	public function test_the_check_of_the_code_runs_no_runtime(): void {
		$this->fake_runtime();
		$this->held( str_repeat( 'c', 64 ), time() - 60 );
		self::gone( $this->dir . '/' . Maintenance::FILE );
		self::gone( $this->dir . '/called.json' ); // None yet.
		( new Maintenance( $this->dir, self::MARK ) )->hold(); // Writes CODE through check_code(), which includes it.
		$this->assertSame( Maintenance::CODE, file_get_contents( $this->dir . '/' . Maintenance::FILE ) );
		$this->assertFileDoesNotExist( $this->dir . '/called.json', 'the check included the code and ran no runtime' );
		$this->assertNotNull( $this->request()['called'], 'the control: a request runs it' );
	}

	public function test_the_earlier_code_with_a_data_file_of_five_lines_blocks_and_is_replaced_by_the_next_put(): void {
		file_put_contents( $this->dir . '/' . Maintenance::DATA, ( new Maintenance( $this->dir, self::MARK ) )->data( Maintenance::held(), Maintenance::NO_PASS, str_repeat( 'c', 64 ) ) );
		file_put_contents( $this->dir . '/' . Maintenance::FILE, Maintenance::CODE_1 );
		$out = $this->request();
		$this->assertSame( 'double', $out['type'], 'blocked: ' . $out['err'] );
		$this->assertNotSame( (float) Maintenance::held(), (float) $out['upgrading'], 'by its own rule (the data file read as none): the time of the request' );
		$file = new Maintenance( $this->dir, self::MARK );
		$this->assertSame( Maintenance::OURS, $file->state(), 'still this restore\'s' );
		$file->hold();
		$this->assertSame( Maintenance::CODE, file_get_contents( $this->dir . '/' . Maintenance::FILE ), 'the next put replaces the code' );
		$this->assertSame( str_repeat( 'c', 64 ), $file->runtime_hash(), 'and keeps the hash' );
	}
}
