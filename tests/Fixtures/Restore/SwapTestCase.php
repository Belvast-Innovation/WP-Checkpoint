<?php

namespace WPCheckpoint\Tests\Fixtures\Restore;

use WPCheckpoint\Files\ScanRoots;
use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\FileStagingStep;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TempTables;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\RestoreFiles;
use WPCheckpoint\Restore\SwapPlan;
use WPCheckpoint\Restore\SwapRules;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * A restore taken to the swap in a sandbox of its own. The site's
 * directories are stand-ins under the temporary directory (plugins,
 * themes, uploads, mu-plugins and the content directory, each holding
 * something of the "live" site), the maintenance file goes to a stand-in
 * for ABSPATH there too, and the tables the swap renames are the test's
 * own and the options (and a network's sitemeta, which a restore must
 * bring): the backup holds every live table of the site, and all others
 * are left out of the restore (exclude_tables), so no other table of the
 * site is renamed. What the swap moved away is put back after each test
 * from the plan, by the swap's own rules.
 *
 * The tables: "swt_keep" (live and in the backup, replaced), "swt_new"
 * (only in the backup) and "swt_gone" (only live: moved aside), each with
 * rows that tell the live one from the backup's.
 */
abstract class SwapTestCase extends RestoreTestCase {

	/** @var string The sandbox ('' before set_up()). */
	protected $sandbox = '';

	/** @var string Stand-in for ABSPATH (the maintenance file). */
	protected $abspath = '';

	/** @var array<string, string> Group => the stand-in directory. */
	protected $dirs = array();

	/** @var string The restore's job type for this test. */
	protected $type = '';

	/** @var array<string, mixed> Parts of the swap step for this test. */
	protected $swap_parts = array();

	/** @var array{seam?: string, sql?: string[]} Statements a killed run sends when it reaches the seam (killed_at()). */
	protected $child_sql = array();

	/** @var array<string, mixed> Test seams of the preflight for this test (RestorePreflightStep). */
	protected $preflight_parts = array();

	/** @var string[] What the swap step's crash seam saw, in order. */
	protected $seams = array();

	/** @var Job|null The job of the test (for tear_down). */
	protected $swap_job = null;

	/** @var int Seconds the swap's clock is set off from the real one (here and in the killed child). */
	protected $clock_offset = 0;

	/** @var string A file the killed child appends every seam it passes to ('' for none). */
	protected $trace = '';

	/**
	 * The installation the test's restore runs under, as the context of its Directories (an empty array: the
	 * plugin's own). Its storage directory holds the backups and the restore's work, and its token is the job's:
	 * the restore is created, run up to the swap and killed (in the child too) with that installation's jobs.
	 *
	 * @var array<string, mixed>
	 */
	protected $storage = array();

	/**
	 * The installation the test's restore runs under (see $storage), a fresh instance.
	 *
	 * @return Directories
	 */
	protected function installation(): Directories {
		return array() === $this->storage ? Plugin::instance()->directories() : new Directories( $this->storage );
	}

	/**
	 * Its jobs.
	 *
	 * @return JobRepository
	 */
	protected function installation_jobs(): JobRepository {
		return array() === $this->storage ? Plugin::instance()->jobs() : new JobRepository( $this->installation() );
	}

	protected function backups_dir(): string {
		return $this->installation()->backups();
	}

	public function set_up(): void {
		parent::set_up();
		$this->set_up_swap();
	}

	public function tear_down(): void {
		$this->tear_down_swap();
		parent::tear_down();
	}

