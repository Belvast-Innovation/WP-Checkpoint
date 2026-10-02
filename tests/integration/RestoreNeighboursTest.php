<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Backups\ExportResults;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * Another WordPress installation in the same database ("{base}old_"): a restore does not replace the live tables it
 * uses. One of its own tables is left out; a table that may be either's, and one this site shares with it, are the
 * user's choice, asked by kind or said by the restore's policy. The swap is not built yet: what is asserted is the
 * plan (no temporary table, no entry of the swap plan replaces the neighbour's table) and the neighbour's rows.
 */
final class RestoreNeighboursTest extends RestoreTestCase {

	/** @var string[] Files of the backup this test exported. */
	private $exported = array();

	/** @var int[] Rows this test added to the site's usermeta table. */
	private $meta = array();

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->meta as $id ) {
			$wpdb->delete( $wpdb->usermeta, array( 'umeta_id' => $id ) );
		}
		// The restore committed the test's transaction (RestoreTestCase::run_restore()), and the rows with it: the
		// deletion is committed too, or the rollback after the test would bring them back for the next one.
		$wpdb->query( 'COMMIT' );
		foreach ( $this->exported as $file ) {
			Deleter::delete_tree( dirname( $file ), $file ); // In the storage directory's backups: the plugin's own to delete.
		}
		parent::tear_down();
	}

	/**
	 * Another installation's WordPress tables under "{base}old_", with a row of its own in its options table.
	 *
	 * @return string The neighbour's prefix.
	 */
	private function neighbour(): string {
		global $wpdb;
		$n = $wpdb->base_prefix . 'old_';
		foreach ( array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships' ) as $name ) {
			$this->create( $n . $name, 'LIKE `' . $wpdb->base_prefix . $name . '`' );
		}
		$wpdb->insert(
			$n . 'options',
			array(
				'option_name'  => 'neighbour_marker',
				'option_value' => 'the neighbour\'s own',
				'autoload'     => 'no',
			)
		);
		$this->assertSame( '', $wpdb->last_error );
		return $n;
	}

	/**
	 * The live tables the swap plan of a completed restore replaces or moves.
	 *
	 * @return string[]
	 */
	private function swapped( Job $job ): array {
		global $wpdb;
		$file    = json_decode( (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::SWAP_PLAN ) ), true );
		$db      = ImportSession::open( Credentials::from_wordpress() );
		$entries = ( new SwapPlan( $db, $wpdb->base_prefix . SwapPlan::TABLE ) )->read( $job->id, (int) $file['attempt'], -1, 100000 );
		$db->close();
		$tables = array();
		foreach ( $entries as $row ) {
			if ( SwapPlan::TABLE_OF === $row['kind'] || SwapPlan::MOVE === $row['kind'] ) {
				$tables[] = (string) $row['live'];
			}
		}
		$this->assertContains( $wpdb->base_prefix . 'options', $tables, 'the control: the plan replaces the site\'s tables' );
		return $tables;
	}

	/**
	 * A meta row on the first user (removed after the test).
	 */
	private function role_key( string $key ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->usermeta,
			array(
				'user_id'    => 1,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture.
				'meta_value' => 'a:0:{}', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture.
			)
		);
		$this->assertSame( '', $wpdb->last_error );
		$this->meta[] = (int) $wpdb->insert_id;
	}

	/**
	 * The paused job's questions in words, as the admin and the terminal get them.
	 *
	 * @return array<string, array{id: string, kind: string, choices: string[], text: string, listed: string[]}>
	 */
	private function asked( Job $job ): array {
		$out = array();
		foreach ( QuestionText::for_job( $job, Plugin::instance()->directories(), array( Plugin::instance()->job_presenter(), 'clean' ) ) as $question ) {
			$out[ $question['id'] ] = $question;
		}
		return $out;
	}

	private function answer( Job $job, array $answers ): Job {
		Plugin::instance()->jobs()->answer( Plugin::instance()->jobs()->find( $job->id ), $answers );
		Plugin::instance()->runner()->tick( $job->id, microtime( true ) ); // Answered, it is taken up again (still "paused" until then).
		return $this->run_restore( Plugin::instance()->jobs()->find( $job->id ) );
	}

	/**
	 * The tables of a job (its temporary tables).
	 *
	 * @return string[]
	 */
	private function job_tables( Job $job ): array {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) );
	}

	public function test_a_backup_holding_a_neighbours_tables_restores_without_touching_them(): void {
		global $wpdb;
		$n      = $this->neighbour();
		$theirs = array();
		foreach ( array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships' ) as $name ) {
			$theirs[] = $n . $name;
		}
		// A backup made by the plugin's own export, the way it was before neighbours were left out: with them in.
		$export = $this->run_restore(
			Plugin::instance()->jobs()->create(
				ExportJob::ID,
				self::$admin_id,
				array(),
				array(
					'contents'       => array( 'files' => array() ),
					'include_tables' => $theirs,
					'policy'         => array(
						'unreadable' => 'continue',
						'oversize'   => 'exclude',
						'large_dirs' => 'include',
					),
				)
			)
		);
		$backups = Plugin::instance()->directories()->backups();
		$base    = ExportResults::base_of( $export->id );
		$this->assertNotSame( '', $base, 'the export recorded its backup' );
		$path             = $backups . '/' . $base . '.manifest.json';
		$this->exported[] = $path;
		$manifest         = json_decode( (string) file_get_contents( $path ), true );
		foreach ( (array) ( $manifest['volumes'] ?? array() ) as $volume ) {
			$this->exported[] = $backups . '/' . $volume['path'];
		}
		$this->assertSame( Job::COMPLETED, $export->status, (string) $export->last_error );
		$this->assertContains( $n . 'options', array_column( $manifest['database']['tables'], 'name' ), 'the control: the backup holds the neighbour\'s tables' );

		$before = array();
		foreach ( $theirs as $table ) {
			$before[ $table ] = $this->rows_of( $table );
		}
		$this->assertContains( 'neighbour_marker', array_column( $before[ $n . 'options' ], 'option_name' ), 'the control: the neighbour\'s rows are read' );

		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$plan = RestorePreflightStep::load_plan( $this->work( $job ) )['plan'];
		foreach ( $theirs as $table ) {
			$this->assertSame( 'neighbour', $plan->skipped()[ $table ] ?? null, $table . ': left out as the neighbour\'s' );
			$this->assertNull( $plan->find( $table ) );
			$this->assertArrayNotHasKey( $table, $this->temporary_names( $job ), $table . ': not imported' );
		}
		$this->assertNotNull( $plan->find( $wpdb->base_prefix . 'options' ), 'the control: this site\'s tables are restored' );
		$swapped = $this->swapped( $job );
		foreach ( $theirs as $table ) {
			$this->assertNotContains( $table, $swapped, $table . ': the swap does not replace it' );
			$this->assertSame( $before[ $table ], $this->rows_of( $table ), $table . ': its rows as they were' );
		}
		$this->assertSame( array(), $job->questions, 'nothing asked: they are the neighbour\'s own' );
	}

	public function test_a_table_that_may_be_either_installations_is_asked_about_and_left_out_as_answered(): void {
		$n = $this->neighbour();
		$this->create( $n . 'shop_orders', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$base = $this->backup( array_merge( self::site_tables(), array( $n . 'shop_orders' ) ) );

		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'uncertain_tables' ), array_column( $job->questions, 'id' ), 'that question only' );
		$this->assertSame( 1, $job->questions[0]['count'] );
		$this->assertSame( array( 'restore', 'exclude' ), $job->questions[0]['choices'], 'no default: both choices' );
		$asked = $this->asked( $job );
		$this->assertSame( array( $n . 'shop_orders' ), $asked['uncertain_tables']['listed'], 'the table, from the work file' );
		$this->assertStringContainsString( 'may belong to this site or to another WordPress installation', $asked['uncertain_tables']['text'] );
		$this->assertStringContainsString( 'its data is replaced by the backup\'s', $asked['uncertain_tables']['text'], 'what restoring it does' );
		$this->assertStringContainsString( 'keeps its current data and is not restored', $asked['uncertain_tables']['text'], 'what leaving it out does' );
		$this->assertSame( array(), $this->job_tables( $job ), 'asked before anything was created' );

		$done = $this->answer( $job, array( 'uncertain_tables' => 'exclude' ) );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$plan = RestorePreflightStep::load_plan( $this->work( $done ) )['plan'];
		$this->assertSame( 'uncertain', $plan->skipped()[ $n . 'shop_orders' ] ?? null );
		$this->assertNotContains( $n . 'shop_orders', $this->swapped( $done ) );
	}

	public function test_a_table_that_may_be_either_installations_is_restored_when_so_answered(): void {
		$n = $this->neighbour();
		$this->create( $n . 'shop_orders', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$base = $this->backup( array_merge( self::site_tables(), array( $n . 'shop_orders' ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$done = $this->answer( $job, array( 'uncertain_tables' => 'restore' ) );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$plan = RestorePreflightStep::load_plan( $this->work( $done ) )['plan'];
		$this->assertNotNull( $plan->find( $n . 'shop_orders' ) );
		$this->assertContains( $n . 'shop_orders', $this->swapped( $done ), 'replaced as answered' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function shared_answers(): array {
		return array(
			'restore them' => array( 'restore', '' ),
			'leave them out' => array( 'exclude', 'shared' ),
		);
	}

	/**
	 * @dataProvider shared_answers
	 */
	public function test_users_tables_another_installation_shares_are_asked_about_by_their_role_keys( string $answer, string $skipped ): void {
		global $wpdb;
		$n = $this->neighbour();
		$this->role_key( $n . 'capabilities' ); // The neighbour keeps its users' roles in this site's usermeta table.
		$users = array( $wpdb->users, $wpdb->usermeta );
		$base  = $this->backup( array_merge( self::site_tables(), $users ) );

		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'shared_tables' ), array_column( $job->questions, 'id' ) );
		$this->assertSame( 2, $job->questions[0]['count'] );
		$asked = $this->asked( $job );
		$this->assertSame( $users, $asked['shared_tables']['listed'] );
		$this->assertStringContainsString( 'used by this site and by another WordPress installation', $asked['shared_tables']['text'] );
		$this->assertStringContainsString( 'the other installation\'s data in them is replaced by the backup\'s too', $asked['shared_tables']['text'] );

		$done = $this->answer( $job, array( 'shared_tables' => $answer ) );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$plan    = RestorePreflightStep::load_plan( $this->work( $done ) )['plan'];
		$swapped = $this->swapped( $done );
		foreach ( $users as $table ) {
			if ( '' === $skipped ) {
				$this->assertNotNull( $plan->find( $table ), $table );
				$this->assertContains( $table, $swapped, $table );
			} else {
				$this->assertSame( $skipped, $plan->skipped()[ $table ] ?? null, $table );
				$this->assertNotContains( $table, $swapped, $table );
			}
		}
	}

	public function test_both_kinds_are_asked_at_once_and_each_answer_holds_for_its_own_kind(): void {
		global $wpdb;
		$n = $this->neighbour();
		$this->create( $n . 'shop_orders', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$this->role_key( $n . 'user_level' );
		$base = $this->backup( array_merge( self::site_tables(), array( $n . 'shop_orders', $wpdb->users, $wpdb->usermeta ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'uncertain_tables', 'shared_tables' ), array_column( $job->questions, 'id' ), 'both questions, in one pause' );
		$done = $this->answer(
			$job,
			array(
				'uncertain_tables' => 'restore',
				'shared_tables'    => 'exclude',
			)
		);
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$plan = RestorePreflightStep::load_plan( $this->work( $done ) )['plan'];
		$this->assertNotNull( $plan->find( $n . 'shop_orders' ), 'restored, as answered for its kind' );
		$this->assertSame( 'shared', $plan->skipped()[ $wpdb->users ] ?? null, 'left out, as answered for its kind' );
		$this->assertSame( 'shared', $plan->skipped()[ $wpdb->usermeta ] ?? null );
	}

	public function test_role_keys_of_a_sub_site_or_of_no_neighbour_are_not_asked_about(): void {
		global $wpdb;
		$this->neighbour();
		if ( is_multisite() ) {
			$blog = self::factory()->blog->create();
			$this->role_key( $wpdb->get_blog_prefix( $blog ) . 'capabilities' ); // A sub-site of this network.
		} else {
			// A sub-site's form of key on a single site, of a number no tables here have (a database shared with
			// multisite test runs may hold whole "{base}2_" sets, which are an installation here).
			for ( $number = 900; array() !== $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix . $number . '_' ) . '%' ) ); $number++ ) {
				$this->assertLessThan( 1000, $number, 'a number without tables' );
			}
			$this->role_key( $wpdb->base_prefix . $number . '_capabilities' ); // No such installation here.
		}
		$this->role_key( $wpdb->base_prefix . 'new_capabilities' ); // A prefix no installation here has.
		$base = $this->backup( array_merge( self::site_tables(), array( $wpdb->users, $wpdb->usermeta ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error . wp_json_encode( $job->questions ) );
		$this->assertSame( array(), $job->questions );
		$this->assertContains( $wpdb->usermeta, $this->swapped( $job ), 'restored as this site\'s' );
	}

	public function test_an_unattended_restore_that_does_not_say_both_is_refused_before_anything(): void {
		$n = $this->neighbour();
		$this->create( $n . 'shop_orders', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$base = $this->backup( array_merge( self::site_tables(), array( $n . 'shop_orders' ) ) );
		$job  = $this->run_restore(
			$this->start_restore(
				$base,
				array(
					'unattended' => true,
					'policy'     => array( 'shared_tables' => 'exclude' ),
				)
			)
		);
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'An unattended restore must say what to do with tables that may belong to this site or to another installation', (string) $job->last_error );
		$this->assertStringContainsString( '"uncertain_tables"', (string) $job->last_error );
		$this->assertSame( array(), $this->job_tables( $job ), 'nothing created' );
		$this->assertFileDoesNotExist( RestoreFiles::path( $this->work( $job ), RestoreFiles::MANIFEST ), 'refused before the backup was even checked' );

		// The control: both said, nothing is asked and the policy is followed.
		$done = $this->run_restore(
			$this->start_restore(
				$base,
				array(
					'unattended' => true,
					'policy'     => array(
						'uncertain_tables' => 'exclude',
						'shared_tables'    => 'exclude',
					),
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$this->assertFileExists( RestoreFiles::path( $this->work( $done ), RestoreFiles::MANIFEST ), 'the control: a restore that goes on checks the backup' );
		$this->assertSame( array(), $done->questions );
		$this->assertSame( 'uncertain', RestorePreflightStep::load_plan( $this->work( $done ) )['plan']->skipped()[ $n . 'shop_orders' ] ?? null );
	}

	public function test_a_neighbours_key_to_a_table_the_restore_replaces_stops_it(): void {
		global $wpdb;
		$n    = $this->neighbour();
		$mine = $wpdb->base_prefix . 'wpcr_shop';
		$this->create( $mine, '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		// One of the neighbour's own tables: it stays, and its key would follow this site's table aside.
		$this->create( $n . 'links', "(`id` int NOT NULL, `shop_id` int, PRIMARY KEY (`id`), CONSTRAINT `fk_old_links_shop` FOREIGN KEY (`shop_id`) REFERENCES `{$mine}` (`id`)) ENGINE=InnoDB" );
		$base = $this->backup( array_merge( self::site_tables(), array( $mine ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( "The table {$n}links stays as it is, but its foreign key fk_old_links_shop references {$mine}", (string) $job->last_error );

		// The control: without that key the same backup goes through.
		$wpdb->query( "ALTER TABLE `{$n}links` DROP FOREIGN KEY `fk_old_links_shop`" );
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->start_restore( $base ) )->status );
	}

	public function test_a_restored_key_to_a_neighbours_table_does_not_stop_the_restore(): void {
		global $wpdb;
		$n    = $this->neighbour();
		$mine = $wpdb->base_prefix . 'wpcr_order';
		$this->create( $mine, "(`id` int NOT NULL, `post_id` bigint(20) unsigned, PRIMARY KEY (`id`), CONSTRAINT `fk_wpcr_order_post` FOREIGN KEY (`post_id`) REFERENCES `{$n}posts` (`ID`)) ENGINE=InnoDB" );
		$base = $this->backup( array_merge( self::site_tables(), array( $mine ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, 'the neighbour\'s table stays where the key points: ' . $job->last_error );
		$names = $this->temporary_names( $job );
		$this->assertSame(
			$n . 'posts',
			$wpdb->get_var( $wpdb->prepare( 'SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL', $names[ $mine ] ) ),
			'the restored key references the neighbour\'s live table'
		);
	}
}
