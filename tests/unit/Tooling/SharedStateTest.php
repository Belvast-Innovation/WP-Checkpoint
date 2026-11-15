<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use WPCheckpoint\Tests\Fixtures\SharedState;

/**
 * The register of state the integration tests share (tests/Fixtures/SharedState.php): an item about something that is
 * no longer there is stale, and the listener fails the run for it. Its reading and putting back run against the test
 * site (LeftoversCheckTest).
 */
final class SharedStateTest extends TestCase {

	public function test_no_item_of_the_register_is_stale_and_the_fence_is_its_first(): void {
		$this->assertSame( array(), SharedState::stale() );
		$this->assertSame( 'the uninstall fence', array_keys( SharedState::ITEMS )[0] );
		$this->assertSame( 'WPCheckpoint\\Jobs\\UninstallFence::TABLE', SharedState::ITEMS['the uninstall fence']['subject'] );
	}

	/**
	 * @return array<string, array{0: array<string, string>, 1: string[]}>
	 */
	public function stale(): array {
		$good = SharedState::ITEMS['the uninstall fence'];
		return array(
			'a constant that is gone'           => array( array( 'subject' => 'WPCheckpoint\\Jobs\\UninstallFence::GONE' ) + $good, array( 'an item: its subject WPCheckpoint\\Jobs\\UninstallFence::GONE is not there' ) ),
			'a class that is gone'              => array( array( 'subject' => 'WPCheckpoint\\Jobs\\GoneFence::TABLE' ) + $good, array( 'an item: its subject WPCheckpoint\\Jobs\\GoneFence::TABLE is not there' ) ),
			'no subject'                        => array( array_diff_key( $good, array( 'subject' => 1 ) ), array( 'an item: its subject (none) is not there' ) ),
			'a method that reads, gone'         => array( array( 'reads' => 'gone' ) + $good, array( 'an item: its "reads" method gone is not there' ) ),
			'a method that restores, gone'      => array( array( 'restores' => 'gone' ) + $good, array( 'an item: its "restores" method gone is not there' ) ),
			'no methods'                        => array( array( 'subject' => $good['subject'] ), array( 'an item: its "reads" method (none) is not there', 'an item: its "restores" method (none) is not there' ) ),
		);
	}

	/**
	 * @dataProvider stale
	 *
	 * @param array<string, string> $item   The item.
	 * @param string[]              $expect What is said.
	 */
	public function test_a_stale_item_is_said_with_why( array $item, array $expect ): void {
		$this->assertSame( array(), SharedState::stale( array( 'an item' => SharedState::ITEMS['the uninstall fence'] ) ), 'the control: the item it was made from is not stale' );
		$this->assertSame( $expect, SharedState::stale( array( 'an item' => $item ) ) );
	}
}
