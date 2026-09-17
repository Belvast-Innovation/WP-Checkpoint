<?php

namespace WPCheckpoint\Tests\Integration;

use WP_UnitTestCase;
use WPCheckpoint\Admin\DownloadHandler;
use WPCheckpoint\Support\Deleter;
use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Guard;
use WPCheckpoint\Support\Options;

final class DownloadTest extends WP_UnitTestCase {

	/** @var Directories */
	private $dirs;

	/** @var string */
	private $base;

	/** @var string */
	private $outside;

	public function set_up(): void {
		parent::set_up();
		Options::delete( Directories::OPTION );
		$this->dirs = new Directories( array( 'is_web_request' => false, 'document_root' => '' ) );
		$this->base = $this->dirs->base();
		$this->assertNotSame( '', $this->base );
		file_put_contents( $this->base . '/backups/site.wpcheckpoint.zip', str_repeat( 'Z', 5000 ) );
		file_put_contents( $this->base . '/backups/site.manifest.json', '{}' );
		file_put_contents( $this->base . '/backups/notes.txt', 'no' );
		file_put_contents( $this->base . '/logs/job-1-abcd.log', "line\n" );
		file_put_contents( $this->base . '/tmp/partial.wpcheckpoint.zip', 'tmp' );
		$this->outside = sys_get_temp_dir() . '/wpcheckpoint-dl-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->outside );
		file_put_contents( $this->outside . '/secret.log', 'secret' );
		@symlink( $this->outside . '/secret.log', $this->base . '/logs/link.log' );
	}

	public function tear_down(): void {
		Deleter::empty_directory( $this->base );
		@rmdir( $this->base );
		Deleter::empty_directory( $this->outside );
		@rmdir( $this->outside );
		Options::delete( Directories::OPTION );
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	private function handler(): DownloadHandler {
		return new DownloadHandler( $this->dirs );
	}

	private function request( string $file, string $method = 'GET', $range = null, $if_range = null ): array {
		$headers = array();
		$out     = fopen( 'php://memory', 'w+' );
		$status  = $this->handler()->handle( $file, $method, $range, $if_range, static function ( string $line, $code ) use ( &$headers ) {
			$headers[] = null === $code ? $line : $line . ' [' . $code . ']';
		}, $out );
		rewind( $out );
		return array( $status, $headers, stream_get_contents( $out ) );
	}

	public function test_allowed_files_resolve(): void {
		$h = $this->handler();
		$this->assertSame( realpath( $this->base . '/backups/site.wpcheckpoint.zip' ), $h->resolve( 'backups/site.wpcheckpoint.zip' ) );
		$this->assertSame( realpath( $this->base . '/backups/site.manifest.json' ), $h->resolve( 'backups/site.manifest.json' ) );
		$this->assertSame( realpath( $this->base . '/logs/job-1-abcd.log' ), $h->resolve( 'logs/job-1-abcd.log' ) );
		$this->assertSame( realpath( $this->base . '/logs/job-1-abcd.log' ), $h->resolve( '/logs/job-1-abcd.log' ), 'leading slash is tolerated' );
	}

	/**
	 * @dataProvider rejected_paths
	 */
	public function test_rejected_paths( string $file ): void {
		$this->assertSame( '', $this->handler()->resolve( $file ), $file );
		list( $status, $headers, $body ) = $this->request( $file );
		$this->assertSame( 404, $status, $file );
		$this->assertSame( 'Not found.', $body );
	}

	public function rejected_paths(): array {
		return array(
			'tmp is not downloadable'        => array( 'tmp/partial.wpcheckpoint.zip' ),
			'traversal into tmp'             => array( 'backups/../tmp/partial.wpcheckpoint.zip' ),
			'traversal out of base'          => array( 'backups/../../../wp-config.php' ),
			'encoded dot segments'           => array( 'backups/%2e%2e/tmp/partial.wpcheckpoint.zip' ),
			'double encoded'                 => array( 'backups/%252e%252e/tmp/partial.wpcheckpoint.zip' ),
			'absolute path'                  => array( '/etc/passwd' ),
			'absolute path with base prefix' => array( ABSPATH . 'wp-config.php' ),
			'windows absolute'               => array( 'C:\\Windows\\win.ini' ),
			'backslash traversal'            => array( 'backups\\..\\tmp\\partial.wpcheckpoint.zip' ),
			'null byte'                      => array( "backups/site.wpcheckpoint.zip\0.txt" ),
			'extension not allowed'          => array( 'backups/notes.txt' ),
			'protection file'                => array( 'backups/index.php' ),
			'owner marker'                   => array( '.wpcheckpoint-owner' ),
			'directory'                      => array( 'backups' ),
			'symlink to outside log'         => array( 'logs/link.log' ),
			'empty'                          => array( '' ),
		);
	}

	public function test_full_download(): void {
		list( $status, $headers, $body ) = $this->request( 'backups/site.wpcheckpoint.zip' );
		$this->assertSame( 200, $status );
		$this->assertSame( 5000, strlen( $body ) );
		$this->assertContains( 'Content-Length: 5000', $headers );
		$this->assertContains( 'Content-Disposition: attachment; filename="site.wpcheckpoint.zip"', $headers );
		$this->assertContains( 'Content-Type: application/octet-stream [200]', $headers );
	}

	public function test_range_download(): void {
		list( $status, $headers, $body ) = $this->request( 'backups/site.wpcheckpoint.zip', 'GET', 'bytes=4990-' );
		$this->assertSame( 206, $status );
		$this->assertSame( 10, strlen( $body ) );
		$this->assertContains( 'Content-Range: bytes 4990-4999/5000', $headers );

		list( $status, $headers, $body ) = $this->request( 'backups/site.wpcheckpoint.zip', 'GET', 'bytes=9999-' );
		$this->assertSame( 416, $status );
		$this->assertContains( 'Content-Range: bytes */5000 [416]', $headers );
		$this->assertSame( '', $body );

		list( $status, $headers, $body ) = $this->request( 'logs/job-1-abcd.log', 'HEAD' );
		$this->assertSame( 200, $status );
		$this->assertSame( '', $body );
		$this->assertContains( 'Content-Length: 5', $headers );
	}

	public function test_serve_requires_the_download_nonce_and_capability(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = Guard::nonce( DownloadHandler::NONCE_ACTION );
		$this->expectException( \WPDieException::class );
		$this->handler()->serve();
	}

	public function test_download_url_carries_the_nonce(): void {
		$url = DownloadHandler::url( 'logs/job-1-abcd.log' );
		$this->assertStringContainsString( 'admin-post.php', $url );
		$this->assertStringContainsString( 'action=wpcheckpoint_download', $url );
		$this->assertStringContainsString( 'file=logs/job-1-abcd.log', $url );
		$this->assertStringContainsString( '_wpnonce=', $url );
	}
}
