<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;

/**
 * In a real web request, Standalone\ConfigLoader reads from this site's
 * wp-config.php exactly what WordPress itself loads (compared as SHA-256 of
 * each value). wp-env runs the official Docker image, whose wp-config.php
 * takes every database setting from the environment. The probes answer only
 * to this test's one-time key.
 */
final class StandaloneConfigHttpTest extends WP_UnitTestCase {

	public function test_the_loader_reads_what_wordpress_loads_in_a_web_request(): void {
		$host = (string) getenv( 'WPCHECKPOINT_TEST_LOOPBACK_HOST' );
		if ( '' === $host ) {
			$this->markTestSkipped( 'Needs the web server of the test site (WPCHECKPOINT_TEST_LOOPBACK_HOST).' );
		}
		$dir      = dirname( __DIR__ ) . '/Fixtures/Standalone/http';
		$key      = bin2hex( random_bytes( 24 ) );
		$base     = rtrim( $host, '/' ) . '/wp-content/plugins/wp-checkpoint/tests/Fixtures/Standalone/http/';
		$get      = static function ( string $url, string $sent = '' ): array {
			$response = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'X-WPCheckpoint-Probe-Key' => $sent ) ) );
			return is_wp_error( $response ) ? array( 0, $response->get_error_message() ) : array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
		};
		// Without a key file, the probe refuses even the right-looking request.
		list( $status, $body ) = $get( $base . 'config-probe.php', $key );
		$this->assertSame( 404, $status, $body );
		$this->assertSame( '', $body );
		file_put_contents( $dir . '/probe.key', $key );
		try {
			list( $status, $body ) = $get( $base . 'config-probe.php', 'wrong' . $key );
			$this->assertSame( 404, $status, 'a wrong key' );
			list( $status, $body ) = $get( $base . 'config-probe.php?key=' . $key );
			$this->assertSame( 404, $status, 'the key in the query string (which access logs keep) does not count' );
			touch( $dir . '/probe.key', time() - 3600 );
			list( $status, $body ) = $get( $base . 'config-probe.php', $key );
			$this->assertSame( 404, $status, 'a key file left behind for an hour' );
			touch( $dir . '/probe.key' );
			list( $status, $ours ) = $get( $base . 'config-probe.php', $key );
			$this->assertSame( 200, $status, $ours );
			list( $status, $theirs ) = $get( $base . 'wordpress-probe.php', $key );
			$this->assertSame( 200, $status, $theirs );
		} finally {
			unlink( $dir . '/probe.key' );
		}
		$ours   = json_decode( $ours, true );
		$theirs = json_decode( $theirs, true );
		$this->assertIsArray( $theirs );
		$this->assertCount( 8, $theirs, 'the control: WordPress\'s values came back' );
		$this->assertSame( $theirs, $ours, 'the same values, one by one' );
	}
}
