<?php
/**
 * Content groups whose directory is a link to a directory outside this site: what the restore asks about them.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. The restore replaces a group's directory where it is (a group reached through a link: its target), so a
 * link to a directory of another installation (a staging site whose uploads link to the production site's) would
 * have the restore change that installation's files. A group whose directory is a link (or cannot be told not to
 * be one) and whose target is neither in this site's WordPress directory nor in the trusted deployment root is asked
 * about in the files preflight, with no default: swap it as usual, or leave it out of the restore (its live
 * directory stays as it is). The answer holds for the very groups and targets its question named (id()). A restore
 * nobody attends says it up front (the policy LINKED_TARGETS, RestoreJob).
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
	 * Whether a target is outside this site: neither in its WordPress directory nor in the trusted deployment root.
	 * A WordPress directory that cannot be resolved is no evidence of being inside: outside.
	 *
	 * @param string $target       The target, resolved.
	 * @param string $abspath_real This site's WordPress directory, resolved ('' when it could not be).
	 * @param string $trusted      The trusted deployment root ('' when none).
	 * @return bool
	 */
	public static function outside( string $target, string $abspath_real, string $trusted ): bool {
		foreach ( array( $abspath_real, $trusted ) as $root ) {
			if ( '' !== $root && Paths::is_same_or_inside( $root, $target ) ) {
				return false;
			}
		}
		return true;
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
