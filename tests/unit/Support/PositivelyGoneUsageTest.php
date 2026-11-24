<?php

namespace WPCheckpoint\Tests\Unit\Support;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Whether a path is positively gone is told in one place, Paths::positively_gone(), which every reader asks with the
 * path as it has it: the function itself answers for a path spelled with "." and ".." segments or through a link, and
 * says "not told" where the spelling cannot be resolved.
 *
 * Scanned (the plugin's code: src/, restore/ and the entry files, by token, so comments and other strings do not count):
 * - every call of it is Paths::positively_gone(), and every file that calls it (or hands it on as a callable) is in
 *   CALLERS with how often: a reader that is new, or gone, fails here and is looked at;
 * - no call resolves the path in its argument (realpath(), Paths::real(), OwnerMarker::real() there): a caller's own
 *   resolution is a second place that decides what the path is, and where it fails the caller would have to choose
 *   between the spelling and nothing, which is the function's to say;
 * - no other function or method of that name is declared.
 *
 * Not seen by this scan: a path resolved in a statement before the call and handed over in a variable
 * (LinkedTargets::resolve() asks about a parent it resolved, which is what it means to ask); the function
 * reached through a callable string that names the class (call_user_func( '…Paths::positively_gone' ), a string put
 * together), which the plugin's code does not do; and a reader that takes a failed lstat() or file_exists() for "not
 * there" without asking at all, which is the convention the callers follow (a failed look is no evidence), kept by
 * their own tests.
 */
final class PositivelyGoneUsageTest extends TestCase {

	/** File => array( calls, callables ). */
	const CALLERS = array(
		'src/Files/FileScanner.php'       => array( 1, 0 ), // A root that is no directory: absent only when positively gone, else "not read", which the restore asks about.
		'src/Files/ScanRoots.php'         => array( 1, 0 ), // The same, for a group that gets no root.
		'src/Jobs/HeldSite.php'           => array( 1, 0 ),
		'src/Jobs/JobRepository.php'      => array( 1, 0 ),
		'src/Jobs/PreviousAttempt.php'    => array( 1, 0 ),
		'src/Jobs/Residue.php'            => array( 1, 0 ),
		'src/Jobs/RestoreFilesPreflightStep.php' => array( 1, 0 ),
		'src/Jobs/SwapCheckStep.php'      => array( 3, 0 ),
		'src/Jobs/SwapStep.php'           => array( 1, 0 ),
		'src/Jobs/Undo.php'               => array( 1, 0 ),
		'src/Restore/LinkedTargets.php'   => array( 2, 0 ),
		'src/Restore/Loader.php'          => array( 2, 0 ),
		'src/Restore/Maintenance.php'     => array( 2, 0 ),
		'src/Restore/OtherEntries.php'    => array( 1, 0 ),
		'src/Support/CloneClassifier.php' => array( 0, 1 ),
		'src/Support/Deleter.php'         => array( 2, 0 ),
		'src/Support/Directories.php'     => array( 4, 0 ),
	);

	/**
	 * What a file's code does with the function.
	 *
	 * @param string $code PHP code.
	 * @return array{calls: int, callables: int, problems: string[]}
	 */
	private static function scan( string $code ): array {
		$tokens = array_values(
			array_filter(
				token_get_all( $code ),
				static function ( $token ): bool {
					return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
				}
			)
		);
		$text   = static function ( $token ): string {
			return is_array( $token ) ? $token[1] : $token;
		};
		$out    = array(
			'calls'     => 0,
			'callables' => 0,
			'problems'  => array(),
		);
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && 'positively_gone' === trim( $token[1], '\'"' ) ) {
				++$out['callables'];
				continue;
			}
			if ( ! is_array( $token ) || T_STRING !== $token[0] || 'positively_gone' !== $token[1] ) {
				continue;
			}
			$line   = $token[2];
			$before = $i > 0 ? $tokens[ $i - 1 ] : '';
			if ( is_array( $before ) && T_FUNCTION === $before[0] ) {
				$out['problems'][] = 'line ' . $line . ': another function of the name is declared';
				continue;
			}
			// The class as written, with its namespace where one is given (tokens apart before PHP 8, one token since).
			$class = '';
			for ( $k = $i - 2; $k >= 0 && is_array( $tokens[ $k ] ) && in_array( token_name( $tokens[ $k ][0] ), array( 'T_STRING', 'T_NS_SEPARATOR', 'T_NAME_QUALIFIED', 'T_NAME_FULLY_QUALIFIED', 'T_STATIC', 'T_VARIABLE' ), true ); $k-- ) {
				$class = $tokens[ $k ][1] . $class;
			}
			if ( ! is_array( $before ) || T_DOUBLE_COLON !== $before[0] || ! in_array( ltrim( $class, '\\' ), array( 'Paths', 'WPCheckpoint\\Support\\Paths' ), true ) ) {
				$out['problems'][] = 'line ' . $line . ': not called as Paths::positively_gone()';
				continue;
			}
			if ( '(' !== $text( $tokens[ $i + 1 ] ?? '' ) ) {
				$out['problems'][] = 'line ' . $line . ': not a call';
				continue;
			}
			++$out['calls'];
			// The arguments, to the matching parenthesis.
			$depth = 0;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$piece = $text( $tokens[ $j ] );
				if ( '(' === $piece ) {
					++$depth;
				} elseif ( ')' === $piece ) {
					--$depth;
					if ( 0 === $depth ) {
						break;
					}
				} elseif ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] && in_array( strtolower( $piece ), array( 'realpath', 'real' ), true ) && '(' === $text( $tokens[ $j + 1 ] ?? '' ) ) {
					$out['problems'][] = 'line ' . $line . ': the path is resolved before it is asked about (' . $piece . '())';
				}
			}
		}
		return $out;
	}

	public function test_the_scan_finds_what_it_looks_for(): void {
		$found = self::scan( '<?php if ( Paths::positively_gone( $path, $listings ) ) { return \WPCheckpoint\Support\Paths::positively_gone( $dir . "/x" ); }' );
		$this->assertSame( 2, $found['calls'] );
		$this->assertSame( array(), $found['problems'] );
		$found = self::scan( '<?php $gone = is_callable( $gone ) ? $gone : array( Paths::class, \'positively_gone\' );' );
		$this->assertSame( array( 0, 1, array() ), array( $found['calls'], $found['callables'], $found['problems'] ) );
		$found = self::scan( "<?php // Paths::positively_gone( realpath( \$x ) )\n/** positively_gone() */ \$a = 'it is positively_gone() that tells';" );
		$this->assertSame( array( 0, 0, array() ), array( $found['calls'], $found['callables'], $found['problems'] ), 'comments and other strings are not calls' );
		foreach (
			array(
				'<?php Paths::positively_gone( realpath( $dir ) . "/x" );' => 'the path is resolved before it is asked about (realpath())',
				'<?php Paths::positively_gone( (string) Paths::real( $dir ) );' => 'the path is resolved before it is asked about (real())',
				'<?php Paths::positively_gone( rtrim( OwnerMarker::real( $dir ), "/" ) );' => 'the path is resolved before it is asked about (real())',
				'<?php Other::positively_gone( $dir );'            => 'not called as Paths::positively_gone()',
				'<?php Other\\Paths::positively_gone( $dir );'      => 'not called as Paths::positively_gone()',
				'<?php static::positively_gone( $dir );'           => 'not called as Paths::positively_gone()',
				'<?php $this->positively_gone( $dir );'            => 'not called as Paths::positively_gone()',
				'<?php positively_gone( $dir );'                   => 'not called as Paths::positively_gone()',
				'<?php function positively_gone( $dir ) {}'        => 'another function of the name is declared',
				'<?php $f = Paths::positively_gone;'               => 'not a call',
			) as $code => $problem
		) {
			$this->assertSame( array( 'line 1: ' . $problem ), self::scan( $code )['problems'], $code );
		}
		// The control: resolving something else next to the call is not the call's argument.
		$this->assertSame( array(), self::scan( '<?php $real = realpath( $a ); if ( false === $real && Paths::positively_gone( $a ) ) {}' )['problems'] );
	}

	public function test_every_reader_asks_in_the_one_place_with_the_path_as_it_has_it(): void {
		$root  = dirname( __DIR__, 3 );
		$files = array();
		foreach ( array( 'src', 'restore' ) as $dir ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = str_replace( '\\', '/', substr( (string) $file, strlen( $root ) + 1 ) );
				}
			}
		}
		foreach ( array( 'wp-checkpoint.php', 'uninstall.php' ) as $entry ) {
			if ( is_file( $root . '/' . $entry ) ) {
				$files[] = $entry;
			}
		}
		sort( $files );
		$this->assertContains( 'src/Support/Paths.php', $files, 'the control: the scan reads the plugin\'s code' );
		$found    = array();
		$problems = array();
		foreach ( $files as $file ) {
			$scan = self::scan( (string) file_get_contents( $root . '/' . $file ) );
			if ( 'src/Support/Paths.php' === $file ) {
				// The one place: its declaration, and nothing else of the name.
				$this->assertSame( array( 'line ' . self::declared_at( $root . '/' . $file ) . ': another function of the name is declared' ), $scan['problems'], 'the control: the scan sees the declaration' );
				continue;
			}
			foreach ( $scan['problems'] as $problem ) {
				$problems[] = $file . ' ' . $problem;
			}
			if ( $scan['calls'] + $scan['callables'] > 0 ) {
				$found[ $file ] = array( $scan['calls'], $scan['callables'] );
			}
		}
		$this->assertSame( array(), $problems );
		$this->assertSame( self::CALLERS, $found, 'a reader that is new, or gone, is registered in CALLERS once it was looked at: it asks with the path as it has it, and takes "not told" in the direction that changes nothing' );
	}

	/**
	 * The line the function is declared at.
	 *
	 * @param string $file Paths.php.
	 * @return int
	 */
	private static function declared_at( string $file ): int {
		foreach ( (array) file( $file ) as $n => $line ) {
			if ( false !== strpos( (string) $line, 'function positively_gone(' ) ) {
				return $n + 1;
			}
		}
		return 0;
	}
}
