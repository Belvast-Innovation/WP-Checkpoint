<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Report;
use WPCheckpoint\Support\TextMask;
use WPCheckpoint\Tests\Fixtures\Sandbox;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The one pipeline text goes through before it leaves the plugin or is written to a log: paths and hosts become
 * placeholders, a failure yields a fixed text, and the plugin's own code never builds a mask that leaves them out.
 */
final class TextMaskTest extends TestCase {

	/** @var string */
	private $dir = '';

	protected function tear_down(): void {
		if ( '' !== $this->dir ) {
			Sandbox::remove( $this->dir );
		}
		parent::tear_down();
	}

	private static function mask( array $paths, array $hosts = array() ): TextMask {
		return new TextMask(
			new Redactor( array( 's3cret!' ) ),
			$paths,
			$hosts,
			array(
				'paths'        => array(),
				'coarse'       => false,
				'network_root' => '',
			)
		);
	}

	public function test_a_logger_writes_placeholders_for_paths_and_hosts_and_keeps_the_rest(): void {
		$this->dir = Sandbox::make( 'text-mask' );
		$logger    = new Logger( $this->dir . '/x.log', self::mask( array( '{abspath}' => '/srv/www/site' ), array( 'shop.example.org' ) ) );
		$logger->info( 'Read /srv/www/site/wp-config.php for https://shop.example.org/wp-admin with s3cret!', array( 'at' => '/srv/www/site' ) );
		$line = (string) file_get_contents( $this->dir . '/x.log' );
		$this->assertStringContainsString( 'Read {abspath}/wp-config.php for https://{site-host}/wp-admin with [redacted]', $line );
		$this->assertStringContainsString( '"at":"{abspath}"', $line );
		$this->assertStringNotContainsString( '/srv/www/site', $line );
		$this->assertStringNotContainsString( 'shop.example.org', $line );
	}

	public function test_a_line_the_mask_cannot_mask_is_written_as_a_fixed_text_never_as_it_was(): void {
		$this->dir = Sandbox::make( 'text-mask' );
		// A path of invalid UTF-8 makes the path pattern fail (the u modifier): the mask cannot tell what to hide.
		$logger = new Logger( $this->dir . '/x.log', self::mask( array( '{x}' => "/srv/bad\xC3\x28" ) ) );
		@$logger->info( 'Read /srv/www/site/wp-config.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the pattern's compile warning, as in production (the failure is what is tested).
		$line = (string) file_get_contents( $this->dir . '/x.log' );
		$this->assertStringContainsString( Report::failure_text(), $line );
		$this->assertStringNotContainsString( '/srv/www/site', $line );
		$good = new Logger( $this->dir . '/y.log', self::mask( array( '{x}' => '/srv/other' ) ) );
		$good->info( 'Read /srv/www/site/wp-config.php' );
		$this->assertStringContainsString( 'Read /srv/www/site/wp-config.php', (string) file_get_contents( $this->dir . '/y.log' ), 'the control: a mask that works writes the line' );
	}

	public function test_a_path_that_ends_a_sentence_is_masked_and_one_before_an_extension_is_not(): void {
		$paths = array( '{x}' => '/srv/site' );
		$this->assertSame( 'ABSPATH changed from {x} to /b.', Report::mask_paths( 'ABSPATH changed from /srv/site to /b.', $paths ) );
		$this->assertSame( 'It moved to {x}.', Report::mask_paths( 'It moved to /srv/site.', $paths ) );
		$this->assertSame( 'It moved to {x}. Then', Report::mask_paths( 'It moved to /srv/site. Then', $paths ) );
		$this->assertSame( 'A copy is /srv/site.old here', Report::mask_paths( 'A copy is /srv/site.old here', $paths ), 'another file, not the path' );
	}

	public function test_a_host_that_ends_a_sentence_is_masked_and_one_before_another_label_is_not(): void {
		$mask = self::mask( array(), array( 'localhost' ) );
		$this->assertSame( 'Checked it for {site-host}.', $mask->clean( 'Checked it for localhost.' ) );
		$this->assertSame( 'Seen at {site-host}. Then', $mask->clean( 'Seen at localhost. Then' ) );
		$this->assertSame( 'Seen at {site-host}, then', $mask->clean( 'Seen at localhost, then' ), 'the control: as before' );
		$this->assertStringNotContainsString( '{site-host}', $mask->clean( 'Another host: localhost.localdomain here' ), 'another host, not the site\'s' );
	}

	public function test_the_plugins_own_code_never_builds_a_mask_without_the_installations_paths_and_hosts(): void {
		$root  = dirname( __DIR__, 3 );
		$found = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( (string) $file );
			if ( 1 === preg_match( '/TextMask::redact_only\s*\(|\bredact_only\s*\(/', $source ) && 'TextMask.php' !== $file->getFilename() ) {
				$found[] = $file->getFilename();
			}
		}
		$this->assertSame( array(), $found );
		$tests = (string) file_get_contents( $root . '/tests/unit/Support/LoggerTest.php' );
		$this->assertSame( 1, preg_match( '/TextMask::redact_only\s*\(/', $tests ), 'the control: the scan finds a use where there is one' );
	}
}
