<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Restore\PluginList;
use WPCheckpoint\Tests\Fixtures\MemoryBudget;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The restore's limits are reachable with the largest legal input, within
 * the step budget, and one past them is refused.
 */
final class RestoreLimitsTest extends TestCase {

	public function test_the_most_tables_left_out_are_accepted_and_one_more_is_not(): void {
		$names = array();
		for ( $i = 0; $i < RestoreJob::MAX_EXCLUDED; $i++ ) {
			$names[] = sprintf( 'wp_%061d', $i ); // 64 bytes each, the longest table name.
		}
		$options = MemoryBudget::within(
			32 * 1048576,
			static function () use ( $names ): array {
				return RestoreJob::options(
					array(
						'base'           => 'example-20260918-100000-a1b2',
						'exclude_tables' => $names,
					)
				);
			}
		);
		$this->assertCount( RestoreJob::MAX_EXCLUDED, $options['exclude_tables'] );
		$names[] = 'wp_one_more';
		$this->expectException( \InvalidArgumentException::class );
		RestoreJob::options(
			array(
				'base'           => 'example-20260918-100000-a1b2',
				'exclude_tables' => $names,
			)
		);
	}

	public function test_the_longest_list_of_active_plugins_is_read_within_the_step_budget(): void {
		$list = array();
		for ( $i = 0; $i < PluginList::MAX_ENTRIES; $i++ ) {
			$list[] = sprintf( 'plugin-%06d/plugin-%06d.php', $i, $i );
		}
		$stored = PluginList::write( $list );
		unset( $list );
		$read = MemoryBudget::within(
			32 * 1048576,
			static function () use ( $stored ) {
				return PluginList::read( $stored );
			}
		);
		$this->assertCount( PluginList::MAX_ENTRIES, (array) $read );
		$this->assertNull( PluginList::read( 'a:' . ( PluginList::MAX_ENTRIES + 1 ) . ':{' ), 'one more is not read' );
	}
}
