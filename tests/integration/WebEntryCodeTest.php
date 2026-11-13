<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\VisitorFile;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * The whole path of a visitor's request while a swap holds the site, each request a php-cgi process that has only what
 * wp-config.php gives (code-child.php: no WordPress, no autoloader): the maintenance file the swap put up requires the
 * runtime it wrote (WebRuntime, every class the run needs in it), which puts the half-made swap back as WP-CLI would
 * and stops before the object cache's flush, recording that the flush is all that is left; the request ends with the
 * maintenance page. The next request whose time has come is let through by the maintenance file's code as far as the
 * must-use plugins, where the swap's loader runs the runtime with WordPress's flush: the job ends, every file of the
 * swap is taken down, the loader with them, and that request too gets the maintenance page's answer. Without the
 * loader nothing is let through: the site stays held for WP-CLI to flush and end. A runtime that is another build than
 * the one the data file names does nothing but say so; what the runs said is in the job's log once the files are taken
 * down.
 */
final class WebEntryCodeTest extends SwapTestCase {

	/**
	 * What the last request printed (for the failure messages).
	 *
	 * @var string
	 */
	private $last = '';

	/**
	 * How many requests ran the runtime from the maintenance file's code, and how many the loader ended.
	 *
	 * @var array{ran: int, let: int}
	 */
	private $seen = array(
		'ran' => 0,
		'let' => 0,
	);

	/**
	 * Where the stand-in for the object cache's flush writes a byte for each call.
	 *
	 * @return string
	 */
	private function flushed(): string {
		return $this->sandbox . '/flushed';
	}

	/**
	 * While this file is there, the stand-in for the flush throws.
	 *
	 * @return string
	 */
	private function flush_fails(): string {
		return $this->sandbox . '/flush-fails';
	}

	/**
	 * How often the object cache was flushed by a request.
	 *
	 * @return int
	 */
	private function flushes(): int {
		clearstatcache();
		return is_file( $this->flushed() ) ? strlen( (string) file_get_contents( $this->flushed() ) ) : 0;
	}

