<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\Notices;
use WPCheckpoint\Admin\SiteIdentityActions;
use WPCheckpoint\Cli\SiteIdentityCommand;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Options;
use WPCheckpoint\Support\OwnerMarker;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * When whether this is the site that chose the storage directory cannot be told, the administrator answers
 * (Directories::identity_question(), answer_identity()): two sources (the two WordPress directories cannot be
 * compared; a take-over died and the site moved again before anything finished it), two answers ("copy", "same"),
 * through the admin (SiteIdentityActions) or WP-CLI (SiteIdentityCommand). And what a newer move makes of the jobs an
 * unanswered one set aside, and the hint when the site moves back and forth.
 */
final class SiteIdentityTest extends WP_UnitTestCase {

	/** @var string The test's directory; '' before set_up() made it. */
	private $root = '';

	/** @var int */
	private $admin = 0;

	public function set_up(): void {
		parent::set_up();
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
	}

	private function dirs( string $site, string $custom = '' ): Directories {
		return new Directories(
			array(
				'is_web_request' => false,
				'document_root'  => '',
				'abspath'        => $this->root . '/' . $site . '/',
				'content_dir'    => $this->root . '/content',
				'custom_dir'     => $custom,
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
}
