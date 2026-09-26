<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The layout's path comparisons, in every spelling a server gives them.
 */
final class RestoreFilesPreflightPathsTest extends TestCase {

	public function test_the_root_of_a_file_system_in_every_spelling(): void {
		foreach ( array( '/', '', 'C:', 'C:/', 'c:\\', '\\\\server\\share', '//server/share', '//server/share/' ) as $root ) {
			$this->assertTrue( RestoreFilesPreflightStep::is_root( $root ), var_export( $root, true ) );
		}
		foreach ( array( '/srv', 'C:/wp', 'C:\\wp', '//server/share/wp', '\\\\server\\share\\wp' ) as $dir ) {
			$this->assertFalse( RestoreFilesPreflightStep::is_root( $dir ), $dir );
		}
	}

	public function test_inside_or_the_same_in_every_spelling_and_without_case(): void {
		foreach ( array(
			array( '/srv/wp/wp-content/uploads', '/srv/wp/wp-content/uploads' ),
			array( '/srv/wp/wp-content/uploads', '/srv/wp/wp-content/uploads/store/' ),
			array( 'C:\\wp\\wp-content\\uploads', 'c:/WP/wp-content/Uploads/store' ),
			array( '\\\\server\\share\\uploads', '//server/share/uploads/x' ),
		) as list( $dir, $path ) ) {
			$this->assertTrue( RestoreFilesPreflightStep::within( $dir, $path ), $path . ' in ' . $dir );
		}
		foreach ( array(
			array( '/srv/wp/wp-content/uploads', '/srv/wp/wp-content/uploads-old' ),
			array( '/srv/wp/wp-content/uploads', '/srv/wp/wp-content' ),
			array( 'C:\\wp\\uploads', 'D:\\wp\\uploads' ),
		) as list( $dir, $path ) ) {
			$this->assertFalse( RestoreFilesPreflightStep::within( $dir, $path ), $path . ' in ' . $dir );
		}
	}
}
