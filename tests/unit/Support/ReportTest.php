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

	public function test_site_on_a_subdomain_treats_the_parent_domain_as_external(): void {
		$text = Report::mask_hosts( 'https://shop.example.com/x and https://example.com/y and www.shop.example.com and SHOP.example.com', array( 'shop.example.com' ) );
		$this->assertSame( 'https://{site-host}/x and https://{external-host}/y and www.{site-host} and {site-host}', $text );
	}

	public function test_redaction_failure_replaces_everything(): void {
		$checks   = array( new Check( 'x', 'g', 'X', 'password=hunter2 ' . str_repeat( 'a=b&', 300 ), Check::INFO ) );
		$previous = ini_get( 'pcre.backtrack_limit' );
		ini_set( 'pcre.backtrack_limit', '1' );
		try {
			$text = Report::text( $checks, new Redactor(), array() );
		} finally {
			ini_set( 'pcre.backtrack_limit', (string) $previous );
		}
		$this->assertSame( Redactor::FAILED, $text );
	}
}
