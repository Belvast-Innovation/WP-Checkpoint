<?php

namespace WPCheckpoint\Tests\Unit\Admin;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Where the plugin's pages and handlers live is decided in one place,
 * Admin\Page (on multisite: the network admin, and admin-post.php under the
 * network's own address). Any other file that builds an admin URL itself
 * would send a link or a redirect to a site's dashboard.
 */
final class AdminUrlUsageTest extends TestCase {

	const PATTERN = '/\b(?:admin_url|network_admin_url|self_admin_url|user_admin_url|get_admin_url|network_site_url)\s*\(|[\'"]wp-admin\/(?!includes\/)/'; // Not core's include files (ABSPATH . 'wp-admin/includes/…').

	/**
	 * Calls outside comments, per file.
	 *
	 * @return array<string, int>
	 */
	private static function uses(): array {
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__, 3 ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = '';
			foreach ( token_get_all( (string) file_get_contents( $file->getPathname() ) ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				$code .= is_array( $token ) ? $token[1] : $token;
			}
			$count = preg_match_all( self::PATTERN, $code );
			if ( $count > 0 ) {
				$found[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( dirname( __DIR__, 3 ) ) + 1 ) ) ] = $count;
			}
		}
		return $found;
	}

	public function test_only_the_page_builds_admin_urls(): void {
		$uses = self::uses();
		// The control: the scan finds the calls where they belong.
		$this->assertArrayHasKey( 'src/Admin/Page.php', $uses );
		$this->assertGreaterThanOrEqual( 3, $uses['src/Admin/Page.php'] );
		unset( $uses['src/Admin/Page.php'] );
		$this->assertSame( array(), $uses, 'build admin URLs through Admin\Page::base_url() / post_url()' );
	}
}
