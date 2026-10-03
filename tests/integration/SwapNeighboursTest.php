<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\RestorePreflightStep;
use WPCheckpoint\Tests\Fixtures\Restore\SwapTestCase;

/**
 * A backup made before neighbours were left out of backups holds another installation's tables; restored to the end
 * (the swap made), the neighbour's live tables are exactly as they were, row for row, not the backup's copies.
 */
final class SwapNeighboursTest extends SwapTestCase {

	const MARKERS = array( 'posts', 'postmeta', 'options', 'comments', 'terms', 'term_taxonomy', 'term_relationships' );

	public function test_an_old_rule_backup_holding_a_neighbours_tables_is_swapped_in_without_touching_them(): void {
		global $wpdb;
		$n      = $wpdb->base_prefix . 'old_';
		$theirs = array();
		foreach ( self::MARKERS as $name ) {
			$this->create( $n . $name, 'LIKE `' . $wpdb->base_prefix . $name . '`' );
			$theirs[] = $n . $name;
		}
		$wpdb->insert(
			$n . 'options',
			array(
				'option_name'  => 'neighbour_marker',
				'option_value' => 'as the backup has it',
				'autoload'     => 'no',
			)
		);
		$this->assertSame( '', $wpdb->last_error );
		$wpdb->query( 'COMMIT' );
		// The restore leaves out what the swap tests leave out, but not the neighbour's tables: the neighbour rule must.
		$exclude = array_values( array_diff( self::live_tables(), array_merge( array( $wpdb->prefix . 'swt_keep', $wpdb->prefix . 'swt_gone' ), self::site_tables(), $theirs ) ) );
		$job     = $this->at_swap(
			array( 'exclude_tables' => $exclude ),
			static function () use ( $wpdb, $n ): void {
				// The neighbour writes after the backup was made: its live rows are no longer the backup's.
				$wpdb->update( $n . 'options', array( 'option_value' => 'written after the backup' ), array( 'option_name' => 'neighbour_marker' ) );
				$wpdb->insert(
					$n . 'options',
					array(
						'option_name'  => 'neighbour_after',
						'option_value' => 'new since the backup',
						'autoload'     => 'no',
					)
				);
				$wpdb->query( 'COMMIT' );
			}
		);
		$plan = RestorePreflightStep::load_plan( $this->work( $job ) )['plan'];
		foreach ( $theirs as $table ) {
			$this->assertSame( 'neighbour', $plan->skipped()[ $table ] ?? null, $table . ': left out as the neighbour\'s' );
		}
		$before = array();
		foreach ( $theirs as $table ) {
			$before[ $table ] = $this->rows_of( $table );
		}
		$this->assertContains(
			array(
				'option_name'  => 'neighbour_marker',
				'option_value' => 'written after the backup',
			),
			array_map(
				static function ( array $row ): array {
					return array_intersect_key( $row, array_flip( array( 'option_name', 'option_value' ) ) );
				},
				$before[ $n . 'options' ]
			),
			'the control: the live rows differ from the backup\'s'
		);
		$site = $this->site();
		$done = $this->cli_run( $job );
		$this->assertSame( Job::COMPLETED, $done->status, $done->last_error );
		$this->assertRestored( $site ); // The control: the swap was made.
		foreach ( $theirs as $table ) {
			$this->assertSame( $before[ $table ], $this->rows_of( $table ), $table . ': row for row as it was' );
		}
	}
}
