<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\VisitorFile;
use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The maintenance file's code lets a request through to flush the object cache (Maintenance::CODE), each request a
 * process of its own with nothing of WordPress (maintenance-child.php, by php-cgi: a request's SAPI, which the test
 * site's image has; nothing here needs WordPress or the database): only once a run recorded that the flush is all
 * that is left (VisitorFile::await_flush()), only while the file under the loader's name in the must-use plugins
 * directory is the loader's code, one request for each time the visitor file's time comes, counted and written before
 * it is let through; the first Maintenance::FLUSH_TRIES at that pace, then one every Maintenance::FLUSH_WAIT seconds,
 * each said in the runtime log; and of several requests at once, one. Nothing is let through that the loader would
 * not end: a process on the command line, a request that stops before the must-use plugins (SHORTINIT), a data file
 * that is not a held one, no runtime next to it.
 */
final class FlushPassTest extends TestCase {

	/** @var string */
	private $root = '';

	/** @var string Stand-in for ABSPATH. */
	private $dir = '';

	/** @var string The must-use plugins directory WordPress would default to under it. */
	private $mu = '';

	/** @var string */
	private $mark = '';

	protected function set_up(): void {
		parent::set_up();
		$this->root = Sandbox::make( 'flush-pass' );
		$this->dir  = $this->root . '/site';
		$this->mu   = $this->dir . '/wp-content/mu-plugins';
		mkdir( $this->mu, 0700, true );
		$this->mark = Maintenance::new_mark();
		$hash       = str_repeat( 'a', 64 );
		// The swap's files, the site held: a runtime that only says it was included (no entry class: nothing more runs).
		file_put_contents( $this->dir . '/' . Maintenance::RUNTIME, "<?php\nif ( ! defined( 'WPCHECKPOINT_WEB_ENTRY' ) ) {\n\treturn;\n}\nfile_put_contents( __DIR__ . '/included', 'x', FILE_APPEND );\nreturn '" . $hash . "';\n" );
		file_put_contents( $this->dir . '/' . Maintenance::VISITOR, "<?php exit; ?>\n# " . $this->mark . "\n{\"plugin\":\"wp-checkpoint\\/wp-checkpoint.php\"}\n" );
		file_put_contents( $this->dir . '/' . Maintenance::LOG, "<?php exit; ?>\n# " . $this->mark . "\n" );
		file_put_contents( $this->dir . '/' . Maintenance::DATA, ( new Maintenance( $this->dir, $this->mark ) )->data( Maintenance::held(), Maintenance::NO_PASS, $hash ) );
		file_put_contents( $this->dir . '/' . Maintenance::FILE, Maintenance::CODE );
		$this->due();
	}

	protected function tear_down(): void {
		if ( '' !== $this->root ) {
			Sandbox::remove( $this->root );
		}
		parent::tear_down();
	}

	/**
	 * The visitor file's time has come.
	 *
	 * @return void
	 */
	private function due(): void {
		touch( $this->dir . '/' . Maintenance::VISITOR, time() - 60 );
		clearstatcache();
	}

	/**
	 * php-cgi, next to the PHP binary.
	 *
	 * @return string
	 */
	private function cgi(): string {
		$cgi = dirname( PHP_BINARY ) . '/php-cgi';
		$this->assertTrue( is_executable( $cgi ), 'php-cgi is next to the PHP binary (the test site\'s image has it): a request\'s SAPI is needed' );
		return $cgi;
	}

	/**
	 * What the child printed: the last line of its output (php-cgi puts its headers first).
	 *
	 * @param string $out Its standard output.
	 * @return mixed
	 */
	private static function report( string $out ) {
		$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $out ) ), 'strlen' ) );
		return json_decode( (string) end( $lines ), true );
	}

	/**
	 * The config of a request's process.
	 *
	 * @param array<string, mixed> $more More of it.
	 * @return string Its path.
	 */
	private function config( array $more = array() ): string {
		$path = $this->root . '/child-' . bin2hex( random_bytes( 4 ) ) . '.json';
		file_put_contents( $path, (string) json_encode( array( 'abspath' => $this->dir ) + $more ) );
		return $path;
	}

	/**
	 * One request.
	 *
	 * @param array<string, mixed> $more More of its config ("define").
	 * @param bool                 $cli  Whether it is a process on the command line instead.
	 * @return array<string, mixed> What maintenance-child.php printed.
	 */
	private function request( array $more = array(), bool $cli = false ): array {
		$pipes   = array();
		$process = proc_open( array( $cli ? PHP_BINARY : $this->cgi(), dirname( __DIR__ ) . '/Fixtures/Restore/maintenance-child.php', $this->config( $more ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$out    = (string) stream_get_contents( $pipes[1] );
		$err    = (string) stream_get_contents( $pipes[2] );
		$status = proc_close( $process );
		$said   = "exit {$status}\nstdout:\n{$out}\nstderr:\n{$err}";
		$this->assertSame( 0, $status, $said );
		$report = self::report( $out );
		$this->assertIsArray( $report, $said );
		$this->assertSame( $cli ? 'cli' : 'cgi-fcgi', $report['sapi'], 'the control: the SAPI meant' );
		clearstatcache();
		return $report;
	}

	/**
	 * The visitor file of this restore.
	 *
	 * @return VisitorFile
	 */
	private function visitor(): VisitorFile {
		return new VisitorFile( $this->dir, $this->mark );
	}

	/**
	 * The visitor file's time as it is now.
	 *
	 * @return int
	 */
	private function visitor_time(): int {
		clearstatcache();
		return (int) filemtime( $this->dir . '/' . Maintenance::VISITOR );
	}

	/**
	 * How often the runtime was included.
	 *
	 * @return int
	 */
	private function included(): int {
		clearstatcache();
		return is_file( $this->dir . '/included' ) ? strlen( (string) file_get_contents( $this->dir . '/included' ) ) : 0;
	}

	/**
	 * The lines the code wrote into the runtime log.
	 *
	 * @return string[]
	 */
	private function passes_logged(): array {
		return array_values(
			array_filter(
				Maintenance::log_lines( $this->dir . '/' . Maintenance::LOG ),
				static function ( string $line ): bool {
					return false !== strpos( $line, 'a request was let through to flush it' );
				}
			)
		);
	}

	private function assertLet( array $report, string $message = '' ): void {
		$this->assertSame( 0, $report['upgrading'], $message );
		$this->assertSame( 'integer', $report['type'], $message );
		$this->assertTrue( $report['flush'], 'WPCHECKPOINT_FLUSH_PASS is defined for the loader. ' . $message );
		$this->assertTrue( $report['cron'], 'no cron runs in the request. ' . $message );
		$this->assertFalse( $report['entry'], 'the runtime is the loader\'s to run then. ' . $message );
	}

	private function assertHeld( array $report, string $message = '' ): void {
		$this->assertSame( 'double', $report['type'], $message );
		$this->assertSame( (float) Maintenance::held(), (float) $report['upgrading'], $message ); // JSON prints a whole float as an integer: its type is the line above.
		$this->assertFalse( $report['flush'], $message );
		$this->assertFalse( $report['cron'], $message );
	}

	public function test_without_the_record_a_held_request_runs_the_runtime_and_none_is_let_through(): void {
		Loader::put( $this->mu );
		$this->assertNull( $this->visitor()->flush(), 'the control: no flush is awaited' );
		$report = $this->request();
		$this->assertHeld( $report );
		$this->assertTrue( $report['entry'] );
		$this->assertSame( 1, $this->included(), 'the runtime was included: the put-back is still to do' );
		$this->assertSame( array(), $this->passes_logged() );
	}

	public function test_with_the_record_and_the_loader_one_request_is_let_through_each_time_and_counted_first(): void {
		Loader::put( $this->mu );
		$this->visitor()->await_flush();
		$this->due();
		$this->assertSame( array( 'n' => 0, 'next' => 0 ), $this->visitor()->flush() );
		$before = time();
		$this->assertLet( $this->request() );
		$this->assertSame( array( 'n' => 1, 'next' => 0 ), $this->visitor()->flush(), 'counted in the visitor file' );
		$this->assertCount( 1, $this->passes_logged() );
		$this->assertStringContainsString( '(try 1)', $this->passes_logged()[0] );
		$this->assertSame( 'wp-checkpoint/wp-checkpoint.php', $this->visitor()->plugin(), 'the rest of the visitor file is as it was' );
		$this->assertGreaterThanOrEqual( $before + VisitorFile::THROTTLE_SECONDS, filemtime( $this->dir . '/' . Maintenance::VISITOR ), 'its time set on, after the count was written' );
		// Within that time: held, nothing counted, and no runtime run (nothing is left for it to rename).
		$this->assertHeld( $this->request() );
		$this->assertSame( 1, $this->visitor()->flush()['n'] );
		$this->assertSame( 0, $this->included() );
		// Its time come again: the next one.
		$this->due();
		$this->assertLet( $this->request() );
		$this->assertSame( array( 'n' => 2, 'next' => 0 ), $this->visitor()->flush() );
		$this->assertCount( 2, $this->passes_logged() );
	}

	public function test_after_three_that_did_not_end_it_the_next_waits_five_minutes_and_then_goes(): void {
		$this->assertSame( 3, Maintenance::FLUSH_TRIES );
		$this->assertSame( 300, Maintenance::FLUSH_WAIT );
		Loader::put( $this->mu );
		$this->visitor()->await_flush();
		for ( $i = 1; $i <= Maintenance::FLUSH_TRIES; $i++ ) {
			$this->due();
			$before = time();
			$this->assertLet( $this->request(), 'try ' . $i );
		}
		$record = $this->visitor()->flush();
		$this->assertSame( Maintenance::FLUSH_TRIES, $record['n'] );
		$this->assertGreaterThanOrEqual( $before + Maintenance::FLUSH_WAIT, $record['next'], 'the third sets the wait' );
		$this->assertLessThanOrEqual( time() + Maintenance::FLUSH_WAIT, $record['next'] );
		// The visitor file's time comes, the wait is not over: held, not counted, no runtime run.
		$this->due();
		$this->assertHeld( $this->request() );
		$this->assertSame( $record, $this->visitor()->flush() );
		$this->assertSame( 0, $this->included() );
		// The wait over (the record's time moved back, as five minutes later): let through again, the next wait set.
		$path = $this->dir . '/' . Maintenance::VISITOR;
		file_put_contents( $path, str_replace( '"next":' . $record['next'], '"next":' . ( time() - 1 ), (string) file_get_contents( $path ) ) );
		$this->assertSame( time() - 1, $this->visitor()->flush()['next'], 'the control: the record was moved back' );
		$this->due();
		$before = time();
		$this->assertLet( $this->request() );
		$after = $this->visitor()->flush();
		$this->assertSame( Maintenance::FLUSH_TRIES + 1, $after['n'] );
		$this->assertGreaterThanOrEqual( $before + Maintenance::FLUSH_WAIT, $after['next'] );
		$this->assertCount( Maintenance::FLUSH_TRIES + 1, $this->passes_logged(), 'each one said in the runtime log' );
	}

	/**
	 * What is under the loader's name when nothing is let through.
	 *
	 * @return array<string, array{0: string|null}>
	 */
	public function not_the_loader(): array {
		return array(
			'nothing'                    => array( null ),
			'another file'               => array( "<?php\n// a must-use plugin of the site's under this name\n" ),
			'the code with more in it'   => array( Loader::CODE . "\n" ),
			'the code cut short'         => array( substr( Loader::CODE, 0, -1 ) ),
			'a file longer than is read' => array( Loader::CODE . str_repeat( ' ', Loader::MAX_READ ) ),
		);
	}

	/**
	 * @dataProvider not_the_loader
	 */
	public function test_without_the_loaders_code_under_its_name_nothing_is_let_through( $contents ): void {
		if ( null !== $contents ) {
			file_put_contents( Loader::path( $this->mu ), $contents );
		}
		$this->visitor()->await_flush();
		$this->due();
		$time = $this->visitor_time();
		$this->assertHeld( $this->request() );
		$this->assertSame( array( 'n' => 0, 'next' => 0 ), $this->visitor()->flush(), 'nothing counted' );
		$this->assertSame( array(), $this->passes_logged() );
		$this->assertSame( 0, $this->included(), 'and no runtime run: the site is put back, the flush is WP-CLI\'s' );
		$this->assertSame( $time, $this->visitor_time(), 'nor is the visitor file\'s time set on' );
		// The control: with the loader's code there, the very next request is let through.
		if ( null !== $contents ) {
			Sandbox::remove( Loader::path( $this->mu ) );
		}
		Loader::put( $this->mu );
		$this->assertLet( $this->request() );
		$this->assertGreaterThan( $time, $this->visitor_time(), 'the control: the one let through set the time on' );
	}

	public function test_while_the_records_next_time_has_not_come_nothing_is_let_through_and_the_time_is_not_touched(): void {
		Loader::put( $this->mu );
		$this->visitor()->await_flush();
		$path = $this->dir . '/' . Maintenance::VISITOR;
		$soon = time() + 600;
		file_put_contents( $path, str_replace( '"next":0', '"next":' . $soon, (string) file_get_contents( $path ) ) );
		$this->assertSame( array( 'n' => 0, 'next' => $soon ), $this->visitor()->flush(), 'the control: the record waits' );
		$this->due();
		$time = $this->visitor_time();
		$this->assertHeld( $this->request() );
		$this->assertSame( array( 'n' => 0, 'next' => $soon ), $this->visitor()->flush() );
		$this->assertSame( $time, $this->visitor_time(), 'the visitor file\'s time is not touched' );
		$this->assertSame( 0, $this->included() );
		// The control: its time come (the record's moved back, nothing else), the next request is let through.
		$mtime = $this->visitor_time();
		file_put_contents( $path, str_replace( '"next":' . $soon, '"next":' . ( time() - 1 ), (string) file_get_contents( $path ) ) );
		touch( $path, $mtime );
		$this->assertLet( $this->request() );
	}

	public function test_the_loader_is_looked_for_where_wordpress_loads_must_use_plugins_from(): void {
		$this->visitor()->await_flush();
		$content = $this->root . '/content';
		$custom  = $this->root . '/custom-mu';
		mkdir( $content . '/mu-plugins', 0700, true );
		mkdir( $custom );
		// The default under ABSPATH holds the loader, WP_CONTENT_DIR is elsewhere: not let through.
		Loader::put( $this->mu );
		$this->due();
		$this->assertHeld( $this->request( array( 'define' => array( 'WP_CONTENT_DIR' => $content ) ) ) );
		Loader::put( $content . '/mu-plugins' );
		$this->due();
		$this->assertLet( $this->request( array( 'define' => array( 'WP_CONTENT_DIR' => $content ) ) ) );
		// WPMU_PLUGIN_DIR, when wp-config.php defines it, is the directory.
		$this->due();
		$this->assertHeld( $this->request( array( 'define' => array( 'WP_CONTENT_DIR' => $content, 'WPMU_PLUGIN_DIR' => $custom ) ) ) );
		Loader::put( $custom );
		$this->due();
		$this->assertLet( $this->request( array( 'define' => array( 'WP_CONTENT_DIR' => $content, 'WPMU_PLUGIN_DIR' => $custom ) ) ) );
		$this->assertSame( 2, $this->visitor()->flush()['n'], 'two were let through, two were not' );
	}

	public function test_a_record_of_another_restore_lets_nothing_through(): void {
		Loader::put( $this->mu );
		$other = Maintenance::new_mark();
		file_put_contents( $this->dir . '/' . Maintenance::VISITOR, "<?php exit; ?>\n# " . $other . "\n{\"plugin\":\"wp-checkpoint\\/wp-checkpoint.php\",\"flush\":{\"n\":0,\"next\":0}}\n" );
		$this->due();
		$this->assertHeld( $this->request() );
		$this->assertSame( array( 'n' => 0, 'next' => 0 ), ( new VisitorFile( $this->dir, $other ) )->flush() );
		// The control: the same record under this restore's mark is let through.
		file_put_contents( $this->dir . '/' . Maintenance::VISITOR, "<?php exit; ?>\n# " . $this->mark . "\n{\"plugin\":\"wp-checkpoint\\/wp-checkpoint.php\",\"flush\":{\"n\":0,\"next\":0}}\n" );
		$this->due();
		$this->assertLet( $this->request() );
	}

	public function test_the_record_is_kept_by_the_pace_of_held_up_runs_and_read_with_it(): void {
		Loader::put( $this->mu );
		$visitor = $this->visitor();
		$visitor->held_up( 7, time() - 1000 ); // A pace before the record, and after it.
		$visitor->await_flush();
		$visitor->held_up( 9, time() - 1000 );
		$this->due();
		$this->assertLet( $this->request() );
		$this->assertSame( 1, $visitor->flush()['n'] );
		$visitor->held_up( 7, time() - 1000 );
		$visitor->cleared( 9 );
		$visitor->await_flush();
		$this->assertSame( 1, $visitor->flush()['n'], 'a change of the pace, and awaiting it again, keep the count' );
		$this->due();
		$this->assertLet( $this->request() );
		$this->assertSame( 2, $visitor->flush()['n'] );
	}

	public function test_of_several_requests_at_once_one_is_let_through(): void {
		Loader::put( $this->mu );
		$this->visitor()->await_flush();
		$this->due();
		$gate      = $this->root . '/gate';
		$processes = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$pipes       = array();
			$process     = proc_open( array( $this->cgi(), dirname( __DIR__ ) . '/Fixtures/Restore/maintenance-child.php', $this->config( array( 'gate' => $gate ) ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			$this->assertIsResource( $process );
			$processes[] = array( $process, $pipes );
		}
		usleep( 300000 ); // Every process is up and waits at the gate.
		touch( $gate );
		$let = 0;
		foreach ( $processes as $entry ) {
			$out    = (string) stream_get_contents( $entry[1][1] );
			$err    = (string) stream_get_contents( $entry[1][2] );
			$status = proc_close( $entry[0] );
			$report = self::report( $out );
			$this->assertIsArray( $report, "exit {$status}\n{$out}\n{$err}" );
			if ( 0 === $report['upgrading'] ) {
				++$let;
				$this->assertLet( $report );
			} else {
				$this->assertHeld( $report );
			}
		}
		clearstatcache();
		$this->assertSame( 1, $let, 'one request was let through' );
		$this->assertSame( 1, $this->visitor()->flush()['n'], 'and one counted' );
		$this->assertCount( 1, $this->passes_logged() );
	}

	/**
	 * What the loader would not end, each alone.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function not_ended_by_the_loader(): array {
		return array(
			'a process on the command line (a system cron\'s php wp-cron.php)' => array( 'cli' ),
			'a request that stops before the must-use plugins (SHORTINIT)'    => array( 'shortinit' ),
			'a data file that is not a held one'                             => array( 'not held' ),
			'no runtime next to the maintenance file'                        => array( 'no runtime' ),
		);
	}

	/**
	 * @dataProvider not_ended_by_the_loader
	 */
	public function test_nothing_is_let_through_that_the_loader_would_not_end( string $what ): void {
		Loader::put( $this->mu );
		$this->visitor()->await_flush();
		$data    = (string) file_get_contents( $this->dir . '/' . Maintenance::DATA );
		$runtime = (string) file_get_contents( $this->dir . '/' . Maintenance::RUNTIME );
		$time    = time() + 600;
		if ( 'not held' === $what ) {
			file_put_contents( $this->dir . '/' . Maintenance::DATA, ( new Maintenance( $this->dir, $this->mark ) )->data( $time, Maintenance::NO_PASS, str_repeat( 'a', 64 ) ) );
		} elseif ( 'no runtime' === $what ) {
			Sandbox::remove( $this->dir . '/' . Maintenance::RUNTIME );
		}
		$this->due();
		$time_before = $this->visitor_time();
		$report      = $this->request( 'shortinit' === $what ? array( 'define' => array( 'SHORTINIT' => true ) ) : array(), 'cli' === $what );
		$this->assertSame( 'double', $report['type'], 'not let through: WordPress answers with its maintenance page' );
		$this->assertSame( (float) ( 'not held' === $what ? $time : Maintenance::held() ), (float) $report['upgrading'] );
		$this->assertFalse( $report['flush'] );
		$this->assertFalse( $report['cron'] );
		$this->assertFalse( $report['entry'], 'and no runtime is run: the flush is all that is left' );
		$this->assertSame( array( 'n' => 0, 'next' => 0 ), $this->visitor()->flush(), 'nothing counted: the try is not spent' );
		$this->assertSame( array(), $this->passes_logged() );
		$this->assertSame( $time_before, $this->visitor_time(), 'nor is the visitor file\'s time set on: the pace\'s window is not taken' );
		// The control: put right, the very next request is let through (the visitor file's time is as it was).
		file_put_contents( $this->dir . '/' . Maintenance::DATA, $data );
		file_put_contents( $this->dir . '/' . Maintenance::RUNTIME, $runtime );
		$this->assertSame( $time_before, $this->visitor_time(), 'the control: putting it right did not touch the visitor file' );
		$this->assertLet( $this->request() );
		$this->assertGreaterThan( $time_before, $this->visitor_time(), 'the control: a request let through sets the time on (the observation tells)' );
		$this->assertSame( 1, $this->visitor()->flush()['n'] );
	}

	public function test_while_the_flush_is_awaited_shortinit_requests_and_command_line_processes_take_neither_a_try_nor_the_window(): void {
		Loader::put( $this->mu );
		$this->visitor()->await_flush();
		$this->due();
		$time = $this->visitor_time();
		$this->assertLessThan( time(), $time, 'the control: the visitor file\'s time has come' );
		foreach ( array( 'shortinit', 'cli', 'shortinit', 'cli', 'shortinit' ) as $i => $what ) {
			$report = $this->request( 'shortinit' === $what ? array( 'define' => array( 'SHORTINIT' => true ) ) : array(), 'cli' === $what );
			$this->assertSame( 'double', $report['type'], "#{$i} {$what}: held" );
			$this->assertFalse( $report['flush'], "#{$i} {$what}" );
			$this->assertFalse( $report['entry'], "#{$i} {$what}: no runtime run" );
			$this->assertSame( $time, $this->visitor_time(), "#{$i} {$what}: the visitor file's time is not touched" );
			$this->assertSame( array( 'n' => 0, 'next' => 0 ), $this->visitor()->flush(), "#{$i} {$what}: nothing counted" );
		}
		$this->assertSame( 0, $this->included() );
		// Right after them, with nothing set back: an ordinary request is let through.
		$this->assertLet( $this->request() );
		$this->assertSame( array( 'n' => 1, 'next' => 0 ), $this->visitor()->flush() );
		$this->assertGreaterThan( $time, $this->visitor_time(), 'the control: the one let through set the time on' );
	}

	public function test_without_the_record_a_command_line_process_touches_nothing_and_a_shortinit_request_runs_the_runtime(): void {
		Loader::put( $this->mu );
		$this->assertNull( $this->visitor()->flush(), 'the control: no flush is awaited (the put-back is still to do)' );
		$time   = $this->visitor_time();
		$report = $this->request( array(), true );
		$this->assertHeld( $report );
		$this->assertFalse( $report['entry'], 'a process on the command line runs no runtime' );
		$this->assertSame( 0, $this->included() );
		$this->assertSame( $time, $this->visitor_time(), 'and does not touch the visitor file\'s time' );
		// A SHORTINIT request is a request like any other here: the runtime needs nothing of WordPress.
		$report = $this->request( array( 'define' => array( 'SHORTINIT' => true ) ) );
		$this->assertHeld( $report );
		$this->assertTrue( $report['entry'] );
		$this->assertSame( 1, $this->included(), 'the runtime ran' );
		$this->assertGreaterThan( $time, $this->visitor_time(), 'at the pace: the time set on' );
	}

	/**
	 * What a request WordPress takes all the way looks like, each let through.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function ended_by_the_loader(): array {
		return array(
			'SHORTINIT defined as false (as WordPress defines it for every other request)' => array( 'shortinit false' ),
			'the held time of a 32-bit PHP'                                                => array( 'held 32' ),
		);
	}

	/**
	 * @dataProvider ended_by_the_loader
	 */
	public function test_a_request_the_loader_ends_is_let_through( string $what ): void {
		Loader::put( $this->mu );
		$this->visitor()->await_flush();
		if ( 'held 32' === $what ) {
			$this->assertNotSame( Maintenance::held_for( 4 ), Maintenance::held(), 'the control: not the value the other cases use' );
			file_put_contents( $this->dir . '/' . Maintenance::DATA, ( new Maintenance( $this->dir, $this->mark ) )->data( Maintenance::held_for( 4 ), Maintenance::NO_PASS, str_repeat( 'a', 64 ) ) );
		}
		$this->due();
		$report = $this->request( 'shortinit false' === $what ? array( 'define' => array( 'SHORTINIT' => false ) ) : array() );
		$this->assertSame( 0, $report['upgrading'] );
		$this->assertTrue( $report['flush'] );
		$this->assertSame( 1, $this->visitor()->flush()['n'] );
	}

	public function test_the_check_of_the_code_before_it_is_put_up_lets_nothing_through(): void {
		Loader::put( $this->mu );
		$this->visitor()->await_flush();
		$this->due();
		Maintenance::check_code( $this->dir . '/' . Maintenance::FILE );
		$this->assertSame( array( 'n' => 0, 'next' => 0 ), $this->visitor()->flush() );
		$this->assertFalse( defined( 'WPCHECKPOINT_FLUSH_PASS' ) );
		// The control: a request then is.
		$this->assertLet( $this->request() );
	}
}
