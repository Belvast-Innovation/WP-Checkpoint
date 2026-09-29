<?php
/**
 * Where the content groups of a site live on disk.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Files;

use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * The WordPress side of the scanner: turns the manifest's content groups
 * (plugins, themes, uploads, mu-plugins, other-content) into roots for
 * FileScanner. The archive path "p" is relative to ABSPATH when the
 * directory lies inside it; a content directory outside ABSPATH (Bedrock
 * and similar layouts) is presented as "wp-content/..." relative to the
 * content directory, so every archive shows the canonical layout and the
 * restore maps "wp-content/" onto the target's content directory. A group
 * whose directory lies outside both gets "wp-content/<group>".
 */
final class ScanRoots {

	const GROUPS = array( 'plugins', 'themes', 'uploads', 'mu-plugins', 'other-content' );

	/**
	 * Roots for the requested groups, in scan order.
	 *
	 * @param string[]              $groups      Content groups (subset of GROUPS).
	 * @param string                $storage_dir The plugin's storage directory, never scanned.
	 * @param array<string, string> $overrides   Group => absolute directory (tests); defaults come from WordPress.
	 * @return array{roots: array<int, array{group: string, path: string, prefix: string, skip: string[], also_skip: string[], collide: string[], hold: string[], refuse: string}>, warnings: string[]}
	 */
	public static function resolve( array $groups, string $storage_dir = '', array $overrides = array() ): array {
		$dirs     = array_merge( self::wordpress_directories(), $overrides );
		$warnings = array();
		$chosen   = array();
		$others   = array();
		foreach ( self::GROUPS as $group ) {
			$path = rtrim( Paths::normalize( (string) $dirs[ $group ] ), '/' );
			if ( ! in_array( $group, $groups, true ) ) {
				if ( 'other-content' !== $group && '' !== $path ) {
					$others[] = $path; // Not chosen: no root backs it up (skipped wherever it lies).
				}
				continue;
			}
			if ( '' === $path || ! is_dir( $path ) ) {
				$warnings[] = 'The directory of the "' . $group . '" group does not exist and was not backed up.';
				continue;
			}
			$chosen[ $group ] = $path;
		}
		$storage = '' === $storage_dir ? array() : array( rtrim( Paths::normalize( $storage_dir ), '/' ) );
		$content = rtrim( Paths::normalize( (string) $dirs['content'] ), '/' );
		$abspath = (string) $dirs['abspath'];

		// 1. The scan's judgement of each group directory (Links::root_verdict()): a link must not lead to or above
		// the WordPress directory or the content directory, to the root of the file system, or into the storage.
		// A refused group stays a root (the scan reports it) but adds nothing below: its place is not skipped by
		// any other root, and it takes no directory from another group.
		$real    = array();
		$refused = array();
		foreach ( $chosen as $group => $path ) {
			$real[ $group ] = Links::key( $path );
			$verdict        = Links::root_verdict( $path, $abspath, $storage, null, 'other-content' === $group ? array() : array( $content ) );
			if ( '' !== $verdict['refusal'] ) {
				$refused[ $group ] = true;
			}
		}

		// 2. One directory, one root: a group whose directory is the same as another's (by real path) is backed
		// up as part of that one, the content directory first, then the earlier group. Nothing is left out.
		$keepers = array_merge( isset( $chosen['other-content'] ) ? array( 'other-content' ) : array(), array_diff( array_keys( $chosen ), array( 'other-content' ) ) );
		$kept    = array();
		foreach ( $keepers as $group ) {
			if ( ! isset( $refused[ $group ] ) ) {
				foreach ( $kept as $other => $unused ) {
					if ( ! isset( $refused[ $other ] ) && $real[ $other ] === $real[ $group ] ) {
						$warnings[] = 'The "' . $group . '" directory is the same directory as the "' . $other . '" directory and is backed up as part of it.';
						continue 2;
					}
				}
			}
			$kept[ $group ] = self::prefix( $group, $chosen[ $group ], $abspath, $content );
		}

		// 3. Roots in scan order. Each skips the plugin's storage, the groups not chosen, and the directories of
		// the other roots (those roots back them up under their own paths), all by real path. Where another
		// root's archive path lies below this root's while that root is elsewhere (a group outside both the
		// WordPress and the content directory is shown as "wp-content/<group>"), the directory of that name here
		// would give the same archive paths: it is left out and listed with the unreadable entries ('collide'),
		// with a warning when it exists. Two different directories with one archive path: the later is refused.
		$fold  = static function ( string $text ): string {
			return Paths::is_windows() ? strtolower( $text ) : $text;
		};
		$roots = array();
		$taken = array();
		foreach ( $chosen as $group => $path ) {
			if ( ! isset( $kept[ $group ] ) ) {
				continue;
			}
			$prefix = $kept[ $group ];
			$root   = array(
				'group'     => $group,
				'path'      => $path,
				'prefix'    => $prefix,
				'skip'      => $storage,
				'also_skip' => $others,
				'collide'   => array(),
				'hold'      => 'other-content' === $group ? array() : array( $content ),
				'refuse'    => '',
			);
			if ( isset( $taken[ $fold( $prefix ) ] ) ) {
				$root['refuse'] = 'another content group is backed up under the same path';
			}
			$taken[ $fold( $prefix ) ] = true;
			foreach ( $kept as $other => $other_prefix ) {
				if ( $other === $group ) {
					continue;
				}
				if ( ! isset( $refused[ $other ] ) ) {
					$root['also_skip'][] = $chosen[ $other ];
				}
				if ( 0 === strpos( $fold( $other_prefix ), $fold( $prefix . '/' ) ) ) {
					$there = $path . '/' . substr( $other_prefix, strlen( $prefix ) + 1 );
					if ( Links::key( $there ) !== $real[ $other ] ) {
						$root['collide'][] = $there;
						if ( file_exists( $there ) ) {
							$warnings[] = 'The directory ' . $other_prefix . ' in the "' . $group . '" directory was not backed up: the "' . $other . '" group, backed up under that path, is in another place on this site.';
						}
					}
				}
			}
			$roots[] = $root;
		}
		return array(
			'roots'    => $roots,
			'warnings' => $warnings,
		);
	}

