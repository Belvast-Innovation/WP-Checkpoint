<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\JobProgress;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\SiteIdentityActions;
use WPCheckpoint\Cli\SiteIdentityCommand;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\OwnerMarker;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use WPCheckpoint\Tests\Fixtures\StorageDirs;

/**
 * When whether this is the site that chose the storage directory cannot be told, the administrator answers
 * (Directories::identity_question(), answer_identity()): two sources (the two WordPress directories cannot be
 * compared; a take-over died and the site moved again before anything finished it), two answers ("copy", "same"),
 * through the admin (SiteIdentityActions) or WP-CLI (SiteIdentityCommand). And what a newer move makes of the jobs an
 * unanswered one set aside, and the hint when the site moves back and forth.
 */
final class SiteIdentityTest extends WP_UnitTestCase {

	/** @var string[]|null The storage directories in wp-content when the test started (StorageDirs); null before set_up(). */
	private $storage_before = null;

	/** @var string The test's directory; '' before set_up() made it. */
	private $root = '';

	/** @var int */
	private $admin = 0;

	public function set_up(): void {
		parent::set_up();
		$this->storage_before = StorageDirs::listing();
		global $wpdb;
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$this->root  = Sandbox::make( 'site-identity' );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $this->admin );
		}
		wp_set_current_user( $this->admin );
		Schema::ensure();
	}

	public function tear_down(): void {
		global $wpdb;
		// Before the DROP TABLE, which commits them.
		Options::delete( Schema::OPTION );
		Options::delete( Directories::OPTION );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Schema::jobs_table() );
		if ( '' !== $this->root ) {
			foreach ( glob( $this->root . '/*/locked' ) ?: array() as $locked ) {
				chmod( $locked, 0755 );
			}
			Sandbox::remove( $this->root );
		}
		remove_all_filters( 'wp_redirect' );
		unset( $_POST[ SiteIdentityActions::FIELD ], $_POST[ SiteIdentityActions::QUESTION ], $_REQUEST['_wpnonce'] );
		parent::tear_down();
		if ( null !== $this->storage_before ) {
			StorageDirs::remove_made_since( $this->storage_before ); // The Directories it made has its directory in wp-content.
		}
	}

	private function dirs( string $site, string $custom = '', array $extra = array() ): Directories {
		return new Directories(
			array_merge(
				array(
					'is_web_request' => false,
					'document_root'  => '',
					'abspath'        => $this->root . '/' . $site . '/',
					'content_dir'    => $this->root . '/content',
					'custom_dir'     => $custom,
				),
				$extra
			)
		);
	}

	private static function repo( Directories $dirs ): JobRepository {
		return new JobRepository( $dirs, null, static function (): int {
			return time();
		} );
	}

	/** A job that holds the site changed (it goes on under any token this installation holds). */
	private static function held( Directories $dirs ): int {
		global $wpdb;
		$job = self::repo( $dirs )->create( 'plain' );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING, 'site_state' => Job::SITE_CHANGING ), array( 'id' => $job->id ) );
		return $job->id;
	}

	/** The handler's answer to the question this request shows, as the form sends it (with its id). */
	private static function answer( Directories $dirs, string $answer ): array {
		$question = $dirs->identity_question();
		return ( new SiteIdentityActions( $dirs ) )->run( $answer, (string) ( $question['id'] ?? '' ) );
	}

	/** Whether a request lets a job through and takes it (the lock given back after). */
	private function runs( int $id, string $site, string $custom = '' ): bool {
		global $wpdb;
		$repo = self::repo( $this->dirs( $site, $custom ) );
		$job  = $repo->find( $id );
		$let  = null !== $job && $repo->gate( $job )['allowed'];
		$took = null !== $repo->acquire( $id );
		$wpdb->update( Schema::jobs_table(), array( 'lock_token' => '', 'locked_until' => 0 ), array( 'id' => $id ) );
		return $let && $took;
	}

	/**
	 * Source 1: state written, by the version before, under a spelling of ABSPATH through a directory that becomes
	 * unsearchable, with a marker of that spelling: from the resolved spelling the two cannot be compared.
	 *
	 * @return array{0: string, 1: int} The original directory and a job holding the site.
	 */
	private function undecidable(): array {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Root searches every directory.' );
		}
		mkdir( $this->root . '/s1/real/wp-includes', 0755, true );
		mkdir( $this->root . '/s1/locked' );
		symlink( $this->root . '/s1/real', $this->root . '/s1/locked/site' );
		$cli  = $this->dirs( 's1/locked/site' );
		$base = $cli->base();
		$this->assertNotSame( '', $base, $cli->last_error() );
		$job   = self::held( $cli );
		$state = Options::get( Directories::OPTION, array() );
		file_put_contents( $base . '/.wpcheckpoint-owner', $state['install_id'] . "\n" . hash( 'sha256', $this->root . '/s1/locked/site' ) . "\n" );
		unset( $state['abspath_real'], $state['marker_hash'] ); // As the version before wrote it.
		Options::set( Directories::OPTION, $state );
		chmod( $this->root . '/s1/locked', 0 );
		return array( $base, $job );
	}

	public function test_an_undecidable_site_answered_as_a_copy_takes_a_token_and_directory_of_its_own(): void {
		list( $base, $job ) = $this->undecidable();
		$web                = $this->dirs( 's1/real' );
		$this->assertSame( '', $web->base(), 'the control: it cannot be told' );
		$question = $web->identity_question();
		$this->assertSame( 'paths', $question['kind'] ?? '' );
		$before = time();
		$result = self::answer( $web, Directories::ANSWER_COPY );
		$this->assertTrue( $result['ok'], $result['message'] );
		$answer = Options::get( Directories::OPTION, array() )['identity_answers'][ $question['id'] ] ?? array();
		$this->assertSame( 'copy', $answer['answer'] ?? '', 'recorded' );
		$this->assertGreaterThanOrEqual( $before, $answer['at'] ?? 0, 'with the time' );

		$next = $this->dirs( 's1/real' );
		$this->assertNotSame( '', $next->base(), $next->last_error() );
		$this->assertNotSame( $base, $next->base(), 'a directory of its own' );
		$this->assertNull( $next->identity_question(), 'the same pair of paths is not asked about again' );
		$this->assertFalse( $this->runs( $job, 's1/real' ), 'the jobs started before are not run here' );
	}

	public function test_an_undecidable_site_answered_as_the_same_keeps_its_token(): void {
		list( , $job ) = $this->undecidable();
		$web           = $this->dirs( 's1/real' );
		$this->assertSame( '', $web->base(), 'the control: it cannot be told' );
		$this->assertTrue( self::answer( $web, Directories::ANSWER_SAME )['ok'] );

		$next = $this->dirs( 's1/real' );
		$this->assertNotSame( '', $next->base(), $next->last_error() );
		$this->assertSame( array(), $next->state()['copied_tokens'], 'none of its tokens set aside' );
		$this->assertTrue( $this->runs( $job, 's1/real' ), 'its job runs' );
		$this->assertNull( $next->identity_question(), 'not asked again' );
	}

	public function test_another_pair_of_paths_is_asked_about_again(): void {
		$this->undecidable();
		$web      = $this->dirs( 's1/real' );
		$answered = $web->identity_question();
		// The same recorded state asked from another WordPress directory: another pair, another question.
		mkdir( $this->root . '/s1/real2/wp-includes', 0755, true );
		$elsewhere = $this->dirs( 's1/real2' )->identity_question();
		$this->assertNotNull( $elsewhere, 'the control: asked there too' );
		$this->assertNotSame( $answered['id'], $elsewhere['id'], 'this request\'s directory is part of the pair' );
		$this->assertTrue( self::answer( $web, Directories::ANSWER_SAME )['ok'] );
		$this->assertNull( $this->dirs( 's1/real' )->identity_question(), 'the control: this pair is not asked again' );
		// A request from the spelling that cannot be resolved: another pair of paths.
		$other = $this->dirs( 's1/locked/site' );
		$this->assertSame( '', $other->base() );
		$question = $other->identity_question();
		$this->assertNotNull( $question, 'asked: an answer holds for its own pair of paths only' );
		$this->assertNotSame( $answered['id'], $question['id'] );
	}

	/**
	 * Source 2: a custom directory shared by releases; a take-over from release 2 rewrites the marker and dies; the
	 * site moves to release 3 before any request finished it.
	 *
	 * @return array{0: string, 1: int, 2: string} The directory, a job holding the site, the token.
	 */
	private function claimed(): array {
		foreach ( array( 1, 2, 3 ) as $release ) {
			mkdir( $this->root . '/s2/releases/' . $release . '/wp-includes', 0755, true );
		}
		$store = $this->root . '/s2/store';
		$first = $this->dirs( 's2/releases/1', $store );
		$this->assertSame( $store, $first->base(), $first->last_error() );
		$token = (string) $first->state()['token'];
		$job   = self::held( $first );
		$moved = $this->dirs( 's2/releases/2', $store );
		$this->assertSame( '', $moved->base(), 'the control: the move is detected' );
		$this->assertTrue( $moved->reclaim()->reclaim( false )['ok'], 'the take-over rewrites the marker' );
		// The request dies here; the next one is from release 3.
		$next = $this->dirs( 's2/releases/3', $store );
		$this->assertSame( '', $next->base() );
		$this->assertArrayNotHasKey( $token, (array) $next->state()['lost_tokens'], 'the token the state holds is never a lost one' );
		return array( $store, $job, $token );
	}

	public function test_a_dead_take_over_after_another_move_answered_as_the_same_site_is_finished(): void {
		list( $store, $job, $token ) = $this->claimed();
		$next                        = $this->dirs( 's2/releases/3', $store );
		$question                    = $next->identity_question();
		$this->assertSame( 'claimed', $question['kind'] ?? '', 'asked, not refused as claimed by another copy' );
		$notices = ( new Notices( $next ) )->notices();
		$this->assertArrayHasKey( 'site_identity', $notices, 'the question in the admin' );
		$this->assertSame( array(), $notices['clone_detected']['link'], 'instead of a "continue" that would be refused' );
		$this->assertTrue( self::answer( $next, Directories::ANSWER_SAME )['ok'] );
		$after = $this->dirs( 's2/releases/3', $store );
		$this->assertSame( $store, $after->base(), $after->last_error() );
		$this->assertSame( $token, (string) $after->state()['token'], 'its token kept' );
		$this->assertTrue( $this->runs( $job, 's2/releases/3', $store ), 'its job runs' );
		$this->assertNull( $after->identity_question() );
	}

	public function test_a_dead_take_over_after_another_move_answered_as_a_copy_keeps_away_from_the_directory(): void {
		list( $store, $job ) = $this->claimed();
		$next                = $this->dirs( 's2/releases/3', $store );
		$this->assertNotNull( $next->identity_question(), 'the control: asked' );
		$this->assertTrue( self::answer( $next, Directories::ANSWER_COPY )['ok'] );
		$after = $this->dirs( 's2/releases/3', $store );
		$this->assertSame( '', $after->base(), 'the directory is another installation\'s now' );
		$this->assertNull( $after->identity_question(), 'not asked again' );
		$this->assertFalse( $this->runs( $job, 's2/releases/3', $store ) );
	}

	public function test_only_an_administrator_with_the_nonce_answers(): void {
		$this->undecidable();
		$web = $this->dirs( 's1/real' );
		$web->base();
		$_POST[ SiteIdentityActions::FIELD ]    = Directories::ANSWER_SAME;
		$_POST[ SiteIdentityActions::QUESTION ] = $web->identity_question()['id'];
		$recorded                            = static function (): array {
			return (array) Options::get( Directories::OPTION, array() )['identity_answers'];
		};
		// Not an administrator (on a network, a site's administrator is not the network's).
		wp_set_current_user( self::factory()->user->create( array( 'role' => is_multisite() ? 'administrator' : 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = Guard::nonce( SiteIdentityActions::NONCE );
		try {
			( new SiteIdentityActions( $web ) )->answer();
			$this->fail( 'let through' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( array(), $recorded(), 'nothing recorded for a user without the capability' );
		}
		// An administrator without the nonce.
		wp_set_current_user( $this->admin );
		unset( $_REQUEST['_wpnonce'] );
		try {
			( new SiteIdentityActions( $web ) )->answer();
			$this->fail( 'let through' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( array(), $recorded(), 'nothing recorded without the nonce' );
		}
		// The control: an administrator with the nonce (stopped at the redirect, which would exit).
		$_REQUEST['_wpnonce'] = Guard::nonce( SiteIdentityActions::NONCE );
		add_filter(
			'wp_redirect',
			static function () {
				throw new \RuntimeException( 'redirected' );
			}
		);
		try {
			( new SiteIdentityActions( $web ) )->answer();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}
		$this->assertCount( 1, $recorded(), 'recorded' );
		$this->assertSame( 'same', array_values( $recorded() )[0]['answer'] );
	}

	public function test_an_answer_is_one_of_the_two(): void {
		$this->undecidable();
		$web = $this->dirs( 's1/real' );
		$web->base();
		$id  = $web->identity_question()['id'];
		foreach ( array( '', 'yes', 'SAME', 'copy ' ) as $bad ) {
			$this->assertFalse( $web->answer_identity( $bad, $id )['ok'], var_export( $bad, true ) );
		}
		// An answer to another question than this request asks: the one shown changed meanwhile.
		$changed = $web->answer_identity( Directories::ANSWER_SAME, str_repeat( '0', 64 ) );
		$this->assertFalse( $changed['ok'] );
		$this->assertStringContainsString( 'The question changed', $changed['message'] );
		$this->assertFalse( $web->answer_identity( Directories::ANSWER_SAME, '' )['ok'], 'nor without the question' );
		$this->assertSame( array(), (array) Options::get( Directories::OPTION, array() )['identity_answers'] );
		$this->assertTrue( $web->answer_identity( Directories::ANSWER_COPY, $id )['ok'], 'the control' );
	}

	public function test_the_question_in_the_admin_has_two_answers_and_no_default(): void {
		$this->undecidable();
		$web     = $this->dirs( 's1/real' );
		$notices = ( new Notices( $web ) )->notices();
		$this->assertArrayHasKey( 'site_identity', $notices );
		$this->assertFalse( $notices['site_identity']['dismissible'] );
		$this->assertSame( array( 'copy', 'same' ), array_column( $notices['site_identity']['answers'], 0 ) );
		$this->assertStringContainsString( 'the original site\'s jobs may run here', $notices['site_identity']['answers'][1][2], 'what "same" means if it is a copy' );
		ob_start();
		set_current_screen( 'toplevel_page_wp-checkpoint' );
		( new Notices( $web ) )->render();
		$html = (string) ob_get_clean();
		$this->assertSame( 2, substr_count( $html, 'name="' . SiteIdentityActions::FIELD . '"' ), 'two forms' );
		$this->assertStringNotContainsString( 'checked', $html, 'nothing preselected' );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertSame( 2, substr_count( $html, 'name="' . SiteIdentityActions::QUESTION . '" value="' . $web->identity_question()['id'] . '"' ), 'each answer names its question' );
	}

	public function test_wp_cli_shows_and_answers_the_question(): void {
		$this->undecidable();
		$web     = $this->dirs( 's1/real' );
		$command = new SiteIdentityCommand( \WPCheckpoint\Plugin::instance()->job_presenter(), $web );
		$shown   = $command->run( null );
		$this->assertSame( 2, $shown['code'], 'a question waiting' );
		$text = implode( "\n", $shown['lines'] );
		$this->assertStringContainsString( '--answer=copy', $text );
		$this->assertStringContainsString( '--answer=same', $text );
		$this->assertStringContainsString( 'there is no default', $text );
		$this->assertStringContainsString( '{tmp}', $text, 'the control: the paths are there, masked' );
		$this->assertStringNotContainsString( sys_get_temp_dir(), $text, 'through the same masking as every output' );
		$this->assertStringContainsString( '--question=' . $web->identity_question()['id'] . ' --answer=copy', $text, 'the command names its question' );
		$this->assertSame( 1, $command->run( 'copy', '' )['code'], 'an answer without its question is refused' );
		$answered = $command->run( 'copy', $web->identity_question()['id'] );
		$this->assertSame( 0, $answered['code'], implode( "\n", $answered['lines'] ) );
		$this->assertSame( 0, ( new SiteIdentityCommand( \WPCheckpoint\Plugin::instance()->job_presenter(), $this->dirs( 's1/real' ) ) )->run( null )['code'], 'no question any more' );
	}

	/**
	 * Releases of a deployment in the default directory: the site at release 1, then 2, then 3, nothing answered.
	 *
	 * @return array{0: int, 1: int} A job of release 1 (set aside by the first move), one of release 2.
	 */
	private function deployments(): array {
		global $wpdb;
		foreach ( array( 1, 2, 3 ) as $release ) {
			mkdir( $this->root . '/s3/releases/' . $release . '/wp-includes', 0755, true );
		}
		$first = $this->dirs( 's3/releases/1' );
		$first->base();
		$one = self::repo( $first )->create( 'plain' );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING ), array( 'id' => $one->id ) );
		$second = $this->dirs( 's3/releases/2' );
		$second->base();
		$this->assertTrue( $second->state()['clone_detected'], 'the control: the first move is detected' );
		$two = self::repo( $second )->create( 'plain' );
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING ), array( 'id' => $two->id ) );
		return array( $one->id, $two->id );
	}

	public function test_jobs_a_newer_move_leaves_behind_fail_with_the_reason(): void {
		list( $one, $two ) = $this->deployments();
		$third             = $this->dirs( 's3/releases/3' );
		$third->base(); // A second move before the first was answered.
		$this->assertTrue( $third->reclaim()->reclaim( false )['ok'], 'continue with the directory before' );
		$third->finish_reclaim();
		self::repo( $this->dirs( 's3/releases/3' ) )->settle_storage();
		$lost = self::repo( $this->dirs( 's3/releases/3' ) )->find( $one );
		$this->assertSame( Job::FAILED, $lost->status );
		$this->assertSame( Job::FAILURE_FINAL, $lost->failure_kind );
		$this->assertStringContainsString( 'identity changed during a deployment', (string) $lost->last_error );
		// Shown on the page: under the failure text, as rendered and as assets/admin/jobs.js updates it.
		$presenter = Plugin::instance()->job_presenter();
		$this->assertStringContainsString( 'identity changed during a deployment', (string) $presenter->present( $lost )['error_detail'] );
		ob_start();
		( new JobProgress( $presenter ) )->render( $lost );
		$this->assertStringContainsString( 'identity changed during a deployment', (string) ob_get_clean() );
		$this->assertNotSame( Job::FAILED, self::repo( $this->dirs( 's3/releases/3' ) )->find( $two )->status, 'the control: the latest move\'s job goes on' );
	}

	public function test_a_move_back_suggests_a_trusted_deployment_root(): void {
		$this->deployments();
		$second = $this->dirs( 's3/releases/2' );
		$this->assertStringNotContainsString( 'trusted deployment root', ( new Notices( $second ) )->notices()['clone_detected']['message'], 'the control: one move, no hint' );
		$back = $this->dirs( 's3/releases/1' ); // A worker of the release before.
		$back->base();
		$this->assertTrue( $back->moved_back() );
		$this->assertStringContainsString( 'trusted deployment root', ( new Notices( $back ) )->notices()['clone_detected']['message'] );
	}

	public function test_a_take_over_that_cannot_be_done_records_no_answer_and_keeps_the_question(): void {
		list( $store, $job, $token ) = $this->claimed();
		$next                        = $this->dirs( 's2/releases/3', $store );
		$question                    = $next->identity_question();
		file_put_contents( $store . '/tmp/recent.tmp', 'x' ); // Something is working there: the take-over's prechecks refuse.
		$refused = self::answer( $next, Directories::ANSWER_SAME );
		$this->assertFalse( $refused['ok'], 'the control: the take-over cannot be done now' );
		$this->assertSame( array(), (array) Options::get( Directories::OPTION, array() )['identity_answers'], 'nothing recorded' );
		$again = $this->dirs( 's2/releases/3', $store );
		$this->assertSame( $question['id'], $again->identity_question()['id'] ?? '', 'still asked' );
		Sandbox::remove( $store . '/tmp/recent.tmp' );
		// A stale record (an older detection's) of the token about to be given back.
		$state                          = Options::get( Directories::OPTION, array() );
		$state['lost_tokens'][ $token ] = time() - 60;
		Options::set( Directories::OPTION, $state );
		$again = $this->dirs( 's2/releases/3', $store ); // A request that reads the state with that record.
		$this->assertArrayHasKey( $token, (array) $again->state()['lost_tokens'], 'the control: the stale record is there' );
		$this->assertTrue( self::answer( $again, Directories::ANSWER_SAME )['ok'], 'answered once it can be done' );
		$after = $this->dirs( 's2/releases/3', $store );
		$this->assertSame( $store, $after->base(), $after->last_error() );
		$this->assertSame( $token, (string) $after->state()['token'] );
		$this->assertArrayNotHasKey( $token, (array) $after->state()['lost_tokens'], 'the token in use is not a lost one' );
		$this->assertTrue( $this->runs( $job, 's2/releases/3', $store ) );
	}

	public function test_an_answer_is_written_into_the_storage_log(): void {
		$this->undecidable();
		$web = $this->dirs( 's1/real' );
		$this->assertTrue( self::answer( $web, Directories::ANSWER_SAME )['ok'] );
		$after = $this->dirs( 's1/real' );
		$log   = $after->base() . '/logs/storage.log';
		$this->assertFileExists( $log, 'the control: the storage log is there' );
		$this->assertStringContainsString( 'The administrator answered "same"', (string) file_get_contents( $log ) );
	}

	/**
	 * Answer "same" to the claimed question from a request whose take-over meets $interference after it recorded the
	 * hash it is about to write and before it replaces the marker; then from a request without it.
	 */
	private function answer_through( callable $interference, string $what, ?callable $meanwhile = null ): void {
		list( $store, $job, $token ) = $this->claimed();
		$question                    = $this->dirs( 's2/releases/3', $store )->identity_question();
		$this->assertNotNull( $question, 'the control: asked' );
		$hooked = $this->dirs( 's2/releases/3', $store, array( 'before_rename' => $interference ) );
		try {
			$result = $hooked->answer_identity( Directories::ANSWER_SAME, $question['id'] );
			$this->assertFalse( $result['ok'], $what . ': the take-over did not happen' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'died', $e->getMessage(), $what );
		}
		$this->assertSame( array(), (array) Options::get( Directories::OPTION, array() )['identity_answers'], $what . ': nothing recorded' );
		if ( null !== $meanwhile ) {
			$meanwhile( $store );
		}
		$again = $this->dirs( 's2/releases/3', $store );
		$this->assertSame( $question['id'], $again->identity_question()['id'] ?? '', $what . ': still asked, the same question' );
		$this->assertTrue( self::answer( $again, Directories::ANSWER_SAME )['ok'], $what . ': answered again, it is done' );
		$after = $this->dirs( 's2/releases/3', $store );
		$this->assertSame( $store, $after->base(), $after->last_error() );
		$this->assertSame( $token, (string) $after->state()['token'] );
		$this->assertTrue( $this->runs( $job, 's2/releases/3', $store ) );
	}

	public function test_a_take_over_that_dies_before_replacing_the_marker_leaves_the_question(): void {
		$this->answer_through(
			static function (): void {
				throw new \RuntimeException( 'died' ); // The request dies between recording the hash and the rename.
			},
			'died before the rename'
		);
	}

	public function test_a_take_over_that_loses_its_lock_at_the_last_check_leaves_the_question(): void {
		$this->answer_through(
			static function ( string $dir ): void {
				file_put_contents( \WPCheckpoint\Support\StorageReclaim::lock_path( $dir ), "intruder\n" ); // Another process takes the lock over.
			},
			'lost the lock',
			static function ( string $store ): void {
				Sandbox::remove( \WPCheckpoint\Support\StorageReclaim::lock_path( $store ) ); // The other process is done.
			}
		);
	}

	/**
	 * A hook that ends the request at one step of answer_identity() (between two of its writes).
	 */
	private static function dies_at( string $at ): callable {
		return static function ( string $step ) use ( $at ): void {
			if ( $step === $at ) {
				throw new \RuntimeException( 'died' );
			}
		};
	}

	public function test_an_answer_recorded_before_the_take_over_finished_is_finished_by_the_next_request(): void {
		list( $store, , $token ) = $this->claimed();
		$question                = $this->dirs( 's2/releases/3', $store )->identity_question();
		$dying                   = $this->dirs( 's2/releases/3', $store, array( 'identity_step' => self::dies_at( 'recorded' ) ) );
		try {
			$dying->answer_identity( Directories::ANSWER_SAME, $question['id'] );
			$this->fail( 'the control: the request was meant to die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'died', $e->getMessage() );
		}
		$this->assertArrayHasKey( $question['id'], (array) Options::get( Directories::OPTION, array() )['identity_answers'], 'the answer recorded' );
		$this->assertTrue( Options::get( Directories::OPTION, array() )['clone_detected'], 'the control: not finished' );
		$after = $this->dirs( 's2/releases/3', $store );
		$this->assertSame( $store, $after->base(), 'finished: ' . $after->last_error() );
		$this->assertFalse( $after->state()['clone_detected'] );
		$this->assertSame( $token, (string) $after->state()['token'] );
		$this->assertNull( $after->identity_question() );
	}

	public function test_a_copy_answer_that_dies_before_it_is_recorded_leaves_no_question(): void {
		list( $store ) = $this->claimed();
		$question      = $this->dirs( 's2/releases/3', $store )->identity_question();
		$this->assertNotNull( $question, 'the control: asked' );
		$dying = $this->dirs( 's2/releases/3', $store, array( 'identity_step' => self::dies_at( 'acknowledged' ) ) );
		try {
			$dying->answer_identity( Directories::ANSWER_COPY, $question['id'] );
			$this->fail( 'the control: the request was meant to die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'died', $e->getMessage() );
		}
		$this->assertSame( array(), (array) Options::get( Directories::OPTION, array() )['identity_answers'], 'the control: not recorded' );
		$this->assertNull( $this->dirs( 's2/releases/3', $store )->identity_question(), 'nothing left to answer' );
	}

	/** The marker hash a directory holds ('' when it has none). */
	private static function marker_hash( string $dir ): string {
		$lines = \WPCheckpoint\Support\StorageReclaim::read_marker( $dir );
		return null === $lines ? '' : $lines[1];
	}

	/**
	 * Another WordPress directory with the same database (a copy, or a release nobody answered for) gets neither the
	 * directory nor the job, and asks nothing it could answer into it without a take-over.
	 */
	private function assert_nothing_for_another_place( string $site, string $store, int $job, array $taken ): void {
		mkdir( $this->root . '/' . $site . '/wp-includes', 0755, true );
		$other = $this->dirs( $site, $store );
		$this->assertNotContains( $other->base(), $taken, 'another place gets none of the site\'s directories' );
		$this->assertFalse( $this->runs( $job, $site, $store ), 'nor the job' );
	}

	/**
	 * Known limit 1, two requests at once: one loaded the state before a take-over, which then replaced the marker and
	 * died, and saves the state after it, overwriting the hashes the take-over recorded. Worst case on the safe side:
	 * the place that answered "same" keeps the directory it took over (the marker it wrote is its own proof), with the
	 * original token; nothing is given to another place.
	 */
	public function test_a_concurrent_save_over_a_dead_take_over_gives_nothing_to_another_place(): void {
		list( $store, $job, $token ) = $this->claimed();
		$question                    = $this->dirs( 's2/releases/3', $store )->identity_question();
		$this->assertNotNull( $question, 'the control: asked' );
		$stale = Options::get( Directories::OPTION, array() ); // Another request loads the state.
		$dying = $this->dirs( 's2/releases/3', $store, array( 'identity_step' => self::dies_at( 'taken' ) ) );
		try {
			$dying->answer_identity( Directories::ANSWER_SAME, $question['id'] );
			$this->fail( 'the control: the request was meant to die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'died', $e->getMessage() );
		}
		$written = self::marker_hash( $store );
		$this->assertTrue( OwnerMarker::is_hash_of( $written, $this->root . '/s2/releases/3/' ), 'the control: the marker was replaced' );
		Options::set( Directories::OPTION, $stale ); // ... and the other request saves what it loaded.
		$slots = array( (string) $stale['reclaim_marker_hash'], (string) $stale['reclaim_marker_prior'] );
		$this->assertNotContains( $written, $slots, 'the control: the hashes the take-over recorded are gone' );

		$here = $this->dirs( 's2/releases/3', $store );
		$this->assertSame( $store, $here->base(), 'where "same" was answered: ' . $here->last_error() );
		$this->assertSame( $token, (string) $here->state()['token'], 'with the original token' );
		$this->assertTrue( $this->runs( $job, 's2/releases/3', $store ) );
		$this->assert_nothing_for_another_place( 's2/copy', $store, $job, array( $store ) );
	}

	/**
	 * Known limit 2, the storage constant changed and changed back while a move waits: a "continue" from release 2
	 * fails after keeping the marker's hash (release 1's), the constant names another directory there, then the
	 * original one again from release 3. Worst case on the safe side: release 3 is asked (a question no take-over
	 * caused), release 1, whose own marker the directory carries, continues with it under the token it holds now; the
	 * original token's job stops; nothing is given to another place.
	 */
	public function test_the_storage_constant_changed_and_back_gives_nothing_to_another_place(): void {
		foreach ( array( 1, 2, 3 ) as $release ) {
			mkdir( $this->root . '/s5/releases/' . $release . '/wp-includes', 0755, true );
		}
		$store  = $this->root . '/s5/store';
		$other  = $this->root . '/s5/store2';
		$first  = $this->dirs( 's5/releases/1', $store );
		$this->assertSame( $store, $first->base(), $first->last_error() );
		$token  = (string) $first->state()['token'];
		$job    = self::held( $first );
		$marker = self::marker_hash( $store );
		$moved  = $this->dirs(
			's5/releases/2',
			$store,
			array(
				'before_rename' => static function (): void {
					throw new \RuntimeException( 'died' );
				},
			)
		);
		$this->assertSame( '', $moved->base(), 'the control: the move is detected' );
		try {
			$moved->reclaim()->reclaim( false );
			$this->fail( 'the control: the continue was meant to die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'died', $e->getMessage() );
		}
		$this->assertSame( $marker, (string) Options::get( Directories::OPTION, array() )['reclaim_marker_prior'], 'the control: the marker\'s hash kept' );
		$this->assertSame( $marker, self::marker_hash( $store ), 'the control: the marker not replaced' );
		$elsewhere = $this->dirs( 's5/releases/2', $other );
		$this->assertSame( $other, $elsewhere->base(), 'the control: the constant names another directory: ' . $elsewhere->last_error() );

		$back = $this->dirs( 's5/releases/3', $store );
		$this->assertSame( '', $back->base(), 'release 3 does not get the directory' );
		$this->assertSame( 'claimed', $back->identity_question()['kind'] ?? '', 'it is asked' );
		$this->assertFalse( $this->runs( $job, 's5/releases/3', $store ) );

		$original = $this->dirs( 's5/releases/1', $store );
		$this->assertSame( $store, $original->base(), 'release 1, whose marker the directory carries: ' . $original->last_error() );
		$this->assertSame( $marker, self::marker_hash( $store ), 'its own marker, unchanged' );
		$this->assert_nothing_for_another_place( 's5/copy', $store, $job, array( $store, $other ) );
		// What is lost on the way, on the safe side: the original token's job stops.
		$this->assertNotSame( $token, (string) $original->state()['token'], 'under the token it holds now' );
		$this->assertFalse( $this->runs( $job, 's5/releases/1', $store ), 'the original token\'s job stops' );
	}

	/**
	 * Known limit 3, the default directory: a "continue" from release 2 replaces the marker of release 1's directory
	 * and dies; the site is at release 3 before anything finished it. Nothing is asked there because release 3 is a
	 * move of its own, detected as usual from what the state recorded (the WordPress directory as resolved, release
	 * 2's directory with release 2's marker): it takes a directory and token of its own, release 2's detection
	 * replaces release 1's, and the job release 1's detection set aside fails with the reason. No answer is carried:
	 * none was recorded, and answers are kept per pair of paths. Worst case on the safe side: the jobs stop (one that
	 * does not hold the site fails with the reason once the latest move is resolved; one that holds it is left as it
	 * is and runs nowhere); release 1's directory is given to no place.
	 */
	public function test_a_newer_move_in_the_default_directory_is_detected_as_usual_and_gives_nothing(): void {
		global $wpdb;
		foreach ( array( 1, 2, 3 ) as $release ) {
			mkdir( $this->root . '/s4/releases/' . $release . '/wp-includes', 0755, true );
		}
		$first = $this->dirs( 's4/releases/1' );
		$one   = $first->base();
		$this->assertNotSame( '', $one, $first->last_error() );
		$token = (string) $first->state()['token'];
		$job   = self::held( $first );
		$plain = self::repo( $first )->create( 'plain' )->id;
		$wpdb->update( Schema::jobs_table(), array( 'status' => Job::RUNNING ), array( 'id' => $plain ) );
		$moved = $this->dirs( 's4/releases/2' );
		$two   = $moved->base();
		$this->assertNotSame( $one, $two, 'the control: the move is detected, with a directory of its own' );
		$this->assertTrue( $moved->reclaim()->reclaim( false )['ok'], 'the continue replaces the marker' );
		$this->assertTrue( OwnerMarker::is_hash_of( self::marker_hash( $one ), $this->root . '/s4/releases/2/' ), 'the control: replaced' );
		// The request dies here; the next one is from release 3.
		$third = $this->dirs( 's4/releases/3' );
		$three = $third->base();
		$state = $third->state();
		$this->assertNotSame( '', $three, $third->last_error() );
		$this->assertNotContains( $three, array( $one, $two ), 'a directory of its own' );
		$this->assertNotSame( $token, (string) $state['token'] );
		$this->assertNull( $third->identity_question(), 'nothing asked' );
		$this->assertFalse( $this->runs( $job, 's4/releases/3' ), 'the job holding the site does not run here' );
		$this->assertFalse( $this->runs( $plain, 's4/releases/3' ) );
		// The latest move resolved: release 3 continues with release 2's directory.
		$latest = $this->dirs( 's4/releases/3' );
		$this->assertTrue( $latest->reclaim()->reclaim( false )['ok'] );
		$latest->finish_reclaim();
		self::repo( $this->dirs( 's4/releases/3' ) )->settle_storage();
		$this->assertFalse( $this->runs( $job, 's4/releases/3' ), 'release 1\'s jobs do not run with the latest move\'s directory' );
		$this->assertFalse( $this->runs( $plain, 's4/releases/3' ) );
		$stopped = self::repo( $this->dirs( 's4/releases/3' ) )->find( $plain );
		$this->assertSame( Job::FAILED, $stopped->status, 'the job set aside fails' );
		$this->assertStringContainsString( 'identity changed during a deployment', (string) $stopped->last_error );
		$this->assertSame( Job::RUNNING, self::repo( $this->dirs( 's4/releases/3' ) )->find( $job )->status, 'the job holding the site is left as it is' );
		foreach ( array( 's4/releases/3', 's4/releases/2', 's4/releases/1' ) as $site ) {
			$this->assertFalse( $this->runs( $job, $site ), 'and runs nowhere: ' . $site );
		}
		// Every directory the site took by now (each request of a release that moved took one).
		$taken = glob( $this->root . '/content/' . Directories::DIR_PREFIX . '*', GLOB_ONLYDIR ) ?: array();
		$this->assertContains( $one, $taken, 'the control: the list holds the site\'s directories' );
		$this->assert_nothing_for_another_place( 's4/copy', '', $job, $taken );
		// How release 3 was taken up: as usual, from what the state recorded; no answer carried over.
		$this->assertSame( array(), (array) $state['identity_answers'], 'no answer recorded, none carried' );
		$this->assertSame( $two, (string) $state['previous_path'], 'detected as usual: release 2\'s directory is the one before' );
		$this->assertSame( rtrim( (string) realpath( $this->root . '/s4/releases/3' ), '/' ), rtrim( (string) $state['abspath_real'], '/' ), 'with the WordPress directory as resolved' );
		$this->assertArrayHasKey( $token, (array) $state['lost_tokens'], 'release 1\'s detection replaced' );
	}
}
