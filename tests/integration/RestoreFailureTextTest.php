<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobActions;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobPresenter;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\TargetIncompatible;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * The words a failed restore and its steps are shown in: every step of every registered job type has a label, every
 * label and the restore's failure texts go through the translation functions, and a target that cannot take what the
 * backup uses fails the job for good, with the first tables on the card (cleaned) and all of them in the log.
 */
final class RestoreFailureTextTest extends JobTestCase {

	/**
	 * Run $work and return what went through gettext in the plugin's text domain.
	 *
	 * @return string[]
	 */
	private static function translated( callable $work ): array {
		$seen   = array();
		$filter = static function ( $translation, $text, $domain ) use ( &$seen ) {
			if ( 'wp-checkpoint' === $domain ) {
				$seen[] = (string) $text;
			}
			return $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );
		try {
			$work();
		} finally {
			remove_filter( 'gettext', $filter, 10 );
		}
		return $seen;
	}

	public function test_every_step_of_every_registered_job_type_has_a_label_that_is_translated(): void {
		$ids = array();
		foreach ( Plugin::instance()->job_types()->all() as $type ) {
			foreach ( $type->steps() as $step ) {
				$ids[] = $step->id();
			}
		}
		$this->assertContains( 'restore_swap', $ids, 'the control: the restore\'s steps are listed' );
		$this->assertContains( 'database', $ids, 'the control: the backup\'s steps are listed' );
		foreach ( array_unique( $ids ) as $id ) {
			$label = '';
			$seen  = self::translated(
				static function () use ( $id, &$label ): void {
					$label = JobPresenter::step_label( $id );
				}
			);
			$this->assertNotSame( $id, $label, 'a label, not the id: ' . $id );
			$this->assertContains( $label, $seen, 'through the translation functions: ' . $id );
		}
		$this->assertSame( array(), self::translated( static function (): void {
			JobPresenter::step_label( 'another_plugins_step' );
		} ), 'the control: an id it does not know is not passed off as a translated label' );
	}

	public function test_the_restores_failure_texts_and_the_targets_message_are_translated(): void {
		foreach ( array( Job::FAILURE_TEMPORARY, Job::FAILURE_FINAL, '' ) as $kind ) {
			$job               = new Job();
			$job->type         = RestoreJob::ID;
			$job->status       = Job::FAILED;
			$job->failure_kind = $kind;
			$text              = '';
			$seen              = self::translated(
				static function () use ( $job, &$text ): void {
					$text = JobPresenter::failure_text( $job );
				}
			);
			$this->assertContains( $text, $seen, 'kind "' . $kind . '"' );
		}
		$seen = self::translated(
			static function (): void {
				new TargetIncompatible( TargetIncompatible::COLLATION, array( 'a_ci' ), array( 't1', 't2', 't3', 't4', 't5', 't6' ) );
			}
		);
		$this->assertContains( 'This database server cannot take %1$s, which the backup uses. Tables that use it: %2$s.', $seen );
		$this->assertContains( '%1$s and others (%2$d tables in all; the job log lists them all)', $seen );
	}

	public function test_a_target_that_cannot_take_what_the_backup_uses_fails_the_job_for_good_with_the_first_tables_shown_and_all_logged(): void {
		$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$tables = array( $host ); // A table named as the site's host: masked on the card, where it stands alone.
		for ( $i = 1; $i < 230; $i++ ) {
			$tables[] = sprintf( 'wp_table_%03d', $i );
		}
		$this->assertNotSame( '', $host, 'the control: the site has a host name to mask' );
		$this->register(
			'incompatible',
			array(
				new ClosureStep(
					'check',
					static function ( JobContext $context ) use ( $tables ): StepResult {
						throw new TargetIncompatible( TargetIncompatible::COLLATION, array( 'utf8mb4_0900_ai_ci' ), $tables );
					}
				),
			)
		);
		$id = $this->job_of( 'incompatible', false );
		Plugin::instance()->job_actions()->tick( $id, JobActions::NO_TIME_LEFT, false );
		$job = Plugin::instance()->jobs()->find( $id );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertSame( Job::FAILURE_FINAL, $job->failure_kind, 'final: retrying fails the same way' );
		$this->assertFalse( $job->retry_useful() );

		$card = Plugin::instance()->job_presenter()->present( $job, false );
		$this->assertStringContainsString( 'This database server cannot take the collation utf8mb4_0900_ai_ci, which the backup uses.', $card['error_detail'] );
		$this->assertStringContainsString( '{site-host}, wp_table_001, wp_table_002, wp_table_003, wp_table_004 and others (230 tables in all; the job log lists them all)', $card['error_detail'], 'cleaned: the site\'s host is masked, the rest kept' );
		$this->assertStringNotContainsString( 'wp_table_005', $card['error_detail'], 'the first few only' );
		$this->assertStringNotContainsString( $host, $card['error_detail'], 'cleaned: the site\'s host is masked' );
		$this->assertStringContainsString( $host, $job->last_error, 'the control: it is in what was stored' );

		$log = (string) file_get_contents( Plugin::instance()->directories()->base() . '/' . $job->log_path );
		foreach ( array( 'wp_table_001', 'wp_table_100', 'wp_table_229' ) as $table ) {
			$this->assertStringContainsString( $table, $log, 'every table in the log' );
		}
		$this->assertSame( 3, substr_count( $log, 'The database server cannot take what these tables use' ), '230 tables in lines of 100' );
	}
}
