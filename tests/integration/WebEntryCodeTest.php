<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * The whole path of a visitor's request while a swap holds the site, in a process that has only what wp-config.php
 * gives (code-child.php: no WordPress, no autoloader): the maintenance file the swap put up requires the runtime it wrote
 * (WebRuntime, every class the run needs in it), which puts the half-made swap back as WP-CLI would (stopping before the
 * object cache's flush, the site held, for WP-CLI to end; or, nothing swapped, ending the job itself), the request ending
 * with the maintenance page. A runtime that is another build than the one the data file names does nothing but say
 * so; what the runs said is in the job's log once the files are taken down, and nothing of them is left.
 */
final class WebEntryCodeTest extends SwapTestCase {

	/**
	 * What the last request printed (for the failure messages).
	 *
	 * @var string
	 */
	private $last = '';

	/**
	 * One request of the child.
	 *
	 * @param Job $job Job (its type is set to the restore's for the run: the runtime knows no other).
	 * @return array<string, mixed>
	 */
	private function request( Job $job ): array {
		global $table_prefix, $wpdb;
		$this->past_grace( $job->id );
		touch( $this->abspath . '/' . Maintenance::VISITOR, time() - 60 ); // Its time has come (the last request's five seconds over).
		$wpdb->update( JobRepository::table(), array( 'type' => RestoreJob::ID ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$config = $this->sandbox . '/code-child-' . bin2hex( random_bytes( 4 ) ) . '.json';
		file_put_contents(
			$config,
			(string) wp_json_encode(
				array(
					'abspath'   => $this->abspath,
					'db'        => array(
						'name'     => DB_NAME,
						'user'     => DB_USER,
						'password' => DB_PASSWORD,
						'host'     => DB_HOST,
						'prefix'   => $table_prefix,
					),
					'multisite' => is_multisite(),
					'host'      => 'visitor.example.org',
				)
			)
		);
		$pipes   = array();
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/Fixtures/Restore/code-child.php', $config ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$out    = (string) stream_get_contents( $pipes[1] );
		$err    = (string) stream_get_contents( $pipes[2] );
		$status = proc_close( $process );
		$wpdb->update( JobRepository::table(), array( 'type' => $this->type ), array( 'id' => $job->id ) );
		$wpdb->query( 'COMMIT' );
		$said = "exit {$status}\nstdout:\n{$out}\nstderr:\n{$err}";
		$this->assertSame( 0, $status, $said );
		$this->assertStringNotContainsString( 'CODE-CHILD-FATAL', $out, $said );
		$lines  = array_values( array_filter( explode( "\n", trim( $out ) ) ) );
		$report = json_decode( (string) end( $lines ), true );
		$this->assertIsArray( $report, $said );
		$this->assertSame( array(), $report['wordpress'], 'WordPress never loaded: ' . $said );
		$this->assertFalse( $report['autoloader'], 'no autoloader: every class from the runtime' );
		$this->assertSame( 'double', $report['type'], 'the request ends with the maintenance page: ' . $said );
		$report['said'] = $said;
		$this->last     = $said;
		return $report;
	}

	/**
	 * Requests until the job ends or stands still (two requests in a row leave its row as it was).
	 *
	 * @param Job $job Job.
	 * @return Job
	 */
	private function requests( Job $job ): Job {
		$last = null;
		for ( $i = 0; $i < 40; $i++ ) {
			$report = $this->request( $job );
			$this->assertTrue( $report['entry'], 'the runtime ran: ' . $report['said'] . "\n" . $this->runtime_log() );
			$now = Plugin::instance()->jobs()->find( $job->id );
			$row = wp_json_encode( array( $now->status, $now->step, $now->cursor, $now->site_state ) );
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
		return ( is_file( $path ) ? (string) file_get_contents( $path ) : '(no runtime log)' ) . "\nThe last request:\n" . $this->last;
	}

	/**
	 * The files of the swap in the WordPress directory.
	 *
	 * @return string[]
	 */
	private function swap_files(): array {
		return array_values( array_filter( array( Maintenance::FILE, Maintenance::DATA, Maintenance::VISITOR, Maintenance::RUNTIME, Maintenance::LOG ), function ( string $name ): bool {
			return file_exists( $this->abspath . '/' . $name );
		} ) );
	}

	public function test_the_swap_puts_up_the_runtime_and_the_visitor_file_with_the_maintenance_file(): void {
		$job = $this->at_swap();
		$this->killed_at( $job, 'dir_in' );
		$this->assertSame( array( Maintenance::FILE, Maintenance::DATA, Maintenance::VISITOR, Maintenance::RUNTIME ), $this->swap_files() );
		$now  = Plugin::instance()->jobs()->find( $job->id );
		$file = new Maintenance( $this->abspath, (string) $now->cursor['mark'] );
		$code = (string) file_get_contents( $this->abspath . '/' . Maintenance::RUNTIME );
		$this->assertSame( 1, preg_match( "/\\Anamespace \\{\\nreturn '([0-9a-f]{64})';\\n\\}\\n\\z/m", substr( $code, (int) strrpos( $code, "namespace {\nreturn '" ) ), $m ) );
		$this->assertSame( $m[1], $file->runtime_hash(), 'the data file names the runtime\'s own hash' );
		$this->assertSame( (string) $now->cursor['mark'], Maintenance::web_mark( $this->abspath . '/' . Maintenance::RUNTIME ), 'the runtime names the restore' );
		$this->assertSame( (string) $now->cursor['mark'], Maintenance::web_mark( $this->abspath . '/' . Maintenance::VISITOR ) );
		$this->assertSame( Maintenance::CODE, file_get_contents( $this->abspath . '/' . Maintenance::FILE ) );
	}

	/**
	 * Where the swap stood when its WP-CLI run was killed, and whether anything was swapped then.
	 *
	 * @return array<string, array{0: string, 1: int, 2: bool}>
	 */
	public function kills(): array {
		return array(
			'dirs'           => array( 'dir_in', 2, false ),
			'carry'          => array( 'carried', 1, false ),
			'a commit'       => array( 'committed', 1, false ),
			'nothing swaped' => array( 'maintenance', 1, true ),
		);
	}

	/**
	 * @dataProvider kills
	 */
	public function test_a_visitors_request_puts_the_swap_back_from_the_runtime_alone( string $seam, int $nth, bool $nothing ): void {
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, $seam, $nth );
		$now = $this->requests( Plugin::instance()->jobs()->find( $job->id ) );
		if ( $nothing ) {
			$this->assertSame( Job::FAILED, $now->status, $this->runtime_log() . ' ' . $now->last_error );
			$this->assertSame( $before, $this->site() );
			$this->assertSame( array(), $this->swap_files(), 'the run took every file down' );
			return;
		}
		$site = $this->site();
		$this->assertTrue( $site['maintenance'], 'held: the flush is WP-CLI\'s' );
		$site['maintenance'] = false;
		$this->assertSame( $before, $site, 'the files and the tables put back by the runtime: ' . $this->runtime_log() );
		$this->assertStringContainsString( 'object cache can only be flushed by WP-CLI', $this->runtime_log() );
		$done = $this->cli_run( $now );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( $before, $this->site() );
		$this->assertSame( array(), $this->swap_files(), 'WP-CLI took every file down, the runtime log last' );
		$this->assertStringContainsString( 'object cache can only be flushed by WP-CLI', Plugin::instance()->job_presenter()->log_tail( $done ), 'what the runs said is in the job\'s log' );
	}

	public function test_a_visitors_request_puts_back_tables_renamed_in_batches(): void {
		$this->swap_parts['batch'] = 1;
		$this->register_type();
		$job    = $this->at_swap();
		$before = $this->site();
		$this->killed_at( $job, 'batch_sent', 2 );
		$now  = $this->requests( Plugin::instance()->jobs()->find( $job->id ) );
		$site = $this->site();
		$this->assertTrue( $site['maintenance'] );
		$site['maintenance'] = false;
		$this->assertSame( $before, $site, $this->runtime_log() );
		$done = $this->cli_run( $now );
		$this->assertSame( Job::FAILED, $done->status, (string) $done->last_error );
		$this->assertSame( array(), $this->swap_files() );
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
