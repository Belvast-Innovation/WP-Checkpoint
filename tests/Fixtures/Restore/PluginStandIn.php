<?php

namespace WPCheckpoint\Tests\Fixtures\Restore;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestResult;
use PHPUnit\Framework\TestSuite;
use WPCheckpoint\Jobs\FileStagingStep;
use WPCheckpoint\Jobs\JobType;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\Step;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Tests\Fixtures\LeftoversStandIn;

/**
 * The one place the integration tests' restores are given a stand-in for the running copy of this plugin.
 *
 * A restore's files preflight, its staging and its final check read the running plugin (RestoreFilesPreflightStep,
 * FileStagingStep, SwapCheckStep: the parts "plugin_dir", "plugin_main", "version", else WPCHECKPOINT_DIR,
 * WPCHECKPOINT_FILE, WPCHECKPOINT_VERSION). On the test site that is the mounted working copy, with its development
 * directories (node_modules, vendor, .git, build): a test's outcome would depend on how big the working copy is (past
 * RestoreFilesPreflightStep::MAX_PLUGIN_ENTRIES every restore fails its preflight). So every job type the plugin's
 * registries give (the filter wpcheckpoint_job_types, which every JobTypes applies: the plugin's, and a test's own) whose
 * steps include one of those three (by its id; every other type is handed out as it is) is wrapped here, and each of
 * those three steps it gives without a plugin directory gets one from a small PluginCopy made for the test (dir()), one
 * per test, removed at its end, with its main file and version where the test set none of its own. A step a test gave a
 * plugin directory of its own (a synthetic tree) keeps it; one given the real running directory fails the test, named,
 * unless its class declares, with the reason, the constant USES_REAL_PLUGIN_DIR.
 *
 * What it does not cover: a step run other than through a registry (the tests run none; PluginStandInUsageTest scans
 * for it), and a restore run by another process (the web server, a WP-CLI process: they load the plugin as installed;
 * the tests run none).
 *
 * Installed by tests/bootstrap.php (integration suite); the listener, registered in phpunit.xml.dist before the leftover
 * check (Leftovers sees no stand-in left), removes each test's copy and reports what it found. Does nothing in the unit
 * suite.
 */
final class PluginStandIn implements TestListener {
	use TestListenerDefaultImplementation;

	/** The constant a test class declares (its value: why) to give a restore step the real running plugin directory. */
	const DECLARATION = 'USES_REAL_PLUGIN_DIR';

	/** The steps that read the running plugin. */
	const STEPS = array( RestoreFilesPreflightStep::class, FileStagingStep::class, SwapCheckStep::class );

	/** Their ids: a type with none of them is handed out as it is. */
	const STEP_IDS = array( RestoreFilesPreflightStep::ID, FileStagingStep::ID, SwapCheckStep::ID );

	/** @var string This test's copy, '' before one is made. */
	private static $dir = '';

	/** @var array<string, true> Every copy made in this run: a step that kept one from an earlier test gets this test's. */
	private static $issued = array();

	/** @var string The test class running now, '' between tests. */
	private static $class = '';

	/** @var string[] Real directories given to a step and not declared, since the last report. */
	private static $found = array();

	/** @var TestResult|null */
	private $result;

	/**
	 * Wrap the registries' job types (tests/bootstrap.php, once WordPress is loaded).
	 */
	public static function install(): void {
		add_filter( 'wpcheckpoint_job_types', array( self::class, 'types' ), PHP_INT_MAX );
	}

	/**
	 * This test's copy of the plugin, made on first use.
	 *
	 * @return string Its directory (PluginCopy::make()).
	 */
	public static function dir(): string {
		if ( '' === self::$dir ) {
			self::$dir                  = PluginCopy::make();
			self::$issued[ self::$dir ] = true;
		}
		return self::$dir;
	}

	/**
	 * The filter: each type with one of the steps wrapped, once.
	 *
	 * @param mixed $types Types keyed by id.
	 * @return mixed
	 */
	public static function types( $types ) {
		if ( ! is_array( $types ) ) {
			return $types;
		}
		foreach ( $types as $id => $type ) {
			if ( $type instanceof JobType && ! $type instanceof PluginStandInType && array() !== array_intersect( self::STEP_IDS, $type->step_ids() ) ) {
				$types[ $id ] = new PluginStandInType( $type );
			}
		}
		return $types;
	}

