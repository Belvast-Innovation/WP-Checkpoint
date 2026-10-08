<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\TargetIncompatible;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * What the target database cannot take, carried as data; the message is made from it and names the first tables only.
 */
final class TargetIncompatibleTest extends TestCase {

	public function test_it_carries_what_is_missing_and_the_tables_and_names_them_all_when_they_are_few(): void {
		$e = new TargetIncompatible( TargetIncompatible::COLLATION, array( 'utf8mb4_0900_ai_ci', 'utf8mb4_0900_ai_ci' ), array( 'wp_posts', 'wp_options' ) );
		$this->assertSame( TargetIncompatible::COLLATION, $e->kind() );
		$this->assertSame( array( 'utf8mb4_0900_ai_ci' ), $e->missing(), 'each name once' );
		$this->assertSame( array( 'wp_posts', 'wp_options' ), $e->tables() );
		$this->assertSame( 'This database server cannot take the collation utf8mb4_0900_ai_ci, which the backup uses. Tables that use it: wp_posts, wp_options.', $e->getMessage() );
	}

	public function test_many_tables_are_named_up_to_the_first_few_and_counted(): void {
		$tables = array();
		for ( $i = 1; $i <= 7; $i++ ) {
			$tables[] = 't' . $i;
		}
		$e = new TargetIncompatible( TargetIncompatible::COLLATION, array( 'a_ci', 'b_ci' ), $tables );
		$this->assertStringContainsString( 'the collations a_ci, b_ci', $e->getMessage() );
		$this->assertStringContainsString( 'Tables that use it: t1, t2, t3, t4, t5 and others (7 tables in all; the job log lists them all).', $e->getMessage() );
		$this->assertStringNotContainsString( 't6', $e->getMessage(), 'the rest are counted, not named' );
		$this->assertStringContainsString( 't5', $e->getMessage(), 'the control: the fifth is named' );
		$this->assertSame( $tables, $e->tables(), 'all of them carried' );
		$this->assertStringNotContainsString( 'and others', TargetIncompatible::summary( TargetIncompatible::COLLATION, array( 'a_ci' ), array_slice( $tables, 0, TargetIncompatible::SHOWN_TABLES ) ), 'exactly the shown number: all named' );
	}
}
