<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Cli\ExportCommand;
use WPCheckpoint\Cli\ExportRun;
use WPCheckpoint\Cli\RunLoop;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\ExportOptions;
use WPCheckpoint\Jobs\ExportPlan;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\PreflightStep;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Plugin;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The export job as the plugin registers it, driven the way
 * "wp wpcheckpoint export" drives it (ExportRun with injected output and
 * input): the real WordPress adapter, the table selection against a
 * neighbour installation in the same database, the question on a
 * terminal and without one, and what the command prints.
 */
final class ExportJobTest extends JobTestCase {

	/** @var string */
	private $uploads;

	/** @var string[] */
	private $neighbour = array();

	/** @var string[] */
	private $out = array();

	/** @var string[] */
	private $err = array();

	/** @var callable|null Called with each output or error line (tests that act mid-run). */
	private $on_out;

	public function set_up(): void {
		parent::set_up();
		$this->uploads = wp_upload_dir()['basedir'] . '/wpcexport';
		mkdir( $this->uploads . '/images', 0755, true );
		file_put_contents( $this->uploads . '/images/a.jpg', str_repeat( 'jpeg', 500 ) );
		file_put_contents( $this->uploads . '/images/b.txt', 'text' );
	}

	public function tear_down(): void {
		global $wpdb;
		Deleter::empty_directory( $this->uploads );
		@rmdir( $this->uploads );
		foreach ( $this->neighbour as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		parent::tear_down();
	}

	/**
	 * A second WordPress installation in the same database, under a longer prefix.
	 */
	private function add_neighbour(): void {
		global $wpdb;
		$prefix = $wpdb->base_prefix . 'old_';
		foreach ( array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships', 'users', 'extra' ) as $name ) {
			$table             = $prefix . $name;
			$this->neighbour[] = $table;
			$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (`id` bigint(20) NOT NULL AUTO_INCREMENT, `v` text, PRIMARY KEY (`id`))" );
			$wpdb->query( "INSERT INTO `{$table}` (`v`) VALUES ('neighbour data')" );
		}
		$this->assertSame( '', $wpdb->last_error );
	}

	private function run_export( array $flags, $input = null ): array {
		$this->out = array();
		$this->err = array();
		$options   = ExportOptions::normalize( array_merge( ExportCommand::options( $flags ), array( 'contents' => array( 'files' => array( 'uploads' ) ) ) ) );
		$job       = Plugin::instance()->jobs()->create( ExportJob::ID, self::$admin_id, array(), $options );
		$code      = $this->driver( $input )->run( $job->id, true, ! empty( $flags['porcelain'] ) );
		return array( $code, $job->id );
	}

	private function driver( $input = null ): ExportRun {
		$plugin = Plugin::instance();
		return new ExportRun(
			$plugin->job_actions(),
			$plugin->job_presenter(),
			$plugin->directories(),
			function ( string $line ): void {
				$this->out[] = $line;
				if ( null !== $this->on_out ) {
					call_user_func( $this->on_out, $line );
				}
			},
			function ( string $line ): void {
				$this->err[] = $line;
				if ( null !== $this->on_out ) {
					call_user_func( $this->on_out, $line );
				}
			},
			$input,
			static function (): void {}
		);
	}

	private function work( int $id ): string {
		$job = Plugin::instance()->jobs()->find( $id );
		return Residue::work_dir( $job->storage_path, $job->id );
	}

	private function slug(): string {
		return (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	}

	public function test_the_registered_job_backs_up_the_site_and_the_command_names_the_backup(): void {
		$this->assertArrayHasKey( ExportJob::ID, Plugin::instance()->job_types()->all() );
		list( $code, $id ) = $this->run_export( array( 'yes' => true ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code, implode( "\n", array_merge( $this->out, $this->err ) ) );
		$base    = (string) ExportPlan::read( $this->work( $id ), ExportPlan::PLAN )['base'];
		$written = preg_grep( '/^Backup written: backups\/[a-z0-9-]+\.manifest\.json$/', $this->out );
		$this->assertSame( array( 'Backup written: backups/' . $base . '.manifest.json' ), array_values( $written ) );
		$this->assertStringStartsWith( $this->slug() . '-', $base, 'the base name starts with the site address' );
		// Every other line is free of the site: the success line names the file the user acts on.
		foreach ( array_merge( array_diff( $this->out, $written ), $this->err ) as $line ) {
			$this->assertStringNotContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $line );
			$this->assertStringNotContainsString( $this->slug(), $line );
		}
		$manifest = Manifest::from_json( (string) file_get_contents( Plugin::instance()->directories()->backups() . '/' . $base . '.manifest.json' ) );
		$this->assertSame( home_url(), $manifest->site()['home_url'] );
		$this->assertSame( is_multisite(), $manifest->site()['multisite'] );
		$this->assertGreaterThanOrEqual( 2, $manifest->files_summary()['count'] );

		// Porcelain: the base name and nothing else on standard output.
		list( $code, $id ) = $this->run_export(
			array(
				'yes'       => true,
				'porcelain' => true,
			)
		);
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code );
		$this->assertSame( array( (string) ExportPlan::read( $this->work( $id ), ExportPlan::PLAN )['base'] ), $this->out );
	}

	private function reclaim( int $id ): void {
		Deleter::empty_directory( $this->work( $id ) );
		@rmdir( $this->work( $id ) );
	}

	public function test_a_backup_whose_name_is_reclaimed_during_the_command_is_confirmed_by_its_completion(): void {
		// Another request's maintenance reclaims the work directory right after the final tick, before the report.
		$this->on_out = function ( string $line ): void {
			if ( 0 === strpos( $line, 'completed' ) ) {
				$this->reclaim( (int) Plugin::instance()->jobs()->list_jobs( array( 'completed' ), 1 )[0]->id );
			}
		};
		list( $code ) = $this->run_export( array( 'yes' => true, 'porcelain' => true ) );
		$this->on_out = null;
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code, 'the store step has just renamed the backup into place' );
		$this->assertSame( array(), preg_grep( '/^[a-z0-9]/', $this->out ), 'porcelain prints no name it does not have' );
		$said = preg_grep( '/^The backup was written to backups\/, but its file name could not be read: The file plan\.json of this job is missing/', $this->err );
		$this->assertCount( 1, $said, implode( "\n", $this->err ) );
		$this->assertStringNotContainsString( ABSPATH, implode( "\n", $this->err ) );
	}

