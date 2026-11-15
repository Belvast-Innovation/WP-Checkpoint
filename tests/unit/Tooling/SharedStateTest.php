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

	/**
	 * @return array{0: string[], 1: array<string, string>, 2: string[], 3: string[]}
	 */
	private static function owned(): array {
		return array(
			\WPCheckpoint\Database\OwnTables::names( '' ),
			\WPCheckpoint\Database\OwnTables::SOURCES,
			\WPCheckpoint\Support\StoredNames::EXACT,
			array_keys( \WPCheckpoint\Support\StoredNames::BUILT ),
		);
	}

	public function test_every_table_and_stored_name_of_the_plugin_is_in_the_inventory_with_its_baseline(): void {
		list( $tables, $sources, $exact, $built ) = self::owned();
		$this->assertSame( array(), SharedState::inventory_problems( $tables, $sources, $exact, $built, SharedState::INVENTORY, SharedState::ITEMS ) );
		$this->assertGreaterThanOrEqual( 3, count( $tables ), 'the control: the plugin\'s tables were read' );
		$this->assertGreaterThanOrEqual( 19, count( $exact ), 'the control: its stored names were read' );
		$this->assertSame( 'the cron option', SharedState::INVENTORY['option:cron']['item'] );
		$this->assertSame( 'the swap plan', SharedState::INVENTORY['table:wpcheckpoint_swap_plan']['item'] );
	}

	public function test_a_table_or_name_added_to_the_plugin_and_not_in_the_inventory_is_said(): void {
		list( $tables, $sources, $exact, $built ) = self::owned();
		$inventory = SharedState::INVENTORY;
		$items     = SharedState::ITEMS;
		$problems  = static function ( array $tables, array $sources, array $exact, array $built, ?array $inv = null, ?array $its = null ) use ( $inventory, $items ): array {
			return SharedState::inventory_problems( $tables, $sources, $exact, $built, null === $inv ? $inventory : $inv, null === $its ? $items : $its );
		};
		$this->assertSame( array( 'table:wpcheckpoint_new: not in the inventory' ), $problems( array_merge( $tables, array( 'wpcheckpoint_new' ) ), $sources + array( 'src/New.php' => 'a new table (names())' ), $exact, $built ) );
		$this->assertSame( array( '4 table(s) kept by name and 3 source(s) said to create one', 'table:wpcheckpoint_new: not in the inventory' ), $problems( array_merge( $tables, array( 'wpcheckpoint_new' ) ), $sources, $exact, $built ) );
		$this->assertSame( array( 'tables:src/New.php: not in the inventory' ), $problems( $tables, $sources + array( 'src/New.php' => 'tables of a new kind (generated())' ), $exact, $built ) );
		$this->assertSame( array( 'name:wpcheckpoint_new: not in the inventory' ), $problems( $tables, $sources, array_merge( $exact, array( 'wpcheckpoint_new' ) ), $built ) );
		$this->assertSame( array( 'built:wpcheckpoint_new_: not in the inventory' ), $problems( $tables, $sources, $exact, array_merge( $built, array( 'wpcheckpoint_new_' ) ) ) );
		$this->assertSame( array( 'name:' . $exact[0] . ': in the inventory, and no longer the plugin\'s' ), $problems( $tables, $sources, array_slice( $exact, 1 ), $built ) );
		$this->assertSame( array( 'table:' . $tables[0] . ': in the inventory, and no longer the plugin\'s' ), $problems( array_slice( $tables, 1 ), array_slice( $sources, 1, null, true ), $exact, $built ) );

		$key = 'name:' . $exact[1];
		$this->assertSame( array( $key . ': no baseline' ), $problems( $tables, $sources, $exact, $built, array( $key => array( 'why' => 'x' ) ) + $inventory ) );
		$this->assertSame( array( $key . ': an item of the register, or why none (one of the two)' ), $problems( $tables, $sources, $exact, $built, array( $key => array( 'baseline' => 'absent' ) ) + $inventory ) );
		$this->assertSame( array( $key . ': an item of the register, or why none (one of the two)' ), $problems( $tables, $sources, $exact, $built, array( $key => array( 'baseline' => 'absent', 'item' => 'the swap plan', 'why' => 'x' ) ) + $inventory ) );
		$this->assertSame( array( $key . ': its item "an item gone" is not in the register' ), $problems( $tables, $sources, $exact, $built, array( $key => array( 'baseline' => 'absent', 'item' => 'an item gone' ) ) + $inventory ) );
		$this->assertSame( array( 'the register\'s item "an item more": of nothing in the inventory' ), $problems( $tables, $sources, $exact, $built, null, $items + array( 'an item more' => $items['the uninstall fence'] ) ) );
	}
}
