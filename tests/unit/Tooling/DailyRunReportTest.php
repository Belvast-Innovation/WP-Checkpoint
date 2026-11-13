<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use WPCheckpoint\Tests\Fixtures\Sandbox;

/**
 * bin/daily-run-report.sh, which says in one issue what the daily run ended as: the issue is its own only by its
 * author, its label and its title together. One that somebody else opened under the same title, or one without the
 * label, is neither commented on nor closed. Run against a stand-in for gh that records what it was asked.
 */
final class DailyRunReportTest extends TestCase {

	private const TITLE = 'The daily integration run in random order fails on main';

	private const BOT = 'github-actions[bot]';

	/** @var string */
	private $sandbox = '';

	protected function setUp(): void {
		parent::setUp();
		if ( '\\' === DIRECTORY_SEPARATOR ) {
			$this->markTestSkipped( 'A bash script.' );
		}
		$this->sandbox = Sandbox::make( 'daily-report' );
		mkdir( $this->sandbox . '/bin' );
		// The stand-in: every call is one line of the record; the listing of issues is the file the test wrote.
		file_put_contents(
			$this->sandbox . '/bin/gh',
			"#!/usr/bin/env bash\n"
			. "printf '%s\\n' \"\$*\" >> \"\$GH_RECORD\"\n"
			. "case \"\$*\" in\n"
			. "\t'api --paginate -X GET repos/owner/repo/issues '*) [ -n \"\${GH_LIST_FAILS:-}\" ] && exit 4; cat \"\$GH_LISTING\" ;;\n"
			. "\t'label create '*) [ -n \"\${GH_LABELS:-}\" ] && { echo 'already exists or refused' >&2; exit 1; } ;;\n"
			. "\t'label list '*) printf '%b' \"\${GH_LABELS:-}\" ;;\n"
			. "esac\n"
			. "exit 0\n"
		);
		chmod( $this->sandbox . '/bin/gh', 0755 );
	}

	protected function tearDown(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tearDown();
	}

	/**
	 * A line of the listing, as the script asks gh to print an issue.
	 *
	 * @param int      $number Number.
	 * @param string   $author Login of who opened it.
	 * @param string[] $labels Labels.
	 * @param string   $title  Title.
	 * @return string
	 */
	private static function issue( int $number, string $author, array $labels, string $title ): string {
		return implode( "\t", array( $number, $author, implode( ',', $labels ), $title ) ) . "\n";
	}

	/**
	 * Run the script.
	 *
	 * @param string               $result  The run's result.
	 * @param string               $listing The open issues with the label, as gh prints them.
	 * @param array<string,string> $env     More environment.
	 * @return array{0: int, 1: string[], 2: string} Its status, what gh was asked (a line a call), its output.
	 */
	private function report( string $result, string $listing, array $env = array() ): array {
		file_put_contents( $this->sandbox . '/listing', $listing );
		file_put_contents( $this->sandbox . '/record', '' );
		$pipes   = array();
		$process = proc_open(
			array( 'bash', dirname( __DIR__, 3 ) . '/bin/daily-run-report.sh' ),
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes,
			$this->sandbox,
			$env + array(
				'PATH'       => $this->sandbox . '/bin' . PATH_SEPARATOR . (string) getenv( 'PATH' ),
				'TMPDIR'     => $this->sandbox,
				'GH_RECORD'  => $this->sandbox . '/record',
				'GH_LISTING' => $this->sandbox . '/listing',
				'RESULT'     => $result,
				'RUN_URL'    => 'https://example.test/run/9',
				'RUN_ID'     => '9',
				'GH_REPO'    => 'owner/repo',
				'GITHUB_SHA' => 'abc123',
			)
		);
		$this->assertIsResource( $process );
		$out    = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
		$status = proc_close( $process );
		$asked  = array_values( array_filter( explode( "\n", (string) file_get_contents( $this->sandbox . '/record' ) ), 'strlen' ) );
		return array( $status, $asked, $out );
	}

