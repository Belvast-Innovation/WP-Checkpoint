<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\TargetNames;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Two paths share a key only in the ways the probe saw the file system fold them.
 */
final class TargetNamesTest extends TestCase {

	const NFC = "caf\xC3\xA9";
	const NFD = "cafe\xCC\x81";

	public function test_a_file_system_that_folds_nothing_keeps_every_name_apart(): void {
		$names = new TargetNames( false, false, false, false );
		$this->assertNotSame( $names->key( 'a/Foo.txt' ), $names->key( 'a/foo.txt' ) );
		$this->assertNotSame( $names->key( self::NFC ), $names->key( self::NFD ) );
		$this->assertNotSame( $names->key( 'a./b' ), $names->key( 'a/b' ) );
		$this->assertSame( 'a/Foo.txt', $names->key( 'a/Foo.txt' ), 'the path itself' );
	}

	public function test_each_behaviour_folds_what_it_names_and_nothing_else(): void {
		$ascii = new TargetNames( true, false, false, false );
		$this->assertSame( $ascii->key( 'A/Foo.TXT' ), $ascii->key( 'a/foo.txt' ) );
		$this->assertNotSame( $ascii->key( "\xC3\x89t\xC3\xA9" ), $ascii->key( "\xC3\xA9t\xC3\xA9" ), 'ASCII only: "É" stays' );

		$unicode = new TargetNames( true, true, false, false );
		$this->assertSame( $unicode->key( "\xC3\x89t\xC3\xA9" ), $unicode->key( "\xC3\xA9t\xC3\xA9" ) );

		$trailing = new TargetNames( false, false, false, true );
		$this->assertSame( $trailing->key( 'a. /b.' ), $trailing->key( 'a/b' ) );
		$this->assertNotSame( $trailing->key( 'a/B' ), $trailing->key( 'a/b' ) );
	}

	public function test_normalising_file_systems_join_nfc_and_nfd_when_intl_is_there(): void {
		$names = new TargetNames( false, false, true, false );
		if ( ! class_exists( '\Normalizer' ) ) {
			$this->assertTrue( $names->approximate(), 'said so when it cannot tell' );
			return;
		}
		$this->assertFalse( $names->approximate() );
		$this->assertSame( $names->key( 'x/' . self::NFC ), $names->key( 'x/' . self::NFD ) );
		$this->assertFalse( ( new TargetNames( true, true, false, true ) )->approximate(), 'nothing to normalise: exact' );
	}

	public function test_a_name_that_is_not_utf8_is_folded_as_ascii_only(): void {
		$names = new TargetNames( true, true, true, false );
		$this->assertSame( "a\xFF", $names->key( "A\xFF" ) );
	}

	public function test_the_flags_round_trip(): void {
		$names = new TargetNames( true, false, true, false );
		$this->assertEquals( $names, TargetNames::from_array( $names->to_array() ) );
		$this->assertEquals( new TargetNames( false, false, false, false ), TargetNames::from_array( array() ) );
	}
}
