<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\Maintenance;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Whoever in the plugin's code reads .maintenance or its data file is in the matrix (MaintenanceMatrixTest,
 * MaintenanceMatrixDbTest), or says why not. The scan (scan_source(), comments left out) counts, per file: calls of
 * the readings of Restore\Maintenance that take a directory ("reader"), its objects made ("object": everything else
 * of the class is asked of one), calls of Loader::needed() ("needed"), and the two files' names, written out or by
 * the class's constants ("name": whoever names a file may read it). READERS registers every file the scan finds, with
 * its counts and what covers each use; a file the scan finds that is not registered fails, a count that changed fails
 * (a new use, or a registration gone stale), and a registration for a file the scan no longer finds fails.
 * METHODS does the same for every public method of Restore\Maintenance: a new one must be put in the matrix or say
 * why it need not be. The controls: the scan finds each kind in a source made for it, and nothing in comments; a
 * reader not registered, a count changed and a registration gone stale are each reported.
 */
final class MaintenanceReadersTest extends TestCase {

	const UNIT = 'tests/unit/Restore/MaintenanceMatrixTest.php';
	const DB   = 'tests/integration/MaintenanceMatrixDbTest.php';

	/**
	 * File (from the repository's root) => array( counts, what covers its uses: test methods of the matrix as
	 * "unit:<method>" or "db:<method>", and in words what is not in the matrix and why ).
	 */
	const READERS = array(
		'src/Cli/HeldGuard.php'       => array(
			array(
				'reader' => 1,
				'name'   => 3,
			),
			array( 'not in the matrix: held_in() for the WP-CLI guard, which writes and deletes nothing; what it answers where .maintenance is deleted or cannot be read is a change of its own, to be made before a release; the names are in its message' ),
		),
		'src/Jobs/HeldSite.php'       => array(
			array( 'object' => 3 ),
			array( 'unit:test_once_a_take_down_says_gone_nothing_answers_as_held_for_the_restore_and_nothing_of_anyone_elses_is_deleted', 'state() for what job status shows: nothing follows from it' ),
		),
		'src/Jobs/JobActions.php'     => array(
			array(
				'reader' => 1,
				'object' => 1,
				'name'   => 1,
			),
			array( 'unit:test_once_a_take_down_says_gone_nothing_answers_as_held_for_the_restore_and_nothing_of_anyone_elses_is_deleted', 'mark_in() finds the job whose file was left; the name is in a message' ),
		),
		'src/Jobs/JobRepository.php'  => array(
			array(
				'reader' => 2,
				'needed' => 1,
			),
			array( 'db:test_the_reaper_deletes_nothing_a_held_answer_could_follow_from_while_a_job_may_stand_behind_it', 'db:test_the_reaper_keeps_a_file_of_a_run_without_wordpress_it_cannot_read_while_a_job_holds_the_site' ),
		),
		'src/Jobs/Residue.php'        => array(
			array( 'name' => 2 ),
			array( 'not in the matrix: the names the reaper lists entries by; what is deleted is JobRepository\'s to judge' ),
		),
		'src/Jobs/RunnerTexts.php'    => array(
			array( 'name' => 2 ),
			array( 'not in the matrix: messages' ),
		),
		'src/Jobs/RuntimeLog.php'     => array(
			array( 'reader' => 1 ),
			array( 'not in the matrix: data_mark() decides whether a line goes into the runtime log or into PHP\'s log; neither file is written or deleted' ),
		),
		'src/Jobs/SwapStep.php'       => array(
			array(
				'object' => 2,
				'name'   => 7,
			),
			array(
				'unit:test_once_a_release_returns_nothing_answers_as_held_for_the_restore_and_it_waits_where_a_file_cannot_be_told',
				'unit:test_once_a_take_down_says_gone_nothing_answers_as_held_for_the_restore_and_nothing_of_anyone_elses_is_deleted',
				'unit:test_a_put_up_writes_nothing_where_the_place_is_someone_elses_or_cannot_be_told',
				'the names are in messages',
			),
		),
		'src/Jobs/WebJobs.php'        => array(
			array( 'object' => 1 ),
			array( 'unit:test_a_job_is_taken_on_only_by_its_own_files_read_and_always_then' ),
		),
		'src/Plugin.php'              => array(
			array(
				'reader' => 3,
				'needed' => 1,
				'name'   => 6,
			),
			array( 'db:test_a_deactivation_takes_the_loader_only_where_both_files_positively_name_no_restore', 'not in the matrix: held_in() and mark_in() for the notices (nothing is written or deleted by them), passes() for the cron rule of a request let through (MaintenanceTest: the same answer as the code\'s); the names are in messages' ),
		),
		'src/Restore/Loader.php'      => array(
			array(
				'reader' => 2,
				'name'   => 1,
			),
			array( 'unit:test_every_reading_of_the_two_files_is_one_of_three_states', 'unit:test_the_fixture_tells_what_the_fixed_codes_would_answer' ),
		),
		'src/Restore/Maintenance.php' => array(
			array( 'name' => 24 ),
			array( 'METHODS, method by method', 'unit:test_the_fixture_tells_what_the_fixed_codes_would_answer' ),
		),
		'src/Support/AtomicFile.php'  => array(
			array( 'name' => 3 ),
			array( 'not in the matrix: the names it writes with the maintenance file\'s care; it reads neither' ),
		),
		'src/Support/Deleter.php'     => array(
			array( 'name' => 1 ),
			array( 'not in the matrix: the names delete_maintenance_file() deletes; what is deleted is the caller\'s to judge' ),
		),
		'src/Support/Uninstaller.php' => array(
			array(
				'reader' => 1,
				'needed' => 1,
			),
			array( 'db:test_an_uninstall_runs_only_without_a_holder_and_leaves_nothing_the_loader_would_answer_for' ),
		),
	);

