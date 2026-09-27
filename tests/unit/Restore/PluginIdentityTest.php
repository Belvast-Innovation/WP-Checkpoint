<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\PluginIdentity;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * This plugin by what its main file says, not where it is.
 */
final class PluginIdentityTest extends TestCase {

	public function test_the_header_or_the_constant_names_this_plugin(): void {
		$running = PluginIdentity::running( dirname( __DIR__, 3 ) . '/wp-checkpoint.php' );
		$this->assertSame( 'plugin header', $running->basis( "<?php\n/**\n * Plugin Name:       WP Checkpoint\n * Text Domain:       wp-checkpoint\n */\n" ) );
		$this->assertSame( 'plugin header', $running->basis( "<?php\r\n/*\r\nPlugin Name: WP Checkpoint\r\nText Domain: wp-checkpoint\r\n*/" ), 'the spellings WordPress reads' );
		$this->assertSame( 'constant', $running->basis( "<?php\n// Renamed and stripped.\ndefine( \"WPCHECKPOINT_VERSION\", '0.0.1' );\n" ) );
		foreach ( array(
			"<?php\n/**\n * Plugin Name: WP Checkpoint Pro\n * Text Domain: wp-checkpoint\n */"   => 'another plugin\'s name',
			"<?php\n/**\n * Plugin Name: WP Checkpoint\n * Text Domain: other\n */"                => 'another text domain',
			"<?php\n// Mentions WPCHECKPOINT_VERSION without defining it.\necho WPCHECKPOINT_VERSION;" => 'only used',
			''                                                                                          => 'nothing',
		) as $head => $why ) {
			$this->assertSame( '', $running->basis( $head ), $why );
		}
		$this->assertSame( '', $running->basis( str_repeat( ' ', PluginIdentity::HEAD_BYTES ) . "define( 'WPCHECKPOINT_VERSION', '1' );" ), 'past the head WordPress reads' );
	}
}
