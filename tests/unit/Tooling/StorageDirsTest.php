<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use WPCheckpoint\Tests\Fixtures\StorageDirs;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The stored storage path, read from the serialized option by its length (StorageDirs::path_in()).
 */
final class StorageDirsTest extends TestCase {

	public function test_the_path_is_read_from_a_serialized_state_by_its_length(): void {
		$state = array(
			'install_id' => 'x',
			'token'      => 'abc',
			'path'       => '/var/www/html/wp-content/wp-checkpoint-abc"; s:4:"path"/',
			'source'     => 'default',
		);
		$this->assertSame( '/var/www/html/wp-content/wp-checkpoint-abc"; s:4:"path"', StorageDirs::path_in( serialize( $state ) ), 'quotes and the key\'s own text inside it' );
		$this->assertSame( '/var/www/html/wp-content/wp-checkpoint-é', StorageDirs::path_in( serialize( array( 'path' => '/var/www/html/wp-content/wp-checkpoint-é' ) ) ), 'bytes, not characters' );
		$this->assertSame( '', StorageDirs::path_in( serialize( array( 'path' => '' ) ) ) );
		$this->assertSame( '', StorageDirs::path_in( serialize( array( 'token' => 'abc' ) ) ) );
		$this->assertSame( '', StorageDirs::path_in( '' ) );
	}
}