	/**
	 * One request of the child, by php-cgi (a request's SAPI: the loader does nothing on the command line).
	 *
	 * @param Job                  $job  Job (its type is set to the restore's for the run: the runtime knows no other).
	 * @param array<string, mixed> $more "pass" (the request's X-WPCheckpoint-Pass header), "due" false (the visitor
	 *                                   file's time is left as it is), "site" true (the request may get past the
	 *                                   must-use plugins).
	 * @return array<string, mixed> What the child printed ("let" false), or, when the loader ended the request, "let"
	 *                              true with its "status" and "page".
	 */
	private function request( Job $job, array $more = array() ): array {
		global $table_prefix, $wpdb;
		$this->past_grace( $job->id );
		clearstatcache();
		$due = ! isset( $more['due'] ) || $more['due'];
		unset( $more['due'] );
		if ( $due && file_exists( $this->abspath . '/' . Maintenance::VISITOR ) ) {
			touch( $this->abspath . '/' . Maintenance::VISITOR, time() - 60 ); // Its time has come (the last request's five seconds over).
		}
		$wpdb->update( JobRepository::table(), array( 'type' => RestoreJob::ID ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$config = $this->sandbox . '/code-child-' . bin2hex( random_bytes( 4 ) ) . '.json';
		file_put_contents(
			$config,
			(string) wp_json_encode(
				array(
					'abspath'     => $this->abspath,
					'content'     => $this->dirs['other-content'],
					'db'          => array(
						'name'     => DB_NAME,
						'user'     => DB_USER,
						'password' => DB_PASSWORD,
						'host'     => DB_HOST,
						'prefix'   => $table_prefix,
					),
					'multisite'   => is_multisite(),
					'host'        => 'visitor.example.org',
					'flushed'     => $this->flushed(),
					'flush_fails' => $this->flush_fails(),
				) + array_intersect_key( $more, array( 'pass' => true ) )
			)
		);
		$cgi = dirname( PHP_BINARY ) . '/php-cgi';
		$this->assertTrue( is_executable( $cgi ), 'php-cgi is next to the PHP binary (the test site\'s image has it): a request\'s SAPI is needed' );
		$pipes   = array();
		$process = proc_open( array( $cgi, dirname( __DIR__ ) . '/Fixtures/Restore/code-child.php', $config ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$out    = (string) stream_get_contents( $pipes[1] );
		$err    = (string) stream_get_contents( $pipes[2] );
		$status = proc_close( $process );
		$wpdb->update( JobRepository::table(), array( 'type' => $this->type ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$said       = "exit {$status}\nstdout:\n{$out}\nstderr:\n{$err}";
		$this->last = $said;
		$this->assertSame( 0, $status, $said );
		$this->assertStringNotContainsString( 'CODE-CHILD-FATAL', $out, $said );
		$parts = explode( "\r\n\r\n", $out, 2 );
		$this->assertCount( 2, $parts, 'the answer has its headers: ' . $said );
		list( $headers, $body ) = $parts;
		$lines  = array_values( array_filter( explode( "\n", trim( $body ) ) ) );
		$report = json_decode( (string) end( $lines ), true );
		if ( ! is_array( $report ) ) {
			// No line of the child's: the loader ended the request, with the maintenance page's answer.
			$this->assertSame( 1, preg_match( '/^Status: 503\b/m', $headers ), 'the loader answers 503: ' . $said );
			$this->assertSame( 1, preg_match( '/^Retry-After: 600\r?$/m', $headers ), 'with Retry-After: ' . $said );
			$this->assertStringContainsString( 'Briefly unavailable for scheduled maintenance', $body, $said );
			$this->assertStringNotContainsString( 'visitor.example.org', $out, 'nothing of the site in the answer' );
			$this->assertStringNotContainsString( $this->sandbox, $out, 'no path in the answer' );
			++$this->seen['let'];
			return array(
				'let'  => true,
				'said' => $said,
			);
		}
		$this->assertSame( 'cgi-fcgi', $report['sapi'], 'the control: a request\'s SAPI' );
		$this->assertSame( array(), $report['wordpress'], 'WordPress never loaded: ' . $said );
		$this->assertFalse( $report['autoloader'], 'no autoloader: every class from the runtime' );
		if ( empty( $more['site'] ) ) {
			$this->assertFalse( $report['site'], 'no request gets past the must-use plugins while the site is held: ' . $said );
			$this->assertSame( 'double', $report['type'], 'the request ends with the maintenance page: ' . $said );
		}
		$this->assertSame( '', trim( implode( "\n", array_slice( $lines, 0, -1 ) ) ), 'nothing else is printed: ' . $said );
		if ( $report['entry'] ) {
			++$this->seen['ran'];
		}
		$report['let']  = false;
		$report['said'] = $said;
		return $report;
	}

	/**
	 * Requests until the job ends or stands still (two requests in a row leave its row and the visitor file's record
	 * as they were).
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	private function requests( Job $job ): Job {
		$last = null;
		for ( $i = 0; $i < 40; $i++ ) {
			$this->request( $job );
			$now = Plugin::instance()->jobs()->find( $job->id );
			$row = wp_json_encode( array( $now->status, $now->step, $now->cursor, $now->site_state, ( new VisitorFile( $this->abspath, (string) ( $now->cursor['mark'] ?? '' ) ) )->flush() ) );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) || $row === $last ) {
				return $now;
			}
			$last = $row;
		}
		$this->fail( 'the requests did not end: ' . $this->runtime_log() );
	}

	/**
	 * The runtime log as it is.
	 *
	 * @return string
	 */
	private function runtime_log(): string {
		$path = $this->abspath . '/' . Maintenance::LOG;
		clearstatcache( true, $path ); // The child process may have taken it down since this process last looked.
		$text = is_file( $path ) ? @file_get_contents( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gone meanwhile: said as none.
		return ( is_string( $text ) ? $text : '(no runtime log)' ) . "\nThe last request:\n" . $this->last;
	}

	/**
	 * The files of the swap in the WordPress directory.
	 *
	 * @return string[]
	 */
	private function swap_files(): array {
		return array_values( array_filter( array( Maintenance::FILE, Maintenance::DATA, Maintenance::VISITOR, Maintenance::RUNTIME, Maintenance::LOG ), function ( string $name ): bool {
			clearstatcache( true, $this->abspath . '/' . $name );
			return file_exists( $this->abspath . '/' . $name );
		} ) );
	}

	/**
	 * Where the swap's loader is now: "live" (the must-use plugins directory), "old", "stage" (the plan's places of that
	 * directory).
	 *
	 * @param Job $job Job.
	 * @return string[]
	 */
	private function loader_in( Job $job ): array {
		$out = array();
		clearstatcache();
		foreach ( $this->plan_of( $job )['dirs'] as $entry ) {
			if ( 'mu-plugins' !== basename( $entry['live'] ) ) {
				continue;
			}
			foreach ( array( 'live', 'old', 'stage' ) as $place ) {
				if ( file_exists( Loader::path( $entry[ $place ] ) ) ) {
					$out[] = $place;
				}
			}
		}
		return $out;
	}

	/**
	 * Take the loader away wherever it is: the fallback is not available.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	private function without_loader( Job $job ): void {
		foreach ( $this->plan_of( $job )['dirs'] as $entry ) {
			foreach ( array( 'live', 'old', 'stage' ) as $place ) {
				if ( 'mu-plugins' === basename( $entry['live'] ) && file_exists( Loader::path( $entry[ $place ] ) ) ) {
					Sandbox::remove( Loader::path( $entry[ $place ] ) );
				}
			}
		}
		$this->assertSame( array(), $this->loader_in( $job ) );
	}

	public function test_the_swap_puts_up_the_runtime_and_the_visitor_file_with_the_maintenance_file(): void {
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in' );
		$this->assertSame( array( Maintenance::FILE, Maintenance::DATA, Maintenance::VISITOR, Maintenance::RUNTIME, Maintenance::LOG ), $this->swap_files() );
		$this->assertSame( array(), Maintenance::log_lines( $this->abspath . '/' . Maintenance::LOG ), 'the log is made empty' );
		$now  = Plugin::instance()->jobs()->find( $job->id );
		$file = new Maintenance( $this->abspath, (string) $now->cursor['mark'] );
		$code = (string) file_get_contents( $this->abspath . '/' . Maintenance::RUNTIME );
		$this->assertSame( 1, preg_match( "/\\Anamespace \\{\\nreturn '([0-9a-f]{64})';\\n\\}\\n\\z/m", substr( $code, (int) strrpos( $code, "namespace {\nreturn '" ) ), $m ) );
		$this->assertSame( $m[1], $file->runtime_hash(), 'the data file names the runtime\'s own hash' );
		$this->assertSame( (string) $now->cursor['mark'], Maintenance::web_mark( $this->abspath . '/' . Maintenance::RUNTIME ), 'the runtime names the restore' );
		$this->assertSame( (string) $now->cursor['mark'], Maintenance::web_mark( $this->abspath . '/' . Maintenance::VISITOR ) );
		$this->assertSame( Maintenance::CODE, file_get_contents( $this->abspath . '/' . Maintenance::FILE ) );
		$this->assertSame( array( 'live' ), $this->loader_in( $now ), 'the loader is in the must-use plugins directory (not moved yet)' );
		$this->assertSame( Loader::CODE, file_get_contents( Loader::path( $this->dirs['mu-plugins'] ) ) );
	}

	/**
	 * Where the swap stood when its WP-CLI run was killed, and whether anything was swapped then.
	 *
	 * @return array<string, array{0: string, 1: int, 2: bool}>
	 */
	public function kills(): array {
		return array(
			'dirs'           => array( 'dir_in', 2, false ),
			'all dirs'       => array( 'carried', 1, false ),
			'a commit'       => array( 'committed', 1, false ),
			'nothing swaped' => array( 'maintenance', 1, true ),
		);
	}

	/**
	 * @dataProvider kills
	 */
	public function test_visitors_requests_put_the_swap_back_flush_and_end_it_from_the_files_alone( string $seam, int $nth, bool $nothing ): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, $seam, $nth );
		$killed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertNotSame( array(), $this->loader_in( $killed ), 'the control: the loader is up, in the must-use plugins directory or where the swap moved it' );
		$now = $this->requests( $killed );
		$this->assertSame( Job::FAILED, $now->status, $this->runtime_log() . ' ' . $now->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $now->site_state );
		$this->assertSame( $before, $this->site(), 'the files and the tables as they were, no maintenance file and no loader: ' . $this->runtime_log() );
		$this->assertSame( array(), $this->swap_files(), 'the run took every file down' );
		$this->assertSame( array(), $this->loader_in( $now ), 'the loader is nowhere: not in place, not where the swap moved the directory' );
		$this->assertGreaterThan( 0, $this->seen['ran'], 'the runtime ran from the maintenance file\'s code' );
		if ( $nothing ) {
			$this->assertSame( 0, $this->seen['let'], 'nothing was swapped: no flush, the first run ended it' );
			$this->assertSame( 0, $this->flushes() );
			return;
		}
		$this->assertSame( 1, $this->seen['let'], 'one request was let through, and the loader ended it' );
		$this->assertSame( 1, $this->flushes(), 'the object cache was flushed once, by that request' );
		$log = Plugin::instance()->job_presenter()->log_tail( $now );
		$this->assertStringContainsString( 'object cache cannot be flushed from here', $log, 'what the runs said is in the job\'s log' );
		$this->assertStringContainsString( 'a request was let through to flush it (try 1)', $log );
	}

	public function test_without_the_loader_the_site_is_put_back_and_stays_held_for_wp_cli_to_flush(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		$this->without_loader( Plugin::instance()->jobs()->find( $job->id ) );
		$now  = $this->requests( Plugin::instance()->jobs()->find( $job->id ) );
		$site = $this->site();
		$this->assertTrue( $site['maintenance'], 'held: the flush is WP-CLI\'s' );
		$site['maintenance'] = false;
		$this->assertSame( $before, $site, 'the files and the tables put back by the runtime: ' . $this->runtime_log() );
		$this->assertStringContainsString( 'object cache cannot be flushed from here', $this->runtime_log() );
		$this->assertSame( array( 'n' => 0, 'next' => 0 ), ( new VisitorFile( $this->abspath, (string) $now->cursor['mark'] ) )->flush(), 'the flush is awaited, and no request was let through for it' );
		$this->assertSame( 0, $this->seen['let'] );
		$this->assertSame( 0, $this->flushes() );
		$ran = $this->seen['ran'];
		$this->request( $now );
		$this->assertSame( $ran, $this->seen['ran'], 'and no runtime is run any more: nothing is left for it to rename' );
		$done = $this->cli_run( $now );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site() );
		$this->assertSame( array(), $this->swap_files(), 'WP-CLI took every file down, the runtime log last' );
		$this->assertStringContainsString( 'object cache cannot be flushed from here', Plugin::instance()->job_presenter()->log_tail( $done ), 'what the runs said is in the job\'s log' );
	}

	public function test_a_flush_that_fails_is_tried_three_times_then_every_five_minutes_and_ends_it_once_it_works(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		touch( $this->flush_fails() );
		$now     = $this->requests( Plugin::instance()->jobs()->find( $job->id ) );
		$visitor = new VisitorFile( $this->abspath, (string) $now->cursor['mark'] );
		$record  = $visitor->flush();
		$this->assertSame( Maintenance::FLUSH_TRIES, $record['n'], 'three requests were let through: ' . $this->runtime_log() );
		$this->assertSame( Maintenance::FLUSH_TRIES, $this->seen['let'] );
		$this->assertGreaterThan( time() + Maintenance::FLUSH_WAIT - 60, $record['next'], 'the next waits five minutes' );
		$this->assertSame( 0, $this->flushes(), 'the control: no flush went through' );
		$this->assertContains( $now->status, array( Job::QUEUED, Job::RUNNING ), 'the job is not given up: ' . $now->last_error );
		$site = $this->site();
		$this->assertTrue( $site['maintenance'] );
		$site['maintenance'] = false;
		$this->assertSame( $before, $site, 'the site is put back, held' );
		// The flush works again, the wait is not over: still nothing let through.
		Sandbox::remove( $this->flush_fails() );
		$this->request( $now );
		$this->assertSame( Maintenance::FLUSH_TRIES, $this->seen['let'] );
		$this->assertSame( 0, $this->flushes() );
		// The wait over (the record's time moved back, as five minutes later): the next request ends it.
		$path = $this->abspath . '/' . Maintenance::VISITOR;
		file_put_contents( $path, str_replace( '"next":' . $record['next'], '"next":' . ( time() - 1 ), (string) file_get_contents( $path ) ) );
		$done = $this->requests( $now );
		$this->assertSame( Job::FAILED, $done->status, $this->runtime_log() . ' ' . $done->last_error );
		$this->assertSame( Maintenance::FLUSH_TRIES + 1, $this->seen['let'] );
		$this->assertSame( 1, $this->flushes() );
		$this->assertSame( $before, $this->site() );
		$this->assertSame( array(), $this->swap_files() );
		$this->assertSame( array(), $this->loader_in( $done ) );
		$this->assertStringContainsString( '(try ' . ( Maintenance::FLUSH_TRIES + 1 ) . ')', Plugin::instance()->job_presenter()->log_tail( $done ), 'each one let through is said' );
	}

	public function test_with_the_maintenance_file_deleted_the_loader_holds_the_site_and_visitors_requests_put_it_back(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 ); // Half swapped, the must-use plugins directory (and the loader in it) still in place.
		$killed = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( array( 'live' ), $this->loader_in( $killed ) );
		Sandbox::remove( $this->abspath . '/' . Maintenance::FILE ); // Deleted: by hand, or by an update's end (the upgrader's maintenance_mode( false )).
		$half = $this->site();
		$this->assertNotSame( $before, array( 'maintenance' => false ) + $half, 'the control: the site is half swapped' );
		// Not its time yet: held all the same, and no runtime run.
		touch( $this->abspath . '/' . Maintenance::VISITOR, time() + 100 );
		$report = $this->request( $killed, array( 'due' => false ) );
		$this->assertTrue( $report['let'], 'the loader ended the request with the maintenance page\'s answer: ' . $report['said'] );
		$this->assertSame( array(), Maintenance::log_lines( $this->abspath . '/' . Maintenance::LOG ), 'nothing run within the visitor file\'s time' );
		$this->assertSame( $half, $this->site(), 'nothing renamed' );
		$this->assertNotContains( Maintenance::FILE, $this->swap_files(), 'the control: still no .maintenance' );
		// Its time come: the loader runs the runtime, which takes the job on its held data file (no .maintenance at
		// all) and puts the site back (.maintenance up again before the first rename: WebClaimTest); WordPress is
		// loaded as far as the must-use plugins here, so the same run flushes the object cache and ends the job. The
		// request is held as before.
		$report = $this->request( $killed );
		$this->assertTrue( $report['let'], $report['said'] );
		$this->assertSame( 0, $this->seen['ran'], 'no maintenance file ran anything: both requests were the loader\'s' );
		$this->assertSame( 2, $this->seen['let'] );
		$done = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( Job::FAILED, $done->status, $this->runtime_log() . ' ' . $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( $before, $this->site(), 'the files and the tables as they were: ' . $this->runtime_log() );
		$this->assertSame( 1, $this->flushes(), 'the object cache was flushed once, by the run the loader made' );
		$this->assertSame( array(), $this->swap_files() );
		$this->assertSame( array(), $this->loader_in( $done ) );
		$log = Plugin::instance()->job_presenter()->log_tail( $done );
		$this->assertStringContainsString( 'A visitor\'s request put the site back', (string) $done->last_error );
		$this->assertStringNotContainsString( 'a request was let through to flush it', $log, 'no request had to be let through' );
		// The control: with the swap's files gone, a request gets past the must-use plugins (the site is open).
		$open = $this->request( $done, array( 'site' => true ) );
		$this->assertFalse( $open['let'] );
		$this->assertTrue( $open['site'] );
	}

	/**
	 * Where a run that ends the job is killed, the maintenance file deleted before it: after it let the file go, after
	 * it recorded the site as put back, before each file of the take-down goes (the loader, the data file, the visitor
	 * file, the runtime, the runtime log; the log of the runs without WordPress is added to the job's before the second),
	 * and with all of them down.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public function endings(): array {
		return array(
			'let go'                        => array( 'unheld', 1 ),
			'recorded as put back'          => array( 'reverted', 1 ),
			'before the loader goes'        => array( 'take_down', 1 ),
			'before the data file goes'     => array( 'take_down', 2 ),
			'before the visitor file goes'  => array( 'take_down', 3 ),
			'before the runtime goes'       => array( 'take_down', 4 ),
			'before the runtime log goes'   => array( 'take_down', 5 ),
			'every file down'               => array( 'maintenance_down', 1 ),
			// Someone else's .maintenance in its place while the job ends (an update begun meanwhile), gone after.
			'recorded as put back, an update\'s file in place' => array( 'reverted', 1, true ),
			'before the loader goes, an update\'s file in place' => array( 'take_down', 1, true ),
		);
	}

	/**
	 * @dataProvider endings
	 */
	public function test_with_the_maintenance_file_deleted_a_run_killed_while_it_ends_the_job_leaves_no_site_held_for_good( string $seam, int $nth, bool $foreign = false ): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		// WP-CLI puts everything back and is killed once that is recorded: the site is whole, and still held.
		$this->killed_at( Plugin::instance()->jobs()->find( $job->id ), 'restored' );
		$restored = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( 'restored', $restored->cursor['phase'] ?? '', 'the control: put back, and recorded' );
		$file = new Maintenance( $this->abspath, (string) $restored->site_mark );
		$this->assertTrue( $file->is_held(), 'the control: held' );
		$this->assertContains( 'live', $this->loader_in( $restored ), 'the control: the loader is in the must-use plugins directory' );
		Sandbox::remove( $file->path() ); // An update's end deletes .maintenance, whoever put it up.
		$this->assertTrue( $file->left_held() );
		touch( $this->abspath . '/' . Maintenance::VISITOR, time() + 100 ); // Not its time: the loader answers, and runs nothing.
		$held = $this->request( $restored, array( 'due' => false ) );
		$this->assertTrue( $held['let'], 'the control: the loader answers for the deleted file: ' . $held['said'] );
		$this->assertSame( 'restored', Plugin::instance()->jobs()->find( $job->id )->cursor['phase'] ?? '', 'the control: and no run was made' );
		if ( $foreign ) {
			file_put_contents( $file->path(), '<?php $upgrading = ' . time() . '; ?>' ); // As WordPress's upgrader writes it.
			$this->assertSame( Maintenance::OTHER, $file->state() );
		}
		// The run that ends the job, killed on its way.
		$this->killed_at( $restored, $seam, $nth );
		if ( $foreign ) {
			$this->assertSame( 1, preg_match( '/^<\?php \$upgrading = \d+; \?>$/', (string) file_get_contents( $file->path() ) ), 'the control: the update\'s file was left as it is' );
			Sandbox::remove( $file->path() ); // The update ends.
		}
		$this->assertNotContains( Maintenance::FILE, $this->swap_files(), 'the control: no .maintenance was made meanwhile' );
		// Visitors alone: within a few requests one reaches the site (the loader answers for nothing that no run can
		// end and no time lets lapse).
		$open = null;
		for ( $i = 0; $i < 10 && null === $open; $i++ ) {
			$report = $this->request( Plugin::instance()->jobs()->find( $job->id ), array( 'site' => true ) );
			if ( ! $report['let'] ) {
				$this->assertTrue( $report['site'], $report['said'] );
				$this->assertSame( 'NULL', $report['type'], 'no maintenance file held it: ' . $report['said'] );
				$open = $i;
			}
		}
		$this->assertNotNull( $open, "killed at {$seam} #{$nth}: ten requests later the site is still held: " . $this->runtime_log() . ' files: ' . implode( ', ', $this->swap_files() ) );
		$site                = $this->site();
		$site['maintenance'] = false;
		$this->assertSame( $before, $site, 'the files and the tables as they were' );
		// WP-CLI ends what is left of the job.
		$now  = Plugin::instance()->jobs()->find( $job->id );
		$done = in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ? $this->cli_run( $now ) : $now;
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $done->site_state );
		$this->assertSame( array(), $this->swap_files() );
		$this->assertSame( array(), $this->loader_in( $done ) );
		$this->assertSame( $before, $this->site() );
	}

	/**
	 * The site held when the plugin is deactivated: with its maintenance file, and with that file deleted.
	 *
	 * @return array<string, array{0: bool}>
	 */
	public function held_when_deactivated(): array {
		return array(
			'the maintenance file up'      => array( false ),
			'the maintenance file deleted' => array( true ),
		);
	}

	/**
	 * @dataProvider held_when_deactivated
	 */
	public function test_a_deactivation_while_the_site_is_held_keeps_the_loader_and_visitors_requests_put_the_site_back( bool $deleted ): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'dir_in', 2 );
		$killed = Plugin::instance()->jobs()->find( $job->id );
		$mu     = (string) $this->swap_parts['mu_dir'];
		$this->assertSame( array( 'live' ), $this->loader_in( $killed ), 'the control: the loader is in the must-use plugins directory' );
		if ( $deleted ) {
			Sandbox::remove( $this->abspath . '/' . Maintenance::FILE );
		}
		// What Plugin::deactivate() does, in the sandbox's directories.
		$this->assertFalse( Plugin::remove_loader( $mu, $this->abspath ), 'not removed: a restore holds the site' );
		$this->assertSame( array( 'live' ), $this->loader_in( $killed ), 'the loader stays' );
		$this->assertContains( Maintenance::RUNTIME, $this->swap_files(), 'and so does the runtime' );
		$this->assertContains( Maintenance::DATA, $this->swap_files() );
		// Held as before, and visitors' requests put the site back and end the job.
		$now = $this->requests( $killed );
		$this->assertSame( Job::FAILED, $now->status, $this->runtime_log() . ' ' . $now->last_error );
		$this->assertSame( Job::SITE_UNTOUCHED, $now->site_state );
		$this->assertSame( $before, $this->site(), 'the files and the tables as they were: ' . $this->runtime_log() );
		$this->assertSame( 1, $this->flushes(), 'the object cache flushed once' );
		$this->assertSame( array(), $this->swap_files() );
		$this->assertSame( array(), $this->loader_in( $now ) );
		// The control: with no restore's files left, the same call removes a loader.
		$this->assertSame( '', Loader::put( $mu ) );
		$this->assertTrue( Plugin::remove_loader( $mu, $this->abspath ) );
		$this->assertFileDoesNotExist( Loader::path( $mu ) );
	}

