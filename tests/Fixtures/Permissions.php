<?php

namespace WPCheckpoint\Tests\Fixtures;

use PHPUnit\Framework\SkippedTestError;

/**
 * For tests that take a permission away from the process (chmod 0000, a
 * directory it cannot write) and check what the code does then. Whether
 * that binds is probed, not guessed from a user id: getmyuid() is the
 * script file's owner, not the process's user, and root (or a process
 * with the same capability) reads and writes whatever the modes say.
 */
final class Permissions {

	/**
	 * Go on only where file modes bind this process: skip on Windows (they do not apply there); stop with a
	 * clear error where the process ignores them, so the run is not mistaken for a failing assertion.
	 *
	 * @return void
	 * @throws SkippedTestError On Windows.
	 * @throws \RuntimeException When file modes do not bind this process.
	 */
	public static function require_enforced(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			throw new SkippedTestError( 'File modes do not block access on Windows.' );
		}
		$probe = tempnam( sys_get_temp_dir(), 'wpc-perm-' );
		if ( false === $probe ) {
			throw new \RuntimeException( 'Cannot create a probe file in the temporary directory.' );
		}
		chmod( $probe, 0000 );
		clearstatcache( true, $probe );
		$readable = false !== @file_get_contents( $probe ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the probe is meant to fail.
		chmod( $probe, 0600 );
		unlink( $probe );
		if ( $readable ) {
			throw new \RuntimeException( 'This test takes a file permission away from the process, and this process reads a file it has no permission for: it runs as root (or with the same capability). Run the tests as a regular user, for example docker run --user "$(id -u):$(id -g)" ...' );
		}
	}
}
