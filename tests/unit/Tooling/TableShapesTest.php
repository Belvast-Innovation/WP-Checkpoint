<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestResult;
use PHPUnit\Framework\TestSuite;
use WPCheckpoint\Tests\Fixtures\TableShapes;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The integration suite's table-shape check, on shapes it is given: a test that changes a table's statement (or drops a
 * table) fails by name with both statements; a class whose class-level set-up does fails as a class; listing only
 * changes nothing, restoring makes the tables again; what rows move (AUTO_INCREMENT=N) is no change.
 */
final class TableShapesTest extends TestCase {

	const OPTIONS = "CREATE TABLE `wp_options` (\n  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`option_id`)\n) ENGINE=InnoDB AUTO_INCREMENT=%d DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";

	/** @var array<string, string> The shapes "there". */
	private $there = array();

	/** @var array<int, array{0: array<string, string>, 1: array<string, string|null>}> What the remaker was given. */
	private $remade = array();

	private function listener( bool $restore ): TableShapes {
		return new TableShapes(
			function (): array {
				return $this->there;
			},
			function ( array $was, array $diff ): array {
				$this->remade[] = array( $was, $diff );
				foreach ( $diff as $table => $now ) {
					$this->there[ $table ] = $was[ $table ];
				}
				ksort( $this->there );
				return array();
			},
			$restore
		);
	}

	private static function probe( TestResult $result, string $name ): TableShapesProbe {
		$test = new TableShapesProbe( $name );
		$test->setTestResultObject( $result );
		return $test;
	}

	private static function options( int $next, string $engine = 'InnoDB' ): string {
		return TableShapes::shape( str_replace( 'ENGINE=InnoDB', 'ENGINE=' . $engine, sprintf( self::OPTIONS, $next ) ) );
	}

	/**
	 * The failures' texts.
	 *
	 * @param TestResult $result Result.
	 * @return string[]
	 */
	private static function failures( TestResult $result ): array {
		$out = array();
		foreach ( $result->failures() as $failure ) {
			$out[] = $failure->getTestName() . ': ' . $failure->thrownException()->getMessage();
		}
		return $out;
	}

	public function test_what_rows_move_is_no_change_and_the_rest_is(): void {
		$this->assertSame( self::options( 5 ), self::options( 900 ), 'AUTO_INCREMENT=N is left out' );
		$this->assertStringNotContainsString( 'AUTO_INCREMENT=', self::options( 5 ) );
		$this->assertStringContainsString( 'NOT NULL AUTO_INCREMENT,', self::options( 5 ), 'the column\'s AUTO_INCREMENT stays' );
		$this->assertNotSame( self::options( 5 ), self::options( 5, 'MyISAM' ), 'the control: another engine is a change' );
		$this->assertSame(
			array(
				'wp_gone'    => null,
				'wp_options' => self::options( 5, 'MyISAM' ),
			),
			TableShapes::differences(
				array(
					'wp_options' => self::options( 5 ),
					'wp_posts'   => 'CREATE TABLE `wp_posts` (x int)',
					'wp_gone'    => 'CREATE TABLE `wp_gone` (x int)',
				),
				array(
					'wp_options' => self::options( 5, 'MyISAM' ),
					'wp_posts'   => 'CREATE TABLE `wp_posts` (x int)',
					'wp_new'     => 'CREATE TABLE `wp_new` (x int)',
				)
			),
			'changed and gone tables, not the same or new ones'
		);
	}

