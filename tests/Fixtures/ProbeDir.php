<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Support\Deleter;

/**
 * The directory a run of the integration suite keeps the HTTP probes' one-time key in (see
 * tests/Fixtures/Standalone/http/guard.php): wp-content/wpcheckpoint-it-probe.<run ID>, where the web server, a
 * container of its own, can read it. The run ID comes from bin/test-integration.sh (WPCHECKPOINT_TEST_RUN_ID), 16
 * lowercase hexadecimal digits; nothing is built from one of another form. A run killed with SIGKILL cannot remove its
 * directory: the next run, taking the lock over, names it (WPCHECKPOINT_TEST_STALE_RUN_ID) and it is removed then.
 */
final class ProbeDir {

	const PREFIX = 'wpcheckpoint-it-probe.';

	/**
	 * Whether a run ID has its form.
	 *
	 * @param mixed $id Candidate.
	 */
	public static function is_run_id( $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[0-9a-f]{16}$/D', $id );
	}

	/**
	 * The probe directory of a run.
	 *
	 * @param string $content wp-content.
	 * @param string $id      Run ID.
	 * @throws \InvalidArgumentException When the run ID does not have its form.
	 */
	public static function path( string $content, string $id ): string {
		if ( ! self::is_run_id( $id ) ) {
			throw new \InvalidArgumentException( 'Not a run ID.' );
		}
		return rtrim( $content, '/\\' ) . DIRECTORY_SEPARATOR . self::PREFIX . $id;
	}

	/**
	 * Remove a probe directory (registered with the Deleter first: it lies among the site's own directories).
	 *
	 * @param string $dir The probe directory (path()).
	 * @return bool Whether it is gone.
	 */
	public static function remove( string $dir ): bool {
		Deleter::allow( $dir );
		Deleter::delete_tree( dirname( $dir ), $dir );
		clearstatcache( true );
		return false === @lstat( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- gone is the answer wanted.
	}

	/**
	 * Remove the probe directory a killed run left, as the next run takes its lock over.
	 *
	 * @param string $content wp-content.
	 * @param string $id      The killed run's ID ('' when none was named).
	 * @return string What was done, for the run's output ('' when there was nothing to do).
	 */
	public static function remove_stale( string $content, string $id ): string {
		if ( '' === $id ) {
			return '';
		}
		if ( ! self::is_run_id( $id ) ) {
			return 'The lock named an earlier run whose ID does not have its form; no probe directory was looked for.';
		}
		$dir = self::path( $content, $id );
		clearstatcache( true );
		if ( false === @lstat( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not there is an answer.
			return '';
		}
		return self::remove( $dir )
			? sprintf( 'Removed the probe directory %s, left by an earlier run that ended without removing it.', $dir )
			: sprintf( 'The probe directory %s, left by an earlier run, could not be removed.', $dir );
	}
}
