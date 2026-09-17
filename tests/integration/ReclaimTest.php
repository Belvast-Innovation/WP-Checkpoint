<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\ReclaimActions;
use WPCheckpoint\Admin\Tabs\SettingsTab;
use WPCheckpoint\Admin\Tabs\ToolsTab;
use WPCheckpoint\Support\CloneClassifier;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\OwnerMarker;
use WPCheckpoint\Support\StorageReclaim;

/**
 * Release-directory deployments change ABSPATH; the administrator (or a
 * trusted deployment root) lets the plugin continue with the original
 * storage directory.
 */
final class ReclaimTest extends WP_UnitTestCase {

	/** @var string */
	private $root;

	/** @var int */
	private $admin;

	public function set_up(): void {
		parent::set_up();
		Options::delete( Directories::OPTION );
		$this->root = sys_get_temp_dir() . '/wpcheckpoint-reclaim-' . bin2hex( random_bytes( 4 ) );
		foreach ( array( 'releases/20260917', 'releases/20260918', 'releases/20260919', 'copy/public_html', 'other/wp' ) as $dir ) {
			mkdir( $this->root . '/' . $dir . '/wp-includes', 0755, true );
		}
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
		add_filter( 'pre_http_request', static function () {
			return new \WP_Error( 'blocked', 'no network in tests' );
		} );
	}

	public function tear_down(): void {
		foreach ( glob( WP_CONTENT_DIR . '/wp-checkpoint-*' ) ?: array() as $dir ) {
			Deleter::empty_directory( $dir );
			@rmdir( $dir );
		}
		Deleter::empty_directory( $this->root );
		@rmdir( $this->root );
		Options::delete( Directories::OPTION );
		unset( $_GET[ ReclaimActions::QUERY_FLAG ], $_REQUEST['_wpnonce'] );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	private function site( string $abspath ): Directories {
		return new Directories( array( 'is_web_request' => false, 'document_root' => '', 'abspath' => $this->root . '/' . $abspath . '/' ) );
	}

	/**
	 * First release: adopt a directory, put a backup and a log in it.
	 */
	private function original(): array {
		$dirs = $this->site( 'releases/20260917' );
		$base = $dirs->base();
		$this->assertNotSame( '', $base, $dirs->last_error() );
		file_put_contents( $base . '/backups/site.wpcheckpoint.zip', 'backup-bytes' );
		file_put_contents( $base . '/logs/job-1-abcd.log', "line\n" );
		return array( $dirs, $base );
	}

	private function snapshot( string $dir ): array {
		$entries = array();
		$it      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$entries[ substr( $file->getPathname(), strlen( $dir ) ) ] = $file->isFile() ? md5_file( $file->getPathname() ) : 'dir';
		}
		ksort( $entries );
		return $entries;
	}

	private function token( string $dir ): string {
		return ReclaimActions::expected_token( $dir );
	}

	public function test_new_release_is_classified_as_a_deployment_and_offers_the_take_over(): void {
		list( , $base ) = $this->original();

		$next = $this->site( 'releases/20260918' );
		$this->assertNotSame( $base, $next->base(), 'a new directory is used until the administrator decides' );
		$state = $next->state();
		$this->assertTrue( $state['clone_detected'] );
		$this->assertSame( $base, $state['previous_path'] );
		$this->assertSame( $this->root . '/releases/20260917/', $state['previous_abspath'] );

		$verdict = $next->reclaim()->classify();
		$this->assertSame( CloneClassifier::DEPLOYMENT, $verdict['verdict'] );
		$this->assertSame( CloneClassifier::RECOMMEND_ORIGINAL, $verdict['recommendation'] );
		$this->assertTrue( $verdict['previous_exists'], 'old releases are kept on disk' );

		$notices = ( new Notices( $next ) )->notices();
		$this->assertStringContainsString( 'deployment or a move', $notices['clone_detected']['message'] );
		$this->assertStringContainsString( ReclaimActions::QUERY_FLAG . '=1', $notices['clone_detected']['link'][0] );
		$this->assertSame( 'Keep the new directory', $notices['clone_detected']['dismiss'] );
	}