	public function test_listing_names_the_test_and_changes_nothing(): void {
		$result      = new TestResult();
		$listener    = $this->listener( false );
		$root        = new TestSuite( 'root' );
		$class       = new TestSuite( 'SomeTest' );
		$this->there = array( 'wp_options' => self::options( 5 ) );
		$listener->startTestSuite( $root );
		$listener->startTestSuite( $class );

		$clean = self::probe( $result, 'test_clean' );
		$listener->startTest( $clean );
		$this->there['wp_options'] = self::options( 40 ); // Rows written.
		$listener->endTest( $clean, 0.1 );
		$this->assertSame( array(), self::failures( $result ), 'the control: a test that writes rows passes' );

		$myisam = self::probe( $result, 'test_myisam' );
		$listener->startTest( $myisam );
		$this->there['wp_options'] = self::options( 41, 'MyISAM' );
		$listener->endTest( $myisam, 0.1 );
		$failures = self::failures( $result );
		$this->assertCount( 1, $failures );
		$this->assertStringContainsString( 'test_myisam', $failures[0], 'named' );
		$this->assertStringContainsString( 'wp_options', $failures[0] );
		$this->assertStringContainsString( 'was: ', $failures[0] );
		$this->assertStringContainsString( 'ENGINE=MyISAM', $failures[0], 'both statements' );
		$this->assertStringContainsString( TableShapes::RESTORE . ' is not 1', $failures[0] );
		$this->assertSame( array(), $this->remade, 'listing only: nothing made again' );
		$this->assertSame( self::options( 41, 'MyISAM' ), $this->there['wp_options'], 'left as it is' );

		$after = self::probe( $result, 'test_after' );
		$listener->startTest( $after );
		$listener->endTest( $after, 0.1 );
		$listener->endTestSuite( $class );
		$listener->endTestSuite( $root );
		$this->assertCount( 1, self::failures( $result ), 'the test after it, the class and the run are not named for it' );
	}

	public function test_restoring_makes_the_table_again(): void {
		$result      = new TestResult();
		$listener    = $this->listener( true );
		$class       = new TestSuite( 'SomeTest' );
		$this->there = array(
			'wp_options' => self::options( 5 ),
			'wp_gone'    => 'CREATE TABLE `wp_gone` (x int)',
		);
		$listener->startTestSuite( $class );
		$test = self::probe( $result, 'test_changes' );
		$listener->startTest( $test );
		$this->there['wp_options'] = self::options( 5, 'MyISAM' );
		unset( $this->there['wp_gone'] );
		$listener->endTest( $test, 0.1 );
		$failures = self::failures( $result );
		$this->assertCount( 1, $failures, 'still a failure, named' );
		$this->assertStringContainsString( 'made again as they were', $failures[0] );
		$this->assertStringContainsString( 'wp_gone: gone', $failures[0] );
		$this->assertCount( 1, $this->remade );
		$this->assertSame( array( 'wp_gone', 'wp_options' ), array_keys( $this->remade[0][1] ), 'given what changed and what is gone' );
		$this->assertSame( self::options( 5 ), $this->there['wp_options'], 'made again' );
		$listener->endTestSuite( $class );
		$this->assertCount( 1, self::failures( $result ), 'the class is not named for it' );
	}

	public function test_a_class_level_change_fails_the_class(): void {
		$result      = new TestResult();
		$listener    = $this->listener( false );
		$class       = new TestSuite( 'ClassSetUpTest' );
		$this->there = array( 'wp_options' => self::options( 5 ) );
		$listener->startTestSuite( $class );
		$test = self::probe( $result, 'test_clean' );
		$listener->startTest( $test ); // Gives the listener the run's result.
		$listener->endTest( $test, 0.1 );
		$this->assertSame( array(), self::failures( $result ), 'the control: no change, no failure' );
		$this->there['wp_options'] = self::options( 5, 'MyISAM' ); // Its class-level tear-down.
		$listener->endTestSuite( $class );
		$failures = self::failures( $result );
		$this->assertCount( 1, $failures );
		$this->assertStringContainsString( 'table shapes of ClassSetUpTest', $failures[0], 'named as the class' );
		$this->assertStringContainsString( 'ClassSetUpTest changed the shape', $failures[0] );
	}
}

/**
 * A test the listener is shown starting and ending.
 */
final class TableShapesProbe extends \PHPUnit\Framework\TestCase {

	/**
	 * Never run.
	 *
	 * @return void
	 */
	public function test_probe(): void {
		$this->markTestSkipped( 'Only started and ended.' );
	}
}
