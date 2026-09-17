<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Check;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Report;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ReportTest extends TestCase {

	private function paths(): array {
		return array(
			'{abspath}'        => '/home/johndoe/public_html/',
			'{abspath-parent}' => '/home/johndoe',
			'{wp-content}'     => '/home/johndoe/public_html/wp-content',
			'{document-root}'  => '/home/johndoe/public_html',
			'{tmp}'            => '/tmp',
		);
	}

	public function test_report_masks_known_directories_longest_first(): void {
		$checks = array(
			new Check( 'storage.path', 'storage', 'Storage directory', '/home/johndoe/public_html/wp-content/wp-checkpoint-abc123def456', Check::OK ),
			new Check( 'php.open_basedir', 'php', 'open_basedir', '/home/johndoe/public_html:/tmp:/home/johndoe/tmp', Check::INFO ),
			new Check( 'storage.error', 'storage', 'Storage', 'unusable', Check::ERROR, 'Cannot create or write to /home/johndoe/public_html/wp-content/wp-checkpoint-abc123def456.' ),
		);
		$text = Report::text( $checks, new Redactor( array( 'abc123def456' ) ), $this->paths() );

		$this->assertStringNotContainsString( 'johndoe', $text );
		$this->assertStringNotContainsString( 'abc123def456', $text );
		$this->assertStringContainsString( '{wp-content}/wp-checkpoint-[redacted]', $text );
		$this->assertStringContainsString( 'open_basedir: {document-root}:{tmp}:{abspath-parent}/tmp', $text );
		$this->assertStringContainsString( 'Cannot create or write to {wp-content}/wp-checkpoint-[redacted].', $text );
		$this->assertStringContainsString( '[storage]', $text );
		$this->assertStringContainsString( '(ERROR)', $text );
	}

	public function test_generic_home_layouts_are_masked_without_placeholders(): void {
		$samples = array(
			'/home/alice/site/wp-config.php'          => '/home/***/site/wp-config.php',
			'/Users/bob/Sites/wp'                     => '/Users/***/Sites/wp',
			'/var/www/vhosts/example.com/httpdocs'   => '/var/www/vhosts/***/httpdocs',
			'/srv/users/carol/apps/wp'                => '/srv/users/***/apps/wp',
			'C:\\Users\\dave\\Sites\\wp'              => 'C:\\Users\\***\\Sites\\wp',
			'C:/Users/erin/Sites/wp'                  => 'C:/Users/***/Sites/wp',
			'open_basedir=/home/frank/public_html:/tmp' => 'open_basedir=/home/***/public_html:/tmp',
		);
		foreach ( $samples as $in => $expected ) {
			$this->assertSame( $expected, Report::mask_paths( $in, array() ), $in );
		}
		$this->assertSame( '/var/www/html/wp', Report::mask_paths( '/var/www/html/wp', array() ), 'ordinary paths stay' );
	}

	public function test_secrets_emails_and_urls_do_not_survive(): void {
		$checks = array(
			new Check( 'db.user', 'database', 'User', 'wp_user_9', Check::INFO ),
			new Check( 'x', 'misc', 'Note', 'contact admin@example.com, password=Hunter2!x', Check::INFO ),
		);
		$text = Report::text( $checks, new Redactor( array( 'Hunter2!x', 'wp_user_9' ) ), array(), array( 'Plugin' => '0.1.0-dev' ) );

		$this->assertStringNotContainsString( 'Hunter2!x', $text );
		$this->assertStringNotContainsString( 'wp_user_9', $text );
		$this->assertStringNotContainsString( 'admin@example.com', $text );
		$this->assertStringContainsString( 'Plugin: 0.1.0-dev', $text );
		$this->assertStringContainsString( 'Summary: 0 ok, 0 warnings, 0 errors, 2 info', $text );
	}

	public function test_site_hosts_become_placeholders_and_other_hosts_become_external(): void {
		$checks = array(
			new Check( 'loopback.rest', 'loopback', 'Loopback', 'redirected (HTTP 301)', Check::WARNING, 'The request was redirected to https://www.example.com/wp-json/wp-checkpoint/v1/probe. Check the addresses.' ),
			new Check( 'a', 'misc', 'Mixed case host', 'http://EXAMPLE.com:8080/x and Example.COM alone', Check::INFO ),
			new Check( 'b', 'misc', 'External', 'proxied via https://cdn.other-host.net/edge and ftp://user:pw@files.other.org/x', Check::INFO ),
			new Check( 'c', 'misc', 'Subdomain', 'https://blog.example.com/feed', Check::INFO ),
			new Check( 'c2', 'misc', 'Multisite sub-site', 'https://clienta.example.com/wp-admin/ and a.b.example.com', Check::INFO ),
			new Check( 'd', 'misc', 'Lookalike', 'https://notexample.com/ and https://example.com.evil.net/', Check::INFO ),
		);
		$text = Report::text( $checks, new Redactor(), array(), array(), array( 'example.com', 'www.example.com' ) );

		$this->assertStringContainsString( 'redirected to https://www.{site-host}/wp-json/wp-checkpoint/v1/probe.', $text );
		$this->assertStringContainsString( 'http://{site-host}:8080/x and {site-host} alone', $text );
		$this->assertStringContainsString( 'https://{external-host}/edge', $text );
		$this->assertStringContainsString( 'ftp://user:[redacted]@{external-host}/x', $text );
		$this->assertStringContainsString( 'https://{subdomain}.{site-host}/feed', $text );
		$this->assertStringNotContainsString( 'blog.', $text );
		$this->assertStringContainsString( 'https://{external-host}/ and https://{external-host}/', $text );
		$this->assertStringContainsString( 'https://{subdomain}.{site-host}/wp-admin/ and {subdomain}.{site-host}', $text );
		$this->assertStringNotContainsString( 'clienta', $text );
		$this->assertStringNotContainsString( 'a.b.', $text );
		$this->assertStringNotContainsStringIgnoringCase( 'example.com', $text );
		$this->assertStringNotContainsString( 'other-host.net', $text );
	}

	public function test_site_paths_are_masked_on_the_site_host_only(): void {
		$hosts = array( 'example.com' );
		$cases = array(
			'https://example.com/shop/wp-json/x'   => 'https://{site-host}/{site-path}/wp-json/x',
			'https://example.com/shop'             => 'https://{site-host}/{site-path}',
			'https://example.com/shop/'            => 'https://{site-host}/{site-path}/',
			'https://www.example.com/shop/a'       => 'https://www.{site-host}/{site-path}/a',
			'https://blog.example.com:8443/shop/a' => 'https://{subdomain}.{site-host}:8443/{site-path}/a',
			'example.com/shop/bare'                => '{site-host}/{site-path}/bare',
			'https://example.com/shopping/x'       => 'https://{site-host}/shopping/x',
			'https://example.com/wp-json/shop/x'   => 'https://{site-host}/wp-json/shop/x',
			'https://other.net/shop/x'             => 'https://{external-host}/shop/x',
		);
		foreach ( $cases as $in => $expected ) {
			$this->assertSame( $expected, Report::mask_hosts( $in, $hosts, array( '/shop/' ) ), $in );
		}

		$this->assertSame(
			'{site-host}/{site-path}/x {site-host}/{site-path}/y {site-host}/{site-path}',
			Report::mask_hosts( 'example.com/clienta/x example.com/client/y example.com/client', $hosts, array( '/client', '/clienta' ) ),
			'longest prefix first, on segment boundaries'
		);
		$this->assertSame( 'https://{site-host}/clientab/x', Report::mask_hosts( 'https://example.com/clientab/x', $hosts, array( '/client', '/clienta' ) ) );
	}

	public function test_coarse_mode_masks_every_unknown_first_segment(): void {
		$hosts = array( 'example.com' );
		$cases = array(
			'https://example.com/clienta/wp-json/x'      => 'https://{site-host}/{site-path}/wp-json/x',
			'https://www.example.com/clientb'            => 'https://www.{site-host}/{site-path}',
			'https://blog.example.com:8443/clientc/'     => 'https://{subdomain}.{site-host}:8443/{site-path}/',
			'example.com/clientd/page'                   => '{site-host}/{site-path}/page',
			'https://example.com/shop/wp-admin/'         => 'https://{site-host}/{site-path}/wp-admin/',
			'https://example.com/wp-json/wp/v2/posts'    => 'https://{site-host}/wp-json/wp/v2/posts',
			'https://example.com/wp-admin/admin.php'     => 'https://{site-host}/wp-admin/admin.php',
			'https://example.com/wp-login.php?x=1'       => 'https://{site-host}/wp-login.php?x=1',
			'https://example.com/WP-Content/uploads/a'   => 'https://{site-host}/WP-Content/uploads/a',
			'https://example.com/'                       => 'https://{site-host}/',
			'https://example.com'                        => 'https://{site-host}',
			'https://other.net/clienta/x'                => 'https://{external-host}/clienta/x',
		);
		foreach ( $cases as $in => $expected ) {
			$this->assertSame( $expected, Report::mask_hosts( $in, $hosts, array( '/shop' ), true ), $in );
		}
		$this->assertSame( 'https://{site-host}/clienta/x', Report::mask_hosts( 'https://example.com/clienta/x', $hosts, array( '/shop' ), false ), 'not coarse: unknown paths stay' );
	}

	public function test_coarse_mode_respects_a_network_root(): void {
		$hosts = array( 'example.com' );
		$cases = array(
			'https://example.com/wp/clienta/feed/'    => 'https://{site-host}/wp/{site-path}/feed/',
			'https://example.com/wp/clientb'          => 'https://{site-host}/wp/{site-path}',
			'https://example.com/wp/'                 => 'https://{site-host}/wp/',
			'https://example.com/wp'                  => 'https://{site-host}/wp',
			'https://example.com/wp/wp-json/x'        => 'https://{site-host}/wp/wp-json/x',
			'https://example.com/wpx/clienta'         => 'https://{site-host}/wpx/clienta',
			'https://example.com/other/clienta'       => 'https://{site-host}/other/clienta',
		);
		foreach ( $cases as $in => $expected ) {
			$this->assertSame( $expected, Report::mask_hosts( $in, $hosts, array(), true, '/wp/' ), $in );
		}
	}

	public function test_coarse_mode_is_announced_in_the_header(): void {
		$checks = array( new Check( 'x', 'loopback', 'Loopback', 'redirected', Check::WARNING, 'to https://example.com/clienta/x' ) );
		$text   = Report::text( $checks, new Redactor(), array(), array(), array( 'example.com' ), array(), array( 'coarse_site_paths' => true ) );
		$this->assertStringContainsString( 'Site paths: coarse (large network)', $text );
		$this->assertStringContainsString( 'cannot be inferred', $text );
		$this->assertStringContainsString( 'https://{site-host}/{site-path}/x', $text );

		$text = Report::text( $checks, new Redactor(), array(), array(), array( 'example.com' ), array() );
		$this->assertStringNotContainsString( 'Site paths: coarse', $text );
		$this->assertStringContainsString( 'https://{site-host}/clienta/x', $text );
	}

	public function test_site_on_a_subdomain_treats_the_parent_domain_as_external(): void {
		$text = Report::mask_hosts( 'https://shop.example.com/x and https://example.com/y and www.shop.example.com and SHOP.example.com', array( 'shop.example.com' ) );
		$this->assertSame( 'https://{site-host}/x and https://{external-host}/y and www.{site-host} and {site-host}', $text );
	}

	public function test_invalid_utf8_is_scrubbed_and_the_report_still_masks(): void {
		$hosts  = array( 'example.com' );
		$paths  = array( '{abspath}' => '/home/johndoe/public_html' );
		$checks = array(
			new Check( 'a', 'php', 'open_basedir', "/home/johndoe/public_html:/tmp/\xD6\xD0\xB9\xFA", Check::INFO ),           // GBK 中国
			new Check( 'b', 'server', 'Locale', "de_DE \xDCbergr\xF6\xDFe", Check::INFO ),                                      // Windows-1252 Übergröße
			new Check( 'c', 'loopback', 'Loopback', "redirected to https://www.example.com/x\xE4\xB8", Check::WARNING ),        // truncated 中
		);

		$text = Report::text( $checks, new Redactor(), $paths, array(), $hosts, array() );

		$this->assertNotSame( Report::failure_text(), $text, 'encoding problems must not withhold the report' );
		$this->assertStringContainsString( \WPCheckpoint\Support\Utf8::REPLACEMENT, $text );
		$this->assertStringContainsString( 'open_basedir: {abspath}:', $text );
		$this->assertStringNotContainsString( 'johndoe', $text );
		$this->assertStringNotContainsString( 'example.com', $text );
		$this->assertStringContainsString( 'https://www.{site-host}/x', $text );
		$this->assertSame( 1, preg_match( '//u', $text ), 'the report is valid UTF-8' );
	}

	public function test_masking_failure_withholds_the_whole_report(): void {
		$hosts  = array( 'example.com' );
		$paths  = array( '{abspath}' => '/home/johndoe/public_html' );
		$checks = array(
			new Check( 'x', 'g', 'Path', '/home/johndoe/public_html/wp-content/uploads', Check::INFO ),
			new Check( 'y', 'g', 'URL', 'https://www.example.com/shop/wp-json/ password=hunter2', Check::INFO ),
		);

		// Only a test seam can make a masking step fail now that the text is scrubbed first.
		Report::set_after_mask_paths_hook( static function (): void {
			// returns null
		} );
		try {
			$text = Report::text( $checks, new Redactor(), $paths, array(), $hosts, array( '/shop' ) );
		} finally {
			Report::set_after_mask_paths_hook( null );
		}
		$this->assertSame( Report::failure_text(), $text );
		$this->assertStringNotContainsString( 'johndoe', $text );
		$this->assertStringNotContainsString( 'example.com', $text );
		$this->assertStringNotContainsString( '/shop', $text );
		$this->assertStringNotContainsString( 'hunter2', $text );

		$bad = "\xB1\x31";
		$this->assertNull( Report::mask_paths( 'x /home/johndoe/y ' . $bad, $paths ), 'called directly, a masking step still fails closed on invalid UTF-8' );
		$this->assertNull( Report::mask_hosts( 'https://example.com/shop/x ' . $bad, $hosts, array( '/shop' ) ) );
		$this->assertNull( Report::mask_site_paths( '{site-host}/shop/x ' . $bad, array( '/shop' ) ) );

		$text = Report::text( $checks, new Redactor(), $paths, array(), $hosts, array( '/shop' ) );
		$this->assertStringContainsString( 'https://www.{site-host}/{site-path}/wp-json/', $text, 'without the seam the report is produced' );
		$this->assertStringContainsString( '{abspath}/wp-content/uploads', $text );
	}
}
