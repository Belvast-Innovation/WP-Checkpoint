<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Utf8;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class Utf8Test extends TestCase {

	public function test_valid_text_is_unchanged(): void {
		$samples = array(
			'',
			'plain ascii',
			"\xEF\xBB\xBFwith BOM",
			'中文 日本語 한국어',
			'emoji 🔑🚀 and 𝄞',
			"\xED\x9F\xBF",                 // U+D7FF, just below the surrogates
			"\xEE\x80\x80",                 // U+E000, just above
			"\xF4\x8F\xBF\xBF",             // U+10FFFF
		);
		foreach ( $samples as $sample ) {
			$this->assertSame( $sample, Utf8::scrub( $sample ), bin2hex( $sample ) );
		}
	}

	public function test_invalid_bytes_become_replacement_characters(): void {
		$r = Utf8::REPLACEMENT;
		$cases = array(
			"\xD6\xF6\xDF"                      => $r . $r . $r,          // Windows-1252 "Ößö"-style bytes
			"\xB1\x31"                          => $r . '1',              // stray continuation byte
			"\xE4\xB8"                          => $r . $r,               // truncated 中
			"abc\xE4\xB8"                       => 'abc' . $r . $r,
			"\xC0\xAF"                          => $r . $r,               // overlong "/"
			"\xED\xA0\x80"                      => $r . $r . $r,          // surrogate U+D800
			"\xF4\x90\x80\x80"                  => $r . $r . $r . $r,     // above U+10FFFF
			"\xF5\x80"                          => $r . $r,               // invalid lead
			"\xD6\xD0\xB9\xFA"                  => $r . "\xD0\xB9" . $r,  // GBK 中国: one accidental valid pair inside
			"ok\xFF"                             => 'ok' . $r,
		);
		foreach ( $cases as $in => $expected ) {
			$this->assertSame( bin2hex( $expected ), bin2hex( Utf8::scrub( $in ) ), bin2hex( $in ) );
		}
	}

	public function test_scrub_deep_covers_keys_and_nested_values(): void {
		$in  = array( "k\xFF" => array( 'v' => "x\xFE", 'n' => 5, 'ok' => 'fine' ) );
		$out = Utf8::scrub_deep( $in );
		$this->assertSame( array( 'k' . Utf8::REPLACEMENT => array( 'v' => 'x' . Utf8::REPLACEMENT, 'n' => 5, 'ok' => 'fine' ) ), $out );
	}

	public function test_result_is_always_valid_utf8(): void {
		mt_srand( 42 );
		for ( $round = 0; $round < 200; $round++ ) {
			$bytes = '';
			$len   = mt_rand( 0, 40 );
			for ( $i = 0; $i < $len; $i++ ) {
				$bytes .= chr( mt_rand( 0, 255 ) );
			}
			$this->assertTrue( 1 === preg_match( '//u', Utf8::scrub( $bytes ) ), bin2hex( $bytes ) );
		}
	}
}
