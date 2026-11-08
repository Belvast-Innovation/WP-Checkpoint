<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobRepository;
use WPCheckpoint\Jobs\JobStore;
use WPCheckpoint\Jobs\Placeholders;
use WPCheckpoint\Jobs\StoreExecutor;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\UpdateSql;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Every statement JobStore sends goes through the conversion for a connection made without WordPress (Placeholders,
 * UpdateSql): a recording executor converts each one and notes the line of JobStore it came from; every call of the
 * executor in JobStore's source must have been reached (the control: a statement added without a case here fails
 * the test). The statement by which a run takes a job is the same for every caller but the condition on who manages
 * the job.
 */
final class JobStoreTemplatesTest extends TestCase {

	public function test_every_statement_of_the_store_converts_for_a_connection_without_wordpress(): void {
		$db = new RecordingExecutor();
		self::exercise( self::store( $db ), $db );
		$this->assertSame( array(), $db->refused, 'converted: ' . implode( ' | ', $db->refused ) );
		$this->assertSame( self::call_sites(), $db->reached(), 'every statement of JobStore reached here, and no other' );
	}

	/**
	 * Writes that set updated_at but are no run's, so they leave wp_run_at as it is (Job::$wp_run_at), with why. Each
	 * must still be met (a registration that matches nothing is out of date).
	 */
	const NOT_A_RUN = array(
		'transition without the lock' => 'not a run\'s write: a retry or cancel made outside a run, without the lock token (a management statement, as JobRepository\'s own)',
	);

	/**
	 * A run in WordPress stamps wp_run_at in every write that sets updated_at (but those registered as no run's); a run
	 * without WordPress (its store given 0) never changes it: the column is either not set or set to GREATEST(it, 0).
	 */
	public function test_every_write_of_a_run_in_wordpress_stamps_wp_run_at_and_a_run_without_it_leaves_it(): void {
		$db = new RecordingExecutor();
		self::exercise( self::store( $db, 1800000123 ), $db );
		$met     = array();
		$stamped = 0;
		foreach ( $db->writes as $write ) {
			$set = self::assignments( $write[0], $write[1] );
			if ( ! array_key_exists( 'updated_at', $set ) ) {
				continue;
			}
			if ( ! array_key_exists( 'wp_run_at', $set ) && false === strpos( $write[0], 'lock_token' ) ) {
				$met['transition without the lock'] = true;
				continue;
			}
			$this->assertArrayHasKey( 'wp_run_at', $set, 'a run\'s write that sets updated_at stamps wp_run_at: ' . $write[0] );
			$this->assertSame( 1800000123, (int) $set['wp_run_at'], $write[0] );
			++$stamped;
		}
		$this->assertGreaterThanOrEqual( 8, $stamped, 'the control: the run\'s writes are seen (claims, heartbeat, record, checkpoints, transition, release)' );
		$this->assertSame( array_keys( self::NOT_A_RUN ), array_keys( $met ), 'every registration is met, and none other' );

		$db = new RecordingExecutor();
		self::exercise( self::store( $db, 0 ), $db );
		$seen = 0;
		foreach ( $db->writes as $write ) {
			$set = self::assignments( $write[0], $write[1] );
			if ( array_key_exists( 'wp_run_at', $set ) ) {
				$this->assertSame( 0, (int) $set['wp_run_at'], 'a run without WordPress leaves wp_run_at (GREATEST of it and 0): ' . $write[0] );
				$this->assertStringContainsString( 'wp_run_at = GREATEST(wp_run_at, %d)', $write[0] );
				++$seen;
			}
		}
		$this->assertGreaterThanOrEqual( 4, $seen, 'the control: the shared statements set it, to GREATEST(it, 0)' );
	}

	public function test_the_assignments_are_read_from_both_kinds_of_statement(): void {
		$this->assertSame( array( 'a' => 5, 'wp_run_at' => 7 ), self::assignments( 'UPDATE t SET a = %d, wp_run_at = GREATEST(wp_run_at, %d) WHERE id = %s', array( 5, 7, 'x' ) ) );
		$this->assertSame( array( 'b' => 'y', 'updated_at' => 3 ), self::assignments( 'UPDATE `t` SET `b` = %s, `updated_at` = %d WHERE `id` = %d', array( 'y', 3, 9 ) ) );
		$this->assertSame( array( 'c' => 2 ), self::assignments( 'UPDATE t SET x = IF(s = %s, 1, 0), c = %d WHERE id = %d', array( 'q', 2, 1 ) ), 'an expression with a placeholder is counted, not read as a value' );
	}

