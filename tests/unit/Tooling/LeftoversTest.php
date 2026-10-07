<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestResult;
use PHPUnit\Framework\TestSuite;
use WPCheckpoint\Tests\Fixtures\Leftovers;
use WPCheckpoint\Tests\Fixtures\LeftoversStandIn;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use PHPUnit\Util\Log\JUnit;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The integration suite's leftover check, on a list it is given: a test that leaves something fails by name and what
 * it left is removed; a class that leaves something in its class-level set-up fails as a class; the run fails when
 * something is left outside any test; an earlier run's leftovers are reported and removed first.
 */
final class LeftoversTest extends TestCase {

	/** @var string[] What is "there". */
	private $there = array();

	/** @var string[] What the listener removed. */
	private $removed = array();

	/** @var string[] Its notices. */
	private $notices = array();

	/** @var string */
	private $sandbox = '';

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	private function listener(): Leftovers {
		return new Leftovers(
			function (): array {
				return $this->there;
			},
			function ( array $items ): void {
				$this->removed = array_merge( $this->removed, $items );
				$this->there   = array_values( array_diff( $this->there, $items ) );
			},
			function ( string $text ): void {
				$this->notices[] = $text;
			}
		);
	}

	private static function probe( TestResult $result, string $name ): LeftoversProbe {
		$test = new LeftoversProbe( $name );
		$test->setTestResultObject( $result );
		return $test;
	}

	public function test_a_test_that_leaves_something_fails_by_name_and_it_is_removed(): void {
		$result      = new TestResult();
		$listener    = $this->listener();
		$root        = new TestSuite( 'root' );
		$class       = new TestSuite( 'SomeTest' );
		$earlier     = array( 'table:wcptmpabcdef_3_beef_posts', 'temp:/tmp/wpc-plugin-00000000' );
		$this->there = $earlier;

		$listener->startTestSuite( $root );
		$this->assertCount( 1, $this->notices );
		$this->assertStringContainsString( 'wcptmpabcdef_3_beef_posts', $this->notices[0], 'an earlier run\'s leftovers are reported' );
		$kept = array( 'temp:/tmp/wpc-plugin-00000000' );
		$this->assertSame( $kept, $this->there, 'its tables removed (the database is this run\'s), anything else left alone' );
		$this->assertSame( array( 'table:wcptmpabcdef_3_beef_posts' ), $this->removed );
		$this->assertSame( 0, $result->failureCount(), 'they fail nothing' );
		$listener->startTestSuite( $class );

		$clean = self::probe( $result, 'test_clean' );
		$listener->startTest( $clean );
		$listener->endTest( $clean, 0.1 );
		$this->assertSame( 0, $result->failureCount(), 'the control: a test that leaves nothing passes' );

		$leaky = self::probe( $result, 'test_leaky' );
		$listener->startTest( $leaky );
		$this->there[] = 'table:wcpoldabcdef_4_beef_options';
		$this->there[] = 'temp:/tmp/wp-checkpoint-stage-x';
		$listener->endTest( $leaky, 0.1 );
		$this->assertSame( 1, $result->failureCount() );
		$failure = $result->failures()[0];
		$this->assertSame( $leaky, $failure->failedTest() );
		$this->assertStringContainsString( 'wcpoldabcdef_4_beef_options', $failure->exceptionMessage() );
		$this->assertStringContainsString( 'wp-checkpoint-stage-x', $failure->exceptionMessage() );
		$this->assertStringNotContainsString( 'wpc-plugin-00000000', $failure->exceptionMessage(), 'not what was there before' );
		$this->assertSame( $kept, $this->there, 'what it left is removed, so the next test starts clean; nothing else' );

		$next = self::probe( $result, 'test_next' );
		$listener->startTest( $next );
		$listener->endTest( $next, 0.1 );
		$this->assertSame( 1, $result->failureCount(), 'the next test is not blamed' );

		$listener->endTestSuite( $class );
		$listener->endTestSuite( $root );
		$this->assertSame( 1, $result->failureCount(), 'nothing new left at the end' );
	}

	public function test_an_earlier_runs_maintenance_file_goes_at_the_start_like_its_tables(): void {
		$listener    = $this->listener();
		$this->there = array( 'maintenance:/srv/site/.maintenance', 'temp:/tmp/wpc-plugin-00000000' );
		$listener->startTestSuite( new TestSuite( 'root' ) );
		$this->assertSame( array( 'maintenance:/srv/site/.maintenance' ), $this->removed, 'handed to the removal (which deletes this plugin\'s only)' );
		$this->assertSame( array( 'temp:/tmp/wpc-plugin-00000000' ), $this->there, 'the control: another run\'s temporary entry stays' );
	}

