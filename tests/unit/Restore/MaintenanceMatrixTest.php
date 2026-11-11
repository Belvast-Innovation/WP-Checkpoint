<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\Loader;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Tests\Fixtures\Restore\MaintenanceStates;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every user of the two files a restore holds a site with that judges them from the files alone, over every state of
 * the two (MaintenanceStates: 9 of .maintenance times 10 of the data file); the users that also ask the database (the
 * reaper, the uninstall, the deactivation) are in the integration suite (MaintenanceMatrixDbTest). What is expected of
 * a state is said by what is true of it by construction (MaintenanceStates::truth()) and by what the fixed codes
 * would answer once every file can be read again (MaintenanceStates::answers(), with the codes' own patterns), never
 * by the judgment under test.
 *
 * - The readings are three states: the code (code_in()), the data file (data_in(), names_restore()), whether the
 *   loader is still needed (Loader::needed(): unless both positively name no restore).
 * - The claim (claimable()): only the restore's own maintenance file read as a regular file, or, with .maintenance
 *   positively not there, its held data file read as a regular file; and always then.
 * - The release (let_go()): once it returns, nothing answers as held for this restore; it throws, and writes
 *   nothing, exactly where a file cannot be told; someone else's .maintenance is never changed and none is made.
 * - The take-down (remove()): once it says "gone", nothing answers as held for this restore; where .maintenance
 *   cannot be told it deletes nothing; someone else's .maintenance and another restore's data file are never deleted.
 * - A put-up (put()) writes nothing where what is in .maintenance's place is someone else's or cannot be told.
 * - The held times are one set (HELD_VALUES): the loader's pattern, the maintenance code's list and is_held_time()
 *   say the same of each, and no other place in the plugin's code writes them.
 */
final class MaintenanceMatrixTest extends TestCase {

	const MARK = 'WP Checkpoint restore 0123456789abcdef0123456789abcdef';

	/** @var string The sandbox. */
	private $root = '';

	/** @var int States built. */
	private $built = 0;

	protected function set_up(): void {
		parent::set_up();
		if ( ! MaintenanceStates::can_hide() ) {
			$this->markTestSkipped( 'Files cannot be made unreadable here (root, or Windows).' );
		}
		$this->root = Sandbox::make( 'maintenance-matrix' );
	}

	protected function tear_down(): void {
		if ( '' !== $this->root ) {
			foreach ( (array) glob( $this->root . '/*/site' ) as $site ) {
				MaintenanceStates::reveal( (string) $site );
			}
			Sandbox::remove( $this->root );
		}
		parent::tear_down();
	}

	/**
	 * A state, built anew.
	 *
	 * @param string $m State of .maintenance.
	 * @param string $d State of the data file.
	 * @return array{site: string, mu: string, file: Maintenance, truth: array<string, bool>, case: string}
	 */
	private function state( string $m, string $d ): array {
		$dirs = MaintenanceStates::build( $this->root . '/' . ( ++$this->built ), $m, $d, self::MARK, 3600 );
		return $dirs + array(
			'file'  => new Maintenance( $dirs['site'], self::MARK ),
			'truth' => MaintenanceStates::truth( $m, $d ),
			'case'  => '[.maintenance ' . $m . ', data ' . $d . ']',
		);
	}

	/**
	 * Whether anything still answers as held for this restore in a state, once every file can be read.
	 *
	 * @param array<string, mixed> $state state().
	 * @return string What does ('' when nothing).
	 */
	private function held_for_us( array $state ): string {
		MaintenanceStates::reveal( $state['site'] );
		$answers = MaintenanceStates::answers( $state['site'], $state['mu'] );
		foreach ( array( 'code', 'legacy', 'loader' ) as $who ) {
			if ( $answers[ $who ] && self::MARK === $answers[ $who . '_mark' ] ) {
				return $who;
			}
		}
		return '';
	}

	/**
	 * The two files as they are, to tell that nothing was written or deleted.
	 *
	 * @param string $site Directory.
	 * @return string
	 */
	private static function both( string $site ): string {
		return MaintenanceStates::digest( $site . '/' . Maintenance::FILE ) . ' | ' . MaintenanceStates::digest( $site . '/' . Maintenance::DATA );
	}

	public function test_the_fixture_tells_what_the_fixed_codes_would_answer(): void {
		// The controls of the oracle itself: it sees a held site by each of the three, and none where there is none.
		$held = $this->state( 'code', 'held64' );
		$this->assertSame( 'code', $this->held_for_us( $held ) );
		$this->assertTrue( MaintenanceStates::answers( $held['site'], $held['mu'] )['loader'], 'the loader would answer too' );
		$this->assertSame( 'loader', $this->held_for_us( $this->state( 'none', 'held32' ) ) );
		$this->assertSame( 'legacy', $this->held_for_us( $this->state( 'legacy', 'none' ) ) );
		$this->assertSame( '', $this->held_for_us( $this->state( 'code', 'lapsed' ) ) );
		$this->assertSame( '', $this->held_for_us( $this->state( 'none', 'foreign_held' ) ), 'another restore\'s' );
		$hidden = $this->state( 'none', 'unreadable_held' );
		$this->assertFalse( MaintenanceStates::answers( $hidden['site'], $hidden['mu'] )['loader'], 'what cannot be read is no file to the loader' );
		$this->assertSame( 'loader', $this->held_for_us( $hidden ), 'and once it can be read it answers' );
		$this->assertSame( 'loader', $this->held_for_us( $this->state( 'none', 'link_held' ) ), 'the loader reads through a link' );
		$linked = $this->state( 'link_code', 'held64' );
		$this->assertTrue( MaintenanceStates::answers( $linked['site'], $linked['mu'] )['code'], 'a linked code blocks' );
		$this->assertSame( '', MaintenanceStates::answers( $linked['site'], $linked['mu'] )['code_mark'], 'and reads the data file next to what the link points to (none there), not the site\'s' );
		$bare = $this->state( 'code', 'none' );
		$this->assertTrue( MaintenanceStates::answers( $bare['site'], $bare['mu'] )['code'], 'the code with no data file blocks' );
		$this->assertSame( '', $this->held_for_us( $bare ), 'and names no restore' );
		// The patterns are the codes' own.
		$this->assertStringContainsString( Maintenance::HELD_VALUES[0] . '|' . Maintenance::HELD_VALUES[1], MaintenanceStates::pattern_of( Loader::CODE ) );
		$this->assertSame( Maintenance::DATA_FORM, MaintenanceStates::pattern_of( Maintenance::CODE ) );
	}

	public function test_every_reading_of_the_two_files_is_one_of_three_states(): void {
		foreach ( MaintenanceStates::all() as $pair ) {
			$state = $this->state( $pair[0], $pair[1] );
			$truth = $state['truth'];
			$code  = $truth['m_untellable'] ? null : ( $truth['m_code'] ? true : false );
			$this->assertSame( $code, Maintenance::code_in( $state['site'] ), 'code_in ' . $state['case'] );
			$names = $truth['d_untellable'] ? null : $truth['d_names'];
			$this->assertSame( $names, Maintenance::names_restore( $state['site'] ), 'names_restore ' . $state['case'] );
			$told = Maintenance::data_in( $state['site'] );
			$want = $truth['d_untellable'] ? Maintenance::UNKNOWN : ( $truth['d_names'] ? 'data' : ( 'none' === $pair[1] ? Maintenance::NONE : 'not' ) );
			$this->assertSame( $want, $told[0], 'data_in ' . $state['case'] );
			$this->assertSame( $truth['d_names'] ? ( $truth['d_own'] ? self::MARK : 'WP Checkpoint restore ' . str_repeat( 'f', 32 ) ) : '', $told[1], 'data_in\'s mark ' . $state['case'] );
			$this->assertSame( ! ( $truth['m_not_code'] && $truth['d_not'] ), Loader::needed( $state['site'] ), 'the loader is needed unless both positively name no restore ' . $state['case'] );
		}
		$this->assertSame( 90, $this->built );
	}

	public function test_a_job_is_taken_on_only_by_its_own_files_read_and_always_then(): void {
		$taken = 0;
		foreach ( MaintenanceStates::all() as $pair ) {
			list( $m, $d ) = $pair;
			$state         = $this->state( $m, $d );
			$own_file      = 'legacy' === $m || ( 'code' === $m && in_array( $d, array( 'held64', 'held32', 'lapsed' ), true ) );
			$left_held     = 'none' === $m && in_array( $d, array( 'held64', 'held32' ), true );
			$this->assertSame( $own_file || $left_held, $state['file']->claimable(), $state['case'] );
			$this->assertSame( $left_held, $state['file']->left_held(), 'left_held ' . $state['case'] );
			$taken += (int) ( $own_file || $left_held );
		}
		$this->assertSame( 15, $taken, 'the control: the states in which it is taken on' );
	}

	public function test_once_a_release_returns_nothing_answers_as_held_for_the_restore_and_it_waits_where_a_file_cannot_be_told(): void {
		$said = array();
		foreach ( MaintenanceStates::all() as $pair ) {
			list( $m, $d ) = $pair;
			$state         = $this->state( $m, $d );
			$truth         = $state['truth'];
			$before        = self::both( $state['site'] );
			$file_before   = MaintenanceStates::digest( $state['site'] . '/' . Maintenance::FILE );
			$untellable    = in_array( $m, array( 'code_unreadable', 'other_unreadable' ), true ) || $truth['d_untellable'];
			$how           = null;
			try {
				$how = $state['file']->let_go( 1800000000 );
			} catch ( \Throwable $e ) {
				$this->assertTrue( $untellable || ( 'legacy' === $m && 'dir' === $d ), 'it waits only where a file cannot be told (or cannot be written): ' . $state['case'] . ': ' . $e->getMessage() );
				if ( $untellable ) {
					$this->assertSame( $before, self::both( $state['site'] ), 'and writes nothing: ' . $state['case'] );
				}
				$said['waits'] = ( $said['waits'] ?? 0 ) + 1;
				continue;
			}
			$this->assertFalse( $untellable, 'what cannot be told is not taken for nothing there: ' . $state['case'] . ' returned ' . $how );
			$this->assertSame( '', $this->held_for_us( $state ), 'once it returned (' . $how . ') nothing answers as held for the restore: ' . $state['case'] );
			if ( in_array( $m, array( 'code', 'legacy' ), true ) ) {
				$want = 'legacy' === $m || in_array( $d, array( 'none', 'form', 'held64', 'held32', 'lapsed' ), true ) ? Maintenance::LET_GO : Maintenance::NONE;
			} else {
				$want = in_array( $d, array( 'held64', 'held32' ), true ) ? Maintenance::ALONE : Maintenance::NONE;
			}
			$this->assertSame( $want, $how, $state['case'] );
			if ( Maintenance::LET_GO !== $how ) {
				$this->assertSame( $file_before, MaintenanceStates::digest( $state['site'] . '/' . Maintenance::FILE ), 'someone else\'s .maintenance is as it was, and none is made: ' . $state['case'] );
			}
			if ( Maintenance::NONE === $how ) {
				$this->assertSame( $before, self::both( $state['site'] ), 'nothing written: ' . $state['case'] );
			}
			if ( Maintenance::ALONE === $how ) {
				$this->assertSame( "<?php exit; ?>\n" . self::MARK . "\n1800000000\n-\n" . MaintenanceStates::RUNTIME_HASH . "\n", file_get_contents( $state['site'] . '/' . Maintenance::DATA ), 'the time, no pass, the runtime kept: ' . $state['case'] );
			}
			$said[ $how ] = ( $said[ $how ] ?? 0 ) + 1;
		}
		ksort( $said );
		$this->assertSame(
			array(
				Maintenance::ALONE  => 10,
				Maintenance::NONE   => 33,
				Maintenance::LET_GO => 12,
				'waits'             => 35,
			),
			$said,
			'the control: how often each way (the states of each)'
		);
	}

	public function test_once_a_take_down_says_gone_nothing_answers_as_held_for_the_restore_and_nothing_of_anyone_elses_is_deleted(): void {
		$gone = 0;
		foreach ( MaintenanceStates::all() as $pair ) {
			list( $m, $d ) = $pair;
			$state         = $this->state( $m, $d );
			$truth         = $state['truth'];
			$was           = MaintenanceStates::there( $state['site'], $state['mu'] );
			$before        = MaintenanceStates::digest( $state['site'] . '/' . Maintenance::FILE );
			$foreign       = 'foreign_held' === $d ? (string) file_get_contents( $state['site'] . '/' . Maintenance::DATA ) : null;
			$said          = $state['file']->remove();
			$now           = MaintenanceStates::there( $state['site'], $state['mu'] );
			if ( in_array( $m, array( 'code_unreadable', 'other_unreadable' ), true ) || ( 'code' === $m && $truth['d_untellable'] ) ) {
				$this->assertFalse( $said, 'what cannot be told is not gone: ' . $state['case'] );
				$this->assertSame( $was, $now, 'and nothing is deleted: ' . $state['case'] );
			}
			if ( $truth['d_untellable'] ) {
				$this->assertFalse( $said, 'a data file that cannot be told may be the restore\'s: not gone: ' . $state['case'] );
				$this->assertTrue( $now[ Maintenance::DATA ], 'and it is not deleted: ' . $state['case'] );
			}
			if ( $said ) {
				$this->assertSame( '', $this->held_for_us( $state ), 'said gone, and nothing answers as held for the restore: ' . $state['case'] );
				++$gone;
			}
			$ours = 'legacy' === $m || ( 'code' === $m && in_array( $d, array( 'none', 'form', 'held64', 'held32', 'lapsed' ), true ) );
			if ( ! $ours ) {
				$this->assertSame( $before, MaintenanceStates::digest( $state['site'] . '/' . Maintenance::FILE ), 'a .maintenance that is not this restore\'s is as it was: ' . $state['case'] );
			} elseif ( ! $truth['d_untellable'] ) {
				$this->assertFalse( $now[ Maintenance::FILE ], 'its own .maintenance is taken down: ' . $state['case'] );
			}
			if ( null !== $foreign ) {
				$this->assertSame( $foreign, file_get_contents( $state['site'] . '/' . Maintenance::DATA ), 'another restore\'s data file is as it was: ' . $state['case'] );
			}
			$this->assertTrue( $now['loader'], 'the loader is not the take-down\'s to remove: ' . $state['case'] );
		}
		$this->assertSame( 56, $gone, 'the control: the states in which it says gone' );
	}

	public function test_a_take_down_keeps_a_runtime_it_cannot_read_while_another_restores_data_file_stands(): void {
		$runtime = '/' . Maintenance::RUNTIME;
		try {
			// Another restore's held data file left without its .maintenance, and a runtime that cannot be read: it may
			// be what that restore's must-use plugin holds the site by.
			$state = $this->state( 'none', 'foreign_held' );
			chmod( $state['site'] . $runtime, 0 );
			$this->assertFalse( $state['file']->remove(), 'not said to be gone' );
			$there = MaintenanceStates::there( $state['site'], $state['mu'] );
			$this->assertTrue( $there[ Maintenance::RUNTIME ], 'the runtime that cannot be read is kept' );
			$this->assertTrue( $there[ Maintenance::DATA ] );
			$this->assertFalse( $there[ Maintenance::VISITOR ], 'the control: what names this restore is taken down' );
			chmod( $state['site'] . $runtime, 0600 );
			$this->assertTrue( MaintenanceStates::answers( $state['site'], $state['mu'] )['loader'], 'the other restore is still answered for' );
			// A data file that cannot be told: kept as well.
			$hidden = $this->state( 'none', 'unreadable_held' );
			chmod( $hidden['site'] . $runtime, 0 );
			$this->assertFalse( $hidden['file']->remove() );
			$this->assertTrue( MaintenanceStates::there( $hidden['site'], $hidden['mu'] )[ Maintenance::RUNTIME ] );
			chmod( $hidden['site'] . $runtime, 0600 );
			// The control: with no data file left, a runtime that cannot be read goes with the take-down.
			$own = $this->state( 'code', 'held64' );
			chmod( $own['site'] . $runtime, 0 );
			$this->assertTrue( $own['file']->remove() );
			$this->assertFalse( MaintenanceStates::there( $own['site'], $own['mu'] )[ Maintenance::RUNTIME ], 'the control: it goes' );
		} finally {
			foreach ( (array) glob( $this->root . '/*/site' . $runtime ) as $path ) {
				chmod( (string) $path, 0600 );
			}
		}
	}

	public function test_a_put_up_writes_nothing_where_the_place_is_someone_elses_or_cannot_be_told(): void {
		$put = 0;
		foreach ( MaintenanceStates::all() as $pair ) {
			list( $m, $d ) = $pair;
			$state         = $this->state( $m, $d );
			$before        = self::both( $state['site'] );
			$refused       = in_array( $m, array( 'code_unreadable', 'other_unreadable', 'other', 'link_code', 'dir', 'long' ), true )
				|| ( 'code' === $m && in_array( $d, array( 'foreign_held', 'long', 'dir', 'unreadable_held', 'link_held' ), true ) );
			try {
				$state['file']->put( 1800000000 );
				$this->assertFalse( $refused, 'written where it must not be: ' . $state['case'] );
				++$put;
			} catch ( \Throwable $e ) {
				if ( $refused ) {
					$this->assertSame( $before, self::both( $state['site'] ), 'nothing written: ' . $state['case'] );
				} else {
					$this->assertSame( 'dir', $d, 'refused only where it must be, or where it cannot write: ' . $state['case'] . ': ' . $e->getMessage() );
				}
			}
		}
		$this->assertSame( 23, $put, 'the control: the states in which it is put up' );
	}

	public function test_the_held_times_are_one_set_for_the_loader_the_maintenance_code_and_the_plugin(): void {
		$loader = MaintenanceStates::pattern_of( Loader::CODE );
		foreach ( array( Maintenance::HELD_VALUES[0], Maintenance::HELD_VALUES[1], '1800000000', '4102444801', '2147483646', '0' ) as $time ) {
			$held = in_array( $time, Maintenance::HELD_VALUES, true );
			$data = MaintenanceStates::data( self::MARK, $time );
			$this->assertSame( $held, Maintenance::is_held_time( $time ), $time );
			$this->assertSame( $held ? 1 : 0, preg_match( $loader, $data ), 'the loader\'s pattern: ' . $time );
			$this->assertSame( $held, false !== strpos( Maintenance::CODE, "'" . $time . "'" ), 'the maintenance code\'s list: ' . $time );
		}
		$this->assertCount( 2, Maintenance::HELD_VALUES );
		$this->assertSame( (int) Maintenance::HELD_VALUES[0], Maintenance::held_for( 8 ) );
		$this->assertSame( (int) Maintenance::HELD_VALUES[1], Maintenance::held_for( 4 ) );
		// No other place writes them: the two digit strings are in the plugin's code once each, in HELD_VALUES (the
		// archive's size limits use the second as a number of bytes, in src/Archive, which reads neither file).
		$found = array();
		$src   = dirname( __DIR__, 3 ) . '/src';
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$path = str_replace( '\\', '/', substr( (string) $file, strlen( $src ) + 1 ) );
			if ( 0 === strpos( $path, 'Archive/' ) ) {
				continue;
			}
			$count = substr_count( (string) file_get_contents( (string) $file ), Maintenance::HELD_VALUES[0] ) + substr_count( (string) file_get_contents( (string) $file ), Maintenance::HELD_VALUES[1] );
			if ( $count > 0 ) {
				$found[ $path ] = $count;
			}
		}
		$this->assertSame( array( 'Restore/Maintenance.php' => 2 ), $found, 'the held times are written in one place' );
		// The control of the scan: the archive's own use of the number is found when it is not left out.
		$this->assertStringContainsString( Maintenance::HELD_VALUES[1], (string) file_get_contents( $src . '/Archive/Packer.php' ) );
	}
}
