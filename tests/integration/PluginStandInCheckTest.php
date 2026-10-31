<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Jobs\FileStagingStep;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\Step;
use WPCheckpoint\Jobs\SwapCheckStep;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;
use WPCheckpoint\Tests\Fixtures\Restore\PluginCopy;
use WPCheckpoint\Tests\Fixtures\Restore\PluginStandIn;

/**
 * PluginStandIn at work: the steps that read the running plugin, as the plugin's registry and a test's own hand them
 * out, read this test's stand-in; a plugin directory a test gave its own step stays; a stand-in kept from an earlier
 * test is replaced; and the real running directory given to a step is found (this class does not declare it).
 */
final class PluginStandInCheckTest extends WP_UnitTestCase {

	public function tear_down(): void {
		PluginStandIn::take_found(); // What a control below made it find is not this test's failure.
		parent::tear_down();
	}

	/**
	 * A step's parts.
	 *
	 * @return array<string, mixed>
	 */
	private static function parts( Step $step ): array {
		$property = new \ReflectionProperty( get_class( $step ), 'parts' );
		$property->setAccessible( true );
		return (array) $property->getValue( $step );
	}

	/**
	 * The steps of a registered type that read the running plugin, by class.
	 *
	 * @param Step[] $steps Steps.
	 * @return array<string, Step>
	 */
	private static function readers( array $steps ): array {
		$out = array();
		foreach ( $steps as $step ) {
			if ( in_array( get_class( $step ), PluginStandIn::STEPS, true ) ) {
				$out[ get_class( $step ) ] = $step;
			}
		}
		return $out;
	}

	public function test_the_registered_restore_reads_the_tests_stand_in(): void {
		$dir     = PluginStandIn::dir();
		$readers = self::readers( Plugin::instance()->job_types()->get( RestoreJob::ID )->steps() );
		$this->assertSame( PluginStandIn::STEPS, array_keys( $readers ), 'the control: the restore has all three, in order' );
		foreach ( $readers as $class => $step ) {
			$parts = self::parts( $step );
			$this->assertSame( $dir, $parts['plugin_dir'] ?? null, $class );
			$this->assertSame( $dir . '/wp-checkpoint.php', $parts['plugin_main'] ?? null, $class );
		}
		$this->assertSame( PluginCopy::VERSION, self::parts( $readers[ SwapCheckStep::class ] )['version'] ?? null );
		$this->assertTrue( is_file( $dir . '/wp-checkpoint.php' ), 'the stand-in is there' );
		$this->assertStringStartsWith( rtrim( sys_get_temp_dir(), '/' ) . '/wpc-plugin-', $dir );
	}

	public function test_a_tests_own_registry_and_its_own_steps_get_it_too(): void {
		$types = new JobTypes();
		$types->add( new FixtureJobType( 'stand-in-own', array( new RestoreFilesPreflightStep(), new FileStagingStep( array( 'read' => 'kept' ) ) ) ) );
		$steps = $types->get( 'stand-in-own' )->steps();
		$this->assertSame( PluginStandIn::dir(), self::parts( $steps[0] )['plugin_dir'] ?? null );
		$this->assertSame( PluginStandIn::dir(), self::parts( $steps[1] )['plugin_dir'] ?? null );
		$this->assertSame( 'kept', self::parts( $steps[1] )['read'] ?? null, 'the test\'s own parts stay' );
	}

	public function test_a_directory_a_test_gave_stays_and_an_earlier_tests_stand_in_is_replaced(): void {
		$own   = sys_get_temp_dir() . '/wpc-plugin-synthetic';
		$types = new JobTypes();
		$types->add( new FixtureJobType( 'stand-in-own', array( new RestoreFilesPreflightStep( array( 'plugin_dir' => $own ) ) ) ) );
		$this->assertSame( $own, self::parts( $types->get( 'stand-in-own' )->steps()[0] )['plugin_dir'] );
		$this->assertSame( array(), PluginStandIn::take_found(), 'not the real directory: nothing found' );

		// A step that outlives a test (registered once) keeps the stand-in it was given; the next test's replaces it.
		$step  = new RestoreFilesPreflightStep();
		$types = new JobTypes();
		$types->add( new FixtureJobType( 'stand-in-kept', array( $step ) ) );
		$types->get( 'stand-in-kept' )->steps();
		$earlier = self::parts( $step )['plugin_dir'];
		$current = new \ReflectionProperty( PluginStandIn::class, 'dir' );
		$current->setAccessible( true );
		$current->setValue( null, '' ); // As at the end of a test (the listener removes it, below).
		PluginCopy::remove( $earlier );
		$types->get( 'stand-in-kept' )->steps();
		$this->assertNotSame( $earlier, self::parts( $step )['plugin_dir'] );
		$this->assertSame( PluginStandIn::dir(), self::parts( $step )['plugin_dir'] );
	}

	public function test_the_real_running_directory_given_to_a_step_is_found(): void {
		$types = new JobTypes();
		$types->add( new FixtureJobType( 'stand-in-real', array( new SwapCheckStep( null, array( 'plugin_dir' => WPCHECKPOINT_DIR ) ) ) ) );
		$this->assertSame( WPCHECKPOINT_DIR, self::parts( $types->get( 'stand-in-real' )->steps()[0] )['plugin_dir'], 'left as given' );
		$found = PluginStandIn::take_found();
		$this->assertCount( 1, $found );
		$this->assertStringContainsString( SwapCheckStep::class . ': ' . rtrim( WPCHECKPOINT_DIR, '/' ), $found[0] );
	}
}
