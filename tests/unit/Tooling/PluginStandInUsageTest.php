<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The integration tests' restores read one stand-in for the running plugin, given in one place
 * (tests/Fixtures/Restore/PluginStandIn.php): no test goes around it unless its class declares USES_REAL_PLUGIN_DIR
 * (with the reason). Scanned in tests/integration and tests/Fixtures, comments left out:
 * - "plugin_dir" and "plugin_main" as keys of a step's parts: only in PluginStandIn, a declaring class, and the files
 *   in KEYS (counted, with the reason): a key given elsewhere bypasses the stand-in for that step;
 * - the filter wpcheckpoint_job_types: only in PluginStandIn (a test that removed or replaced it would go around it);
 * - one of the three steps that read the running plugin run other than through a registry: its private methods
 *   invoked by reflection, run() called on one made with new: only in a declaring class;
 * - PluginCopy::make(): only in PluginStandIn (and PluginCopy itself): a copy of a test's own is another way around;
 * - the declaring classes: exactly DECLARED, each with a reason.
 * The runtime half (a real running directory given to a step through a registry fails the test) is PluginStandIn's;
 * its controls are in PluginStandInCheckTest. Known limit: run() reached through a variable not assigned from new in
 * the same file, or nested deeper than three parentheses in the new expression, is not seen; the tests have neither.
 */
final class PluginStandInUsageTest extends TestCase {

	/** The steps that read the running plugin. */
	const STEPS = '(?:RestoreFilesPreflightStep|FileStagingStep|SwapCheckStep)';

	/** Files that give a step a plugin directory of their own: path => [count, why]. */
	const KEYS = array(
		'integration/RestoreFilesPreflightTest.php' => array( 6, 'synthetic plugin trees of a set size and count: the preflight\'s space and entry limits' ),
		'integration/PluginStandInCheckTest.php'    => array( 12, 'the controls of PluginStandIn itself: reads the parts it gave, gives a synthetic and the real directory' ),
	);

	/** The classes that declare USES_REAL_PLUGIN_DIR. */
	const DECLARED = array( 'integration/RealPluginDirCountTest.php' );

	/** Where the stand-in is given, and PluginCopy itself. */
	const HOME = array( 'Fixtures/Restore/PluginStandIn.php', 'Fixtures/Restore/PluginCopy.php' );