	/**
	 * Every public method of Restore\Maintenance => what covers it (as in READERS).
	 */
	const METHODS = array(
		'held'          => 'unit:test_the_held_times_are_one_set_for_the_loader_the_maintenance_code_and_the_plugin',
		'held_for'      => 'unit:test_the_held_times_are_one_set_for_the_loader_the_maintenance_code_and_the_plugin',
		'is_held_time'  => 'unit:test_the_held_times_are_one_set_for_the_loader_the_maintenance_code_and_the_plugin',
		'held_in'       => 'not in the matrix: the notices and the WP-CLI guard; nothing is written or deleted by its answer (the guard: a change of its own)',
		'mark_in'       => 'not in the matrix: finds a job by the file\'s mark, for a notice and for the take-down of a file left (remove() judges the file again)',
		'code_in'       => 'unit:test_every_reading_of_the_two_files_is_one_of_three_states',
		'data_in'       => 'unit:test_every_reading_of_the_two_files_is_one_of_three_states',
		'data_mark'     => 'not in the matrix: where a log line goes (Jobs\RuntimeLog)',
		'names_restore' => 'unit:test_every_reading_of_the_two_files_is_one_of_three_states',
		'passes'        => 'not in the matrix: lets a request through only on both files read (MaintenanceTest, against the code)',
		'check_code'    => 'not in the matrix: reads neither file',
		'__construct'   => 'not in the matrix: reads neither file',
		'new_mark'      => 'not in the matrix: reads neither file',
		'data'          => 'not in the matrix: reads neither file',
		'runtime_hash'  => 'not in the matrix: what a put-up keeps of the data file it rewrites, after state() let it',
		'legacy'        => 'not in the matrix: reads neither file',
		'state'         => 'unit:test_a_put_up_writes_nothing_where_the_place_is_someone_elses_or_cannot_be_told',
		'time'          => 'not in the matrix: this restore\'s own time, for is_held() and what job status shows',
		'put'           => 'unit:test_a_put_up_writes_nothing_where_the_place_is_someone_elses_or_cannot_be_told',
		'hold'          => 'unit:test_a_put_up_writes_nothing_where_the_place_is_someone_elses_or_cannot_be_told',
		'is_held'       => 'not in the matrix: whether the swap holds its file again (hold(), which refuses what cannot be told)',
		'left_held'     => 'unit:test_a_job_is_taken_on_only_by_its_own_files_read_and_always_then',
		'claimable'     => 'unit:test_a_job_is_taken_on_only_by_its_own_files_read_and_always_then',
		'let_go'        => 'unit:test_once_a_release_returns_nothing_answers_as_held_for_the_restore_and_it_waits_where_a_file_cannot_be_told',
		'remove'        => 'unit:test_once_a_take_down_says_gone_nothing_answers_as_held_for_the_restore_and_nothing_of_anyone_elses_is_deleted',
		'log_lines'     => 'not in the matrix: the runtime log, not one of the two files',
		'web_mark'      => 'not in the matrix: a file of a run without WordPress, not one of the two files',
		'web_names'     => 'db:test_the_reaper_keeps_a_file_of_a_run_without_wordpress_it_cannot_read_while_a_job_holds_the_site',
		'path'          => 'not in the matrix: reads neither file',
		'data_path'     => 'not in the matrix: reads neither file',
	);

