<?php

namespace WPCheckpoint\Tests\Unit\Database;

use WPCheckpoint\Database\OwnTables;
use WPCheckpoint\Jobs\TempTables;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The plugin's own run tables: by name, and by the whole grammar of the names TempTables makes, never by a
 * prefix. And every statement in src/ that creates a table is registered in OwnTables::SOURCES.
 */
final class OwnTablesTest extends TestCase {

	public function test_every_form_of_a_generated_name_is_recognised(): void {
		$names = array(
			TempTables::name( 'abcdef12', 7, '1a2b', 'posts' ),
			TempTables::name( 'abcdef12', 1234567, '1a2b', 'options' ),
			TempTables::name( 'abcdef12', 7, '1a2b', str_repeat( 'long_name_', 8 ) ), // Cut, with a hash.
			TempTables::name( 'abcdef12', 7, '1a2b', "caf\u{00E9}" ), // Replaced characters, with a hash.
			TempTables::ledger( 'abcdef12', 7, '1a2b' ),
			TempTables::old( '0123456789', 7, 'ffff', 'options' ),
			TempTables::old( '0123456789', 7, 'ffff', str_repeat( 'x', 80 ) ),
			TempTables::stray( '0123456789', 7, '0f0f', 'options' ),
			TempTables::stray( '0123456789', 1234567, '0f0f', str_repeat( 'x', 80 ) ),
		);
		foreach ( $names as $name ) {
			$this->assertTrue( OwnTables::generated( $name ), $name );
			$this->assertTrue( OwnTables::is_own( $name ), $name );
		}
	}

	public function test_a_site_whose_prefix_looks_like_them_keeps_its_tables(): void {
		foreach ( array( 'wcp_', 'w', 'wc', 'wcp', 'wcptmp', 'wcpold_', 'wcpstray', 'wcpstray_' ) as $prefix ) {
			foreach ( array( 'posts', 'options', 'wc_orders', 'cptmp_notes', 'ptmpabcdef12_notes' ) as $table ) {
				$this->assertFalse( OwnTables::is_own( $prefix . $table ), $prefix . $table );
			}
		}
		// Near misses of the grammar: no job id, job id 0, upper-case hex, short token, no run, another word.
		foreach ( array( 'wcptmpabcdef_1a2b_posts', 'wcptmpabcdef_0_1a2b_posts', 'wcptmpABCDEF_7_1a2b_posts', 'wcptmpabcde_7_1a2b_posts', 'wcptmpabcdef_7_posts', 'wcpoldabcdef_7_1a2b_', 'wcpstrayabcdef_7_1a2b_', 'wcpstrayabcdef_7_posts', 'wcpnewabcdef_7_1a2b_posts', 'wcptmpabcdef_7_1a2b_' . str_repeat( 'x', 60 ) ) as $name ) {
			$this->assertFalse( OwnTables::generated( $name ), $name );
		}
	}

	public function test_the_run_tables_are_any_installations_by_name_in_any_letter_case(): void {
		$this->assertSame( array( 'wp_wpcheckpoint_jobs', 'wp_wpcheckpoint_swap_plan', 'wp_wpcheckpoint_fence' ), OwnTables::names( 'wp_' ) );
		foreach ( array( 'wp_wpcheckpoint_jobs', 'wp_wpcheckpoint_swap_plan', 'wp_old_wpcheckpoint_swap_plan', 'wp2_wpcheckpoint_jobs', 'wpcheckpoint_jobs', 'WP_WPCHECKPOINT_JOBS', 'Wp_Old_WpCheckpoint_Swap_Plan', 'wp_wpcheckpoint_fence', 'WP2_WPCHECKPOINT_FENCE' ) as $name ) {
			$this->assertTrue( OwnTables::is_own( $name ), $name . ': this installation\'s, or a neighbour\'s in the same database (its recovery record)' );
		}
		foreach ( array( 'wp_wpcheckpoint_jobs_old', 'wp_wpcheckpoint_swap_plans', 'wp_wpcheckpoint-jobs', 'wp_posts', 'wp_wpcheckpoint_fences' ) as $name ) {
			$this->assertFalse( OwnTables::is_own( $name ), $name . ': only the names themselves' );
		}
	}

	/**
	 * Files and the string literals in them that begin a CREATE TABLE statement.
	 *
	 * @param string $code PHP code.
	 * @return string[] The literals found.
	 */
	private static function creates_tables( string $code ): array {
		$found = array();
		foreach ( token_get_all( $code ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				// Reading a table's definition is not making one.
				$text = (string) preg_replace( '/\bSHOW\s+CREATE\s+TABLE\b/i', '', ltrim( $token[1], "'\"" ) );
				// The statement, not a message about one: the table's name follows (quoted, interpolated, a sprintf or
				// prepare placeholder, concatenated after the literal ends, or a plain name and its column list, LIKE,
				// AS or SELECT), anywhere in the literal.
				if ( 1 === preg_match( '/\bCREATE\s+(?:TEMPORARY\s+)?TABLE(?:\s+IF\s+NOT\s+EXISTS)?(?:\s*[`{$]|\s*%[si]|\s*[\'"]?\z|\s+[A-Za-z_][A-Za-z0-9_]*\b(?:\s*\(|\s+(?:LIKE|AS|SELECT)\b))/i', $text ) ) {
					$found[] = $token[1];
				}
			}
		}
		return $found;
	}

	public function test_the_check_finds_a_create_table_statement_and_nothing_else(): void {
		$code = <<<'PHP'
<?php
$a = "CREATE TABLE {$table} (id int)";
$b = 'CREATE TABLE IF NOT EXISTS ' . $name;
$c = 'create temporary table x (y int)';
$d = 'The table will be created; if this persists, the database user lacks CREATE TABLE.';
$e = 'CREATE TABLE has two primary keys.';
$f = sprintf( 'CREATE TABLE %s (id int)', $t );
$g = $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $a, $b );
$h = 'CREATE TABLE copy AS SELECT * FROM src';
$i = "-- setup; CREATE TABLE `t` (id int)";
$j = 'SHOW CREATE TABLE ' . $t;
$k = 'CREATE TABLE has an item of a kind the restore does not create (';
// CREATE TABLE in a comment
/* CREATE TABLE in another */
PHP;
		$this->assertCount( 7, self::creates_tables( $code ), 'seven statements, not the messages or the comments' );
	}

	public function test_every_statement_in_src_that_creates_a_table_is_registered(): void {
		$root  = dirname( __DIR__, 3 );
		$found = array();
		$files = array( $root . '/wp-checkpoint.php', $root . '/uninstall.php' );
		foreach ( array( '/src', '/restore' ) as $dir ) {
			if ( is_dir( $root . $dir ) ) {
				foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . $dir, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
					$files[] = $file->getPathname();
				}
			}
		}
		foreach ( $files as $file ) {
			if ( 'php' === pathinfo( $file, PATHINFO_EXTENSION ) && is_file( $file ) && array() !== self::creates_tables( (string) file_get_contents( $file ) ) ) {
				$found[] = str_replace( '\\', '/', substr( $file, strlen( $root ) + 1 ) );
			}
		}
		sort( $found );
		$registered = array_keys( OwnTables::SOURCES );
		sort( $registered );
		$this->assertNotSame( array(), $found, 'the control: the check finds the statements that are there' );
		$this->assertSame( $registered, $found, 'a new table in src/ is registered in OwnTables::SOURCES (and so left out of backups), and nothing registered is gone' );
	}
}
