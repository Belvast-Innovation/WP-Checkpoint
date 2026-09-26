<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\RestoreFilesPreflightStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The layout's path comparisons, in every spelling a server gives them; the byte count a question carries.
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

	public function test_the_bytes_a_question_carries_are_capped_and_never_overflow(): void {
		foreach ( array( (float) PHP_INT_MAX, 2.0 ** 63, 2.0 ** 64, 1e30 ) as $bytes ) {
			$this->assertSame( PHP_INT_MAX, RestoreFilesPreflightStep::question_bytes( $bytes ), (string) $bytes );
		}
		$this->assertSame( 104857600, RestoreFilesPreflightStep::question_bytes( 104857600.0 ), 'the control: a count in range as it is' );
		$this->assertSame( 9007199254740992, RestoreFilesPreflightStep::question_bytes( 2.0 ** 53 ) );
		$this->assertSame( 0, RestoreFilesPreflightStep::question_bytes( 0.0 ) );
		$this->assertSame( 0, RestoreFilesPreflightStep::question_bytes( -1.0 ), 'never negative (a question refuses that)' );
	}
}