	public function test_a_backup_that_cannot_be_confirmed_later_exits_with_7(): void {
		list( $code, $id ) = $this->run_export( array( 'yes' => true ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code );
		$work = $this->work( $id );
		$plan = ExportPlan::read( $work, ExportPlan::PLAN );
		$this->assertMatchesRegularExpression( PreflightStep::BASE_PATTERN, (string) $plan['base'] );

		// A name that is not a backup's: never printed, never used as a path.
		ExportPlan::write( $work, ExportPlan::PLAN, array_merge( $plan, array( 'base' => '../../x' ) ) );
		$this->out = array();
		$this->err = array();
		$this->assertSame( ExportRun::EXIT_UNCONFIRMED, $this->driver()->run( $id, true, true ) );
		$this->assertSame( array(), $this->out );
		$this->assertCount( 1, preg_grep( '/^The job completed earlier; its backup can no longer be identified: The file plan\.json of this job does not name a backup/', $this->err ), implode( "\n", $this->err ) );

		// The manifest is gone from backups/.
		ExportPlan::write( $work, ExportPlan::PLAN, $plan );
		$manifest = Plugin::instance()->directories()->backups() . '/' . $plan['base'] . '.manifest.json';
		rename( $manifest, $manifest . '.moved' );
		$this->err = array();
		$this->assertSame( ExportRun::EXIT_UNCONFIRMED, $this->driver()->run( $id, true, false ) );
		$this->assertSame( array( 'The job completed, but its backup is no longer in backups/.' ), array_values( preg_grep( '/backups\//', $this->err ) ) );
		rename( $manifest . '.moved', $manifest );

		// The work directory was reclaimed since: nothing tells whether the backup is still there.
		$this->reclaim( $id );
		$this->out = array();
		$this->err = array();
		$this->assertSame( ExportRun::EXIT_UNCONFIRMED, $this->driver()->run( $id, true, true ) );
		$this->assertSame( array(), $this->out );
		$this->assertCount( 1, preg_grep( '/^The job completed earlier; its backup can no longer be identified: The file plan\.json of this job is missing/', $this->err ), implode( "\n", $this->err ) );
	}

	public function test_another_installation_in_the_same_database_is_left_out_and_can_be_named_back(): void {
		global $wpdb;
		$this->add_neighbour();
		$old = $wpdb->base_prefix . 'old_';
		list( $code, $id ) = $this->run_export( array( 'yes' => true ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code, implode( "\n", $this->err ) );
		$tables = ExportPlan::read( $this->work( $id ), ExportPlan::PLAN )['tables'];
		foreach ( array_diff( $this->neighbour, array( $old . 'extra' ) ) as $table ) {
			$this->assertNotContains( $table, $tables, $table . ' is the neighbour\'s core' );
		}
		$this->assertContains( $old . 'extra', $tables, 'not certainly the neighbour\'s: stays in' );
		$this->assertContains( $wpdb->posts, $tables, 'this site\'s own tables are there' );
		$this->assertNotContains( Schema::jobs_table(), $tables, 'the plugin\'s own job table is never in a backup' );
		$left = preg_grep( '/^Warning: 8 tables with the prefix ' . preg_quote( $old, '/' ) . ' are the core tables of another WordPress installation in the same database and are not in the backup \(for example /', $this->out );
		$this->assertCount( 1, $left, 'the command lists the left-out tables: ' . implode( "\n", $this->out ) );
		$this->assertStringContainsString( '--include-table', (string) reset( $left ) );
		$kept = preg_grep( '/^Warning: 1 other tables with the prefix ' . preg_quote( $old, '/' ) . ' are in the backup although they may belong to that installation \(for example ' . preg_quote( $old, '/' ) . 'extra\)/', $this->out );
		$this->assertCount( 1, $kept, implode( "\n", $this->out ) );

		// Porcelain: the name alone on standard output, the warnings on standard error.
		list( $code, $id ) = $this->run_export( array( 'yes' => true, 'porcelain' => true ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code );
		$this->assertSame( array( (string) ExportPlan::read( $this->work( $id ), ExportPlan::PLAN )['base'] ), $this->out );
		$this->assertCount( 2, preg_grep( '/^Warning: .* with the prefix ' . preg_quote( $old, '/' ) . '/', $this->err ), implode( "\n", $this->err ) );

		// Named back and named out: exactly those.
		list( $code, $id ) = $this->run_export(
			array(
				'yes'           => true,
				'include-table' => $old . 'options',
				'exclude-table' => $old . 'extra',
			)
		);
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code );
		$tables = ExportPlan::read( $this->work( $id ), ExportPlan::PLAN )['tables'];
		$this->assertContains( $old . 'options', $tables );
		$this->assertNotContains( $old . 'posts', $tables );
		$this->assertNotContains( $old . 'extra', $tables );
		$this->assertCount( 1, preg_grep( '/7 tables with the prefix/', $this->out ) );
		$this->assertCount( 0, preg_grep( '/other tables with the prefix/', $this->out ), 'the one kept table was left out by name' );
	}

	public function test_multisite_sub_sites_are_part_of_the_backup(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
		global $wpdb;
		$site              = self::factory()->blog->create();
		list( $code, $id ) = $this->run_export( array( 'yes' => true ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code, implode( "\n", $this->err ) );
		$tables = ExportPlan::read( $this->work( $id ), ExportPlan::PLAN )['tables'];
		$this->assertContains( $wpdb->get_blog_prefix( $site ) . 'posts', $tables, 'a sub-site is this network, not a neighbour' );
		$this->assertContains( $wpdb->base_prefix . 'users', $tables );
		$this->assertCount( 0, preg_grep( '/look like another WordPress installation/', $this->out ) );
	}

	/**
	 * A heavy directory: the review asks whether to include it.
	 */
	private function add_heavy_directory(): void {
		mkdir( $this->uploads . '/node_modules/pkg', 0755, true );
		foreach ( array( 'one', 'two' ) as $name ) {
			$h = fopen( $this->uploads . '/node_modules/pkg/' . $name . '.bin', 'wb' );
			ftruncate( $h, 30 * 1048576 ); // Sparse: over the review's 50 MB floor, never read (left out).
			fclose( $h );
		}
	}

	public function test_without_a_terminal_the_question_ends_the_run_with_code_6_and_the_answer_command(): void {
		$this->add_heavy_directory();
		list( $code, $id ) = $this->run_export( array() );
		$this->assertSame( RunLoop::EXIT_PAUSED, $code );
		$this->assertSame( Job::PAUSED, Plugin::instance()->jobs()->find( $id )->status );
		$text = implode( "\n", $this->err );
		$this->assertStringContainsString( '[large_dir_0] Directory wp-content/uploads/wpcexport/node_modules holds 60 MB', $text );
		$this->assertStringContainsString( sprintf( "wp wpcheckpoint job answer %d '{\"large_dir_0\":\"include|exclude\"}'", $id ), $text );
		$this->assertStringContainsString( sprintf( 'wp wpcheckpoint job run %d', $id ), $text );
		$this->assertStringContainsString( '--yes', $text );
		$this->assertStringNotContainsString( (string) wp_parse_url( home_url(), PHP_URL_HOST ), $text );
		$this->assertStringNotContainsString( ABSPATH, $text );
		$this->assertSame( array(), preg_grep( '/^Backup written/', $this->out ) );
		// The printed command works: answer, run again, done.
		Plugin::instance()->job_actions()->answer( $id, array( 'large_dir_0' => 'exclude' ) );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $this->driver()->run( $id, true, false ) );
		$this->assertSame( array( 'wp-content/uploads/wpcexport/node_modules' ), ExportPlan::read( $this->work( $id ), ExportPlan::REVIEW )['decisions']['exclude_paths'] );
	}

	public function test_on_a_terminal_the_question_is_asked_and_the_run_goes_on(): void {
		$this->add_heavy_directory();
		$input = fopen( 'php://memory', 'r+b' );
		fwrite( $input, "maybe\nexclude\n" ); // A wrong answer first: asked again.
		rewind( $input );
		list( $code, $id ) = $this->run_export( array(), $input );
		fclose( $input );
		$this->assertSame( RunLoop::EXIT_COMPLETED, $code, implode( "\n", array_merge( $this->out, $this->err ) ) );
		$this->assertCount( 2, preg_grep( '/^Answer \(include \/ exclude\): $/', $this->out ), 'asked twice: the first answer was not a choice' );
		$this->assertSame( array( 'wp-content/uploads/wpcexport/node_modules' ), ExportPlan::read( $this->work( $id ), ExportPlan::REVIEW )['decisions']['exclude_paths'] );
	}
}
