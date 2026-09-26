<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\TransientFailure;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\LedgerOutdated;
use WPCheckpoint\Restore\Queries;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The ledger's check of its own columns, against a connection that answers
 * SHOW COLUMNS with what the test gives: every column (taken), one missing
 * (LedgerOutdated), and no answer at all (TransientFailure: no evidence
 * that anything is missing).
 */
final class LedgerColumnsTest extends TestCase {

	/**
	 * A connection whose SHOW COLUMNS answers $columns; it records what it is sent.
	 *
	 * @param string[] $columns Column names.
	 */
	private static function session( array $columns, array &$sent ): Queries {
		return new class( $columns, $sent ) implements Queries {
			/** @var string[] */
			private $columns;
			/** @var string[] */
			private $sent;

			public function __construct( array $columns, array &$sent ) {
				$this->columns = $columns;
				$this->sent    = &$sent;
			}

			public function run( string $sql ): int {
				$this->sent[] = $sql;
				return 0;
			}

			public function rows( string $sql, array $params = array() ): array {
				$this->sent[] = $sql;
				if ( 0 !== strpos( $sql, 'SHOW COLUMNS FROM ' ) ) {
					return array();
				}
				return array_map(
					static function ( string $name ): array {
						return array( $name, 'int', 'NO', '', null, '' );
					},
					$this->columns
				);
			}

			public function write( string $sql, array $params ): int {
				$this->sent[] = $sql;
				return 0;
			}
		};
	}

	public function test_a_ledger_with_every_column_is_taken(): void {
		$sent = array();
		new Ledger( self::session( array_keys( Ledger::COLUMNS ), $sent ), 'wcptmpabcdef_1_0000_', 'aaaa' );
		$this->assertStringStartsWith( 'CREATE TABLE IF NOT EXISTS `wcptmpabcdef_1_0000_` (', $sent[0] );
		$this->assertSame( 'SHOW COLUMNS FROM `wcptmpabcdef_1_0000_`', $sent[1], 'the control: the columns are read' );
	}

	public function test_a_ledger_without_a_column_of_this_version_is_outdated(): void {
		$sent = array();
		$this->expectException( LedgerOutdated::class );
		$this->expectExceptionMessage( '(restarting)' );
		new Ledger( self::session( array_values( array_diff( array_keys( Ledger::COLUMNS ), array( 'restarting' ) ) ), $sent ), 'wcptmpabcdef_1_0000_', 'aaaa' );
	}

	public function test_no_column_list_is_no_evidence_and_is_tried_again(): void {
		$sent = array();
		try {
			new Ledger( self::session( array(), $sent ), 'wcptmpabcdef_1_0000_', 'aaaa' );
			$this->fail( 'a ledger whose columns could not be read was taken' );
		} catch ( LedgerOutdated $e ) {
			$this->fail( 'no answer was taken for a missing column' );
		} catch ( TransientFailure $e ) {
			$this->assertStringContainsString( 'could not be read', $e->getMessage() );
		}
		$this->assertContains( 'SHOW COLUMNS FROM `wcptmpabcdef_1_0000_`', $sent, 'the control: they were asked for' );
	}
}
