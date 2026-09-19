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
	 * @return array{roots: array<int, array{group: string, path: string, prefix: string, skip: string[]}>, warnings: string[]}
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
		// A group inside another chosen group is covered by the outer one (with the outer prefix).
		foreach ( $chosen as $group => $path ) {
			foreach ( $chosen as $other => $other_path ) {
				if ( $group !== $other && 'other-content' !== $other && Paths::is_inside( $other_path, $path ) ) {
					$warnings[] = 'The "' . $group . '" directory lies inside the "' . $other . '" directory and is backed up as part of it.';
					continue 2;
				}
			}
			$skip = array();
			if ( 'other-content' === $group ) {
				foreach ( self::GROUPS as $inner ) {
					if ( 'other-content' !== $inner && isset( $dirs[ $inner ] ) ) {
						$skip[] = rtrim( Paths::normalize( (string) $dirs[ $inner ] ), '/' );
					}
				}
			}
			if ( '' !== $storage_dir ) {
				$skip[] = rtrim( Paths::normalize( $storage_dir ), '/' );
			}
			$roots[] = array(
				'group'  => $group,
				'path'   => $path,
				'prefix' => self::prefix( $group, $path, $dirs['abspath'], $dirs['content'] ),
				'skip'   => $skip,
			);
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
