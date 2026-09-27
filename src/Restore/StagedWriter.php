<?php
/**
 * Opening a staged file safely, fresh or to resume it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Archive\EntryPath;
use WPCheckpoint\Archive\EnvironmentFailure;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- plain file system calls under the restore's own staging root.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- a warning would put a path into the error log; failures are thrown with a reason.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- job errors with paths; the presenter cleans them.

/**
 * No WordPress. The root passed in is the staging root itself, and every
 * directory level from it down is looked at with lstat() before anything
 * is written under it: it must be a directory, not a link to one (a link
 * planted there would send the write elsewhere); a missing level is created
 * alone, with this site's mode. The relative path must be an archive entry
 * path (EntryPath: no "", "." or ".." segment). A new file is created
 * exclusively ("x": an entry already there, a link included, is never
 * followed). A file being resumed must be a regular file with one name
 * (lstat(): a hard link would make the write land in the other name's
 * file), and the handle opened must be that same file (fstat()'s device
 * and inode equal lstat()'s); it is cut back to the length the restore
 * committed, and one shorter than that is not padded (StagingChanged).
 * Messages name the path under the root only: the root's random name is
 * its protection where .htaccess is not read.
 *
 * Known limits: PHP has no openat(), so between a level's lstat() and the
 * mkdir() or fopen() under it, a process that can write in the staging
 * root as this site's user could swap the level (the root's random name
 * keeps it from being found); and the link checks rely on POSIX lstat()
 * (PHP on Windows reports a junction as a directory), which is why
 * restores refuse Windows servers (RestorePlatformStep).
 */
final class StagedWriter {

	/**
	 * Make sure the directories of a relative path exist under a root, each checked.
	 *
	 * @param string        $root     Staging directory (exists).
	 * @param string        $relative Directory path under it, "/"-separated ('' for the root itself).
	 * @param int           $mode     Mode of a created directory.
	 * @param callable|null $made     function( string $dir ): void after a directory is created (tests).
	 * @return void
	 * @throws StagingChanged When a level is a link or not a directory.
	 * @throws EnvironmentFailure When a directory cannot be created.
	 */
	public static function directories( string $root, string $relative, int $mode, $made = null ): void {
		if ( '' !== $relative && null !== EntryPath::problem( $relative ) ) {
			throw new StagingChanged( 'A staged path is not a path under the staging directory.' );
		}
		self::assert_directory( $root, '' );
		$path = $root;
		$here = '';
		foreach ( '' === $relative ? array() : explode( '/', $relative ) as $segment ) {
			$here .= ( '' === $here ? '' : '/' ) . $segment;
			$path .= '/' . $segment;
			clearstatcache( true, $path );
			$stat = @lstat( $path );
			if ( false === $stat ) {
				if ( ! @mkdir( $path, $mode ) ) {
					clearstatcache( true, $path );
					if ( false === @lstat( $path ) ) {
						throw new EnvironmentFailure( sprintf( 'The directory %s cannot be created where the restore stages its files.', $here ) );
					}
				} else {
					@chmod( $path, $mode ); // mkdir() applies the umask.
					if ( null !== $made ) {
						call_user_func( $made, $path );
					}
				}
			}
			self::assert_directory( $path, $here );
		}
	}

	/**
	 * Open a staged file for writing at the committed length.
	 *
	 * @param string        $root      Staging directory.
	 * @param string        $relative  File path under it, "/"-separated.
	 * @param int           $committed Bytes the restore committed of it (0: none).
	 * @param int           $dir_mode  Mode of a created directory.
	 * @param int           $file_mode Mode of a created file.
	 * @param callable|null $made function( string $dir ): void after a directory is created (tests).
	 * @param callable|null $between function( string $path ): void after the file is looked at and before it is
	 *                               opened to be resumed (tests: where another process could replace it).
	 * @return resource Positioned at $committed.
	 * @throws StagingChanged When the path or the file is not what the restore wrote.
	 * @throws EnvironmentFailure When it cannot be created or opened.
	 */
	public static function open( string $root, string $relative, int $committed, int $dir_mode, int $file_mode, $made = null, $between = null ) {
		if ( null !== EntryPath::problem( $relative ) ) {
			throw new StagingChanged( 'A staged path is not a path under the staging directory.' );
		}
		$slash = strrpos( $relative, '/' );
		self::directories( $root, false === $slash ? '' : substr( $relative, 0, $slash ), $dir_mode, $made );
		$path = $root . '/' . $relative;
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( false === $stat ) {
			if ( $committed > 0 ) {
				throw new StagingChanged( sprintf( 'The staged file %s is gone; the staging directory was changed.', $relative ) );
			}
			$handle = @fopen( $path, 'xb' );
			if ( false === $handle ) {
				throw new EnvironmentFailure( sprintf( 'The file %s cannot be created where the restore stages its files.', $relative ) );
			}
			@chmod( $path, $file_mode );
			return $handle;
		}
		if ( 0100000 !== ( (int) $stat['mode'] & 0170000 ) || 1 !== (int) $stat['nlink'] ) {
			throw new StagingChanged( sprintf( 'The staged path %s is not a regular file of its own (a link, or a file with another name too); the staging directory was changed.', $relative ) );
		}
		if ( null !== $between ) {
			call_user_func( $between, $path );
		}
		$handle = @fopen( $path, 'r+b' );
		if ( false === $handle ) {
			throw new EnvironmentFailure( sprintf( 'The staged file %s cannot be opened to continue it.', $relative ) );
		}
		$opened = fstat( $handle );
		if ( ! is_array( $opened ) || (int) $opened['dev'] !== (int) $stat['dev'] || (int) $opened['ino'] !== (int) $stat['ino'] || 0100000 !== ( (int) $opened['mode'] & 0170000 ) ) {
			fclose( $handle );
			throw new StagingChanged( sprintf( 'The staged file %s is not the one that was looked at; the staging directory was changed.', $relative ) );
		}
		if ( (int) $opened['size'] < $committed ) {
			fclose( $handle );
			throw new StagingChanged( sprintf( 'The staged file %s is shorter than the restore recorded; the staging directory was changed.', $relative ) );
		}
		if ( (int) $opened['size'] > $committed && ! ftruncate( $handle, $committed ) ) {
			fclose( $handle );
			throw new EnvironmentFailure( sprintf( 'The staged file %s cannot be cut back to its committed length.', $relative ) );
		}
		if ( 0 !== fseek( $handle, $committed ) ) {
			fclose( $handle );
			throw new EnvironmentFailure( sprintf( 'The staged file %s cannot be positioned.', $relative ) );
		}
		return $handle;
	}

	/**
	 * A path must be a directory, not a link to one.
	 *
	 * @param string $path Path.
	 * @param string $name What to call it in a message ('' for the staging directory itself).
	 * @return void
	 * @throws StagingChanged When it is not.
	 */
	public static function assert_directory( string $path, string $name = '' ): void {
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		if ( false === $stat || 0040000 !== ( (int) $stat['mode'] & 0170000 ) ) {
			throw new StagingChanged( sprintf( 'The staging path %s is not a directory (or is a link to one); the staging directory was changed.', '' === $name ? '(the staging directory)' : $name ) );
		}
	}
}