	/**
	 * Give one step this test's copy, when it reads the running plugin and was given no plugin directory (or a copy of
	 * an earlier test's); note a real running directory given and not declared.
	 *
	 * @param Step $step A step a registry's type gave.
	 * @return void
	 */
	public static function give( Step $step ): void {
		if ( ! in_array( get_class( $step ), self::STEPS, true ) ) {
			return;
		}
		$property = new \ReflectionProperty( get_class( $step ), 'parts' );
		$property->setAccessible( true );
		$parts = (array) $property->getValue( $step );
		$given = isset( $parts['plugin_dir'] ) ? rtrim( (string) $parts['plugin_dir'], '/\\' ) : '';
		if ( '' !== $given && ! isset( self::$issued[ $given ] ) ) {
			$real = realpath( WPCHECKPOINT_DIR );
			if ( false !== $real && realpath( $given ) === $real && ! self::declared() ) {
				self::$found[] = get_class( $step ) . ': ' . $given;
			}
			return;
		}
		$dir                 = self::dir();
		$parts['plugin_dir'] = $dir;
		// A main file or version the test set stays (a version other than the one staged, say); one in an earlier
		// test's copy does not.
		if ( ! isset( $parts['plugin_main'] ) || isset( self::$issued[ dirname( (string) $parts['plugin_main'] ) ] ) ) {
			$parts['plugin_main'] = $dir . '/wp-checkpoint.php';
		}
		if ( SwapCheckStep::class === get_class( $step ) && ! isset( $parts['version'] ) ) {
			$parts['version'] = PluginCopy::VERSION;
		}
		$property->setValue( $step, $parts );
	}

	/**
	 * What give() found since the last call, emptied (PluginStandInCheckTest).
	 *
	 * @return string[]
	 */
	public static function take_found(): array {
		$found       = self::$found;
		self::$found = array();
		return $found;
	}

	/**
	 * Whether the test class running now declares the real running directory.
	 *
	 * @return bool
	 */
	private static function declared(): bool {
		$constant = self::$class . '::' . self::DECLARATION;
		return '' !== self::$class && defined( $constant ) && '' !== trim( (string) constant( $constant ) );
	}

	/**
	 * Whether this is the integration suite.
	 *
	 * @return bool
	 */
	private static function active(): bool {
		return 'integration' === getenv( 'WPCHECKPOINT_TEST_SUITE' );
	}

	/**
	 * Remove this test's copy.
	 *
	 * @return void
	 */
	private static function remove(): void {
		if ( '' !== self::$dir ) {
			$dir       = self::$dir;
			self::$dir = '';
			PluginCopy::remove( $dir );
		}
	}

	/**
	 * The start of a test.
	 *
	 * @param Test $test Test.
	 * @return void
	 */
	public function startTest( Test $test ): void {
		if ( ! self::active() ) {
			return;
		}
		if ( $test instanceof TestCase && null !== $test->getTestResultObject() ) {
			$this->result = $test->getTestResultObject();
		}
		self::$class = get_class( $test );
	}

	/**
	 * The end of a test: its copy goes, and a real running directory given to a step fails it.
	 *
	 * @param Test  $test Test.
	 * @param float $time Its time.
	 * @return void
	 */
	public function endTest( Test $test, float $time ): void {
		if ( ! self::active() ) {
			return;
		}
		self::remove();
		self::$class = '';
		$found       = self::take_found();
		if ( array() !== $found && null !== $this->result ) {
			$this->result->addFailure( $test, new AssertionFailedError( sprintf( "A restore step was given the real running plugin directory, and the test class does not declare %s (why):\n  %s", self::DECLARATION, implode( "\n  ", $found ) ) ), 0.0 );
		}
	}

	/**
	 * The end of a suite: a copy made outside a test (a class-level set-up) goes, and what was found there fails the
	 * class.
	 *
	 * @param TestSuite $suite Suite.
	 * @return void
	 */
	public function endTestSuite( TestSuite $suite ): void {
		if ( ! self::active() ) {
			return;
		}
		self::remove();
		$found = self::take_found();
		if ( array() !== $found && null !== $this->result ) {
			$stand_in = new LeftoversStandIn( 'plugin stand-in of ' . $suite->getName() );
			$this->result->startTest( $stand_in );
			$this->result->addFailure( $stand_in, new AssertionFailedError( sprintf( "A restore step was given the real running plugin directory outside a test:\n  %s", implode( "\n  ", $found ) ) ), 0.0 );
			$this->result->endTest( $stand_in, 0.0 );
		}
	}
}