	/**
	 * The columns a write sets to a single placeholder (or to GREATEST(itself, a placeholder)), with the value bound.
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return array<string, mixed>
	 */
	private static function assignments( string $template, array $params ): array {
		$plain = str_replace( '`', '', $template );
		$start = stripos( $plain, ' SET ' );
		$end   = stripos( $plain, ' WHERE ' );
		$out   = array();
		if ( false === $start || false === $end ) {
			return $out;
		}
		$before = substr( $plain, 0, $start + 5 );
		$index  = preg_match_all( '/%[ds]/', $before );
		$set    = substr( $plain, $start + 5, $end - $start - 5 );
		preg_match_all( '/(?:^|,\s*)([a-z_]+) = (%[ds]|GREATEST\(\1, %d\))|%[ds]/', $set, $found, PREG_SET_ORDER );
		foreach ( $found as $match ) {
			if ( isset( $match[1] ) && '' !== $match[1] ) {
				$out[ $match[1] ] = $params[ $index ] ?? null;
			}
			++$index;
		}
		return $out;
	}

	/**
	 * Every statement of the store, once (a run's, and a retry made outside one).
	 *
	 * @param JobStore          $store Store.
	 * @param RecordingExecutor $db    Its executor.
	 * @return void
	 */
	private static function exercise( JobStore $store, RecordingExecutor $db ): void {
		$job = self::job( Job::RUNNING );

		$db->rows = array( array( self::row() ) );
		$store->find( 7 );

		list( $managing, $params ) = JobRepository::managing_condition( 'aaaaaaaaaaaa', array( 'aaaaaaaaaaaa', 'bbbbbbbbbbbb' ) );
		$db->affected              = array( 1 );
		$store->claim( 7, 'token', 120, $managing, $params );
		list( $managing, $params ) = JobRepository::managing_condition( 'aaaaaaaaaaaa', array() );
		$db->affected              = array( 1 );
		$store->claim( 7, 'token', 120, $managing, $params );
		$db->affected = array( 1 );
		$store->claim( 7, 'token', 120, \WPCheckpoint\Jobs\WebJobs::MANAGING, array( 'aaaaaaaaaaaa', 'WP Checkpoint restore 0123456789abcdef0123456789abcdef', 1799999400 ) );

		$db->affected = array( 0 );
		$db->rows     = array( array( array( 'held' => '1' ) ) ); // heartbeat() changed no row in the same second: holds_lock().
		$store->heartbeat( $job, 'token', 120 );

		$db->affected = array( 1 );
		$store->save_record( $job, 'token', 'tables', 3 );

		$db->affected = array( 1 );
		$store->save_progress( $job, 'token', 'restore_swap', array( 'phase' => 'rollback' ), 50, 'Putting the site back as it was', true, Job::SITE_CHANGING, 'WP Checkpoint restore 0123456789abcdef0123456789abcdef' );

		$entering     = self::job( Job::RUNNING, Job::SITE_UNTOUCHED );
		$db->affected = array( 1 );
		$store->save_progress( $entering, 'token', SwapStep::ID, array( 'phase' => 'enter' ), 20, '', true, Job::SITE_CHANGING );

		$swapping     = self::job( Job::RUNNING, Job::SITE_CHANGING );
		$db->affected = array( 1 );
		$db->rows     = array( array( array( 'cancel_requested' => '0' ) ) );
		$store->save_progress( $swapping, 'token', SwapStep::ID, array( 'phase' => 'committed' ), 80, '', true, Job::SITE_SWAPPED );

		$db->affected = array( 1 );
		$store->write_transition( self::job( Job::RUNNING ), Job::FAILED, 'It failed.', 'token', array( 'cursor_json' => '{"__retry_from":"x"}' ), array( '%s' ), Job::FAILURE_TEMPORARY, 0, '__retry_from' );
		$db->affected = array( 1 );
		$failed       = self::job( Job::FAILED );
		$failed->cursor = array( '__retry_from' => 'restore_swap_check' );
		$store->write_transition( $failed, Job::QUEUED, '', '', array(), array(), '', 0, '__retry_from' );

		$db->affected = array( 1 );
		$store->release( $job, 'token' );
	}

	public function test_the_control_finds_the_executor_calls_in_the_source(): void {
		$sites = self::call_sites();
		$this->assertGreaterThanOrEqual( 9, count( $sites ), 'the control: the scan finds the store\'s statements' );
		$this->assertContains( 'select', array_map( static function ( string $site ): string {
			return explode( '@', $site )[0];
		}, $sites ) );
	}

	public function test_the_statement_that_takes_a_job_differs_only_in_who_manages_it(): void {
		$store = self::store( new RecordingExecutor() );
		$cli   = $store->claim_template( '((CLI CONDITION))' );
		$web   = $store->claim_template( '((WEB CONDITION))' );
		$this->assertNotSame( $cli, $web );
		$this->assertSame( str_replace( '((CLI CONDITION))', '', $cli ), str_replace( '((WEB CONDITION))', '', $web ) );
		$this->assertSame( 1, substr_count( $cli, '((CLI CONDITION))' ), 'the condition goes in once' );
		$this->assertStringContainsString( "(lock_token = '' OR locked_until < %d)", $cli, 'the lease is part of every claim' );
	}

