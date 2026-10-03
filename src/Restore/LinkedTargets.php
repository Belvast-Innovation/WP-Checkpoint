<?php
/**
 * Content groups whose directory is a link to a directory outside this site: what the restore asks about them.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Files\Links;
use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * The restore replaces a group's directory where it is (a group reached through a link: where the link leads), so a
 * link to a directory of another installation (a staging site whose uploads link to the production site's, or whose
 * whole content directory does) would have the restore change that installation's files. Whether a group is asked
 * about is one rule over the path WordPress names its directory by, walked a component at a time
 * (reached_from_outside()); the files preflight asks, with no default: swap it as usual, or leave it out of the
 * restore (its live directory stays as it is). The answer holds for the very groups and targets its question named
 * (id()). A restore nobody attends says it up front (the policy POLICY_KEY, RestoreJob).
 */
final class LinkedTargets {

	/**
	 * The restore policy and the prefix of the question's id.
	 */
	const POLICY_KEY = 'linked_targets';

	/**
	 * The question's kind.
	 */
	const KIND = 'link_targets_outside_site';

	/**
	 * The answers.
	 */
	const SWAP    = 'swap';
	const EXCLUDE = 'exclude';
	const CHOICES = array( self::SWAP, self::EXCLUDE );

	/**
	 * Home directories, as the masks of paths know them (Report::mask_paths()): the directory under one of these is a
	 * user's.
	 */
	const HOMES = '#\A((?:[A-Za-z]:)?/(?:home|users|var/www/vhosts|srv/users|usr/home|export/home)/[^/]+)(?:/|\z)#i';

