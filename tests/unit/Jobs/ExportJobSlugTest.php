<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\ExportJob;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ExportJobSlugTest extends TestCase {

	public function test_the_host_without_port_and_the_path_of_a_subdirectory_install(): void {
		$this->assertSame( 'example.com', ExportJob::slug_from_url( 'https://example.com' ) );
		$this->assertSame( 'example.com', ExportJob::slug_from_url( 'https://Example.COM/' ) );
		$this->assertSame( 'shop.example.com/blog', ExportJob::slug_from_url( 'https://shop.example.com:8443/blog/' ), 'the port is dropped, the path kept' );
		$this->assertSame( 'localhost/wp/site', ExportJob::slug_from_url( 'http://localhost:8888/wp/site' ) );
		$this->assertSame( '', ExportJob::slug_from_url( 'not a url' ), 'nothing usable: the pre-flight falls back to "site"' );
	}

	public function test_a_unicode_host_becomes_punycode_when_intl_is_there_and_is_left_to_the_cleaning_otherwise(): void {
		$idn = static function ( string $host ): string {
			return 'münchen.de' === $host ? 'xn--mnchen-3ya.de' : $host;
		};
		$this->assertSame( 'xn--mnchen-3ya.de', ExportJob::slug_from_url( 'https://münchen.de/', $idn ) );
		$this->assertSame( 'münchen.de', ExportJob::slug_from_url( 'https://münchen.de/' ), 'without intl the pre-flight drops the letters it cannot use' );
		if ( function_exists( 'idn_to_ascii' ) ) {
			$this->assertSame( 'xn--mnchen-3ya.de', ExportJob::slug_from_url( 'https://münchen.de/', 'idn_to_ascii' ), 'the real conversion' );
		}
	}
}
