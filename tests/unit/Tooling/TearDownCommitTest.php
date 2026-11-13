<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * A test's tear-down that commits (a removal of rows the test had committed) rolls back first: a COMMIT with the
 * test's transaction still open commits whatever else the test wrote and left uncommitted, onto the site the tests
 * after it run on. Every tear_down() in tests/ whose body has a COMMIT before the parent's tear-down has a ROLLBACK
 * before that COMMIT.
 */
final class TearDownCommitTest extends TestCase {

	/**
	 * The tear-downs of a source that commit before the parent's tear-down without rolling back first.
	 *
	 * @param string $source A PHP source.
	 * @return int[] The line each begins on.
	 */
	public static function found( string $source ): array {
		$out = array();
		if ( ! preg_match_all( '/function\s+(tear_down|tearDown)\s*\(\s*\)[^{;]*\{/', $source, $heads, PREG_OFFSET_CAPTURE ) ) {
			return $out;
		}
		foreach ( $heads[0] as $i => $head ) {
			$start = $head[1] + strlen( $head[0] );
			$depth = 1;
			$at    = $start;
			$end   = strlen( $source );
			while ( $depth > 0 && $at < $end ) {
				$depth += '{' === $source[ $at ] ? 1 : ( '}' === $source[ $at ] ? -1 : 0 );
				++$at;
			}
			$body   = substr( $source, $start, $at - $start );
			$parent = strpos( $body, 'parent::' . $heads[1][ $i ][0] );
			$before = false === $parent ? $body : substr( $body, 0, $parent );
			$commit = stripos( $before, "'COMMIT'" );
			if ( false === $commit ) {
				continue;
			}
			$rollback = stripos( $before, "'ROLLBACK'" );
			if ( false === $rollback || $rollback > $commit ) {
				$out[] = substr_count( $source, "\n", 0, $head[1] ) + 1;
			}
		}
		return $out;
	}

	public function test_the_scan_finds_a_tear_down_that_commits_with_the_tests_transaction_open(): void {
		$commits  = "<?php\nclass A {\n\tpublic function tear_down(): void {\n\t\t\$wpdb->query( 'DELETE' );\n\t\t\$wpdb->query( 'COMMIT' );\n\t\tparent::tear_down();\n\t}\n}\n";
		$this->assertSame( array( 3 ), self::found( $commits ), 'the control: the scan finds one' );
		$late     = str_replace( "\t\tparent::tear_down();\n", "\t\t\$wpdb->query( 'ROLLBACK' );\n\t\tparent::tear_down();\n", $commits );
		$this->assertSame( array( 3 ), self::found( $late ), 'a rollback after the commit is no rollback before it' );
		$first    = str_replace( "\t\t\$wpdb->query( 'DELETE' );\n", "\t\t\$wpdb->query( 'ROLLBACK' );\n\t\t\$wpdb->query( 'DELETE' );\n", $commits );
		$this->assertSame( array(), self::found( $first ) );
		$after    = "<?php\nclass A {\n\tpublic function tear_down(): void {\n\t\tparent::tear_down();\n\t\t\$wpdb->query( 'COMMIT' );\n\t}\n}\n";
		$this->assertSame( array(), self::found( $after ), 'after the parent\'s tear-down the transaction is rolled back already' );
		$elsewhere = "<?php\nclass A {\n\tpublic function tear_down(): void {\n\t\tparent::tear_down();\n\t}\n\tpublic function write(): void {\n\t\t\$wpdb->query( 'COMMIT' );\n\t}\n}\n";
		$this->assertSame( array(), self::found( $elsewhere ), 'a commit in another method is not the tear-down\'s' );
	}

	public function test_no_tear_down_commits_with_the_tests_transaction_open(): void {
		$tests = dirname( __DIR__, 2 );
		$seen  = 0;
		$found = array();
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $tests, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $path => $item ) {
			if ( ! $item->isFile() || '.php' !== substr( (string) $path, -4 ) || __FILE__ === (string) $path ) {
				continue;
			}
			$source = (string) file_get_contents( (string) $path );
			$seen  += (int) ( false !== strpos( $source, "'ROLLBACK' ); // What" ) );
			foreach ( self::found( $source ) as $line ) {
				$found[] = str_replace( '\\', '/', substr( (string) $path, strlen( $tests ) + 1 ) ) . ':' . $line;
			}
		}
		$this->assertGreaterThan( 0, $seen, 'the control: the scan read the tear-downs that roll back first' );
		$this->assertSame( array(), $found, 'these tear-downs commit before the parent\'s tear-down without rolling back first' );
	}
}
