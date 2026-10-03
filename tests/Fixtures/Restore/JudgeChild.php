<?php

namespace WPCheckpoint\Tests\Fixtures\Restore;

use PHPUnit\Framework\Assert;

/**
 * Runs judge-child.php under an open_basedir restriction and gives back its verdicts, after asserting that the child
 * ran under exactly that restriction: the child reports the one in effect, so a caller that drops it, or a child that
 * does not set it, fails here rather than judging unrestricted and passing.
 */
final class JudgeChild {

	/**
	 * @param string $cases_file The cases (see judge-child.php), inside the restriction.
	 * @param string $basedir    The open_basedir value (not empty).
	 * @return string[] The verdicts.
	 */
	public static function verdicts( string $cases_file, string $basedir ): array {
		Assert::assertNotSame( '', $basedir );
		$output = array();
		$status = 0;
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/judge-child.php' ) . ' ' . escapeshellarg( $cases_file ) . ' ' . escapeshellarg( $basedir ) . ' 2>&1', $output, $status );
		Assert::assertSame( 0, $status, implode( "\n", $output ) );
		$result = json_decode( (string) end( $output ), true );
		Assert::assertIsArray( $result, implode( "\n", $output ) );
		Assert::assertSame( $basedir, $result['open_basedir'] ?? null, 'the child ran under the restriction given' );
		Assert::assertIsArray( $result['verdicts'] ?? null );
		return $result['verdicts'];
	}
}
