<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;

/**
 * The integration suite runs from a directory made for the run (bin/test-integration.sh), never from the plugin's
 * directory, which wp-env mounts from the developer's repository: a path resolved against the working directory by
 * mistake (an empty or relative one) lands there, not in the repository.
 */
final class WorkingDirectoryTest extends WP_UnitTestCase {

	public function test_the_suite_does_not_run_from_the_plugins_directory(): void {
		$plugin = (string) realpath( dirname( __DIR__, 2 ) );
		$cwd    = (string) realpath( (string) getcwd() );
		$this->assertFileExists( $plugin . '/phpunit.xml.dist', 'the control: the plugin\'s directory is found' );
		$this->assertNotSame( '', $cwd );
		$this->assertNotSame( $plugin, $cwd, 'the working directory is not the plugin\'s' );
		$this->assertStringStartsNotWith( $plugin . '/', $cwd . '/', 'nor inside it' );
		$this->assertStringStartsNotWith( $cwd . '/', $plugin . '/', 'nor above it' );
	}
}
