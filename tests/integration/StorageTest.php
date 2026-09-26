<?php

namespace WPCheckpoint\Tests\Integration;

use WP_Error;
use WP_UnitTestCase;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\OwnerMarker;
use WPCheckpoint\Support\Protection;
use WPCheckpoint\Support\Uninstaller;

final class StorageTest extends WP_UnitTestCase {

	/** @var string[] */
	private $cleanup = array();

	/** @var string */
	private $fake_root;

	public function set_up(): void {
		parent::set_up();
		Options::delete( Directories::OPTION );
		$this->fake_root = sys_get_temp_dir() . '/wpcheckpoint-storage-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->fake_root . '/htdocs/wp', 0755, true );
		$this->cleanup[] = $this->fake_root;
	}

	public function tear_down(): void {
		$state = Directories::load_state();
		if ( '' !== $state['path'] && is_dir( $state['path'] ) ) {
			$this->cleanup[] = $state['path'];
		}
		if ( '' !== $state['previous_path'] && is_dir( $state['previous_path'] ) ) {
			$this->cleanup[] = $state['previous_path'];
		}
		foreach ( array_unique( $this->cleanup ) as $dir ) {
			if ( is_dir( $dir ) ) {
				Deleter::empty_directory( $dir );
				@rmdir( $dir );
			}
		}
		foreach ( glob( WP_CONTENT_DIR . '/wp-checkpoint-*' ) ?: array() as $dir ) {
			Deleter::empty_directory( $dir );
			@rmdir( $dir );
		}
		Options::delete( Directories::OPTION );
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'content_url' );
		parent::tear_down();
	}

	private function cli_context( array $extra = array() ): array {
		return array_merge( array( 'is_web_request' => false, 'document_root' => '' ), $extra );
	}

	public function test_cli_context_creates_provisional_directory_under_wp_content(): void {
		$dirs = new Directories( $this->cli_context() );
		$base = $dirs->base();

		$this->assertNotSame( '', $base, $dirs->last_error() );
		$this->assertStringStartsWith( WP_CONTENT_DIR . '/wp-checkpoint-', $base );
		$this->assertMatchesRegularExpression( '/wp-checkpoint-[a-f0-9]{12}$/', $base );
		foreach ( array( 'backups', 'tmp', 'logs' ) as $sub ) {
			$this->assertDirectoryExists( $base . '/' . $sub );
			$this->assertFileExists( $base . '/' . $sub . '/index.php' );
			$this->assertFileExists( $base . '/' . $sub . '/.htaccess' );
		}
		$this->assertFileExists( $base . '/index.php' );
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $base . '/.htaccess' ) );
		$this->assertStringContainsString( 'Deny from all', file_get_contents( $base . '/.htaccess' ) );
		$this->assertFileExists( $base . '/' . OwnerMarker::FILENAME );
		$this->assertSame( array(), glob( $base . '/.probe-*' ) ?: array(), 'probe file removed' );

		$state = $dirs->state();
		$this->assertSame( Directories::SOURCE_CONTENT, $state['source'] );
		$this->assertTrue( $state['provisional'] );
		$this->assertSame( 32, strlen( $state['install_id'] ) );
		$this->assertSame( $base, $state['path'] );

		$again = new Directories( $this->cli_context() );
		$this->assertSame( $base, $again->base(), 'a second instance reuses the stored directory' );
	}

	public function test_web_request_prefers_a_sibling_outside_the_document_root(): void {
		$abspath  = $this->fake_root . '/htdocs/wp/';
		$doc_root = $this->fake_root . '/htdocs/wp';
		$dirs     = new Directories( array( 'abspath' => $abspath, 'document_root' => $doc_root, 'is_web_request' => true ) );
		$base     = $dirs->base();

		$this->assertNotSame( '', $base, $dirs->last_error() );
		$this->assertStringStartsWith( $this->fake_root . '/htdocs/wp-checkpoint-', $base );
		$this->assertSame( Directories::SOURCE_OUTSIDE, $dirs->state()['source'] );
		$this->assertFalse( $dirs->state()['provisional'] );
	}

	public function test_web_request_falls_back_to_wp_content_when_the_parent_is_inside_the_document_root(): void {
		$abspath  = $this->fake_root . '/htdocs/wp/';
		$doc_root = $this->fake_root . '/htdocs';
		$dirs     = new Directories( array( 'abspath' => $abspath, 'document_root' => $doc_root, 'is_web_request' => true ) );
		$base     = $dirs->base();

		$this->assertStringStartsWith( WP_CONTENT_DIR . '/wp-checkpoint-', $base );
		$this->assertSame( Directories::SOURCE_CONTENT, $dirs->state()['source'] );
		$this->assertFalse( $dirs->state()['provisional'], 'a formal choice, even though it fell back' );
	}

	public function test_provisional_choice_migrates_on_the_next_web_request_while_empty(): void {
		$cli  = new Directories( $this->cli_context( array( 'abspath' => $this->fake_root . '/htdocs/wp/' ) ) );
		$old  = $cli->base();
		$this->assertTrue( $cli->state()['provisional'] );

		$web = new Directories( array( 'abspath' => $this->fake_root . '/htdocs/wp/', 'document_root' => $this->fake_root . '/htdocs/wp', 'is_web_request' => true ) );
		$new = $web->base();

		$this->assertNotSame( $old, $new );
		$this->assertStringStartsWith( $this->fake_root . '/htdocs/wp-checkpoint-', $new );
		$this->assertSame( basename( $old ), basename( $new ), 'token is kept' );
		$this->assertDirectoryDoesNotExist( $old );
		$this->assertFalse( $web->state()['provisional'] );
	}

	public function test_provisional_choice_is_not_migrated_while_a_job_is_unfinished(): void {
		global $wpdb;
		$cli = new Directories( $this->cli_context( array( 'abspath' => $this->fake_root . '/htdocs/wp/' ) ) );
		$old = $cli->base();
		$web = array( 'abspath' => $this->fake_root . '/htdocs/wp/', 'document_root' => $this->fake_root . '/htdocs/wp', 'is_web_request' => true );

		// Read from the jobs table: an unfinished job there (an export started from WP-CLI, whose files are not
		// written yet) keeps the directory where it is.
		\WPCheckpoint\Support\Schema::ensure();
		$wpdb->insert( \WPCheckpoint\Support\Schema::jobs_table(), array( 'type' => 'export', 'status' => 'queued', 'created_at' => 1 ) );
		$id = (int) $wpdb->insert_id;
		$this->assertTrue( \WPCheckpoint\Jobs\JobRepository::has_unfinished() );
		$dirs = new Directories( $web );
		$this->assertSame( $old, $dirs->base(), 'not moved while a job is unfinished' );
		$this->assertTrue( $dirs->state()['provisional'], 'the choice waits for the job to end' );
		$this->assertDirectoryExists( $old );

		// Nor when that cannot be read.
		$unknown = new Directories( array_merge( $web, array( 'unfinished' => '__return_null' ) ) );
		$this->assertSame( $old, $unknown->base() );

		// The control: once the job has ended, the same request moves it.
		$wpdb->update( \WPCheckpoint\Support\Schema::jobs_table(), array( 'status' => 'completed' ), array( 'id' => $id ) );
		$this->assertFalse( \WPCheckpoint\Jobs\JobRepository::has_unfinished() );
		$moved = new Directories( $web );
		$this->assertNotSame( $old, $moved->base() );
		$this->assertFalse( $moved->state()['provisional'] );
	}

	public function test_provisional_choice_is_kept_once_user_files_exist(): void {
		$cli = new Directories( $this->cli_context( array( 'abspath' => $this->fake_root . '/htdocs/wp/' ) ) );
		$old = $cli->base();
		file_put_contents( $old . '/backups/site.wpcheckpoint.zip', 'data' );

		$web = new Directories( array( 'abspath' => $this->fake_root . '/htdocs/wp/', 'document_root' => $this->fake_root . '/htdocs/wp', 'is_web_request' => true ) );
		$this->assertSame( $old, $web->base() );
		$this->assertFalse( $web->state()['provisional'] );
		$this->assertFileExists( $old . '/backups/site.wpcheckpoint.zip' );
	}

	public function test_clone_with_same_options_never_writes_into_the_original_directory(): void {
		$original = new Directories( $this->cli_context( array( 'abspath' => '/srv/original/' ) ) );
		$old      = $original->base();
		file_put_contents( $old . '/backups/precious.wpcheckpoint.zip', 'keep' );
		$snapshot = $this->snapshot( $old );

		// Same options (same install ID, same stored path), different ABSPATH.
		$clone = new Directories( $this->cli_context( array( 'abspath' => '/srv/clone/' ) ) );
		$new   = $clone->base();

		$this->assertNotSame( '', $new, $clone->last_error() );
		$this->assertNotSame( $old, $new );
		$this->assertSame( $snapshot, $this->snapshot( $old ), 'original directory untouched' );
		$state = $clone->state();
		$this->assertTrue( $state['clone_detected'] );
		$this->assertSame( $old, $state['previous_path'] );
		$this->assertNotSame( basename( $old ), basename( $new ), 'a new token was chosen' );

		// Uninstall on the clone must refuse to delete the original.
		update_option( Uninstaller::OPTION_DELETE_DATA, true );
		$state['path']  = $old;
		$state['token'] = substr( basename( $old ), strlen( Directories::DIR_PREFIX ) );
		Options::set( Directories::OPTION, $state );
		$result = Uninstaller::delete_storage();
		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( $snapshot, $this->snapshot( $old ), 'clone uninstall left the original alone' );
	}

	public function test_uninstall_deletes_own_directory_but_not_link_targets(): void {
		$dirs = new Directories( $this->cli_context() );
		$base = $dirs->base();
		file_put_contents( $base . '/logs/a.log', 'x' );
		mkdir( $this->fake_root . '/victim' );
		file_put_contents( $this->fake_root . '/victim/keep.txt', 'keep' );
		if ( @symlink( $this->fake_root . '/victim', $base . '/backups/link' ) ) {
			$this->assertFileExists( $base . '/backups/link/keep.txt' );
		}

		$result = Uninstaller::delete_storage();

		$this->assertSame( array(), $result['failed'] );
		$this->assertDirectoryDoesNotExist( $base );
		$this->assertFileExists( $this->fake_root . '/victim/keep.txt' );
	}

	public function test_custom_directory_keeps_the_directory_itself_on_uninstall(): void {
		$custom = $this->fake_root . '/custom-storage';
		mkdir( $custom );
		file_put_contents( $custom . '/user-file.txt', 'mine' );
		$dirs = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
		$this->assertSame( $custom, $dirs->base(), $dirs->last_error() );
		$this->assertSame( Directories::SOURCE_CUSTOM, $dirs->state()['source'] );
		file_put_contents( $custom . '/backups/b.wpcheckpoint.zip', 'x' );

		$result = Uninstaller::delete_storage();

		$this->assertSame( array(), $result['failed'] );
		$this->assertDirectoryExists( $custom );
		$this->assertFileExists( $custom . '/user-file.txt' );
		$this->assertDirectoryDoesNotExist( $custom . '/backups' );
		$this->assertFileDoesNotExist( $custom . '/' . OwnerMarker::FILENAME );
		$this->assertFileDoesNotExist( $custom . '/.htaccess' );
	}

	public function test_custom_directory_gets_a_token_that_is_stable_and_changes_with_the_path(): void {
		$custom = $this->fake_root . '/custom-storage';
		mkdir( $custom );
		$dirs = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
		$this->assertSame( $custom, $dirs->base(), $dirs->last_error() );
		$token = (string) $dirs->state()['token'];
		$this->assertTrue( Directories::is_valid_token( $token ), 'a custom directory carries an installation token like any other' );
		$this->assertStringNotContainsString( $token, $custom, 'the token is not part of the directory name' );
		$this->assertTrue( Directories::is_valid_token( Directories::load_state()['token'] ), 'persisted' );

		$again = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
		$this->assertSame( $custom, $again->base() );
		$this->assertSame( $token, $again->state()['token'], 'stable across requests' );
		$this->assertSame( 'wcptmp' . substr( $token, 0, 6 ) . '_7_beef_posts', TempTables::name( $token, 7, 'beef', 'posts' ), 'temporary tables can be named on this installation' );

		// A different custom path is a different directory choice: new token, as with a new default directory.
		$other = $this->fake_root . '/custom-two';
		mkdir( $other );
		$moved = new Directories( $this->cli_context( array( 'custom_dir' => $other ) ) );
		$this->assertSame( $other, $moved->base(), $moved->last_error() );
		$this->assertNotSame( $token, $moved->state()['token'] );
		$this->assertTrue( Directories::is_valid_token( $moved->state()['token'] ) );
		$this->assertSame( $moved->state()['install_id'], $dirs->state()['install_id'], 'install_id is the installation, the token is the directory choice' );
	}

	public function test_a_custom_directory_installation_without_a_token_is_given_one_on_upgrade(): void {
		global $wpdb;
		$custom = $this->fake_root . '/custom-storage';
		mkdir( $custom );
		$dirs = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
		$this->assertSame( $custom, $dirs->base() );
		// The state a release before this one left behind: the directory adopted, no token.
		$state          = Directories::load_state();
		$state['token'] = '';
		Options::set( Directories::OPTION, $state );

		$upgraded = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
		$this->assertSame( $custom, $upgraded->base(), $upgraded->last_error() );
		$this->assertTrue( Directories::is_valid_token( $upgraded->state()['token'] ) );
		$this->assertSame( $custom, $upgraded->state()['path'] );
	}

	public function test_custom_directory_owned_by_another_site_is_refused(): void {
		$custom = $this->fake_root . '/custom-storage';
		mkdir( $custom );
		file_put_contents( $custom . '/' . OwnerMarker::FILENAME, OwnerMarker::build( 'other-install', '/srv/other/' ) );

		$dirs = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
		$this->assertSame( '', $dirs->base() );
		$this->assertNotSame( '', $dirs->last_error() );
		$this->assertTrue( $dirs->state()['clone_detected'] );
		$this->assertSame( array( '.', '..', OwnerMarker::FILENAME ), scandir( $custom ), 'nothing written' );
	}

	/**
	 * @dataProvider verification_responses
	 */
	public function test_loopback_verification_outcomes( $response, string $expected ): void {
		$dirs = new Directories( $this->cli_context() );
		$base = $dirs->base();
		$seen = array();
		add_filter( 'pre_http_request', static function ( $pre, $args, $url ) use ( $response, &$seen, $base ) {
			$seen[] = $url;
			if ( is_callable( $response ) ) {
				return $response( $url, $base );
			}
			return $response;
		}, 10, 3 );

		$result = $dirs->verify_protection();

		$this->assertSame( $expected, $result['status'] );
		$this->assertCount( 1, $seen );
		$this->assertStringStartsWith( content_url( basename( $base ) . '/probe-' ), $seen[0] );
		$this->assertSame( array(), glob( $base . '/probe-*' ) ?: array(), 'probe file removed' );
		$this->assertSame( $expected, $dirs->state()['verification']['status'] );
	}

	public function verification_responses(): array {
		$http = static function ( int $code, string $body ): array {
			return array( 'response' => array( 'code' => $code, 'message' => '' ), 'body' => $body, 'headers' => array(), 'cookies' => array(), 'filename' => null );
		};
		$echo_probe = static function ( string $url, string $base ) use ( $http ): array {
			return $http( 200, (string) file_get_contents( $base . '/' . basename( $url ) ) );
		};
		return array(
			'403 is protected'                   => array( $http( 403, 'Forbidden' ), Protection::STATUS_PROTECTED ),
			'404 is protected'                   => array( $http( 404, '' ), Protection::STATUS_PROTECTED ),
			'200 with probe content is exposed'  => array( $echo_probe, Protection::STATUS_EXPOSED ),
			'200 with other content unverified'  => array( $http( 200, '<html>catch-all page</html>' ), Protection::STATUS_UNVERIFIED ),
			'500 unverified'                     => array( $http( 500, '' ), Protection::STATUS_UNVERIFIED ),
			'network error unverified'           => array( new WP_Error( 'http_request_failed', 'timeout' ), Protection::STATUS_UNVERIFIED ),
		);
	}

	public function test_apache_in_wp_env_really_blocks_the_directory(): void {
		$host = getenv( 'WPCHECKPOINT_TEST_LOOPBACK_HOST' );
		if ( ! $host ) {
			$this->markTestSkipped( 'Set WPCHECKPOINT_TEST_LOOPBACK_HOST to the tests web server URL to run the real loopback check.' );
		}
		add_filter( 'content_url', static function ( string $url ) use ( $host ): string {
			return preg_replace( '#^https?://[^/]+#', rtrim( $host, '/' ), $url );
		} );
		$dirs   = new Directories( $this->cli_context() );
		$result = $dirs->verify_protection();
		$this->assertSame( Protection::STATUS_PROTECTED, $result['status'], $result['message'] );
		$this->assertSame( 403, $result['code'] );
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
}