	/**
	 * The calls that change an issue.
	 *
	 * @param string[] $asked What gh was asked.
	 * @return string[] "comment 7", "close 7", "create" (with its label).
	 */
	private static function changes( array $asked ): array {
		$out = array();
		foreach ( $asked as $line ) {
			if ( 1 === preg_match( '/^issue (comment|close) (\S+)/', $line, $m ) ) {
				$out[] = $m[1] . ' ' . $m[2];
			} elseif ( 1 === preg_match( '/^issue create .*--label (\S+)/', $line, $m ) ) {
				$out[] = 'create labelled ' . $m[1];
			} elseif ( 0 === strpos( $line, 'issue ' ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	public function test_the_issues_are_asked_for_open_and_by_the_label(): void {
		list( $status, $asked, $out ) = $this->report( 'success', '' );
		$this->assertSame( 0, $status, $out );
		$this->assertCount( 1, $asked, 'a run that passed with no issue of its own asks for the list and nothing more' );
		$this->assertStringStartsWith( 'api --paginate -X GET repos/owner/repo/issues -f state=open -f labels=daily-run ', $asked[0] );
	}

	public function test_its_own_issue_is_added_to_by_a_failed_run_and_closed_by_one_that_passed(): void {
		$own = self::issue( 7, self::BOT, array( 'bug', 'daily-run' ), self::TITLE );

		list( $status, $asked, $out ) = $this->report( 'failure', $own );
		$this->assertSame( 0, $status, $out );
		$this->assertSame( array( 'comment 7' ), self::changes( $asked ), 'the control: the script finds an issue and adds to it' );

		list( $status, $asked, $out ) = $this->report( 'success', $own );
		$this->assertSame( 0, $status, $out );
		$this->assertSame( array( 'close 7' ), self::changes( $asked ), 'the control: the script finds an issue and closes it' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function not_its_own(): array {
		return array(
			'somebody else opened it, same title and label' => array( self::issue( 3, 'somebody', array( 'daily-run' ), self::TITLE ) ),
			'an account named like the workflow\'s without the suffix' => array( self::issue( 3, 'github-actions', array( 'daily-run' ), self::TITLE ) ),
			'the workflow\'s account, without the label'    => array( self::issue( 3, self::BOT, array( 'bug' ), self::TITLE ) ),
			'the workflow\'s account, a label that only contains the name' => array( self::issue( 3, self::BOT, array( 'not-daily-run', 'daily-run-old' ), self::TITLE ) ),
			'the workflow\'s account and label, another title' => array( self::issue( 3, self::BOT, array( 'daily-run' ), self::TITLE . ' again' ) ),
		);
	}

	/**
	 * @dataProvider not_its_own
	 */
	public function test_an_issue_that_is_not_its_own_is_neither_added_to_nor_closed( string $other ): void {
		list( $status, $asked, $out ) = $this->report( 'failure', $other );
		$this->assertSame( 0, $status, $out );
		$this->assertSame( array( 'create labelled daily-run' ), self::changes( $asked ), 'a failed run opens its own issue, with the label, and leaves the other alone' );

		list( $status, $asked, $out ) = $this->report( 'success', $other );
		$this->assertSame( 0, $status, $out );
		$this->assertSame( array(), self::changes( $asked ), 'a run that passed leaves it open' );

		// With its own issue listed after the other one, it is its own that is changed.
		$both                         = $other . self::issue( 7, self::BOT, array( 'daily-run' ), self::TITLE );
		list( $status, $asked, $out ) = $this->report( 'failure', $both );
		$this->assertSame( 0, $status, $out );
		$this->assertSame( array( 'comment 7' ), self::changes( $asked ) );
		list( $status, $asked, $out ) = $this->report( 'success', $both );
		$this->assertSame( 0, $status, $out );
		$this->assertSame( array( 'close 7' ), self::changes( $asked ) );
	}

	public function test_a_list_that_cannot_be_read_ends_the_script_and_no_issue_is_changed_or_opened(): void {
		list( $status, $asked, $out ) = $this->report( 'failure', self::issue( 7, self::BOT, array( 'daily-run' ), self::TITLE ), array( 'GH_LIST_FAILS' => '1' ) );
		$this->assertSame( 4, $status, $out );
		$this->assertSame( array(), self::changes( $asked ), 'not knowing whether it has an issue, it opens none' );
		$this->assertCount( 1, $asked );
	}

	public function test_a_label_that_is_there_already_is_used_and_one_that_cannot_be_made_opens_no_issue(): void {
		list( $status, $asked, $out ) = $this->report( 'failure', '', array( 'GH_LABELS' => 'daily-run-old\ndaily-run\n' ) );
		$this->assertSame( 0, $status, $out );
		$this->assertSame( array( 'create labelled daily-run' ), self::changes( $asked ), 'the label could not be made because it is there: the issue is opened with it' );

		list( $status, $asked, $out ) = $this->report( 'failure', '', array( 'GH_LABELS' => 'daily-run-old\nnot-daily-run\n' ) );
		$this->assertSame( 1, $status, $out );
		$this->assertSame( array(), self::changes( $asked ), 'without its label the issue would not be found again: none is opened' );
		$this->assertStringContainsString( 'already exists or refused', $out, 'why the label could not be made is said' );
	}

	public function test_the_workflow_runs_the_script_and_only_its_report_job_may_write_issues(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/workflows/random-order.yml' );
		$this->assertStringContainsString( 'run: bash bin/daily-run-report.sh', $workflow );
		$this->assertSame( 1, substr_count( $workflow, 'issues: write' ) );
		$this->assertSame( 0, substr_count( $workflow, 'gh issue ' ), 'the issue is changed by the script alone' );
	}
}
