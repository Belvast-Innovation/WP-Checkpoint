<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\TempTables;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class TempTablesTest extends TestCase {

	const TOKEN = 'a1b2c3d4e5f6';

	public function test_names_carry_the_installation_the_job_and_a_run(): void {
		$this->assertSame( 'wcptmpa1b2c3_', TempTables::owner_prefix( self::TOKEN ) );
		$this->assertSame( 'wcptmpa1b2c3_12_', TempTables::job_prefix( self::TOKEN, 12 ) );
		$name = TempTables::name( self::TOKEN, 12, 'beef', 'posts' );
		$this->assertSame( 'wcptmpa1b2c3_12_beef_posts', $name );
		$this->assertSame( 12, TempTables::job_id_of( self::TOKEN, $name ) );
		// Another installation's tables are not ours, even in the same database.
		$this->assertSame( 0, TempTables::job_id_of( 'ffffff000000', $name ) );
		$this->assertSame( 0, TempTables::job_id_of( self::TOKEN, 'wcptmpa1b2c3_x_beef_posts' ) );
		$this->assertSame( 0, TempTables::job_id_of( self::TOKEN, 'wcptmpa1b2c3_12_beef' ), 'the run part must be followed by a name separator' );
		$this->assertSame( 0, TempTables::job_id_of( self::TOKEN, 'wp_posts' ) );
		$this->assertSame( 0, TempTables::job_id_of( '', $name ) );
	}

	public function test_long_names_are_truncated_with_a_hash_and_stay_distinct(): void {
		$long  = str_repeat( 'a', 70 ) . 'x';
		$other = str_repeat( 'a', 70 ) . 'y';
		$a     = TempTables::name( self::TOKEN, 123456789, 'beef', $long );
		$b     = TempTables::name( self::TOKEN, 123456789, 'beef', $other );
		$this->assertLessThanOrEqual( TempTables::MAX_NAME, strlen( $a ) );
		$this->assertSame( TempTables::MAX_NAME, strlen( $a ) );
		$this->assertNotSame( $a, $b, 'the hash keeps names that share a truncated prefix apart' );
		$this->assertStringStartsWith( 'wcptmpa1b2c3_123456789_beef_aaaa', $a );
		$this->assertSame( 123456789, TempTables::job_id_of( self::TOKEN, $a ) );
		// A name that just fits is kept as is.
		$fits = str_repeat( 'b', TempTables::MAX_NAME - strlen( 'wcptmpa1b2c3_1_beef_' ) );
		$this->assertSame( 'wcptmpa1b2c3_1_beef_' . $fits, TempTables::name( self::TOKEN, 1, 'beef', $fits ) );
	}

	public function test_every_name_that_can_be_created_can_be_dropped(): void {
		$cases = array(
			'umlauts'            => 'wp_bestellungen_für_küche',
			'chinese'            => 'wp_订单表',
			'mixed'              => 'posts-2024.archive',
			'cut in a multibyte' => str_repeat( 'a', 33 ) . 'ü' . str_repeat( 'b', 20 ),
			'plain long'         => str_repeat( 'x', 80 ),
			'plain short'        => 'posts',
		);
		$names = array();
		foreach ( $cases as $label => $table ) {
			$name = TempTables::name( self::TOKEN, 12, 'beef', $table );
			$this->assertTrue( TempTables::is_safe_name( $name ), "{$label}: {$name}" );
			$this->assertLessThanOrEqual( TempTables::MAX_NAME, strlen( $name ), $label );
			$this->assertSame( 12, TempTables::job_id_of( self::TOKEN, $name ), $label );
			$this->assertSame( $name, TempTables::name( self::TOKEN, 12, 'beef', $table ), "{$label}: deterministic" );
			$names[ $label ] = $name;
		}
		$this->assertSame( count( $cases ), count( array_unique( $names ) ), 'no two originals map to one name' );
		$this->assertSame( 'wcptmpa1b2c3_12_beef_posts', $names['plain short'], 'a plain name is kept as is' );
		// Names that collapse to the same replacement stay apart through the hash of the original.
		$this->assertNotSame( TempTables::name( self::TOKEN, 12, 'beef', 'posts_ä' ), TempTables::name( self::TOKEN, 12, 'beef', 'posts_ö' ) );
		$this->assertStringStartsWith( 'wcptmpa1b2c3_12_beef_posts__', TempTables::name( self::TOKEN, 12, 'beef', 'posts_ä' ) );
		// The rule the dropping side checks refuses exactly what the creating side never produces.
		foreach ( array( 'wcptmpa1b2c3_12_beef_ü', 'a b', '', str_repeat( 'a', 65 ), 'x`y' ) as $bad ) {
			$this->assertFalse( TempTables::is_safe_name( $bad ), $bad );
		}
	}

	public function test_bad_inputs_are_refused(): void {
		foreach ( array( array( 'xyz', 1, 'beef', 't' ), array( self::TOKEN, 0, 'beef', 't' ), array( self::TOKEN, 1, 'BEEF', 't' ), array( self::TOKEN, 1, 'bee', 't' ), array( self::TOKEN, 1, 'beef', '' ), array( self::TOKEN, 1, 'beef', "a\nb" ), array( self::TOKEN, 1, 'beef', 'a/b' ) ) as $args ) {
			try {
				TempTables::name( ...$args );
				$this->fail( 'Accepted: ' . json_encode( $args ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}
}
