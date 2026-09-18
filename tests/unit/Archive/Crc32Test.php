<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use WPCheckpoint\Archive\Crc32;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class Crc32Test extends TestCase {

	/**
	 * @return array<string, array{int}>
	 */
	public function int_sizes(): array {
		return array(
			'64-bit arithmetic' => array( 8 ),
			'32-bit arithmetic' => array( 4 ),
		);
	}

	private function hex( int $crc, int $int_size ): string {
		// On a 64-bit machine the 32-bit path yields the signed view; compare the low 32 bits.
		return sprintf( '%08x', PHP_INT_SIZE >= 8 ? ( $crc & 0xFFFFFFFF ) : $crc );
	}

	/**
	 * @dataProvider int_sizes
	 */
	public function test_of_matches_hash_crc32b( int $int_size ): void {
		foreach ( array( '', 'a', 'The quick brown fox', random_bytes( 1000003 ) ) as $data ) {
			$this->assertSame( hash( 'crc32b', $data ), $this->hex( Crc32::of( $data, $int_size ), $int_size ) );
		}
		$this->assertSame( 'cbf43926', $this->hex( Crc32::of( '123456789', $int_size ), $int_size ), 'the standard check value' );
	}

	/**
	 * @dataProvider int_sizes
	 */
	public function test_combine_reproduces_the_crc_of_the_concatenation( int $int_size ): void {
		$pieces = array( random_bytes( 4194304 ), random_bytes( 1 ), random_bytes( 65537 ), '', random_bytes( 300000 ), "\xFF\xFF\xFF\xFF" );
		$crc    = 0;
		$whole  = '';
		foreach ( $pieces as $piece ) {
			$crc    = Crc32::combine( $crc, Crc32::of( $piece, $int_size ), strlen( $piece ), $int_size );
			$whole .= $piece;
			$this->assertSame( hash( 'crc32b', $whole ), $this->hex( $crc, $int_size ), 'after ' . strlen( $whole ) . ' bytes' );
		}
		$this->assertSame( 7, Crc32::combine( 7, 99, 0, $int_size ), 'an empty second block changes nothing' );
	}

	public function test_the_32_bit_path_never_leaves_the_signed_range(): void {
		// Every value the 32-bit arithmetic produces must be representable as a 32-bit signed int.
		$crc = 0;
		for ( $i = 0; $i < 50; $i++ ) {
			$piece = random_bytes( 1 + $i * 997 );
			$crc   = Crc32::combine( $crc, Crc32::of( $piece, 4 ), strlen( $piece ), 4 );
			$this->assertGreaterThanOrEqual( -2147483648, $crc );
			$this->assertLessThanOrEqual( 2147483647, $crc );
		}
		$this->assertSame( "\xFF\xFF\xFF\xFF", Crc32::pack( -1 ), 'pack() writes the two\'s complement bytes' );
		$this->assertSame( 'ffffffff', Crc32::hex( PHP_INT_SIZE >= 8 ? 0xFFFFFFFF : -1 ) );
	}
}