	/**
	 * What a source reads of the two files, by kind (comments left out).
	 *
	 * @param string $source PHP source.
	 * @return array<string, int>
	 */
	public static function scan_source( string $source ): array {
		$code = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}
		$kinds = array(
			'reader' => '/\bMaintenance::(?:held_in|mark_in|code_in|data_in|data_mark|names_restore|passes)\s*\(/',
			'object' => '/\bnew\s+(?:\\\\?(?:WPCheckpoint\\\\)?(?:Restore\\\\)?)?Maintenance\s*\(/',
			'needed' => '/\bLoader::needed\s*\(/',
			'name'   => '/(?<![A-Za-z0-9_-])\.maintenance(?![A-Za-z0-9_.-]*\.tmp)\b|\.wpcheckpoint-maintenance\.php|\bMaintenance::(?:FILE|DATA)\b|\bself::(?:FILE|DATA)\b/',
		);
		$out   = array();
		foreach ( $kinds as $kind => $pattern ) {
			$count = (int) preg_match_all( $pattern, $code );
			if ( $count > 0 ) {
				$out[ $kind ] = $count;
			}
		}
		return $out;
	}

	/**
	 * What differs between what the scan found and what is registered.
	 *
	 * @param array<string, array<string, int>>                         $found      File => counts.
	 * @param array<string, array{0: array<string, int>, 1: string[]}> $registered READERS.
	 * @return string[]
	 */
	public static function differences( array $found, array $registered ): array {
		$out = array();
		foreach ( $found as $file => $counts ) {
			if ( ! isset( $registered[ $file ] ) ) {
				$out[] = $file . ' reads .maintenance or its data file and is not registered: ' . json_encode( $counts );
			} elseif ( $registered[ $file ][0] !== $counts ) {
				$out[] = $file . ': registered ' . json_encode( $registered[ $file ][0] ) . ', found ' . json_encode( $counts );
			}
		}
		foreach ( array_keys( $registered ) as $file ) {
			if ( ! isset( $found[ $file ] ) ) {
				$out[] = $file . ' is registered and reads neither file any more';
			}
		}
		return $out;
	}

	/**
	 * The plugin's code, scanned.
	 *
	 * @return array<string, array<string, int>>
	 */
	private static function found(): array {
		$root  = dirname( __DIR__, 3 );
		$paths = array( $root . '/wp-checkpoint.php', $root . '/uninstall.php' );
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$paths[] = (string) $file;
			}
		}
		$out = array();
		foreach ( $paths as $path ) {
			$counts = self::scan_source( (string) file_get_contents( $path ) );
			if ( array() !== $counts ) {
				$out[ str_replace( '\\', '/', substr( $path, strlen( $root ) + 1 ) ) ] = $counts;
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Why what covers a use is not what it says ('' when it is): a test method named must be in the matrix's file.
	 *
	 * @param string $by "unit:<method>", "db:<method>", or words.
	 * @return string
	 */
	private static function not_covered( string $by ): string {
		if ( 1 !== preg_match( '/\A(unit|db):(test_[a-z0-9_]+)\z/', $by, $found ) ) {
			return strlen( $by ) >= 20 && false === strpos( $by, ':test_' ) ? '' : 'neither a test of the matrix nor a reason: ' . $by;
		}
		$file = dirname( __DIR__, 3 ) . '/' . ( 'unit' === $found[1] ? self::UNIT : self::DB );
		return false !== strpos( (string) file_get_contents( $file ), 'public function ' . $found[2] . '(' ) ? '' : 'no such test in the matrix: ' . $by;
	}

	public function test_every_reader_of_the_two_files_in_the_plugins_code_is_registered_with_what_covers_it(): void {
		$found = self::found();
		$this->assertSame( array(), self::differences( $found, self::READERS ), 'register the reader in READERS, with the matrix test that covers it or why none does' );
		$this->assertGreaterThan( 10, count( $found ), 'the control: the scan finds the readers' );
		foreach ( self::READERS as $file => $entry ) {
			$this->assertNotSame( array(), $entry[1], $file . ' says what covers it' );
			foreach ( $entry[1] as $by ) {
				$this->assertSame( '', self::not_covered( $by ), $file );
			}
		}
	}

	public function test_every_public_method_of_the_class_is_in_the_matrix_or_says_why_not(): void {
		$methods = array();
		foreach ( ( new \ReflectionClass( Maintenance::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			$methods[] = $method->getName();
		}
		sort( $methods );
		$registered = array_keys( self::METHODS );
		sort( $registered );
		$this->assertSame( $methods, $registered, 'a new public method is put in the matrix, or says why it need not be; one gone is taken out' );
		foreach ( self::METHODS as $method => $by ) {
			$this->assertSame( '', self::not_covered( $by ), $method );
		}
	}

	public function test_the_scan_finds_each_kind_of_reader_and_what_is_not_registered_is_reported(): void {
		// The controls of the scan: each kind in a source made for it; nothing in comments.
		$this->assertSame( array( 'reader' => 2 ), self::scan_source( "<?php\nif ( Maintenance::held_in( \$dir ) || '' !== \\WPCheckpoint\\Restore\\Maintenance::data_in( \$dir )[1] ) {}\n" ) );
		$this->assertSame( array( 'object' => 3 ), self::scan_source( "<?php\n\$a = new Maintenance( \$d, \$m );\n\$b = new \\WPCheckpoint\\Restore\\Maintenance( \$d, \$m );\n\$c = new Restore\\Maintenance( \$d, \$m );\n" ) );
		$this->assertSame( array( 'needed' => 1 ), self::scan_source( "<?php\nreturn Restore\\Loader::needed( \$dir );\n" ) );
		$this->assertSame( array( 'name' => 4 ), self::scan_source( "<?php\n\$a = @file_get_contents( \$dir . '/.maintenance' );\n\$b = \$dir . '/.wpcheckpoint-maintenance.php';\n\$c = Maintenance::DATA;\n\$d = self::FILE;\n" ) );
		$this->assertSame( array(), self::scan_source( "<?php\n// Maintenance::held_in( \$dir ) reads .maintenance\n/** new Maintenance( 1, 2 ); Loader::needed( \$d ) */\n\$a = '.maintenance.0123456789abcdef.tmp';\n\$b = 'in maintenance mode';\n" ), 'comments, a temporary name and other words are not readers' );

		// A reader that is not registered is reported; so is a count that changed, and a registration gone stale.
		$found  = self::found();
		$source = "<?php\nfunction wpcheckpoint_new_reader( string \$dir ): bool {\n\treturn is_string( @file_get_contents( \$dir . '/.wpcheckpoint-maintenance.php' ) );\n}\n";
		$new    = $found + array( 'src/Support/NewReader.php' => self::scan_source( $source ) );
		$this->assertSame( array( 'src/Support/NewReader.php reads .maintenance or its data file and is not registered: {"name":1}' ), self::differences( $new, self::READERS ) );
		$more                               = $found;
		$more['src/Jobs/WebJobs.php']['object'] = 2;
		$this->assertSame( array( 'src/Jobs/WebJobs.php: registered {"object":1}, found {"object":2}' ), self::differences( $more, self::READERS ) );
		$less = $found;
		unset( $less['src/Jobs/RuntimeLog.php'] );
		$this->assertSame( array( 'src/Jobs/RuntimeLog.php is registered and reads neither file any more' ), self::differences( $less, self::READERS ) );
		// And what covers a use must be there.
		$this->assertSame( '', self::not_covered( 'unit:test_every_reading_of_the_two_files_is_one_of_three_states' ) );
		$this->assertNotSame( '', self::not_covered( 'unit:test_no_such_test' ) );
		$this->assertNotSame( '', self::not_covered( 'db:test_no_such_test' ) );
		$this->assertNotSame( '', self::not_covered( 'covered' ), 'a word is not a reason' );
	}
}
