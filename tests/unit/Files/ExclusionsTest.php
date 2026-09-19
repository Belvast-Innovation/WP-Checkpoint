<?php

namespace WPCheckpoint\Tests\Unit\Files;

use WPCheckpoint\Files\Exclusions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class ExclusionsTest extends TestCase {

	public function test_defaults_cover_caches_our_storage_and_other_backup_plugins_and_nothing_else(): void {
		$x = new Exclusions();
		foreach ( array( 'wp-content/cache', 'wp-content/cache/page/index.html', 'wp-content/wp-checkpoint-a1b2c3d4e5f6/backups/x.zip', 'wp-content/updraft/log.txt', 'wp-content/ai1wm-backups/site.wpress', 'wp-content/uploads/backwpup-1234-backups/a.zip' ) as $p ) {
			$this->assertTrue( $x->excludes( $p ), $p );
		}
		foreach ( array( 'wp-content/plugins/x/node_modules/a.js', 'wp-content/themes/t/.git/config', 'wp-content/cached/a', 'wp-content/uploads/cache.jpg', 'wp-content/plugins/wp-checkpoint/wp-checkpoint.php', 'wp-content/updraftplus-addons/a.php' ) as $p ) {
			$this->assertFalse( $x->excludes( $p ), $p . ' is the user\'s to keep' );
		}
		$this->assertSame( array(), $x->invalid() );
	}

	public function test_user_globs_stay_within_segments_unless_doubled_and_match_subtrees(): void {
		$x = new Exclusions( array( 'wp-content/uploads/*.zip', 'wp-content/uploads/**/*.mp4', 'wp-content/plugins/big-?', '/wp-content/themes/old/' ), array() );
		$this->assertTrue( $x->excludes( 'wp-content/uploads/a.zip' ) );
		$this->assertFalse( $x->excludes( 'wp-content/uploads/2024/a.zip' ), '* does not cross a slash' );
		$this->assertTrue( $x->excludes( 'wp-content/uploads/2024/01/a.mp4' ), '** does' );
		$this->assertTrue( $x->excludes( 'wp-content/plugins/big-1/file.php' ), 'a matching directory excludes its subtree' );
		$this->assertFalse( $x->excludes( 'wp-content/plugins/big-10/file.php' ), '? is one character' );
		$this->assertTrue( $x->excludes( 'wp-content/themes/old/style.css' ), 'leading and trailing slashes are ignored' );
		$this->assertFalse( $x->excludes( 'wp-content/themes/older/style.css' ), 'a prefix match is not a directory match' );
		$this->assertSame( array( 'wp-content/uploads/*.zip', 'wp-content/uploads/**/*.mp4', 'wp-content/plugins/big-?', 'wp-content/themes/old' ), $x->globs() );
	}

	public function test_unusable_patterns_are_dropped_and_reported_not_silently_ignored(): void {
		$x = new Exclusions( array( '', "a\x00b", "bad\x1bname", str_repeat( 'a', 2000 ), 'fine/*' ), array() );
		$this->assertSame( array( 'fine/*' ), $x->globs() );
		$this->assertCount( 4, $x->invalid(), 'every unusable pattern is reported, the empty one included' );
		$this->assertTrue( $x->excludes( 'fine/x' ) );
		$this->assertNull( Exclusions::compile( '' ) );
		$this->assertNotNull( Exclusions::compile( 'a(b' ), 'regex metacharacters in a glob are literal' );
		$y = new Exclusions( array( 'a(b' ), array() );
		$this->assertTrue( $y->excludes( 'a(b/c' ) );
		$this->assertFalse( $y->excludes( 'ab' ) );
	}
}
