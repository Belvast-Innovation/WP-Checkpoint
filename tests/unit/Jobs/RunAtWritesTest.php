<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\RunnerRepository;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The grace period of a run without WordPress counts from the last write of a run in WordPress (Job::$wp_run_at):
 * JobStore's statements stamp it (JobStoreTemplatesTest); the methods of RunnerRepository that JobRepository writes
 * itself (not through JobStore) either stamp it too or are registered here with why they need not, each kind asserted
 * by an integration test (WebGraceTest). A method that writes updated_at itself without either fails; a registration
 * that no longer matches (the method no longer writes updated_at itself, or now stamps wp_run_at) fails too.
 */
final class RunAtWritesTest extends TestCase {

	/**
	 * Why a method JobRepository writes itself leaves wp_run_at: "not_claimable", the job is not in a state a run
	 * without WordPress takes once it is written (failed, or not holding the site); "not_a_run", the write is no run's.
	 */
	const REGISTERED = array(
		'fail_unreadable'          => array( 'not_claimable', 'failed, and only when the job does not hold the site (site_state = 0 in the statement): a run without WordPress takes none of those' ),
		'fail_for_missing_columns' => array( 'not_claimable', 'failed, and only when the job does not hold the site (site_state = 0 in the statement, or the table has no site_state at all)' ),
		'record_blocked'           => array( 'not_a_run', 'the gate refused the tick: nothing ran. A job holding the site is refused only under a newer schema or where it is not managed (a copy sharing the database), whose refusals must not put off the site\'s own run without WordPress' ),
	);

	public function test_every_method_of_the_run_that_writes_updated_at_itself_stamps_wp_run_at_or_is_registered(): void {
		$found = self::classify( (string) file_get_contents( dirname( __DIR__, 3 ) . '/src/Jobs/JobRepository.php' ), self::interface_methods() );
		$own   = array_keys(
			array_filter(
				$found,
				static function ( string $kind ): bool {
					return 'itself' === $kind;
				}
			)
		);
		sort( $own );
		$registered = array_keys( self::REGISTERED );
		sort( $registered );
		$this->assertSame( $registered, $own, 'each method that writes updated_at itself without wp_run_at is registered, and each registration still matches one' );
		$this->assertContains( 'through_store', $found, 'the control: the methods that go through JobStore are told apart' );
		$this->assertSame( 'through_store', $found['heartbeat'] );
		$this->assertSame( 'none', $found['note_held_back'], 'it writes the progress message only' );
	}

	public function test_the_scan_tells_each_kind_apart(): void {
		$source = <<<'PHP'
<?php
class X {
	public function a() { $wpdb->update( 't', array( 'updated_at' => 1 ) ); }
	public function b() { $wpdb->query( "UPDATE t SET updated_at = %d, wp_run_at = %d" ); }
	public function c() { return $this->store()->release( $job, $token ); }
	public function d() { $this->helper(); }
	private function helper() { $wpdb->query( 'UPDATE t SET updated_at = 1' ); }
	public function e() { return 1; } // updated_at in a comment is no write.
	public function f() { return $this->write_transition( $job, 'failed' ); }
}
PHP;
		$this->assertSame(
			array(
				'a' => 'itself',
				'b' => 'stamps',
				'c' => 'through_store',
				'd' => 'itself',
				'e' => 'none',
				'f' => 'through_store',
			),
			self::classify( $source, array( 'a', 'b', 'c', 'd', 'e', 'f' ) )
		);
	}

	/**
	 * The methods of RunnerRepository.
	 *
	 * @return string[]
	 */
	private static function interface_methods(): array {
		return array_map(
			static function ( \ReflectionMethod $method ): string {
				return $method->getName();
			},
			( new \ReflectionClass( RunnerRepository::class ) )->getMethods()
		);
	}

	/**
	 * Each method's kind, from a class's source (by tokens: comments are not code): "stamps" (its body, or a method of
	 * the class it calls, names wp_run_at), "itself" (names updated_at, but not wp_run_at), "through_store" (calls
	 * $this->store() or $this->write_transition(), JobStore's), "none".
	 *
	 * @param string   $source  PHP source.
	 * @param string[] $methods Methods to classify.
	 * @return array<string, string>
	 */
	private static function classify( string $source, array $methods ): array {
		$bodies = self::bodies( $source );
		$out    = array();
		foreach ( $methods as $method ) {
			if ( ! isset( $bodies[ $method ] ) ) {
				continue;
			}
			$text = self::reach( $bodies, $method );
			if ( false !== strpos( $text, 'wp_run_at' ) ) {
				$out[ $method ] = 'stamps';
			} elseif ( false !== strpos( $text, 'updated_at' ) ) {
				$out[ $method ] = 'itself';
			} elseif ( 1 === preg_match( '/\$this->(store\(\)->|write_transition\()/', $bodies[ $method ] ) ) {
				$out[ $method ] = 'through_store';
			} else {
				$out[ $method ] = 'none';
			}
		}
		return $out;
	}

	/**
	 * A method's body and those of the methods of the class it calls ($this->x(), self::x(), static::x()), but
	 * store() and write_transition() (JobStore's, classified apart), as one text.
	 *
	 * @param array<string, string> $bodies Method => code.
	 * @param string                $method Method.
	 * @return string
	 */
	private static function reach( array $bodies, string $method ): string {
		$seen  = array( $method => true );
		$queue = array( $method );
		$text  = '';
		while ( array() !== $queue ) {
			$name  = array_shift( $queue );
			$text .= $bodies[ $name ] . "\n";
			preg_match_all( '/(?:\$this->|self::|static::)([a-z_0-9]+)\(/i', $bodies[ $name ], $calls );
			foreach ( $calls[1] as $called ) {
				if ( isset( $bodies[ $called ] ) && ! isset( $seen[ $called ] ) && ! in_array( $called, array( 'store', 'write_transition' ), true ) ) {
					$seen[ $called ] = true;
					$queue[]         = $called;
				}
			}
		}
		return $text;
	}

	/**
	 * The code of each method of a class's source (comments and doc comments left out).
	 *
	 * @param string $source PHP source.
	 * @return array<string, string>
	 */
	private static function bodies( string $source ): array {
		$tokens = token_get_all( $source );
		$out    = array();
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
				continue;
			}
			$k = $i + 1;
			while ( $k < $count && is_array( $tokens[ $k ] ) && T_WHITESPACE === $tokens[ $k ][0] ) {
				++$k;
			}
			if ( ! is_array( $tokens[ $k ] ) || T_STRING !== $tokens[ $k ][0] ) {
				continue; // A closure.
			}
			$name = $tokens[ $k ][1];
			while ( $k < $count && '{' !== $tokens[ $k ] && ';' !== $tokens[ $k ] ) {
				++$k;
			}
			if ( $k >= $count || ';' === $tokens[ $k ] ) {
				continue; // Abstract.
			}
			$level = 0;
			$code  = '';
			for ( ; $k < $count; $k++ ) {
				$t = $tokens[ $k ];
				if ( '{' === $t || ( is_array( $t ) && in_array( $t[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$level;
				} elseif ( '}' === $t ) {
					--$level;
				}
				if ( ! is_array( $t ) || ! in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
					$code .= is_array( $t ) ? $t[1] : $t;
				}
				if ( 0 === $level ) {
					break;
				}
			}
			$out[ $name ] = $code;
			$i            = $k;
		}
		return $out;
	}

	public function test_the_repository_is_the_one_scanned(): void {
		$this->assertTrue( ( new \ReflectionClass( JobRepository::class ) )->implementsInterface( RunnerRepository::class ) );
	}
}
