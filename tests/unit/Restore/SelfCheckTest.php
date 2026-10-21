<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\SelfCheck;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The self-check row's value, the pass header's check and the answer to a challenge (Restore\SelfCheck).
 */
final class SelfCheckTest extends TestCase {

	public function test_a_value_reads_back_as_written_and_nothing_else_reads(): void {
		$nonce = SelfCheck::random();
		$pass  = hash( 'sha256', SelfCheck::random() );
		$value = SelfCheck::value( 12, $nonce, $pass );
		$this->assertSame(
			array(
				'job'   => 12,
				'nonce' => $nonce,
				'pass'  => $pass,
			),
			SelfCheck::parse( $value )
		);
		foreach ( array(
			'more after'      => $value . "\n",
			'more before'     => ' ' . $value,
			'no job'          => '0:' . $nonce . ':' . $pass,
			'a job led by 0'  => '012:' . $nonce . ':' . $pass,
			'capitals'        => '12:' . strtoupper( $nonce ) . ':' . $pass,
			'short'           => '12:' . substr( $nonce, 1 ) . ':' . $pass,
			'two parts'       => '12:' . $nonce,
			'empty'           => '',
			'json'            => (string) json_encode( array( 12, $nonce, $pass ) ),
		) as $case => $stored ) {
			$this->assertNull( SelfCheck::parse( $stored ), $case );
		}
		foreach ( array( array( 0, $nonce, $pass ), array( 12, 'x', $pass ), array( 12, $nonce, strtoupper( $pass ) ) ) as $i => $args ) {
			try {
				SelfCheck::value( ...$args );
				$this->fail( 'written: ' . $i );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'Not a self-check row.', $e->getMessage() );
			}
		}
	}

	public function test_only_the_key_whose_hash_the_row_holds_passes(): void {
		$key  = SelfCheck::random();
		$pass = hash( 'sha256', $key );
		$this->assertTrue( SelfCheck::passes( $key, $pass ), 'the control: the key' );
		foreach ( array(
			'another key'   => SelfCheck::random(),
			'the hash'      => $pass,
			'capitals'      => strtoupper( $key ),
			'cut short'     => substr( $key, 0, 63 ),
			'and more'      => $key . '0',
			'padded'        => ' ' . $key,
			'empty'         => '',
		) as $case => $header ) {
			$this->assertFalse( SelfCheck::passes( $header, $pass ), $case );
		}
		$this->assertFalse( SelfCheck::passes( $key, '' ), 'no hash held' );
		$this->assertFalse( SelfCheck::passes( $key, strtoupper( $pass ) ), 'a hash not of its form' );
	}

	public function test_the_answer_is_the_hmac_of_the_challenge_keyed_by_the_one_time_value(): void {
		$nonce     = SelfCheck::random();
		$challenge = SelfCheck::random();
		$this->assertSame( hash_hmac( 'sha256', $challenge, $nonce ), SelfCheck::answer( $nonce, $challenge ) );
		$this->assertNotSame( SelfCheck::answer( $nonce, $challenge ), SelfCheck::answer( SelfCheck::random(), $challenge ), 'another one-time value, another answer' );
		$this->assertMatchesRegularExpression( SelfCheck::HEX, SelfCheck::random() );
		$this->assertNotSame( SelfCheck::random(), SelfCheck::random() );
	}
}
