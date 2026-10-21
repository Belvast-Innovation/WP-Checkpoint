<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Jobs\SwapStep;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Where the checks of the restored site go: WP_HOME and WP_SITEURL when wp-config.php defines them, otherwise home and
 * siteurl as the options table swapped in holds them; each address with a parameter no cache has seen.
 */
final class CheckAddressesTest extends TestCase {

	public function test_the_constants_win_over_the_restored_options_and_the_options_are_read_otherwise(): void {
		$asked = array();
		$read  = static function ( string $name ) use ( &$asked ): string {
			$asked[] = $name;
			return 'home' === $name ? 'https://restored.test/blog/' : 'https://restored.test/blog/wp';
		};
		$urls = SwapStep::addresses( null, null, $read, 'f00d' );
		$this->assertSame(
			array(
				'home'  => 'https://restored.test/blog/?wpcheckpoint_check=f00d',
				'login' => 'https://restored.test/blog/wp/wp-login.php?wpcheckpoint_check=f00d',
				'self'  => 'https://restored.test/blog/?rest_route=/wp-checkpoint/v1/self-check&wpcheckpoint_check=f00d',
			),
			$urls
		);
		$this->assertSame( array( 'home', 'siteurl' ), $asked, 'the control: both read from the options' );
		$asked = array();
		$urls  = SwapStep::addresses( 'http://constant.test', 'http://constant.test/core', $read, 'f00d' );
		$this->assertSame( 'http://constant.test/?wpcheckpoint_check=f00d', $urls['home'] );
		$this->assertSame( 'http://constant.test/core/wp-login.php?wpcheckpoint_check=f00d', $urls['login'] );
		$this->assertSame( array(), $asked, 'the constants: the options are not read' );
		$urls = SwapStep::addresses( 'http://constant.test', null, $read, 'f00d' );
		$this->assertSame( 'https://restored.test/blog/wp/wp-login.php?wpcheckpoint_check=f00d', $urls['login'], 'one constant, the other option' );
		$this->assertSame( array( 'siteurl' ), $asked );
	}

	public function test_an_address_that_is_not_http_is_none(): void {
		$read = static function (): string {
			return '';
		};
		$this->assertNull( SwapStep::addresses( null, null, $read, 'f00d' ), 'nothing read' );
		$this->assertNull( SwapStep::addresses( 'ftp://site.test', 'http://site.test', $read, 'f00d' ) );
		$this->assertNotNull( SwapStep::addresses( 'HTTP://site.test', 'https://site.test', $read, 'f00d' ), 'the control' );
	}
}
