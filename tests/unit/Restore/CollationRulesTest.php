<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\CollationRules;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The rule table: a known name stays, an unknown one takes the first candidate the server knows, and one without a
 * usable candidate has no replacement.
 */
final class CollationRulesTest extends TestCase {

	public function test_a_name_the_server_knows_is_kept_as_written(): void {
		$known = CollationRules::set( array( 'utf8mb4_0900_ai_ci', 'utf8mb4_uca1400_nopad_ai_ci' ) );
		$this->assertSame( 'utf8mb4_0900_ai_ci', CollationRules::resolve( 'utf8mb4_0900_ai_ci', $known ) );
		$this->assertSame( 'UTF8MB4_0900_AI_CI', CollationRules::resolve( 'UTF8MB4_0900_AI_CI', $known ), 'any case, written as it was' );
	}

	public function test_an_unknown_name_takes_the_first_candidate_the_server_knows(): void {
		$mariadb_11 = CollationRules::set( array( 'utf8mb4_uca1400_nopad_ai_ci', 'utf8mb4_unicode_520_nopad_ci', 'utf8mb4_nopad_bin' ) );
		$mariadb_10 = CollationRules::set( array( 'utf8mb4_unicode_520_nopad_ci', 'utf8mb4_nopad_bin' ) );
		$this->assertSame( 'utf8mb4_uca1400_nopad_ai_ci', CollationRules::resolve( 'utf8mb4_0900_ai_ci', $mariadb_11 ) );
		$this->assertSame( 'utf8mb4_unicode_520_nopad_ci', CollationRules::resolve( 'utf8mb4_0900_ai_ci', $mariadb_10 ), 'the second candidate when the first is unknown' );
		$this->assertSame( 'utf8mb4_nopad_bin', CollationRules::resolve( 'utf8mb4_0900_bin', $mariadb_10 ) );
	}

	public function test_a_name_without_a_usable_candidate_has_no_replacement(): void {
		$mysql_57 = CollationRules::set( array( 'utf8mb4_unicode_520_ci', 'utf8mb4_general_ci' ) );
		$this->assertNull( CollationRules::resolve( 'utf8mb4_0900_ai_ci', $mysql_57 ), 'MySQL 5.7 has no NO PAD collation' );
		$this->assertNull( CollationRules::resolve( 'utf8mb4_ja_0900_as_cs', CollationRules::set( array( 'utf8mb4_uca1400_nopad_as_cs' ) ) ), 'a language-specific name has no row' );
		$this->assertNull( CollationRules::resolve( 'utf8mb4_0900_as_cs', array() ), 'the control: no server, no replacement' );
	}
}
