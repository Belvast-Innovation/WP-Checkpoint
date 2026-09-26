<?php
/**
 * What a restore can do in a directory where it would stage files.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- plain file system calls on a directory of the restore's own.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- errors with paths; the job presenter cleans them.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put the path into the error log; failures are thrown with a reason.

/**
 * No WordPress. In a staging parent, one probe directory (named by
 * StagingLayout::probe_name()) is created, two files are written in it, the
 * directory is renamed and everything is removed again: what staging and
 * the swap will do there. The files answer how the file system compares
 * names (TargetNames): whether "Aé", "aÉ" and "ae" + combining acute reach
 * the file "aé", and whether "b" reaches "b.". The directory's device
 * number is the file system's identity for the same-disk and free-space
 * checks.
 *
 * Creating and renaming are confirmed first ($confirm, the job's lease).
 * Removing needs no confirmation: the names are random and this probe's
 * alone. A process that dies in between leaves the probe directory, which
 * the reaper removes (Residue: probe).
 */
final class DirectoryProbe {

	/**
	 * "aé", NFC.
	 */
	const NAME = "a\xC3\xA9";

	/**
	 * Other spellings of NAME, by the behaviour that makes them reach it.
	 */
	const VARIANTS = array(
		'fold_ascii'   => "A\xC3\xA9",
		'fold_unicode' => "a\xC3\x89",
		'normalize'    => "ae\xCC\x81",
	);

	/**
	 * A name with a trailing dot, and the same name without it.
	 */
	const TRAILING = 'b.';
	const BARE     = 'b';

	/**
	 * Probe a directory.
	 *
	 * @param string   $where   Staging parent (exists).
	 * @param string   $name    Probe directory name.
	 * @param callable $confirm function(): void, throws to stop.
	 * @return array{dev: int, names: TargetNames}
	 * @throws CannotStage When the directory cannot be used for staging.
	 */
	public static function run( string $where, string $name, callable $confirm ): array {
		$dir = rtrim( $where, '/\\' ) . '/' . $name;
		call_user_func( $confirm );
		if ( ! @mkdir( $dir, 0700 ) ) {
			throw new CannotStage( sprintf( 'A directory cannot be created in %s, where the restore stages its files. Make that directory writable by the web server (and by WP-CLI, if the restore runs there), then try again.', $where ) );
		}
		$renamed = false;
		try {
			foreach ( array( self::NAME, self::TRAILING ) as $file ) {
				$handle = @fopen( $dir . '/' . $file, 'xb' );
				if ( false === $handle ) {
					throw new CannotStage( sprintf( 'Files cannot be created in a new directory in %s, where the restore stages its files. Make that directory writable by the web server (and by WP-CLI, if the restore runs there), then try again.', $where ) );
				}
				fclose( $handle );
			}
			clearstatcache();
			$flags = array();
			foreach ( self::VARIANTS as $flag => $variant ) {
				$flags[ $flag ] = file_exists( $dir . '/' . $variant );
			}
			$names = new TargetNames( $flags['fold_ascii'], $flags['fold_unicode'], $flags['normalize'], file_exists( $dir . '/' . self::BARE ) );
			$stat  = @stat( $dir );
			if ( false === $stat ) {
				throw new CannotStage( sprintf( 'A new directory in %s cannot be examined, so whether it is on the same disk as the directories the restore replaces cannot be told.', $where ) );
			}
			call_user_func( $confirm );
			if ( ! @rename( $dir, $dir . '-r' ) ) {
				throw new CannotStage( sprintf( 'A directory cannot be renamed in %s. The restore swaps its staged files in by renaming, so it cannot run here. Check the permissions of that directory (and any security software that blocks renames), then try again.', $where ) );
			}
			$renamed = true;
		} finally {
			self::remove( $renamed ? $dir . '-r' : $dir );
		}
		clearstatcache();
		if ( file_exists( $dir . '-r' ) ) {
			throw new CannotStage( sprintf( 'A directory the restore created in %s cannot be removed again. The restore removes what it replaced once it is done, so it cannot run here. Check the permissions of that directory, then try again.', $where ) );
		}
		return array(
			'dev'   => (int) $stat['dev'],
			'names' => $names,
		);
	}

	/**
	 * Remove the probe directory and what is in it (best effort).
	 *
	 * @param string $dir Probe directory.
	 * @return void
	 */
	private static function remove( string $dir ): void {
		$entries = @scandir( $dir );
		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				@unlink( $dir . '/' . $entry );
			}
		}
		@rmdir( $dir );
	}
}
