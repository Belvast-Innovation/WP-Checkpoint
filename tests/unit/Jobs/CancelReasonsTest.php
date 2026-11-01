<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\JobActions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every reason JobActions::cancel() can give is either one that refuses (JobActions::CANCEL_REFUSED: REST answers a
 * conflict, WP-CLI an error) or one of the reasons that cancelled or requested something: a refusing reason left out
 * of the list would be answered by REST as "cancelled". Read from the source of cancel() and of undo_cancel_state(),
 * which picks the reason of a cancel of an undo.
 */
final class CancelReasonsTest extends TestCase {

	/** The reasons of a cancel that did something: cancelled, cleaned up, or requested. */
	const ACCEPTED = array( 'cleaned', 'holder', 'unavailable', 'requested', 'undo_given_up', 'undo_requested', 'redo_given_up', 'redo_requested' );

	/**
	 * The reasons written in a source: as the value of "reason" in cancel(), assigned to $reason, or returned (or
	 * assigned to $undoing) in undo_cancel_state(). Line endings are made "\n" first: a checkout on Windows has "\r\n",
	 * and a function's end would not be found.
	 *
	 * @param string $source The source of JobActions.
	 * @return string[]
	 */
	public static function reasons( string $source ): array {
		$source = str_replace( "\r\n", "\n", $source );
		$found  = array();
		$this_body = static function ( string $head ) use ( $source ): string {
			$start = strpos( $source, $head );
			if ( false === $start ) {
				return '';
			}
			$end = strpos( $source, "\n\t}\n", $start );
			return substr( $source, $start, false === $end ? null : $end - $start );
		};
		$cancel = $this_body( 'public function cancel( int $id )' );
		preg_match_all( "/'reason'\\s*=>\\s*([^\\n]+)/", $cancel, $values );
		preg_match_all( "/\\\$reason\\s*=\\s*([^\\n]+)/", $cancel, $assigned );
		$state = $this_body( 'private function undo_cancel_state( Job $job )' );
		preg_match_all( "/^\\s*(?:return|\\\$undoing\\s*=)\\s*([^\\n]+)/m", $state, $returned );
		foreach ( array_merge( $values[1], $assigned[1], $returned[1] ) as $expression ) {
			preg_match_all( "/'([a-z_]+)'/", $expression, $literals );
			$found = array_merge( $found, $literals[1] );
		}
		$found = array_values( array_unique( $found ) );
		sort( $found );
		return $found;
	}

	public function test_every_reason_a_cancel_gives_refuses_or_did_something(): void {
		$reasons = self::reasons( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Jobs/JobActions.php' ) );
		// The controls: the scan finds reasons of every kind and from every place.
		foreach ( array( 'abandoned', 'cleaned', 'holder', 'undo_given_up', 'undo_failed', 'undo_expired', 'managed_elsewhere', 'undo_ended' ) as $known ) {
			$this->assertContains( $known, $reasons, 'the scan finds ' . $known );
		}
		foreach ( $reasons as $reason ) {
			$this->assertTrue( in_array( $reason, JobActions::CANCEL_REFUSED, true ) xor in_array( $reason, self::ACCEPTED, true ), $reason . ': in exactly one of the refusing and the accepting reasons' );
		}
		foreach ( JobActions::CANCEL_REFUSED as $refused ) {
			$this->assertContains( $refused, $reasons, $refused . ' is a reason cancel() gives (not a stale entry)' );
		}
	}

	public function test_a_reason_in_neither_list_is_found(): void {
		$source = "\tpublic function cancel( int \$id ) {\n\t\treturn array( 'reason'  => 'brand_new' );\n\t}\n"
			. "\tprivate function undo_cancel_state( Job \$job ) {\n\t\treturn \$x ? 'other_new' : null;\n\t}\n"
			. "\tprivate function after() {\n\t\t\$reason = 'not_a_cancel';\n\t\treturn 'nor_this';\n\t}\n";
		$this->assertSame( array( 'brand_new', 'other_new' ), self::reasons( $source ) );
		// The same with Windows line endings: each function still ends where it does.
		$this->assertSame( array( 'brand_new', 'other_new' ), self::reasons( str_replace( "\n", "\r\n", $source ) ) );
	}
}
