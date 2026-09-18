<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Admin\JobProgress;
use WPCheckpoint\Admin\Page;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

final class JobProgressTest extends JobTestCase {

	private function render( Job $job ): string {
		ob_start();
		( new JobProgress( Plugin::instance()->job_presenter() ) )->render( $job );
		return (string) ob_get_clean();
	}

	public function test_block_is_escaped_masked_and_carries_the_data_attributes(): void {
		$this->register( 'export', array( new ClosureStep( 'files', static function ( JobContext $ctx ): StepResult {
			$ctx->logger()->info( 'reading ' . ABSPATH . "wp-content/<b>x</b> \x1b[31mred\xC2\x9B[2J " . DB_PASSWORD );
			throw new \RuntimeException( 'cannot read ' . ABSPATH . "<script>alert(1)</script>\u{202E}" );
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'export' );
		$this->rest( 'POST', 'jobs/' . $job->id . '/tick' );
		$job  = Plugin::instance()->jobs()->find( $job->id );
		$html = $this->render( $job );

		$this->assertStringContainsString( 'data-wpcheckpoint-job="' . $job->id . '"', $html );
		$this->assertStringContainsString( 'data-status="failed"', $html );
		$this->assertStringContainsString( '<progress class="wpcheckpoint-job-bar" max="100" value="0"', $html );
		$this->assertStringContainsString( 'data-field="log_tail"', $html );
		$this->assertStringContainsString( '{wp-content}/&lt;b&gt;x&lt;/b&gt;', $html, 'escaped, path masked (longest placeholder wins) with the relative part kept' );
		$this->assertStringContainsString( '{abspath}/&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), $html );
		$this->assertStringNotContainsString( DB_PASSWORD, $html );
		$this->assertStringNotContainsString( "\x1b", $html, 'terminal escape neutralized by the presenter pipeline' );
		$this->assertStringNotContainsString( "\xC2\x9B", $html, '8-bit CSI neutralized' );
		$this->assertStringNotContainsString( "\u{202E}", $html, 'bidi override neutralized' );
		$this->assertStringContainsString( "\u{FFFD}[31mred", $html );
		$this->assertMatchesRegularExpression( '/<button type="button" class="button" data-action="cancel" hidden>/', $html, 'no cancel for a failed job' );
		$this->assertMatchesRegularExpression( '/<button type="button" class="button" data-action="retry">/', $html, 'retry offered' );
		$this->assertStringContainsString( '>Failed<', $html );
	}

	public function test_active_job_offers_cancel_and_the_script_is_enqueued_on_the_plugin_page(): void {
		$this->register( 'plain', array( $this->counting_step( 'p', 3 ) ) );
		$job  = Plugin::instance()->jobs()->create( 'plain' );
		$html = $this->render( $job );
		$this->assertMatchesRegularExpression( '/data-action="cancel">/', $html );
		$this->assertMatchesRegularExpression( '/data-action="retry" hidden>/', $html );
		$this->assertStringContainsString( '>Queued<', $html );

		set_current_screen( 'toplevel_page_' . Page::SLUG );
		Plugin::instance()->enqueue_admin_assets( 'toplevel_page_' . Page::SLUG );
		$this->assertTrue( wp_script_is( 'wpcheckpoint-jobs', 'enqueued' ) );
		$inline = implode( "\n", (array) wp_scripts()->get_data( 'wpcheckpoint-jobs', 'before' ) );
		$this->assertStringContainsString( '"root":"', (string) $inline );
		$this->assertStringContainsString( '"nonce":"', (string) $inline );
		$this->assertStringContainsString( '"loopback":false', (string) $inline );
		$this->assertStringContainsString( 'session_expired', (string) $inline );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ), (string) $inline );
	}
}