	/**
	 * The lines of JobStore that call the executor, as "method@line".
	 *
	 * @return string[]
	 */
	private static function call_sites(): array {
		$sites = array();
		foreach ( file( dirname( __DIR__, 3 ) . '/src/Jobs/JobStore.php' ) as $i => $line ) {
			if ( 1 === preg_match( '/\$this->db->(execute|update|select)\(/', $line, $m ) ) {
				$sites[] = $m[1] . '@' . ( $i + 1 );
			}
		}
		sort( $sites );
		return $sites;
	}

	/**
	 * A store on the executor.
	 *
	 * @param StoreExecutor $db Executor.
	 * @return JobStore
	 */
	private static function store( StoreExecutor $db, int $run_at = 1800000000 ): JobStore {
		return new JobStore(
			$db,
			'wp_wpcheckpoint_jobs',
			'wp_wpcheckpoint_fence',
			static function (): int {
				return 1800000000;
			},
			static function ( $value ) {
				return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- unit test.
			},
			static function ( string $text ): string {
				return $text;
			},
			static function ( string $text ): string {
				return $text;
			},
			static function ( array $value ): void {
				unset( $value );
			},
			static function (): bool {
				return false;
			},
			static function () use ( $run_at ): int {
				return $run_at;
			}
		);
	}

	/**
	 * A job as read.
	 *
	 * @param string $status     Status.
	 * @param int    $site_state Site state.
	 * @return Job
	 */
	private static function job( string $status, int $site_state = Job::SITE_CHANGING ): Job {
		$job              = new Job();
		$job->id          = 7;
		$job->status      = $status;
		$job->site_state  = $site_state;
		$job->lock_token  = 'token';
		$job->finished_at = Job::FAILED === $status ? 1799999000 : 0;
		return $job;
	}

	/**
	 * A row as read.
	 *
	 * @return array<string, string|null>
	 */
	private static function row(): array {
		return array(
			'id'          => '7',
			'status'      => Job::RUNNING,
			'cursor_json' => '[]',
		);
	}
}

/**
 * Converts each statement as SessionExecutor would, and notes where in JobStore it came from.
 */
final class RecordingExecutor implements StoreExecutor {

	/**
	 * Affected rows to answer, in order.
	 *
	 * @var array<int, int|null>
	 */
	public $affected = array();

	/**
	 * Rows to answer, in order.
	 *
	 * @var array<int, array<int, array<string, string|null>>|null>
	 */
	public $rows = array();

	/**
	 * Statements the conversion refused.
	 *
	 * @var string[]
	 */
	public $refused = array();

	/**
	 * Statements that change rows, in order: array( template, params ).
	 *
	 * @var array<int, array{0: string, 1: array<int, mixed>}>
	 */
	public $writes = array();

	/**
	 * Call sites reached, "method@line".
	 *
	 * @var array<string, true>
	 */
	private $sites = array();

	/**
	 * Run one statement that changes rows.
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return int|null
	 */
	public function execute( string $template, array $params ) {
		$this->convert( 'execute', $template, $params );
		$this->writes[] = array( $template, $params );
		return array_shift( $this->affected );
	}

	/**
	 * Update rows of a table.
	 *
	 * @param string               $table         Table.
	 * @param array<string, mixed> $data          Column => value.
	 * @param string[]             $formats       Formats.
	 * @param array<string, mixed> $where         Column => value.
	 * @param string[]             $where_formats Formats.
	 * @return int|null
	 */
	public function update( string $table, array $data, array $formats, array $where, array $where_formats ) {
		list( $template, $params ) = UpdateSql::template( $table, $data, $formats, $where, $where_formats );
		$this->convert( 'update', $template, $params );
		$this->writes[] = array( $template, $params );
		return array_shift( $this->affected );
	}

	/**
	 * Run one query.
	 *
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return array<int, array<string, string|null>>|null
	 */
	public function select( string $template, array $params ) {
		$this->convert( 'select', $template, $params );
		return array_shift( $this->rows );
	}

	/**
	 * The call sites reached.
	 *
	 * @return string[]
	 */
	public function reached(): array {
		$sites = array_keys( $this->sites );
		sort( $sites );
		return $sites;
	}

	/**
	 * Convert and note.
	 *
	 * @param string            $method   Executor method.
	 * @param string            $template Template.
	 * @param array<int, mixed> $params   Values.
	 * @return void
	 */
	private function convert( string $method, string $template, array $params ): void {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- where the statement came from.
			if ( isset( $frame['file'], $frame['line'] ) && 'JobStore.php' === basename( $frame['file'] ) ) {
				$this->sites[ $method . '@' . $frame['line'] ] = true;
				break;
			}
		}
		try {
			Placeholders::convert( $template, $params );
		} catch ( \InvalidArgumentException $e ) {
			$this->refused[] = $e->getMessage() . ': ' . $template;
		}
	}
}
