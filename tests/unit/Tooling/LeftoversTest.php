<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestResult;
use PHPUnit\Framework\TestSuite;
use WPCheckpoint\Tests\Fixtures\Leftovers;
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
		$result    = new TestResult();
		$listener  = $this->listener();
		$root      = new TestSuite( 'root' );
		$class     = new TestSuite( 'SomeTest' );
		$this->there = array( 'table:wcptmpabcdef_3_beef_posts', 'temp:/tmp/wpc-plugin-00000000' );

		$listener->startTestSuite( $root );
		$this->assertSame( array(), $this->there, 'an earlier run\'s leftovers are removed first' );
		$this->assertCount( 1, $this->notices );
		$this->assertStringContainsString( 'wcptmpabcdef_3_beef_posts', $this->notices[0], 'and reported' );
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
		$this->assertSame( array(), $this->there, 'removed, so the next test starts clean' );

		$next = self::probe( $result, 'test_next' );
		$listener->startTest( $next );
		$listener->endTest( $next, 0.1 );
		$this->assertSame( 1, $result->failureCount(), 'the next test is not blamed' );

		$listener->endTestSuite( $class );
		$listener->endTestSuite( $root );
		$this->assertSame( 1, $result->failureCount(), 'nothing left at the end' );
	}

	public function test_a_class_that_leaves_something_fails_as_a_class_and_so_does_the_run(): void {
		$result   = new TestResult();
		$listener = $this->listener();
		$root     = new TestSuite( 'root' );
		$class    = new TestSuite( 'ClassLevelTest' );
		$listener->startTestSuite( $root );
		$listener->startTestSuite( $class );
		$this->there[] = 'table:wcptmpabcdef_9_beef_made_before_class'; // set_up_before_class().
		$test          = self::probe( $result, 'test_one' );
		$listener->startTest( $test );
		$listener->endTest( $test, 0.1 );
		$this->assertSame( 0, $result->failureCount(), 'not the test\'s' );
		$listener->endTestSuite( $class );
		$this->assertSame( 1, $result->failureCount() );
		$this->assertSame( $class, $result->failures()[0]->failedTest() );
		$this->assertStringContainsString( 'made_before_class', $result->failures()[0]->exceptionMessage() );

		$this->there[] = 'temp:/tmp/wpcheckpoint-verify-00000000'; // Outside any class.
		$listener->endTestSuite( $root );
		$this->assertSame( 2, $result->failureCount(), 'the run ends with none' );
		$this->assertSame( $root, $result->failures()[1]->failedTest() );
		$this->assertSame( array(), $this->there );
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
