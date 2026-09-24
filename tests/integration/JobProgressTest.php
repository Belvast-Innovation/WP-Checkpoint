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
use WPCheckpoint\Jobs\WorkLost;

final class JobProgressTest extends JobTestCase {

	private function render( Job $job ): string {
		ob_start();
		( new JobProgress( Plugin::instance()->job_presenter() ) )->render( $job );
		return (string) ob_get_clean();
	}

	public function test_block_is_escaped_masked_and_carries_the_data_attributes(): void {
		$this->register( 'export', array( new ClosureStep( 'files', static function ( JobContext $ctx ): StepResult {
			$ctx->logger()->info( 'reading ' . ABSPATH . "wp-content/<b>x</b> \x1b[31mred\xC2\x9B[2J " . DB_PASSWORD );
			throw new WorkLost( 'cannot read ' . ABSPATH . "<script>alert(1)</script>\u{202E}" );
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
		$this->assertMatchesRegularExpression( '/data-action="retry" hidden>/', $html, 'no retry where it would fail the same way (lost work files)' );
		$this->assertStringContainsString( 'retrying would fail the same way. Create a new backup.', $html );
		$this->assertStringContainsString( '>Failed<', $html );
		$this->assertStringContainsString( 'data-state="failed"', $html );
		// On the screen: what it means and what happened; the step and the exception class only in the log.
		$failure = substr( $html, strpos( $html, 'data-field="failure"' ), strpos( $html, 'data-field="progress_box"' ) - strpos( $html, 'data-field="failure"' ) );
		$this->assertStringContainsString( 'What happened:', $failure );
		$this->assertStringContainsString( 'Cannot read {abspath}/&lt;script&gt;', $failure );
		$this->assertStringNotContainsString( 'WorkLost', $failure );
		$this->assertStringNotContainsString( 'Step &quot;files&quot;', $failure );
		$this->assertStringContainsString( 'WorkLost', substr( $html, strpos( $html, 'data-field="log_tail"' ) ), 'the log keeps the detail' );
	}

	public function test_a_failure_whose_cause_does_not_say_offers_retry(): void {
		$this->register( 'export', array( new ClosureStep( 'files', static function (): StepResult {
			throw new \RuntimeException( 'The volume could not be positioned.' );
		} ) ) );
		$job = Plugin::instance()->jobs()->create( 'export' );
		$this->rest( 'POST', 'jobs/' . $job->id . '/tick' );
		$html = $this->render( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertStringContainsString( 'data-state="failed"', $html );
		$this->assertMatchesRegularExpression( '/data-action="retry">/', $html, 'Retry offered' );
		$this->assertStringContainsString( 'Retry goes on where it stopped; if it fails the same way again, create a new backup.', $html );
		$this->assertStringNotContainsString( 'retrying would fail the same way', $html );
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
