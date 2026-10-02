<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * How the server compares table names is read in one place, SiteTables::fold_case() (a refusal and the comparisons
 * depend on it, and a failed read must stop the restore everywhere alike); and its test override is set by tests
 * only: nothing in the plugin can set it.
 */
final class FoldCaseUsageTest extends TestCase {

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
	 * Where the server setting is read: the files whose code (comments left out) names it as a variable.
	 *
	 * @param array<string, string> $files Path => content.
	 * @return array<string, int> Path => how often.
	 */
	public static function reads( array $files ): array {
		$out = array();
		foreach ( $files as $path => $content ) {
			$count = 0;
			foreach ( token_get_all( $content ) as $token ) {
				if ( is_array( $token ) && in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) && false !== stripos( $token[1], 'lower_case_table_names' ) && ( 1 === preg_match( '/@@|\bselect\b|\bshow\s+variables\b/i', $token[1] ) || 1 === preg_match( '/\A[\'"]\s*lower_case_table_names\s*[\'"]\z/i', $token[1] ) ) ) {
					++$count;
				}
			}
			if ( $count > 0 ) {
				$out[ $path ] = $count;
			}
		}
		return $out;
	}

	/**
	 * Where the test override is written: an assignment to it anywhere but its declaration.
	 *
	 * @param array<string, string> $files Path => content.
	 * @return array<string, int> Path => how often.
	 */
	public static function overrides( array $files ): array {
		$out = array();
		foreach ( $files as $path => $content ) {
			$count = preg_match_all( '/(?<!private static )\$fold_case_in_tests\s*(?:\?\?)?=(?!=)|fold_case_in_tests[\'"]/', $content );
			if ( $count > 0 ) {
				$out[ $path ] = $count;
			}
		}
		return $out;
	}

	public function test_the_server_setting_is_read_in_site_tables_only(): void {
		$this->assertSame( array( 'src/Restore/SiteTables.php' => 1 ), self::reads( self::plugin_files() ) );
	}

	public function test_nothing_in_the_plugin_sets_the_test_override(): void {
		$files = self::plugin_files();
		$this->assertStringContainsString( 'private static $fold_case_in_tests = null;', $files['src/Restore/SiteTables.php'] ?? '', 'the control: the override is where the scan looks' );
		$this->assertSame( array(), self::overrides( $files ) );
	}

	public function test_the_scans_find_what_they_look_for(): void {
		$files = array(
			'a.php' => "<?php // @@lower_case_table_names in a comment\n\$x = \$wpdb->get_var( 'SELECT @@lower_case_table_names' );",
			'b.php' => "<?php \$s = \"SELECT @@LOWER_CASE_TABLE_NAMES\";",
			'c.php' => "<?php echo 'The server compares names without case (lower_case_table_names).';",
			'h.php' => "<?php \$x = \$wpdb->get_var( 'SELECT @@GLOBAL.lower_case_table_names' );",
			'i.php' => "<?php \$x = \$wpdb->get_row( \"SHOW VARIABLES LIKE 'lower_case_table_names'\" );",
			'd.php' => "<?php class S { private static \$fold_case_in_tests = null; }",
			'e.php' => "<?php S::\$fold_case_in_tests = true;",
			'f.php' => "<?php \$p = new ReflectionProperty( S::class, 'fold_case_in_tests' );",
			'g.php' => "<?php if ( null !== self::\$fold_case_in_tests ) {}",
			'j.php' => "<?php S::\$fold_case_in_tests ??= true;",
			'k.php' => "<?php \$x = \$wpdb->get_row( \$wpdb->prepare( 'SHOW VARIABLES LIKE %s', 'lower_case_table_names' ) );",
		);
		$this->assertSame(
			array(
				'a.php' => 1,
				'b.php' => 1,
				'h.php' => 1,
				'i.php' => 1,
				'k.php' => 1,
			),
			self::reads( $files ),
			'reads in code, any case and form; not in comments, nor the name in a message'
		);
		$this->assertSame(
			array(
				'e.php' => 1,
				'f.php' => 1,
				'j.php' => 1,
			),
			self::overrides( $files ),
			'an assignment or a reflection by name; not the declaration, not a read'
		);
	}
}
