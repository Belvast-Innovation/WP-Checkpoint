<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Redactor;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class RedactorTest extends TestCase {

	private const SECRETS = array(
		'p@ss/w"rd\\x',          // slash, quote, backslash
		'密码🔑secret',           // Chinese + emoji
		'Sup3r-Secret_Value!',
		'abc',                   // too short: must be ignored
	);

	private function redactor(): Redactor {
		return new Redactor( self::SECRETS );
	}

	public function test_known_values_are_masked_in_every_encoding(): void {
		$r = $this->redactor();
		foreach ( array_slice( self::SECRETS, 0, 3 ) as $secret ) {
			$samples = array(
				'raw: ' . $secret,
				'json: ' . json_encode( array( 'x' => $secret ) ),
				'json-unescaped: ' . json_encode( array( 'x' => $secret ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'query: a=' . rawurlencode( $secret ) . '&b=' . urlencode( $secret ),
				'html: ' . htmlspecialchars( $secret, ENT_QUOTES, 'UTF-8' ),
			);
			foreach ( $samples as $sample ) {
				$out = $r->redact( $sample );
				$this->assertStringNotContainsString( $secret, $out, $sample );
				$this->assertStringNotContainsString( substr( json_encode( $secret ), 1, -1 ), $out, $sample );
				$this->assertStringNotContainsString( rawurlencode( $secret ), $out, $sample );
				$this->assertStringContainsString( Redactor::MASK, $out, $sample );
			}
		}
	}

	public function test_short_values_are_not_masked(): void {
		$this->assertSame( 'abc is fine', $this->redactor()->redact( 'abc is fine' ) );
	}

	public function test_key_name_patterns(): void {
		$r     = new Redactor();
		$cases = array(
			'db_password=hunter2 next=1'             => 'hunter2',
			'password: hunter2; other: keep'         => 'hunter2',
			'{"api_key":"AbCd1234","name":"keep"}'   => 'AbCd1234',
			'{"nested":{"client_secret":"zzz9"}}'    => 'zzz9',
			'token => "tok_123"'                     => 'tok_123',
			'https://example.com/?access_key=K1&x=1' => 'K1',
			'Authorization: Bearer eyJhbGciOi.abc'   => 'eyJhbGciOi.abc',
			'Authorization: Basic dXNlcjpwYXNz'      => 'dXNlcjpwYXNz',
			'mysql://root:s3cr3t@db.local/wp'        => 's3cr3t',
			'key AKIAIOSFODNN7EXAMPLE used'          => 'AKIAIOSFODNN7EXAMPLE',
		);
		foreach ( $cases as $input => $secret ) {
			$out = $r->redact( $input );
			$this->assertStringNotContainsString( $secret, $out, $input );
			$this->assertStringContainsString( Redactor::MASK, $out, $input );
		}
		$this->assertStringContainsString( '"name":"keep"', $r->redact( '{"api_key":"AbCd1234","name":"keep"}' ) );
		$this->assertStringContainsString( 'other: keep', $r->redact( 'password: hunter2; other: keep' ) );
		$this->assertStringContainsString( 'db.local/wp', $r->redact( 'mysql://root:s3cr3t@db.local/wp' ) );
	}

	public function test_email_keeps_first_character_and_domain(): void {
		$r = new Redactor();
		$this->assertSame( 'contact h***@example.com now', $r->redact( 'contact hannes.gao@example.com now' ) );
		$this->assertSame( 'a***@sub.example.org', $r->redact( 'admin@sub.example.org' ) );
	}

	public function test_checksums_and_ordinary_text_survive(): void {
		$r    = $this->redactor();
		$line = 'sha256=3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855 file=wp-content/uploads/a.jpg size=1024';
		$this->assertSame( $line, $r->redact( $line ) );
	}

	public function test_secrets_can_only_be_added(): void {
		$r = new Redactor();
		$this->assertSame( 'value ninja-secret-1 here', $r->redact( 'value ninja-secret-1 here' ) );
		$r->add_secrets( array( 'ninja-secret-1' ) );
		$this->assertStringNotContainsString( 'ninja-secret-1', $r->redact( 'value ninja-secret-1 here' ) );
		$this->assertFalse( method_exists( $r, 'remove_secrets' ) );
		$this->assertFalse( method_exists( $r, 'clear_secrets' ) );
	}

	public function test_regex_failure_replaces_the_whole_line(): void {
		$r        = new Redactor();
		$line     = 'password=hunter2 ' . str_repeat( 'a=b&', 200 ) . 'user@example.com';
		$previous = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$out = $r->redact( $line );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $previous );
		}
		$this->assertSame( Redactor::FAILED, $out );
		$this->assertStringNotContainsString( 'hunter2', $out );

		$this->assertStringContainsString( Redactor::MASK, $r->redact( $line ), 'works again once the limit is restored' );
	}

	public function test_longer_secret_wins_over_its_prefix(): void {
		$r = new Redactor( array( 'abcd', 'abcdefgh' ) );
		$this->assertSame( Redactor::MASK . ' and ' . Redactor::MASK, $r->redact( 'abcdefgh and abcd' ) );
	}
}
