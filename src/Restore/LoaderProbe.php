<?php
/**
 * Whether a file can be put where the restore's loader must go.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Support\AtomicFile;
use WPCheckpoint\Support\AtomicWriteFailed;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- plain file system calls on a file of the restore's own.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- errors with paths; the job presenter cleans them.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log; failures are thrown with a reason.

/**
 * No WordPress. The swap relies on a must-use plugin: the must-use plugins
 * directory may be missing, read-only, or moved (WPMU_PLUGIN_DIR), so a
 * file is actually put there once, the way the loader will be (AtomicFile:
 * a temporary name that does not end in ".php", renamed, read back), then
 * removed. A directory created for the probe is removed again when it is
 * empty.
 *
 * The file's contents are valid PHP that does nothing: a process that dies
 * after the rename leaves it in place, where WordPress loads it on every
 * request until the reaper removes it (Residue: probe).
 *
 * Replaying after a death between creating the directory and the end of
 * the probe finds the directory there and leaves it: whether it was created
 * here is not known any more, and an empty must-use plugins directory is
 * harmless.
 */
final class LoaderProbe {

	const CONTENTS = "<?php\n// WP Checkpoint: a restore checks that a file can be placed here. This file does nothing and is removed right away.\n";

	/**
	 * Probe.
	 *
	 * @param string   $dir     The must-use plugins directory.
	 * @param string   $name    Probe file name (StagingLayout::probe_name( '.php' )).
	 * @param callable $confirm function(): void, throws to stop.
	 * @param int      $mode    Mode of a created directory.
	 * @return void
	 * @throws CannotStage When no file can be placed there.
	 */
	public static function run( string $dir, string $name, callable $confirm, int $mode = 0755 ): void {
		$created = false;
		if ( ! is_dir( $dir ) ) {
			call_user_func( $confirm );
			if ( ! @mkdir( $dir, $mode ) && ! is_dir( $dir ) ) {
				throw new CannotStage( sprintf( 'The must-use plugins directory %s does not exist and cannot be created. The restore puts a small must-use plugin there that can undo the restore if the site does not come back; create that directory and make it writable by the web server, then try again.', $dir ) );
			}
			$created = true;
		}
		$file = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $name;
		try {
			AtomicFile::write( $dir, $name, self::CONTENTS, array( 'confirm' => $confirm ) );
		} catch ( AtomicWriteFailed $e ) {
			throw new CannotStage( sprintf( 'A file cannot be put into the must-use plugins directory %1$s (%2$s). The restore puts a small must-use plugin there that can undo the restore if the site does not come back; make that directory writable by the web server, then try again.', $dir, $e->getMessage() ) );
		} finally {
			@unlink( $file );
			if ( $created ) {
				$entries = @scandir( $dir );
				if ( is_array( $entries ) && array() === array_diff( $entries, array( '.', '..' ) ) ) {
					@rmdir( $dir );
				}
			}
		}
		clearstatcache( true, $file );
		if ( file_exists( $file ) ) {
			throw new CannotStage( sprintf( 'A file the restore put into the must-use plugins directory %s cannot be removed again, and the restore must be able to remove its must-use plugin once it is done. Check the permissions of that directory, then try again.', $dir ) );
		}
	}
}
