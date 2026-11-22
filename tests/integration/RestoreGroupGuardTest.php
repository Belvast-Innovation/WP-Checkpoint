<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\FileScanStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\PackStep;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Jobs\WorkLost;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Restore\OtherEntries;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\StagingLayout;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A restore does not swap a content group for an empty directory, or leave paths of the backup out, without asking.
 *
 * The export records a group's files under where the group's directory is on the site it runs on; the restore puts
 * a path into a group by its spelling. Where the two disagree (a directory under a name of its own, in another
 * place, or reached through a link) the restore used to complete without a word, the group's directory replaced by
 * an empty one. The cases here are that defect's: each exports the sandbox's site with the plugin's own export
 * steps (the directories given in place of WordPress's), writes a file into every group after the export, and
 * restores. The site's directories are the sandbox's, not real WordPress constants.
 */
final class RestoreGroupGuardTest extends SwapTestCase {

	const NAMED = array( 'plugins', 'themes', 'uploads', 'mu-plugins' );

	/**
	 * The WordPress directory the export takes the site to have ('' for the fixture's, which the content directory
	 * is outside of).
	 *
	 * @var string
	 */
	private $export_abspath = '';

	/** @var array<string, mixed> More parts of the final check. */
	private $check_more = array();

	protected function check_parts( array $more = array() ): array {
		return $this->check_more + parent::check_parts( $more );
	}

	/**
	 * A real export of the sandbox's site as it stands: the plugin's own export steps, the scan and the pack given
	 * the sandbox's directories in place of WordPress's (ScanRoots::resolve_dirs()). Returns the backup's base name.
	 */
	private function real_export(): string {
		global $wpdb;
		$resolved = ScanRoots::resolve_dirs(
			array_merge(
				$this->dirs,
				array(
					'abspath' => '' === $this->export_abspath ? $this->abspath : $this->export_abspath,
					'content' => $this->dirs['other-content'],
				)
			),
			ScanRoots::GROUPS,
			Plugin::instance()->directories()->base()
		);
		$export   = new ExportJob(
			static function () {
				return Plugin::instance()->directories();
			},
			array( Plugin::instance()->job_presenter(), 'clean' )
		);
		$steps    = array();
		foreach ( $export->steps() as $step ) {
			if ( $step instanceof FileScanStep ) {
				$steps[] = new FileScanStep( $resolved['roots'], new Exclusions(), $resolved['warnings'] );
			} elseif ( $step instanceof PackStep ) {
				$steps[] = new PackStep( $resolved['roots'] );
			} else {
				$steps[] = $step;
			}
		}
		$type = 'export_guard_' . bin2hex( random_bytes( 3 ) );
		$this->register( $type, $steps );
		$backups = Plugin::instance()->directories()->backups();
		$before  = (array) glob( $backups . '/*.manifest.json' );
		$jobs    = Plugin::instance()->jobs();
		$job     = $jobs->create(
			$type,
			self::$admin_id,
			array(),
			array(
				'policy' => array(
					'unreadable' => 'continue',
					'oversize'   => 'fail',
					'large_dirs' => 'include',
				),
			)
		);
		$runner  = Plugin::instance()->runner();
		for ( $i = 0; $i < 2000; $i++ ) {
			$this->autocommit(
				static function () use ( $runner, $job ) {
					return $runner->tick( $job->id, microtime( true ) );
				}
			);
			$now = $jobs->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
		}
		$wpdb->query( 'COMMIT' );
		$now = $jobs->find( $job->id );
		$this->assertSame( Job::COMPLETED, $now->status, 'the export completed: ' . $now->last_error );
		$made = array_values( array_diff( (array) glob( $backups . '/*.manifest.json' ), $before ) );
		$this->assertCount( 1, $made, 'one backup was made' );
		return substr( basename( (string) $made[0] ), 0, -strlen( '.manifest.json' ) );
	}

	/**
	 * A backup of the fixture's tables with these files, its manifest's site values changed by $site.
	 *
	 * @param array<string, string> $files Archive path => content.
	 * @param callable|null         $site  function( array $site ): array.
	 */
	private function made_backup( array $files, $site = null ): string {
		global $wpdb;
		$keep = $wpdb->prefix . 'swt_keep';
		$new  = $wpdb->prefix . 'swt_new';
		$gone = $wpdb->prefix . 'swt_gone';
		$this->create( $new, '(id INT UNSIGNED NOT NULL PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB' );
		$wpdb->query( "UPDATE `{$keep}` SET v = 'backup'" );
		$wpdb->query( "INSERT INTO `{$keep}` (id, v) VALUES (3, 'backup')" );
		$wpdb->query( "INSERT INTO `{$new}` (id, v) VALUES (1, 'backup')" );
		$tables = array_values( array_diff( self::live_tables(), array( $gone ) ) );
		// Every group named, as an export of a whole site names them, whether or not it has a file for each.
		$base = $this->backup(
			$tables,
			null,
			$site,
			array(
				'files'  => $files,
				'groups' => StagingLayout::GROUPS,
			)
		);
		$wpdb->query( "DELETE FROM `{$keep}` WHERE id = 3" );
		$wpdb->query( "UPDATE `{$keep}` SET v = 'live'" );
		$wpdb->query( "DROP TABLE `{$new}`" );
		$wpdb->query( 'COMMIT' );
		return $base;
	}

	/**
	 * Tick a restore until it stops (paused, failed, completed) or stands at the swap, which WP-CLI runs.
	 */
	private function drive( Job $job ): Job {
		global $wpdb;
		$runner = Plugin::instance()->runner();
		for ( $i = 0; $i < 500; $i++ ) {
			$result = $this->autocommit(
				static function () use ( $runner, $job ): TickResult {
					return $runner->tick( $job->id, microtime( true ) );
				}
			);
			$now    = Plugin::instance()->jobs()->find( $job->id );
			if ( TickResult::CLI === $result->status || ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
		}
		$wpdb->query( 'COMMIT' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		if ( SwapStep::ID === $now->step && in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
			$this->swap_job = $now;
		}
		return $now;
	}

	/**
	 * A restore of a backup, ticked until it stops or stands at the swap.
	 *
	 * @param string               $base    Backup.
	 * @param array<string, mixed> $options More options.
	 * @param bool                 $drive   Whether to tick it (false: the job as created).
	 */
	private function restore( string $base, array $options = array(), bool $drive = true ): Job {
		global $wpdb;
		$this->swap_parts['mu_dir'] = $this->dirs['mu-plugins'];
		$this->register_type();
		$exclude = array_values( array_diff( self::live_tables(), array_merge( array( $wpdb->prefix . 'swt_keep', $wpdb->prefix . 'swt_gone' ), self::site_tables() ) ) );
		$job     = Plugin::instance()->jobs()->create(
			$this->type,
			self::$admin_id,
			array(),
			array_merge(
				array(
					'base'           => $base,
					'exclude_tables' => $exclude,
				),
				$options
			)
		);
		return $drive ? $this->drive( $job ) : $job;
	}

	/**
	 * Answer a paused restore and tick it on.
	 *
	 * @param Job                   $job     Paused job.
	 * @param array<string, string> $answers Kind => choice.
	 */
	private function answered( Job $job, array $answers ): Job {
		$by_id = array();
		foreach ( $job->questions as $question ) {
			$this->assertArrayHasKey( $question['kind'], $answers, 'an answer for every question asked' );
			$by_id[ $question['id'] ] = $answers[ $question['kind'] ];
		}
		$this->assertNotNull( Plugin::instance()->job_actions()->answer( $job->id, $by_id ) );
		return $this->drive( Plugin::instance()->jobs()->find( $job->id ) );
	}

	/**
	 * The kinds a paused job asks, sorted.
	 *
	 * @return string[]
	 */
	private static function asked( Job $job ): array {
		$kinds = array_column( $job->questions, 'kind' );
		sort( $kinds );
		return $kinds;
	}

	/**
	 * A question of a kind.
	 *
	 * @return array<string, mixed>
	 */
	private function question( Job $job, string $kind ): array {
		foreach ( $job->questions as $question ) {
			if ( $kind === $question['kind'] ) {
				return $question;
			}
		}
		$this->fail( 'no question of kind ' . $kind );
	}

	/**
	 * The question's line as the terminal and the admin page show it.
	 */
	private function words( Job $job, string $kind ): array {
		foreach ( QuestionText::for_job(
			$job,
			Plugin::instance()->directories(),
			static function ( string $text ): string {
				return $text;
			}
		) as $line ) {
			if ( $kind === $line['kind'] ) {
				return $line;
			}
		}
		$this->fail( 'no line for a question of kind ' . $kind );
	}

	/**
	 * Write a file into every group's directory: what the site got after the backup was made.
	 */
	private function after_export(): void {
		foreach ( array_merge( self::NAMED, array( 'other-content' ) ) as $group ) {
			file_put_contents( $this->dirs[ $group ] . '/after-export-' . $group . '.txt', 'written after the export' );
		}
	}

	/**
	 * The job's log.
	 */
	private static function log_of( Job $job ): string {
		return (string) file_get_contents( $job->storage_path . '/' . $job->log_path );
	}

	/**
	 * The paths of the backup the restore found no group for.
	 *
	 * @return string[]
	 */
	private function unmapped( Job $job ): array {
		$out  = array();
		$file = RestoreFiles::path( $this->work( $job ), RestoreFiles::UNMAPPED );
		foreach ( is_file( $file ) ? array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) : array() as $line ) {
			$out[] = (string) json_decode( $line, true )['p'];
		}
		sort( $out );
		return $out;
	}

	/**
	 * The swap run to its end.
	 */
	private function swapped( Job $job ): Job {
		$this->assertSame( SwapStep::ID, $job->step, 'the restore stands at the swap: ' . $job->status . ' ' . $job->last_error . ' ' . wp_json_encode( $job->questions ) );
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		return $done;
	}

	public function test_the_default_layout_is_restored_without_a_question(): void {
		$base = $this->real_export();
		$this->after_export();
		$job = $this->restore( $base );
		$this->assertSame( array(), $job->questions, 'the control: nothing to ask about' );
		$done = $this->swapped( $job );
		foreach ( self::NAMED as $group ) {
			$this->assertFileDoesNotExist( $this->dirs[ $group ] . '/after-export-' . $group . '.txt', 'the group is as the backup has it' );
		}
		$this->assertFileExists( $this->dirs['uploads'] . '/live.txt' );
		$this->assertFileExists( $this->dirs['mu-plugins'] . '/live-mu.php' );
		$this->assertFileExists( $this->dirs['plugins'] . '/live-plugin/live.php' );
		$this->assertFileExists( $this->dirs['themes'] . '/live-theme/style.css' );
		// Other content is swapped entry by entry: what the backup does not have stays.
		$this->assertFileExists( $this->dirs['other-content'] . '/after-export-other-content.txt' );
		$this->assertSame( Job::COMPLETED, $done->status );
	}

	/**
	 * What the control's restore says when it ends. Apart from the control, which asserts only what held before
	 * this change as well.
	 */
	public function test_the_default_layout_restore_says_what_it_did_with_other_content_and_names_nothing_left_out(): void {
		$base = $this->real_export();
		$this->after_export();
		$job = $this->restore( $base );
		$this->assertSame( array(), $job->questions );
		$done = $this->swapped( $job );
		$this->assertStringContainsString( 'only what the backup holds was replaced', (string) $done->progress_message );
		$this->assertStringNotContainsString( 'Not restored', (string) $done->progress_message );
		$this->assertSame( array(), RestoreFilesPreflightStep::staging( $this->work( $done ) )['kept'] );
	}

	public function test_must_use_plugins_under_a_name_of_their_own_are_asked_about_and_kept(): void {
		$content = $this->dirs['other-content'];
		rename( $content . '/mu-plugins', $content . '/my-mu' );
		$this->dirs['mu-plugins'] = $content . '/my-mu';
		$base                     = $this->real_export();
		$this->after_export();
		$before = self::tree( $content . '/my-mu' );
		$job    = $this->restore( $base );
		$this->assertSame( Job::PAUSED, $job->status, 'it asks: ' . $job->last_error );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND, RestoreFilesPreflightStep::UNMAPPED_KIND ), self::asked( $job ) );
		$this->assertSame( array( 'wp-content/my-mu/live-mu.php' ), $this->unmapped( $job ) );
		$lost = $this->question( $job, RestoreFilesPreflightStep::UNMAPPED_KIND );
		$this->assertSame( 1, $lost['count'] );
		$this->assertSame( array( 'skip', 'stop' ), $lost['choices'] );
		$empty = $this->question( $job, RestoreFilesPreflightStep::EMPTY_KIND );
		$this->assertSame( 1, $empty['count'] );
		$this->assertSame( array( 'keep', 'replace' ), $empty['choices'], 'keep comes first' );
		// The question about the group says that paths found no group: they may be its files.
		$file = json_decode( (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::EMPTY_GROUPS ) ), true );
		$this->assertSame( array( 'mu-plugins' ), $file['groups'] );
		$this->assertSame( 1, $file['unmapped'] );
		$words = $this->words( $job, RestoreFilesPreflightStep::EMPTY_KIND );
		$this->assertSame( array( 'mu-plugins' ), $words['listed'] );
		$this->assertStringContainsString( '1 path of the backup belongs to no content directory of this site', $words['text'] );
		$this->assertStringContainsString( 'it may be a file of these very directories', $words['text'] );
		$this->assertSame( array( 'wp-content/my-mu/live-mu.php' ), $this->words( $job, RestoreFilesPreflightStep::UNMAPPED_KIND )['listed'] );
		$this->assertSame( $before, self::tree( $content . '/my-mu' ), 'nothing was changed before the answer' );

		$job = $this->answered(
			$job,
			array(
				RestoreFilesPreflightStep::UNMAPPED_KIND => 'skip',
				RestoreFilesPreflightStep::EMPTY_KIND    => 'keep',
			)
		);
		$staging = RestoreFilesPreflightStep::staging( $this->work( $job ) );
		$this->assertSame( array( 'mu-plugins' ), $staging['kept'] );
		$this->assertNotContains( 'mu-plugins', $staging['staged'] );
		$done = $this->swapped( $job );
		$this->assertSame( $before, self::tree( $content . '/my-mu' ), 'the directory is as it was: not replaced by an empty one' );
		$this->assertFileDoesNotExist( $this->dirs['uploads'] . '/after-export-uploads.txt', 'the control: the other groups were restored' );
		$this->assertStringContainsString( 'Not restored, and left as they were, as you chose: mu-plugins', (string) $done->progress_message );
		$this->assertStringContainsString( '1 path of the backup was not restored', (string) $done->progress_message );
	}

	public function test_must_use_plugins_from_a_name_of_their_own_into_the_default_layout_are_asked_about(): void {
		$content = $this->dirs['other-content'];
		rename( $content . '/mu-plugins', $content . '/my-mu' );
		$this->dirs['mu-plugins'] = $content . '/my-mu';
		$base                     = $this->real_export();
		// The target site: the default layout.
		rename( $content . '/my-mu', $content . '/mu-plugins' );
		$this->dirs['mu-plugins'] = $content . '/mu-plugins';
		$this->after_export();
		$before = self::tree( $content . '/mu-plugins' );
		$job    = $this->restore( $base );
		$this->assertSame( Job::PAUSED, $job->status, 'it asks: ' . $job->last_error );
		// Here the backup's must-use plugins have a place (an entry of the content directory named as on the source
		// site), so no path is without a group; the group itself would still be swapped for an empty directory.
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND ), self::asked( $job ) );
		$this->assertSame( array(), $this->unmapped( $job ) );
		$this->assertStringNotContainsString( 'belongs to no content directory', $this->words( $job, RestoreFilesPreflightStep::EMPTY_KIND )['text'] );
		$done = $this->swapped( $this->answered( $job, array( RestoreFilesPreflightStep::EMPTY_KIND => 'keep' ) ) );
		$this->assertSame( $before, self::tree( $content . '/mu-plugins' ), 'the directory is as it was' );
		$this->assertFileExists( $content . '/my-mu/live-mu.php', 'the backup\'s files are where the backup has them' );
		$this->assertStringContainsString( 'Not restored, and left as they were, as you chose: mu-plugins', (string) $done->progress_message );
	}

	public function test_plugins_under_a_name_of_their_own_are_asked_about_and_stop_stops_before_anything_changes(): void {
		$content = $this->dirs['other-content'];
		rename( $content . '/plugins', $content . '/my-plugins' );
		$this->dirs['plugins'] = $content . '/my-plugins';
		$base                  = $this->real_export();
		$this->after_export();
		$before = self::tree( $content );
		$job    = $this->restore( $base );
		$this->assertSame( Job::PAUSED, $job->status, 'it asks: ' . $job->last_error );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND, RestoreFilesPreflightStep::UNMAPPED_KIND ), self::asked( $job ) );
		$this->assertSame( array( 'wp-content/my-plugins/live-plugin/live.php' ), $this->unmapped( $job ) );
		$this->assertSame( array( 'plugins' ), $this->words( $job, RestoreFilesPreflightStep::EMPTY_KIND )['listed'] );
		$stopped = $this->answered(
			$job,
			array(
				RestoreFilesPreflightStep::UNMAPPED_KIND => 'stop',
				RestoreFilesPreflightStep::EMPTY_KIND    => 'keep',
			)
		);
		$this->assertSame( Job::FAILED, $stopped->status );
		$this->assertStringContainsString( 'The restore was stopped because paths of the backup belong to no content group of this site and would not have been restored.', (string) $stopped->last_error );
		$this->assertSame( $before, self::tree( $content ), 'nothing of the site was changed' );
		// A retry asks what stopped it again; the other answer was the user's and stands.
		Plugin::instance()->job_actions()->retry( $stopped->id );
		$again = $this->drive( Plugin::instance()->jobs()->find( $stopped->id ) );
		$this->assertSame( Job::PAUSED, $again->status, (string) $again->last_error );
		$this->assertSame( array( RestoreFilesPreflightStep::UNMAPPED_KIND ), self::asked( $again ) );
		$mine = self::tree( $content . '/my-plugins' );
		$done = $this->swapped( $this->answered( $again, array( RestoreFilesPreflightStep::UNMAPPED_KIND => 'skip' ) ) );
		$this->assertSame( $mine, self::tree( $content . '/my-plugins' ), 'the plugins directory is as it was' );
		$this->assertStringContainsString( 'as you chose: plugins', (string) $done->progress_message );
	}

	public function test_plugins_from_a_name_of_their_own_into_the_default_layout_are_replaced_only_when_told(): void {
		$content = $this->dirs['other-content'];
		rename( $content . '/plugins', $content . '/my-plugins' );
		$this->dirs['plugins'] = $content . '/my-plugins';
		$base                  = $this->real_export();
		rename( $content . '/my-plugins', $content . '/plugins' );
		$this->dirs['plugins'] = $content . '/plugins';
		$this->after_export();
		$job = $this->restore( $base );
		$this->assertSame( Job::PAUSED, $job->status, 'it asks: ' . $job->last_error );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND ), self::asked( $job ) );
		$this->assertSame( array( 'plugins' ), $this->words( $job, RestoreFilesPreflightStep::EMPTY_KIND )['listed'] );
		$job = $this->answered( $job, array( RestoreFilesPreflightStep::EMPTY_KIND => 'replace' ) );
		$this->assertContains( 'plugins', RestoreFilesPreflightStep::staging( $this->work( $job ) )['staged'] );
		$done = $this->swapped( $job );
		// As told: the directory holds the running plugin's copy and nothing of what was there.
		$this->assertFileDoesNotExist( $content . '/plugins/live-plugin/live.php' );
		$this->assertFileDoesNotExist( $content . '/plugins/after-export-plugins.txt' );
		$this->assertFileExists( $content . '/my-plugins/live-plugin/live.php', 'the backup\'s plugins are where the backup has them' );
		$this->assertStringNotContainsString( 'Not restored', (string) $done->progress_message );
		$this->assertStringContainsString( 'replaced by an empty one, as chosen', self::log_of( $done ) );
	}

	public function test_a_content_directory_inside_wordpress_under_another_name_is_asked_about_when_the_manifest_does_not_say_so(): void {
		// The export takes the sandbox for the WordPress directory, so every path starts with "app/". The manifest
		// records the test site's real directories (the export step reads the constants), not the sandbox's: it does
		// not say that "app" is the content directory, and every path is without a group. The next test is the same
		// backup with a manifest that says it.
		$old = $this->dirs['other-content'];
		$new = $this->sandbox . '/app';
		rename( $old, $new );
		foreach ( $this->dirs as $group => $dir ) {
			$this->dirs[ $group ] = $new . substr( $dir, strlen( $old ) );
		}
		$this->export_abspath = $this->sandbox;
		$base                 = $this->real_export();
		$this->after_export();
		$before = self::tree( $new );
		$job    = $this->restore( $base );
		$this->assertSame( Job::PAUSED, $job->status, 'it asks: ' . $job->last_error );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND, RestoreFilesPreflightStep::UNMAPPED_KIND ), self::asked( $job ) );
		$this->assertSame( self::NAMED, $this->words( $job, RestoreFilesPreflightStep::EMPTY_KIND )['listed'] );
		$lost = $this->unmapped( $job );
		$this->assertContains( 'app/plugins/live-plugin/live.php', $lost );
		$this->assertContains( 'app/uploads/live.txt', $lost );
		$this->assertSame( count( $lost ), $this->question( $job, RestoreFilesPreflightStep::UNMAPPED_KIND )['count'] );
		$job = $this->answered(
			$job,
			array(
				RestoreFilesPreflightStep::UNMAPPED_KIND => 'skip',
				RestoreFilesPreflightStep::EMPTY_KIND    => 'keep',
			)
		);
		$this->assertSame( self::NAMED, RestoreFilesPreflightStep::staging( $this->work( $job ) )['kept'] );
		$done = $this->swapped( $job );
		$this->assertSame( $before, array_intersect_key( self::tree( $new ), $before ), 'no group was replaced by an empty directory' );
		$this->assertStringContainsString( 'as you chose: plugins, themes, uploads, mu-plugins', (string) $done->progress_message );
	}

	public function test_a_backup_whose_manifest_names_its_content_directory_is_read_under_that_name(): void {
		$files = array();
		foreach ( self::backup_files() as $path => $text ) {
			$files[ 'app/' . substr( $path, strlen( 'wp-content/' ) ) ] = $text;
		}
		$base = $this->made_backup(
			$files,
			static function ( array $site ): array {
				$site['abspath']     = '/var/www/html/';
				$site['content_dir'] = '/var/www/html/app';
				return $site;
			}
		);
		$this->after_export();
		$job = $this->restore( $base );
		$this->assertSame( array(), $job->questions, 'nothing to ask about: ' . $job->last_error );
		$this->assertSame( 'app', RestoreFilesPreflightStep::staging( $this->work( $job ) )['prefix'] );
		$this->assertSame( array(), $this->unmapped( $job ) );
		$done = $this->swapped( $job );
		$this->assertFileExists( $this->dirs['plugins'] . '/demo/demo.php' );
		$this->assertFileExists( $this->dirs['themes'] . '/restored/style.css' );
		$this->assertFileExists( $this->dirs['uploads'] . '/2026/10/restored.txt' );
		$this->assertFileExists( $this->dirs['mu-plugins'] . '/restored-mu.php' );
		$this->assertFileExists( $this->dirs['other-content'] . '/languages/restored.mo' );
		foreach ( self::NAMED as $group ) {
			$this->assertFileDoesNotExist( $this->dirs[ $group ] . '/after-export-' . $group . '.txt' );
		}
		$this->assertStringNotContainsString( 'Not restored', (string) $done->progress_message );
		$this->assertStringContainsString( 'under another name', self::log_of( $done ) );
	}

	/**
	 * Manifest values that must not be used to read the backup's paths differently: site.abspath, site.content_dir.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function data_values_not_to_be_used(): array {
		return array(
			'a dot-dot segment in the WordPress directory' => array( '/var/www/other/../html/', '/var/www/html/app' ),
			'a dot-dot segment in the content directory'   => array( '/var/www/html/', '/var/www/html/x/../app' ),
			'a dot segment'                                => array( '/var/www/html/', '/var/www/html/./app' ),
			'neither inside the other'                     => array( '/var/www/html/', '/var/www/shared/app' ),
			'the content directory somewhere else'         => array( '/var/www/html/', '/srv/app' ),
			'the WordPress directory inside the content'   => array( '/var/www/html/app/core/', '/var/www/html/app' ),
			'backslashes'                                  => array( 'C:\\www\\', 'C:\\www\\app' ),
			'relative paths'                               => array( 'www/', 'www/app' ),
		);
	}

	/**
	 * @dataProvider data_values_not_to_be_used
	 */
	public function test_manifest_values_that_are_not_canonical_or_not_one_inside_the_other_read_no_path_differently( string $abspath, string $content ): void {
		$files = array();
		foreach ( self::backup_files() as $path => $text ) {
			$files[ 'app/' . substr( $path, strlen( 'wp-content/' ) ) ] = $text;
		}
		$base   = $this->made_backup(
			$files,
			static function ( array $site ) use ( $abspath, $content ): array {
				$site['abspath']     = $abspath;
				$site['content_dir'] = $content;
				return $site;
			}
		);
		$before = self::tree( $this->dirs['other-content'] );
		$job    = $this->restore( $base );
		$this->assertSame( Job::PAUSED, $job->status, 'it asks: ' . $job->last_error );
		$this->assertSame( '', RestoreFilesPreflightStep::staging( $this->work( $job ) )['prefix'] );
		$expected = array_keys( $files );
		sort( $expected );
		$this->assertSame( $expected, $this->unmapped( $job ), 'every path is without a group: none was read under another name' );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND, RestoreFilesPreflightStep::UNMAPPED_KIND ), self::asked( $job ) );
		$this->assertSame( $before, self::tree( $this->dirs['other-content'] ) );
	}

	public function test_uploads_in_the_wordpress_directory_are_asked_about_and_kept(): void {
		rename( $this->dirs['uploads'], $this->sandbox . '/files' );
		$this->dirs['uploads'] = $this->sandbox . '/files';
		$this->export_abspath  = $this->sandbox;
		$base                  = $this->real_export();
		$this->after_export();
		$before = self::tree( $this->sandbox . '/files' );
		$job    = $this->restore( $base );
		$this->assertSame( Job::PAUSED, $job->status, 'it asks: ' . $job->last_error );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND, RestoreFilesPreflightStep::UNMAPPED_KIND ), self::asked( $job ) );
		$this->assertSame( array( 'files/live.txt' ), $this->unmapped( $job ) );
		$this->assertSame( array( 'uploads' ), $this->words( $job, RestoreFilesPreflightStep::EMPTY_KIND )['listed'] );
		$done = $this->swapped(
			$this->answered(
				$job,
				array(
					RestoreFilesPreflightStep::UNMAPPED_KIND => 'skip',
					RestoreFilesPreflightStep::EMPTY_KIND    => 'keep',
				)
			)
		);
		$this->assertSame( $before, self::tree( $this->sandbox . '/files' ), 'the uploads are as they were' );
		$this->assertStringContainsString( 'as you chose: uploads', (string) $done->progress_message );
	}

	public function test_an_unattended_restore_fails_on_either_question_and_changes_nothing(): void {
		$content = $this->dirs['other-content'];
		$policy  = array(
			'uncertain_tables'        => 'exclude',
			'shared_tables'           => 'exclude',
			LinkedTargets::POLICY_KEY => 'swap',
			'unverified'              => 'keep',
		);
		// Paths without a group only: the must-use plugins of the backup under a name the site keeps other things under.
		$files = self::backup_files();
		$files['outside-the-content-directory.txt'] = 'x';
		$base                                       = $this->made_backup( $files );
		$before                                     = self::tree( $content );
		$job                                        = $this->restore(
			$base,
			array(
				'unattended' => true,
				'policy'     => $policy,
			)
		);
		$this->assertSame( Job::FAILED, $job->status, 'nobody would answer' );
		$this->assertSame( array(), $job->questions );
		$this->assertStringContainsString( 'This restore runs unattended, and it came to a decision only a person can make: whether to go on without the paths of the backup that belong to none of this site\'s content directories', (string) $job->last_error );
		$this->assertSame( $before, self::tree( $content ) );
		$asked = $this->restore( $base, array( 'policy' => $policy ) );
		$this->assertSame( Job::PAUSED, $asked->status, 'the control: attended under the same policy, it is asked' );
		$this->assertSame( array( RestoreFilesPreflightStep::UNMAPPED_KIND ), self::asked( $asked ) );
		Plugin::instance()->job_actions()->cancel( $asked->id );

		// A group without files only.
		$files = self::backup_files();
		unset( $files['wp-content/mu-plugins/restored-mu.php'] );
		$base = $this->made_backup( $files );
		$job  = $this->restore(
			$base,
			array(
				'unattended' => true,
				'policy'     => $policy,
			)
		);
		$this->assertSame( Job::FAILED, $job->status, 'nobody would answer' );
		$this->assertStringContainsString( 'whether to keep or replace the content directories the backup lists no file for while this site\'s hold files', (string) $job->last_error );
		$this->assertSame( $before, self::tree( $content ) );
	}

	public function test_a_group_the_backup_and_the_site_both_have_nothing_in_is_not_asked_about(): void {
		$files = self::backup_files();
		unset( $files['wp-content/mu-plugins/restored-mu.php'] );
		$base = $this->made_backup( $files );
		rename( $this->dirs['mu-plugins'] . '/live-mu.php', $this->sandbox . '/moved-away.php' );
		$job = $this->restore( $base );
		$this->assertSame( array(), $job->questions, 'nothing to lose, nothing to ask: ' . $job->last_error );
		$this->assertContains( 'mu-plugins', RestoreFilesPreflightStep::staging( $this->work( $job ) )['staged'] );
		$this->swapped( $job );
	}

	public function test_a_plugins_directory_that_holds_only_this_plugin_is_not_asked_about(): void {
		$files = self::backup_files();
		unset( $files['wp-content/plugins/demo/demo.php'] );
		$base    = $this->made_backup( $files );
		$plugins = $this->dirs['plugins'];
		// This plugin's own directory there (named as the running plugin's is), and nothing else.
		mkdir( $plugins . '/wp-checkpoint' );
		file_put_contents( $plugins . '/wp-checkpoint/wp-checkpoint.php', '<?php' );
		$asked = $this->restore( $base );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND ), self::asked( $asked ), 'the control: with another plugin there, it is asked about' );
		$this->assertSame( array( 'plugins' ), $this->words( $asked, RestoreFilesPreflightStep::EMPTY_KIND )['listed'] );
		Plugin::instance()->job_actions()->cancel( $asked->id );
		$this->drive( Plugin::instance()->jobs()->find( $asked->id ) );
		rename( $plugins . '/live-plugin', $this->sandbox . '/live-plugin-moved-away' );
		$job = $this->restore( $base );
		$this->assertSame( array(), $job->questions, 'the restore stages this plugin\'s copy whatever the backup holds: nothing is lost' );
		$this->assertSame( SwapStep::ID, $job->step, (string) $job->last_error );
		$this->swapped( $job );
	}

	public function test_an_answer_given_for_groups_that_are_no_longer_asked_about_is_not_used(): void {
		$files = self::backup_files();
		unset( $files['wp-content/mu-plugins/restored-mu.php'] );
		$base = $this->made_backup( $files );
		$job  = $this->restore( $base );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND ), self::asked( $job ) );
		$this->assertStringStartsWith( RestoreFilesPreflightStep::EMPTY_QUESTION . '_', $this->question( $job, RestoreFilesPreflightStep::EMPTY_KIND )['id'], 'the id says which groups' );
		// Between the question and its answer the directory is emptied: there is no group to keep any more.
		rename( $this->dirs['mu-plugins'] . '/live-mu.php', $this->sandbox . '/moved-away.php' );
		$job = $this->answered( $job, array( RestoreFilesPreflightStep::EMPTY_KIND => 'keep' ) );
		$this->assertSame( SwapStep::ID, $job->step, (string) $job->last_error );
		$staging = RestoreFilesPreflightStep::staging( $this->work( $job ) );
		$this->assertSame( array(), $staging['kept'], 'the answer was for a group that is not asked about now' );
		$this->assertContains( 'mu-plugins', $staging['staged'] );
		$this->assertStringContainsString( 'An answer given for other paths or content groups than the restore would now ask about is not used', self::log_of( $job ) );
		$this->swapped( $job );
	}

	public function test_a_run_that_dies_after_the_staging_file_is_written_again_goes_on_to_the_same_end(): void {
		$files = self::backup_files();
		unset( $files['wp-content/mu-plugins/restored-mu.php'] );
		$base                        = $this->made_backup( $files );
		$died                        = 0;
		$this->files_preflight_parts = array(
			'at' => static function ( string $point ) use ( &$died ): void {
				if ( 'kept' === $point && 1 === ++$died ) {
					throw new \RuntimeException( 'simulated: the run is killed here' );
				}
			},
		);
		$before                      = self::tree( $this->dirs['mu-plugins'] );
		$job                         = $this->restore( $base );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND ), self::asked( $job ) );
		$job = $this->answered( $job, array( RestoreFilesPreflightStep::EMPTY_KIND => 'keep' ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'simulated', (string) $job->last_error, 'the control: killed right after the file was written again' );
		$this->assertSame( array( 'mu-plugins' ), RestoreFilesPreflightStep::staging( $this->work( $job ) )['kept'], 'the file is written, the step has not ended' );
		Plugin::instance()->job_actions()->retry( $job->id );
		$job = $this->drive( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( array(), $job->questions, 'the answer is on the job: not asked again' );
		$staging = RestoreFilesPreflightStep::staging( $this->work( $job ) );
		$this->assertSame( array( 'mu-plugins' ), $staging['kept'] );
		$this->assertNotContains( 'mu-plugins', $staging['staged'] );
		$done = $this->swapped( $job );
		$this->assertSame( $before, self::tree( $this->dirs['mu-plugins'] ) );
		$this->assertStringContainsString( 'as you chose: mu-plugins', (string) $done->progress_message );
	}

	/**
	 * The must-use plugins directory reached through a link in the content directory.
	 */
	private function link_the_must_use_plugins(): void {
		$content = $this->dirs['other-content'];
		mkdir( $this->sandbox . '/shared' );
		rename( $content . '/mu-plugins', $this->sandbox . '/shared/mu' );
		symlink( $this->sandbox . '/shared/mu', $content . '/mu-link' );
		$this->dirs['mu-plugins'] = $content . '/mu-link';
	}

	public function test_an_entry_that_is_a_link_to_a_groups_directory_is_not_staged_as_other_content(): void {
		$this->link_the_must_use_plugins();
		$content = $this->dirs['other-content'];
		$files   = array();
		foreach ( self::backup_files() as $path => $text ) {
			$files[ 0 === strpos( $path, 'wp-content/mu-plugins/' ) ? 'wp-content/mu-link/' . substr( $path, strlen( 'wp-content/mu-plugins/' ) ) : $path ] = $text;
		}
		$base   = $this->made_backup( $files );
		$before = self::tree( $this->sandbox . '/shared/mu' );
		$job    = $this->restore( $base, array( 'policy' => array( LinkedTargets::POLICY_KEY => LinkedTargets::SWAP ) ) );
		$this->assertSame( Job::PAUSED, $job->status, 'it asks: ' . $job->last_error );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND, RestoreFilesPreflightStep::UNMAPPED_KIND ), self::asked( $job ) );
		$this->assertSame( array( 'wp-content/mu-link/restored-mu.php' ), $this->unmapped( $job ) );
		$records = OtherEntries::read( RestoreFiles::path( $this->work( $job ), RestoreFiles::OTHER_ENTRIES ) );
		$this->assertSame( array( 'mu-link' ), array_keys( $records ) );
		$this->assertTrue( $records['mu-link']['off'] );
		$this->assertSame( (string) realpath( $this->sandbox . '/shared/mu' ), OtherEntries::place( $records['mu-link']['state'] ) );
		$done = $this->swapped(
			$this->answered(
				$job,
				array(
					RestoreFilesPreflightStep::UNMAPPED_KIND => 'skip',
					RestoreFilesPreflightStep::EMPTY_KIND    => 'keep',
				)
			)
		);
		clearstatcache( true );
		$this->assertTrue( is_link( $content . '/mu-link' ), 'the link is a link still' );
		$this->assertSame( $before, self::tree( $this->sandbox . '/shared/mu' ), 'the directory it leads to is as it was' );
		$this->assertStringContainsString( 'as you chose: mu-plugins', (string) $done->progress_message );
	}

	/**
	 * A backup with an entry "ext" of the content directory, which on this site is a link to a directory elsewhere.
	 */
	private function with_a_link_elsewhere(): string {
		mkdir( $this->sandbox . '/elsewhere' );
		file_put_contents( $this->sandbox . '/elsewhere/kept.txt', 'k' );
		mkdir( $this->sandbox . '/elsewhere-2' );
		symlink( $this->sandbox . '/elsewhere', $this->dirs['other-content'] . '/ext' );
		return $this->made_backup( self::backup_files() + array( 'wp-content/ext/from-the-backup.txt' => 'b' ) );
	}

	public function test_a_link_to_somewhere_else_is_recorded_and_replaced_as_other_content(): void {
		$base = $this->with_a_link_elsewhere();
		$job  = $this->restore( $base );
		$this->assertSame( array(), $job->questions, (string) $job->last_error );
		$records = OtherEntries::read( RestoreFiles::path( $this->work( $job ), RestoreFiles::OTHER_ENTRIES ) );
		$this->assertSame( array( 'ext' ), array_keys( $records ), 'the control: the link was seen and recorded' );
		$this->assertFalse( $records['ext']['off'] );
		$this->swapped( $job );
		clearstatcache( true );
		$this->assertFalse( is_link( $this->dirs['other-content'] . '/ext' ) );
		$this->assertFileExists( $this->dirs['other-content'] . '/ext/from-the-backup.txt' );
		$this->assertFileExists( $this->sandbox . '/elsewhere/kept.txt', 'what the link led to is untouched' );
	}

	public function test_a_recorded_link_pointed_elsewhere_before_the_swap_refuses_it(): void {
		$base = $this->with_a_link_elsewhere();
		$job  = $this->restore( $base );
		$this->assertSame( SwapStep::ID, $job->step, (string) $job->last_error );
		$link = $this->dirs['other-content'] . '/ext';
		rename( $link, $this->sandbox . '/the-link-as-it-was' );
		symlink( $this->sandbox . '/elsewhere-2', $link );
		$before = self::tree( $this->dirs['other-content'] );
		$failed = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $failed->status );
		$this->assertSame( Job::FAILURE_FINAL, $failed->failure_kind );
		$this->assertStringContainsString( 'is not what it was when the restore read the backup\'s files', (string) $failed->last_error );
		$this->assertSame( $before, self::tree( $this->dirs['other-content'] ), 'the swap changed nothing' );
		$this->assertSame( 0, (int) $failed->site_state );
	}

	public function test_a_directory_that_became_a_link_before_the_final_check_refuses_the_swap(): void {
		$content          = $this->dirs['other-content'];
		$base             = $this->made_backup( self::backup_files() );
		$changed          = 0;
		$this->check_more = array(
			// Called by the final check when it lists the staged other content, right before it looks at the entries.
			'scandir' => function ( string $dir ) use ( &$changed, $content ) {
				if ( 1 === ++$changed ) {
					rename( $content . '/languages', $this->sandbox . '/languages-as-it-was' );
					symlink( $this->dirs['mu-plugins'], $content . '/languages' );
				}
				return scandir( $dir );
			},
		);
		$job              = $this->restore( $base );
		$this->assertGreaterThan( 0, $changed, 'the control: the final check listed the staged other content' );
		$this->assertSame( Job::FAILED, $job->status, 'refused: ' . $job->step );
		$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind );
		$this->assertStringContainsString( 'The entry languages of the content directory', (string) $job->last_error );
		$this->assertStringContainsString( 'is not what it was when the restore read the backup\'s files', (string) $job->last_error );
		clearstatcache( true );
		$this->assertTrue( is_link( $content . '/languages' ), 'nothing was swapped' );
		$this->assertFileExists( $this->dirs['mu-plugins'] . '/live-mu.php' );
	}

	public function test_the_final_check_passes_when_no_entry_changed(): void {
		$base             = $this->made_backup( self::backup_files() );
		$listed           = 0;
		$this->check_more = array(
			'scandir' => static function ( string $dir ) use ( &$listed ) {
				++$listed;
				return scandir( $dir );
			},
		);
		$job              = $this->restore( $base );
		$this->assertGreaterThan( 0, $listed, 'the control: the same seam, nothing changed at it' );
		$this->swapped( $job );
	}

	/**
	 * $count entries of the content directory that are links to directories elsewhere in the sandbox, and a backup
	 * with a file in each (first in the index) besides the fixture's files.
	 *
	 * @return string The backup.
	 */
	private function with_links_elsewhere( int $count ): string {
		$files = array();
		mkdir( $this->sandbox . '/targets' );
		for ( $i = 0; $i < $count; $i++ ) {
			mkdir( $this->sandbox . '/targets/t' . $i );
			symlink( $this->sandbox . '/targets/t' . $i, $this->dirs['other-content'] . '/ext-' . $i );
			$files[ 'wp-content/ext-' . $i . '/f.txt' ] = (string) $i;
		}
		return $this->made_backup( $files + self::backup_files() );
	}

	/**
	 * The lines of the record of entries judged by where they are.
	 *
	 * @return string[]
	 */
	private function record_lines( Job $job ): array {
		$file = RestoreFiles::path( $this->work( $job ), RestoreFiles::OTHER_ENTRIES );
		return is_file( $file ) ? array_values( array_filter( explode( "\n", (string) file_get_contents( $file ) ) ) ) : array();
	}

	public function test_a_unit_that_dies_after_recording_an_entry_is_replayed_from_the_committed_length(): void {
		$base                        = $this->with_links_elsewhere( 3 );
		$died                        = 0;
		$this->files_preflight_parts = array(
			'page_lines' => 1,
			'at'         => static function ( string $point ) use ( &$died ): void {
				if ( 'appended' === $point && 2 === ++$died ) {
					throw new \RuntimeException( 'simulated: the run is killed here' );
				}
			},
		);
		$job                         = $this->restore( $base );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'simulated', (string) $job->last_error );
		$this->assertCount( 2, $this->record_lines( $job ), 'the control: the dead run recorded two entries, and its cursor committed neither' );
		$this->assertSame( 0, (int) ( $job->cursor['entries'] ?? -1 ) );
		// A write the death tore: half a line at the end.
		file_put_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::OTHER_ENTRIES ), '{"n":"6578', FILE_APPEND );
		Plugin::instance()->job_actions()->retry( $job->id );
		$job = $this->drive( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( SwapStep::ID, $job->step, (string) $job->last_error );
		$this->assertCount( 3, $this->record_lines( $job ), 'each entry once: the dead run\'s bytes were cut back' );
		$this->assertSame( array( 'ext-0', 'ext-1', 'ext-2' ), array_keys( OtherEntries::read( RestoreFiles::path( $this->work( $job ), RestoreFiles::OTHER_ENTRIES ) ) ) );
	}

	public function test_the_record_cut_shorter_than_committed_fails_the_restore_and_is_not_padded(): void {
		$base                        = $this->with_links_elsewhere( 3 );
		$this->files_preflight_parts = array( 'page_lines' => 1 );
		$job                         = $this->restore( $base, array(), false );
		$runner                      = $this->small_runner();
		$cursor                      = array();
		for ( $i = 0; $i < 400; $i++ ) {
			$this->autocommit(
				static function () use ( $runner, $job ): void {
					$runner->tick( $job->id, microtime( true ) );
				}
			);
			$now    = Plugin::instance()->jobs()->find( $job->id );
			$cursor = $now->cursor;
			if ( RestoreFilesPreflightStep::ID === $now->step && 'index' === ( $cursor['phase'] ?? '' ) && (int) ( $cursor['entries'] ?? 0 ) > 0 ) {
				break;
			}
			$this->assertContains( $now->status, array( Job::QUEUED, Job::RUNNING ), (string) $now->last_error );
		}
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertGreaterThan( 0, (int) ( $cursor['entries'] ?? 0 ), 'the control: stopped inside the index phase, with an entry recorded and committed' );
		$file  = RestoreFiles::path( $this->work( $job ), RestoreFiles::OTHER_ENTRIES );
		$short = (int) $cursor['entries'] - 3;
		$handle = fopen( $file, 'r+b' );
		ftruncate( $handle, $short );
		fclose( $handle );
		$job = $this->drive( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'A work file of the restore is shorter than recorded; the work directory was changed.', (string) $job->last_error );
		clearstatcache();
		$this->assertSame( $short, filesize( $file ), 'not padded' );
	}

	public function test_the_record_cut_at_a_line_or_taken_away_after_it_was_complete_fails_the_restore(): void {
		$base = $this->with_links_elsewhere( 3 );
		$job  = $this->restore( $base );
		$this->assertSame( SwapStep::ID, $job->step, (string) $job->last_error );
		$work = $this->work( $job );
		$file = RestoreFiles::path( $work, RestoreFiles::OTHER_ENTRIES );
		$this->assertCount( 3, RestoreFilesPreflightStep::staging( $work )['entries'], 'the control: the record as the index phase left it is read' );
		$lines = $this->record_lines( $job );
		$whole = (string) file_get_contents( $file );

		// One whole line less: every line left is a record's, and the record is not the one that was left.
		$handle = fopen( $file, 'r+b' );
		ftruncate( $handle, strlen( $whole ) - strlen( $lines[2] ) - 1 );
		fclose( $handle );
		$this->assertCount( 2, OtherEntries::read( $file ), 'the control: what is left reads as a record' );
		try {
			RestoreFilesPreflightStep::staging( $work );
			$this->fail( 'read a record shorter than it was left' );
		} catch ( WorkLost $e ) {
			$this->assertStringContainsString( 'not as long as recorded', $e->getMessage() );
		}

		// As long as it was, with another entry in a line's place.
		file_put_contents( $file, $lines[0] . "\n" . $lines[1] . "\n" . $lines[1] . "\n" );
		$this->assertSame( strlen( $whole ), strlen( (string) file_get_contents( $file ) ), 'the control: the same length' );
		try {
			RestoreFilesPreflightStep::staging( $work );
			$this->fail( 'read a record with fewer entries than it was left with' );
		} catch ( WorkLost $e ) {
			$this->assertStringContainsString( 'not as long as recorded', $e->getMessage() );
		}

		// Taken away (moved aside: tests do not delete): no file would read as "nothing was recorded".
		rename( $file, $this->sandbox . '/record-aside' );
		$this->assertSame( array(), OtherEntries::read( $file ) );
		try {
			RestoreFilesPreflightStep::staging( $work );
			$this->fail( 'read no record where one was left' );
		} catch ( WorkLost $e ) {
			$this->assertStringContainsString( 'not as long as recorded', $e->getMessage() );
		}

		// And the restore itself: the swap does not start.
		$before = self::tree( $this->dirs['other-content'] );
		$done   = $this->cli_run( $job );
		$this->assertSame( Job::FAILED, $done->status );
		$this->assertStringContainsString( 'not as long as recorded', (string) $done->last_error );
		$this->assertSame( $before, self::tree( $this->dirs['other-content'] ), 'nothing was swapped' );
		clearstatcache();
		$this->assertFileDoesNotExist( $file, 'not written again' );
	}

	public function test_a_run_that_dies_after_the_records_length_is_written_goes_on_to_the_same_end(): void {
		$base                        = $this->with_links_elsewhere( 3 );
		$died                        = 0;
		$this->files_preflight_parts = array(
			'page_lines' => 1,
			'at'         => static function ( string $point ) use ( &$died ): void {
				if ( 'entries' === $point && 1 === ++$died ) {
					throw new \RuntimeException( 'simulated: the run is killed here' );
				}
			},
		);
		$job                         = $this->restore( $base );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'simulated', (string) $job->last_error, 'the control: killed right after the length was written' );
		$this->assertSame( 'index', $job->cursor['phase'] ?? '', 'the cursor has not left the index phase' );
		Plugin::instance()->job_actions()->retry( $job->id );
		$job = $this->drive( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( SwapStep::ID, $job->step, (string) $job->last_error );
		$this->assertSame( 2, $died, 'the control: the phase ended a second time' );
		$this->assertSame( array( 'ext-0', 'ext-1', 'ext-2' ), array_keys( RestoreFilesPreflightStep::staging( $this->work( $job ) )['entries'] ) );
	}

	public function test_the_most_entries_are_recorded_and_one_more_is_refused(): void {
		$base = $this->with_links_elsewhere( OtherEntries::MAX_RECORDS );
		$job  = $this->restore( $base );
		$this->assertSame( SwapStep::ID, $job->step, 'as many as the limit get through: ' . $job->last_error );
		$this->assertCount( OtherEntries::MAX_RECORDS, RestoreFilesPreflightStep::staging( $this->work( $job ) )['entries'] );
		Plugin::instance()->job_actions()->cancel( $job->id );
		$this->drive( Plugin::instance()->jobs()->find( $job->id ) );
		$this->swap_job = null;
		$this->tear_down_swap();
		$this->set_up_swap();
		$base = $this->with_links_elsewhere( OtherEntries::MAX_RECORDS + 1 );
		$job  = $this->restore( $base );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'More than ' . OtherEntries::MAX_RECORDS . ' entries of the backup\'s content directory are links on this site', (string) $job->last_error );
	}

	/**
	 * Where WordPress itself puts a directory under a setting, and what the export would call it. The settings are
	 * the real ones (the option, the filter), read through the functions the export reads; the files of the cases
	 * that follow are in the sandbox, in a layout of the same shape: this is not a round trip on a site set up so.
	 *
	 * @param string $group Group.
	 * @return string The archive prefix.
	 */
	private static function exported_as( string $group ): string {
		$given = ScanRoots::site_directories_as_given();
		return ScanRoots::prefix( $group, rtrim( \WPCheckpoint\Support\Paths::normalize( (string) $given[ $group ] ), '/' ), rtrim( \WPCheckpoint\Support\Paths::normalize( ABSPATH ), '/' ), rtrim( \WPCheckpoint\Support\Paths::normalize( WP_CONTENT_DIR ), '/' ) );
	}

	public function test_wordpress_puts_uploads_and_themes_where_the_settings_say_and_the_export_names_them_so(): void {
		$this->assertSame( 'wp-content/uploads', self::exported_as( 'uploads' ), 'the control: the default' );
		$this->assertSame( 'wp-content/themes', self::exported_as( 'themes' ), 'the control: the default' );
		try {
			update_option( 'upload_path', 'files' );
			wp_upload_dir( null, false, true );
			$this->assertSame( 'files', self::exported_as( 'uploads' ), 'a relative upload_path is in the WordPress directory' );
			update_option( 'upload_path', 'wp-content/media' );
			wp_upload_dir( null, false, true );
			$this->assertSame( 'wp-content/media', self::exported_as( 'uploads' ) );
			update_option( 'upload_path', $this->sandbox . '/outside' );
			wp_upload_dir( null, false, true );
			$this->assertSame( 'wp-content/uploads', self::exported_as( 'uploads' ), 'outside both: under the canonical name' );
		} finally {
			delete_option( 'upload_path' );
			wp_upload_dir( null, false, true );
		}
		$root   = '';
		$filter = static function () use ( &$root ): string {
			return $root;
		};
		add_filter( 'theme_root', $filter );
		try {
			$root = WP_CONTENT_DIR . '/my-themes';
			$this->assertSame( 'wp-content/my-themes', self::exported_as( 'themes' ) );
			$root = rtrim( ABSPATH, '/' ) . '/skins';
			$this->assertSame( 'skins', self::exported_as( 'themes' ) );
		} finally {
			remove_filter( 'theme_root', $filter );
		}
	}

	/**
	 * The shapes the settings above give, as sandbox layouts: group, where its directory is (relative to the
	 * sandbox), and the archive prefix of its files.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function data_shapes_of_the_settings(): array {
		return array(
			'upload_path relative: uploads in the WordPress directory' => array( 'uploads', 'files', 'files' ),
			'upload_path in the content directory under another name'  => array( 'uploads', 'wp-content/media', 'wp-content/media' ),
			'theme_root in the content directory under another name'   => array( 'themes', 'wp-content/my-themes', 'wp-content/my-themes' ),
			'theme_root in the WordPress directory'                    => array( 'themes', 'skins', 'skins' ),
		);
	}

	/**
	 * @dataProvider data_shapes_of_the_settings
	 */
	public function test_a_directory_where_such_a_setting_puts_it_is_asked_about_and_kept( string $group, string $where, string $prefix ): void {
		rename( $this->dirs[ $group ], $this->sandbox . '/' . $where );
		$this->dirs[ $group ] = $this->sandbox . '/' . $where;
		$files                = array();
		foreach ( self::backup_files() as $path => $text ) {
			$from = 'wp-content/' . $group . '/';
			$files[ 0 === strpos( $path, $from ) ? $prefix . '/' . substr( $path, strlen( $from ) ) : $path ] = $text;
		}
		$base   = $this->made_backup( $files );
		$before = self::tree( $this->dirs[ $group ] );
		$job    = $this->restore( $base );
		$this->assertSame( Job::PAUSED, $job->status, 'it asks: ' . $job->last_error );
		$this->assertSame( array( RestoreFilesPreflightStep::EMPTY_KIND, RestoreFilesPreflightStep::UNMAPPED_KIND ), self::asked( $job ) );
		$this->assertSame( array( $group ), $this->words( $job, RestoreFilesPreflightStep::EMPTY_KIND )['listed'] );
		$this->assertCount( 1, $this->unmapped( $job ) );
		$this->assertStringStartsWith( $prefix . '/', $this->unmapped( $job )[0] );
		$this->swapped(
			$this->answered(
				$job,
				array(
					RestoreFilesPreflightStep::UNMAPPED_KIND => 'skip',
					RestoreFilesPreflightStep::EMPTY_KIND    => 'keep',
				)
			)
		);
		$this->assertSame( $before, self::tree( $this->dirs[ $group ] ), 'the directory is as it was' );
	}
}