	/**
	 * The sandbox, the live tables and the type (again, for a test that runs several cases).
	 *
	 * @return void
	 */
	protected function set_up_swap(): void {
		global $wpdb;
		$this->seams     = array();
		$this->child_sql = array();
		$this->sandbox   = Sandbox::make( 'swap' );
		$content       = $this->sandbox . '/wp-content';
		$this->abspath = $this->sandbox . '/site';
		mkdir( $this->abspath );
		$this->dirs = array( 'other-content' => $content );
		foreach ( array( 'plugins', 'themes', 'uploads', 'mu-plugins' ) as $group ) {
			mkdir( $content . '/' . $group, 0755, true );
			$this->dirs[ $group ] = $content . '/' . $group;
		}
		foreach ( self::live_files() as $path => $text ) {
			if ( ! is_dir( dirname( $content . '/' . $path ) ) ) {
				mkdir( dirname( $content . '/' . $path ), 0755, true );
			}
			file_put_contents( $content . '/' . $path, $text );
		}
		foreach ( array( 'swt_keep', 'swt_gone' ) as $name ) {
			$this->create( $wpdb->prefix . $name, '(id INT UNSIGNED NOT NULL PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB' );
			$wpdb->query( "INSERT INTO `{$wpdb->prefix}{$name}` (id, v) VALUES (1, 'live'), (2, 'live')" );
		}
		$this->swap_parts = array(
			'now'       => function (): int {
				return time() + $this->clock_offset;
			},
			'abspath'   => $this->abspath,
			'site_dirs' => function (): array {
				return $this->dirs;
			},
			'sleep'     => static function ( int $seconds ): void {
				unset( $seconds );
			},
			'flush'     => static function (): void {},
			'at'        => function ( string $point ): void {
				$this->seams[] = $point;
			},
		);
		$this->register_type();
	}

