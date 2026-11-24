<?php

namespace WPCheckpoint\Tests\Unit\Files;

use WPCheckpoint\Files\ScanRoots;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which root, and so which content group, a path of the files index belongs to is decided in one place
 * (ScanRoots::prefix_of()): the pack step reads a file from the root it names, and the manifest step counts the
 * file for that root's group. Two copies of the rule could come to differ, and the manifest would then count a
 * file for another group than the one it was read from. The scan below holds the export's files to the one
 * function.
 */
final class PrefixRuleTest extends TestCase {

	/**
	 * The files that turn an archive path into a root or a group, and how often each calls the rule.
	 */
	const CALLERS = array(
		'src/Jobs/PackStep.php'      => 2, // The root the scan listed a line under; the root a line is read from.
		'src/Archive/IndexAudit.php' => 1, // The group a line is counted for.
	);

	/**
	 * Files of the export that match a path against a prefix for another purpose, how often, and why.
	 */
	const OTHER = array(
		'src/Files/ScanRoots.php' => 2, // The rule itself, and whether another root's archive path lies below a root's (resolving the roots, no line of the index).
		'src/Jobs/ExportPlan.php' => 1, // Whether a path is, or is inside, a path the review left out: no root is chosen.
	);

	public function test_the_longest_prefix_that_is_the_path_or_holds_it_wins(): void {
		$prefixes = array( 'wp-content', 'wp-content/plugins', 'wp-content/plugins/deep', 'uploads' );
		$this->assertSame( 'wp-content/plugins', ScanRoots::prefix_of( $prefixes, 'wp-content/plugins/a/a.php' ) );
		$this->assertSame( 'wp-content/plugins/deep', ScanRoots::prefix_of( $prefixes, 'wp-content/plugins/deep/a.php' ) );
		$this->assertSame( 'wp-content/plugins/deep', ScanRoots::prefix_of( array_reverse( $prefixes ), 'wp-content/plugins/deep/a.php' ), 'in any order' );
		$this->assertSame( 'wp-content', ScanRoots::prefix_of( $prefixes, 'wp-content/plugins-extra/a.php' ), 'a name that only starts alike is not under the prefix' );
		$this->assertSame( 'wp-content/plugins', ScanRoots::prefix_of( $prefixes, 'wp-content/plugins' ), 'the prefix itself' );
		$this->assertSame( 'uploads', ScanRoots::prefix_of( $prefixes, 'uploads/2026/a.jpg' ) );
		$this->assertSame( '', ScanRoots::prefix_of( $prefixes, 'uploads-old/a.jpg' ) );
		$this->assertSame( '', ScanRoots::prefix_of( $prefixes, 'wp-includes/version.php' ) );
		$this->assertSame( '', ScanRoots::prefix_of( array(), 'wp-content/a' ) );
		$this->assertSame( '', ScanRoots::prefix_of( array( '' ), 'wp-content/a' ), 'an empty prefix holds nothing' );
	}

	/**
	 * A group that is given no root is said to be absent (its directory is positively not there) or not read (it
	 * is the same directory as another group's, whose files it is backed up with): never silently "empty".
	 */
	public function test_a_group_without_a_root_is_absent_or_not_read(): void {
		$site = \WPCheckpoint\Tests\Fixtures\Sandbox::make( 'scan-roots' );
		try {
			mkdir( $site . '/wp-content/plugins', 0755, true );
			mkdir( $site . '/wp-content/uploads' );
			$dirs     = array(
				'abspath'       => $site,
				'content'       => $site . '/wp-content',
				'plugins'       => $site . '/wp-content/plugins',
				'themes'        => $site . '/wp-content/plugins',   // The same directory as the plugins'.
				'uploads'       => $site . '/wp-content/uploads',
				'mu-plugins'    => $site . '/wp-content/mu-plugins', // Not there.
				'other-content' => $site . '/wp-content',
			);
			$resolved = ScanRoots::resolve_dirs( $dirs, ScanRoots::GROUPS );
			$this->assertSame( array( 'mu-plugins' => 'absent', 'themes' => 'unread' ), $resolved['states'] );
			$this->assertSame( array( 'plugins', 'uploads', 'other-content' ), array_column( $resolved['roots'], 'group' ), 'the control: the others have their roots' );
			// The control: every group with a directory of its own has a root, and nothing is said of it here.
			$dirs['themes'] = $site . '/wp-content/themes';
			mkdir( $dirs['themes'] );
			mkdir( $dirs['mu-plugins'] );
			$this->assertSame( array(), ScanRoots::resolve_dirs( $dirs, ScanRoots::GROUPS )['states'] );
		} finally {
			\WPCheckpoint\Tests\Fixtures\Sandbox::remove( $site );
		}
	}