	/**
	 * Whether a resolved path is outside this site: neither in its WordPress directory nor in the trusted deployment
	 * root, both resolved; compared as resolved strings (a path that does not exist is judged by where it would be).
	 * A WordPress directory that could not be resolved is no evidence of being inside: outside.
	 *
	 * @param string $target       The path, resolved.
	 * @param string $abspath_real This site's WordPress directory, resolved ('' when it could not be).
	 * @param string $trusted_real The trusted deployment root, resolved ('' when none).
	 * @return bool
	 */
	public static function outside( string $target, string $abspath_real, string $trusted_real ): bool {
		foreach ( array( $abspath_real, $trusted_real ) as $root ) {
			if ( '' !== $root && self::within( $root, $target ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a group's directory, as WordPress names it, is reached through a link that leads outside this site. The
	 * path is walked a component at a time, as the system does: "." is passed over, ".." goes to the parent of where
	 * the walk is (after any link), a component that is not there ends what can be a link (the rest is a name). A
	 * component that is a link (or whose kind cannot be told and that resolves elsewhere) is harmless when it leads
	 * into this site's WordPress directory or the trusted deployment root, or, when components follow it, to a
	 * directory that holds the WordPress directory (a deployment's link: current -> releases/N). Any other link asks.
	 * A path that is not absolute, or a component whose existence or target cannot be told, asks too: nothing is
	 * concluded without positive evidence. A path with no link on it never asks, wherever it is (a directory outside
	 * the WordPress directory that WordPress names directly is this site's own).
	 *
	 * @param string $given        The directory as WordPress names it.
	 * @param string $abspath_real This site's WordPress directory, resolved ('' when it could not be).
	 * @param string $trusted_real The trusted deployment root, resolved ('' when none).
	 * @return bool
	 */
	public static function reached_from_outside( string $given, string $abspath_real, string $trusted_real ): bool {
		$path = Paths::normalize( $given );
		if ( 1 !== preg_match( '#\A(/|[A-Za-z]:/|//[^/]+/[^/]+/)(.*)\z#s', $path, $m ) ) {
			return true; // Not absolute: where it is cannot be told.
		}
		$cur   = rtrim( $m[1], '/' );
		$parts = array_values(
			array_filter(
				explode( '/', $m[2] ),
				static function ( string $part ): bool {
					return '' !== $part;
				}
			)
		);
		$gone  = ''; // Where the walk left what is there: below it, names only (until ".." climbs above it).
		$count = count( $parts );
		foreach ( $parts as $i => $part ) {
			if ( '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				$cur = '' === $cur || false === strpos( $cur, '/' ) ? $cur : (string) substr( $cur, 0, (int) strrpos( $cur, '/' ) );
				if ( '' !== $gone && ! self::within( $gone, $cur ) ) {
					$gone = ''; // Back where things are there.
				}
				continue;
			}
			$next = $cur . '/' . $part;
			if ( '' !== $gone ) {
				$cur = $next; // Below a component that is not there: names only.
				continue;
			}
			if ( false === @lstat( $next ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- told apart below.
				if ( ! Paths::positively_gone( $next ) ) {
					return true; // Whether it is there cannot be told.
				}
				$gone = $next;
				$cur  = $next;
				continue;
			}
			$real = Paths::real( $next );
			if ( false === $real ) {
				return true; // There, but where it leads cannot be told.
			}
			$real  = rtrim( Paths::normalize( (string) $real ), '/' );
			$state = Links::state( $next );
			if ( Links::LINK === $state || ( Links::UNKNOWN === $state && ! Paths::same( $real, $next, Paths::is_windows() ) ) ) {
				$last = true;
				for ( $j = $i + 1; $j < $count; $j++ ) {
					if ( '.' !== $parts[ $j ] ) {
						$last = false;
						break;
					}
				}
				$deployment = ! $last && '' !== $abspath_real && self::within( $real, $abspath_real );
				if ( ! $deployment && self::outside( $real, $abspath_real, $trusted_real ) ) {
					return true;
				}
			}
			$cur = $real;
		}
		return false;
	}

	/**
	 * Whether a resolved path is a directory or in it (strings compared; case folded on Windows).
	 *
	 * @param string $dir  Directory, resolved.
	 * @param string $path Path, resolved.
	 * @return bool
	 */
	private static function within( string $dir, string $path ): bool {
		return Paths::same( $dir, $path, Paths::is_windows() ) || Paths::is_prefix( $dir, $path, Paths::is_windows() );
	}

	/**
	 * The question's id: the policy key and a digest of the groups and their targets. An answer holds only for the
	 * question it was given to; another target (a link pointed elsewhere since) is another question.
	 *
	 * @param array<string, string> $targets Group => target, resolved.
	 * @return string
	 */
	public static function id( array $targets ): string {
		ksort( $targets, SORT_STRING );
		$canonical = array();
		foreach ( $targets as $group => $target ) {
			$canonical[] = bin2hex( (string) $group ) . ':' . bin2hex( (string) $target );
		}
		return self::POLICY_KEY . '_' . substr( hash( 'sha256', implode( ';', $canonical ) ), 0, 16 );
	}

	/**
	 * The home directory a path is in ('' when it is in none).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function home( string $path ): string {
		return 1 === preg_match( self::HOMES, Paths::normalize( $path ), $m ) ? strtolower( $m[1] ) : '';
	}

	/**
	 * Whether a target is in this site's home directory: "same", "other", or "unknown" (this site is in no home
	 * directory, so nothing can be said).
	 *
	 * @param string $target       Target.
	 * @param string $abspath_real This site's WordPress directory, resolved.
	 * @return string
	 */
	public static function relation( string $target, string $abspath_real ): string {
		$site = self::home( $abspath_real );
		if ( '' === $site ) {
			return 'unknown';
		}
		return self::home( $target ) === $site ? 'same' : 'other';
	}

	/**
	 * The question's lines: each group with its target as masked, whether it is in this site's home directory (said,
	 * not shown: the mask hides whose home it is), and a number where two targets read the same once masked.
	 *
	 * @param array<int, array{group: string, target: string, relation: string}> $entries Entries.
	 * @param callable                                                           $clean   Text cleaner (masks paths).
	 * @return string[]
	 */
	public static function lines( array $entries, callable $clean ): array {
		$shown = array();
		foreach ( $entries as $i => $entry ) {
			$shown[ $i ] = (string) call_user_func( $clean, (string) $entry['target'] );
		}
		$counts = array_count_values( $shown );
		$seen   = array();
		$out    = array();
		foreach ( $entries as $i => $entry ) {
			$line = sprintf( '%1$s: %2$s', (string) $entry['group'], $shown[ $i ] );
			if ( $counts[ $shown[ $i ] ] > 1 ) {
				$seen[ $shown[ $i ] ] = ( $seen[ $shown[ $i ] ] ?? 0 ) + 1;
				/* translators: %d: number of the target among those that read the same */
				$line .= ' ' . sprintf( __( '(target %d)', 'wp-checkpoint' ), $seen[ $shown[ $i ] ] );
			}
			if ( 'same' === $entry['relation'] ) {
				$line .= ' ' . __( '(in this site\'s home directory)', 'wp-checkpoint' );
			} elseif ( 'other' === $entry['relation'] ) {
				$line .= ' ' . __( '(not in this site\'s home directory)', 'wp-checkpoint' );
			}
			$out[] = $line;
		}
		return $out;
	}
}
