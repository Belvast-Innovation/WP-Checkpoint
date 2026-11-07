<?php
/**
 * Which swaps a run made without WordPress takes on, and how.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\SiteCheck;

defined( 'ABSPATH' ) || exit;

/**
 * The table of what a run made without WordPress (a visitor's request, once no WP-CLI run went on with the swap for
 * the grace period) may do with a restore's swap, read from its cursor alone: only putting the site back as it was,
 * from a swap that was going forward and was not let out; everything else is left to WP-CLI. Pure: no WordPress, no
 * database, no file; the caller gives the restore's policy for a site that could not be checked.
 *
 * - A redo's cursor (it sets out from an undo: "undone_at") or one of a site let out ("out"): left.
 * - enter, dirs, carry: roll back, as interrupted.
 * - rename: roll back; as interrupted, or as abandoned when every rename went through (the run decides, from what
 *   is there: such a swap is not committed until it is recorded).
 * - committed, post cache or verify (no verdict yet): reverse, as abandoned.
 * - committed, post forget: a failed verdict reverses (reversed); one that could not tell reverses when the policy
 *   says undo (undone); a passed one, or one that could not tell with keep or ask, is left.
 * - rollback with interrupted, incomplete, stopped, busy or abandoned; reverse with reversed, abandoned or undone:
 *   go on putting back.
 * - restored or reverted with any of those reasons: only the end.
 * - anything else (start, judge, recount, ready, rejudged, done, carry_ask, undo, undone_ask, redo, redo_ask,
 *   redo_stopped, committed past forget, another reason): left.
 */
final class RollbackEligibility {

	const LEAVE     = 'leave';
	const ROLL_BACK = 'roll_back';
	const REVERSE   = 'reverse';
	const GO_ON     = 'go_on';
	const FINISH    = 'finish';

	/**
	 * Reasons of a rollback a run without WordPress goes on with.
	 */
	const ROLLBACK_REASONS = array( SwapStep::INTERRUPTED, SwapStep::INCOMPLETE, SwapStep::STOPPED, SwapStep::BUSY, SwapStep::ABANDONED );

	/**
	 * Reasons of a reverse a run without WordPress goes on with (undone only without "out", as everything here).
	 */
	const REVERSE_REASONS = array( SwapStep::REVERSED, SwapStep::ABANDONED, SwapStep::UNDONE );

	/**
	 * What to do with a swap, and the reason to record when it is turned back here ('' for a rollback from rename,
	 * whose reason the run reads from what is there; '' when nothing is recorded).
	 *
	 * @param array<string, mixed> $cursor     The swap's cursor, as stored.
	 * @param string               $unverified The restore's policy for a site that could not be checked (RestoreJob::UNDO, KEEP, or 'ask').
	 * @return array{0: string, 1: string}
	 */
	public static function judge( array $cursor, string $unverified ): array {
		if ( (int) ( $cursor['undone_at'] ?? 0 ) > 0 || ! empty( $cursor['out'] ) ) {
			return array( self::LEAVE, '' );
		}
		$phase  = is_string( $cursor['phase'] ?? null ) ? $cursor['phase'] : '';
		$reason = is_string( $cursor['reason'] ?? null ) ? $cursor['reason'] : '';
		switch ( $phase ) {
			case 'enter':
			case 'dirs':
			case 'carry':
				return array( self::ROLL_BACK, SwapStep::INTERRUPTED );
			case 'rename':
				return array( self::ROLL_BACK, '' );
			case 'committed':
				$post = is_string( $cursor['post'] ?? null ) ? $cursor['post'] : '';
				if ( 'cache' === $post || 'verify' === $post ) {
					return array( self::REVERSE, SwapStep::ABANDONED );
				}
				if ( 'forget' === $post ) {
					$verdict = is_string( $cursor['verdict'] ?? null ) ? $cursor['verdict'] : '';
					if ( SiteCheck::FAILED === $verdict ) {
						return array( self::REVERSE, SwapStep::REVERSED );
					}
					if ( SiteCheck::UNKNOWN === $verdict && RestoreJob::UNDO === $unverified ) {
						return array( self::REVERSE, SwapStep::UNDONE );
					}
				}
				return array( self::LEAVE, '' );
			case 'rollback':
				return in_array( $reason, self::ROLLBACK_REASONS, true ) ? array( self::GO_ON, '' ) : array( self::LEAVE, '' );
			case 'reverse':
				return in_array( $reason, self::REVERSE_REASONS, true ) ? array( self::GO_ON, '' ) : array( self::LEAVE, '' );
			case 'restored':
			case 'reverted':
				return in_array( $reason, array_merge( self::ROLLBACK_REASONS, self::REVERSE_REASONS ), true ) ? array( self::FINISH, '' ) : array( self::LEAVE, '' );
		}
		return array( self::LEAVE, '' );
	}
}
