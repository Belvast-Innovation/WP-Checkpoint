<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Restore\PrefixKeys;
use WPCheckpoint\Support\StoredNames;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The registry of the names the plugin stores under: what a restore carries
 * by exact name, and what it must never meet.
 */
final class StoredNamesTest extends TestCase {

	/**
	 * Whether a name is one the table prefix rewrite renames in the options table (ending in "user_roles").
	 */
	private static function a_roles_name( string $name ): bool {
		return strlen( $name ) >= strlen( PrefixKeys::ROLES ) && substr( $name, -strlen( PrefixKeys::ROLES ) ) === PrefixKeys::ROLES;
	}

	public function test_every_exact_name_is_the_plugins_and_fits_every_form_it_can_be_stored_in(): void {
		$this->assertSame( array_values( array_unique( StoredNames::EXACT ) ), StoredNames::EXACT, 'listed once each' );
		foreach ( StoredNames::EXACT as $name ) {
			$this->assertStringStartsWith( 'wpcheckpoint_', $name );
			// option_name holds 191 characters; the longest form in front of a name is "_site_transient_timeout_".
			$this->assertLessThanOrEqual( 191 - strlen( '_site_transient_timeout_' ), strlen( $name ), $name );
		}
	}

	/**
	 * The prefix rewrite renames "{P}user_roles" to "{Q}user_roles" before the carry; a stored name ending in
	 * "user_roles" would be "{Q}user_roles" for Q = the rest of it ("wpcheckpoint_x_" and so on), and the carry
	 * would replace the restored roles with the plugin's row.
	 */
	public function test_no_stored_name_can_be_a_roles_option_under_any_table_prefix(): void {
		$this->assertTrue( self::a_roles_name( 'wpcheckpoint_user_roles' ), 'the control: the check finds such a name' );
		foreach ( StoredNames::stored_forms( false ) as $name ) {
			$this->assertFalse( self::a_roles_name( $name ), $name );
		}
		foreach ( array_keys( StoredNames::BUILT ) as $prefix ) {
			// What follows a built prefix is digits or hex digits: never "user_roles".
			$this->assertFalse( self::a_roles_name( $prefix ), $prefix );
		}
	}

	public function test_the_forms_a_name_is_stored_under(): void {
		$options = StoredNames::stored_forms( false );
		$meta    = StoredNames::stored_forms( true );
		$this->assertCount( 5 * count( StoredNames::EXACT ), $options );
		$this->assertCount( 3 * count( StoredNames::EXACT ), $meta );
		$this->assertContains( StoredNames::STORAGE, $options );
		$this->assertContains( '_transient_timeout_' . StoredNames::ENVIRONMENT, $options );
		$this->assertContains( '_site_transient_' . StoredNames::ENVIRONMENT, $meta );
		$this->assertNotContains( '_transient_' . StoredNames::ENVIRONMENT, $meta, 'sitemeta holds site transients only' );
	}

	public function test_the_builders_take_only_values_of_their_form(): void {
		$hash = hash( 'sha256', 'x' );
		$this->assertSame( 'wpcheckpoint_loopback_' . $hash, StoredNames::loopback_token( $hash ) );
		$this->assertSame( 'wpcheckpoint_loopback_job_12', StoredNames::loopback_job( 12 ) );
		$this->assertSame( 'wpcheckpoint_reclaim_message_0', StoredNames::reclaim_message( 0 ) );
		$this->assertSame( 'wpcheckpoint_probe_' . $hash, StoredNames::probe( $hash ) );
		$this->assertSame( StoredNames::LOCK_VERIFY, StoredNames::environment_lock( 'verify' ) );
		$refused = 0;
		foreach (
			array(
				static function () use ( $hash ) {
					StoredNames::loopback_token( strtoupper( $hash ) );
				},
				static function () use ( $hash ) {
					StoredNames::probe( substr( $hash, 1 ) );
				},
				static function () {
					StoredNames::loopback_job( -1 );
				},
				static function () {
					StoredNames::environment_lock( 'other' );
				},
			) as $call
		) {
			try {
				$call();
			} catch ( \InvalidArgumentException $e ) {
				++$refused;
			}
		}
		$this->assertSame( 4, $refused );
		$this->assertFalse( StoredNames::is_sha256( str_repeat( 'g', 64 ) ) );
	}
}
