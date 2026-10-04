<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Which storage token manages a job is answered in one place, Job::managing() (and its SQL form, Job::MANAGING_SQL):
 * a job another installation took over (held_by) keeps its storage_token, which its names are made from. Anything
 * else that compared storage_token for ownership (who runs, cancels, reclaims, warns) would treat a job taken over as
 * still the other installation's. Making names from storage_token and checking them against it stays as it is.
 *
 * The scan flags, in the plugin's code (comments left out): storage_token compared with ==, !=, ===, !==, <>; as
 * the needle of in_array() or array_search(); as an array key; and, in SQL strings, storage_token followed by =, <>,
 * != or IN.
 */
final class ManagingTokenUsageTest extends TestCase {

	/**
	 * Comparisons that are name checks, not ownership: path => [count, why].
	 */
	const NAME_CHECKS = array(
		'src/Jobs/PreviousAttempt.php' => array( 1, 'a staging root\'s name carries the token the job made it with' ),
		'src/Jobs/JobRepository.php'   => array( 2, 'take_over() and abandon_held() write only the row as read, the job started with that token (a second take-over too); who may do so is decided before, by manages() and HeldSite' ),
	);

	/**
	 * The plugin's PHP files: the entry files, src/ and restore/.
	 *
	 * @return array<string, string> Path relative to the plugin => content.
	 */
	private static function plugin_files(): array {
		$root  = dirname( __DIR__, 3 );
		$files = array();
		foreach ( array( 'wp-checkpoint.php', 'uninstall.php' ) as $entry ) {
			$files[ $entry ] = (string) file_get_contents( $root . '/' . $entry );
		}
		foreach ( array( 'src', 'restore' ) as $dir ) {
			if ( ! is_dir( $root . '/' . $dir ) ) {
				continue;
			}
			$walk = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $walk as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[ str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root ) + 1 ) ) ] = (string) file_get_contents( $file->getPathname() );
				}
			}
		}
		return $files;
	}

	/**
	 * Ownership comparisons of storage_token in code.
	 *
	 * @param string $content PHP source.
	 * @return string[] The offending code, one entry each.
	 */
	public static function comparisons( string $content ): array {
		$code    = '';
		$strings = array();
		foreach ( token_get_all( $content ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= ' ';
				continue;
			}
			if ( is_array( $token ) && in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				$strings[] = $token[1];
				$code     .= "''";
				continue;
			}
			$code .= is_array( $token ) ? $token[1] : $token;
		}
		$found = array();
		$x     = '\$\w+(?:\s*->\s*\w+\s*(?:\([^()]*\))?)*\s*->\s*storage_token\b';
		$forms = array(
			'/' . $x . '\s*(?:===|!==|==|!=|<>)/',
			'/(?:===|!==|==|!=|<>)\s*(?:\(\s*string\s*\)\s*)?' . $x . '/',
			'/\b(?:in_array|array_search)\s*\(\s*' . $x . '/',
			'/\[\s*' . $x . '\s*\]/',
		);
		foreach ( $forms as $form ) {
			if ( preg_match_all( $form, $code, $matches ) > 0 ) {
				foreach ( $matches[0] as $match ) {
					$found[] = trim( (string) preg_replace( '/\s+/', ' ', $match ) );
				}
			}
		}
		foreach ( $strings as $string ) {
			if ( preg_match_all( '/(?<![\$\w])storage_token\s*(?:=|<>|!=|\bIN\b)/i', $string, $matches ) > 0 ) {
				foreach ( $matches[0] as $match ) {
					$found[] = trim( (string) preg_replace( '/\s+/', ' ', $match ) );
				}
			}
		}
		return $found;
	}

	public function test_only_the_managing_rule_compares_storage_tokens_for_ownership(): void {
		$found = array();
		foreach ( self::plugin_files() as $path => $content ) {
			$hits = self::comparisons( $content );
			if ( array() !== $hits ) {
				$found[ $path ] = $hits;
			}
		}
		$allowed = array();
		foreach ( self::NAME_CHECKS as $path => $entry ) {
			$this->assertArrayHasKey( $path, $found, 'a name check listed that the scan no longer finds: ' . $path );
			$this->assertCount( $entry[0], $found[ $path ], $path . ': ' . $entry[1] );
			$allowed[] = $path;
		}
		$this->assertSame( array(), array_diff_key( $found, array_flip( $allowed ) ), 'compare Job::managing_token() (or Job::MANAGING_SQL), not storage_token, for who manages a job' );
	}

	/**
	 * Reads of storage_token that the scan does not flag (no comparison where it is read) but that decide something
	 * about a token, each with why it is not the managing token: path => [the code that reads it, the code that uses
	 * it, why]. Pinned: when either changes, this test fails and the reason is to be looked at again.
	 */
	const DIRECTORY_IDENTITY = array(
		array(
			'src/Jobs/JobRepository.php',
			"'SELECT storage_path, storage_token FROM '",
			'src/Support/Directories.php',
			"Paths::same_location( \$dir, \$restore['path'] ) && ! \$this->is_copied( (string) \$restore['token'] )",
			'JobRepository::unfinished_restores() gives each unfinished restore\'s storage directory with the token that directory was chosen with; Directories takes that directory back with its own token. A storage directory\'s identity, paired with its path: held_by says who took over the job, and does not change which token a directory carries.',
		),
	);

	public function test_the_reads_of_a_directorys_own_token_are_still_what_their_reason_says(): void {
		$files = self::plugin_files();
		foreach ( self::DIRECTORY_IDENTITY as $entry ) {
			$this->assertStringContainsString( $entry[1], $files[ $entry[0] ], $entry[0] . ': ' . $entry[4] );
			$this->assertStringContainsString( $entry[3], $files[ $entry[2] ], $entry[2] . ': ' . $entry[4] );
		}
	}

	public function test_the_scan_finds_each_form_and_leaves_names_alone(): void {
		$bad = <<<'PHP'
<?php
if ( $job->storage_token === $token ) {}
if ( $token !== (string) $job->storage_token ) {}
if ( in_array( $context->job()->storage_token, $held, true ) ) {}
if ( isset( $lost[ $job->storage_token ] ) ) {}
$wpdb->query( "UPDATE t SET a = 1 WHERE storage_token = %s" );
$wpdb->query( 'SELECT id FROM t WHERE storage_token IN (' . $list . ')' );
PHP;
		$this->assertCount( 6, self::comparisons( $bad ), implode( "\n", self::comparisons( $bad ) ) );
		$good = <<<'PHP'
<?php
$name          = TempTables::old( $job->storage_token, $job->id, $random, $bare );
$layout        = new StagingLayout( $groups, $context->job()->storage_token, $job->id, $random, $storage );
$storage_token = (string) $state['token'];
$row           = array( 'storage_token' => $token );
// if ( $job->storage_token === $token ) a comment.
$sql           = 'SELECT storage_path, storage_token FROM t';
if ( $job->managing_token() === $token ) {}
PHP;
		$this->assertSame( array(), self::comparisons( $good ) );
	}
}
