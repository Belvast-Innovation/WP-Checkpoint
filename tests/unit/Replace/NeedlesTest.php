<?php

namespace WPCheckpoint\Tests\Unit\Replace;

use WPCheckpoint\Replace\Needles;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The encodings a search text is looked for in, and where a match counts.
 */
final class NeedlesTest extends TestCase {

	private static function move(): Needles {
		return Needles::for_move( 'https://old.example', 'https://new.example', '/var/www/old', '/srv/new' );
	}

	public function test_a_pair_is_found_in_every_encoding_and_replaced_in_the_same_one(): void {
		$needles = new Needles( array( array( 'https://old.example', 'https://new.example' ) ) );
		$this->assertSame( array( 'https://new.example/a', 1 ), $needles->replace( 'https://old.example/a' ) );
		$this->assertSame( array( '{"u":"https:\/\/new.example\/a"}', 1 ), $needles->replace( '{"u":"https:\/\/old.example\/a"}' ), 'JSON with escaped slashes' );
		$this->assertSame( array( '"{\"u\":\"https:\\\\\/\\\\\/new.example\"}"', 1 ), $needles->replace( '"{\"u\":\"https:\\\\\/\\\\\/old.example\"}"' ), 'JSON inside JSON' );
		$this->assertSame( array( 'to=https%3A%2F%2Fnew.example%2Fa', 1 ), $needles->replace( 'to=https%3A%2F%2Fold.example%2Fa' ), 'URL encoded' );
		$this->assertSame( array( 'to=https%3a%2f%2fnew.example', 1 ), $needles->replace( 'to=https%3a%2f%2fold.example' ), 'URL encoded, lowercase' );
		// The replaced JSON is still JSON and says the new address.
		$json = $needles->replace( '[{"settings":{"url":"https:\/\/old.example\/wp-content\/uploads\/a.jpg"}}]' )[0];
		$this->assertSame( 'https://new.example/wp-content/uploads/a.jpg', json_decode( $json, true )[0]['settings']['url'] );
	}

	public function test_a_match_counts_only_on_a_boundary(): void {
		$needles = new Needles( array( array( 'old.example', 'new.example' ) ) );
		$this->assertSame( array( 'new.example/path', 1 ), $needles->replace( 'old.example/path' ), 'the control: a bounded match is replaced' );
		foreach ( array( 'old.example.au', 'old.example-staging.net', 'cold.example', 'old.examples', 'x.old.example_' ) as $longer ) {
			$this->assertSame( array( $longer, 0 ), $needles->replace( $longer ), $longer . ' names another host' );
		}
		$this->assertSame( array( 'mail@new.example', 1 ), $needles->replace( 'mail@old.example' ) );
		$this->assertSame( array( '%2F%2Fnew.example', 1 ), $needles->replace( '%2F%2Fold.example' ), 'after a percent-escape' );
		$this->assertSame( array( 'a https://new.example/x and https://new.example.', 2 ), ( new Needles( array( array( 'https://old.example', 'https://new.example' ) ) ) )->replace( 'a https://old.example/x and https://old.example.' ), 'a sentence may end right after an address' );
	}

	public function test_the_longest_needle_wins_and_replaced_text_is_not_searched_again(): void {
		$move = self::move();
		$this->assertSame( array( 'https://new.example/a //new.example/b', 2 ), $move->replace( 'https://old.example/a //old.example/b' ) );
		$this->assertSame( array( 'https://new.example/', 1 ), $move->replace( 'http://old.example/' ), 'http becomes the new scheme' );
		$this->assertSame( array( '/srv/new/wp-content', 1 ), $move->replace( '/var/www/old/wp-content' ) );
		$this->assertSame( array( '/var/www/old2', 0 ), $move->replace( '/var/www/old2' ) );
		$growing = new Needles( array( array( 'https://a.example', 'https://a.example/sub' ) ) );
		$this->assertSame( array( 'https://a.example/sub/x', 1 ), $growing->replace( 'https://a.example/x' ), 'the replacement contains the search: replaced once' );
	}

	public function test_a_pair_that_cannot_be_written_in_every_form_is_refused(): void {
		foreach ( array( array( 'https://old.example', 'https://new."example' ), array( 'a\\b', 'c' ), array( "a\nb", 'c' ), array( '', 'x' ) ) as $pair ) {
			try {
				new Needles( array( $pair ) );
				$this->fail( 'accepted ' . json_encode( $pair ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->expectException( \InvalidArgumentException::class );
		new Needles( array( array( 'https://old.example', 'https://a.example' ), array( 'https://old.example', 'https://b.example' ) ) );
	}

	public function test_occurs_in_is_a_cheap_filter_over_every_form(): void {
		$needles = new Needles( array( array( 'https://old.example', 'https://new.example' ) ) );
		$this->assertTrue( $needles->occurs_in( 'x https%3A%2F%2Fold.example' ) );
		$this->assertTrue( $needles->occurs_in( 'https:\/\/old.example' ) );
		$this->assertFalse( $needles->occurs_in( 'https://new.example' ) );
		$this->assertContains( 'https%3A%2F%2Fold.example', $needles->needles() );
	}
}
