<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\ExportJob;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Plugin;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * What a test changes in the plugin's own objects does not outlive it:
 * fixture job types (under a real type's id or a new one) and private
 * properties a test replaced are put back without the test doing it.
 */
final class JobTestCaseTest extends JobTestCase {

	public function test_registered_types_and_replaced_internals_are_put_back(): void {
		$types   = Plugin::instance()->job_types();
		$actions = Plugin::instance()->job_actions();
		$runner  = new Runner( Plugin::instance()->jobs(), $types, Plugin::instance()->redactor() );
		$read    = static function () use ( $actions ) {
			$property = new \ReflectionProperty( get_class( $actions ), 'runner' );
			$property->setAccessible( true );
			return $property->getValue( $actions );
		};
		$before = $read();

		$this->register( ExportJob::ID, array( $this->counting_step( 'files', 1 ) ) );
		$this->register( 'isolation-new', array( $this->counting_step( 'n', 1 ) ) );
		$this->replace_internal( $actions, 'runner', $runner );
		$this->replace_internal( $actions, 'runner', new Runner( Plugin::instance()->jobs(), $types, Plugin::instance()->redactor() ) );
		// The control: the changes are seen while the test runs.
		$this->assertInstanceOf( FixtureJobType::class, $types->get( ExportJob::ID ) );
		$this->assertNotNull( $types->get( 'isolation-new' ) );
		$this->assertNotSame( $before, $read() );

		$this->restore_plugin();
		$this->assertInstanceOf( ExportJob::class, $types->get( ExportJob::ID ), 'the real type is back' );
		$this->assertNull( $types->get( 'isolation-new' ), 'a new fixture type is gone' );
		$this->assertSame( $before, $read(), 'replaced twice: the value from before the test is back' );
	}
}
