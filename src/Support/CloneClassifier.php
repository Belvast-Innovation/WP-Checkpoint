<?php
/**
 * Decides whether an ABSPATH change looks like a deployment or a clone.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Support;

/**
 * Pure classification of a storage owner mismatch.
 *
 * PHP resolves symlinks in __FILE__, so with release-directory deployments
 * (Capistrano, Deployer, Trellis: current -> releases/2026...) ABSPATH is
 * the real release path and changes on every deploy while the database and
 * the shared wp-content stay the same. That is the main case this class
 * recognises: the previous and the current ABSPATH are siblings under the
 * same parent directory.
 */
final class CloneClassifier {

	const DEPLOYMENT = 'deployment';
	const MOVED      = 'moved';
	const CLONE      = 'clone';

	const RECOMMEND_ORIGINAL = 'original';
	const RECOMMEND_NEW      = 'new';

	/**
	 * Classify a mismatch.
	 *
	 * @param string        $previous_abspath ABSPATH recorded when the directory was adopted.
	 * @param string        $current_abspath  ABSPATH of this request.
	 * @param callable|null $is_dir           Directory existence probe (defaults to is_dir()).
	 * @param callable|null $realpath         Path resolver (defaults to realpath()).
	 * @return array{verdict: string, recommendation: string, previous_exists: bool, previous_is_wordpress: bool, siblings: bool, deploy_root: string}
	 */
	public static function classify( string $previous_abspath, string $current_abspath, $is_dir = null, $realpath = null ): array {
		$is_dir   = is_callable( $is_dir ) ? $is_dir : 'is_dir';
		$realpath = is_callable( $realpath ) ? $realpath : 'realpath';

		$previous = rtrim( Paths::normalize( $previous_abspath ), '/' );
		$current  = rtrim( Paths::normalize( $current_abspath ), '/' );

		$previous_exists = '' !== $previous && (bool) call_user_func( $is_dir, $previous );
		$previous_is_wp  = $previous_exists && (bool) call_user_func( $is_dir, $previous . '/wp-includes' );

		$siblings    = false;
		$deploy_root = '';
		if ( '' !== $previous && '' !== $current && $previous !== $current ) {
			$previous_parent = self::parent( $previous, $realpath );
			$current_parent  = self::parent( $current, $realpath );
			if ( '' !== $current_parent && Paths::same( $previous_parent, $current_parent, Paths::is_windows() ) ) {
				$siblings    = true;
				$deploy_root = $current_parent;
			}
		}

		if ( $siblings ) {
			$verdict        = self::DEPLOYMENT;
			$recommendation = self::RECOMMEND_ORIGINAL;
		} elseif ( ! $previous_is_wp ) {
			$verdict        = self::MOVED;
			$recommendation = self::RECOMMEND_ORIGINAL;
		} else {
			$verdict        = self::CLONE;
			$recommendation = self::RECOMMEND_NEW;
		}

		return array(
			'verdict'               => $verdict,
			'recommendation'        => $recommendation,
			'previous_exists'       => $previous_exists,
			'previous_is_wordpress' => $previous_is_wp,
			'siblings'              => $siblings,
			'deploy_root'           => $deploy_root,
		);
	}

	/**
	 * Real parent directory of a path (the path itself may no longer exist).
	 *
	 * @param string   $path     Normalised path without trailing slash.
	 * @param callable $realpath Path resolver.
	 * @return string Normalised parent, empty when it cannot be resolved.
	 */
	public static function parent( string $path, $realpath = null ): string {
		$realpath = is_callable( $realpath ) ? $realpath : 'realpath';
		$parent   = dirname( $path );
		if ( '' === $parent || '.' === $parent || $parent === $path ) {
			return '';
		}
		$real = call_user_func( $realpath, $parent );
		return is_string( $real ) && '' !== $real ? rtrim( Paths::normalize( $real ), '/' ) : '';
	}
}
