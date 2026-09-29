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
	 * @return array{roots: array<int, array{group: string, path: string, prefix: string, skip: string[], hold: string[], also_skip: string[]}>, warnings: string[]}
	 */
	public static function resolve( array $groups, string $storage_dir = '', array $overrides = array() ): array {
		$dirs     = array_merge( self::wordpress_directories(), $overrides );
		$roots    = array();
		$warnings = array();
		$chosen   = array();
		foreach ( self::GROUPS as $group ) {
			if ( ! in_array( $group, $groups, true ) ) {
				continue;
			}
			$path = rtrim( Paths::normalize( (string) $dirs[ $group ] ), '/' );
			if ( '' === $path || ! is_dir( $path ) ) {
				$warnings[] = 'The directory of the "' . $group . '" group does not exist and was not backed up.';
				continue;
			}
			$chosen[ $group ] = $path;
		}
		// A group inside another chosen group is covered by the outer one (with the outer prefix), by real path,
		// but only where neither directory is a link: a group reached through a link stays a root of its own (merged
		// away, its files would come back under the other group's path and its own directory would be missing), and
		// nothing is merged into one (its target, not its place, would decide). The content directory is never
		// merged into a group.
		$plain = array();
		foreach ( $chosen as $group => $path ) {
			$plain[ $group ] = Links::PLAIN === Links::state( $path );
		}
		$kept = array();
		foreach ( $chosen as $group => $path ) {
			if ( 'other-content' !== $group && $plain[ $group ] ) {
				foreach ( $chosen as $other => $other_path ) {
					if ( $group !== $other && 'other-content' !== $other && $plain[ $other ] && Paths::is_prefix( Links::key( $other_path ), Links::key( $path ), false ) ) {
						$warnings[] = 'The "' . $group . '" directory lies inside the "' . $other . '" directory and is backed up as part of it.';
						continue 2;
					}
				}
			}
			$kept[ $group ] = self::prefix( $group, $path, $dirs['abspath'], $dirs['content'] );
		}
		foreach ( $kept as $group => $prefix ) {
			$path = $chosen[ $group ];
			$skip = array();
			$hold = array();
			if ( 'other-content' === $group ) {
				foreach ( self::GROUPS as $inner ) {
					if ( 'other-content' !== $inner && isset( $dirs[ $inner ] ) ) {
						$skip[] = rtrim( Paths::normalize( (string) $dirs[ $inner ] ), '/' );
					}
				}
				// Where another root's archive path lies in the content directory while that group is elsewhere
				// (a group outside both ABSPATH and the content directory is shown as "wp-content/<group>"), the
				// directory of that name here would give the same archive paths: it is not the site's (WordPress
				// uses the other one) and is left out, with a warning when it exists.
				foreach ( $kept as $other => $other_prefix ) {
					if ( 'other-content' === $other || 0 !== strpos( $other_prefix, $prefix . '/' ) ) {
						continue;
					}
					$there = $path . '/' . substr( $other_prefix, strlen( $prefix ) + 1 );
					if ( Links::key( $there ) !== Links::key( $chosen[ $other ] ) ) {
						$skip[] = $there;
						if ( file_exists( $there ) ) {
							$warnings[] = 'The directory ' . $other_prefix . ' in the content directory was not backed up: the "' . $other . '" group, backed up under that path, is in another place on this site.';
						}
					}
				}
			} else {
				// A group that is a link must not lead to a group directory (or the content directory) that is not a
				// link, or to a directory holding one: it would back those up a second time under its own path.
				foreach ( $chosen as $other => $other_path ) {
					if ( $group !== $other && 'other-content' !== $other && $plain[ $other ] ) {
						$hold[] = $other_path;
					}
				}
				$hold[] = rtrim( Paths::normalize( (string) $dirs['content'] ), '/' );
			}
			if ( '' !== $storage_dir ) {
				$skip[] = rtrim( Paths::normalize( $storage_dir ), '/' );
			}
			$roots[] = array(
				'group'  => $group,
				'path'   => $path,
				'prefix' => $prefix,
				'skip'   => $skip,
				'hold'   => $hold,
			);
		}
		// A root that is a link the scan follows is backed up under its own prefix: where it leads is skipped by
		// every other root (compared by real path, 'also_skip'), so a target inside another group is not backed up
		// twice. A root the scan refuses adds nothing: its files stay with the group they are in. Kept apart from
		// 'skip', which is what a root must not lead into (Links::root_verdict()): two links, one leading inside the
		// other's target, are each scanned and each skip the other's target. Two links to one directory are both
		// scanned in full, each under its own path (a root never skips itself).
		$targets = array();
		foreach ( $roots as $i => $root ) {
			$verdict = Links::root_verdict( $root['path'], (string) $dirs['abspath'], $root['skip'], null, $root['hold'] );
			if ( $verdict['link'] && '' === $verdict['refusal'] ) {
				$targets[ $i ] = rtrim( Paths::normalize( (string) realpath( $root['path'] ) ), '/' );
			}
		}
		foreach ( array_keys( $roots ) as $i ) {
			$roots[ $i ]['also_skip'] = array_values( array_diff_key( $targets, array( $i => true ) ) );
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
