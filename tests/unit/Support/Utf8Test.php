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

	public function test_terminal_controls_and_bidi_overrides_are_neutralized(): void {
		$r = Utf8::REPLACEMENT;
		// C0 except tab and newline, DEL.
		$this->assertSame( "a{$r}[31mb\tc\nd{$r}e{$r}", Utf8::neutralize_controls( "a\x1b[31mb\tc\nd\x7fe\r" ) );
		// C1 (U+0085, U+009B) as UTF-8.
		$this->assertSame( "x{$r}y{$r}[2Jz", Utf8::neutralize_controls( "x\xC2\x85y\xC2\x9B[2Jz" ) );
		// Bidi overrides and isolates; neighbouring code points in the same blocks survive.
		$this->assertSame( "{$r}{$r}{$r}{$r}{$r}{$r}{$r}{$r}{$r}", Utf8::neutralize_controls( "\u{202A}\u{202B}\u{202C}\u{202D}\u{202E}\u{2066}\u{2067}\u{2068}\u{2069}" ) );
		$this->assertSame( "\u{2029}\u{202F}\u{2065}\u{206A}", Utf8::neutralize_controls( "\u{2029}\u{202F}\u{2065}\u{206A}" ) );
		// Ordinary multibyte text, including U+00C2-lead sequences that are not C1, is untouched.
		$this->assertSame( "Ünïcödé — 表 ‑ 🙂 ¡\u{00A0}¿", Utf8::neutralize_controls( "Ünïcödé — 表 ‑ 🙂 ¡\u{00A0}¿" ) );
		$this->assertSame( '', Utf8::neutralize_controls( '' ) );
		foreach ( array( "\x1b", "\xC2\x9B", "\u{202E}", "\x00", "\x7f" ) as $bad ) {
			$this->assertStringNotContainsString( $bad, Utf8::neutralize_controls( Utf8::scrub( "start{$bad}end" ) ) );
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
