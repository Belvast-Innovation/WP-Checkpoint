<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A test's set_up() (and set_up_before_class()) calls its parent first, before anything that could skip, return or
 * throw, so the parent's set-up and tear-down always run as a pair. This does not keep tear_down() from meeting
 * properties that were never set: PHPUnit runs tear_down() after a set_up() that stopped wherever it stopped, the
 * parent's call included. What does is a tear_down() that checks each path it was given ('' !== ...) and deletes
 * only through Sandbox or the Deleter, which refuse an empty path (TestDeletionUsageTest).
 */
final class SetUpOrderTest extends TestCase {

	/**
	 * Methods whose first statement must be the parent's call, by the name of the parent method.
	 */
	const METHODS = array(
		'set_up'              => 'set_up',
		'setup'               => 'setUp',
		'set_up_before_class' => 'set_up_before_class',
		'setupbeforeclass'    => 'setUpBeforeClass',
	);

	/**
	 * Files whose set-up still does something first, with how many methods: a file's number must be what the scan
	 * finds, so the list can only shrink.
	 */
	const PENDING = array();

	/**
	 * Every PHP file under tests/.
	 *
	 * @return array<string, string> Path relative to the plugin root => absolute path.
	 */
	private static function test_files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array();
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/tests', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ) ] = $file->getPathname();
			}
		}
		ksort( $files );
		return $files;
	}

	/**
	 * The set-up methods in PHP code whose first statement is not the parent's call to the same method.
	 *
	 * @param string $code PHP code.
	 * @return string[] Their names, one per method.
	 */
	public static function late_parents( string $code ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( $code ),
				static function ( $token ): bool {
					return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
				}
			)
		);
		$found  = array();
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] || ! is_array( $tokens[ $i + 1 ] ?? null ) || T_STRING !== $tokens[ $i + 1 ][0] ) {
				continue;
			}
			$name = strtolower( $tokens[ $i + 1 ][1] );
			if ( ! isset( self::METHODS[ $name ] ) ) {
				continue;
			}
			// The body: the first "{" after the parameters, unless a ";" comes first (abstract).
			$j = $i + 2;
			while ( $j < $count && '{' !== $tokens[ $j ] && ';' !== $tokens[ $j ] ) {
				++$j;
			}
			if ( $j >= $count || ';' === $tokens[ $j ] ) {
				continue;
			}
			// Its first statement, as text without spaces.
			$first = '';
			for ( $k = $j + 1; $k < $count && ';' !== $tokens[ $k ] && '}' !== $tokens[ $k ] && '{' !== $tokens[ $k ]; $k++ ) {
				$first .= is_array( $tokens[ $k ] ) ? $tokens[ $k ][1] : $tokens[ $k ];
			}
			$parent = 'parent::' . strtolower( self::METHODS[ $name ] ) . '()';
			if ( $k >= $count || ';' !== $tokens[ $k ] || strtolower( $first ) !== $parent ) {
				$found[] = $tokens[ $i + 1 ][1];
			}
		}
		return $found;
	}

	public function test_set_up_calls_its_parent_first(): void {
		$counts = array();
		foreach ( self::test_files() as $relative => $path ) {
			if ( 'tests/unit/Tooling/SetUpOrderTest.php' === $relative ) {
				continue;
			}
			$found = self::late_parents( (string) file_get_contents( $path ) );
			if ( array() !== $found ) {
				$counts[ $relative ] = count( $found );
			}
			$this->assertSame( self::PENDING[ $relative ] ?? 0, count( $found ), "{$relative}: " . implode( ', ', $found ) . '() calls its parent first; a file on the PENDING list lowers its number as it is changed' );
		}
		$this->assertSame( self::PENDING, $counts, 'the PENDING list is what the scan finds' );
	}

	public function test_the_scan_finds_what_it_looks_for(): void {
		$late = <<<'PHP'
<?php
class A {
	protected function set_up(): void {
		if ( PHP_OS_FAMILY === 'Windows' ) {
			$this->markTestSkipped( 'x' );
		}
		parent::set_up();
	}
	public function setUp(): void {
		$this->dir = '';
		parent::setUp();
	}
	public static function set_up_before_class(): void {
		self::skip_unless();
		parent::set_up_before_class();
	}
	public function set_up() {
		return;
	}
	public function set_up() {
		parent::tear_down();
	}
	public function set_up() {
		parent::set_up_before_class();
	}
	public function set_up() {
		parent::set_up() || x();
	}
}
PHP;
		$this->assertSame( array( 'set_up', 'setUp', 'set_up_before_class', 'set_up', 'set_up', 'set_up', 'set_up' ), self::late_parents( $late ) );
		$first = <<<'PHP'
<?php
class B {
	protected function set_up(): void {
		// A comment first is fine.
		parent::set_up();
		if ( PHP_OS_FAMILY === 'Windows' ) {
			$this->markTestSkipped( 'x' );
		}
	}
	public function setUp(): void {
		parent::setUp();
	}
	public static function set_up_before_class(): void {
		parent :: set_up_before_class ( );
	}
	abstract protected function set_up();
	public function set_up_the_fixture() {
		$this->markTestSkipped( 'not a set-up method' );
	}
}
PHP;
		$this->assertSame( array(), self::late_parents( $first ) );
		$files = self::test_files();
		foreach ( array_keys( self::PENDING ) as $relative ) {
			$this->assertArrayHasKey( $relative, $files, 'listed and there' );
		}
	}
}
