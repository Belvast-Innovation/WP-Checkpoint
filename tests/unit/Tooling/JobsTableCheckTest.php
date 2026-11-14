<?php

namespace WPCheckpoint\Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use WPCheckpoint\Support\Schema;
use WPCheckpoint\Tests\Fixtures\JobsTableCheck;

/**
 * The check of the tests database's jobs table before an integration run: whole, or the run does not start.
 */
final class JobsTableCheckTest extends TestCase {

	/**
	 * The table as the schema makes it: each column with the type of its definition.
	 *
	 * @return array<string, string>
	 */
	private static function whole(): array {
		$types = array();
		foreach ( Schema::COLUMNS as $name => $definition ) {
			$types[ $name ] = strtolower( (string) strtok( $definition, ' ' ) );
		}
		return $types;
	}

	public function test_a_whole_table_lets_the_run_start(): void {
		$this->assertGreaterThan( 10, count( self::whole() ), 'the control: the schema\'s columns were read' );
		$this->assertSame( array(), JobsTableCheck::problems( self::whole() ) );
		$this->assertSame( '', JobsTableCheck::refusal( 'wp_wpcheckpoint_jobs', self::whole() ) );
		$this->assertSame( array(), JobsTableCheck::problems( self::whole() + array( 'a_later_column' => 'int(11)' ) ), 'more columns than this code knows (a later version\'s) are not a lack' );
		$this->assertSame( array(), JobsTableCheck::problems( array_change_key_case( self::whole(), CASE_UPPER ) ), 'however the server spells the names' );
	}

	public function test_no_table_stops_the_run_and_says_how_to_make_it_again(): void {
		$this->assertSame( array( 'the table is not there' ), JobsTableCheck::problems( null ) );
		$said = JobsTableCheck::refusal( 'wp_wpcheckpoint_jobs', null );
		$this->assertStringContainsString( 'wp_wpcheckpoint_jobs', $said );
		$this->assertStringContainsString( 'schema version ' . Schema::CURRENT, $said );
		$this->assertStringContainsString( 'The run does not start.', $said );
		$this->assertStringContainsString( 'Schema::ensure()', $said, 'what to run' );
		$this->assertStringContainsString( "\n  npx wp-env run tests-cli wp eval 'require_once \"/var/www/html/wp-content/plugins/wp-checkpoint/wp-checkpoint.php\"; echo json_encode( \\WPCheckpoint\\Support\\Schema::ensure() );'\n", $said, 'the whole command, as it is typed on the host' );
	}

	public function test_every_missing_column_and_every_narrower_one_is_named(): void {
		foreach ( array_keys( Schema::COLUMNS ) as $name ) {
			$types = self::whole();
			unset( $types[ $name ] );
			$this->assertSame( array( 'no column ' . $name ), JobsTableCheck::problems( $types ), $name );
		}
		$narrowed = 0;
		foreach ( self::whole() as $name => $type ) {
			if ( 1 !== preg_match( '/^varchar\((\d+)\)/', $type, $width ) ) {
				continue;
			}
			$types          = self::whole();
			$types[ $name ] = 'varchar(' . ( (int) $width[1] - 1 ) . ')';
			$this->assertSame( array( sprintf( 'column %s is varchar(%d), the schema\'s is varchar(%d)', $name, (int) $width[1] - 1, (int) $width[1] ) ), JobsTableCheck::problems( $types ), $name );
			$types[ $name ] = 'varchar(' . ( (int) $width[1] + 1 ) . ')';
			$this->assertSame( array(), JobsTableCheck::problems( $types ), $name . ': a wider one holds what the code writes' );
			++$narrowed;
		}
		$this->assertGreaterThan( 0, $narrowed, 'the control: the schema has varchar columns' );
	}

	public function test_the_script_is_run_by_the_integration_script_before_phpunit_and_its_refusal_stops_the_run(): void {
		$script = (string) file_get_contents( dirname( __DIR__, 3 ) . '/bin/test-integration.sh' ); // LF in every checkout (.gitattributes).
		$check  = strpos( $script, 'php "$PLUGIN/tests/Fixtures/jobs-table-precheck.php"' );
		$run    = strpos( $script, 'php "$PHPUNIT"' );
		$this->assertNotFalse( $check );
		$this->assertNotFalse( $run, 'the control: where PHPUnit is started' );
		$this->assertLessThan( $run, $check, 'before PHPUnit' );
		$this->assertMatchesRegularExpression( '/jobs-table-precheck\.php"\nPRECHECK=\$\?\nif \[ "\$PRECHECK" -eq 77 \]; then\n\texit 77\n/', $script, 'its status 77 is the run\'s' );
	}
}
