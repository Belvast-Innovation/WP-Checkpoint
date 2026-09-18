<?php

namespace WPCheckpoint\Tests\Unit\Archive;

use JsonSchema\Validator;
use WPCheckpoint\Archive\Manifest;
use WPCheckpoint\Archive\ManifestError;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ManifestTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../Fixtures/Manifest';
	private const SCHEMA   = __DIR__ . '/../../../schema/manifest-v1.json';

	/**
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public function cases(): array {
		$out = array();
		foreach ( require self::FIXTURES . '/cases.php' as $name => $expect ) {
			$out[ $name ] = array( (string) file_get_contents( self::FIXTURES . '/' . $name . '.json' ), $expect );
		}
		return $out;
	}

	private function schema_accepts( string $json ): bool {
		$validator = new Validator();
		$document  = json_decode( $json );
		$validator->validate( $document, (object) array( '$ref' => 'file://' . realpath( self::SCHEMA ) ) );
		return $validator->isValid();
	}

	/**
	 * @dataProvider cases
	 */
	public function test_runtime_and_schema_agree_on_every_fixture( string $json, array $expect ): void {
		if ( $expect['valid'] ) {
			$manifest = Manifest::from_json( $json );
			$this->assertTrue( $this->schema_accepts( $json ), 'the schema accepts what the runtime accepts' );
			$this->assertSame( 1, $manifest->format_version() );
			return;
		}
		try {
			Manifest::from_json( $json );
			$this->fail( 'expected ManifestError' );
		} catch ( ManifestError $e ) {
			$this->assertSame( $expect['field'], $e->field(), $e->getMessage() );
		}
		if ( $expect['runtime_only'] ) {
			$this->assertTrue( $this->schema_accepts( $json ), 'a runtime-only rule: the schema cannot express it and must accept the document' );
		} else {
			$this->assertFalse( $this->schema_accepts( $json ), 'the schema rejects what the runtime rejects' );
		}
	}

	public function test_every_fixture_file_has_a_case(): void {
		$cases = require self::FIXTURES . '/cases.php';
		foreach ( array( 'valid', 'invalid' ) as $dir ) {
			foreach ( glob( self::FIXTURES . '/' . $dir . '/*.json' ) ?: array() as $file ) {
				$this->assertArrayHasKey( $dir . '/' . basename( $file, '.json' ), $cases, basename( $file ) . ' has no expectation in cases.php' );
			}
		}
	}

	public function test_round_trip_is_stable_and_canonical(): void {
		$json     = (string) file_get_contents( self::FIXTURES . '/valid/extra_keys_ignored.json' );
		$manifest = Manifest::from_json( $json );
		$out      = $manifest->to_json();
		$this->assertStringNotContainsString( 'x_pro_extension', $out, 'unknown keys are dropped on output' );
		$this->assertSame( $out, Manifest::from_json( $out )->to_json(), 'stable' );
		$this->assertStringStartsWith( "{\n    \"format\": \"wpcheckpoint-archive\",\n    \"format_version\": 1,\n    \"required_features\": [],", $out, 'canonical key order' );
		$this->assertStringContainsString( '"home_url": "https://example.com"', $out, 'slashes unescaped' );
		$this->assertSame( 16777216, $manifest->chunk_bytes() );
		$this->assertSame( 'backup', $manifest->kind() );
		$this->assertSame( 'manual', $manifest->trigger() );
		$this->assertSame( 'wp_posts', $manifest->tables()[0]['name'] );
		$this->assertCount( 2, $manifest->volumes() );
		$this->assertArrayNotHasKey( 'chunks', $manifest->volumes()[1] );
		$this->assertSame( array( 'Views were not exported.' ), $manifest->warnings() );
		$this->assertSame( 'wp_', $manifest->site()['table_prefix'] );
	}

	public function test_document_level_rejections(): void {
		foreach ( array( '', 'null', '[]', '"x"', '{"format": ', str_repeat( '[', 40 ) . str_repeat( ']', 40 ) ) as $json ) {
			try {
				Manifest::from_json( $json );
				$this->fail( 'accepted: ' . $json );
			} catch ( ManifestError $e ) {
				$this->assertSame( '', $e->field() );
			}
		}
		$this->expectException( ManifestError::class );
		$this->expectExceptionMessage( 'larger than the maximum' );
		Manifest::from_json( str_repeat( ' ', Manifest::MAX_JSON_BYTES + 1 ) );
	}

	public function test_size_limits_are_enforced_by_both_sides(): void {
		$base = json_decode( (string) file_get_contents( self::FIXTURES . '/valid/base.json' ), true );

		$many = $base;
		for ( $i = 0; $i <= Manifest::MAX_WARNINGS; $i++ ) {
			$many['warnings'][] = 'w' . $i;
		}
		$json = (string) json_encode( $many );
		$this->assertFalse( $this->schema_accepts( $json ) );
		try {
			Manifest::from_json( $json );
			$this->fail( 'too many warnings accepted' );
		} catch ( ManifestError $e ) {
			$this->assertSame( 'warnings', $e->field() );
		}

		$long           = $base;
		$long['site']['locale'] = str_repeat( 'x', Manifest::MAX_STRING + 1 );
		$json           = (string) json_encode( $long );
		$this->assertFalse( $this->schema_accepts( $json ) );
		try {
			Manifest::from_json( $json );
			$this->fail( 'over-long string accepted' );
		} catch ( ManifestError $e ) {
			$this->assertSame( 'site.locale', $e->field() );
		}

		$nul = $base;
		$nul['generator']['name'] = "wp\0checkpoint";
		try {
			Manifest::from_json( (string) json_encode( $nul ) );
			$this->fail( 'NUL accepted' );
		} catch ( ManifestError $e ) {
			$this->assertSame( 'generator.name', $e->field() );
		}
	}
}
