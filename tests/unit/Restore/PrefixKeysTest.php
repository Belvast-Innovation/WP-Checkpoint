<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\PrefixKeys;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The exact names WordPress gives rows after the table prefix, per site, and nothing matched by "starts with".
 */
final class PrefixKeysTest extends TestCase {

	public function test_every_known_name_per_site_and_through_an_intermediate_name(): void {
		$keys    = new PrefixKeys( 'wpx_', 'wp_', 'wcp-7-ab12:' );
		$renames = $keys->user_keys( array( 1, 5 ) );
		$this->assertCount( 18, $renames, 'nine keys, two sites' );
		$this->assertSame( array( 'wcp-7-ab12:1:0', 'wp_capabilities' ), $renames['wpx_capabilities'], 'the main site: no id' );
		$this->assertSame( array( 'wcp-7-ab12:5:0', 'wp_5_capabilities' ), $renames['wpx_5_capabilities'] );
		$this->assertSame( 'wp_5_usersettingstime', $renames['wpx_5_usersettingstime'][1], 'the old names too' );
		$this->assertSame( array( 'wpx_user_roles', 'wcp-7-ab12:1:r', 'wp_user_roles' ), $keys->roles( 1 ) );
		$this->assertSame( array( 'wpx_5_user_roles', 'wcp-7-ab12:5:r', 'wp_5_user_roles' ), $keys->roles( 5 ) );
		$this->assertSame( count( $renames ), count( array_unique( array_column( $renames, 0 ) ) ), 'every intermediate name its own' );
	}

	public function test_known_is_the_exact_names_of_sites_that_exist(): void {
		$keys = new PrefixKeys( 'wpx_', 'wp_', 'm:' );
		foreach ( array( 'wpx_capabilities', 'wpx_user-settings', 'wpx_5_capabilities' ) as $key ) {
			$this->assertTrue( $keys->known( $key, array( 5 ) ), $key );
		}
		foreach ( array( 'wpx_6_capabilities' => 'a site the backup does not have', 'wpx_1_capabilities' => 'the main site has no id', 'wpx_05_capabilities' => 'not how WordPress writes an id', 'wpx_myplugin' => 'not a WordPress name', 'wp_capabilities' => 'another prefix', 'capabilities' => 'no prefix' ) as $key => $why ) {
			$this->assertFalse( $keys->known( $key, array( 5 ) ), $why );
		}
	}

	public function test_an_empty_backup_prefix_matches_the_exact_names_only(): void {
		$keys = new PrefixKeys( '', 'wp_', 'm:' );
		$this->assertSame( array( 'm:1:0', 'wp_capabilities' ), $keys->user_keys( array( 1 ) )['capabilities'] );
		$this->assertSame( 'user_roles', $keys->roles( 1 )[0] );
		$this->assertTrue( $keys->known( '3_capabilities', array( 3 ) ) );
		$this->assertFalse( $keys->known( 'nickname', array() ), 'an ordinary key is not one of them' );
		$this->assertFalse( $keys->known( 'myplugin_capabilities', array() ) );
	}

	public function test_a_copy_keeps_what_follows_the_backups_prefix(): void {
		$keys = new PrefixKeys( 'wpx_', 'wp_', 'm:' );
		$this->assertSame( 'wp_myplugin_pref', $keys->copy_of( 'wpx_myplugin_pref' ) );
		$this->assertSame( 'wp_9_capabilities', $keys->copy_of( 'wpx_9_capabilities' ) );
	}
}
