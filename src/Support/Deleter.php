<?php
/**
 * Recursive deletion confined to a base directory.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

use WPCheckpoint\Restore\StagingLayout;

// phpcs:disable WordPress.WP.AlternativeFunctions -- pure PHP deleter also used from uninstall.php; WP_Filesystem is unavailable there and would follow links.

/**
 * Deletes trees the plugin owns without ever following a link.
 *
 * Before anything is touched, every entry point asks refusal() about the
 * path it deletes (delete_tree()'s target, empty_directory()'s directory)
 * and throws DeletionRefused, deleting nothing, when it is: empty (PHP
 * resolves '' to the working directory), relative, the root of the file
 * system, ABSPATH, the content directory, the plugins directory or this
 * plugin's own directory, or any directory that holds one of these; or
 * when it lies outside every directory the plugin may delete in. Those
 * are: a storage directory of this plugin (it carries the owner marker,
 * OwnerMarker::FILENAME; what it opens is in storage_allows(): all of it
 * only when it has the plugin's own name, otherwise its sub-directories
 * and its own files, and nothing when it is itself refused), a staging
 * root or probe of a restore (a name StagingLayout::parse() recognises),
 * and a directory registered with allow() (the tests register their
 * sandboxes, and the temporary directory for what is inside it only).
 *
 * Past that check, every path is checked with Paths::is_inside() against
 * the base before it is touched, symbolic links and junctions are removed
 * as links (their targets are never entered), and failures are collected
 * instead of thrown.
 */
final class Deleter {

	/**
	 * What storage_refusal() says of a path that is not absolute, or has . or .. segments and leads nowhere yet.
	 */
	public const NOT_A_FULL_PATH = 'the path is relative or has . or .. segments';

	/**
	 * Directories registered with allow(): resolved path => whether the directory itself may go too (not only what is
	 * inside it).
	 *
	 * @var array<string, bool>
	 */
	private static $roots = array();

	/**
	 * Directories treated like ABSPATH besides the WordPress ones (tests): resolved paths.
	 *
	 * @var string[]
	 */
	private static $protected = array();

