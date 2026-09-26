<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Restore\Ledger;
use WPCheckpoint\Restore\LedgerOutdated;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Tests\Fixtures\Restore\RestoreTestCase;

/**
 * A restore's ledger is created once and never changed (CREATE TABLE IF
 * NOT EXISTS leaves an existing table as it is): one created by an older
 * version, without a column this version reads, stops the import with the
 * reason instead of a database error.
 */
final class LedgerColumnsTest extends RestoreTestCase {

	/** @var ImportSession|null */
	private $db;

	public function tear_down(): void {
		if ( null !== $this->db ) {
			$this->db->close();
		}
		parent::tear_down();
	}

	public function test_a_ledger_of_an_older_version_is_refused_with_the_reason(): void {
		global $wpdb;
		$this->db = ImportSession::open( Credentials::from_wordpress() );
		$wpdb->query( 'COMMIT' );
		// The ledger as it was before the restarting column (the definition of that version).
		$old = 'wcptmpabcdef_9_0001_';
		$this->create( $old, "(n INT UNSIGNED NOT NULL PRIMARY KEY, chunk INT UNSIGNED NOT NULL, pos BIGINT UNSIGNED NOT NULL, row_count BIGINT UNSIGNED NOT NULL, data_offset BIGINT UNSIGNED NOT NULL, transactional TINYINT NOT NULL, restarts INT UNSIGNED NOT NULL DEFAULT 0, holder VARCHAR(64) NOT NULL DEFAULT '', constraint_names MEDIUMTEXT NULL) ENGINE=InnoDB" );
		try {
			new Ledger( $this->db, $old, 'aaaa' );
			$this->fail( 'an older ledger was used' );
		} catch ( LedgerOutdated $e ) {
			$this->assertStringContainsString( 'started by an older version of WP Checkpoint', $e->getMessage() );
			$this->assertStringContainsString( '(restarting)', $e->getMessage() );
			$this->assertStringContainsString( 'Start the restore again', $e->getMessage() );
		}

		// The control: a ledger this version creates is taken, and so is the same table the next time.
		$new             = 'wcptmpabcdef_9_0002_';
		$this->created[] = $new;
		$ledger          = new Ledger( $this->db, $new, 'aaaa' );
		$this->assertNull( $ledger->get( 1 ) );
		$this->assertNull( ( new Ledger( $this->db, $new, 'bbbb' ) )->get( 1 ) );
	}
}
