<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Jobs\DatabaseExportStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which table names can become a chunk file: the name is one path
 * segment and one space-free token in the chunk's header and end lines.
 */
final class DatabaseExportStepNamesTest extends TestCase {

	/**
	 * @dataProvider names
	 */
	public function test_storable_names( string $name, bool $expected ): void {
		$this->assertSame( $expected, DatabaseExportStep::storable_name( $name ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function names(): array {
		return array(
			'plain'                => array( 'wp_posts', true ),
			'upper case'           => array( 'wp_Posts', true ),
			'unicode'              => array( 'wp_表', true ),
			'dot'                  => array( 'wp.posts', true ),
			'longest'              => array( str_repeat( 'a', Manifest::MAX_TABLE_NAME ), true ),
			'empty'                => array( '', false ),
			'space'                => array( 'wp posts', false ),
			'tab'                  => array( "wp\tposts", false ),
			'slash'                => array( 'a/b', false ),
			'backslash'            => array( 'a\\b', false ),
			'dots only'            => array( '..', true ), // database/...0001.sql is one ordinary file name, not a traversal.
			'control character'    => array( "wp\x01posts", false ),
			'invalid utf-8'        => array( "wp\xffposts", false ),
			'too long'             => array( str_repeat( 'a', Manifest::MAX_TABLE_NAME + 1 ), false ),
			'newline'              => array( "wp\nposts", false ),
		);
	}
}
