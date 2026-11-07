<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\RollbackEligibility as E;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Restore\SiteCheck;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The table of what a run without WordPress takes on (RollbackEligibility::judge()), checked against the table as it
 * is written (ARCHITECTURE, "b 的资格表"), row by row, here written again on its own: every phase the swap writes, every
 * stage after the swap, every verdict, policy and reason, with and without "out" and a redo's key. Each row of the
 * table is hit (the control).
 */
final class RollbackEligibilityTest extends TestCase {

	const PHASES  = array( 'start', 'judge', 'recount', 'ready', 'enter', 'dirs', 'carry', 'rename', 'rejudged', 'committed', 'done', 'carry_ask', 'undo', 'undone_ask', 'redo', 'redo_ask', 'redo_stopped', 'rollback', 'reverse', 'restored', 'reverted', 'bogus', '' );
	const POSTS   = array( null, 'cache', 'verify', 'forget', 'release', 'rewrite', 'cron', 'ask', 'exit' );
	const VERDICT = array( null, SiteCheck::PASSED, SiteCheck::FAILED, SiteCheck::UNKNOWN );
	const POLICY  = array( RestoreJob::UNDO, RestoreJob::KEEP, 'ask' );

	/**
	 * The table, row by row: the first row that matches says what is done. Each row: a name, a test of the cursor and
	 * policy, the outcome.
	 *
	 * @return array<int, array{0: string, 1: callable, 2: array{0: string, 1: string}|callable}>
	 */
	private static function table(): array {
		$back    = array( SwapStep::INTERRUPTED, SwapStep::INCOMPLETE, SwapStep::STOPPED, SwapStep::BUSY, SwapStep::ABANDONED );
		$reverse = array( SwapStep::REVERSED, SwapStep::ABANDONED, SwapStep::UNDONE );
		return array(
			array( 'a redo or let out', static function ( array $c ): bool {
				return isset( $c['undone_at'] ) || isset( $c['out'] );
			}, array( E::LEAVE, '' ) ),
			array( 'enter, dirs, carry', static function ( array $c ): bool {
				return in_array( $c['phase'] ?? '', array( 'enter', 'dirs', 'carry' ), true );
			}, array( E::ROLL_BACK, SwapStep::INTERRUPTED ) ),
			array( 'rename', static function ( array $c ): bool {
				return 'rename' === ( $c['phase'] ?? '' );
			}, array( E::ROLL_BACK, '' ) ),
			array( 'committed, cache or verify', static function ( array $c ): bool {
				return 'committed' === ( $c['phase'] ?? '' ) && in_array( $c['post'] ?? '', array( 'cache', 'verify' ), true );
			}, array( E::REVERSE, SwapStep::ABANDONED ) ),
			array( 'committed, forget, failed', static function ( array $c ): bool {
				return 'committed' === ( $c['phase'] ?? '' ) && 'forget' === ( $c['post'] ?? '' ) && SiteCheck::FAILED === ( $c['verdict'] ?? '' );
			}, array( E::REVERSE, SwapStep::REVERSED ) ),
			array( 'committed, forget, unknown, undo', static function ( array $c, string $p ): bool {
				return 'committed' === ( $c['phase'] ?? '' ) && 'forget' === ( $c['post'] ?? '' ) && SiteCheck::UNKNOWN === ( $c['verdict'] ?? '' ) && RestoreJob::UNDO === $p;
			}, array( E::REVERSE, SwapStep::UNDONE ) ),
			array( 'rollback, a reason of a forward failure', static function ( array $c ) use ( $back ): bool {
				return 'rollback' === ( $c['phase'] ?? '' ) && in_array( $c['reason'] ?? '', $back, true );
			}, array( E::GO_ON, '' ) ),
			array( 'reverse, reversed, abandoned or undone', static function ( array $c ) use ( $reverse ): bool {
				return 'reverse' === ( $c['phase'] ?? '' ) && in_array( $c['reason'] ?? '', $reverse, true );
			}, array( E::GO_ON, '' ) ),
			array( 'restored or reverted, those reasons', static function ( array $c ) use ( $back, $reverse ): bool {
				return in_array( $c['phase'] ?? '', array( 'restored', 'reverted' ), true ) && in_array( $c['reason'] ?? '', array_merge( $back, $reverse ), true );
			}, array( E::FINISH, '' ) ),
			array( 'anything else', static function (): bool {
				return true;
			}, array( E::LEAVE, '' ) ),
		);
	}

	public function test_every_cursor_is_judged_as_the_table_says(): void {
		$reasons = array_merge( array( null, 'bogus' ), array_keys( SwapStep::REASONS ) );
		$hit     = array();
		$cases   = 0;
		foreach ( self::PHASES as $phase ) {
			foreach ( self::POSTS as $post ) {
				foreach ( self::VERDICT as $verdict ) {
					foreach ( $reasons as $reason ) {
						foreach ( array( false, true ) as $out ) {
							foreach ( array( false, true ) as $redo ) {
								foreach ( self::POLICY as $policy ) {
									$cursor = array( 'phase' => $phase );
									foreach ( array( 'post' => $post, 'verdict' => $verdict, 'reason' => $reason ) as $key => $value ) {
										if ( null !== $value ) {
											$cursor[ $key ] = $value;
										}
									}
									if ( $out ) {
										$cursor['out'] = true;
									}
									if ( $redo ) {
										$cursor['undone_at'] = 1800000000;
									}
									foreach ( self::table() as $row ) {
										if ( call_user_func( $row[1], $cursor, $policy ) ) {
											$hit[ $row[0] ] = ( $hit[ $row[0] ] ?? 0 ) + 1;
											$this->assertSame( $row[2], E::judge( $cursor, $policy ), $row[0] . ': ' . json_encode( $cursor ) . ' ' . $policy ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit test.
											break;
										}
									}
									++$cases;
								}
							}
						}
					}
				}
			}
		}
		$this->assertGreaterThan( 50000, $cases );
		$this->assertSame( array_column( self::table(), 0 ), array_keys( array_filter( array_merge( array_fill_keys( array_column( self::table(), 0 ), 0 ), $hit ) ) ), 'the control: every row of the table is hit' );
	}

	public function test_a_cursor_that_is_not_what_the_swap_writes_is_left(): void {
		foreach ( array(
			array(),
			array( 'phase' => array( 'rollback' ) ),
			array( 'phase' => 'rollback', 'reason' => array( SwapStep::INTERRUPTED ) ),
			array( 'phase' => 'committed', 'post' => 7 ),
			array( 'phase' => 'enter', 'undone_at' => '5' ),
			array( 'phase' => 'enter', 'out' => 1 ),
		) as $i => $cursor ) {
			$this->assertSame( E::LEAVE, E::judge( $cursor, RestoreJob::UNDO )[0], 'case ' . $i );
		}
	}
}
