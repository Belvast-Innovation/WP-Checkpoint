<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\PrefixRewriteStep;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * A backup made with another table prefix: the rows WordPress names after the prefix renamed for this
 * site's in the restored tables, a plugin's per-user settings copied, rows already under this site's
 * prefix removed, and the rest reported; the same through every interruption, and nothing by a run that
 * lost the job.
 */
final class PrefixRewriteTest extends RestoreTestCase {

	/**
	 * This site's prefix.
	 */
	private static function q(): string {
		global $wpdb;
		return $wpdb->base_prefix;
	}

	/**
	 * Tables of a site with prefix $p, as a backup of it would hold them; the backup's name.
	 *
	 * @param string                               $p        The backup's prefix.
	 * @param array<int, array<int, int|string>> $usermeta Rows: user, key, value, and a primary key of its own.
	 * @param array<string, string>                $options  The main site's options: name => value.
	 * @param array<int, array<string, string>|null> $sites  Other sites of a network: id => their options (null: no options table).
	 * @param string                               $engine   usermeta's storage engine.
	 * @param string                               $charset  usermeta's character set ('' as this site's).
	 * @param string                               $alter    An ALTER TABLE of usermeta after its rows ('' none).
	 */
	private function backup_with_prefix( string $p, array $usermeta, array $options, array $sites = array(), string $engine = 'InnoDB', string $charset = '', string $alter = '' ): string {
		global $wpdb;
		$q = self::q();
		$this->create( $p . 'options', "LIKE `{$q}options`" );
		$wpdb->query( "INSERT INTO `{$p}options` SELECT * FROM `{$q}options` WHERE option_name IN ('siteurl', 'home', 'active_plugins')" );
		foreach ( $options as $name => $value ) {
			$wpdb->insert( $p . 'options', array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'on' ) );
		}
		$this->create( $p . 'usermeta', "LIKE `{$q}usermeta`" );
		$wpdb->query( "ALTER TABLE `{$p}usermeta` ENGINE={$engine}" );
		if ( '' !== $charset ) {
			$wpdb->query( "ALTER TABLE `{$p}usermeta` CONVERT TO CHARACTER SET {$charset}" );
		}
		foreach ( $usermeta as $row ) {
			$wpdb->insert( $p . 'usermeta', array( 'user_id' => $row[0], 'meta_key' => $row[1], 'meta_value' => $row[2] ) );
			if ( isset( $row[3] ) ) { // A key of its own (0: an update, as an insert of 0 takes the next one).
				$this->assertSame( 1, (int) $wpdb->update( $p . 'usermeta', array( 'umeta_id' => $row[3] ), array( 'umeta_id' => $wpdb->insert_id ) ) );
			}
		}
		if ( '' !== $alter ) {
			$wpdb->query( "ALTER TABLE `{$p}usermeta` {$alter}" );
			$this->assertSame( '', $wpdb->last_error );
		}
		$tables = array( $p . 'options', $p . 'usermeta' );
		if ( is_multisite() ) {
			$this->create( $p . 'sitemeta', "LIKE `{$q}sitemeta`" );
			$wpdb->query( "INSERT INTO `{$p}sitemeta` SELECT * FROM `{$q}sitemeta`" );
			$this->create( $p . 'blogs', "LIKE `{$q}blogs`" );
			$wpdb->insert( $p . 'blogs', array( 'blog_id' => 1, 'site_id' => 1, 'domain' => 'example.org', 'path' => '/' ) );
			$tables[] = $p . 'sitemeta';
			$tables[] = $p . 'blogs';
			foreach ( $sites as $id => $site_options ) {
				$wpdb->insert( $p . 'blogs', array( 'blog_id' => $id, 'site_id' => 1, 'domain' => 'example.org', 'path' => '/s' . $id . '/' ) );
				if ( null === $site_options ) {
					continue;
				}
				$this->create( $p . $id . '_options', "LIKE `{$q}options`" );
				foreach ( $site_options as $name => $value ) {
					$wpdb->insert( $p . $id . '_options', array( 'option_name' => $name, 'option_value' => $value, 'autoload' => 'on' ) );
				}
				$tables[] = $p . $id . '_options';
			}
		}
		return $this->backup(
			$tables,
			null,
			static function ( array $site ) use ( $p ): array {
				$site['table_prefix'] = $p;
				return $site;
			}
		);
	}

	/**
	 * A restored table's rows: usermeta as sorted [user, key, value], options as name => value.
	 *
	 * @return array<int|string, mixed>
	 */
	private function restored( Job $job, string $table ): array {
		global $wpdb;
		$temp = $this->temporary_names( $job )[ $table ];
		$GLOBALS['wpdb']->query( 'COMMIT' );
		if ( 'usermeta' === substr( $table, -8 ) ) {
			$rows = (array) $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM `{$temp}` ORDER BY user_id, meta_key, meta_value", ARRAY_N );
			return array_map(
				static function ( array $row ): array {
					return array( (int) $row[0], (string) $row[1], (string) $row[2] );
				},
				$rows
			);
		}
		return array_column( (array) $wpdb->get_results( "SELECT option_name, option_value FROM `{$temp}` ORDER BY option_name", ARRAY_N ), 1, 0 );
	}

	private function report( Job $job ): array {
		return json_decode( (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::PREFIX_REPORT ) ), true );
	}

	/**
	 * The standard backup: every kind of row the rewrite treats.
	 */
	private function standard( string $engine = 'InnoDB' ): string {
		$p = 'wpx_';
		$q = self::q();
		return $this->backup_with_prefix(
			$p,
			array(
				array( 1, $p . 'capabilities', 'admin' ),
				array( 1, $p . 'user_level', '10' ),
				array( 1, $p . 'user-settings', 'libraryContent=browse' ),
				array( 1, $p . 'persisted_preferences', 'prefs' ),
				array( 1, $p . 'usersettings', 'old name' ),
				array( 1, 'nickname', 'admin' ),
				array( 1, $p . 'myplugin_pref', 'mine' ),
				array( 2, $p . 'other', 'from the backup' ),
				array( 2, $q . 'other', 'already there' ),
				array( 3, $q . 'capabilities', 'inert' ),
				array( 3, $p . 'capabilities', 'subscriber' ),
				array( 1, $p . '5_capabilities', 'site five' ),
				array( 1, $p . '9_capabilities', 'no such site' ),
				array( 1, 'wpxAother', 'decoy' ), // Matches "wpx_%" only where "_" is a wildcard.
			),
			array(
				$p . 'user_roles'     => 'roles of the backup',
				$q . 'user_roles'     => 'inert roles',
				$p . 'plugin_setting' => 'x',
				'blogname'            => 'Site',
			),
			array(
				5 => array(
					$p . '5_user_roles' => 'roles of site five',
					$p . '5_plugin'     => 'y',
				),
			),
			$engine
		);
	}

	/**
	 * A restore type whose rewrite has these sizes and crash seam.
	 *
	 * @param array<string, int> $sizes Sizes.
	 */
	private function type_with( array $sizes, $at = null ): string {
		$type = 'restore_prefix_' . bin2hex( random_bytes( 3 ) );
		$this->register( $type, self::restore_steps_with( new PrefixRewriteStep( null, $at, $sizes ) ) );
		return $type;
	}

	/**
	 * The smallest sizes: a row, two ids, a site at a time; one name listed.
	 */
	const SMALL = array(
		'range'      => 2,
		'copy_range' => 2,
		'copy_rows'  => 1,
		'group'      => 1,
		'listed'     => 1,
		'keys'       => 1,
	);

	/**
	 * What a restore leaves: usermeta, the main site's options, the report.
	 *
	 * @return array<int, mixed>
	 */
	private function outcome( Job $job ): array {
		$report = $this->report( $job );
		// The counts for the final check are by temporary table, whose names carry the job's id: the numbers only.
		$report['rows'] = array( array_values( $report['rows']['removed'] ), array_values( $report['rows']['above'] ) );
		return array( $this->restored( $job, 'wpx_usermeta' ), $this->restored( $job, 'wpx_options' ), $report );
	}

	public function test_the_rows_named_after_the_prefix_are_renamed_copied_cleared_and_reported(): void {
		$q   = self::q();
		$job = $this->run_restore( $this->start_restore( $this->standard() ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$meta = $this->restored( $job, 'wpx_usermeta' );
		$want = array(
			array( 1, 'nickname', 'admin' ),
			array( 1, 'wpxAother', 'decoy' ),
			array( 1, $q . 'capabilities', 'admin' ),
			array( 1, $q . 'myplugin_pref', 'mine' ),
			array( 1, $q . 'persisted_preferences', 'prefs' ),
			array( 1, $q . 'user-settings', 'libraryContent=browse' ),
			array( 1, $q . 'user_level', '10' ),
			array( 1, $q . 'usersettings', 'old name' ),
			array( 1, 'wpx_myplugin_pref', 'mine' ),
			array( 2, $q . 'other', 'already there' ),
			array( 2, 'wpx_other', 'from the backup' ),
			array( 3, $q . 'capabilities', 'subscriber' ),
		);
		if ( is_multisite() ) {
			$want[] = array( 1, $q . '5_capabilities', 'site five' );
			$want[] = array( 1, $q . '9_capabilities', 'no such site' );
			$want[] = array( 1, 'wpx_9_capabilities', 'no such site' );
		} else {
			// No network: "5_" and "9_" name no site, so both are a plugin's keys to this restore: copied.
			$want[] = array( 1, $q . '5_capabilities', 'site five' );
			$want[] = array( 1, $q . '9_capabilities', 'no such site' );
			$want[] = array( 1, 'wpx_5_capabilities', 'site five' );
			$want[] = array( 1, 'wpx_9_capabilities', 'no such site' );
		}
		usort( $want, static function ( array $a, array $b ): int { return array( $a[0], $a[1], $a[2] ) <=> array( $b[0], $b[1], $b[2] ); } );
		usort( $meta, static function ( array $a, array $b ): int { return array( $a[0], $a[1], $a[2] ) <=> array( $b[0], $b[1], $b[2] ); } );
		$this->assertSame( $want, $meta );

		$options = $this->restored( $job, 'wpx_options' );
		$this->assertSame( 'roles of the backup', $options[ $q . 'user_roles' ] );
		$this->assertArrayNotHasKey( 'wpx_user_roles', $options );
		$this->assertSame( 'x', $options['wpx_plugin_setting'], 'an unknown option: reported, left' );
		$this->assertSame( 'Site', $options['blogname'] );
		if ( is_multisite() ) {
			$five = $this->restored( $job, 'wpx_5_options' );
			ksort( $five );
			$this->assertSame( array( $q . '5_user_roles' => 'roles of site five', 'wpx_5_plugin' => 'y' ), $five );
		}

		$report = $this->report( $job );
		if ( is_multisite() ) {
			$this->assertSame( 2, $report['options']['tables'] );
			$this->assertSame( array( $q . 'options' => array( 'wpx_plugin_setting' ), $q . '5_options' => array( 'wpx_5_plugin' ) ), $report['options']['first'] );
		} else {
			$this->assertSame( 1, $report['options']['tables'] );
			$this->assertSame( array( $q . 'options' => array( 'wpx_plugin_setting' ) ), $report['options']['first'] );
		}
		$this->assertSame( $report['options']['tables'], $report['options']['names'] );
		$this->assertSame( array( 'rows' => 1, 'copied' => 1 ), $report['copy']['keys']['wpx_myplugin_pref'] );
		$this->assertSame( array( 'rows' => 1, 'existing' => 1 ), $report['copy']['keys']['wpx_other'], 'a name the user already had: left alone, reported' );
		$this->assertSame( 0, $report['copy']['more'] );
		$this->assertSame( array( 'capabilities' => 1, 'user_roles' => 1 ), $report['cleared']['keys'] );
		$log = (string) file_get_contents( $job->storage_path . '/' . $job->log_path );
		$this->assertStringContainsString( 'were removed from the restored tables', $log );
		$this->assertStringContainsString( 'were left as they are (reported, not renamed)', $log );
	}

	public function test_an_empty_backup_prefix_renames_the_exact_names_only(): void {
		$q   = self::q();
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix(
					'',
					array(
						array( 1, 'capabilities', 'admin' ),
						array( 1, 'myplugin_pref', 'mine' ),
					),
					array(
						'user_roles'     => 'roles',
						'plugin_setting' => 'x',
					)
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$meta = $this->restored( $job, 'usermeta' );
		sort( $meta );
		$this->assertSame( array( array( 1, 'myplugin_pref', 'mine' ), array( 1, $q . 'capabilities', 'admin' ) ), $meta, 'nothing copied: no prefix to tell a plugin\'s key by' );
		$options = $this->restored( $job, 'options' );
		$this->assertSame( 'roles', $options[ $q . 'user_roles' ] );
		$this->assertSame( 'x', $options['plugin_setting'] );
		$this->assertFalse( $this->report( $job )['identified'] );
		$this->assertStringContainsString( 'The backup has no table prefix', (string) file_get_contents( $job->storage_path . '/' . $job->log_path ) );
	}

	public function test_a_copy_whose_name_would_not_fit_is_reported_not_made(): void {
		$q    = self::q();
		$p    = 'x_';
		$long = $p . str_repeat( 'k', 255 - strlen( $p ) );
		$fits = $p . str_repeat( 'k', 255 - strlen( $q ) );
		$this->assertGreaterThan( strlen( $p ), strlen( $q ), 'the control: this site\'s prefix is the longer' );
		$job = $this->run_restore( $this->start_restore( $this->backup_with_prefix( $p, array( array( 1, $long, 'long' ), array( 2, $fits, 'fits' ) ), array() ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$meta = $this->restored( $job, $p . 'usermeta' );
		sort( $meta );
		$want = array( array( 1, $long, 'long' ), array( 2, $fits, 'fits' ), array( 2, $q . substr( $fits, strlen( $p ) ), 'fits' ) );
		sort( $want );
		$this->assertSame( $want, $meta, 'the control: a copy of 255 bytes was made' );
		$keys = $this->report( $job )['copy']['keys'];
		$this->assertSame( array( 'rows' => 1, 'too_long' => 1 ), $keys[ $long ] );
		$this->assertSame( array( 'rows' => 1, 'copied' => 1 ), $keys[ $fits ] );
	}

	/**
	 * A row at key 0 (a table that lost its AUTO_INCREMENT), each kind: its key, what user 1's keys become,
	 * whether it is the table's only row, and what the report counts.
	 *
	 * and the backup's prefix.
	 *
	 * @return array<string, array{0: string, 1: array<int, string>, 2: bool, 3: array<int, mixed>, 4?: string}>
	 */
	public function rows_at_zero(): array {
		return array(
			'renamed'                => array( 'wpx_capabilities', array( '{Q}capabilities' ), false, array() ),
			'renamed, no prefix'     => array( 'capabilities', array( '{Q}capabilities' ), false, array(), '' ),
			'renamed, second group'  => array( 'wpx_6_capabilities', array( '{Q}6_capabilities' ), false, array() ),
			'cleared, the only row'  => array( '{Q}capabilities', array(), true, array( 'cleared', 'keys', array( 'capabilities' => 1 ) ) ),
			'copied, the only row'   => array( 'wpx_myplugin_pref', array( 'wpx_myplugin_pref', '{Q}myplugin_pref' ), true, array( 'copy', 'keys', array( 'wpx_myplugin_pref' => array( 'rows' => 1, 'copied' => 1 ) ) ) ),
		);
	}

	/**
	 * @dataProvider rows_at_zero
	 *
	 * @param string[]          $becomes Keys of user 1 after the restore.
	 * @param array<int, mixed> $counted Report section, part, and what it holds.
	 */
	public function test_a_row_at_key_zero_is_rewritten_too( string $key, array $becomes, bool $alone, array $counted, string $p = 'wpx_' ): void {
		global $wpdb;
		if ( false !== strpos( $key, '_6_' ) && ! is_multisite() ) {
			$this->markTestSkipped( 'A network\'s sites only.' );
		}
		$q    = self::q();
		$rows = array( array( 1, str_replace( '{Q}', $q, $key ), 'zero', 0 ) );
		if ( ! $alone ) {
			$rows[] = array( 2, $p . 'capabilities', 'other' );
		}
		// With groups of one site, the first group is the main site and site 5, the second site 6.
		$base = $this->backup_with_prefix( $p, $rows, array(), array( 5 => array(), 6 => array() ) );
		$job  = $this->run_restore( Plugin::instance()->jobs()->create( $this->type_with( self::SMALL ), self::$admin_id, array(), array( 'base' => $base ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$temp = $this->temporary_names( $job )[ $p . 'usermeta' ];
		if ( array() !== $becomes ) {
			$this->assertSame( '0', (string) $wpdb->get_var( "SELECT MIN(umeta_id) FROM `{$temp}`" ), 'the control: the row is at key 0 in the restored table' );
		}
		$keys = array_column(
			array_filter(
				$this->restored( $job, $p . 'usermeta' ),
				static function ( array $row ): bool {
					return 1 === $row[0];
				}
			),
			1
		);
		sort( $keys );
		$want = str_replace( '{Q}', $q, $becomes );
		sort( $want );
		$this->assertSame( $want, $keys );
		if ( array() !== $counted ) {
			$this->assertSame( $counted[2], $this->report( $job )[ $counted[0] ][ $counted[1] ] );
		}
	}

	public function test_a_restore_without_usermeta_renames_the_roles(): void {
		$q   = self::q();
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix( 'wpx_', array( array( 1, 'wpx_capabilities', 'admin' ) ), array( 'wpx_user_roles' => 'roles' ) ),
				array( 'exclude_tables' => array( 'wpx_usermeta' ) )
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertArrayNotHasKey( 'wpx_usermeta', $this->temporary_names( $job ), 'the control: usermeta is not restored' );
		$this->assertSame( 'roles', $this->restored( $job, 'wpx_options' )[ $q . 'user_roles' ] );
	}

	public function test_a_usermeta_table_that_does_not_number_its_rows_gets_no_copies(): void {
		global $wpdb;
		$q   = self::q();
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix(
					'wpx_',
					array(
						array( 1, 'wpx_capabilities', 'admin', 0 ),
						array( 1, 'wpx_myplugin_pref', 'mine' ),
					),
					array(),
					array(),
					'InnoDB',
					'',
					'MODIFY umeta_id bigint(20) unsigned NOT NULL'
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertStringNotContainsStringIgnoringCase( 'auto_increment', (string) $wpdb->get_var( $wpdb->prepare( 'SELECT EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $this->temporary_names( $job )['wpx_usermeta'], 'umeta_id' ) ), 'the control: the restored table does not number its rows' );
		$meta = $this->restored( $job, 'wpx_usermeta' );
		sort( $meta );
		$want = array( array( 1, 'wpx_myplugin_pref', 'mine' ), array( 1, $q . 'capabilities', 'admin' ) );
		sort( $want );
		$this->assertSame( $want, $meta, 'renamed, not copied' );
		$report = $this->report( $job );
		$this->assertSame( array( false, 'numbering' ), array( $report['copies'], $report['no_copies'] ) );
		$this->assertSame( array( 'rows' => 1, 'reported' => 1 ), $report['copy']['keys']['wpx_myplugin_pref'] );
		$this->assertStringContainsString( 'does not number its rows', (string) file_get_contents( $job->storage_path . '/' . $job->log_path ) );
	}

	public function test_a_copy_the_backup_already_has_is_found_in_a_table_of_another_character_set(): void {
		global $wpdb;
		$q   = self::q();
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix(
					'wpx_',
					array(
						array( 1, "wpx_caf\u{e9}", 'mine' ),
						array( 1, "{$q}caf\u{e9}", 'already there' ),
					),
					array(),
					array(),
					'InnoDB',
					'latin1'
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$temp = $this->temporary_names( $job )['wpx_usermeta'];
		$this->assertSame( 'latin1', $wpdb->get_var( $wpdb->prepare( 'SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s', $temp, 'meta_key' ) ), 'the control: the restored column is latin1' );
		$meta = $this->restored( $job, 'wpx_usermeta' );
		sort( $meta );
		$want = array( array( 1, "wpx_caf\u{e9}", 'mine' ), array( 1, "{$q}caf\u{e9}", 'already there' ) );
		sort( $want );
		$this->assertSame( $want, $meta, 'no second row under the name' );
		$this->assertSame( array( 'rows' => 1, 'existing' => 1 ), $this->report( $job )['copy']['keys']["wpx_caf\u{e9}"] );
	}

	public function test_a_backup_prefix_that_starts_with_this_sites_gets_no_copy_named_like_its_own_rows(): void {
		$q   = self::q();
		$p   = $q . 'a'; // A copy of "{P}a2_capabilities" would be "{Q}a2_capabilities", that is "{P}2_capabilities".
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix(
					$p,
					array(
						array( 1, $p . 'capabilities', 'admin' ),
						array( 1, $p . 'a2_capabilities', 'odd' ),
						array( 1, $p . 'myplugin_pref', 'mine' ),
					),
					array()
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$meta = $this->restored( $job, $p . 'usermeta' );
		sort( $meta );
		$want = array(
			array( 1, $p . 'a2_capabilities', 'odd' ),
			array( 1, $p . 'myplugin_pref', 'mine' ),
			array( 1, $q . 'capabilities', 'admin' ),
			array( 1, $q . 'myplugin_pref', 'mine' ),
		);
		sort( $want );
		$this->assertSame( $want, $meta, 'the control: another key was copied' );
		$this->assertSame( array( 'rows' => 1, 'reported' => 1 ), $this->report( $job )['copy']['keys'][ $p . 'a2_capabilities' ] );
	}

	public function test_a_site_prefix_that_starts_with_the_backups_gets_no_copies(): void {
		$q = self::q();
		$p = substr( $q, 0, -2 ); // "wptests_" and "wptest": a copy of "{P}s_x" would be named "{Q}x", a name of the backed-up site too.
		$this->assertSame( 0, strncmp( $q, $p, strlen( $p ) ), 'the control: this site\'s prefix starts with the backup\'s' );
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix(
					$p,
					array(
						array( 1, $p . 'capabilities', 'admin' ),
						array( 1, $p . 'myplugin_pref', 'mine' ),
					),
					array( $p . 'user_roles' => 'roles' )
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$meta = $this->restored( $job, $p . 'usermeta' );
		sort( $meta );
		$want = array( array( 1, $p . 'myplugin_pref', 'mine' ), array( 1, $q . 'capabilities', 'admin' ) );
		sort( $want );
		$this->assertSame( $want, $meta );
		$report = $this->report( $job );
		$this->assertFalse( $report['copies'] );
		$this->assertSame( array( $p . 'myplugin_pref' => array( 'rows' => 1, 'reported' => 1 ) ), $report['copy']['keys'] );
		$this->assertStringContainsString( 'so a copy under it would be a name of the backed-up site too', (string) file_get_contents( $job->storage_path . '/' . $job->log_path ) );
	}

	public function test_one_sites_new_name_being_another_sites_old_one_is_renamed_right(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network\'s sites only.' );
		}
		$q   = self::q();
		$p   = $q . '77_'; // The main site's "{P}capabilities" is site 77's "{Q}77_capabilities".
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix(
					$p,
					array(
						array( 1, $p . 'capabilities', 'main' ),
						array( 1, $p . '77_capabilities', 'site 77' ),
					),
					array( $p . 'user_roles' => 'main roles' ),
					array( 77 => array( $p . '77_user_roles' => 'site 77 roles' ) )
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertSame( array( array( 1, $q . '77_capabilities', 'site 77' ), array( 1, $q . 'capabilities', 'main' ) ), $this->restored( $job, $p . 'usermeta' ) );
	}

	/**
	 * Engines, sizes and which pass of a seam is killed.
	 *
	 * @return array<string, array{0: string, 1: array<string, int>, 2: bool}>
	 */
	public function interruptions(): array {
		return array(
			'InnoDB'                        => array( 'InnoDB', array(), false ),
			'MyISAM'                        => array( 'MyISAM', array(), false ),
			'InnoDB, small units, last pass' => array( 'InnoDB', self::SMALL, true ),
		);
	}

	/**
	 * @dataProvider interruptions
	 *
	 * @param array<string, int> $sizes Sizes.
	 */
	public function test_an_interrupted_rewrite_ends_as_one_that_ran_through( string $engine, array $sizes, bool $last ): void {
		global $wpdb;
		$base   = $this->standard( $engine );
		$passes = array();
		$clean  = $this->run_restore(
			Plugin::instance()->jobs()->create(
				$this->type_with(
					$sizes,
					static function ( string $at ) use ( &$passes ): void {
						$passes[ $at ] = ( $passes[ $at ] ?? 0 ) + 1;
					}
				),
				self::$admin_id,
				array(),
				array( 'base' => $base )
			)
		);
		$this->assertSame( Job::COMPLETED, $clean->status, (string) $clean->last_error );
		$this->assertSame( $engine, $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $this->temporary_names( $clean )['wpx_usermeta'] ) ), 'the control: the restored usermeta is ' . $engine );
		$want = $this->outcome( $clean );
		foreach ( array( 'options_report', 'copied', 'copy', 'a', 'a_options', 'cleared', 'clear', 'clear_options', 'b', 'b_options' ) as $point ) {
			$this->assertArrayHasKey( $point, $passes, 'the control: the rewrite passes ' . $point );
			$kill = $last ? $passes[ $point ] : 1;
			$hit  = 0;
			$type = $this->type_with(
				$sizes,
				static function ( string $at ) use ( $point, $kill, &$hit ): void {
					if ( $at === $point && $kill === ++$hit ) {
						throw new \RuntimeException( 'simulated: the run is killed here' );
					}
				}
			);
			$job = $this->run_restore( Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) ) );
			$this->assertSame( Job::FAILED, $job->status, $point );
			$this->assertSame( $kill, $hit, 'the control: killed at ' . $point );
			Plugin::instance()->job_actions()->retry( $job->id );
			$job = $this->run_restore( $job );
			$this->assertSame( Job::COMPLETED, $job->status, $point . ': ' . $job->last_error );
			$this->assertSame( $want, $this->outcome( $job ), $point );
		}
	}

	/**
	 * The job, retried after its back-off, ends as a restore of the same backup that was never interrupted.
	 */
	private function assertRetriedToTheEnd( Job $job, string $base ): void {
		$runner = Plugin::instance()->runner();
		for ( $i = 0; $i < 500; $i++ ) {
			$job = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			$runner->tick( $job->id, microtime( true ) + 3600 ); // Past the back-off.
		}
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$clean = $this->run_restore( $this->start_restore( $base ) );
		$this->assertSame( $this->outcome( $clean ), $this->outcome( $job ), 'the retry ends as a run that was never interrupted' );
	}

	/**
	 * Where the claim is taken: between statements, before a report is written.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function steals(): array {
		return array(
			'between statements'    => array( 'a' ),
			'before a report write' => array( 'copied' ),
		);
	}

	/**
	 * @dataProvider steals
	 */
	public function test_a_run_whose_claim_was_taken_changes_nothing_more( string $point ): void {
		$base   = $this->standard();
		$stolen = false;
		$type   = 'restore_prefix_lost_' . $point;
		$work   = function ( Job $job ): string {
			return $this->work( $job );
		};
		$this->register(
			$type,
			self::restore_steps_with(
				new PrefixRewriteStep(
					null,
					static function ( string $at ) use ( &$stolen, &$job, $work, $point ): void {
						global $wpdb;
						if ( $point === $at && ! $stolen ) {
							// Another run claims the rewrite.
							$stolen = true;
							$ledger = TempTables::ledger( $job->storage_token, $job->id, (string) RestorePreflightStep::load_plan( $work( $job ) )['random'] );
							$wpdb->query( 'COMMIT' ); // The test's transaction began before the ledger was created.
							$taken = $wpdb->query( $wpdb->prepare( "UPDATE `{$ledger}` SET holder = 'another-run' WHERE n = %d", PrefixRewriteStep::CLAIM ) );
							$wpdb->query( 'COMMIT' ); // Seen by the import's own connection.
							if ( 1 !== (int) $taken ) {
								throw new \LogicException( 'The claim was not taken: ' . $wpdb->last_error );
							}
						}
					}
				)
			)
		);
		$job    = Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $base ) );
		$runner = Plugin::instance()->runner();
		$result = null;
		for ( $i = 0; $i < 500 && ! $stolen; $i++ ) {
			$result = $runner->tick( $job->id, microtime( true ) );
			$job    = Plugin::instance()->jobs()->find( $job->id );
		}
		$this->assertTrue( $stolen, 'the control: the claim was taken in the middle of the rewrite' );
		$this->assertStringContainsString( 'took over the table prefix rewrite', (string) $result->message, 'the run went on and stopped at the next look at its claim' );
		$this->assertSame( Job::RUNNING, Plugin::instance()->jobs()->find( $job->id )->status, 'it stopped to be retried, not failed' );
		if ( 'copied' === $point ) {
			$copy = $this->report( $job )['copy'];
			$this->assertSame( array( -1, array() ), array( $copy['upto'], $copy['keys'] ), 'the report was not written by the run whose claim was taken' );
			$this->assertContains( array( 1, self::q() . 'myplugin_pref', 'mine' ), $this->restored( $job, 'wpx_usermeta' ), 'the control: the copy before the claim was taken ran' );
			$this->assertRetriedToTheEnd( $job, $base );
			return;
		}
		$meta = $this->restored( $job, 'wpx_usermeta' );
		$this->assertNotContains( array( 1, 'wpx_capabilities', 'admin' ), $meta, 'the control: the usermeta statement before the claim was taken ran' );
		$this->assertContains( array( 3, self::q() . 'capabilities', 'inert' ), $meta, 'the clearing did not run for the run whose claim was taken' );
		$this->assertNotContains( array( 1, self::q() . 'capabilities', 'admin' ), $meta, 'nor did the renaming' );
		$this->assertSame( 'roles of the backup', $this->restored( $job, 'wpx_options' )['wpx_user_roles'], 'the options statement after the claim was taken changed nothing' );
		$this->assertRetriedToTheEnd( $job, $base );
	}

	public function test_usermeta_keys_are_matched_byte_for_byte_and_option_names_by_the_collation(): void {
		$q   = self::q();
		$uq  = strtoupper( $q );
		$job = $this->run_restore(
			$this->start_restore(
				$this->backup_with_prefix(
					'wpx_',
					array(
						array( 1, 'WPX_capabilities', 'upper case' ),
						array( 1, 'WPX_myplugin_pref', 'upper case' ),
						array( 2, $uq . 'capabilities', 'upper case, this site' ),
						array( 2, $q . 'capabilities ', 'trailing space, this site' ),
						array( 3, 'wpx_capabilities', 'the one' ),
						array( 4, 'wpx_myplugin_pref', 'mine' ),
						array( 4, $uq . 'myplugin_pref', 'upper case, this site' ),
					),
					array(
						'WPX_user_roles'     => 'upper case',
						$uq . 'user_roles'   => 'upper case, this site',
						'WPX_plugin_setting' => 'upper case',
					)
				)
			)
		);
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$meta = $this->restored( $job, 'wpx_usermeta' );
		$want = array(
			array( 1, 'WPX_capabilities', 'upper case' ),
			array( 1, 'WPX_myplugin_pref', 'upper case' ),
			array( 2, $uq . 'capabilities', 'upper case, this site' ),
			array( 2, $q . 'capabilities ', 'trailing space, this site' ),
			array( 3, $q . 'capabilities', 'the one' ),
			array( 4, 'wpx_myplugin_pref', 'mine' ),
			array( 4, $uq . 'myplugin_pref', 'upper case, this site' ),
			array( 4, $q . 'myplugin_pref', 'mine' ),
		);
		$sort = static function ( array $a, array $b ): int {
			return strcmp( $a[0] . "\0" . $a[1], $b[0] . "\0" . $b[1] );
		};
		usort( $want, $sort );
		usort( $meta, $sort );
		$this->assertSame( $want, $meta, 'the control: the one row equal byte for byte was renamed' );
		// get_option( 'wpx_user_roles' ) read "WPX_user_roles" on the backed-up site; the unique index holds one of them.
		$options = $this->restored( $job, 'wpx_options' );
		$this->assertSame( 'upper case', $options[ $q . 'user_roles' ] );
		$this->assertArrayNotHasKey( 'WPX_user_roles', $options );
		$this->assertArrayNotHasKey( $uq . 'user_roles', $options );
		$report = $this->report( $job );
		$this->assertSame( array( 'WPX_plugin_setting' ), $report['options']['first'][ $q . 'options' ] );
		$this->assertSame( array( 'wpx_myplugin_pref' => array( 'rows' => 1, 'copied' => 1 ) ), $report['copy']['keys'], 'an upper case name is not the copy\'s' );
		$this->assertSame( array( 'user_roles' => 1 ), $report['cleared']['keys'], 'the control: the option was cleared, the usermeta rows were not' );
	}

	public function test_units_of_a_row_and_groups_of_a_site_end_as_the_default_ones_with_the_report_bounded(): void {
		$base    = $this->standard();
		$default = $this->run_restore( $this->start_restore( $base ) );
		$small   = $this->run_restore( Plugin::instance()->jobs()->create( $this->type_with( self::SMALL ), self::$admin_id, array(), array( 'base' => $base ) ), false, 2000 );
		$this->assertSame( Job::COMPLETED, $small->status, (string) $small->last_error );
		$this->assertSame( array_slice( $this->outcome( $default ), 0, 2 ), array_slice( $this->outcome( $small ), 0, 2 ) );
		if ( is_multisite() ) {
			$this->assertSame( 'roles of site five', $this->restored( $small, 'wpx_5_options' )[ self::q() . '5_user_roles' ], 'the control: the second group was renamed too' );
		}
		$full    = $this->report( $default );
		$bounded = $this->report( $small );
		$this->assertCount( 1, $bounded['copy']['keys'] );
		$this->assertGreaterThan( 0, $bounded['copy']['more'] );
		$rows = static function ( array $report ): int {
			return array_sum( array_column( $report['copy']['keys'], 'rows' ) ) + $report['copy']['more'];
		};
		$this->assertSame( $rows( $full ), $rows( $bounded ), 'every row counted once' );
		$this->assertCount( 1, $bounded['options']['first'] );
		$this->assertSame( $full['options']['tables'], $bounded['options']['tables'] );
		$this->assertSame( $full['cleared']['keys'], $bounded['cleared']['keys'] );
	}

	public function test_the_sites_without_an_options_table_are_counted_and_the_first_named(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'A network\'s sites only.' );
		}
		$base = $this->backup_with_prefix( 'wpx_', array( array( 1, 'wpx_capabilities', 'admin' ) ), array(), array( 6 => null, 7 => null, 8 => array() ) );
		$job  = $this->run_restore( Plugin::instance()->jobs()->create( $this->type_with( self::SMALL ), self::$admin_id, array(), array( 'base' => $base ) ) );
		$this->assertSame( Job::COMPLETED, $job->status, (string) $job->last_error );
		$this->assertSame( array( 'count' => 2, 'first' => array( 'wpx_6_options' ) ), $this->report( $job )['missing'] );
	}

	public function test_a_unit_slower_than_the_whole_budget_ends_the_job_with_the_reason(): void {
		$type   = $this->type_with(
			array(),
			function ( string $at ): void {
				if ( 'a' === $at ) {
					$this->now += 60; // The statement took a minute.
				}
			}
		);
		$job    = Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array( 'base' => $this->standard() ) );
		$runner = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array(
				'clock'  => function (): float {
					return $this->now;
				},
				'budget' => new Budget( 20, 32 * 1048576, false ),
			)
		);
		for ( $i = 0; $i < 500; $i++ ) {
			$job = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $job->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			$runner->tick( $job->id, microtime( true ) );
		}
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertSame( Job::FAILED, $job->status );
		$this->assertStringContainsString( 'one unit of work took 60 seconds, more than the 20-second time budget', (string) $job->last_error );
	}

	public function test_a_rewrite_moves_on_in_every_tick_with_no_time_left(): void {
		$job    = $this->start_restore( $this->standard() );
		$runner = new Runner( Plugin::instance()->jobs(), Plugin::instance()->job_types(), new Redactor( Redactor::installation_secrets() ), array( 'budget' => new Budget( 20, 32 * 1048576, false ) ) );
		$ticks  = 0;
		for ( $i = 0; $i < 5000; $i++ ) {
			$now = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
			if ( PrefixRewriteStep::ID === $now->step ) {
				++$ticks;
			}
			$runner->tick( $job->id, microtime( true ) - 3600 );
		}
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$this->assertSame( Job::COMPLETED, $now->status, (string) $now->last_error );
		$this->assertGreaterThan( 5, $ticks, 'the control: the rewrite crossed ticks, a statement each' );
		$this->assertContains( array( 1, self::q() . 'capabilities', 'admin' ), $this->restored( $now, 'wpx_usermeta' ) );
	}
}
