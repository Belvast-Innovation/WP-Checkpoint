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
use WPCheckpoint\Support\StorageLocation;
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
			if ( '' === (string) $dir || '' !== Deleter::refusal( (string) $dir ) ) {
				continue; // Not set, or not one the plugin may delete (what the test made elsewhere it removes itself).
			}
			if ( is_dir( $dir ) ) {
				Deleter::empty_directory( $dir );
				@rmdir( $dir );
			}
		}
		foreach ( glob( WP_CONTENT_DIR . '/wp-checkpoint-*' ) ?: array() as $dir ) {
			if ( '' === (string) $dir || '' !== Deleter::refusal( (string) $dir ) ) {
				continue; // Not set, or not one the plugin may delete (what the test made elsewhere it removes itself).
			}
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
		$copied                = Directories::load_state();
		$copied['past_tokens'] = array( 'aaaaaaaaaaaa' ); // The original's history, copied with the options.
		Options::set( Directories::OPTION, $copied );
		$this->assertContains( 'aaaaaaaaaaaa', Directories::own_tokens(), 'the control: the original\'s own' );
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
		$this->assertSame( array(), $state['past_tokens'], 'the original\'s history is dropped' );
		$this->assertSame( array(), Directories::own_tokens(), 'nothing is claimed while the clone is unresolved: no staging is reaped here' );
		$clone->acknowledge_clone();
		$this->assertSame( array( substr( basename( $new ), strlen( Directories::DIR_PREFIX ) ) ), Directories::own_tokens(), 'once resolved: the clone\'s new token only' );
		$state = $clone->state();

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
		$dirs   = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
		$this->assertSame( $custom, $dirs->base(), $dirs->last_error() );
		$this->assertSame( Directories::SOURCE_CUSTOM, $dirs->state()['source'] );
		file_put_contents( $custom . '/backups/b.wpcheckpoint.zip', 'x' );
		file_put_contents( $custom . '/user-file.txt', 'mine' ); // Put there by the user later.

		$result = Uninstaller::delete_storage();

		$this->assertSame( array(), $result['failed'] );
		$this->assertDirectoryExists( $custom );
		$this->assertFileExists( $custom . '/user-file.txt' );
		$this->assertDirectoryDoesNotExist( $custom . '/backups' );
		$this->assertFileDoesNotExist( $custom . '/' . OwnerMarker::FILENAME );
		$this->assertFileDoesNotExist( $custom . '/.htaccess' );
	}

	public function test_a_custom_directory_that_is_or_holds_a_wordpress_directory_is_refused_before_anything_is_written(): void {
		// A stand-in for ABSPATH (the real directories are asked about in DeleterProtectionTest, without writing).
		$site   = $this->fake_root . '/htdocs/wp';
		$before = Deleter::replace_protected( array( $site ) );
		try {
			foreach ( array( $site, $this->fake_root . '/htdocs' ) as $custom ) {
				$dirs = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
				$this->assertSame( '', $dirs->base(), $custom );
				$this->assertStringContainsString( 'WPCHECKPOINT_STORAGE_DIR names the root of the file system, a WordPress directory', $dirs->last_error() );
				$this->assertSame( array( '.', '..' ), scandir( $site ), 'nothing was written there' );
				$this->assertSame( array( '.', '..', 'wp' ), scandir( $this->fake_root . '/htdocs' ), 'nothing was written there' );
			}
			$custom = $this->fake_root . '/custom-storage';
			$dirs   = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
			$this->assertSame( $custom, $dirs->base(), 'the control: a directory beside it: ' . $dirs->last_error() );
			$this->assertFileExists( $custom . '/' . OwnerMarker::FILENAME );
		} finally {
			Deleter::replace_protected( $before );
		}
	}

	public function test_a_custom_directory_named_by_a_relative_path_is_refused_before_anything_is_written(): void {
		$cwd = (string) getcwd();
		chdir( $this->fake_root ); // Where the relative paths would lead: anything written lands in the sandbox.
		try {
			foreach ( array( 'relative-storage', './relative-storage', $this->fake_root . '/htdocs/../relative-storage' ) as $custom ) {
				$dirs = new Directories( $this->cli_context( array( 'custom_dir' => $custom ) ) );
				$this->assertSame( '', $dirs->base(), $custom );
				$this->assertStringContainsString( 'WPCHECKPOINT_STORAGE_DIR must be an absolute path', $dirs->last_error() );
				$this->assertSame( array( '.', '..', 'htdocs' ), scandir( $this->fake_root ), 'nothing was written' );
			}
			$dirs = new Directories( $this->cli_context( array( 'custom_dir' => $this->fake_root . '/relative-storage' ) ) );
			$this->assertSame( $this->fake_root . '/relative-storage', $dirs->base(), 'the control: the same directory, named in full: ' . $dirs->last_error() );
		} finally {
			chdir( $cwd );
		}
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
		$this->assertSame( array( $moved->state()['token'], $token ), Directories::own_tokens(), 'the earlier token is still this installation\'s: it may name staging next to the site' );
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
		$this->assertSame( $upgraded->state()['token'], Directories::load_state()['token'], 'the new token is saved, not made again on every request' );
	}

	/**
	 * Stand-ins for WordPress's directories in the sandbox (Directories::wordpress_dirs() for the real ones): a
	 * failing rule writes into the sandbox only.
	 *
	 * @return array{within: array<string, string>, itself: array<string, string>}
	 */
	private function stand_ins(): array {
		$site = $this->fake_root . '/htdocs/wp';
		return array(
			'within' => array(
				$site . '/wp-admin'            => 'the wp-admin directory',
				$site . '/wp-content/uploads' => 'the uploads directory',
				$site . '/wp-content/plugins' => 'the plugins directory',
			),
			'itself' => array( $site . '/wp-content' => 'the content directory (wp-content)' ),
		);
	}

	/**
	 * A directory's files and their contents.
	 *
	 * @return array<string, string>
	 */
	private static function files_of( string $dir ): array {
		$out = array();
		foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $entry ) {
			$out[ (string) $entry ] = is_file( $dir . '/' . $entry ) ? (string) file_get_contents( $dir . '/' . $entry ) : '(directory)';
		}
		return $out;
	}

	private function custom( string $dir ): Directories {
		return new Directories( $this->cli_context( array( 'custom_dir' => $dir, 'wordpress_dirs' => $this->stand_ins() ) ) );
	}

	public function test_a_wordpress_directory_is_refused_and_its_files_stay_as_they_were(): void {
		$site = $this->fake_root . '/htdocs/wp';
		mkdir( $site . '/wp-admin' );
		file_put_contents( $site . '/wp-admin/index.php', "<?php // The dashboard.\n" );
		file_put_contents( $site . '/wp-admin/.htaccess', "# The site's own rules.\n" );
		mkdir( $site . '/wp-content/uploads', 0755, true );
		file_put_contents( $site . '/wp-content/uploads/index.php', "<?php // Silence is golden.\n" );
		file_put_contents( $site . '/wp-content/uploads/.htaccess', "# Media rules.\n" );
		foreach ( array(
			$site . '/wp-admin'                        => 'names the wp-admin directory',
			$site . '/wp-content/uploads'             => 'names the uploads directory',
			$site . '/wp-content/uploads/2026/store'  => 'names the uploads directory',
			$site . '/wp-content'                      => 'names the content directory (wp-content)',
		) as $custom => $message ) {
			$before = array( self::files_of( $site . '/wp-admin' ), self::files_of( $site . '/wp-content/uploads' ), self::files_of( $site . '/wp-content' ) );
			$dirs   = $this->custom( $custom );
			$this->assertSame( '', $dirs->base(), $custom );
			$this->assertStringContainsString( 'WPCHECKPOINT_STORAGE_DIR ' . $message, $dirs->last_error() );
			$this->assertSame( $before, array( self::files_of( $site . '/wp-admin' ), self::files_of( $site . '/wp-content/uploads' ), self::files_of( $site . '/wp-content' ) ), 'nothing written, nothing changed' );
		}
		$this->assertSame( "<?php // The dashboard.\n", file_get_contents( $site . '/wp-admin/index.php' ) );
		$this->assertSame( "# Media rules.\n", file_get_contents( $site . '/wp-content/uploads/.htaccess' ) );
		$this->assertDirectoryDoesNotExist( $site . '/wp-content/uploads/2026' );

		// The control: a directory of wp-content that is none of them.
		$dirs = $this->custom( $site . '/wp-content/wpc-store' );
		$this->assertSame( $site . '/wp-content/wpc-store', $dirs->base(), $dirs->last_error() );
	}

	public function test_the_empty_uploads_of_a_new_site_is_refused(): void {
		$uploads = $this->fake_root . '/htdocs/wp/wp-content/uploads';
		mkdir( $uploads, 0755, true );
		$dirs = $this->custom( $uploads );
		$this->assertSame( '', $dirs->base() );
		$this->assertStringContainsString( 'names the uploads directory', $dirs->last_error() );
		$this->assertSame( array( '.', '..' ), scandir( $uploads ), 'still empty' );
	}

	public function test_an_empty_directory_of_wp_content_is_taken_and_marked(): void {
		$store = $this->fake_root . '/htdocs/wp/wp-content/wpc-store';
		mkdir( $store, 0755, true );
		$this->assertSame( array( '.', '..' ), scandir( $store ) );
		$dirs = $this->custom( $store );
		$this->assertSame( $store, $dirs->base(), $dirs->last_error() );
		$this->assertFileExists( $store . '/' . OwnerMarker::FILENAME );
		$this->assertDirectoryExists( $store . '/backups' );

		// Used again, with what is in it now.
		file_put_contents( $store . '/backups/b.wpcheckpoint.zip', 'x' );
		$again = $this->custom( $store );
		$this->assertSame( $store, $again->base(), 'a directory with this installation\'s marker: ' . $again->last_error() );
		$this->assertFileExists( $store . '/backups/b.wpcheckpoint.zip' );
	}

	public function test_a_directory_that_holds_other_files_is_refused_before_anything_is_written(): void {
		$other = $this->fake_root . '/other';
		mkdir( $other );
		file_put_contents( $other . '/.keep', '' ); // A hidden file is enough.
		$dirs = $this->custom( $other );
		$this->assertSame( '', $dirs->base() );
		$this->assertStringContainsString( 'already holds files and does not carry WP Checkpoint\'s owner marker', $dirs->last_error() );
		$this->assertSame( array( '.', '..', '.keep' ), scandir( $other ), 'nothing written' );

		unlink( $other . '/.keep' );
		$dirs = $this->custom( $other );
		$this->assertSame( $other, $dirs->base(), 'the control: the same directory, empty: ' . $dirs->last_error() );
	}

	public function test_a_directory_left_by_a_request_that_died_is_taken_up(): void {
		$first = $this->custom( $this->fake_root . '/first' );
		$this->assertNotSame( '', $first->base(), $first->last_error() );
		$marker = OwnerMarker::build( (string) Directories::load_state()['install_id'], ABSPATH );

		// Died after creating the directory, before the marker: an empty directory.
		mkdir( $this->fake_root . '/died-empty' );
		$dirs = $this->custom( $this->fake_root . '/died-empty' );
		$this->assertSame( $this->fake_root . '/died-empty', $dirs->base(), $dirs->last_error() );
		$this->assertSame( $marker, file_get_contents( $this->fake_root . '/died-empty/' . OwnerMarker::FILENAME ) );

		// Died while writing the marker: nothing but the start of this installation's.
		foreach ( array( 'died-in-marker-0' => '', 'died-in-marker-half' => substr( $marker, 0, 20 ) ) as $name => $start ) {
			mkdir( $this->fake_root . '/' . $name );
			file_put_contents( $this->fake_root . '/' . $name . '/' . OwnerMarker::FILENAME, $start );
			$dirs = $this->custom( $this->fake_root . '/' . $name );
			$this->assertSame( $this->fake_root . '/' . $name, $dirs->base(), $name . ': ' . $dirs->last_error() );
			$this->assertSame( $marker, file_get_contents( $this->fake_root . '/' . $name . '/' . OwnerMarker::FILENAME ), $name );
			$this->assertFalse( $dirs->state()['clone_detected'], $name );
		}

		// Died right after the marker (before the probe, the sub-directories and the protection files): the marker is
		// the first thing written, so the directory already reads as this installation's.
		$dies = $this->cli_context(
			array(
				'custom_dir'     => $this->fake_root . '/died-after-marker',
				'wordpress_dirs' => $this->stand_ins(),
				'after_marker'   => function (): void {
					throw new \RuntimeException( 'The request died here.' );
				},
			)
		);
		try {
			( new Directories( $dies ) )->base();
			$this->fail( 'the request did not die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The request died here.', $e->getMessage() );
		}
		$this->assertSame( array( OwnerMarker::FILENAME => $marker ), self::files_of( $this->fake_root . '/died-after-marker' ), 'nothing but the marker' );
		$dirs = $this->custom( $this->fake_root . '/died-after-marker' );
		$this->assertSame( $this->fake_root . '/died-after-marker', $dirs->base(), $dirs->last_error() );
		$this->assertDirectoryExists( $this->fake_root . '/died-after-marker/backups' );

		// The control: the start of another installation's marker is not taken up.
		mkdir( $this->fake_root . '/foreign-start' );
		file_put_contents( $this->fake_root . '/foreign-start/' . OwnerMarker::FILENAME, substr( OwnerMarker::build( 'other-install', '/srv/other/' ), 0, 10 ) );
		$dirs = $this->custom( $this->fake_root . '/foreign-start' );
		$this->assertSame( '', $dirs->base() );
		$this->assertSame( array( '.', '..', OwnerMarker::FILENAME ), scandir( $this->fake_root . '/foreign-start' ), 'nothing written' );
	}

	public function test_the_real_wordpress_directories_cannot_be_the_storage_directory(): void {
		// Asked only (StorageLocation writes nothing); what a refusal does is shown above with stand-ins.
		$wordpress = Directories::wordpress_dirs();
		foreach ( array(
			ABSPATH . 'wp-admin'                      => 'the wp-admin directory',
			ABSPATH . 'wp-includes'                   => 'the wp-includes directory',
			wp_upload_dir( null, false )['basedir']   => 'the uploads directory',
			WP_PLUGIN_DIR                             => 'the plugins directory',
			WPMU_PLUGIN_DIR                           => 'the must-use plugins directory',
			get_theme_root()                          => 'a themes directory',
			WP_LANG_DIR                               => 'the languages directory',
			WP_CONTENT_DIR . '/upgrade'               => 'the upgrade directory',
			WP_CONTENT_DIR . '/upgrade-temp-backup'   => 'the directory WordPress keeps backups in during updates',
			WP_CONTENT_DIR                            => 'the content directory (wp-content)',
			ABSPATH                                   => 'the content directory (wp-content)',
		) as $dir => $label ) {
			$this->assertSame( $label, StorageLocation::refusal( (string) $dir, $wordpress['within'], $wordpress['itself'] ), (string) $dir );
		}
		if ( function_exists( 'wp_get_font_dir' ) ) {
			// Fonts in wp-content rather than in the uploads (where WordPress puts them when it can).
			$moved = static function ( array $dir ): array {
				$dir['path']    = WP_CONTENT_DIR . '/fonts';
				$dir['basedir'] = WP_CONTENT_DIR . '/fonts';
				return $dir;
			};
			add_filter( 'font_dir', $moved );
			$fonts = Directories::wordpress_dirs();
			remove_filter( 'font_dir', $moved );
			$this->assertSame( 'the fonts directory', StorageLocation::refusal( WP_CONTENT_DIR . '/fonts', $fonts['within'], $fonts['itself'] ) );
			$this->assertSame( '', StorageLocation::refusal( WP_CONTENT_DIR . '/fonts', $wordpress['within'], $wordpress['itself'] ), 'the control: not a WordPress directory while the fonts are elsewhere' );
		}
		if ( is_multisite() ) {
			$this->assertSame( 'the uploads directory', StorageLocation::refusal( WP_CONTENT_DIR . '/blogs.dir', $wordpress['within'], $wordpress['itself'] ) );
			// From a site of the network other than the main one: the main site's uploads too.
			$main = wp_upload_dir( null, false )['basedir'];
			switch_to_blog( self::factory()->blog->create() );
			$this->assertNotSame( $main, wp_upload_dir( null, false )['basedir'], 'this site has uploads of its own' );
			$network = Directories::wordpress_dirs();
			restore_current_blog();
			// A directory in the main site's uploads beside the sites' (the main uploads itself holds this site's anyway).
			$this->assertSame( 'the uploads directory', StorageLocation::refusal( $main . '/wpc-store', $network['within'], $network['itself'] ) );
		}
		$this->assertSame( '', StorageLocation::refusal( WP_CONTENT_DIR . '/wpc-storage-' . bin2hex( random_bytes( 3 ) ), $wordpress['within'], $wordpress['itself'] ), 'the control: a new directory in wp-content' );
		$this->assertSame( '', StorageLocation::refusal( dirname( ABSPATH ) . '/wpc-storage', $wordpress['within'], $wordpress['itself'] ), 'the control: next to the WordPress directory' );
	}

	/**
	 * Count the writes of the storage state (update_option or, on a network, update_site_option): each attempt, an
	 * unchanged value included.
	 *
	 * @return callable Returns the count so far.
	 */
	private function count_saves(): callable {
		$saves = 0;
		$count = static function ( $value ) use ( &$saves ) {
			++$saves;
			return $value;
		};
		add_filter( 'pre_update_option_' . Directories::OPTION, $count );
		add_filter( 'pre_update_site_option_' . Directories::OPTION, $count );
		return static function () use ( &$saves ): int {
			return $saves;
		};
	}

	/**
	 * A marker reader that fails, and one that reads, counting what it was asked.
	 *
	 * @param int $calls Calls, by reference.
	 * @return array{0: callable, 1: callable} Failing, reading.
	 */
	private static function readers( int &$calls ): array {
		return array(
			static function ( string $path ) use ( &$calls ) {
				++$calls;
				unset( $path );
				return false;
			},
			static function ( string $path ) use ( &$calls ) {
				++$calls;
				return file_get_contents( $path );
			},
		);
	}

	public function test_an_unreadable_owner_marker_of_a_custom_directory_changes_nothing(): void {
		$custom = $this->fake_root . '/custom-storage';
		$first  = $this->custom( $custom );
		$this->assertSame( $custom, $first->base(), $first->last_error() );
		$state                = Directories::load_state();
		$state['past_tokens'] = array( 'aaaaaaaaaaaa' ); // The history an unreadable marker must not cost.
		Options::set( Directories::OPTION, $state );
		$before = Directories::load_state();
		$files  = self::files_of( $custom );
		$saves  = $this->count_saves();
		$calls  = 0;
		list( $failing, $reading ) = self::readers( $calls );

		// The control: read, the marker reaches the ownership decision (this installation's: taken).
		$dirs = new Directories( $this->cli_context( array( 'custom_dir' => $custom, 'wordpress_dirs' => $this->stand_ins(), 'read_marker' => $reading ) ) );
		$this->assertSame( $custom, $dirs->base(), $dirs->last_error() );
		$this->assertGreaterThan( 0, $calls, 'the marker was read through the reader' );
		$this->assertSame( $before, Directories::load_state() );

		// Not read: an environment error, and nothing recorded, saved or written.
		$calls = 0;
		$saved = $saves();
		$dirs  = new Directories( $this->cli_context( array( 'custom_dir' => $custom, 'wordpress_dirs' => $this->stand_ins(), 'read_marker' => $failing ) ) );
		$this->assertSame( '', $dirs->base() );
		$this->assertGreaterThan( 0, $calls, 'the reader was asked' );
		$this->assertStringContainsString( 'cannot be read (file permissions, or the host\'s open_basedir setting)', $dirs->last_error() );
		$this->assertStringNotContainsString( 'another installation', $dirs->last_error() );
		$this->assertFalse( $dirs->state()['clone_detected'] );
		$this->assertSame( $before, Directories::load_state(), 'past_tokens and the rest as they were' );
		$this->assertSame( $saved, $saves(), 'nothing saved' );
		$this->assertSame( $files, self::files_of( $custom ), 'nothing written' );

		// The control for the counter and for the other answer: another installation's marker, read, is a clone.
		file_put_contents( $custom . '/' . OwnerMarker::FILENAME, OwnerMarker::build( 'other-install', '/srv/other/' ) );
		$dirs = new Directories( $this->cli_context( array( 'custom_dir' => $custom, 'wordpress_dirs' => $this->stand_ins(), 'read_marker' => $reading ) ) );
		$this->assertSame( '', $dirs->base() );
		$this->assertStringContainsString( 'belongs to another installation', $dirs->last_error() );
		$this->assertTrue( Directories::load_state()['clone_detected'] );
		$this->assertSame( array(), Directories::load_state()['past_tokens'] );
		$this->assertGreaterThan( $saved, $saves(), 'the counter sees a save' );
	}

	public function test_an_unreadable_owner_marker_of_the_default_directory_changes_nothing(): void {
		$first = new Directories( $this->cli_context() );
		$dir   = $first->base();
		$this->assertNotSame( '', $dir, $first->last_error() );
		$state                = Directories::load_state();
		$state['past_tokens'] = array( 'aaaaaaaaaaaa' );
		Options::set( Directories::OPTION, $state );
		$before = Directories::load_state();
		$files  = $this->snapshot( $dir );
		$saves  = $this->count_saves();
		$calls  = 0;
		list( $failing, $reading ) = self::readers( $calls );

		$dirs = new Directories( $this->cli_context( array( 'read_marker' => $reading ) ) );
		$this->assertSame( $dir, $dirs->base(), 'the control: read, it is this installation\'s: ' . $dirs->last_error() );
		$this->assertGreaterThan( 0, $calls );

		$saved = $saves();
		$dirs  = new Directories( $this->cli_context( array( 'read_marker' => $failing ) ) );
		$this->assertSame( '', $dirs->base(), 'no other directory is chosen meanwhile' );
		$this->assertStringContainsString( 'cannot be read (file permissions, or the host\'s open_basedir setting)', $dirs->last_error() );
		$this->assertFalse( $dirs->state()['clone_detected'] );
		$this->assertSame( $before, Directories::load_state() );
		$this->assertSame( $saved, $saves(), 'nothing saved' );
		$this->assertSame( $files, $this->snapshot( $dir ) );
		$this->assertSame( array( $dir ), glob( dirname( $dir ) . '/' . Directories::DIR_PREFIX . '*' ), 'no other directory made' );

		// The control for the counter: another installation's marker, read, is a clone and is saved.
		file_put_contents( $dir . '/' . OwnerMarker::FILENAME, OwnerMarker::build( 'other-install', '/srv/other/' ) );
		$dirs = new Directories( $this->cli_context( array( 'read_marker' => $reading ) ) );
		$this->assertNotSame( $dir, $dirs->base(), 'another directory: ' . $dirs->last_error() ); // Resolved here (lazily).
		$this->assertTrue( Directories::load_state()['clone_detected'] );
		$this->assertGreaterThan( $saved, $saves() );
	}

	public function test_a_candidate_directory_with_an_unreadable_marker_is_not_called_another_installations(): void {
		// A new default directory in a stand-in content directory, where something with a marker is already.
		$content = $this->fake_root . '/htdocs/wp/wp-content';
		mkdir( $content );
		$token          = 'abcdef012345';
		$state          = Directories::load_state();
		$state['token'] = $token;
		Options::set( Directories::OPTION, $state );
		$candidate = $content . '/' . Directories::DIR_PREFIX . $token;
		mkdir( $candidate );
		file_put_contents( $candidate . '/' . OwnerMarker::FILENAME, 'whatever it holds' );
		file_put_contents( $candidate . '/other-file', 'x' );
		$calls = 0;
		list( $failing, $reading ) = self::readers( $calls );

		$dirs = new Directories( $this->cli_context( array( 'content_dir' => $content, 'read_marker' => $reading ) ) );
		$this->assertSame( '', $dirs->base() );
		$this->assertSame( 'The directory belongs to another installation.', $dirs->last_error(), 'the control: read, it is someone else\'s' );
		$this->assertGreaterThan( 0, $calls );

		$dirs = new Directories( $this->cli_context( array( 'content_dir' => $content, 'read_marker' => $failing ) ) );
		$this->assertSame( '', $dirs->base() );
		$this->assertStringContainsString( 'cannot be read (file permissions, or the host\'s open_basedir setting)', $dirs->last_error() );
		$this->assertFalse( Directories::load_state()['clone_detected'] );
		$this->assertSame( array( '.', '..', OwnerMarker::FILENAME, 'other-file' ), scandir( $candidate ), 'nothing written' );
	}

	public function test_the_start_of_this_installations_marker_in_a_directory_that_cannot_be_listed_is_no_clone(): void {
		$first = $this->custom( $this->fake_root . '/first' ); // This installation's install ID.
		$this->assertNotSame( '', $first->base(), $first->last_error() );
		$state                = Directories::load_state();
		$state['past_tokens'] = array( 'aaaaaaaaaaaa' );
		Options::set( Directories::OPTION, $state );
		$before = Directories::load_state();
		$dir    = $this->fake_root . '/died-in-marker';
		mkdir( $dir );
		file_put_contents( $dir . '/' . OwnerMarker::FILENAME, substr( OwnerMarker::build( (string) $before['install_id'], ABSPATH ), 0, 20 ) );
		chmod( $dir, 0300 ); // Its files can be reached by name, its contents not listed.
		clearstatcache();
		try {
			if ( false !== @scandir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the test's own directory.
				$this->markTestSkipped( 'A directory without read permission can still be listed here (the tests run as root).' );
			}
			$this->assertIsString( file_get_contents( $dir . '/' . OwnerMarker::FILENAME ), 'the control: the marker itself can be read' );
			$dirs = $this->custom( $dir );
			$this->assertSame( '', $dirs->base() );
			$this->assertStringContainsString( 'cannot be read (file permissions, or the host\'s open_basedir setting)', $dirs->last_error(), 'whether anything but the marker is there cannot be seen' );
			$this->assertFalse( Directories::load_state()['clone_detected'] );
			$this->assertSame( $before, Directories::load_state() );
		} finally {
			chmod( $dir, 0755 );
		}
		$dirs = $this->custom( $dir );
		$this->assertSame( $dir, $dirs->base(), 'the control: listed, it is taken up as a directory a request died in: ' . $dirs->last_error() );
	}

	/**
	 * A storage directory that cannot be searched (0600: listed, its marker not looked at) or not even listed (0000).
	 *
	 * @return array<string, array{0: int}>
	 */
	public function unsearchable_modes(): array {
		return array(
			'listed, not searched' => array( 0600 ),
			'neither'              => array( 0000 ),
		);
	}

	/**
	 * @dataProvider unsearchable_modes
	 */
	public function test_a_default_directory_whose_marker_cannot_be_looked_at_is_no_clone( int $mode ): void {
		$first = new Directories( $this->cli_context() );
		$dir   = $first->base();
		$this->assertNotSame( '', $dir, $first->last_error() );
		$state                = Directories::load_state();
		$state['past_tokens'] = array( 'aaaaaaaaaaaa' );
		Options::set( Directories::OPTION, $state );
		$before = Directories::load_state();
		$saves  = $this->count_saves();
		chmod( $dir, $mode );
		clearstatcache();
		try {
			if ( @is_file( $dir . '/' . OwnerMarker::FILENAME ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the test's own directory.
				$this->markTestSkipped( 'A directory without search permission can still be searched here (the tests run as root).' );
			}
			$this->assertTrue( is_dir( $dir ), 'the control: the directory itself is there' );
			$dirs = new Directories( $this->cli_context() );
			$this->assertSame( '', $dirs->base(), 'no other directory is chosen meanwhile' );
			$this->assertStringContainsString( 'cannot be read (file permissions, or the host\'s open_basedir setting)', $dirs->last_error() );
			$this->assertFalse( Directories::load_state()['clone_detected'] );
			$this->assertSame( $before, Directories::load_state() );
			$this->assertSame( 0, $saves(), 'nothing saved' );
		} finally {
			chmod( $dir, 0755 );
		}
		$dirs = new Directories( $this->cli_context() );
		$this->assertSame( $dir, $dirs->base(), 'the control: searchable again, it is this installation\'s: ' . $dirs->last_error() );
	}

	public function test_a_marker_the_file_system_will_not_let_be_read_warns_nothing_that_names_the_path(): void {
		$custom = $this->fake_root . '/unreadable-marker';
		$first  = $this->custom( $custom );
		$this->assertSame( $custom, $first->base(), $first->last_error() );
		$marker = $custom . '/' . OwnerMarker::FILENAME;
		chmod( $marker, 0000 );
		clearstatcache();
		try {
			if ( is_readable( $marker ) ) {
				$this->markTestSkipped( 'A file without permissions is still readable here (the tests run as root).' );
			}
			$warnings = array();
			set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- the test observes warnings.
				static function ( int $level, string $message ) use ( &$warnings ): bool {
					if ( 0 !== ( error_reporting() & $level ) ) { // What reaches the log: not what "@" silenced.
						$warnings[] = $message;
					}
					return true;
				}
			);
			try {
				file_get_contents( $marker ); // The control: reading it warns, naming the path.
				$control  = $warnings;
				$warnings = array();
				$dirs     = $this->custom( $custom );
				$base     = $dirs->base(); // Resolved here (lazily), while the handler watches.
			} finally {
				restore_error_handler();
			}
			$this->assertNotSame( array(), array_filter( $control, static function ( string $w ) use ( $custom ): bool {
				return false !== strpos( $w, $custom );
			} ), 'the control: a plain read warns with the path' );
			$this->assertSame( '', $base );
			$this->assertStringContainsString( 'cannot be read', $dirs->last_error() );
			$this->assertFalse( $dirs->state()['clone_detected'] );
			$this->assertSame( array(), array_values( array_filter( $warnings, static function ( string $w ) use ( $custom ): bool {
				return false !== strpos( $w, $custom );
			} ) ), 'no warning names the path' );
		} finally {
			chmod( $marker, 0644 );
		}
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