	public function test_an_earlier_runs_other_tables_are_reported_and_removed_at_the_start_and_name_a_run_that_kept_the_lock(): void {
		$strays   = array( 'table:wp_swt_keep' );
		$listener = new Leftovers(
			function (): array {
				return $this->there;
			},
			function ( array $items ): void {
				$this->removed = array_merge( $this->removed, $items );
			},
			function ( string $text ): void {
				$this->notices[] = $text;
			},
			static function () use ( &$strays ): array {
				return $strays;
			}
		);
		$before = getenv( 'WPCHECKPOINT_TEST_STALE_RUN_ID' );
		putenv( 'WPCHECKPOINT_TEST_STALE_RUN_ID=0123456789abcdef' );
		try {
			$listener->startTestSuite( new TestSuite( 'root' ) );
		} finally {
			putenv( false === $before ? 'WPCHECKPOINT_TEST_STALE_RUN_ID' : 'WPCHECKPOINT_TEST_STALE_RUN_ID=' . $before );
		}
		$this->assertSame( array( 'table:wp_swt_keep' ), $this->removed, 'a fixture table an earlier run left goes' );
		$this->assertCount( 1, $this->notices );
		$this->assertStringContainsString( 'table:wp_swt_keep', $this->notices[0] );
		$this->assertStringContainsString( '0123456789abcdef, which ended without releasing the lock', $this->notices[0] );
		// Read at the start of the run only: one that shows up later is not a test's leftover by this check.
		$strays   = array( 'table:wp_swt_new' );
		$result   = new TestResult();
		$test     = self::probe( $result, 'test_one' );
		$listener->startTest( $test );
		$listener->endTest( $test, 0.1 );
		$this->assertSame( 0, $result->failureCount() );
	}

	public function test_the_tables_that_are_neither_wordpresss_nor_the_plugins_are_strays(): void {
		$global = array( 'users', 'usermeta', 'blogs', 'site', 'sitemeta' );
		$blog   = array( 'posts', 'options', 'postmeta' );
		$all    = array(
			'wp_posts',
			'wp_users',
			'wp_options',
			'wp_2_posts',
			'wp_16_options',
			'wp_wpcheckpoint_jobs',
			'wp_wpcheckpoint_swap_plan',
			'wp_wpcheckpoint_fence',
			'wcptmpabcdef_3_beef_posts',
			'wcptmp_a',
			'wcpold_child',
			'wp_swt_keep',
			'wp_swt_new',
			'wpcx_blob',
			'wpcpother_t',
			'wp_2_users',
			'wp_0_posts',
			'other_posts',
		);
		$this->assertSame(
			array( 'wp_swt_keep', 'wp_swt_new', 'wpcx_blob', 'wpcpother_t', 'wp_2_users', 'wp_0_posts', 'other_posts' ),
			Leftovers::stray_tables( $all, 'wp_', $global, $blog )
		);
	}

	public function test_a_class_that_leaves_something_fails_as_a_class_and_so_does_the_run(): void {
		$result   = new TestResult();
		$listener = $this->listener();
		$seen     = new LeftoversRecorder();
		$result->addListener( $listener ); // As in a run: the stand-in's start and end reach it too.
		$result->addListener( $seen );
		$root  = new TestSuite( 'root' );
		$class = new TestSuite( 'ClassLevelTest' );
		$listener->startTestSuite( $root );
		$listener->startTestSuite( $class );
		$this->there[] = 'table:wcptmpabcdef_9_beef_made_before_class'; // set_up_before_class().
		$test          = self::probe( $result, 'test_one' );
		$result->startTest( $test );
		$result->endTest( $test, 0.1 );
		$this->assertSame( 0, $result->failureCount(), 'not the test\'s' );
		$listener->endTestSuite( $class );
		$this->assertSame( 1, $result->failureCount() );
		$stand_in = $result->failures()[0]->failedTest();
		$this->assertInstanceOf( LeftoversStandIn::class, $stand_in );
		$this->assertSame( 'leftovers of ClassLevelTest', $stand_in->getName() );
		$this->assertStringContainsString( 'made_before_class', $result->failures()[0]->exceptionMessage() );
		$this->assertSame( array( 'start leftovers of ClassLevelTest', 'failure leftovers of ClassLevelTest', 'end leftovers of ClassLevelTest' ), array_slice( $seen->events, -3 ), 'started, failed and ended like a test, so every log records it' );

		$this->there[] = 'temp:/tmp/wpcheckpoint-verify-00000000'; // Outside any class.
		$listener->endTestSuite( $root );
		$this->assertSame( 2, $result->failureCount(), 'the run ends with none' );
		$this->assertSame( 'leftovers of root', $result->failures()[1]->failedTest()->getName() );
		$this->assertSame( array(), $this->there );
		$this->assertSame( 0.1, $result->time(), 'the stand-ins and failures add no time' );
	}

