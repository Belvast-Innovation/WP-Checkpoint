<?php

namespace WPCheckpoint\Tests\Integration;

use WP_Error;
use WP_UnitTestCase;
use WPCheckpoint\Admin\EnvironmentActions;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\Tabs\ToolsTab;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Environment;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\Protection;

final class ToolsTabTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Options::delete( Directories::OPTION );
		Environment::invalidate();
		add_filter( 'pre_http_request', static function () {
			return new WP_Error( 'blocked', 'no network in tests' );
		} );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
	}

	public function tear_down(): void {
		$state = Directories::load_state();
		if ( '' !== $state['path'] && is_dir( $state['path'] ) ) {
			Deleter::empty_directory( $state['path'] );
			@rmdir( $state['path'] );
		}
		Options::delete( Directories::OPTION );
		Environment::invalidate();
		remove_all_filters( 'pre_http_request' );
		unset( $_GET['tab'], $_GET[ EnvironmentActions::RESULT_PARAM ] );
		parent::tear_down();
	}

	private function render( $directories = null ): string {
		ob_start();
		( new ToolsTab( $directories ) )->render();
		return (string) ob_get_clean();
	}

	public function test_renders_groups_actions_and_escaped_report(): void {
		$html = $this->render();

		foreach ( array( 'WordPress', 'PHP', 'Limits', 'Database', 'Storage', 'Connectivity', 'Server' ) as $group ) {
			$this->assertStringContainsString( '<th colspan="3">' . $group . '</th>', $html );
		}
		$this->assertStringContainsString( 'name="action" value="' . EnvironmentActions::ACTION_RECHECK . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . EnvironmentActions::ACTION_VERIFY . '"', $html );
		$this->assertSame( 2, substr_count( $html, 'name="_wpnonce"' ) );
		preg_match_all( '/name="_wpnonce" value="([^"]+)"/', $html, $m );
		$this->assertNotSame( $m[1][0], $m[1][1], 'each action carries its own nonce' );

		$this->assertStringContainsString( '<textarea id="wpcheckpoint-report"', $html );
		$this->assertStringContainsString( 'WP Checkpoint environment report', $html );
		$this->assertStringNotContainsString( DB_PASSWORD, $html );
		preg_match( '#<textarea id="wpcheckpoint-report"[^>]*>(.*?)</textarea>#s', $html, $m );
		$this->assertNotEmpty( $m, 'report textarea present' );
		$this->assertStringNotContainsString( rtrim( ABSPATH, '/' ) . '/wp-content', $m[1], 'report shows placeholders, not paths' );
		$this->assertStringContainsString( '{wp-content}/wp-checkpoint-', $m[1] );
		$this->assertStringContainsString( 'data-wpcheckpoint-copy="wpcheckpoint-report"', $html );
	}

	public function test_report_textarea_is_escaped(): void {
		add_filter( 'pre_option_blogname', static function () {
			return '</textarea><script>alert(1)</script>';
		} );
		$html = $this->render();
		$this->assertStringNotContainsString( '</textarea><script>', $html );
	}

	public function test_result_flag_renders_notice_and_lock_disables_button(): void {
		$_GET[ EnvironmentActions::RESULT_PARAM ] = 'locked';
		set_site_transient( 'wpcheckpoint_lock_recheck', time() + 30, 30 );
		$html = $this->render();
		$this->assertStringContainsString( 'less than a minute ago', $html );
		$this->assertMatchesRegularExpression( '/<button type="submit" class="button" disabled=\'disabled\'>\s*Re-check/', $html );
		delete_site_transient( 'wpcheckpoint_lock_recheck' );
	}

	public function test_exposed_directory_shows_rule_once_and_notice_is_suppressed_on_tools_tab(): void {
		$dirs = new Directories( array( 'is_web_request' => false, 'document_root' => '' ) );
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static function ( $pre, array $args, string $url ) use ( $dirs ) {
			// A server that serves the probe file verbatim: the directory is exposed.
			$body = (string) file_get_contents( $dirs->base() . '/' . basename( $url ) );
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => $body, 'headers' => array(), 'cookies' => array(), 'filename' => null );
		}, 10, 3 );
		$this->assertSame( Protection::STATUS_EXPOSED, $dirs->verify_protection()['status'] );
		remove_all_filters( 'pre_http_request' );
		add_filter( 'pre_http_request', static function () {
			return new WP_Error( 'blocked', 'no network in tests' );
		} );
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.25';

		$_GET['tab'] = 'tools';
		$html        = $this->render( $dirs );
		$this->assertStringContainsString( 'deny all', $html );
		$this->assertSame( 1, substr_count( $html, 'location ~*' ) );

		set_current_screen( 'toplevel_page_wp-checkpoint' );
		ob_start();
		( new Notices( new Directories( array( 'is_web_request' => false, 'document_root' => '' ) ) ) )->render();
		$notices = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'location ~*', $notices, 'no second copy of the rule on the Tools tab' );

		$_GET['tab'] = 'backups';
		ob_start();
		( new Notices( new Directories( array( 'is_web_request' => false, 'document_root' => '' ) ) ) )->render();
		$notices = (string) ob_get_clean();
		$this->assertStringContainsString( 'location ~*', $notices, 'other tabs still show the banner' );
		unset( $_SERVER['SERVER_SOFTWARE'] );
	}
}
