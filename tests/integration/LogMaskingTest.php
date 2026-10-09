<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\TextMask;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * Logs are masked when they are written: a job's log and the storage log hold placeholders for the site's host and
 * its paths, never the paths themselves, so nothing raw is on disk to be served by a web server that ignores
 * .htaccess. The masking where a log is shown or downloaded stays as a second line.
 */
final class LogMaskingTest extends JobTestCase {

	private static function host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	public function test_a_jobs_log_holds_placeholders_for_the_host_and_the_paths_and_keeps_the_rest(): void {
		$host = self::host();
		$this->assertNotSame( '', $host, 'the control: the site has a host' );
		$work = '';
		$this->register(
			'masked_log',
			array(
				new ClosureStep(
					'say',
					static function ( JobContext $context ) use ( $host, &$work ): StepResult {
						$work = $context->work_path();
						$context->logger()->info( sprintf( 'Seen from %s at %s, content in %s, work in %s.', $host, ABSPATH . 'wp-config.php', WP_CONTENT_DIR . '/uploads', $work ), array( 'dir' => $work ) );
						return StepResult::done( 'said' );
					}
				),
			)
		);
		$id = $this->job_of( 'masked_log', false );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT, false );
		$job = Plugin::instance()->jobs()->find( $id );
		$log = (string) file_get_contents( Plugin::instance()->directories()->base() . '/' . $job->log_path );
		$this->assertNotSame( '', $work, 'the control: the step ran' );
		// The storage directory's name carries the installation's token, which is redacted before paths are masked.
		$this->assertStringContainsString( 'Seen from {site-host} at {abspath}/wp-config.php, content in {wp-content}/uploads, work in {wp-content}/wp-checkpoint-[redacted]/tmp/job-', $log );
		$this->assertStringContainsString( '"dir":"{wp-content}/wp-checkpoint-[redacted]/tmp/job-', $log, 'the context too' );
		foreach ( array( $host, rtrim( ABSPATH, '/' ), WP_CONTENT_DIR, $work ) as $raw ) {
			$this->assertStringNotContainsString( $raw, $log );
		}
	}

	public function test_the_storage_log_holds_placeholders_for_the_host_and_the_paths(): void {
		$host  = self::host();
		$dirs  = Plugin::instance()->directories();
		$file  = $dirs->base() . '/logs/storage.log';
		$size  = is_file( $file ) ? (int) filesize( $file ) : 0;
		$dirs->log_event( sprintf( 'Checked %s and %s for %s.', ABSPATH . 'wp-config.php', $dirs->base() . '/tmp', $host ) );
		clearstatcache( true, $file );
		$added = (string) file_get_contents( $file, false, null, $size );
		$this->assertStringContainsString( 'Checked {abspath}/wp-config.php and {wp-content}/wp-checkpoint-[redacted]/tmp for {site-host}.', $added, 'the host at the end of the sentence too' );
		foreach ( array( $host, rtrim( ABSPATH, '/' ), $dirs->base() ) as $raw ) {
			$this->assertStringNotContainsString( $raw, $added );
		}
	}

	public function test_a_custom_storage_directory_outside_wp_content_is_masked_in_its_storage_log_too(): void {
		// Outside wp-content, ABSPATH and the run's temporary directory: none of the installation's paths covers it.
		$custom = dirname( dirname( sys_get_temp_dir() ) ) . '/wpc-logmask-' . bin2hex( random_bytes( 4 ) );
		$this->assertSame( $custom, TextMask::for_installation( new Redactor() )->clean( $custom ), 'the control: the installation\'s own paths do not hide it' );
		$this->assertTrue( mkdir( $custom ) );
		$roots = Deleter::replace_roots( array() );
		Deleter::replace_roots( $roots );
		try {
			$dirs = new Directories(
				array(
					'is_web_request' => false,
					'document_root'  => '',
					'custom_dir'     => $custom,
				)
			);
			$this->assertSame( $custom, rtrim( $dirs->base(), '/' ), 'the control: the custom directory is the storage directory' );
			$dirs->log_event( sprintf( 'Using the storage directory %s for backups in %s.', $custom, $custom . '/backups' ) );
			$log = (string) file_get_contents( $custom . '/logs/storage.log' );
			$this->assertStringContainsString( 'Using the storage directory {storage', $log );
			$this->assertStringNotContainsString( $custom, $log, 'nowhere in the storage log, whatever wrote to it' );
		} finally {
			Deleter::allow( $custom );
			Deleter::delete_tree( dirname( $custom ), $custom );
			Deleter::replace_roots( $roots );
		}
		clearstatcache();
		$this->assertDirectoryDoesNotExist( $custom );
	}
}
