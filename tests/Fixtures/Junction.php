<?php

namespace WPCheckpoint\Tests\Fixtures;

use PHPUnit\Framework\SkippedTestError;

/**
 * Directory junctions: what a Windows site has where a POSIX one has a symbolic link to a directory. Creating one needs
 * no privileges (mklink /J), unlike a symbolic link, so the Windows versions of the link tests use junctions.
 */
final class Junction {

	/**
	 * Make a junction at $link that leads to the directory $target.
	 *
	 * @param string $target Existing directory.
	 * @param string $link   Path of the junction (must not exist).
	 * @return void
	 * @throws SkippedTestError Not on Windows, or when the junction cannot be made there.
	 */
	public static function make( string $target, string $link ): void {
		if ( 'Windows' !== PHP_OS_FAMILY ) {
			throw new SkippedTestError( 'Windows only: directory junctions; the symbolic link version of this test runs elsewhere.' );
		}
		$output = array();
		$code   = 0;
		exec( 'cmd /c mklink /J "' . str_replace( '/', '\\', $link ) . '" "' . str_replace( '/', '\\', $target ) . '" 2>&1', $output, $code );
		clearstatcache();
		if ( 0 !== $code || ! is_dir( $link ) ) {
			throw new SkippedTestError( 'mklink /J failed: ' . implode( ' ', $output ) );
		}
	}

	/**
	 * Remove a junction as a link (the directory it leads to and its content stay).
	 *
	 * @param string $link Junction.
	 * @return void
	 */
	public static function remove( string $link ): void {
		@rmdir( $link ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- already gone is fine.
	}
}