	/**
	 * The archive path prefix of a group directory.
	 *
	 * @param string $group   Group.
	 * @param string $path    Normalised absolute directory.
	 * @param string $abspath Normalised ABSPATH.
	 * @param string $content Normalised content directory.
	 * @return string
	 */
	public static function prefix( string $group, string $path, string $abspath, string $content ): string {
		$abspath = rtrim( $abspath, '/' );
		$content = rtrim( $content, '/' );
		if ( '' !== $abspath && Paths::is_prefix( $abspath, $path, Paths::is_windows() ) ) {
			return substr( $path, strlen( $abspath ) + 1 );
		}
		if ( '' !== $content && Paths::is_prefix( $content, $path, Paths::is_windows() ) ) {
			return 'wp-content/' . substr( $path, strlen( $content ) + 1 );
		}
		if ( '' !== $content && Paths::same( $content, $path, Paths::is_windows() ) ) {
			return 'wp-content';
		}
		return 'wp-content/' . $group;
	}

	/**
	 * Each content group's live directory on this site (other-content: the content directory itself), as
	 * WordPress reports them, resolved and normalised: a directory reached through a link is named by where
	 * it is (the swap renames that directory, and staging goes next to it), a missing one by its resolved
	 * parent and its name. On a multisite network, uploads is the main site's upload directory whichever site
	 * the request runs for: another site's ("uploads/sites/N") is inside it.
	 *
	 * @return array<string, string>
	 */
	public static function site_directories(): array {
		$dirs = self::wordpress_directories();
		if ( function_exists( 'is_multisite' ) && is_multisite() && ! is_main_site() ) {
			switch_to_blog( get_main_site_id() );
			$uploads = wp_upload_dir( null, false );
			restore_current_blog();
			if ( isset( $uploads['basedir'] ) ) {
				$dirs['uploads'] = Paths::normalize( (string) $uploads['basedir'] );
			}
		}
		$out = array();
		foreach ( self::GROUPS as $group ) {
			$out[ $group ] = self::resolved( rtrim( $dirs[ $group ], '/' ) );
		}
		return $out;
	}

	/**
	 * A directory where it is: realpath(), or its parent's with its name when it does not exist; as given when
	 * neither resolves.
	 *
	 * @param string $dir Directory.
	 * @return string
	 */
	public static function resolved( string $dir ): string {
		$real = realpath( $dir );
		if ( false === $real ) {
			$parent = realpath( dirname( $dir ) );
			$real   = false === $parent ? false : rtrim( $parent, '/\\' ) . '/' . basename( $dir );
		}
		return false === $real ? $dir : rtrim( Paths::normalize( $real ), '/' );
	}

	/**
	 * The site's directories as WordPress reports them.
	 *
	 * @return array<string, string>
	 */
	private static function wordpress_directories(): array {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir( null, false ) : array();
		return array(
			'abspath'       => Paths::normalize( ABSPATH ),
			'content'       => Paths::normalize( WP_CONTENT_DIR ),
			'plugins'       => defined( 'WP_PLUGIN_DIR' ) ? Paths::normalize( WP_PLUGIN_DIR ) : Paths::normalize( WP_CONTENT_DIR ) . '/plugins',
			'mu-plugins'    => defined( 'WPMU_PLUGIN_DIR' ) ? Paths::normalize( WPMU_PLUGIN_DIR ) : Paths::normalize( WP_CONTENT_DIR ) . '/mu-plugins',
			'themes'        => function_exists( 'get_theme_root' ) ? Paths::normalize( get_theme_root() ) : Paths::normalize( WP_CONTENT_DIR ) . '/themes',
			'uploads'       => isset( $uploads['basedir'] ) ? Paths::normalize( (string) $uploads['basedir'] ) : Paths::normalize( WP_CONTENT_DIR ) . '/uploads',
			'other-content' => Paths::normalize( WP_CONTENT_DIR ),
		);
	}
}
