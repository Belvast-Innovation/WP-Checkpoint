<?php

namespace WPCheckpoint\Tests\Unit\Support;

use WPCheckpoint\Tests\Fixtures\ExpectedPath;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Unit tests join the parts of an expected path only through Tests\Fixtures\ExpectedPath (native() or slashed()),
 * never by hand: a path joined with '/' is right on POSIX and wrong on Windows (and one joined with
 * DIRECTORY_SEPARATOR is wrong where the code normalises to '/'), which only the Windows job finds, after the push.
 *
 * What is checked: the expected argument (the first) of the assertions that compare strings or keys, where a value
 * that is not a string literal is joined with a literal starting with '/', with DIRECTORY_SEPARATOR, or through
 * interpolation ("{$dir}/x"), at the argument's top level or inside an array literal. A join inside a function call
 * (file_get_contents( $dir . '/x' ), ExpectedPath::native( … )) is not a comparison of paths and is left alone.
 * Not seen: an expected value built earlier and passed in as a variable.
 */
final class ExpectedPathUsageTest extends TestCase {

	/**
	 * Files whose '/'-joined expected values are not file system paths, with the reason.
	 */
	const EXEMPT = array(
		'tests/unit/Replace/EngineTest.php'        => 'URLs (https://new.example/…), not paths',
		'tests/unit/Archive/ManifestTest.php'      => 'fixture case names (valid/…, invalid/…), not paths',
		'tests/unit/Support/ExpectedPathUsageTest.php' => 'this test names the patterns in its fixtures',
	);

	/**
	 * The assertions whose first argument is an expected string or key.
	 */
	const ASSERTIONS = array(
		'assertsame',
		'assertequals',
		'assertnotsame',
		'assertnotequals',
		'assertstringstartswith',
		'assertstringendswith',
		'assertstringstartsnotwith',
		'assertstringendsnotwith',
		'assertstringcontainsstring',
		'assertstringnotcontainsstring',
		'assertcontains',
		'assertnotcontains',
		'assertarrayhaskey',
		'assertarraynothaskey',
	);