	/**
	 * Calls of strpos() that look for a prefix followed by "/" (the shape of the rule), and calls of the rule,
	 * in PHP source, comments left out.
	 *
	 * @return array{matches: int, calls: int}
	 */
	private static function scan( string $source ): array {
		$tokens = token_get_all( $source );
		$out    = array(
			'matches' => 0,
			'calls'   => 0,
		);
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			if ( ! is_array( $token ) || T_STRING !== $token[0] ) {
				continue;
			}
			if ( 'prefix_of' === $token[1] ) {
				// A call (ScanRoots::prefix_of( or self::prefix_of(), not the declaration.
				$before = $i - 1;
				while ( $before >= 0 && is_array( $tokens[ $before ] ) && T_WHITESPACE === $tokens[ $before ][0] ) {
					--$before;
				}
				if ( $before >= 0 && is_array( $tokens[ $before ] ) && T_DOUBLE_COLON === $tokens[ $before ][0] ) {
					++$out['calls'];
				}
				continue;
			}
			if ( ! in_array( strtolower( $token[1] ), array( 'strpos', 'stripos', 'strncmp', 'substr_compare', 'str_starts_with' ), true ) ) {
				continue;
			}
			// The arguments, to the matching parenthesis: one of them appends "/" to something.
			$depth = 0;
			$slash = false;
			$last  = '';
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( is_array( $t ) && T_WHITESPACE === $t[0] ) {
					continue;
				}
				if ( '(' === $t ) {
					++$depth;
				} elseif ( ')' === $t ) {
					if ( 0 === --$depth ) {
						break;
					}
				} elseif ( '.' === $last && is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] && in_array( $t[1], array( "'/'", '"/"' ), true ) ) {
					$slash = true; // Appended: a search for "/" alone is not a prefix match.
				}
				$last = is_array( $t ) ? $t[1] : $t;
			}
			if ( $slash ) {
				++$out['matches'];
			}
		}
		return $out;
	}

	public function test_the_scan_finds_a_copy_of_the_rule_and_a_call_of_it(): void {
		$copy = '<?php foreach ( $roots as $root ) { $prefix = (string) $root["prefix"]; if ( ( $p === $prefix || 0 === strpos( $p, $prefix . \'/\' ) ) && strlen( $prefix ) > $len ) { $best = $root; } }';
		$this->assertSame( array( 'matches' => 1, 'calls' => 0 ), self::scan( $copy ), 'the rule written out again, as the pack step had it' );
		$this->assertSame( array( 'matches' => 1, 'calls' => 0 ), self::scan( '<?php $in = 0 === stripos( $p . "/", $candidate . "/" );' ), 'in another spelling' );
		$this->assertSame( array( 'matches' => 0, 'calls' => 1 ), self::scan( '<?php $prefix = ScanRoots::prefix_of( $prefixes, $p );' ) );
		$this->assertSame( array( 'matches' => 0, 'calls' => 0 ), self::scan( '<?php // 0 === strpos( $p, $prefix . \'/\' ) and ScanRoots::prefix_of( $a, $p )' . "\n" . 'function prefix_of() {} $at = strpos( $p, "x" ); $in = false !== strpos( $p, \'/\' );' ), 'a comment, a declaration and other searches are not counted' );
	}

	public function test_the_export_decides_the_root_of_a_path_in_one_place(): void {
		$root  = dirname( __DIR__, 3 );
		$found = array();
		$calls = array();
		foreach ( array( 'src/Files', 'src/Archive', 'src/Jobs' ) as $dir ) {
			foreach ( glob( $root . '/' . $dir . '/*.php' ) ?: array() as $file ) {
				$name = $dir . '/' . basename( $file );
				// The export's side: the restore reads paths by its own rules (StagingLayout), which the manifest's counts are compared with.
				if ( 'src/Jobs' === $dir && 1 !== preg_match( '/\A(?:PackStep|ManifestStep|FileScanStep|ExportPlan|ExportJob|PreflightStep|ReviewStep|StoreStep)\.php\z/', basename( $file ) ) ) {
					continue;
				}
				$scan = self::scan( (string) file_get_contents( $file ) );
				if ( $scan['matches'] > 0 ) {
					$found[ $name ] = $scan['matches'];
				}
				if ( $scan['calls'] > 0 ) {
					$calls[ $name ] = $scan['calls'];
				}
			}
		}
		ksort( $found );
		ksort( $calls );
		$other = self::OTHER;
		ksort( $other );
		$this->assertSame( $other, $found, 'a prefix is matched against a path somewhere else than in ScanRoots::prefix_of(): call the rule, or list the place with its reason' );
		$callers = self::CALLERS;
		ksort( $callers );
		$this->assertSame( $callers, $calls, 'the callers of the rule' );
		$this->assertFileExists( $root . '/src/Jobs/ManifestStep.php', 'the scan looked at the files it names' );
	}
}
