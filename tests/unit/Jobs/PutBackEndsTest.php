<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\SwapStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * How a put-back ends (SwapStep::put_back_cancels()): as its claim recorded it in the cursor ("ends"), whatever else
 * the cursor holds; in a cursor a claim wrote before that was recorded, by the rule the claim then went by.
 */
final class PutBackEndsTest extends TestCase {

	public function test_the_claim_records_how_each_put_back_ends(): void {
		$this->assertSame( SwapStep::PUT_BACK_ENDS_CANCELLED, SwapStep::put_back_cursor( false, 1, 'm', 'e', 's' )['ends'], 'a swap half made' );
		$this->assertSame( SwapStep::PUT_BACK_ENDS_CANCELLED, SwapStep::put_back_cursor( false, 1, 'm', 'e', 's', 0, true )['ends'], 'its position unreadable' );
		$this->assertSame( SwapStep::PUT_BACK_ENDS_UNDONE, SwapStep::put_back_cursor( true, 1, 'm', 'e', 's' )['ends'], 'a committed swap' );
		$this->assertSame( SwapStep::PUT_BACK_ENDS_UNDONE, SwapStep::put_back_cursor( false, 1, 'm', 'e', 's', 1700000000 )['ends'], 'a redo half made' );
		$this->assertSame( SwapStep::PUT_BACK_ENDS_UNDONE, SwapStep::put_back_cursor( false, 1, 'm', 'e', 's', 0, false, true )['ends'], 'an undo half made' );
	}

	public function test_the_recorded_end_decides_whatever_else_the_cursor_holds(): void {
		$back = array( 'reason' => SwapStep::PUT_BACK_REASON );
		// The control: without "ends", the rule the claim went by.
		$this->assertTrue( SwapStep::put_back_cancels( $back ) );
		$this->assertFalse( SwapStep::put_back_cancels( $back + array( 'out' => true ) ) );
		$this->assertFalse( SwapStep::put_back_cancels( $back + array( 'undone_at' => 1700000000 ) ) );
		// Recorded: it alone decides.
		$this->assertFalse( SwapStep::put_back_cancels( $back + array( 'ends' => SwapStep::PUT_BACK_ENDS_UNDONE ) ) );
		$this->assertTrue( SwapStep::put_back_cancels( $back + array( 'ends' => SwapStep::PUT_BACK_ENDS_CANCELLED, 'out' => true ) ) );
		$this->assertTrue( SwapStep::put_back_cancels( $back + array( 'ends' => SwapStep::PUT_BACK_ENDS_CANCELLED, 'undone_at' => 1700000000 ) ) );
		// A value no claim writes is not read as one: the rule decides.
		$this->assertTrue( SwapStep::put_back_cancels( $back + array( 'ends' => 'later' ) ) );
		// Not a put-back: never.
		$this->assertFalse( SwapStep::put_back_cancels( array( 'ends' => SwapStep::PUT_BACK_ENDS_CANCELLED ) ) );
	}
}