	public function test_copy_elsewhere_is_classified_as_a_clone(): void {
		list( , $base ) = $this->original();
		$copy = $this->site( 'copy/public_html' );
		$copy->base();
		$this->assertSame( CloneClassifier::CLONE, $copy->reclaim()->classify()['verdict'] );
		$this->assertSame( CloneClassifier::RECOMMEND_NEW, $copy->reclaim()->classify()['recommendation'] );
		$this->assertStringContainsString( 'looks like a copy', ( new Notices( $copy ) )->notices()['clone_detected']['message'] );
		$this->assertNotEmpty( ( new Notices( $copy ) )->notices()['clone_detected']['link'], 'still offered, with the warning' );
		$this->assertDirectoryExists( $base );
	}

	public function test_removed_previous_directory_is_a_move(): void {
		$this->original();
		Deleter::empty_directory( $this->root . '/releases/20260917' );
		rmdir( $this->root . '/releases/20260917' );
		$moved = $this->site( 'other/wp' );
		$moved->base();
		$this->assertSame( CloneClassifier::MOVED, $moved->reclaim()->classify()['verdict'] );
	}

	public function test_confirmed_take_over_rewrites_the_marker_and_restores_the_state(): void {
		list( , $base ) = $this->original();
		$before = $this->snapshot( $base );
		unset( $before['/' . OwnerMarker::FILENAME] );

		$next     = $this->site( 'releases/20260918' );
		$new_base = $next->base();
		$this->assertSame( $before, array_diff_key( $this->snapshot( $base ), array( '/' . OwnerMarker::FILENAME => 1 ) ), 'untouched before confirmation' );

		$result = ( new ReclaimActions( $next ) )->run_reclaim( true, $this->token( $base ), false );
		$this->assertTrue( $result['ok'], $result['message'] );

		$state = $next->state();
		$this->assertSame( $base, $state['path'] );
		$this->assertSame( $base, $next->base() );
		$this->assertFalse( $state['clone_detected'] );
		$this->assertSame( '', $state['previous_path'] );
		$this->assertSame( '', $state['previous_abspath'] );
		$this->assertSame( '', $state['trusted_deploy_root'] );
		$this->assertSame( $this->root . '/releases/20260918/', $state['abspath'] );
		$this->assertTrue( OwnerMarker::matches( (string) file_get_contents( $base . '/' . OwnerMarker::FILENAME ), $state['install_id'], $this->root . '/releases/20260918/' ) );
		$this->assertSame( $before, array_diff_key( $this->snapshot( $base ), array( '/' . OwnerMarker::FILENAME => 1 ) ), 'backups and logs intact' );
		$this->assertDirectoryDoesNotExist( $new_base, 'the empty replacement directory is removed' );
		$this->assertFileDoesNotExist( StorageReclaim::lock_path( $base ) );

		$again = $this->site( 'releases/20260918' );
		$this->assertSame( $base, $again->base() );
		$this->assertFalse( $again->state()['clone_detected'] );
	}

	public function test_take_over_refusals(): void {
		list( , $base ) = $this->original();
		$next = $this->site( 'releases/20260918' );
		$next->base();
		$actions = new ReclaimActions( $next );

		$this->assertFalse( $actions->run_reclaim( false, $this->token( $base ), false )['ok'], 'checkbox required' );
		$this->assertFalse( $actions->run_reclaim( true, 'ffffffffffff', false )['ok'], 'token must match' );
		$this->assertFalse( $actions->run_reclaim( true, '', false )['ok'] );

		file_put_contents( $base . '/tmp/job-7.lock', 'running' );
		$result = $actions->run_reclaim( true, $this->token( $base ), false );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'job', $result['message'] );
		unlink( $base . '/tmp/job-7.lock' );

