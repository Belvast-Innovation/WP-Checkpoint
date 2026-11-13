<?php

namespace WPCheckpoint\Tests\Fixtures;

use WPCheckpoint\Support\Schema;

/**
 * The jobs table of the tests database before an integration run (tests/Fixtures/jobs-table-precheck.php): the tests
 * that drop it put it back as they found it (RunTables), so a run stopped in the middle of one of them leaves the
 * database without it, and the tests that read it from another process (a request to the test site, WP-CLI) fail in
 * the next run for a reason not their own. The table is there with every column of the schema's version this code
 * has (Schema::COLUMNS, a varchar at least as wide as defined), or the run does not start.
 */
final class JobsTableCheck {

	/**
	 * What the table lacks: array() when it is whole.
	 *
	 * @param array<string, string>|null $types Column => its type as the server says it; null when there is no table.
	 * @return string[]
	 */
	public static function problems( $types ): array {
		if ( null === $types ) {
			return array( 'the table is not there' );
		}
		$types = array_change_key_case( $types, CASE_LOWER );
		$out   = array();
		foreach ( Schema::COLUMNS as $name => $definition ) {
			if ( ! isset( $types[ $name ] ) ) {
				$out[] = 'no column ' . $name;
				continue;
			}
			if ( 1 === preg_match( '/^varchar\((\d+)\)/', $definition, $want ) && 1 === preg_match( '/^varchar\((\d+)\)/', strtolower( $types[ $name ] ), $have ) && (int) $have[1] < (int) $want[1] ) {
				$out[] = sprintf( 'column %s is varchar(%d), the schema\'s is varchar(%d)', $name, (int) $have[1], (int) $want[1] );
			}
		}
		return $out;
	}

	/**
	 * Why the run does not start, '' when the table is whole.
	 *
	 * @param string                     $table Table name.
	 * @param array<string, string>|null $types problems()'s.
	 * @return string
	 */
	public static function refusal( string $table, $types ): string {
		$problems = self::problems( $types );
		if ( array() === $problems ) {
			return '';
		}
		return "The jobs table of the tests database ({$table}) is not as schema version " . Schema::CURRENT . " makes it:\n  " . implode( "\n  ", $problems ) . "\n"
			. "A run stopped in the middle of a test that had dropped it leaves it so; the tests that read it from another process would fail. The run does not start.\n"
			. "Make it again in the tests container, then start the run again:\n"
			. "  wp eval 'require_once \"wp-content/plugins/wp-checkpoint/wp-checkpoint.php\"; echo json_encode( \\WPCheckpoint\\Support\\Schema::ensure() );'\n";
	}
}
