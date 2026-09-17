<?php

namespace WPCheckpoint\Tests\Unit\Support;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Handlers must go through Guard::require_ajax() / require_admin_post(),
 * whose failure path stops the request. The check_* verdicts are easy to
 * misuse (a WP_Error is truthy), so their use is confined to Guard itself
 * and, for REST, to the controller base class.
 */
final class GuardUsageTest extends TestCase {

	/**
	 * Every shipped PHP file: src/ plus the two root entry files.
	 *
	 * @return array<string, string> Path relative to the plugin root => absolute path.
	 */
	private function source_files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array(
			'wp-checkpoint.php' => $root . '/wp-checkpoint.php',
			'uninstall.php'     => $root . '/uninstall.php',
		);
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ) ] = $file->getPathname();
			}
		}
		ksort( $files );
		return $files;
	}

	public function test_check_ajax_and_check_admin_post_are_only_used_inside_guard(): void {
		foreach ( $this->source_files() as $relative => $path ) {
			if ( 'src/Support/Guard.php' === $relative ) {
				continue;
			}
			$source = file_get_contents( $path );
			foreach ( array( 'check_ajax(', 'check_admin_post(', 'check_request(' ) as $needle ) {
				$this->assertStringNotContainsString( $needle, $source, "{$relative} must call Guard::require_* instead of Guard::{$needle}" );
			}
		}
	}

	public function test_check_rest_is_only_used_by_the_rest_controller_base(): void {
		foreach ( $this->source_files() as $relative => $path ) {
			if ( in_array( $relative, array( 'src/Support/Guard.php', 'src/Rest/Controller.php' ), true ) ) {
				continue;
			}
			$this->assertStringNotContainsString( 'check_rest(', file_get_contents( $path ), "{$relative} must not call Guard::check_rest() directly; extend Rest\\Controller" );
		}
	}

	public function test_scan_covers_the_known_entry_points(): void {
		$files = $this->source_files();
		$this->assertArrayHasKey( 'src/Support/Guard.php', $files );
		$this->assertArrayHasKey( 'src/Rest/Controller.php', $files );
		$this->assertArrayHasKey( 'wp-checkpoint.php', $files );
		$this->assertArrayHasKey( 'uninstall.php', $files );
		$this->assertFileExists( $files['uninstall.php'] );
		$this->assertStringContainsString( 'check_rest(', file_get_contents( $files['src/Rest/Controller.php'] ) );
	}
}