	public function test_the_loader_lets_a_request_with_the_pass_through_and_no_other(): void {
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in', 2 );
		$killed = Plugin::instance()->jobs()->find( $job->id );
		$file   = new Maintenance( $this->abspath, (string) $killed->cursor['mark'] );
		$key    = str_repeat( '0f', 32 );
		// The data file as the swap writes it while its own checks are let through, and no .maintenance.
		file_put_contents( $this->abspath . '/' . Maintenance::DATA, $file->data( Maintenance::held(), hash( 'sha256', $key ), $file->runtime_hash() ) );
		Sandbox::remove( $this->abspath . '/' . Maintenance::FILE );
		touch( $this->abspath . '/' . Maintenance::VISITOR, time() + 100 ); // No run in these requests: only who is held.
		$row = wp_json_encode( Plugin::instance()->jobs()->find( $job->id )->cursor );
		foreach ( array(
			'no header'          => null,
			'another key'        => str_repeat( 'f0', 32 ),
			'the key upper-case' => strtoupper( $key ),
			'the key\'s hash'    => hash( 'sha256', $key ),
			'an empty header'    => '',
		) as $what => $header ) {
			$report = $this->request( $killed, array( 'due' => false ) + ( null === $header ? array() : array( 'pass' => $header ) ) );
			$this->assertTrue( $report['let'], $what . ': held by the loader. ' . $report['said'] );
		}
		$through = $this->request( $killed, array( 'due' => false, 'pass' => $key, 'site' => true ) );
		$this->assertFalse( $through['let'], 'the pass: not held. ' . $through['said'] );
		$this->assertTrue( $through['site'], 'it got past the must-use plugins' );
		$this->assertFalse( $through['entry'], 'and ran no runtime' );
		$this->assertSame( $row, wp_json_encode( Plugin::instance()->jobs()->find( $job->id )->cursor ), 'nothing was run by any of them' );
		// Put the swap back and end it (the test's own tear-down expects no held site).
		$file->hold();
		$this->cli_run( Plugin::instance()->jobs()->find( $job->id ) );
	}

