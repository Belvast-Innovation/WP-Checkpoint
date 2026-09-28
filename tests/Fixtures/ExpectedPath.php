<?php

namespace WPCheckpoint\Tests\Fixtures;

/**
 * Expected paths in tests: the only way a test joins the parts of a path it compares a result with
 * (tests/unit/Support/ExpectedPathUsageTest.php). Which of the two forms is the author's statement of what the code
 * under test returns; a path joined by hand with '/' is right on POSIX and wrong on Windows, where it went unnoticed
 * until the Windows job ran (eight times, 2026-09-17 to 09-28).
 */
final class ExpectedPath {

	/**
	 * A base followed by names joined with DIRECTORY_SEPARATOR: the base exactly as given (trailing separators
	 * aside), every '/' or '\' in the names turned into DIRECTORY_SEPARATOR. For code that appends names to a base
	 * with DIRECTORY_SEPARATOR; when it returns what the file system gives (realpath()), give the base resolved too.
	 *
	 * @param string $base     The base as the code under test was given it (a sandbox, a storage directory).
	 * @param string ...$names The names below it ('tmp/job-1.lock' or 'tmp', 'job-1.lock').
	 * @return string
	 */
	public static function native( string $base, string ...$names ): string {
		$path = rtrim( $base, '/\\' );
		foreach ( $names as $name ) {
			$path .= DIRECTORY_SEPARATOR . str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, trim( $name, '/\\' ) );
		}
		return $path;
	}

	/**
	 * The path with forward slashes only, on every platform. For code that normalises paths (Paths::normalize(), the
	 * staging layout) or names archive entries.
	 *
	 * @param string $base     The base as the test has it ('\' in it becomes '/').
	 * @param string ...$names The names below it.
	 * @return string
	 */
	public static function slashed( string $base, string ...$names ): string {
		$path = rtrim( str_replace( '\\', '/', $base ), '/' );
		foreach ( $names as $name ) {
			$path .= '/' . trim( str_replace( '\\', '/', $name ), '/' );
		}
		return $path;
	}
}