	/**
	 * Undo what the test's swap left, and remove the sandbox.
	 *
	 * @return void
	 */
	protected function tear_down_swap(): void {
		$this->discard_abandoned();
		if ( null !== $this->swap_job ) {
			$this->undo( $this->swap_job );
			$this->swap_job = null;
		}
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
			$this->sandbox = '';
		}
	}

	/**
	 * What the live site has in its directories (relative to the content directory).
	 *
	 * @return array<string, string>
	 */
	protected static function live_files(): array {
		return array(
			'uploads/live.txt'               => 'live upload',
			'plugins/live-plugin/live.php'   => "<?php\n// live plugin\n",
			'themes/live-theme/style.css'    => '/* live theme */',
			'mu-plugins/live-mu.php'         => "<?php\n// live mu-plugin\n",
			'languages/live.mo'              => 'live mo',
			'object-cache.php'               => "<?php\n// live drop-in\n",
			'live-only.txt'                  => 'only the live site has this',
		);
	}

	/**
	 * What the backup has in its files (archive paths).
	 *
	 * @return array<string, string>
	 */
	protected static function backup_files(): array {
		return array(
			'wp-content/uploads/2026/10/restored.txt' => 'restored upload',
			'wp-content/plugins/demo/demo.php'        => "<?php\n/* Plugin Name: Demo */\n",
			'wp-content/themes/restored/style.css'    => '/* restored theme */',
			'wp-content/mu-plugins/restored-mu.php'   => "<?php\n// restored mu-plugin\n",
			'wp-content/languages/restored.mo'        => 'restored mo',
			'wp-content/upgrade/restored.txt'         => 'a directory the live site does not have',
			'wp-content/object-cache.php'             => "<?php\n// the backup's drop-in\n",
		);
	}

	/**
	 * Register the restore type of this test: the real steps, the directories of the sandbox, and the swap with
	 * $this->swap_parts (and $more).
	 *
	 * @param array<string, mixed> $more    More parts of the swap step.
	 * @param callable|null        $connect The swap's connection (null: the site's own).
	 * @return void
	 */
	protected function register_type( array $more = array(), $connect = null ): void {
		$dirs  = function (): array {
			return $this->dirs;
		};
		$steps   = array();
		$restore = new RestoreJob(
			function (): Directories {
				return $this->installation();
			},
			array( Plugin::instance()->job_presenter(), 'clean' )
		);
		foreach ( $restore->steps() as $step ) {
			switch ( $step->id() ) {
				case \WPCheckpoint\Jobs\RestorePreflightStep::ID:
					$steps[] = new \WPCheckpoint\Jobs\RestorePreflightStep(
						function (): string {
							return $this->backups_dir();
						},
						\WPCheckpoint\Jobs\RestorePreflightStep::HEAD_BYTES,
						$this->preflight_parts
					);
					break;
				case RestoreFilesPreflightStep::ID:
					$steps[] = new RestoreFilesPreflightStep(
						array(
							'directories'  => $dirs,
							// The sandbox stands for the site's own directories (its WordPress directory is elsewhere).
							'trusted_root' => function (): string {
								return $this->sandbox;
							},
						)
					);
					break;
				case FileStagingStep::ID:
					$steps[] = new FileStagingStep( $this->staging_parts() );
					break;
				case SwapCheckStep::ID:
					$steps[] = new SwapCheckStep( null, $this->check_parts( array( 'site_dirs' => $dirs, 'abspath' => $this->abspath ) ) );
					break;
				default:
					$steps[] = $step;
			}
		}
		// The test case's restore type ends at the final check (RestoreTestCase); the swap comes after it.
		$steps[] = new SwapStep( $connect, $more + $this->swap_parts );
		$this->type = 'restore_swap_' . bin2hex( random_bytes( 3 ) );
		$this->register( $this->type, $steps );
	}

	/**
	 * Register the type again with these parts and point the job at it (the job keeps its position).
	 *
	 * @param Job                  $job     Job.
	 * @param array<string, mixed> $more    More parts of the swap step.
	 * @param callable|null        $connect The swap's connection.
	 * @return void
	 */
	protected function retype( Job $job, array $more = array(), $connect = null ): void {
		global $wpdb;
		$this->register_type( $more, $connect );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \WPCheckpoint\Jobs\JobRepository::table() . ' SET type = %s WHERE id = %d', $this->type, $job->id ) );
		$wpdb->query( 'COMMIT' );
	}

	/**
	 * Every live table of the site under its prefix but this plugin's own and the restore's.
	 *
	 * @return string[]
	 */
	protected static function live_tables(): array {
		global $wpdb;
		$out = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->base_prefix ) . '%' ) ) as $table ) {
			if ( ! \WPCheckpoint\Database\OwnTables::is_own( (string) $table ) ) {
				$out[] = (string) $table;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * A backup of every live table (the test's tables as the backup has them) with the backup's files.
	 *
	 * @return string The backup's base name.
	 */
	protected function swap_backup(): string {
		global $wpdb;
		$keep = $wpdb->prefix . 'swt_keep';
		$new  = $wpdb->prefix . 'swt_new';
		$gone = $wpdb->prefix . 'swt_gone';
		$this->create( $new, '(id INT UNSIGNED NOT NULL PRIMARY KEY, v VARCHAR(20) NOT NULL) ENGINE=InnoDB' );
		$wpdb->query( "UPDATE `{$keep}` SET v = 'backup'" );
		$wpdb->query( "INSERT INTO `{$keep}` (id, v) VALUES (3, 'backup')" );
		$wpdb->query( "INSERT INTO `{$new}` (id, v) VALUES (1, 'backup')" );
		$tables = array_values( array_diff( self::live_tables(), array( $gone ) ) );
		$base   = $this->backup( $tables, null, null, array( 'files' => self::backup_files() ) );
		// The live site as it is before the restore: the backup's rows were only for the export.
		$wpdb->query( "DELETE FROM `{$keep}` WHERE id = 3" );
		$wpdb->query( "UPDATE `{$keep}` SET v = 'live'" );
		$wpdb->query( "DROP TABLE `{$new}`" );
		$wpdb->query( 'COMMIT' );
		return $base;
	}

	/**
	 * A restore of the swap backup, run up to the swap (the job then stands at the swap step, queued for WP-CLI).
	 *
	 * @param array<string, mixed> $options      More options of the job.
	 * @param callable|null        $after_backup function(): void, between the backup and the restore.
	 * @return Job
	 */
	protected function at_swap( array $options = array(), $after_backup = null ): Job {
		global $wpdb;
		$base    = $this->swap_backup();
		if ( null !== $after_backup ) {
			call_user_func( $after_backup );
		}
		// The restore must bring the options (and a network's sitemeta): the restored site's list of active plugins.
		$exclude = array_values( array_diff( self::live_tables(), array_merge( array( $wpdb->prefix . 'swt_keep', $wpdb->prefix . 'swt_gone' ), self::site_tables() ) ) );
		$jobs    = $this->installation_jobs();
		$job     = $jobs->create( $this->type, self::$admin_id, array(), array_merge( array( 'base' => $base, 'exclude_tables' => $exclude ), $options ) );
		$runner  = array() === $this->storage ? Plugin::instance()->runner() : new Runner( $jobs, Plugin::instance()->job_types(), new Redactor( Redactor::installation_secrets() ) );
		for ( $i = 0; $i < 500; $i++ ) {
			$result = $runner->tick( $job->id, microtime( true ) );
			$now    = Plugin::instance()->jobs()->find( $job->id );
			if ( TickResult::CLI === $result->status || ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) ) {
				break;
			}
		}
		$wpdb->query( 'COMMIT' );
		$now = Plugin::instance()->jobs()->find( $job->id );
		$this->assertSame( SwapStep::ID, $now->step, sprintf( 'the restore reached the swap (ticks %d, last %s: %s; status %s): %s', $i + 1, $result->status, $result->message, $now->status, $now->last_error ) );
		$this->assertContains( $now->status, array( Job::QUEUED, Job::RUNNING ) );
		$this->swap_job = $now;
		return $now;
	}

	/**
	 * One tick as WP-CLI drives it, in this process.
	 *
	 * @param Job $job Job.
	 * @return TickResult
	 */
	protected function cli_tick( Job $job ): TickResult {
		$runner = new Runner(
			Plugin::instance()->jobs(),
			Plugin::instance()->job_types(),
			new Redactor( Redactor::installation_secrets() ),
			array( 'cli' => true )
		);
		return $this->autocommit(
			static function () use ( $runner, $job ): TickResult {
				return $runner->tick( $job->id, microtime( true ) );
			}
		);
	}

	/**
	 * Run with the site's connection in autocommit, as in a WP-CLI process. The test framework keeps it in a
	 * transaction, and every table it reads in one stays locked against a RENAME until it commits: the swap would
	 * wait for the test's own connection.
	 *
	 * @template T
	 * @param callable(): T $work Work.
	 * @return T
	 */
	protected function autocommit( callable $work ) {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		$wpdb->query( 'SET autocommit = 1' );
		try {
			return $work();
		} finally {
			$wpdb->query( 'SET autocommit = 0' );
		}
	}

	/**
	 * WP-CLI ticks until the job is no longer queued or running (or waits).
	 *
	 * @param Job $job Job.
	 * @param int $max Most ticks.
	 * @return Job
	 */
	protected function cli_run( Job $job, int $max = 50 ): Job {
		for ( $i = 0; $i < $max; $i++ ) {
			$result = $this->cli_tick( $job );
			$now    = Plugin::instance()->jobs()->find( $job->id );
			if ( ! in_array( $now->status, array( Job::QUEUED, Job::RUNNING ), true ) || TickResult::WAITING === $result->status ) {
				return $now;
			}
		}
		$this->fail( 'the swap did not end' );
	}

	/**
	 * One WP-CLI tick in a child process that kills itself (SIGKILL) at the $nth time the swap step reaches $seam:
	 * nothing of the step's or the Runner's catch, finally or shutdown runs, as when the process is killed.
	 *
	 * @param Job    $job  Job.
	 * @param string $seam Seam.
	 * @param int    $nth  Which time it is reached (1 = the first).
	 * @return void
	 */
	protected function killed_at( Job $job, string $seam, int $nth = 1 ): void {
		$this->assertTrue( function_exists( 'posix_kill' ), 'the crash tests need the posix extension (a process killed for real, not exit())' );
		$GLOBALS['wpdb']->query( 'COMMIT' );
		$config = $this->sandbox . '/child-' . bin2hex( random_bytes( 4 ) ) . '.json';
		file_put_contents(
			$config,
			(string) wp_json_encode(
				array(
					'job'     => $job->id,
					'type'    => $this->type,
					'seam'    => $seam,
					'nth'     => $nth,
					'abspath' => $this->abspath,
					'dirs'    => $this->dirs,
					'plugin'  => (string) ( $this->swap_parts['plugin'] ?? '' ),
					'packet'  => (int) ( $this->swap_parts['packet'] ?? 0 ),
					'batch'   => (int) ( $this->swap_parts['batch'] ?? 0 ),
					'offset'  => $this->clock_offset,
					'trace'   => $this->trace,
					'sql_at'  => $this->child_sql,
					'admin'   => self::$admin_id,
					'storage' => $this->storage,
				)
			)
		);
		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/swap-child.php' ) . ' ' . escapeshellarg( $config ) . ' 2>&1', $output, $status );
		$text = implode( "\n", $output );
		$this->assertStringContainsString( 'WPCHECKPOINT-CHILD-READY', $text, 'the child ran the tick: ' . $text );
		$this->assertStringNotContainsString( 'WPCHECKPOINT-CHILD-DONE', $text, 'the child was killed at ' . $seam . ' #' . $nth . ', not done: ' . $text );
		// Signal 9: reported as 9 by PHP's exec() here, as 128 + 9 by a shell in between.
		$this->assertContains( $status, array( 9, 137 ), 'killed by SIGKILL: ' . $text );
		// The lease of the killed run is still there: as after a real crash, the next run takes over once it ran out.
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( 'UPDATE ' . \WPCheckpoint\Jobs\JobRepository::table() . ' SET locked_until = %d WHERE id = %d', time() - 1, $job->id ) );
	}

	/**
	 * The plan the check wrote: the directory units and the table entries.
	 *
	 * @param Job $job Job.
	 * @return array{dirs: array<int, array<string, mixed>>, tables: array<int, array<string, mixed>>}
	 */
	protected function plan_of( Job $job ): array {
		global $wpdb;
		$db   = ImportSession::open( Credentials::from_wordpress() );
		$file = json_decode( (string) file_get_contents( RestoreFiles::path( $this->work( $job ), RestoreFiles::SWAP_PLAN ) ), true );
		$out  = array(
			'site'   => array(),
			'dirs'   => array(),
			'tables' => array(),
		);
		foreach ( ( new SwapPlan( $db, $wpdb->base_prefix . SwapPlan::TABLE ) )->read( $job->id, (int) $file['attempt'], -1, 100000 ) as $entry ) {
			$out[ SwapPlan::SITE === $entry['kind'] ? 'site' : ( SwapPlan::DIR === $entry['kind'] ? 'dirs' : 'tables' ) ][] = $entry;
		}
		$db->close();
		return $out;
	}

	/**
	 * Put back whatever the swap moved (the swap's own rules, run as the rollback runs them), and remove the
	 * maintenance file: what a committed swap leaves is not left behind a test.
	 *
	 * @param Job $job Job.
	 * @return void
	 */
	/**
	 * Put away what abandoned jobs keep on purpose (JobRepository::reclaim_scope(): nothing of an abandoned job is
	 * reclaimed): the tables their swaps moved aside back (undo()), their temporary tables dropped. Only abandoned jobs:
	 * anything else a test leaves is still a leak the leftover check reports.
	 */
	protected function discard_abandoned(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		foreach ( (array) $wpdb->get_col( 'SELECT id FROM ' . JobRepository::table() ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the plugin's table.
			$job = Plugin::instance()->jobs()->find( (int) $id );
			if ( null === $job || Job::REASON_ABANDONED !== $job->failure_reason ) {
				continue;
			}
			$this->undo( $job );
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( TempTables::job_prefix( $job->storage_token, $job->id ) ) . '%' ) ) as $table ) {
				$wpdb->query( 'DROP TABLE `' . str_replace( '`', '', (string) $table ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- a listed table name.
			}
		}
	}

	protected function undo( Job $job ): void {
		global $wpdb;
		$file = RestoreFiles::path( $this->work( $job ), RestoreFiles::SWAP_PLAN );
		if ( ! is_file( $file ) ) {
			return;
		}
		$plan  = $this->plan_of( $job );
		$there = array_fill_keys( (array) $wpdb->get_col( 'SHOW TABLES' ), true );
		foreach ( array_reverse( $plan['tables'] ) as $entry ) {
			$pairs = SwapRules::table_back( $entry, $there, 'wcpstray_undo_' . bin2hex( random_bytes( 3 ) ) . '_' . $entry['seq'] ); // Dropped with the test's tables.
			if ( array() !== $pairs ) {
				$wpdb->query( SwapRules::rename_sql( $pairs ) );
				foreach ( $pairs as $pair ) {
					unset( $there[ $pair[0] ] );
					$there[ $pair[1] ] = true;
				}
			}
		}
		foreach ( array_reverse( $plan['dirs'] ) as $entry ) {
			$exists = static function ( string $path ): bool {
				clearstatcache( true, $path );
				return false !== @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a test fixture.
			};
			foreach ( SwapRules::dir_back( $entry, $exists( $entry['live'] ), $exists( $entry['stage'] ), $exists( $entry['old'] ), $entry['live'] . '.undo-' . bin2hex( random_bytes( 2 ) ) ) as $pair ) {
				rename( $pair[0], $pair[1] );
			}
		}
	}

	/**
	 * The files under a directory: relative path => contents (or "<dir>"), sorted.
	 *
	 * @param string $dir Directory.
	 * @return array<string, string>
	 */
	protected static function tree( string $dir ): array {
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $file ) {
			$relative         = substr( $file->getPathname(), strlen( $dir ) + 1 );
			$out[ $relative ] = $file->isDir() ? '<dir>' : (string) file_get_contents( $file->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * The site as the swap could change it: the sandbox's directories (without the staging roots) and the test's
	 * tables (rows), and whether a maintenance file is up.
	 *
	 * @return array<string, mixed>
	 */
	protected function site(): array {
		global $wpdb;
		$files = array();
		foreach ( self::tree( $this->dirs['other-content'] ) as $path => $text ) {
			if ( 0 !== strpos( $path, 'wp-checkpoint-' ) ) { // Staging roots and probes: the restore's, not the site's.
				$files[ $path ] = $text;
			}
		}
		$tables = array();
		foreach ( array( 'swt_keep', 'swt_new', 'swt_gone' ) as $name ) {
			$table = $wpdb->prefix . $name;
			$wpdb->query( 'COMMIT' );
			$there            = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$tables[ $name ] = '' === $there ? null : (array) $wpdb->get_results( "SELECT id, v FROM `{$table}` ORDER BY id", ARRAY_A );
		}
		return array(
			'files'       => $files,
			'tables'      => $tables,
			'maintenance' => file_exists( $this->abspath . '/.maintenance' ),
		);
	}

	/**
	 * What the site is after the swap: the backup's files in the groups, the live drop-in kept, the tables
	 * replaced, the one the backup does not have moved aside.
	 *
	 * @param array<string, mixed> $before The site before (site()).
	 * @return void
	 */
	protected function assertRestored( array $before ): void {
		$after = $this->site();
		$this->assertSame( 'restored upload', $after['files']['uploads/2026/10/restored.txt'] ?? null );
		$this->assertArrayNotHasKey( 'uploads/live.txt', $after['files'], 'the live uploads were swapped out' );
		$this->assertSame( "<?php\n/* Plugin Name: Demo */\n", $after['files']['plugins/demo/demo.php'] ?? null );
		$this->assertArrayNotHasKey( 'plugins/live-plugin/live.php', $after['files'] );
		$this->assertSame( 'restored mo', $after['files']['languages/restored.mo'] ?? null );
		$this->assertArrayNotHasKey( 'languages/live.mo', $after['files'], 'a top-level entry of other content is a unit of its own' );
		$this->assertSame( 'a directory the live site does not have', $after['files']['upgrade/restored.txt'] ?? null );
		$this->assertSame( "<?php\n// live drop-in\n", $after['files']['object-cache.php'] ?? null, 'the live drop-in stays' );
		$this->assertSame( 'only the live site has this', $after['files']['live-only.txt'] ?? null, 'an entry the backup does not have stays' );
		$this->assertSame(
			array(
				array( 'id' => '1', 'v' => 'backup' ),
				array( 'id' => '2', 'v' => 'backup' ),
				array( 'id' => '3', 'v' => 'backup' ),
			),
			$after['tables']['swt_keep']
		);
		$this->assertSame( array( array( 'id' => '1', 'v' => 'backup' ) ), $after['tables']['swt_new'] );
		$this->assertNull( $after['tables']['swt_gone'], 'moved aside' );
		$this->assertFalse( $after['maintenance'], 'the maintenance file is down' );
		$this->assertNotSame( $before, $after );
	}
}
