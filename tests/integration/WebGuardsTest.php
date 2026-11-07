<?php

namespace WPCheckpoint\Tests\Integration;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobTypes;
use WPCheckpoint\Jobs\Runner;
use WPCheckpoint\Jobs\StepResult;
use WPCheckpoint\Jobs\SwapHost;
use WPCheckpoint\Jobs\SwapPause;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\TickResult;
use WPCheckpoint\Plugin;
use WPCheckpoint\Restore\Queries;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\Jobs\ClosureStep;
use WPCheckpoint\Tests\Fixtures\Jobs\FixtureJobType;
use WPCheckpoint\Tests\Fixtures\Jobs\JobTestCase;

/**
 * Every way into a part of the swap a run made without WordPress does not take (SwapStep's guards, cli_only() its
 * first statement) leaves the job to WP-CLI there: made to reach it (the method called on a swap with a SwapHost made
 * without WordPress, in a step of a job a web Runner ticks), the tick ends LEFT, with no fatal error, and the job row
 * is as it was but its lease.
 */
final class WebGuardsTest extends JobTestCase {

	/**
	 * SwapStep's methods whose first statement is its guard, with the guard's word.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function guards(): array {
		$tokens = token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Jobs/SwapStep.php' ) );
		$code   = array_values(
			array_filter(
				$tokens,
				static function ( $t ): bool {
					return ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
				}
			)
		);
		$found = array();
		foreach ( $code as $i => $t ) {
			if ( ! is_array( $t ) || T_FUNCTION !== $t[0] || ! is_array( $code[ $i + 1 ] ?? null ) ) {
				continue;
			}
			$name = $code[ $i + 1 ][1];
			for ( $j = $i + 2, $level = 0; isset( $code[ $j ] ); $j++ ) {
				if ( '(' === $code[ $j ] ) {
					++$level;
				} elseif ( ')' === $code[ $j ] && 0 === --$level ) {
					break;
				}
			}
			while ( isset( $code[ $j ] ) && '{' !== $code[ $j ] && ';' !== $code[ $j ] ) {
				++$j;
			}
			$first = array_slice( $code, $j + 1, 6 );
			if ( '{' === ( $code[ $j ] ?? '' ) && is_array( $first[0] ) && '$this' === $first[0][1] && is_array( $first[2] ) && 'cli_only' === $first[2][1] && is_array( $first[4] ) ) {
				$found[ $name ] = array( $name, trim( $first[4][1], '\'"' ) );
			}
		}
		return $found;
	}

	public function test_the_guards_are_found(): void {
		$guards = self::guards();
		$this->assertCount( 28, $guards, 'the guards when written: ' . implode( ', ', array_keys( $guards ) ) );
		foreach ( array( 'check_undone', 'ask_after_undo', 'send', 'rename_tables', 'commit', 'after', 'ask_carry', 'put_back_ended', 'undone_ended' ) as $named ) {
			$this->assertArrayHasKey( $named, $guards );
		}
		foreach ( $guards as $guard ) {
			$this->assertSame( $guard[0], $guard[1], 'each guard says its own method' );
		}
	}

	/**
	 * A value for a parameter (the guard is the first statement: none is looked at).
	 *
	 * @param \ReflectionParameter $param   Parameter.
	 * @param JobContext           $context The step's context.
	 * @return mixed
	 */
	private static function value( \ReflectionParameter $param, JobContext $context ) {
		$type = $param->getType();
		$name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
		if ( JobContext::class === $name ) {
			return $context;
		}
		if ( Queries::class === $name ) {
			return new class() implements Queries {
				public function run( string $sql ): int {
					throw new \LogicException( 'Nothing is sent before the guard: ' . $sql );
				}
				public function rows( string $sql, array $params = array() ): array {
					throw new \LogicException( 'Nothing is sent before the guard: ' . $sql );
				}
				public function write( string $sql, array $params ): int {
					throw new \LogicException( 'Nothing is sent before the guard: ' . $sql );
				}
			};
		}
		if ( $param->isDefaultValueAvailable() ) {
			return $param->getDefaultValue();
		}
		switch ( $name ) {
			case 'array':
				return array();
			case 'string':
				return '';
			case 'int':
				return 0;
			case 'float':
				return 0.0;
			case 'bool':
				return false;
			case 'Throwable':
			case 'Exception':
			case 'RuntimeException':
				return new \RuntimeException( 'Not looked at.' );
			case '':
				return null;
		}
		return ( new \ReflectionClass( $name ) )->newInstanceWithoutConstructor();
	}

