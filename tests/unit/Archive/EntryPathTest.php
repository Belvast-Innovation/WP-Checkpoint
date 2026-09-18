<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\EntryPath;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class EntryPathTest extends TestCase {

	public function test_accepts_relative_paths_and_rejects_everything_that_could_escape(): void {
		foreach ( array( 'a', 'wp-content/uploads/2026/09/Ärger 中文.jpg', 'database/wp_posts.0001.sql', 'x.y', 'a b/c', 'a/colon:later' ) as $ok ) {
			$this->assertNull( EntryPath::problem( $ok ), $ok );
		}
		foreach ( array( '', '/etc/passwd', 'a\\b', 'a/../b', '../x', './x', 'a//b', 'a/', 'C:x', 'data:text/plain,x', 'php://memory', "a\x00b", "a\tb", "a\x7fb", str_repeat( 'a', 4097 ) ) as $bad ) {
			$this->assertNotNull( EntryPath::problem( $bad ), json_encode( $bad ) );
			$this->assertFalse( EntryPath::is_valid( $bad ) );
		}
	}
}
