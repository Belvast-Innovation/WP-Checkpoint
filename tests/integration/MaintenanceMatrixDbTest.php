<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\Residue;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Maintenance;
use WPCheckpoint\Support\Uninstaller;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;
use WPCheckpoint\Tests\Fixtures\Restore\MaintenanceStates;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * The removals outside a swap (the reaper's three rules, a deactivation, an uninstall) over every state of the two
 * files a restore holds a site with (MaintenanceStates: .maintenance times the data file), with and without a job
 * that holds the site. What may be deleted is said of each state by what is true of it by construction, not by the
 * code's own judgment:
 *
 * - the data file goes only when .maintenance is positively not this plugin's code, what the data file says can be
 *   told, and (the reaper) no job that holds the site has its mark;
 * - the files of a run without WordPress go only when .maintenance is positively not the code, the data file
 *   positively names no restore (or went by the rule above in the same pass), and no job that holds the site has
 *   their mark;
 * - the loader goes only when no job holds the site, .maintenance is positively not the code and the data file
 *   positively names no restore (or went by the rule above);
 * - .maintenance itself is never touched, and nothing but a regular file is deleted under the data file's name;
 * - an uninstall runs only while no job holds the site (positively), and then leaves nothing the loader would answer
 *   for once every file can be read again, wherever .maintenance is positively not the code.
 *
 * Each rule has its control: states in which the thing must go (a rule that never deletes would hold all of the
 * above), counted.
 */
final class MaintenanceMatrixDbTest extends JobTestCase {

	/** @var string The sandbox ('' before it is made). */
	private $root = '';

	/** @var string This restore's mark. */
	private $mark = '';

	/** @var int The job that holds the site in the states with one. */
	private $holder = 0;

	public function set_up(): void {
		parent::set_up();
		if ( ! MaintenanceStates::can_hide() ) {
			$this->markTestSkipped( 'Files cannot be made unreadable here (root, or Windows).' );
		}
		$this->root = Sandbox::make( 'maintenance-matrix' );
		$this->mark = Maintenance::new_mark();
		$this->assertSame( array(), Plugin::instance()->jobs()->holding_site(), 'the control: no job holds the site before the test' );
		$this->holder = Plugin::instance()->jobs()->create( RestoreJob::ID, self::$admin_id )->id;
		$this->holds( false );
	}

	public function tear_down(): void {
		if ( '' !== $this->root ) {
			foreach ( (array) glob( $this->root . '/*/site' ) as $site ) {
				MaintenanceStates::reveal( (string) $site );
			}
			Sandbox::remove( $this->root );
			$this->root = '';
		}
		parent::tear_down();
	}

	/**
	 * Make the job hold the site (with this restore's mark), or not.
	 *
	 * @param bool $holds Whether it holds.
	 * @return void
	 */
	private function holds( bool $holds ): void {
		global $wpdb;
		$wpdb->update(
			JobRepository::table(),
			array(
				'status'     => Job::FAILED,
				'site_state' => $holds ? Job::SITE_CHANGING : Job::SITE_UNTOUCHED,
				'site_mark'  => $this->mark,
			),
			array( 'id' => $this->holder )
		);
		$this->assertSame( $holds ? array( $this->holder ) : array(), Plugin::instance()->jobs()->holding_site(), 'the control: the holder is as set' );
	}

	/**
	 * Build a state, run a removal on it, and say what broke a rule.
	 *
	 * @param string   $how    'reap', 'uninstall' or 'deactivate'.
	 * @param string   $m      State of .maintenance.
	 * @param string   $d      State of the data file.
	 * @param bool     $holder Whether a job holds the site.
	 * @param string[] $broken Collected: what broke a rule.
	 * @param int[]    $gone   Collected: how often each thing went (the controls).
	 * @return void
	 */
	private function run_state( string $how, string $m, string $d, bool $holder, array &$broken, array &$gone ): void {
		static $n = 0;
		$root  = $this->root . '/' . ( ++$n );
		$age   = 2 * Residue::VERIFY_TTL;
		$dirs  = MaintenanceStates::build( $root, $m, $d, $this->mark, $age );
		$site  = $dirs['site'];
		$mu    = $dirs['mu'];
		$truth = MaintenanceStates::truth( $m, $d );
		$file  = MaintenanceStates::digest( $site . '/' . Maintenance::FILE );
		$was   = MaintenanceStates::there( $site, $mu );
		$prev  = Residue::replace_maintenance_dir( $site );
		try {
			if ( 'deactivate' === $how ) {
				Plugin::remove_loader( $mu, $site );
			} elseif ( 'uninstall' === $how ) {
				Uninstaller::delete_site_residue( null, $mu );
			} else {
				$jobs = new JobRepository( Plugin::instance()->directories() );
				$jobs->with_site_dirs(
					static function () use ( $mu ): array {
						return array( 'mu-plugins' => $mu );
					}
				);
				$jobs->reap_residue();
			}
		} finally {
			Residue::replace_maintenance_dir( $prev );
		}
		$now  = MaintenanceStates::there( $site, $mu );
		$case = $how . ' [.maintenance ' . $m . ', data ' . $d . ', ' . ( $holder ? 'a holder' : 'no holder' ) . ']';
		$went = static function ( string $name ) use ( $was, $now ): bool {
			return $was[ $name ] && ! $now[ $name ];
		};
		if ( MaintenanceStates::digest( $site . '/' . Maintenance::FILE ) !== $file ) {
			$broken[] = $case . ': .maintenance was touched';
		}
		$data_went = $went( Maintenance::DATA );
		if ( $data_went ) {
			$gone['data'] = ( $gone['data'] ?? 0 ) + 1;
			$may          = $truth['d_regular'] && $truth['m_not_code'];
			if ( 'uninstall' !== $how ) {
				$may = $may && ! $truth['d_untellable'] && ! ( $holder && $truth['d_own'] );
			}
			if ( 'deactivate' === $how || ! $may ) {
				$broken[] = $case . ': the data file was deleted';
			}
		}
		$names_none = $truth['d_not'] || $data_went;
		$web_went   = array_values( array_filter( MaintenanceStates::WEB, $went ) );
		if ( array() !== $web_went ) {
			$gone['web'] = ( $gone['web'] ?? 0 ) + 1;
			$may         = $truth['m_not_code'];
			if ( 'uninstall' !== $how ) {
				$may = $may && $names_none && ! $truth['d_untellable'] && ! $holder;
			}
			if ( 'deactivate' === $how || ! $may ) {
				$broken[] = $case . ': files of a run without WordPress were deleted (' . implode( ', ', $web_went ) . ')';
			}
		}
		if ( $went( 'loader' ) ) {
			$gone['loader'] = ( $gone['loader'] ?? 0 ) + 1;
			$may            = ( 'deactivate' === $how || ! $holder ) && $truth['m_not_code'] && $names_none && ( ! $truth['d_untellable'] || ( 'uninstall' === $how && $data_went ) );
			if ( ! $may ) {
				$broken[] = $case . ': the loader was deleted';
			}
		}
		MaintenanceStates::reveal( $site );
		if ( 'uninstall' === $how && $truth['m_not_code'] && MaintenanceStates::answers( $site, $mu )['loader'] ) {
			$broken[] = $case . ': after the uninstall the loader would still answer for a held data file';
		}
		if ( $holder && $truth['d_own'] && $truth['d_held'] ) {
			// The job holds the site with this data file: whatever answered for it before still does.
			$answers = MaintenanceStates::answers( $site, $mu );
			if ( ! $answers['loader'] || $this->mark !== $answers['loader_mark'] ) {
				$broken[] = $case . ': the loader no longer answers for the data file of the job that holds the site';
			}
		}
		Sandbox::remove( $root );
	}

	public function test_the_reaper_deletes_nothing_a_held_answer_could_follow_from_while_a_job_may_stand_behind_it(): void {
		$broken = array();
		$gone   = array();
		$count  = 0;
		foreach ( array( false, true ) as $holder ) {
			$this->holds( $holder );
			$here = array();
			foreach ( MaintenanceStates::all() as $state ) {
				delete_site_transient( 'wpcheckpoint_jobs_reaped' );
				$this->run_state( 'reap', $state[0], $state[1], $holder, $broken, $here );
				++$count;
			}
			foreach ( $here as $what => $times ) {
				$gone[ $what . ( $holder ? ' with a holder' : ' without a holder' ) ] = $times;
			}
		}
		$this->holds( false );
		$this->assertSame( 180, $count, 'every state, with and without a holder' );
		$this->assertSame( array(), $broken, implode( "\n", $broken ) );
		// The controls: each rule deletes where it must (the counts are the states in which each thing may go).
		// Without a holder: the data file in the 5 states of .maintenance that are positively not the code times the 6
		// of a regular data file that can be read; the rest in those 5 times the 8 in which the data file names no
		// restore or went. With one: only a data file that is positively not the holder's (3 states), nothing else.
		ksort( $gone );
		$this->assertSame(
			array(
				'data with a holder'      => 15,
				'data without a holder'   => 30,
				'loader without a holder' => 40,
				'web without a holder'    => 40,
			),
			$gone,
			'what went, by rule: ' . wp_json_encode( $gone )
		);
	}

	public function test_the_reaper_keeps_a_file_of_a_run_without_wordpress_it_cannot_read_while_a_job_holds_the_site(): void {
		$reap = function ( bool $holder ): array {
			static $n = 0;
			$this->holds( $holder );
			$root = $this->root . '/web-' . ( ++$n );
			$dirs = MaintenanceStates::build( $root, 'none', 'none', $this->mark, 2 * Residue::VERIFY_TTL );
			// The runtime cannot be read: whose it is cannot be told.
			chmod( $dirs['site'] . '/' . Maintenance::RUNTIME, 0 );
			$this->assertNull( Maintenance::web_names( $dirs['site'] . '/' . Maintenance::RUNTIME ), 'the control: it cannot be read' );
			$this->assertSame( $this->mark, Maintenance::web_names( $dirs['site'] . '/' . Maintenance::VISITOR ), 'the control: the others name the restore' );
			$prev = Residue::replace_maintenance_dir( $dirs['site'] );
			try {
				delete_site_transient( 'wpcheckpoint_jobs_reaped' );
				$jobs = new JobRepository( Plugin::instance()->directories() );
				$jobs->with_site_dirs(
					static function () use ( $dirs ): array {
						return array( 'mu-plugins' => $dirs['mu'] );
					}
				);
				$jobs->reap_residue();
			} finally {
				Residue::replace_maintenance_dir( $prev );
			}
			$there = MaintenanceStates::there( $dirs['site'], $dirs['mu'] );
			if ( $there[ Maintenance::RUNTIME ] ) {
				chmod( $dirs['site'] . '/' . Maintenance::RUNTIME, 0600 );
			}
			return $there;
		};
		$kept = $reap( true );
		$this->assertTrue( $kept[ Maintenance::RUNTIME ], 'a job holds the site, and the runtime that cannot be read may be its own: kept' );
		$this->assertTrue( $kept[ Maintenance::VISITOR ], 'the control: the holder\'s by its mark' );
		$this->assertTrue( $kept['loader'] );
		$gone = $reap( false );
		$this->assertFalse( $gone[ Maintenance::RUNTIME ], 'the control: no job holds the site, and it is reaped, read or not' );
		$this->assertFalse( $gone[ Maintenance::VISITOR ] );
		$this->assertFalse( $gone['loader'] );
	}

	public function test_an_uninstall_runs_only_without_a_holder_and_leaves_nothing_the_loader_would_answer_for(): void {
		// Uninstaller::run() stops before anything is removed while a job holds the site (UninstallSequencesTest); the
		// residue is the uninstall's to remove only without one.
		$this->holds( true );
		$this->assertSame( 1, Uninstaller::jobs_holding_the_site(), 'while a job holds the site nothing is uninstalled' );
		$this->holds( false );
		$this->assertSame( 0, Uninstaller::jobs_holding_the_site(), 'the control: without one it goes on' );
		$broken = array();
		$gone   = array();
		foreach ( MaintenanceStates::all() as $state ) {
			$this->run_state( 'uninstall', $state[0], $state[1], false, $broken, $gone );
		}
		$this->assertSame( array(), $broken, implode( "\n", $broken ) );
		ksort( $gone );
		$this->assertSame(
			array(
				'data'   => 35,
				'loader' => 45,
				'web'    => 50,
			),
			$gone,
			'what went: ' . wp_json_encode( $gone )
		);
	}

	public function test_a_deactivation_takes_the_loader_only_where_both_files_positively_name_no_restore(): void {
		$broken = array();
		$gone   = array();
		foreach ( array( false, true ) as $holder ) {
			$this->holds( $holder );
			foreach ( MaintenanceStates::all() as $state ) {
				$this->run_state( 'deactivate', $state[0], $state[1], $holder, $broken, $gone );
			}
		}
		$this->holds( false );
		$this->assertSame( array(), $broken, implode( "\n", $broken ) );
		$this->assertSame( array( 'loader' => 40 ), $gone, 'what went: ' . wp_json_encode( $gone ) );
	}
}
