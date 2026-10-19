<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every write of the swap phase to the plugin's tables and the temporary tables (the final check, the swap, the
 * count, the carry, the ledger, the plan) is one of three: its statement is conditioned on the run still holding the
 * job (lock_token or the ledger's holder in the statement), it is made inside a transaction that first locked the
 * job row (Restore\JobFence::lock()), or it is listed here as an exception with the reason. A write that is none of
 * them fails this test; so does an entry that no longer matches a write. The scanner is shown to find a write.
 */
final class FencedWritesTest extends TestCase {

	const CONDITIONED = 'conditioned';
	const LOCKED      = 'locked';
	const EXCEPTION   = 'exception';

	/**
	 * What a write line looks like: a call that changes rows (or runs a statement), not a SELECT, a session setting
	 * or a transaction statement.
	 */
	const WRITE = '/->(?:write|run)\(|->rows\(\s*[\'"](?:DELETE|INSERT|UPDATE|REPLACE)\b|\$wpdb->(?:query|update|insert|delete|replace)\(/';
	const NOT   = '/->(?:run|rows)\(\s*[\'"](?:SET SESSION|START TRANSACTION|COMMIT|ROLLBACK|SELECT)\b/';

	/**
	 * The writes, by file: a substring of the line, its kind, and for an exception the reason.
	 *
	 * @var array<string, array<int, array{0: string, 1: string, 2?: string}>>
	 */
	const WRITES = array(
		'src/Jobs/SwapStep.php'       => array(
			array( '$db->run( $sql );', self::EXCEPTION, 'RENAME TABLE is DDL and carries no condition: a lease check right before it (send()); the window between the two is known (ARCHITECTURE).' ),
			array( "'DELETE FROM ' . SqlWriter::identifier( \$entry['live'] )", self::EXCEPTION, 'After the swap is made: idempotent, the same row the new holder deletes, right after a lease check.' ),
			array( "'INSERT INTO ' . SqlWriter::identifier( self::base_prefix() . 'options' )", self::EXCEPTION, 'After the swap is made: an upsert of the after-swap mark, the same the new holder writes, right after a lease check.' ),
		),
		'src/Jobs/SwapCheckStep.php'  => array(
			array( '$swap->write( $job, $attempt, $from, $batch, self::fence( $context ) );', self::EXCEPTION, 'Not a statement: SwapPlan::write(), whose statements are in a transaction that locks the job row first (registered there).' ),
		),
		'src/Restore/StateCarry.php'  => array(
			array( "'DELETE FROM ' . SqlWriter::identifier( \$temp ) . ' WHERE '", self::LOCKED ),
			array( "'INSERT INTO ' . SqlWriter::identifier( \$temp ) . ' ' . \$columns", self::LOCKED ),
			array( "VALUES ('\" . self::ACTIVE . \"', ?, 'yes')", self::LOCKED ),
			array( "SET option_value = ? WHERE option_name = '\" . self::ACTIVE . \"'", self::LOCKED ),
			array( "VALUES (?, '\" . self::ACTIVE_NETWORK . \"', ?)", self::LOCKED ),
			array( "SET meta_value = ? WHERE site_id = ? AND meta_key = '\" . self::ACTIVE_NETWORK . \"'", self::LOCKED ),
		),
		'src/Restore/Ledger.php'      => array(
			array( "'CREATE TABLE IF NOT EXISTS '", self::EXCEPTION, 'DDL, idempotent: the ledger of this job, created once.' ),
			array( 'SET l.owned = ? WHERE l.n = ? AND l.owned < 0 AND j.lock_token = ?', self::CONDITIONED ),
			array( "SET holder = ? WHERE holder <> ?'", self::EXCEPTION, 'The claim of every record by the final check (take_all()), between two lease checks.' ),
			array( '(n, chunk, pos, row_count, data_offset, transactional, holder) VALUES (?, 0, 0, 0, 0, 1, ?)', self::EXCEPTION, 'The claim of a new record (claim()): the row did not exist, and a second claimant is refused by the key.' ),
			array( "SET holder = ? WHERE n = ? AND holder = ?'", self::EXCEPTION, 'The claim of a record from its previous holder (claim()): a compare-and-set on the holder.' ),
			array( 'SET chunk = 1, pos = ?, data_offset = ?, transactional = ?, constraint_names = ? WHERE n = ? AND chunk = 0 AND holder = ?', self::CONDITIONED ),
			array( 'SET chunk = ?, pos = ?, row_count = row_count + ? WHERE n = ? AND chunk = ? AND pos = ? AND holder = ?', self::CONDITIONED ),
			array( 'SET restarting = 1, restarts = restarts + 1 WHERE n = ? AND restarting = 0 AND holder = ?', self::CONDITIONED ),
			array( 'SET chunk = 1, pos = data_offset, row_count = 0 WHERE n = ? AND restarting = 1 AND holder = ?', self::CONDITIONED ),
			array( 'SET restarting = 0 WHERE n = ? AND restarting = 1 AND chunk = 1 AND pos = data_offset AND row_count = 0 AND holder = ?', self::CONDITIONED ),
		),
		'src/Restore/SwapPlan.php'    => array(
			array( '$db->run( self::create_sql( $name ) );', self::EXCEPTION, 'DDL, idempotent: the plan table, created once.' ),
			array( "WHERE job_id = ? AND attempt <> ? ORDER BY id LIMIT", self::EXCEPTION, 'Rows of attempts no run reads any more (an attempt is numbered anew each time the check writes): a run that lost the job removes what the next one would.' ),
			array( "WHERE job_id = ? AND attempt = ? AND seq >= ?'", self::LOCKED ),
			array( '(job_id, attempt, seq, kind, live, stage, old, had_live) VALUES', self::LOCKED ),
			array( '$this->write(', self::LOCKED, 'complete(): write(), the transaction that locks the job row first.' ),
		),
		'src/Jobs/TempTableCount.php' => array(),
		'src/Restore/JobFence.php'    => array(),
	);

	/**
	 * The write lines of a source: line number => the statement's text (the call line and what follows it up to the
	 * end of the statement, at most four lines).
	 *
	 * @param string $source Source.
	 * @return array<int, string>
	 */
	public static function writes( string $source ): array {
		$lines = explode( "\n", $source );
		$out   = array();
		foreach ( $lines as $i => $line ) {
			$text = trim( $line );
			if ( '' === $text || '*' === $text[0] || 0 === strpos( $text, '//' ) || 0 === strpos( $text, '/*' ) ) {
				continue;
			}
			if ( 1 !== preg_match( self::WRITE, $line ) || 1 === preg_match( self::NOT, $line ) ) {
				continue;
			}
			$statement = $line;
			for ( $j = $i + 1; $j < min( count( $lines ), $i + 4 ) && false === strpos( $statement, ';' ); $j++ ) {
				$statement .= "\n" . $lines[ $j ];
			}
			$out[ $i + 1 ] = $statement;
		}
		return $out;
	}

	/**
	 * Every way the writes of a file fall short of the registry: messages.
	 *
	 * @param string                                                  $file    Path, for the messages.
	 * @param string                                                  $source  Source.
	 * @param array<int, array{0: string, 1: string, 2?: string}>     $entries The registry's entries for it.
	 * @return string[]
	 */
	public static function problems( string $file, string $source, array $entries ): array {
		$problems = array();
		$writes   = self::writes( $source );
		$matched  = array_fill( 0, count( $entries ), 0 );
		foreach ( $writes as $line => $statement ) {
			$hits = array();
			foreach ( $entries as $k => $entry ) {
				if ( false !== strpos( $statement, $entry[0] ) ) {
					$hits[] = $k;
				}
			}
			if ( array() === $hits ) {
				$problems[] = "$file:$line: a write that is not registered: " . trim( explode( "\n", $statement )[0] );
				continue;
			}
			if ( 1 !== count( $hits ) ) {
				$problems[] = "$file:$line: matches " . count( $hits ) . ' entries';
				continue;
			}
			$entry = $entries[ $hits[0] ];
			++$matched[ $hits[0] ];
			if ( self::CONDITIONED === $entry[1] && 1 !== preg_match( '/lock_token|holder = \?/', $statement ) ) {
				$problems[] = "$file:$line: registered as conditioned, but the statement names neither lock_token nor the holder";
			}
			if ( self::LOCKED === $entry[1] && false === strpos( $source, 'JobFence::lock(' ) ) {
				$problems[] = "$file:$line: registered as locked, but the file never locks the job row (JobFence::lock())";
			}
			if ( self::EXCEPTION === $entry[1] && '' === trim( (string) ( $entry[2] ?? '' ) ) ) {
				$problems[] = "$file:$line: an exception without a reason";
			}
		}
		foreach ( $matched as $k => $times ) {
			if ( 1 !== $times ) {
				$problems[] = "$file: the entry '" . $entries[ $k ][0] . "' matches $times writes, not one";
			}
		}
		return $problems;
	}

	public function test_every_write_of_the_swap_phase_is_conditioned_locked_or_an_exception_with_a_reason(): void {
		$root = dirname( __DIR__, 3 );
		$all  = array();
		foreach ( self::WRITES as $file => $entries ) {
			$source = (string) file_get_contents( $root . '/' . $file );
			$this->assertNotSame( '', $source, $file );
			$all = array_merge( $all, self::problems( $file, $source, $entries ) );
		}
		$this->assertSame( array(), $all );
		$found = 0;
		foreach ( self::WRITES as $file => $entries ) {
			$found += count( self::writes( (string) file_get_contents( $root . '/' . $file ) ) );
		}
		$this->assertGreaterThanOrEqual( 20, $found, 'the control: the scanner finds the writes' );
	}

	public function test_the_scanner_finds_an_unregistered_write_and_the_rules_bite(): void {
		$source = "<?php\n\$db->write( 'UPDATE t SET a = 1 WHERE n = ?', array( '1' ) );\n\$db->run( 'SET SESSION x = 1' );\n\$db->rows( 'SELECT 1' );\n";
		$this->assertSame( array( 2 ), array_keys( self::writes( $source ) ), 'a write found, a setting and a read not' );
		$this->assertStringContainsString( 'not registered', implode( "\n", self::problems( 'x.php', $source, array() ) ) );
		$this->assertStringContainsString( 'names neither lock_token nor the holder', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::CONDITIONED ) ) ) ) );
		$this->assertStringContainsString( 'never locks the job row', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::LOCKED ) ) ) ) );
		$this->assertStringContainsString( 'without a reason', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::EXCEPTION ) ) ) ) );
		$this->assertSame( array(), self::problems( 'x.php', $source, array( array( 'SET a = 1', self::EXCEPTION, 'a reason' ) ) ) );
		$this->assertStringContainsString( 'matches 0 writes', implode( "\n", self::problems( 'x.php', $source, array( array( 'SET a = 1', self::EXCEPTION, 'r' ), array( 'gone', self::EXCEPTION, 'r' ) ) ) ), 'a stale entry' );
		$fenced = "<?php\n\$db->write( 'UPDATE t AS l JOIN j ON j.id = ? SET l.a = 1 WHERE j.lock_token = ?', array() );\n";
		$this->assertSame( array(), self::problems( 'x.php', $fenced, array( array( 'SET l.a = 1', self::CONDITIONED ) ) ) );
	}
}
