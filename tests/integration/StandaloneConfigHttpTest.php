<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Tests\Fixtures\ProbeDir;

/**
 * In a real web request, Standalone\ConfigLoader reads from this site's
 * wp-config.php exactly what WordPress itself loads (compared as SHA-256 of
 * each value). wp-env runs the official Docker image, whose wp-config.php
 * takes every database setting from the environment. The probes answer only
 * to this test's one-time key, kept in this run's probe directory
 * (tests/Fixtures/ProbeDir.php), sent with this run's ID.
 */
final class StandaloneConfigHttpTest extends WP_UnitTestCase {

	public function test_the_loader_reads_what_wordpress_loads_in_a_web_request(): void {
		$host = (string) getenv( 'WPCHECKPOINT_TEST_LOOPBACK_HOST' );
		if ( '' === $host ) {
			$this->markTestSkipped( 'Needs the web server of the test site (WPCHECKPOINT_TEST_LOOPBACK_HOST).' );
		}
		$id = (string) getenv( 'WPCHECKPOINT_TEST_RUN_ID' );
		$this->assertTrue( ProbeDir::is_run_id( $id ), 'run through bin/test-integration.sh, which gives the run its ID' );
		$dir   = ProbeDir::path( WP_CONTENT_DIR, $id ); // This run's own, where the web server can read it.
		$other_id = strrev( $id ) === $id ? str_repeat( 'a', 16 ) : strrev( $id ); // Another run's ID, well formed.
		$other    = ProbeDir::path( WP_CONTENT_DIR, $other_id );
		$key   = bin2hex( random_bytes( 24 ) );
		$base  = rtrim( $host, '/' ) . '/wp-content/plugins/wp-checkpoint/tests/Fixtures/Standalone/http/';
		$get   = static function ( string $url, string $run, string $sent ): array {
			$response = wp_remote_get( $url, array( 'timeout' => 20, 'headers' => array( 'X-WPCheckpoint-Probe-Run' => $run, 'X-WPCheckpoint-Probe-Key' => $sent ) ) );
			return is_wp_error( $response ) ? array( 0, $response->get_error_message() ) : array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
		};
		// Without a key file, the probe refuses even the right-looking request.
		list( $status, $body ) = $get( $base . 'config-probe.php', $id, $key );
		$this->assertSame( 404, $status, $body );
		$this->assertSame( '', $body );
		mkdir( $dir );
		Deleter::allow( $dir ); // Made by this test among the site's own directories: registered to be deleted.
		file_put_contents( $dir . '/probe.key', $key );
		try {
			list( $status ) = $get( $base . 'config-probe.php', $id, $key );
			$this->assertSame( 200, $status, 'the control: this run\'s ID and key' );
			list( $status ) = $get( $base . 'config-probe.php', $id, 'wrong' . $key );
			$this->assertSame( 404, $status, 'a wrong key' );
			list( $status ) = $get( $base . 'config-probe.php?key=' . $key . '&run=' . $id, '', '' );
			$this->assertSame( 404, $status, 'the key in the query string (which access logs keep) does not count' );
			// Run IDs not of the form, each with a directory of that name holding the right key: never built into a path.
			$malformed = array( substr( $id, 1 ), $id . '0', 'A' . substr( $id, 1 ), '..' . substr( $id, 2 ) );
			foreach ( $malformed as $run ) {
				mkdir( WP_CONTENT_DIR . '/' . ProbeDir::PREFIX . $run );
				Deleter::allow( WP_CONTENT_DIR . '/' . ProbeDir::PREFIX . $run );
				file_put_contents( WP_CONTENT_DIR . '/' . ProbeDir::PREFIX . $run . '/probe.key', $key );
			}
			foreach ( array_merge( $malformed, array( str_repeat( '0', 16 ), '' ) ) as $run ) {
				list( $status ) = $get( $base . 'config-probe.php', $run, $key );
				$this->assertSame( 404, $status, 'a run ID not of the form, or of another run: ' . $run );
			}
			// A well-formed ID whose directory is a link to this run's: refused, though the key behind it is right.
			$this->assertTrue( symlink( $dir, $other ) );
			Deleter::allow( $other );
			list( $status ) = $get( $base . 'config-probe.php', $other_id, $key );
			$this->assertSame( 404, $status, 'the probe directory is a link' );
			// The key file a link to a file holding the key: refused; the same key in a plain file: let through.
			file_put_contents( $dir . '/probe.real', $key );
			Deleter::delete_tree( $dir, $dir . '/probe.key' );
			$this->assertTrue( symlink( $dir . '/probe.real', $dir . '/probe.key' ) );
			list( $status ) = $get( $base . 'config-probe.php', $id, $key );
			$this->assertSame( 404, $status, 'the key file is a link' );
			Deleter::delete_tree( $dir, $dir . '/probe.key' );
			file_put_contents( $dir . '/probe.key', $key );
			touch( $dir . '/probe.key', time() - 3600 );
			list( $status ) = $get( $base . 'config-probe.php', $id, $key );
			$this->assertSame( 404, $status, 'a key file left behind for an hour' );
			touch( $dir . '/probe.key' );
			list( $status, $ours ) = $get( $base . 'config-probe.php', $id, $key );
			$this->assertSame( 200, $status, $ours );
			list( $status, $theirs ) = $get( $base . 'wordpress-probe.php', $id, $key );
			$this->assertSame( 200, $status, $theirs );
		} finally {
			foreach ( $malformed ?? array() as $run ) {
				$made = WP_CONTENT_DIR . '/' . ProbeDir::PREFIX . $run;
				Deleter::delete_tree( WP_CONTENT_DIR, $made );
			}
			ProbeDir::remove( $other );
			$this->assertTrue( ProbeDir::remove( $dir ), 'the probe directory goes' );
		}
		$ours   = json_decode( $ours, true );
		$theirs = json_decode( $theirs, true );
		$this->assertIsArray( $theirs );
		$this->assertCount( 8, $theirs, 'the control: WordPress\'s values came back' );
		$this->assertSame( $theirs, $ours, 'the same values, one by one' );
	}
}
