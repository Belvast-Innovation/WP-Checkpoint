<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Backups\ExportResults;
use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\QuestionText;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Restore\IncomingQuestions;
use WPCheckpoint\Restore\SiteTables;
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
			$out[ (string) array_search( $question['kind'], IncomingQuestions::QUESTION_KINDS, true ) ] = $question;
		}
		return $out;
	}

	/**
	 * The policy keys of a job's questions (their ids carry a digest of their tables).
	 *
	 * @return string[]
	 */
	private static function keys_of( Job $job ): array {
		$out = array();
		foreach ( $job->questions as $question ) {
			$key = (string) array_search( $question['kind'], IncomingQuestions::QUESTION_KINDS, true );
			self::assertStringStartsWith( $key . '_', (string) $question['id'], 'its id names its kind' );
			$out[] = $key;
		}
		return $out;
	}

	/**
	 * Answers by policy key, under the ids of the questions the job asks now.
	 *
	 * @param array<string, string> $by_key Policy key => choice.
	 * @return array<string, string>
	 */
	private static function ids_for( Job $job, array $by_key ): array {
		$out = array();
		foreach ( $job->questions as $question ) {
			$key = (string) array_search( $question['kind'], IncomingQuestions::QUESTION_KINDS, true );
			if ( isset( $by_key[ $key ] ) ) {
				$out[ (string) $question['id'] ] = $by_key[ $key ];
			}
		}
		return $out;
	}

	private function answer( Job $job, array $answers ): Job {
		Plugin::instance()->jobs()->answer( Plugin::instance()->jobs()->find( $job->id ), self::ids_for( Plugin::instance()->jobs()->find( $job->id ), $answers ) );
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
		$this->assertSame( array( 'uncertain_tables' ), self::keys_of( $job ), 'that question only' );
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
		$this->assertNotSame( array(), $this->job_tables( $done ), 'the control: a job\'s temporary tables are found' );
		$plan = RestorePreflightStep::load_plan( $this->work( $done ) )['plan'];
		$this->assertSame( 'uncertain', $plan->skipped()[ $n . 'shop_orders' ] ?? null );
		$this->assertNotContains( $n . 'shop_orders', $this->swapped( $done ) );
	}

	public function test_an_answer_holds_only_for_the_tables_it_was_given_for(): void {
		global $wpdb;
		$n = $this->neighbour();
		$this->create( $n . 'shop_orders', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$this->create( $n . 'shop_items', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$base = $this->backup( array_merge( self::site_tables(), array( $n . 'shop_orders', $n . 'shop_items' ) ) );
		$wpdb->query( "DROP TABLE `{$n}shop_items`" ); // Not here when the question is asked.

		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( $n . 'shop_orders' ), $this->asked( $job )['uncertain_tables']['listed'], 'the control: asked about the one table' );

		// Answered "restore" for that table; meanwhile the neighbour gets another table the backup holds.
		Plugin::instance()->jobs()->answer( Plugin::instance()->jobs()->find( $job->id ), self::ids_for( $job, array( 'uncertain_tables' => 'restore' ) ) );
		$this->create( $n . 'shop_items', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		Plugin::instance()->runner()->tick( $job->id, microtime( true ) );
		$again = $this->run_restore( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::PAUSED, $again->status, 'asked again: the answer was for other tables: ' . $again->last_error );
		$this->assertSame( array( 'uncertain_tables' ), self::keys_of( $again ) );
		$this->assertSame( 2, $again->questions[0]['count'] );
		$this->assertSame( array( $n . 'shop_orders', $n . 'shop_items' ), $this->asked( $again )['uncertain_tables']['listed'], 'the tables as they are now' );
		$this->assertSame( array(), $this->job_tables( $again ), 'nothing created on the answer meant for one table' );

		$done = $this->answer( $again, array( 'uncertain_tables' => 'exclude' ) );
		$this->assertSame( Job::COMPLETED, $done->status, (string) $done->last_error );
		$plan = RestorePreflightStep::load_plan( $this->work( $done ) )['plan'];
		$this->assertSame( 'uncertain', $plan->skipped()[ $n . 'shop_orders' ] ?? null );
		$this->assertSame( 'uncertain', $plan->skipped()[ $n . 'shop_items' ] ?? null );
	}

	public function test_a_question_lists_its_tables_only_from_a_file_that_lists_the_tables_its_id_names(): void {
		$n = $this->neighbour();
		$this->create( $n . 'shop_orders', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$base = $this->backup( array_merge( self::site_tables(), array( $n . 'shop_orders' ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( $n . 'shop_orders' ), $this->asked( $job )['uncertain_tables']['listed'], 'the control: the file the question was asked with' );
		// Another run's file (one that outlived its lease) lists other tables than the question's id names.
		$path            = RestoreFiles::path( $this->work( $job ), RestoreFiles::INCOMING );
		$file            = json_decode( (string) file_get_contents( $path ), true );
		$file['uncertain'] = array( $n . 'shop_other' );
		file_put_contents( $path, (string) wp_json_encode( $file ) );
		$asked = $this->asked( $job )['uncertain_tables'];
		$this->assertSame( array(), $asked['listed'], 'not shown as the tables the question is about' );
		$this->assertStringContainsString( 'may belong to this site or to another WordPress installation', $asked['text'], 'the question itself is still shown' );
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
		$this->assertSame( array( 'shared_tables' ), self::keys_of( $job ) );
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

	public function test_a_role_key_spelt_in_another_case_is_found_as_the_column_compares_it(): void {
		global $wpdb;
		$n = $this->neighbour();
		// As a neighbour's wp-config.php may spell its prefix where the server lists its tables lowercased.
		$this->role_key( strtoupper( $n ) . 'capabilities' );
		$base = $this->backup( array_merge( self::site_tables(), array( $wpdb->users, $wpdb->usermeta ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'shared_tables' ), self::keys_of( $job ) );
	}

	public function test_both_kinds_are_asked_at_once_and_each_answer_holds_for_its_own_kind(): void {
		global $wpdb;
		$n = $this->neighbour();
		$this->create( $n . 'shop_orders', '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$this->role_key( $n . 'capabilities' );
		$base = $this->backup( array_merge( self::site_tables(), array( $n . 'shop_orders', $wpdb->users, $wpdb->usermeta ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'uncertain_tables', 'shared_tables' ), self::keys_of( $job ), 'both questions, in one pause' );
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

	public function test_the_usermeta_table_this_site_uses_is_the_one_looked_in(): void {
		global $wpdb;
		$n      = $this->neighbour();
		$custom = $wpdb->base_prefix . 'wpcr_membermeta';
		$this->create( $custom, 'LIKE `' . $wpdb->usermeta . '`' );
		$wpdb->insert(
			$custom,
			array(
				'user_id'    => 1,
				'meta_key'   => $n . 'capabilities', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture.
				'meta_value' => 'a:0:{}', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture.
			)
		);
		$this->assertSame( '', $wpdb->last_error );
		$default = $wpdb->usermeta;
		$base    = $this->backup( array_merge( self::site_tables(), array( $wpdb->users, $default, $custom ) ) );

		// The control: the site uses the usual usermeta table, where no neighbour keeps roles: nothing to ask.
		$job = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error . wp_json_encode( $job->questions ) );

		// The site uses the other table (as CUSTOM_USER_META_TABLE makes WordPress do): the neighbour's keys are there.
		$wpdb->usermeta = $custom;
		try {
			$asked = $this->run_restore( $this->start_restore( $base ) );
		} finally {
			$wpdb->usermeta = $default;
		}
		$this->assertSame( Job::PAUSED, $asked->status, (string) $asked->last_error );
		$this->assertSame( array( 'shared_tables' ), self::keys_of( $asked ) );
		$this->assertSame( array( $wpdb->users, $custom ), $this->asked( $asked )['shared_tables']['listed'], 'the users table and the usermeta table the site uses' );
	}

	public function test_only_this_sites_own_keys_and_its_sites_are_not_asked_about(): void {
		global $wpdb;
		$this->neighbour();
		if ( is_multisite() ) {
			$blog = self::factory()->blog->create();
			$this->role_key( $wpdb->get_blog_prefix( $blog ) . 'capabilities' ); // A site of this network.
		}
		$base = $this->backup( array_merge( self::site_tables(), array( $wpdb->users, $wpdb->usermeta ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error . wp_json_encode( $job->questions ) );
		$this->assertSame( array(), $job->questions );
		$this->assertContains( $wpdb->usermeta, $this->swapped( $job ), 'restored as this site\'s' );
		// The control: a key of any other prefix is looked at, and asked about.
		$this->role_key( $wpdb->base_prefix . 'new_capabilities' );
		$asked = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $asked->status, (string) $asked->last_error );
		$this->assertSame( array( 'shared_tables' ), self::keys_of( $asked ) );
	}

	public function test_another_installation_whose_prefix_is_not_under_this_sites_is_found_by_its_keys(): void {
		global $wpdb;
		$this->role_key( 'wp2_capabilities' ); // An installation "wp2_" with CUSTOM_USER_TABLE and CUSTOM_USER_META_TABLE set to this site's.
		$base = $this->backup( array_merge( self::site_tables(), array( $wpdb->users, $wpdb->usermeta ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'shared_tables' ), self::keys_of( $job ) );
		$asked = $this->asked( $job )['shared_tables'];
		$this->assertSame( array( $wpdb->users, $wpdb->usermeta ), $asked['listed'] );
		$this->assertStringContainsString( 'with the table prefix wp2_', $asked['text'], 'the evidence: the prefix found' );
	}

	public function test_a_key_of_a_site_number_this_network_does_not_have_is_asked_about(): void {
		global $wpdb;
		$number = 2;
		if ( is_multisite() ) {
			// A number past every site this network has.
			$number = 1 + (int) $wpdb->get_var( "SELECT MAX(blog_id) FROM {$wpdb->blogs}" );
		}
		$this->role_key( $wpdb->base_prefix . $number . '_capabilities' ); // On a single site, "wp_2_" is not one of its sites.
		$base = $this->backup( array_merge( self::site_tables(), array( $wpdb->users, $wpdb->usermeta ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'shared_tables' ), self::keys_of( $job ) );
		$this->assertStringContainsString( 'with the table prefix ' . $wpdb->base_prefix . $number . '_', $this->asked( $job )['shared_tables']['text'] );
	}

	public function test_more_other_installations_than_can_be_told_apart_are_asked_about(): void {
		global $wpdb;
		for ( $i = 0; $i <= RestorePreflightStep::MAX_EVIDENCE; $i++ ) {
			$this->role_key( 'other' . $i . '_capabilities' );
		}
		$base = $this->backup( array_merge( self::site_tables(), array( $wpdb->users, $wpdb->usermeta ) ) );
		$job  = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( Job::PAUSED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'shared_tables' ), self::keys_of( $job ) );
		$this->assertStringContainsString( 'more other installations than could be told apart', $this->asked( $job )['shared_tables']['text'] );
	}

	public function test_the_walk_over_the_user_table_shows_its_progress_and_goes_on_across_requests(): void {
		global $wpdb;
		// Rows far apart: each is a window of its own, and the walk jumps the gaps.
		$last = (int) $wpdb->get_var( "SELECT MAX(umeta_id) FROM {$wpdb->usermeta}" );
		foreach ( array( 1, 2, 3 ) as $step ) {
			$wpdb->insert(
				$wpdb->usermeta,
				array(
					'umeta_id'   => $last + $step * 3 * RestorePreflightStep::META_WINDOW,
					'user_id'    => 1,
					'meta_key'   => 3 === $step ? 'far_capabilities' : 'wpc_far_' . $step, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a test fixture.  The last window holds another installation's key.
					'meta_value' => 'x', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- a test fixture.
				)
			);
			$this->assertSame( '', $wpdb->last_error );
			$this->meta[] = (int) $wpdb->insert_id;
		}
		$base   = $this->backup( array_merge( self::site_tables(), array( $wpdb->users, $wpdb->usermeta ) ) );
		$job    = $this->start_restore( $base );
		$runner = $this->small_runner();
		$shown  = array();
		$waited = false;
		// Short ticks until the walk is over (the step's next phase), then the rest as usual.
		for ( $i = 0; $i < 300; $i++ ) {
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) || ( RestorePreflightStep::ID === $now->step && 'plan' !== ( $now->cursor['phase'] ?? 'plan' ) ) ) {
				break;
			}
			if ( RestorePreflightStep::ID === $now->step ) {
				$shown[] = $now->progress_message;
				if ( 'plan' === ( $now->cursor['phase'] ?? '' ) && ! empty( $now->cursor['meta']['done'] ) ) {
					$waited = true; // The walk ended in a tick with no time left: the rest of the plan waited for the next.
				}
			}
			$runner->tick( $job->id, microtime( true ) );
		}
		$this->assertTrue( $waited, 'the plan after the walk waits for a tick with time' );
		$done = $this->run_restore( Plugin::instance()->jobs()->find( $job->id ) );
		$this->assertSame( Job::PAUSED, $done->status, (string) $done->last_error );
		$this->assertStringContainsString( 'with the table prefix far_', $this->asked( $done )['shared_tables']['text'], 'the walk went on to the last window' );
		$walking = array_filter(
			$shown,
			static function ( string $message ): bool {
				return false !== strpos( $message, 'Looking for other installations\' users' );
			}
		);
		$this->assertGreaterThanOrEqual( 2, count( $walking ), 'shown on the page while the walk goes on, over more than one request: ' . wp_json_encode( array_values( array_unique( $shown ) ) ) );
	}

	public function test_a_server_that_folds_case_refuses_a_backup_with_an_upper_case_table_name(): void {
		global $wpdb;
		$mixed = $wpdb->base_prefix . 'Shop';
		$this->create( $mixed, '(`id` int NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB' );
		$base = $this->backup( array_merge( self::site_tables(), array( $mixed ) ) );
		$this->assertSame( Job::COMPLETED, $this->run_restore( $this->start_restore( $base ) )->status, 'the control: where names keep their case, it is restored' );
		$fold = new \ReflectionProperty( SiteTables::class, 'fold_case_in_tests' );
		$fold->setAccessible( true );
		$fold->setValue( null, true );
		try {
			$job = $this->run_restore( $this->start_restore( $base ) );
		} finally {
			$fold->setValue( null, null );
		}
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'compares table names without letter case', (string) $job->last_error );
		$this->assertStringContainsString( "the backup's table {$mixed} has upper-case letters", (string) $job->last_error );
		$this->assertSame( array(), $this->job_tables( $job ), 'nothing created' );
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
		$this->assertNotSame( array(), $this->job_tables( $done ), 'the control: its temporary tables are found' );
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
