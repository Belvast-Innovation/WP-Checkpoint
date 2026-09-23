<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Admin\LogDownload;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The log a user downloads for support is the log on screen, whole: every
 * line cleaned, never the raw file.
 */
final class LogDownloadTest extends JobTestCase {

	private function download( int $id ): array {
		$headers = array();
		$out     = fopen( 'php://memory', 'w+' );
		$status  = ( new LogDownload( Plugin::instance()->job_actions(), Plugin::instance()->job_presenter() ) )->handle(
			$id,
			static function ( string $line, $code ) use ( &$headers ): void {
				$headers[] = null === $code ? $line : $line . ' [' . $code . ']';
			},
			$out
		);
		rewind( $out );
		return array( $status, $headers, (string) stream_get_contents( $out ) );
	}

	public function test_the_downloaded_log_names_neither_the_site_nor_its_paths(): void {
		$job  = Plugin::instance()->jobs()->create( 'export', self::$admin_id );
		$job  = Plugin::instance()->jobs()->find( $job->id );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$path = $job->storage_path . '/' . $job->log_path;
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, "[t] INFO Tick started marker-one\n[t] ERROR Could not reach https://{$host}/wp-json at " . ABSPATH . "wp-content/x.php marker-two\n" );

		list( $status, $headers, $body ) = $this->download( $job->id );
		$this->assertSame( 200, $status );
		$this->assertContains( 'Content-Disposition: attachment; filename="wp-checkpoint-job-' . $job->id . '.log"', $headers );
		$this->assertStringContainsString( 'marker-one', $body, 'the log is there, line by line' );
		$this->assertStringContainsString( 'marker-two', $body );
		$this->assertStringNotContainsString( $host, $body, 'the site\'s host is masked' );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $body, 'paths are masked' );
		$this->assertStringContainsString( 'https://{site-host}/wp-json at {wp-content}/x.php', $body, 'masked, not dropped' );
	}

	public function test_no_log_for_a_missing_job_and_the_block_links_the_cleaned_download(): void {
		list( $status, , $body ) = $this->download( 999999 );
		$this->assertSame( 404, $status );
		$this->assertSame( 'Not found.', $body );

		$job = Plugin::instance()->jobs()->create( 'export', self::$admin_id );
		ob_start();
		( new \WPCheckpoint\Admin\JobProgress( Plugin::instance()->job_presenter() ) )->render( Plugin::instance()->jobs()->find( $job->id ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'action=' . LogDownload::ACTION, html_entity_decode( $html ) );
		$this->assertStringNotContainsString( 'file=logs', html_entity_decode( $html ), 'never the raw file' );
	}
}