	public function test_visitors_requests_put_back_tables_renamed_in_batches(): void {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', 2 );
		$now = $this->requests( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::FAILED, $now->status, $this->runtime_log() . ' ' . $now->last_error );
		$this->assertSame( $before, $this->site(), $this->runtime_log() );
		$this->assertSame( array(), $this->swap_files() );
		$this->assertSame( 1, $this->flushes() );
	}

	public function test_a_runtime_of_another_build_does_nothing_and_what_it_said_ends_in_the_jobs_log(): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'maintenance' ); // Nothing swapped: a run that goes through ends the job itself.
		$path = $this->abspath . '/' . Maintenance::RUNTIME;
		$code = (string) file_get_contents( $path );
		// Another build of the same classes (the same version, other bytes): its own hash in it, not the data file's.
		$foot  = (int) strrpos( $code, "namespace {\nreturn '" );
		$head  = strpos( $code, "}\n", (int) strpos( $code, "namespace {\nif ( ! defined(" ) ) + 2;
		$body  = substr( $code, $head, $foot - $head ) . "// Another build.\n";
		$other = substr( $code, 0, $head ) . $body . "namespace {\nreturn '" . hash( 'sha256', $body ) . "';\n}\n";
		file_put_contents( $path, $other );
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}
		$row    = wp_json_encode( Plugin::instance()->jobs()->find( $job->id ) );
		$report = $this->request( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertTrue( $report['entry'], 'the control: the runtime ran: ' . $report['said'] );
		$this->assertStringContainsString( 'The runtime here is not the one the restore wrote', $this->runtime_log() );
		$after = Plugin::instance()->jobs()->find( $job->id );
		$after->type = $this->type;
		$this->assertSame( $row, wp_json_encode( $after ), 'nothing written' );
		$this->assertStringNotContainsString( 'visitor.example.org', $this->runtime_log(), 'the request\'s host is masked' );

		// The build the data file names: the run goes through and ends the job (nothing was swapped).
		file_put_contents( $path, $code );
		if ( function_exists( 'opcache_invalidate' ) ) {
			opcache_invalidate( $path, true );
		}
		$now = $this->requests( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::FAILED, $now->status, $this->runtime_log() . ' ' . $now->last_error );
		$this->assertSame( $before, $this->site() );
		$this->assertSame( array(), $this->swap_files(), 'no file left: the runtime log taken down too' );
		$this->assertStringContainsString( 'The runtime here is not the one the restore wrote', Plugin::instance()->job_presenter()->log_tail( $now ), 'the refusal is in the job\'s log' );
	}
}
