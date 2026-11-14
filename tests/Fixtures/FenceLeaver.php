<?php

namespace WPCheckpoint\Tests\Fixtures;

use PHPUnit\Framework\TestCase;
use WPCheckpoint\Jobs\UninstallFence;

/**
 * Two tests the check of the shared-state register is run on (LeftoversCheckTest), never collected by the suite: one
 * that leaves the uninstall fence closed, committed, as a test does that closes it before a statement that commits
 * and opens it again with a write that is rolled back; and the one after it, which says how it finds the fence.
 */
final class FenceLeaver extends TestCase {

	/** The run that "closed" it. */
	const BY = 'left-by-a-test';

	/** @var string|null How the second test found the fence's row ("state/closed_by"). */
	public static $seen = null;

	/**
	 * The fence's row as stored, read on the test site's connection.
	 *
	 * @return string "state/closed_by", '' when it is not there.
	 */
	public static function stored(): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT state, closed_by FROM `' . UninstallFence::name() . '` WHERE id = %d', UninstallFence::ROW ), ARRAY_A ); // phpcs:ignore -- a fixture's own statement.
		return is_array( $row ) ? $row['state'] . '/' . $row['closed_by'] : '';
	}

	public function test_leaves_the_fence_closed(): void {
		global $wpdb;
		$this->assertSame( UninstallFence::DONE, UninstallFence::close( self::BY ) );
		$wpdb->query( 'COMMIT' );
		// Opened again, as a test's "finally" does, by a write that the tear-down's rollback undoes.
		$this->assertTrue( UninstallFence::open() );
		$wpdb->query( 'ROLLBACK' );
	}

	public function test_says_how_it_finds_the_fence(): void {
		self::$seen = self::stored();
		$this->assertNotSame( '', self::$seen );
	}
}