	/**
	 * A tick of a run without WordPress (web, not WP-CLI) over the plugin's storage.
	 *
	 * @param int      $id    Job id.
	 * @param JobTypes $types Types.
	 * @return TickResult
	 */
	private function web_tick( int $id, JobTypes $types ): TickResult {
		$runner = new Runner(
			Plugin::instance()->jobs(),
			$types,
			new Redactor( Redactor::installation_secrets() ),
			array(
				'cli'    => false,
				'web'    => true,
				'budget' => new Budget( 10, 32 * 1048576, false ),
			)
		);
		return $this->autocommit(
			static function () use ( $runner, $id ): TickResult {
				return $runner->tick( $id, microtime( true ) );
			}
		);
	}

	/**
	 * The job row as stored.
	 *
	 * @param int $id Job id.
	 * @return array<string, string|null>
	 */
	private static function row( int $id ): array {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
		return (array) $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . JobRepository::table() . ' WHERE id = %d', $id ), ARRAY_A );
	}

	/**
	 * @dataProvider guards
	 *
	 * @param string $method The guarded method.
	 * @param string $word   Its guard's word.
	 */
	public function test_a_run_without_wordpress_that_reaches_a_guard_leaves_the_job_as_it_is( string $method, string $word ): void {
		global $wpdb;
		$swap    = new SwapStep( null, array(), SwapHost::without_wordpress( $wpdb->base_prefix, is_multisite(), 0, 'wp-checkpoint/wp-checkpoint.php' ) );
		$reached = false;
		$step    = new ClosureStep(
			SwapStep::ID,
			static function ( JobContext $context ) use ( $swap, $method, &$reached ): StepResult {
				$reached    = true;
				$reflection = new \ReflectionMethod( SwapStep::class, $method );
				$reflection->setAccessible( true );
				$values = array();
				$args   = array();
				foreach ( $reflection->getParameters() as $n => $param ) {
					$values[ $n ] = self::value( $param, $context );
					$args[]       = &$values[ $n ];
				}
				try {
					$reflection->invokeArgs( $swap, $args );
				} catch ( SwapPause $pause ) {
					return $pause->result();
				}
				throw new \LogicException( $method . ' went past its guard.' );
			}
		);
		$type  = 'web_guard_' . bin2hex( random_bytes( 3 ) );
		$types = new JobTypes( false );
		$types->add( new FixtureJobType( $type, array( $step ) ) );
		$this->register( $type, array( $step ) );
		$id = Plugin::instance()->jobs()->create( $type, self::$admin_id, array(), array() )->id;
		// As a driver finds a job that has run: running, its lease let go.
		$wpdb->update(
			JobRepository::table(),
			array(
				'status'     => Job::RUNNING,
				'attempts'   => 1,
				'started_at' => time(),
				'step'       => SwapStep::ID,
			),
			array( 'id' => $id )
		);
		$wpdb->query( 'COMMIT' );
		$before = self::row( $id );
		$result = $this->web_tick( $id, $types );
		$this->assertTrue( $reached, 'the control: the step ran' );
		$this->assertSame( TickResult::LEFT, $result->status, $result->message );
		$this->assertStringContainsString( '(' . $word . ')', (string) $result->message );
		$lease = array_flip( array( 'lock_token', 'locked_until', 'updated_at' ) );
		$this->assertSame( array_diff_key( $before, $lease ), array_diff_key( self::row( $id ), $lease ), 'the row as it was but its lease' );
		$this->assertSame( '', self::row( $id )['lock_token'], 'the lease let go' );
	}
}