	/**
	 * A source without its comments.
	 */
	private static function code( string $source ): string {
		$out = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}
		return $out;
	}

	/**
	 * What the rules find in the sources (path relative to tests/ => source).
	 *
	 * @param array<string, string> $sources Sources.
	 * @param array<string, array{0: int, 1: string}> $keys Files allowed keys of their own (as KEYS).
	 * @param string[] $declared The declaring files expected (as DECLARED).
	 * @return string[]
	 */
	public static function findings( array $sources, array $keys, array $declared ): array {
		$found = array();
		$seen  = array();
		foreach ( $sources as $path => $source ) {
			$code = self::code( $source );
			$home = in_array( $path, self::HOME, true );
			if ( 1 === preg_match( '/\bconst\s+USES_REAL_PLUGIN_DIR\s*=\s*([\'"])(.*?)\1\s*;/s', $code, $m ) ) {
				$seen[] = $path;
				if ( '' === trim( $m[2] ) ) {
					$found[] = $path . ': declares USES_REAL_PLUGIN_DIR without a reason';
				}
			}
			$declares = in_array( $path, $seen, true );
			$count    = preg_match_all( '/[\'"]plugin_(?:dir|main)[\'"]\s*(?:=>|\])/', $code );
			if ( 0 < $count && ! $home && ! $declares ) {
				$allowed = $keys[ $path ][0] ?? 0;
				if ( $count !== $allowed ) {
					$found[] = sprintf( '%s: %d plugin_dir / plugin_main keys, %d registered in KEYS', $path, $count, $allowed );
				}
			}
			if ( ! $home && false !== strpos( $code, 'wpcheckpoint_job_types' ) ) {
				$found[] = $path . ': uses the filter wpcheckpoint_job_types';
			}
			if ( ! $home && 1 === preg_match( '/PluginCopy\s*::\s*make\s*\(/', $code ) ) {
				$found[] = $path . ': makes a PluginCopy of its own';
			}
			if ( ! $declares ) {
				if ( 1 === preg_match( '/new\s+\\\\?ReflectionMethod\s*\(\s*[\w\\\\]*?' . self::STEPS . '\b/', $code ) ) {
					$found[] = $path . ': invokes a method of a step that reads the running plugin by reflection';
				}
				$nested = '\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)';
				if ( 1 === preg_match( '/new\s+[\w\\\\]*?' . self::STEPS . '\s*' . $nested . '\s*\)\s*->\s*run\s*\(/', $code ) ) {
					$found[] = $path . ': runs a step that reads the running plugin directly';
				}
				preg_match_all( '/\$(\w+)\s*=\s*new\s+[\w\\\\]*?' . self::STEPS . '\s*\(/', $code, $vars );
				foreach ( array_unique( $vars[1] ) as $var ) {
					if ( 1 === preg_match( '/\$' . $var . '\s*->\s*run\s*\(/', $code ) ) {
						$found[] = $path . ': runs a step that reads the running plugin directly ($' . $var . ')';
					}
				}
			}
		}
		foreach ( $keys as $path => $entry ) {
			if ( ! isset( $sources[ $path ] ) ) {
				$found[] = $path . ': in KEYS, not there';
			}
			if ( '' === trim( $entry[1] ) ) {
				$found[] = $path . ': in KEYS without a reason';
			}
		}
		sort( $seen );
		$want = $declared;
		sort( $want );
		if ( $seen !== $want ) {
			$found[] = 'the declaring classes are ' . implode( ', ', $seen ) . '; DECLARED says ' . implode( ', ', $want );
		}
		return $found;
	}

	/**
	 * The sources scanned: tests/integration and tests/Fixtures.
	 *
	 * @return array<string, string>
	 */
	private static function sources(): array {
		$tests = dirname( __DIR__, 2 );
		$out   = array();
		foreach ( array( 'integration', 'Fixtures' ) as $dir ) {
			$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $tests . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $items as $path => $item ) {
				if ( $item->isFile() && '.php' === substr( (string) $path, -4 ) ) {
					$out[ str_replace( '\\', '/', substr( (string) $path, strlen( $tests ) + 1 ) ) ] = str_replace( "\r\n", "\n", (string) file_get_contents( (string) $path ) );
				}
			}
		}
		ksort( $out );
		return $out;
	}

	public function test_no_test_goes_around_the_stand_in(): void {
		$sources = self::sources();
		// The controls: the scan reads the files it is about.
		$this->assertArrayHasKey( 'Fixtures/Restore/PluginStandIn.php', $sources );
		$this->assertArrayHasKey( 'integration/RestoreFilesPreflightTest.php', $sources );
		$this->assertArrayHasKey( 'integration/RealPluginDirCountTest.php', $sources );
		$this->assertSame( array(), self::findings( $sources, self::KEYS, self::DECLARED ) );
	}

	/**
	 * Each rule finds its own case, and lets the allowed forms through.
	 */
	public function test_each_way_around_is_found(): void {
		$ok    = "<?php\nfinal class FineTest {\n\t// 'plugin_dir' => in a comment, wpcheckpoint_job_types, PluginCopy::make(), ( new SwapCheckStep() )->run( \$c )\n\tpublic function t() {\n\t\t\$s = new SwapCheckStep( null, array( 'sizes' => 1 ) );\n\t\t\$s->id();\n\t\t\$this->register( 'x', array( new FileStagingStep( array( 'read' => 1 ) ) ) );\n\t}\n}\n";
		$cases = array(
			'a key'                => "<?php\n\$s = new FileStagingStep( array( 'plugin_dir' => \$d ) );\n",
			'a key assigned'       => "<?php\n\$p['plugin_main'] = \$f;\n",
			'the filter'           => "<?php\nremove_all_filters( 'wpcheckpoint_job_types' );\n",
			'a copy of its own'    => "<?php\n\$d = PluginCopy::make();\n",
			'reflection'           => "<?php\n\$m = new \\ReflectionMethod( \\WPCheckpoint\\Jobs\\RestoreFilesPreflightStep::class, 'plugin_bytes' );\n",
			'run on new'           => "<?php\n( new SwapCheckStep( null, array( 'a' => f( 1 ) ) ) )->run( \$ctx );\n",
			'run on a variable'    => "<?php\n\$step = new \\WPCheckpoint\\Jobs\\FileStagingStep();\n\$step->run( \$ctx );\n",
			'a reason left out'    => "<?php\nfinal class T {\n\tconst USES_REAL_PLUGIN_DIR = ' ';\n}\n",
		);
		$this->assertSame( array(), self::findings( array( 'integration/FineTest.php' => $ok ), array(), array() ), 'the allowed forms' );
		foreach ( $cases as $name => $source ) {
			$this->assertNotSame( array(), self::findings( array( 'integration/WayTest.php' => $source ), array(), 'a reason left out' === $name ? array( 'integration/WayTest.php' ) : array() ), $name );
		}

		// The same keys, registered with their count; a declaring class may use them, and reflection.
		$keyed = $cases['a key'];
		$this->assertSame( array(), self::findings( array( 'integration/WayTest.php' => $keyed ), array( 'integration/WayTest.php' => array( 1, 'why' ) ), array() ) );
		$this->assertNotSame( array(), self::findings( array( 'integration/WayTest.php' => $keyed . $keyed ), array( 'integration/WayTest.php' => array( 1, 'why' ) ), array() ), 'one more than registered' );
		$this->assertNotSame( array(), self::findings( array(), array( 'integration/GoneTest.php' => array( 1, 'why' ) ), array() ), 'registered and gone' );
		$declaring = "<?php\nfinal class RealTest {\n\tconst USES_REAL_PLUGIN_DIR = 'why';\n}\n" . substr( $cases['reflection'], 5 ) . substr( $keyed, 5 );
		$this->assertSame( array(), self::findings( array( 'integration/RealTest.php' => $declaring ), array(), array( 'integration/RealTest.php' ) ) );
		$this->assertNotSame( array(), self::findings( array( 'integration/RealTest.php' => $declaring ), array(), array() ), 'a declaring class not in DECLARED' );
		$this->assertNotSame( array(), self::findings( array( 'integration/FineTest.php' => $ok ), array(), array( 'integration/FineTest.php' ) ), 'DECLARED names a class that does not declare' );
		// Where the stand-in is given, all of it is allowed.
		$this->assertSame( array(), self::findings( array( 'Fixtures/Restore/PluginStandIn.php' => $cases['the filter'] . substr( $cases['a copy of its own'], 5 ) . substr( $keyed, 5 ) ), array(), array() ) );
	}
}