	/**
	 * Hand-made path joins in the expected arguments of a file's assertions.
	 *
	 * @param string $code PHP code.
	 * @return string[] "line: kind" for each assertion with one.
	 */
	public static function joins( string $code ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( $code ),
				static function ( $token ): bool {
					return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
				}
			)
		);
		$found = array();
		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$name = $tokens[ $i ];
			if ( ! is_array( $name ) || T_STRING !== $name[0] || ! in_array( strtolower( $name[1] ), self::ASSERTIONS, true ) || '(' !== ( $tokens[ $i + 1 ] ?? '' ) ) {
				continue;
			}
			$kinds = array();
			$stack = array( 'argument' ); // What each open bracket is: an array literal, or a call (or anything else).
			for ( $j = $i + 2; $j < $count; $j++ ) {
				$token = $tokens[ $j ];
				$text  = is_array( $token ) ? $token[1] : $token;
				if ( '(' === $text || '[' === $text ) {
					$before  = $tokens[ $j - 1 ];
					$stack[] = self::opens_array( $text, $before ) ? 'array' : 'call';
					continue;
				}
				if ( ')' === $text || ']' === $text ) {
					array_pop( $stack );
					if ( array() === $stack ) {
						break;
					}
					continue;
				}
				if ( 1 === count( $stack ) && ',' === $text ) {
					break; // The first argument ends.
				}
				if ( in_array( 'call', $stack, true ) ) {
					continue;
				}
				$kind = self::kind( $tokens, $j );
				if ( '' !== $kind ) {
					$kinds[] = $kind;
				}
			}
			if ( array() !== $kinds ) {
				$found[] = $name[2] . ': ' . implode( ', ', array_unique( $kinds ) );
			}
		}
		return $found;
	}

	/**
	 * Whether a bracket opens an array literal (array( or a [ that does not index something).
	 *
	 * @param string       $bracket '(' or '['.
	 * @param array|string $before  The token before it.
	 */
	private static function opens_array( string $bracket, $before ): bool {
		if ( '(' === $bracket ) {
			return is_array( $before ) && T_ARRAY === $before[0];
		}
		$text = is_array( $before ) ? $before[1] : $before;
		return ! ( is_array( $before ) && in_array( $before[0], array( T_VARIABLE, T_STRING ), true ) ) && ']' !== $text && ')' !== $text;
	}

	/**
	 * What kind of hand-made join starts at a token, or ''.
	 *
	 * @param array<int, array|string> $tokens Tokens.
	 * @param int                      $at     Position.
	 */
	private static function kind( array $tokens, int $at ): string {
		$token = $tokens[ $at ];
		if ( is_array( $token ) && T_STRING === $token[0] && 'DIRECTORY_SEPARATOR' === $token[1] ) {
			// Joined onto something ('.' before or after it); compared on its own it only asks which platform this is.
			return '.' === ( $tokens[ $at - 1 ] ?? '' ) || '.' === ( $tokens[ $at + 1 ] ?? '' ) ? 'DIRECTORY_SEPARATOR' : '';
		}
		if ( is_array( $token ) && T_ENCAPSED_AND_WHITESPACE === $token[0] && 0 === strpos( $token[1], '/' ) ) {
			return 'interpolated /';
		}
		$next = $tokens[ $at + 1 ] ?? null;
		if ( '.' !== $token || ! is_array( $next ) || T_CONSTANT_ENCAPSED_STRING !== $next[0] ) {
			return '';
		}
		$before = $tokens[ $at - 1 ];
		if ( is_array( $before ) && T_CONSTANT_ENCAPSED_STRING === $before[0] ) {
			return ''; // Two literals: no path from outside the test in it.
		}
		return 0 === strpos( substr( $next[1], 1, -1 ), '/' ) ? "'/'" : '';
	}

	/**
	 * Every PHP file under tests/unit.
	 *
	 * @return array<string, string> Path relative to the plugin root => absolute path.
	 */
	private static function unit_test_files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/tests/unit', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ) ] = $file->getPathname();
			}
		}
		ksort( $files );
		return $files;
	}

	public function test_expected_paths_are_joined_only_through_the_helper(): void {
		$found = array();
		foreach ( self::unit_test_files() as $relative => $path ) {
			if ( isset( self::EXEMPT[ $relative ] ) ) {
				continue;
			}
			foreach ( self::joins( (string) file_get_contents( $path ) ) as $where ) {
				$found[] = $relative . ':' . $where;
			}
		}
		$this->assertSame( array(), $found, 'Join expected paths with ExpectedPath::native() or ::slashed()' );
	}

	public function test_the_scan_finds_each_kind_of_hand_made_join(): void {
		$code = <<<'PHP'
<?php
$this->assertSame( $dir . '/job-1.lock', $lock->path() );
$this->assertSame( $dir . DIRECTORY_SEPARATOR . 'job-1.lock', $lock->path() );
$this->assertStringStartsWith( "{$dir}/logs", $file );
$this->assertSame( array( 'staged' => $root . '/plugins/a.php' ), $layout->map( 'x' ) );
$this->assertSame( [ $root . '/b' ], $list );
PHP;
		$this->assertSame(
			array( "2: '/'", '3: DIRECTORY_SEPARATOR', '4: interpolated /', "5: '/'", "6: '/'" ),
			self::joins( $code ),
			'the control: every kind is found'
		);
	}

	public function test_the_scan_leaves_the_helper_calls_and_what_is_no_join_of_paths(): void {
		$code = <<<'PHP'
<?php
$this->assertSame( ExpectedPath::native( $dir, 'job-1.lock' ), $lock->path() );
$this->assertSame( ExpectedPath::slashed( $root, 'plugins/a.php' ), $layout->map( 'x' )['staged'] );
$this->assertSame( file_get_contents( $dir . '/a' ), $read );
$this->assertSame( '/srv/wp' . '/plugins', $groups['plugins'] );
$this->assertSame( 'files/' . $name, $entry );
$this->assertSame( $expected, $dir . '/x' );
$this->assertSame( $list[ $dir . '/x' ], $value );
$this->assertSame( '\\' === DIRECTORY_SEPARATOR ? '' : 'refused', $answer );
PHP;
		$this->assertSame( array(), self::joins( $code ) );
		// The same file's helper calls, joined by hand instead: found (the control for the lines above).
		$this->assertCount( 2, self::joins( "<?php\n\$this->assertSame( \$dir . '/job-1.lock', 1 );\n\$this->assertSame( \$root . '/plugins/a.php', 2 );\n" ) );
	}

	public function test_the_helper_names_a_path_in_either_form(): void {
		$this->assertSame( 'C:/a/b/c.txt', ExpectedPath::slashed( 'C:\\a\\', 'b\\c.txt' ) );
		$this->assertSame( '/tmp/x/y/z', ExpectedPath::slashed( '/tmp/x/', 'y', '/z' ) );
		// The base as given; the names joined with the platform's separator.
		$this->assertSame( 'C:\\a' . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'c.txt', ExpectedPath::native( 'C:\\a\\', 'b/c.txt' ) );
		$this->assertSame( '/tmp/x' . DIRECTORY_SEPARATOR . 'y' . DIRECTORY_SEPARATOR . 'z', ExpectedPath::native( '/tmp/x', 'y', 'z' ) );
		$this->assertSame( ExpectedPath::native( '/tmp/x', 'y/z' ), ExpectedPath::native( '/tmp/x', 'y', 'z' ) );
	}

	public function test_the_exemptions_still_exist(): void {
		$files = self::unit_test_files();
		foreach ( array_keys( self::EXEMPT ) as $relative ) {
			$this->assertArrayHasKey( $relative, $files, 'an exemption for a file that is gone' );
		}
	}
}