	/**
	 * Register a directory the plugin may delete in.
	 *
	 * @param string $root   Directory.
	 * @param bool   $itself Whether the directory itself may be deleted or emptied too, not only what is inside it (a
	 *                       shared directory, such as the temporary directory, is registered without). A directory
	 *                       registered again keeps the narrower of the two.
	 * @return void
	 * @throws DeletionRefused When it is not one that may be registered (empty, relative, the root of the file system,
	 *                         or a WordPress directory or one that holds it).
	 */
	public static function allow( string $root, bool $itself = true ): void {
		$why = self::basic_refusal( $root );
		if ( '' !== $why ) {
			throw new DeletionRefused( sprintf( '%1$s cannot be registered as a directory to delete in: %2$s.', $root, $why ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
		$resolved                 = self::resolve( $root );
		self::$roots[ $resolved ] = isset( self::$roots[ $resolved ] ) ? self::$roots[ $resolved ] && $itself : $itself; // Registering again only narrows.
	}

	/**
	 * Why a path must not be deleted, or '' when it may.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function refusal( string $path ): string {
		$why = self::basic_refusal( $path );
		if ( '' !== $why ) {
			return $why;
		}
		$real = self::resolve( $path );
		foreach ( self::$roots as $root => $itself ) {
			if ( $itself ? self::within( (string) $root, $real ) : Paths::is_prefix( (string) $root, $real, Paths::is_windows() ) ) {
				return '';
			}
		}
		if ( self::in_owned_root( $real ) ) {
			return '';
		}
		return 'it is outside every directory the plugin may delete in (its storage directory, a staging root of a restore, a registered directory)';
	}

	/**
	 * Why a directory cannot be a storage directory, or '' when it can: it is the root of the file system, a WordPress
	 * directory or one that holds it. Nothing could be deleted in such a directory (storage_allows()), so its jobs'
	 * files would never be reclaimed. Both the path as named and where it leads count (a link to such a directory is
	 * one too). A path that does not resolve (its parent does not exist yet) holds nothing. A relative path is
	 * refused: what it names depends on the working directory, which differs between requests, and every path built
	 * on it would be refused as relative. So is a path with . or .. segments that does not lead to an existing
	 * directory: where those segments end up is not known yet (an existing one is checked where it leads).
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	public static function storage_refusal( string $dir ): string {
		if ( '' === trim( $dir ) || ! self::absolute_on( $dir, Paths::is_windows() ) ) {
			return self::NOT_A_FULL_PATH;
		}
		$leads = @realpath( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
		if ( false === $leads ) {
			foreach ( explode( '/', str_replace( '\\', '/', $dir ) ) as $segment ) {
				if ( '.' === $segment || '..' === $segment ) {
					return self::NOT_A_FULL_PATH;
				}
			}
		}
		$paths = array( self::resolve( $dir ) );
		if ( false !== $leads ) {
			$paths[] = '' === rtrim( $leads, '/\\' ) ? DIRECTORY_SEPARATOR : rtrim( $leads, '/\\' );
		}
		foreach ( $paths as $real ) {
			$why = '' === $real ? '' : self::holding_refusal( $real );
			if ( '' !== $why ) {
				return $why;
			}
		}
		return '';
	}

	/**
	 * Tests: replace the registered directories, returning the ones before.
	 *
	 * @param array<string, bool> $roots Resolved directory => whether the directory itself may go too.
	 * @return array<string, bool>
	 */
	public static function replace_roots( array $roots ): array {
		$before      = self::$roots;
		self::$roots = array_map( 'boolval', $roots );
		return $before;
	}

	/**
	 * Tests: replace the directories treated like ABSPATH, returning the ones before.
	 *
	 * @param string[] $dirs Directories.
	 * @return string[]
	 */
	public static function replace_protected( array $dirs ): array {
		$before          = self::$protected;
		self::$protected = array_values( array_map( 'strval', $dirs ) );
		return $before;
	}

	/**
	 * The refusals that hold whatever is registered: empty, relative, unresolvable, the root of the file system, a
	 * protected directory or one that holds it.
	 *
	 * @param string $path Path.
	 * @return string '' when none applies.
	 */
	private static function basic_refusal( string $path ): string {
		if ( '' === trim( $path ) ) {
			return 'the path is empty (it would mean the working directory)';
		}
		if ( ! self::is_absolute( $path ) ) {
			return 'the path is relative';
		}
		$real = self::resolve( $path );
		if ( '' === $real ) {
			return 'the path cannot be resolved';
		}
		return self::holding_refusal( $real );
	}

	/**
	 * Why a resolved path must never be deleted whatever holds it: the root of the file system, a protected directory
	 * or one that holds it. '' when neither.
	 *
	 * @param string $real Resolved path.
	 * @return string
	 */
	private static function holding_refusal( string $real ): string {
		if ( self::is_filesystem_root( $real ) ) {
			return 'it is the root of the file system';
		}
		foreach ( self::protected_dirs() as $label => $dir ) {
			if ( self::within( $real, $dir ) ) {
				return sprintf( 'it is %s or holds it', $label );
			}
		}
		return '';
	}

	/**
	 * The directories never deleted, nor anything that holds them: label => resolved path.
	 *
	 * @return array<string, string>
	 */
	private static function protected_dirs(): array {
		$named = array();
		foreach ( array(
			'ABSPATH'          => 'the WordPress directory (ABSPATH)',
			'WP_CONTENT_DIR'   => 'the content directory',
			'WP_PLUGIN_DIR'    => 'the plugins directory',
			'WPCHECKPOINT_DIR' => 'the directory of WP Checkpoint',
		) as $constant => $label ) {
			if ( defined( $constant ) ) {
				$named[ $label ] = (string) constant( $constant );
			}
		}
		foreach ( self::$protected as $i => $dir ) {
			$named[ 'a protected directory (' . ( $i + 1 ) . ')' ] = $dir;
		}
		$out = array();
		foreach ( $named as $label => $dir ) {
			// Both the path as named and where it leads (a directory reached through a link is protected at both).
			$resolved = self::resolve( $dir );
			if ( '' !== $resolved ) {
				$out[ $label ] = $resolved;
			}
			$real = @realpath( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			if ( false !== $real && rtrim( $real, '/\\' ) !== $resolved ) {
				$out[ $label . ', where it leads' ] = rtrim( $real, '/\\' );
			}
		}
		return $out;
	}

	/**
	 * Whether a resolved path is in a directory of this plugin's: a staging root or probe of a restore (its name, the
	 * path itself or a directory above it), or a storage directory (the nearest directory above it, or the path itself,
	 * that carries the owner marker; see storage_allows()).
	 *
	 * @param string $real Resolved path.
	 * @return bool
	 */
	private static function in_owned_root( string $real ): bool {
		$dir = $real;
		while ( true ) {
			if ( null !== StagingLayout::parse( basename( $dir ) ) ) {
				return true;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			if ( @is_dir( $dir ) && ! @is_link( $dir ) && @is_file( $dir . DIRECTORY_SEPARATOR . OwnerMarker::FILENAME ) ) {
				return self::storage_allows( $dir, $real );
			}
			$parent = dirname( $dir );
			if ( $parent === $dir || self::is_filesystem_root( $parent ) ) {
				return false;
			}
			$dir = $parent;
		}
	}

	/**
	 * What a storage directory lets be deleted: its own sub-directories (Directories::SUBDIRS) and what is in them,
	 * and its own files (index.php, .htaccess, the marker). The whole directory only when it has the plugin's own name
	 * (Directories::DIR_PREFIX and a token): a custom one (WPCHECKPOINT_STORAGE_DIR) may be a directory that holds
	 * other things. Never a storage directory that is itself refused (a WordPress directory, one that holds it).
	 *
	 * @param string $storage Directory with the owner marker.
	 * @param string $real    Resolved path.
	 * @return bool
	 */
	private static function storage_allows( string $storage, string $real ): bool {
		if ( '' !== self::basic_refusal( $storage ) ) {
			return false;
		}
		$name = basename( $storage );
		if ( 0 === strpos( $name, Directories::DIR_PREFIX ) && Directories::is_valid_token( substr( $name, strlen( Directories::DIR_PREFIX ) ) ) ) {
			return true;
		}
		if ( $real === $storage ) {
			return false;
		}
		$first = explode( '/', str_replace( '\\', '/', substr( $real, strlen( rtrim( $storage, '/\\' ) ) + 1 ) ) )[0];
		if ( in_array( $first, Directories::SUBDIRS, true ) ) {
			return true;
		}
		return $real === $storage . DIRECTORY_SEPARATOR . $first && in_array( $first, array( 'index.php', '.htaccess', OwnerMarker::FILENAME ), true );
	}

	/**
	 * The path resolved: the real path of an existing entry that is not a link, otherwise its parent's real path and
	 * its name (a link is deleted as itself). '' when neither resolves.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function resolve( string $path ): string {
		$trimmed = rtrim( $path, '/\\' );
		if ( '' === $trimmed ) {
			$real = @realpath( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			return false === $real ? '' : $real;
		}
		$name = basename( $trimmed );
		if ( '.' === $name || '..' === $name || ! @is_link( $trimmed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			$real = @realpath( $trimmed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
			if ( false !== $real ) {
				$real = rtrim( $real, '/\\' );
				return '' === $real ? DIRECTORY_SEPARATOR : $real;
			}
			if ( '.' === $name || '..' === $name ) {
				return '';
			}
		}
		$parent = @realpath( dirname( $trimmed ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir warnings would name the path.
		return false === $parent ? '' : rtrim( $parent, '/\\' ) . DIRECTORY_SEPARATOR . $name;
	}

	/**
	 * Whether a path is absolute on this platform.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	private static function is_absolute( string $path ): bool {
		return self::absolute_on( $path, Paths::is_windows() );
	}

	/**
	 * Whether a path is absolute on a platform: POSIX, "/…"; Windows also a drive ("C:\…", "C:/…") or a UNC path
	 * ("\\server\share"). On POSIX a backslash is an ordinary character, so those forms are relative there.
	 *
	 * @param string $path    Path (not empty).
	 * @param bool   $windows Whether the platform is Windows.
	 * @return bool
	 */
	public static function absolute_on( string $path, bool $windows ): bool {
		if ( '' === $path ) {
			return false;
		}
		if ( '/' === $path[0] ) {
			return true;
		}
		return $windows && ( '\\' === $path[0] || 1 === preg_match( '#\A[A-Za-z]:[\\\\/]#', $path ) );
	}

	/**
	 * Whether a resolved path is the root of a file system.
	 *
	 * @param string $real Resolved path.
	 * @return bool
	 */
	private static function is_filesystem_root( string $real ): bool {
		return '' === rtrim( $real, '/\\' ) || 1 === preg_match( '#\A[A-Za-z]:[\\\\/]?\z#', $real ) || dirname( $real ) === $real;
	}

	/**
	 * Whether a resolved path is a directory or inside it.
	 *
	 * @param string $outer Resolved directory.
	 * @param string $inner Resolved path.
	 * @return bool
	 */
	private static function within( string $outer, string $inner ): bool {
		$windows = Paths::is_windows();
		return Paths::same( $outer, $inner, $windows ) || Paths::is_prefix( $outer, $inner, $windows );
	}

	/**
	 * Refuse, deleting nothing, a path refusal() does not allow.
	 *
	 * @param string $path Path.
	 * @return void
	 * @throws DeletionRefused When it is refused.
	 */
	private static function guard( string $path ): void {
		$why = self::refusal( $path );
		if ( '' !== $why ) {
			throw new DeletionRefused( sprintf( 'Nothing was deleted: %1$s is not deleted because %2$s.', $path, $why ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
	}

	/**
	 * The names delete_maintenance_file() deletes: the maintenance file the swap writes and its temporary names
	 * (AtomicFile).
	 */
	const MAINTENANCE_NAMES = '/\A\.maintenance(?:\.[0-9a-f]{16}\.tmp)?\z/';

	/**
	 * Delete the maintenance file a restore's swap wrote in ABSPATH, or one of its temporary files. The only file
	 * the plugin deletes in ABSPATH: one of MAINTENANCE_NAMES, directly in ABSPATH (or in a directory registered
	 * with allow(), the tests' stand-in), a regular file and not a link. Whether it is this restore's to delete
	 * (its contents) is the caller's to check; nothing else is checked here.
	 *
	 * @param string        $dir     ABSPATH (or a registered directory).
	 * @param string        $name    One of MAINTENANCE_NAMES.
	 * @param callable|null $confirm Called right before the file is deleted (a job's lease check; throws to stop).
	 * @return bool Whether it is gone (deleted now, or not there).
	 * @throws DeletionRefused When it is not such a file; nothing is deleted.
	 */
	public static function delete_maintenance_file( string $dir, string $name, $confirm = null ): bool {
		$path = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR . $name;
		$why  = 1 === preg_match( self::MAINTENANCE_NAMES, $name ) ? self::basic_refusal( $path ) : 'it is not a maintenance file of the plugin';
		if ( '' === $why && ! ( defined( 'ABSPATH' ) && Paths::same_location( $dir, (string) ABSPATH ) ) && '' !== self::refusal( $path ) ) {
			$why = 'it is not in the WordPress directory';
		}
		clearstatcache( true, $path );
		$stat = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- not there: nothing to delete.
		if ( '' === $why && false !== $stat && 0100000 !== ( $stat['mode'] & 0170000 ) ) {
			$why = 'it is not a regular file';
		}
		if ( '' !== $why ) {
			throw new DeletionRefused( sprintf( 'Nothing was deleted: %1$s is not deleted because %2$s.', $path, $why ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- internal message.
		}
		if ( false === $stat ) {
			return Paths::positively_gone( $path );
		}
		if ( is_callable( $confirm ) ) {
			call_user_func( $confirm );
		}
		return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the result says it.
	}

	/**
	 * Delete a file or directory tree strictly inside $base.
	 *
	 * With $max_entries > 0 the call stops after that many entries were
	 * deleted or failed and reports "remaining": a tree of a hundred
	 * thousand files is removed across several calls (the reaper runs
	 * every few minutes), each of which fits one tick. Deletion is
	 * idempotent, so a caller simply calls again later. The limit is a
	 * soft budget, not a security bound: it is checked before each entry,
	 * the rmdir() on the way back up is not counted, so a deeply nested
	 * chain can exceed it by its depth (bounded by the path length limit).
	 * Each directory's listing is loaded at once (scandir), so a single
	 * directory with millions of entries would exhaust memory; only this
	 * plugin's own steps can create such a directory.
	 *
	 * @param string $base        Directory the plugin owns.
	 * @param string $target      Entry to delete; must be inside $base.
	 * @param int    $max_entries Stop after this many entries (0: no limit).
	 * @return array{deleted: int, failed: string[], remaining: bool}
	 * @throws DeletionRefused When the target is not one the plugin may delete (refusal()); nothing is deleted.
	 */
	public static function delete_tree( string $base, string $target, int $max_entries = 0 ): array {
		self::guard( $target );
		$result = array(
			'deleted'   => 0,
			'failed'    => array(),
			'remaining' => false,
		);

		$target = rtrim( $target, '/\\' );
		if ( '' === $target ) {
			$result['failed'][] = $target;
			return $result;
		}

		if ( self::is_reparse( $target ) ) {
			// A link may be removed only when the link itself sits inside base.
			if ( ! Paths::is_inside( $base, dirname( $target ) ) && ! self::same_dir( $base, dirname( $target ) ) ) {
				$result['failed'][] = $target;
					return $result;
			}
			self::remove_link( $target, $result );
			return $result;
		}

		if ( ! Paths::is_inside( $base, $target ) ) {
			$result['failed'][] = $target;
			return $result;
		}

		$real = realpath( $target );
		if ( false === $real ) {
			$result['failed'][] = $target;
			return $result;
		}

		if ( is_dir( $real ) ) {
			self::delete_children( $base, $real, $result, $max_entries );
			if ( ! $result['remaining'] ) {
				self::remove_dir( $real, $result );
			}
		} else {
			self::remove_file( $real, $result );
		}

		return $result;
	}

	/**
	 * Delete everything inside $base but keep $base itself.
	 *
	 * @param string $base Directory the plugin owns.
	 * @return array{deleted: int, failed: string[]}
	 * @throws DeletionRefused When the directory is not one the plugin may empty (refusal()); nothing is deleted.
	 */
	public static function empty_directory( string $base ): array {
		self::guard( $base );
		$result = array(
			'deleted'   => 0,
			'failed'    => array(),
			'remaining' => false,
		);
		$real   = realpath( rtrim( $base, '/\\' ) );
		if ( false === $real || ! is_dir( $real ) || self::is_reparse( $base ) ) {
			$result['failed'][] = $base;
			return $result;
		}
		self::delete_children( $real, $real, $result );
		return $result;
	}

	const REPARSE_LINK    = 'link';
	const REPARSE_PLAIN   = 'plain';
	const REPARSE_UNKNOWN = 'unknown';

	/**
	 * Whether a path is a symbolic link, junction or other reparse point.
	 *
	 * "Unknown" (an empty directory, or one whose entries are all links, on a
	 * platform without a definite answer) counts as not a link here: entering
	 * such a directory cannot delete anything behind a link. Callers that must
	 * be sure (reclaiming a directory) use reparse_state() instead.
	 *
	 * @param string $path Path to inspect (no trailing separator).
	 * @return bool
	 */
	public static function is_reparse( string $path ): bool {
		return self::REPARSE_LINK === self::reparse_state( $path );
	}

	/**
	 * Classify a path as a link, a plain entry, or undecidable.
	 *
	 * The is_link() check covers symbolic links. Windows junctions are not
	 * reported by is_link(), but PHP's Windows readlink() returns the final
	 * path of any directory (it resolves junctions and symlinks), so a
	 * directory whose final path is not its parent's final path plus its own
	 * name is a reparse point. Elsewhere, a child entry is resolved through
	 * the directory as a fallback: realpath()
	 * resolves reparse points in intermediate path components on every
	 * platform (PHP's Windows implementation strips a trailing "." before
	 * resolving, so the entry must be a real child), and a result that is not
	 * the parent's real path plus the entry name means the entry redirects
	 * somewhere else. An empty directory has no child to probe; entering it
	 * cannot delete anything, and rmdir() on a junction removes the junction.
	 *
	 * @param string $path Path to inspect (no trailing separator).
	 * @return string One of the REPARSE_* constants.
	 */
	public static function reparse_state( string $path ): string {
		$path = rtrim( $path, '/\\' );
		if ( '' === $path ) {
			return self::REPARSE_PLAIN;
		}
		if ( is_link( $path ) ) {
			return self::REPARSE_LINK;
		}
		if ( Paths::is_windows() ) {
			// Checked before is_dir(): stat() reports a junction with mode 0, so
			// is_dir() and is_file() are both false for it.
			// Unavailable readlink() (disabled by the host) gives false: no definite answer from this branch.
			$final        = HostFunctions::readlink( $path );
			$final_parent = HostFunctions::readlink( dirname( $path ) );
			if ( is_string( $final ) && '' !== $final && is_string( $final_parent ) && '' !== $final_parent ) {
				$expected = rtrim( self::strip_windows_prefix( $final_parent ), '/\\' ) . DIRECTORY_SEPARATOR . basename( $path );
				return Paths::same( $expected, self::strip_windows_prefix( $final ), true ) ? self::REPARSE_PLAIN : self::REPARSE_LINK;
			}
		}
		if ( ! is_dir( $path ) ) {
			return self::REPARSE_PLAIN;
		}

		$parent = realpath( dirname( $path ) );
		if ( false === $parent ) {
			return self::REPARSE_UNKNOWN;
		}
		$child = self::first_plain_child( $path );
		if ( '' === $child ) {
			// Empty, or only links inside: nothing to resolve through, so no definite answer.
			return self::REPARSE_UNKNOWN;
		}
		$resolved = realpath( $path . DIRECTORY_SEPARATOR . $child );
		if ( false === $resolved ) {
			return self::REPARSE_UNKNOWN;
		}
		$expected = rtrim( $parent, '/\\' ) . DIRECTORY_SEPARATOR . basename( $path ) . DIRECTORY_SEPARATOR . $child;
		return Paths::same( $expected, $resolved, Paths::is_windows() ) ? self::REPARSE_PLAIN : self::REPARSE_LINK;
	}

	/**
	 * Remove the \\?\ and \\?\UNC\ prefixes Windows may put on final paths.
	 *
	 * @param string $path Path from readlink().
	 * @return string
	 */
	private static function strip_windows_prefix( string $path ): string {
		if ( 0 === strpos( $path, '\\\\?\\UNC\\' ) ) {
			return '\\\\' . substr( $path, 8 );
		}
		if ( 0 === strpos( $path, '\\\\?\\' ) ) {
			return substr( $path, 4 );
		}
		return $path;
	}

	/**
	 * Name of the first entry inside a directory that is not itself a link
	 * (resolving a link would tell us about the link, not the directory).
	 * Empty when the directory is empty, unreadable, or holds only links.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	private static function first_plain_child( string $dir ): string {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable directories simply yield no child.
		if ( ! is_array( $entries ) ) {
			return '';
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( ! is_link( $dir . DIRECTORY_SEPARATOR . $entry ) ) {
				return $entry;
			}
		}
		return '';
	}

	/**
	 * Delete the children of a real directory.
	 *
	 * @param string                                                 $base   Owning base directory.
	 * @param string                                                 $dir    Real directory path.
	 * @param array{deleted: int, failed: string[], remaining: bool} $result Accumulator.
	 * @param int                                                    $limit  Stop after this many entries (0: no limit).
	 * @return void
	 */
	private static function delete_children( string $base, string $dir, array &$result, int $limit = 0 ): void {
		$entries = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable directories are reported as failures.
		if ( false === $entries ) {
			$result['failed'][] = $dir;
			return;
		}
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			if ( $limit > 0 && $result['deleted'] + count( $result['failed'] ) >= $limit ) {
				$result['remaining'] = true;
				return;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $entry;
			// Between this check and the unlink() below the entry could be swapped for a link (TOCTOU); that
			// needs write access inside the plugin's directory, which is the web user already.
			if ( self::is_reparse( $path ) ) {
				self::remove_link( $path, $result );
				continue;
			}
			if ( ! Paths::is_inside( $base, $path ) ) {
				$result['failed'][] = $path;
				continue;
			}
			if ( is_dir( $path ) ) {
				self::delete_children( $base, $path, $result, $limit );
				if ( $result['remaining'] ) {
					return;
				}
				self::remove_dir( $path, $result );
			} else {
				self::remove_file( $path, $result );
			}
		}
	}

	/**
	 * Remove a link without touching its target.
	 *
	 * @param string                                                 $path   Link path.
	 * @param array{deleted: int, failed: string[], remaining: bool} $result Accumulator.
	 * @return void
	 */
	private static function remove_link( string $path, array &$result ): void {
		// Directory links on Windows must be removed with rmdir().
		if ( @unlink( $path ) || @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is recorded below.
			++$result['deleted'];
			return;
		}
		$result['failed'][] = $path;
	}

	/**
	 * Remove a regular file.
	 *
	 * @param string                                                 $path   File path.
	 * @param array{deleted: int, failed: string[], remaining: bool} $result Accumulator.
	 * @return void
	 */
	private static function remove_file( string $path, array &$result ): void {
		if ( @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is recorded below.
			++$result['deleted'];
			return;
		}
		$result['failed'][] = $path;
	}

	/**
	 * Remove an (empty) directory.
	 *
	 * @param string                                                 $path   Directory path.
	 * @param array{deleted: int, failed: string[], remaining: bool} $result Accumulator.
	 * @return void
	 */
	private static function remove_dir( string $path, array &$result ): void {
		if ( @rmdir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failure is recorded below.
			++$result['deleted'];
			return;
		}
		$result['failed'][] = $path;
	}

	/**
	 * Whether two directories resolve to the same location.
	 *
	 * @param string $a First directory.
	 * @param string $b Second directory.
	 * @return bool
	 */
	private static function same_dir( string $a, string $b ): bool {
		$ra = realpath( $a );
		$rb = realpath( $b );
		return false !== $ra && false !== $rb && Paths::same( $ra, $rb, Paths::is_windows() );
	}
}
