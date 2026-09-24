<?php

namespace WPCheckpoint\Tests\Unit\Standalone;

use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Standalone\Failure;
use WPCheckpoint\Standalone\Token;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Database settings show nothing however they are dumped; failures carry
 * fixed words only; tokens are compared by their hash.
 */
final class CredentialsTest extends TestCase {

	const VALUES = array(
		'name'     => 'MARKER_NAME',
		'user'     => 'MARKER_USER',
		'password' => 'MARKER_PASSWORD',
		'host'     => 'MARKER_HOST',
		'prefix'   => 'MARKER_',
	);

	public function test_dumping_the_settings_shows_none_of_them(): void {
		$credentials = Credentials::from_values( self::VALUES );
		// The control: the values are held.
		$this->assertSame( 'MARKER_PASSWORD', $credentials->get( 'password' ) );
		$this->assertSame( 'MARKER_', $credentials->get( 'prefix' ) );
		ob_start();
		var_dump( $credentials ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_dump -- the point of the test.
		$dumps = array(
			'var_dump'   => (string) ob_get_clean(),
			'print_r'    => print_r( $credentials, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- as above.
			'var_export' => var_export( $credentials, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- as above.
			'json'       => (string) json_encode( $credentials ),
			'array cast' => print_r( (array) $credentials, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- as above.
		);
		foreach ( $dumps as $how => $dump ) {
			$this->assertStringNotContainsString( 'MARKER', $dump, $how );
		}
	}

	public function test_the_settings_are_never_serialized_or_copied(): void {
		$credentials = Credentials::from_values( self::VALUES );
		foreach ( array(
			'serialize' => static function () use ( $credentials ) {
				return serialize( $credentials ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the point of the test.
			},
			'clone'     => static function () use ( $credentials ) {
				return clone $credentials;
			},
		) as $how => $attempt ) {
			try {
				$attempt();
				$this->fail( $how . ' went through' );
			} catch ( \LogicException $e ) {
				$this->assertStringNotContainsString( 'MARKER', $e->getMessage(), $how );
			}
		}
		$this->assertSame( 'MARKER_USER', $credentials->get( 'user' ), 'the original is untouched' );
	}

	public function test_settings_typed_in_are_checked_like_those_read(): void {
		foreach ( array( 'name', 'user', 'host' ) as $missing ) {
			$values = self::VALUES;
			unset( $values[ $missing ] );
			try {
				Credentials::from_values( $values );
				$this->fail( 'accepted without ' . $missing );
			} catch ( Failure $e ) {
				$this->assertSame( Failure::MISSING_CONSTANT, $e->reason() );
			}
		}
		$empty_password = self::VALUES;
		$empty_password['password'] = '';
		$this->assertSame( '', Credentials::from_values( $empty_password )->get( 'password' ), 'an empty password is a password' );
		foreach ( array( '', 'wp-', 'wp_ ', "wp_\0" ) as $prefix ) {
			$values           = self::VALUES;
			$values['prefix'] = $prefix;
			try {
				Credentials::from_values( $values );
				$this->fail( 'accepted the prefix ' . json_encode( $prefix ) );
			} catch ( Failure $e ) {
				$this->assertSame( Failure::BAD_PREFIX, $e->reason() );
			}
		}
	}

	public function test_failures_say_fixed_words(): void {
		$this->assertSame( 'wp-config.php does not define DB_HOST.', ( new Failure( Failure::MISSING_CONSTANT, 'DB_HOST' ) )->getMessage() );
		$this->assertSame( 'wp-config.php does not define a database setting.', ( new Failure( Failure::MISSING_CONSTANT, 'MARKER_SECRET' ) )->getMessage(), 'only known names are named' );
		$this->assertSame( 'The database connection failed (MySQL error 1105).', ( new Failure( Failure::OTHER, 1105 ) )->getMessage() );
		$this->assertSame( Failure::OTHER, ( new Failure( 'made up' ) )->reason() );
	}

	public function test_tokens_are_random_well_formed_and_compared_by_hash(): void {
		$token = Token::generate();
		$this->assertTrue( Token::well_formed( $token ) );
		$this->assertNotSame( $token, Token::generate() );
		$hash = Token::hash( $token );
		$this->assertTrue( Token::matches( $token, $hash ) );
		$this->assertFalse( Token::matches( Token::generate(), $hash ) );
		foreach ( array( strtoupper( $token ), substr( $token, 1 ), $token . '0', str_repeat( 'g', 64 ), 42, null ) as $bad ) {
			$this->assertFalse( Token::well_formed( $bad ), json_encode( $bad ) );
		}
		$this->assertFalse( Token::matches( strtoupper( $token ), $hash ) );
	}

	public function test_the_http_probes_of_the_tests_are_never_packaged(): void {
		$root = dirname( __DIR__, 3 );
		// The control: the probes exist, under tests/.
		$this->assertFileExists( $root . '/tests/Fixtures/Standalone/http/config-probe.php' );
		$this->assertFileExists( $root . '/tests/Fixtures/Standalone/http/wordpress-probe.php' );
		$this->assertContains( '/tests', array_map( 'trim', (array) file( $root . '/.distignore' ) ), 'tests/ is left out of the package' );
		$this->assertContains( 'tests/Fixtures/Standalone/http/probe.key', array_map( 'trim', (array) file( $root . '/.gitignore' ) ), 'the one-time key is never committed' );
	}
}
