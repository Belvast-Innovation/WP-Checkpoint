<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\Restore\PluginCopy;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The restore tests' stand-in plugin copy removes only a stand-in it made, and all of it.
 */
final class PluginCopyTest extends TestCase {

	/** @var string */
	private $sandbox = '';

	protected function tear_down(): void {
		if ( '' !== $this->sandbox ) {
			Sandbox::remove( $this->sandbox );
		}
		parent::tear_down();
	}

	public function test_a_stand_in_is_removed_with_what_was_added_to_it(): void {
		$dir = PluginCopy::make();
		file_put_contents( $dir . '/added-by-a-restore.txt', 'x' );
		mkdir( $dir . '/more' );
		file_put_contents( $dir . '/more/file.txt', 'x' );
		$this->assertDirectoryExists( dirname( $dir ) );
		PluginCopy::remove( $dir );
		clearstatcache();
		$this->assertDirectoryDoesNotExist( dirname( $dir ), 'its directory is gone, not left because of an added file' );
	}

	public function test_a_path_that_is_not_a_stand_in_is_refused_and_nothing_is_touched(): void {
		$this->sandbox = Sandbox::make( 'plugin-copy-test' );
		foreach ( array( 'other', 'wpc-plugin-zzzzzzzz', 'wpc-plugin-0123456789' ) as $parent ) {
			mkdir( $this->sandbox . '/' . $parent . '/wp-checkpoint', 0755, true );
			file_put_contents( $this->sandbox . '/' . $parent . '/wp-checkpoint/keep.txt', 'keep' );
		}
		mkdir( $this->sandbox . '/wpc-plugin-01234567/other', 0755, true );
		$refused = array(
			'',
			'/',
			'wp-checkpoint',
			'wpc-plugin-01234567/wp-checkpoint',
			$this->sandbox . '/other/wp-checkpoint',
			$this->sandbox . '/wpc-plugin-zzzzzzzz/wp-checkpoint',
			$this->sandbox . '/wpc-plugin-0123456789/wp-checkpoint',
			$this->sandbox . '/wpc-plugin-01234567/other',
		);
		foreach ( $refused as $dir ) {
			try {
				PluginCopy::remove( $dir );
				$this->fail( var_export( $dir, true ) . ' was not refused' );
			} catch ( \LogicException $e ) {
				$this->assertStringContainsString( 'Nothing was deleted', $e->getMessage() );
			}
		}
		foreach ( array( 'other', 'wpc-plugin-zzzzzzzz', 'wpc-plugin-0123456789' ) as $parent ) {
			$this->assertFileExists( $this->sandbox . '/' . $parent . '/wp-checkpoint/keep.txt' );
		}
		$this->assertDirectoryExists( $this->sandbox . '/wpc-plugin-01234567/other' );
	}
}