	public function test_a_class_and_the_run_are_recorded_in_the_junit_log(): void {
		$this->sandbox = Sandbox::make( 'leftovers-junit' );
		$file          = $this->sandbox . '/junit.xml';
		$result        = new TestResult();
		$junit         = new JUnit( $file );
		// In the order PHPUnit's runner adds them: the configuration's listeners, then the loggers.
		$result->addListener( $this->listener() );
		$result->addListener( $junit );
		$root  = new TestSuite( 'root' );
		$class = new TestSuite( 'ClassLevelTest' );
		$result->startTestSuite( $root );
		$result->startTestSuite( $class );
		$this->there[] = 'table:wcptmpabcdef_9_beef_made_before_class';
		$test          = self::probe( $result, 'test_one' );
		$result->startTest( $test );
		$result->endTest( $test, 0.1 );
		$result->endTestSuite( $class );
		$this->there[] = 'temp:/tmp/wpcheckpoint-verify-00000000';
		$result->endTestSuite( $root );
		$junit->flush();

		$xml   = new \SimpleXMLElement( (string) file_get_contents( $file ) );
		$names = array();
		foreach ( $xml->xpath( '//testcase' ) as $case ) {
			$names[ (string) $case['name'] ] = isset( $case->failure ) ? (string) $case->failure : '';
		}
		$this->assertArrayHasKey( 'test_one', $names, 'the control: the log records the tests' );
		$this->assertSame( '', $names['test_one'] );
		$this->assertStringContainsString( 'made_before_class', $names['leftovers of ClassLevelTest'] ?? '', 'the class\'s leftovers' );
		$this->assertStringContainsString( 'wpcheckpoint-verify-00000000', $names['leftovers of root'] ?? '', 'the run\'s' );
		$this->assertSame( '2', (string) $xml->testsuite['failures'] );
	}

	public function test_the_temporary_directory_is_looked_at_only_when_it_is_the_runs_own(): void {
		$before = getenv( 'WPCHECKPOINT_TEST_RUN_TMP' );
		try {
			putenv( 'WPCHECKPOINT_TEST_RUN_TMP=' . sys_get_temp_dir() );
			$this->assertSame( realpath( sys_get_temp_dir() ), Leftovers::run_temp_dir(), 'the control: the run\'s own' );
			putenv( 'WPCHECKPOINT_TEST_RUN_TMP=' . dirname( __DIR__, 3 ) );
			$this->assertSame( '', Leftovers::run_temp_dir(), 'named, but not the temporary directory in use' );
			putenv( 'WPCHECKPOINT_TEST_RUN_TMP' );
			$this->assertSame( '', Leftovers::run_temp_dir(), 'shared' );
		} finally {
			putenv( false === $before ? 'WPCHECKPOINT_TEST_RUN_TMP' : 'WPCHECKPOINT_TEST_RUN_TMP=' . $before );
		}
	}

	public function test_outside_the_integration_suite_it_looks_at_nothing(): void {
		$this->assertNotSame( 'integration', getenv( 'WPCHECKPOINT_TEST_SUITE' ) );
		$result   = new TestResult();
		$listener = new Leftovers();
		$test     = self::probe( $result, 'test_one' );
		$listener->startTestSuite( new TestSuite( 'root' ) );
		$listener->startTest( $test );
		$listener->endTest( $test, 0.1 );
		$listener->endTestSuite( new TestSuite( 'root' ) );
		$this->assertSame( 0, $result->failureCount() );
	}
}

/**
 * A test for the listener to watch.
 */
final class LeftoversProbe extends \PHPUnit\Framework\TestCase {

	public function test_nothing(): void {
		$this->assertTrue( true );
	}
}

/**
 * Records what a result tells its listeners.
 */
final class LeftoversRecorder implements \PHPUnit\Framework\TestListener {
	use \PHPUnit\Framework\TestListenerDefaultImplementation;

	/** @var string[] */
	public $events = array();

	public function startTest( \PHPUnit\Framework\Test $test ): void {
		$this->events[] = 'start ' . ( $test instanceof \PHPUnit\Framework\TestCase ? $test->getName() : '' );
	}

	public function addFailure( \PHPUnit\Framework\Test $test, \PHPUnit\Framework\AssertionFailedError $e, float $time ): void {
		$this->events[] = 'failure ' . ( $test instanceof \PHPUnit\Framework\TestCase ? $test->getName() : '' );
	}

	public function endTest( \PHPUnit\Framework\Test $test, float $time ): void {
		$this->events[] = 'end ' . ( $test instanceof \PHPUnit\Framework\TestCase ? $test->getName() : '' );
	}
}
