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
			. "\t'label list '*) [ -n \"\${GH_LABELS_UNREAD:-}\" ] && exit 6; printf '%b' \"\${GH_LABELS:-}\" ;;\n"
			// The run's failed jobs (the ids the test gives), a job's name, and the issue's body kept for the test.
			. "\t'api repos/owner/repo/actions/runs/9/jobs '*) printf '%b' \"\${GH_FAILED_JOBS:-}\" ;;\n"
			. "\t'api repos/owner/repo/actions/jobs/'*'/logs'*) echo 'the response contains terminal escape sequences' >&2; exit 1 ;;\n"
			. "\t'api repos/owner/repo/actions/jobs/'*) echo \"job \${2##*/}\" ;;\n"
			. "\t'issue create '*|'issue comment '*) while [ \$# -gt 0 ]; do [ \"\$1\" = --body-file ] && cp \"\$2\" \"\$GH_BODY\"; shift; done ;;\n"
			. "esac\n"
			. "exit 0\n"
		);
		chmod( $this->sandbox . '/bin/gh', 0755 );
		// The stand-in for curl: the log of the job the address names, from the file the test wrote for that job;
		// without one, curl's failure. What it was asked is recorded, the token left out.
		file_put_contents(
			$this->sandbox . '/bin/curl',
			"#!/usr/bin/env bash\n"
			. "out=''; url=''\n"
			. "while [ \$# -gt 0 ]; do case \"\$1\" in -o) out=\"\$2\"; shift ;; -H) shift ;; -*) ;; *) url=\"\$1\" ;; esac; shift; done\n"
			. "printf '%s\\n' \"\$url\" >> \"\$CURL_RECORD\"\n"
			. "job=\"\$(printf '%s' \"\$url\" | sed -E 's#.*/jobs/([0-9]+)/logs\$#\\1#')\"\n"
			. "[ -f \"\$CURL_LOGS/\$job\" ] || { echo 'curl: (22) The requested URL returned error: 403' >&2; exit 22; }\n"
			. "cat \"\$CURL_LOGS/\$job\" > \"\$out\"\n"
		);
		chmod( $this->sandbox . '/bin/curl', 0755 );
		mkdir( $this->sandbox . '/logs' );
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
				'GH_BODY'    => $this->sandbox . '/body',
				'GH_TOKEN'   => 'not-a-token',
				'CURL_RECORD' => $this->sandbox . '/curl-record',
				'CURL_LOGS'  => $this->sandbox . '/logs',
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

	public function test_a_failed_jobs_log_is_read_and_its_seed_and_failed_tests_are_in_the_issue(): void {
		$esc = "\x1b";
		// A job's log as the API gives it: a time before every line, the steps' commands in colours.
		file_put_contents(
			$this->sandbox . '/logs/41',
			"2026-11-15T11:10:30.0293828Z {$esc}[36;1mnpx wp-env run tests-cli{$esc}[0m\n"
			. "2026-11-15T11:10:30.0360743Z   SEED: 2943672378\n"
			. "2026-11-15T11:45:14.0079425Z There was 1 failure:\n"
			. "2026-11-15T11:45:14.0079999Z 1) WPCheckpoint\\Tests\\Integration\\SomeTest::test_it_fails\n"
			. "2026-11-15T11:45:14.0092892Z {$esc}[37;41mTests: 1381, Assertions: 77084, Failures: 1.{$esc}[0m\n"
		);
		list( $status, $asked, $out ) = $this->report( 'failure', '', array( 'GH_FAILED_JOBS' => "41\n42\n" ) );
		$this->assertSame( 0, $status, $out );
		$this->assertSame( array( 'create labelled daily-run' ), self::changes( $asked ) );
		$body = (string) file_get_contents( $this->sandbox . '/body' );
		$this->assertStringContainsString( "job 41\n    SEED: 2943672378\n  1) WPCheckpoint\\Tests\\Integration\\SomeTest::test_it_fails\n  Tests: 1381, Assertions: 77084, Failures: 1.\n", $body, 'the lines of the log, without their times and colours' );
		$this->assertStringNotContainsString( $esc, $body );
		// The job whose log cannot be read: said with why, and no line of the job before it in its place.
		$this->assertStringContainsString( "job 42\n  (its log could not be read: curl: (22) The requested URL returned error: 403", $body );
		$this->assertSame( 1, substr_count( $body, 'SEED: 2943672378' ), $body );
		$this->assertSame(
			array( 'https://api.github.com/repos/owner/repo/actions/jobs/41/logs', 'https://api.github.com/repos/owner/repo/actions/jobs/42/logs' ),
			array_values( array_filter( explode( "\n", (string) file_get_contents( $this->sandbox . '/curl-record' ) ), 'strlen' ) ),
			'each failed job\'s log, asked of the API'
		);
		// The logs are not asked of gh, which refuses a response with escape sequences (the stand-in does too).
		$this->assertSame( array(), preg_grep( '#/logs#', $asked ) );
		$this->assertStringNotContainsString( 'not-a-token', $body . $out );
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
		$this->assertStringContainsString( 'The label daily-run is not there and could not be made: already exists or refused', $out, 'why the label could not be made is said, in the script\'s own line' );

		list( $status, $asked, $out ) = $this->report( 'failure', '', array( 'GH_LABELS' => 'daily-run\n', 'GH_LABELS_UNREAD' => '1' ) );
		$this->assertSame( 6, $status, $out );
		$this->assertSame( array(), self::changes( $asked ), 'the labels could not be listed: whether its label is there is not known, and no issue is opened' );
	}

	public function test_the_workflow_runs_the_script_and_only_its_report_job_may_write_issues(): void {
		$workflow = (string) file_get_contents( dirname( __DIR__, 3 ) . '/.github/workflows/random-order.yml' );
		$this->assertStringContainsString( 'run: bash bin/daily-run-report.sh', $workflow );
		$this->assertSame( 1, substr_count( $workflow, 'issues: write' ) );
		$this->assertSame( 0, substr_count( $workflow, 'gh issue ' ), 'the issue is changed by the script alone' );
	}
}
