<?php

namespace WPCheckpoint\Tests\Unit\Files;

use WPCheckpoint\Files\PathKey;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class PathKeyTest extends TestCase {

	public function test_case_is_folded_and_unicode_forms_agree_when_intl_is_present(): void {
		$this->assertSame( PathKey::of( 'Foo.TXT' ), PathKey::of( 'foo.txt' ) );
		$this->assertSame( PathKey::of( 'Ärger.png' ), PathKey::of( 'ärger.png' ), 'case folding is Unicode-aware when mbstring is present' );
		$this->assertNotSame( PathKey::of( 'a.txt' ), PathKey::of( 'b.txt' ) );
		$nfc = "\u{00E9}.txt";        // é precomposed.
		$nfd = "e\u{0301}.txt";       // e + combining acute.
		if ( PathKey::normalization_available() ) {
			$this->assertSame( PathKey::of( $nfc ), PathKey::of( $nfd ) );
		} else {
			$this->assertNotSame( PathKey::of( $nfc ), PathKey::of( $nfd ), 'without intl only case is folded' );
		}
	}

	public function test_collisions_among_siblings_are_grouped(): void {
		$groups = PathKey::collisions( array( 'Readme.md', 'readme.md', 'README.md', 'other.md', 'Other.MD', 'unique' ) );
		$this->assertCount( 2, $groups );
		$this->assertSame( array( 'Readme.md', 'readme.md', 'README.md' ), $groups[0] );
		$this->assertSame( array( 'other.md', 'Other.MD' ), $groups[1] );
		$this->assertSame( array(), PathKey::collisions( array( 'a', 'b', 'c' ) ) );
		$this->assertSame( array(), PathKey::collisions( array() ) );
	}
}