		file_put_contents( StorageReclaim::lock_path( $base ), "somebody\n" );
		$result = $actions->run_reclaim( true, $this->token( $base ), false );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'right now', $result['message'] );
		touch( StorageReclaim::lock_path( $base ), time() - StorageReclaim::LOCK_TTL - 10 );
		clearstatcache();

		$marker = $base . '/' . OwnerMarker::FILENAME;
		$mine   = (string) file_get_contents( $marker );
		file_put_contents( $marker, OwnerMarker::build( $next->state()['install_id'], $this->root . '/copy/public_html/' ) );
		$result = $actions->run_reclaim( true, $this->token( $base ), false );
		$this->assertFalse( $result['ok'], 'another copy claimed it in between' );
		$this->assertStringContainsString( 'already claimed', $result['message'] );
		file_put_contents( $marker, $mine );

		file_put_contents( $marker, OwnerMarker::build( 'other-install-id', $this->root . '/releases/20260917/' ) );
		$this->assertFalse( $next->reclaim()->prechecks()['ok'], 'foreign install ID fails the prechecks' );
		$this->assertSame( array(), ( new Notices( $next ) )->notices()['clone_detected']['link'], 'no take-over offered for a foreign directory' );
		$result = $actions->run_reclaim( true, $this->token( $base ), false );
		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'another installation', $result['message'] );
		file_put_contents( $marker, $mine );

		$this->assertTrue( $next->state()['clone_detected'], 'state unchanged after refusals' );
		$this->assertTrue( $actions->run_reclaim( true, $this->token( $base ), false )['ok'], 'stale lock is ignored and the original works again' );
	}

	public function test_take_over_aborts_when_the_lock_is_replaced_while_held(): void {
		list( , $base ) = $this->original();
		$next = $this->site( 'releases/20260918' );
		$next->base();
		$reclaim = $next->reclaim();
		$reclaim->on_before_rename( static function ( string $dir ): void {
			file_put_contents( StorageReclaim::lock_path( $dir ), "intruder\n" );
		} );

		$result = $reclaim->reclaim( false );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'lock', $result['message'] );
		$this->assertTrue( OwnerMarker::matches( (string) file_get_contents( $base . '/' . OwnerMarker::FILENAME ), $next->state()['install_id'], $this->root . '/releases/20260917/' ), 'marker untouched' );
		$this->assertSame( "intruder\n", file_get_contents( StorageReclaim::lock_path( $base ) ), 'the other lock is left alone' );
	}

	public function test_action_endpoints_require_the_plugin_capability(): void {
		list( , $base ) = $this->original();
		$next = $this->site( 'releases/20260918' );
		$next->base();
		wp_set_current_user( self::factory()->user->create( array( 'role' => is_multisite() ? 'administrator' : 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = Guard::nonce( ReclaimActions::NONCE_RECLAIM );
		$_POST['wpcheckpoint_confirm'] = '1';
		$_POST['wpcheckpoint_token']   = $this->token( $base );
		try {
			$this->expectException( \WPDieException::class );
			( new ReclaimActions( $next ) )->reclaim();
		} finally {
			unset( $_POST['wpcheckpoint_confirm'], $_POST['wpcheckpoint_token'] );
			$this->assertTrue( $this->site( 'releases/20260918' )->state()['clone_detected'], 'nothing changed' );
		}
	}

	public function test_trusted_root_takes_over_sibling_releases_automatically(): void {
		list( , $base ) = $this->original();
		$next = $this->site( 'releases/20260918' );
		$next->base();
		$result = ( new ReclaimActions( $next ) )->run_reclaim( true, $this->token( $base ), true );
		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( realpath( $this->root . '/releases' ), $next->state()['trusted_deploy_root'] );

		$third = $this->site( 'releases/20260919' );
		$this->assertSame( $base, $third->base(), 'taken over without confirmation' );
		$state = $third->state();
		$this->assertFalse( $state['clone_detected'] );
		$this->assertSame( $this->root . '/releases/20260919/', $state['abspath'] );
		$this->assertSame( $this->root . '/releases/20260918/', $state['auto_reclaimed']['from'] );
		$this->assertTrue( OwnerMarker::matches( (string) file_get_contents( $base . '/' . OwnerMarker::FILENAME ), $state['install_id'], $this->root . '/releases/20260919/' ) );
		$this->assertStringContainsString( 'reclaimed automatically', (string) file_get_contents( $base . '/logs/storage.log' ) );
		$this->assertSame( array(), glob( WP_CONTENT_DIR . '/wp-checkpoint-*' ) ? array_diff( glob( WP_CONTENT_DIR . '/wp-checkpoint-*' ), array( $base ) ) : array(), 'no replacement directory was created' );

		$notices = new Notices( $third );
		$this->assertArrayHasKey( 'auto_reclaimed', $notices->notices() );
		$notices->record_dismissal( 'auto_reclaimed' );
		$this->assertArrayNotHasKey( 'auto_reclaimed', ( new Notices( $this->site( 'releases/20260919' ) ) )->notices() );
	}

	public function test_automatic_take_over_falls_back_to_manual_when_conditions_fail(): void {
		list( , $base ) = $this->original();
		$next = $this->site( 'releases/20260918' );
		$next->base();
		$this->assertTrue( ( new ReclaimActions( $next ) )->run_reclaim( true, $this->token( $base ), true )['ok'] );

		// Outside the trusted root.
		$outside = $this->site( 'other/wp' );
		$this->assertNotSame( $base, $outside->base() );
		$this->assertTrue( $outside->state()['clone_detected'] );
		$this->assertSame( array(), $outside->state()['auto_reclaimed'] );
		$this->assertTrue( ( new ReclaimActions( $outside ) )->run_reclaim( true, $this->token( $base ), false )['ok'], 'manual path still works' );

		// A job is running in the directory.
		file_put_contents( $base . '/tmp/job-3.lock', 'running' );
		$busy = $this->site( 'releases/20260919' );
		$this->assertNotSame( $base, $busy->base() );
		$this->assertTrue( $busy->state()['clone_detected'] );
		unlink( $base . '/tmp/job-3.lock' );
		$this->assertTrue( ( new ReclaimActions( $busy ) )->run_reclaim( true, $this->token( $base ), false )['ok'] );

		// The marker carries another install ID.
		file_put_contents( $base . '/' . OwnerMarker::FILENAME, OwnerMarker::build( 'foreign', $this->root . '/releases/20260919/' ) );
		$foreign = $this->site( 'releases/20260918' );
		$this->assertNotSame( $base, $foreign->base() );
		$this->assertTrue( $foreign->state()['clone_detected'] );
		file_put_contents( $base . '/' . OwnerMarker::FILENAME, OwnerMarker::build( $foreign->state()['install_id'], $this->root . '/releases/20260919/' ) );
		$this->assertTrue( ( new ReclaimActions( $foreign ) )->run_reclaim( true, $this->token( $base ), false )['ok'] );

		// Trust revoked.
		$foreign->untrust_deploy_root();
		$this->assertSame( '', $foreign->state()['trusted_deploy_root'] );
		$revoked = $this->site( 'releases/20260919' );
		$this->assertNotSame( $base, $revoked->base() );
		$this->assertTrue( $revoked->state()['clone_detected'] );
	}

	public function test_tools_tab_shows_the_confirmation_block_and_settings_show_the_trusted_root(): void {
		list( , $base ) = $this->original();
		$next = $this->site( 'releases/20260918' );
		$next->base();

		$_GET[ ReclaimActions::QUERY_FLAG ] = '1';
		ob_start();
		( new ToolsTab( $next ) )->render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Continue with the original storage directory?', $html );
		$this->assertStringContainsString( esc_html( $base ), $html );
		$this->assertStringContainsString( 'name="wpcheckpoint_token"', $html );
		$this->assertStringContainsString( 'name="wpcheckpoint_confirm"', $html );
		$this->assertStringContainsString( 'name="wpcheckpoint_trust_root"', $html );
		$this->assertStringContainsString( 'value="' . ReclaimActions::ACTION_RECLAIM . '"', $html );
		$this->assertStringContainsString( 'Recommended: continue with the original directory.', $html );
		$this->assertMatchesRegularExpression( '/Backups in it<\/th><td>1\s*\(newest/', $html );

		$this->assertTrue( ( new ReclaimActions( $next ) )->run_reclaim( true, $this->token( $base ), true )['ok'] );
		ob_start();
		( new SettingsTab( $next ) )->render();
		$settings = (string) ob_get_clean();
		$this->assertStringContainsString( 'Stop trusting this deployment root', $settings );
		$this->assertStringContainsString( 'value="' . ReclaimActions::ACTION_UNTRUST . '"', $settings );
	}
}
