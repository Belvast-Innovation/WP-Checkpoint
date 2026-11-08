<?php
/**
 * A run of a restore's rollback made without WordPress: its assembly.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Support\Report;
use WPCheckpoint\Support\TextMask;
use WPCheckpoint\Support\Utf8;

defined( 'ABSPATH' ) || exit;

/**
 * Everything a run without WordPress is made of, put together in one place (the entries of b2 call tick()): its own
 * connection (ImportSession, from wp-config.php's settings), the jobs' storage for it (WebJobs), the restore as such
 * a run sees it (WebRestoreJob over a SwapStep with a SwapHost made without WordPress), a registry of job types
 * without filters, and the Runner made for it: "web", not WP-CLI, its budget of BUDGET_SECONDS and a lease
 * LEASE_MARGIN longer, untranslated texts, PlainJson, no WordPress default anywhere (its clean, PHP log line, log
 * mask and memory limit are given here). Nothing here reads the request.
 */
final class WebRun {

	/**
	 * The budget of such a run, in seconds (by the wall clock).
	 */
	const BUDGET_SECONDS = 10;

	/**
	 * The lease is the budget and this, in seconds.
	 */
	const LEASE_MARGIN = 30;

	/**
	 * Memory a run may add, in bytes.
	 */
	const BUDGET_BYTES = 32 * 1048576;

	/**
	 * Seconds such a run's connection attempt may take: a visitor waits for it.
	 */
	const CONNECT_SECONDS = 3;

	/**
	 * Seconds a read from the server may take: a statement's lock wait (SwapStep::LOCK_WAIT) and five more.
	 */
	const READ_SECONDS = SwapStep::LOCK_WAIT + 5;

	/**
	 * The database settings and the table prefix (wp-config.php's $table_prefix, Credentials::get( 'prefix' )).
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * The table prefix, as the settings hold it.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * The WordPress directory.
	 *
	 * @var string
	 */
	private $abspath;

	/**
	 * Whether the site is a network.
	 *
	 * @var bool
	 */
	private $multisite;

	/**
	 * This plugin's basename.
	 *
	 * @var string
	 */
	private $plugin;

	/**
	 * Told what a run did not take on, could not tell, or left to WP-CLI: function( string $line ): void.
	 *
	 * @var callable
	 */
	private $note;

	/**
	 * Flushes the object cache here, or null when it cannot be done (the rollback then stops before its flush).
	 *
	 * @var callable|null
	 */
	private $flush;

	/**
	 * Test seams: "type" (the id the restore is registered under), "parts" (the swap's parts, SwapStep). The entries of
	 * b2 give none.
	 *
	 * @var array<string, mixed>
	 */
	private $seams;

	/**
	 * The pace of runs held up on a job (WebPace): MemoryPace unless the entries give where it is kept.
	 *
	 * @var WebPace
	 */
	private $pace;

	/**
	 * The site's names the masks hide: the hosts the entries give (the request's), and once the job is read those every
	 * table of its plan holds (WebJobs::site_names()).
	 *
	 * @var array{hosts: string[], paths: string[], coarse: bool}
	 */
	private $names = array(
		'hosts'  => array(),
		'paths'  => array(),
		'coarse' => false,
	);

	/**
	 * Constructor.
	 *
	 * @param Credentials          $credentials The database settings and the table prefix.
	 * @param string               $abspath     The WordPress directory.
	 * @param bool                 $multisite   Whether the site is a network.
	 * @param string               $plugin      This plugin's basename.
	 * @param callable             $note        function( string $line ): void.
	 * @param callable|null        $flush       function(): mixed, or null.
	 * @param array<string, mixed> $seams Test seams ("type", "parts"); none outside the tests.
	 * @param WebPace|null         $pace        Where the pace of held-up runs is kept; in this object when null (MemoryPace).
	 * @param string[]             $hosts       Host names the entries know to be the site's (the request's), masked in
	 *                                          everything such a run says.
	 */
	public function __construct( Credentials $credentials, string $abspath, bool $multisite, string $plugin, callable $note, $flush = null, array $seams = array(), $pace = null, array $hosts = array() ) {
		$this->credentials = $credentials;
		$this->prefix      = $credentials->get( 'prefix' );
		$this->abspath     = $abspath;
		$this->multisite   = $multisite;
		$this->plugin      = $plugin;
		$this->note        = $note;
		$this->flush       = is_callable( $flush ) ? $flush : null;
		$this->seams       = $seams;
		$this->pace        = $pace instanceof WebPace ? $pace : new MemoryPace();
		$this->names       = array(
			'hosts'  => array_values( array_filter( array_map( 'strval', $hosts ) ) ),
			'paths'  => array(),
			'coarse' => false,
		);
	}

	/**
	 * One tick of a job, as a run without WordPress makes it. A job such a run was held up on (TickResult::$held_up)
	 * waits as the pace says (WebPace) before it is tried again, untouched meanwhile (WAITING, its seconds); a held-up
	 * run makes the next wait longer, any other (but BUSY and UNAVAILABLE) starts it over. A connection that cannot be
	 * opened, or a job that cannot be read, is UNAVAILABLE: nothing is written, the note says why.
	 *
	 * @param int        $job_id     Job id.
	 * @param float|null $started_at When the request started (its budget counts from there).
	 * @return TickResult
	 */
	public function tick( int $job_id, $started_at = null ): TickResult {
		$wait = $this->pace->wait( $job_id, time() );
		if ( $wait > 0 ) {
			// Held up before: not tried again yet (nothing is opened, read or written).
			return new TickResult( TickResult::WAITING, $wait, null, sprintf( 'Job %d was held up: the next try is in %d seconds.', $job_id, $wait ) );
		}
		$result          = $this->paced( $job_id, $this->tick_now( $job_id, $started_at ), time() );
		$result->message = self::clean( (string) $result->message, new Redactor( Redactor::installation_secrets() ), array( '{abspath}' => rtrim( $this->abspath, '/\\' ) ), $this->names['hosts'], $this->names['paths'], $this->names['coarse'] ); // As everything such a run says.
		return $result;
	}

	/**
	 * One tick of the job that holds the site with a maintenance file of this mark (WebEntry's: the data file names the
	 * mark, not the job): MISSING when no job does, LEFT when more than one row says so, UNAVAILABLE when the jobs cannot
	 * be read; otherwise tick().
	 *
	 * @param string     $mark       The mark.
	 * @param float|null $started_at When the request started.
	 * @return TickResult
	 * @throws NotHere Rethrown unchanged, never taken as the database being unavailable.
	 */
	public function tick_mark( string $mark, $started_at = null ): TickResult {
		try {
			$session = $this->connection();
		} catch ( \Throwable $e ) {
			return $this->unavailable( 0, 'its connection to the database could not be opened', $e );
		}
		try {
			$id = $this->jobs( $session )->held_by_mark( $mark );
		} catch ( NotHere $e ) {
			throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged: the Runner's, never this run's to swallow.
		} catch ( \Throwable $e ) {
			return $this->unavailable( 0, 'the jobs could not be read', $e );
		} finally {
			$session->close();
		}
		if ( 0 === $id ) {
			$this->note( 'No job holds the site with this maintenance file; nothing was done (wp wpcheckpoint job list says which restore put it up).' );
			return new TickResult( TickResult::MISSING, -1, null, 'No job holds the site with this maintenance file.' );
		}
		if ( $id < 0 ) {
			$this->note( 'More than one job says it holds the site with this maintenance file; none is taken on here (wp wpcheckpoint job list says which).' );
			return new TickResult( TickResult::LEFT, -1, null, 'More than one job holds the site with this maintenance file.' );
		}
		return $this->tick( $id, $started_at );
	}

	/**
	 * What a tick's result does to the pace: held up, the next wait is longer (and the result says it); BUSY (another run
	 * holds the job) says nothing of it; anything else starts it over.
	 *
	 * @param int        $job_id Job id.
	 * @param TickResult $result The tick's result.
	 * @param int        $now    Unix time.
	 * @return TickResult
	 */
	public function paced( int $job_id, TickResult $result, int $now ): TickResult {
		if ( TickResult::LEFT === $result->status && $result->held_up ) {
			$result->retry_after = $this->pace->held_up( $job_id, $now );
			$this->note( sprintf( 'Job %d is held up (%s); the next try is in %d seconds.', $job_id, $result->message, $result->retry_after ) );
		} elseif ( TickResult::BUSY !== $result->status && TickResult::UNAVAILABLE !== $result->status ) {
			$this->pace->cleared( $job_id );
		}
		return $result;
	}

	/**
	 * One tick of a job, now.
	 *
	 * @param int        $job_id     Job id.
	 * @param float|null $started_at When the request started.
	 * @return TickResult
	 * @throws NotHere Rethrown unchanged, never taken as the database being unavailable.
	 */
	private function tick_now( int $job_id, $started_at ): TickResult {
		try {
			$session = $this->connection();
		} catch ( \Throwable $e ) {
			return $this->unavailable( $job_id, 'its connection to the database could not be opened', $e );
		}
		try {
			$jobs = $this->jobs( $session );
			try {
				$job = $jobs->find( $job_id );
			} catch ( NotHere $e ) {
				throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged: the Runner's, never this run's to swallow.
			} catch ( \Throwable $e ) {
				return $this->unavailable( $job_id, 'the job could not be read', $e );
			}
			if ( null === $job ) {
				// JobStore::find() gives null for a row it could not read too: missing only on positive evidence, the row
				// asked for again and none answered.
				try {
					$rows = $session->rows( 'SELECT id FROM ' . \WPCheckpoint\Database\SqlWriter::identifier( $jobs->jobs_table() ) . ' WHERE id = ?', array( (string) $job_id ) );
				} catch ( NotHere $e ) {
					throw $e; // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- rethrown unchanged: the Runner's, never this run's to swallow.
				} catch ( \Throwable $e ) {
					return $this->unavailable( $job_id, 'the job could not be read', $e );
				}
				return array() === $rows ? new TickResult( TickResult::MISSING, -1, null ) : $this->unavailable( $job_id, 'the job could not be read', new \RuntimeException( 'Its row could not be read.' ) );
			}
			$names       = $jobs->site_names( $job );
			$this->names = array(
				'hosts'  => array_values( array_unique( array_merge( $this->names['hosts'], $names['hosts'] ) ) ),
				'paths'  => array_values( array_unique( array_merge( $this->names['paths'], $names['paths'] ) ) ),
				'coarse' => $names['coarse'],
			);
			$network     = $job->options['recorded'][ SwapStep::NETWORK_RECORD ] ?? null;
			if ( $this->multisite && ! is_int( $network ) ) {
				// The network's id is what the swap recorded; without it the carry back cannot tell its rows.
				$this->note( sprintf( 'Job %d is not taken on here: the swap did not record its network.', $job_id ) );
				return new TickResult( TickResult::LEFT, -1, $job, 'The swap did not record its network.' );
			}
			if ( ! $this->multisite && null !== $network ) {
				// Recorded on a network, run as a single site: the carry back would leave the network's sitemeta out.
				$this->note( sprintf( 'Job %d is not taken on here: the swap was made on a network.', $job_id ) );
				return new TickResult( TickResult::LEFT, -1, $job, 'The swap was made on a network.' );
			}
			return $this->runner( $jobs, is_int( $network ) ? $network : 0 )->tick( $job_id, $started_at );
		} finally {
			$session->close();
		}
	}

	/**
	 * Nothing written: why, noted (the exception's message cleaned as every text of such a run).
	 *
	 * @param int        $job_id Job id.
	 * @param string     $what   What could not be done.
	 * @param \Throwable $e      Why.
	 * @return TickResult
	 */
	private function unavailable( int $job_id, string $what, \Throwable $e ): TickResult {
		$why = self::clean( $e->getMessage(), new Redactor( Redactor::installation_secrets() ), array( '{abspath}' => rtrim( $this->abspath, '/\\' ) ) );
		$this->note( sprintf( '%s is not taken on now: %s (%s). Nothing was written.', 0 === $job_id ? 'The job that holds the site' : 'Job ' . $job_id, $what, $why ) );
		return new TickResult( TickResult::UNAVAILABLE, -1, null, sprintf( 'The database could not be used: %s.', $what ) );
	}

	/**
	 * Such a run's own connection: CONNECT_SECONDS to connect, READ_SECONDS for each read.
	 *
	 * @return ImportSession
	 * @throws TransientFailure When it cannot be opened.
	 */
	public function connection(): ImportSession {
		return ImportSession::open( $this->credentials, self::CONNECT_SECONDS, self::READ_SECONDS );
	}

	/**
	 * The jobs' storage of such a run, over a connection: its writes leave wp_run_at as it is (0, JobStore), so that the
	 * grace period after a run in WordPress is never moved by a run without it (WebJobs::GRACE_SECONDS).
	 *
	 * @param ImportSession $session Connection.
	 * @return WebJobs
	 */
	public function jobs( ImportSession $session ): WebJobs {
		return new WebJobs(
			$session,
			$this->prefix,
			$this->abspath,
			function ( string $line ): void {
				$this->note( $line ); // Cleaned as everything such a run says.
			},
			static function (): int {
				return 0;
			}
		);
	}

	/**
	 * The Runner, as a run without WordPress makes it.
	 *
	 * @param WebJobs $jobs     The jobs' storage.
	 * @param int     $network  The network's id (0 on a single site).
	 * @return Runner
	 */
	public function runner( WebJobs $jobs, int $network = 0 ): Runner {
		$swap  = new SwapStep(
			function (): ImportSession {
				return $this->connection();
			},
			isset( $this->seams['parts'] ) && is_array( $this->seams['parts'] ) ? $this->seams['parts'] : array(),
			SwapHost::without_wordpress( $this->prefix, $this->multisite, $network, $this->plugin, $this->flush )
		);
		$types = new JobTypes( false );
		$types->add( new WebRestoreJob( $swap, isset( $this->seams['type'] ) && is_string( $this->seams['type'] ) ? $this->seams['type'] : RestoreJob::ID ) );
		$redactor = new Redactor( Redactor::installation_secrets() );
		$paths    = array(
			'{abspath}'    => rtrim( $this->abspath, '/\\' ),
			'{wp-content}' => self::content_dir( $this->abspath ),
		);
		return new Runner(
			$jobs,
			$types,
			$redactor,
			array(
				'cli'          => false,
				'web'          => true,
				'budget'       => new Budget( self::BUDGET_SECONDS, self::BUDGET_BYTES, false ),
				'lease'        => self::BUDGET_SECONDS + self::LEASE_MARGIN,
				'memory_limit' => self::bytes( (string) ini_get( 'memory_limit' ) ),
				'texts'        => new RunnerTexts( false ),
				'encode'       => array( PlainJson::class, 'encode' ),
				'paths'        => $paths,
				'clean'        => function ( string $text ) use ( $redactor, $paths ): string {
					return self::clean( $text, $redactor, $paths, $this->names['hosts'], $this->names['paths'], $this->names['coarse'] );
				},
				'php_log'      => function ( string $line, array $extra ) use ( $redactor, $paths ): void {
					error_log( 'WP Checkpoint: ' . self::clean( $line, $redactor, array_merge( $extra, $paths ), $this->names['hosts'], $this->names['paths'], $this->names['coarse'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the job's own log cannot be written.
				},
				'mask'         => function ( Redactor $mask_redactor, array $extra ) use ( $paths ): TextMask {
					return new TextMask(
						$mask_redactor,
						array_merge( $extra, $paths ),
						$this->names['hosts'],
						array(
							'paths'        => $this->names['paths'],
							'coarse'       => $this->names['coarse'],
							'network_root' => '',
						)
					);
				},
				'on_left'      => function ( $job, string $reason ): void {
					$this->note( sprintf( 'Job %d is left to WP-CLI: %s', $job instanceof Job ? $job->id : 0, $reason ) );
				},
			)
		);
	}

	/**
	 * Text as such a run says it: redacted, its paths masked, the site's hosts (and its sites' paths) masked and every
	 * other URL's host (Report::mask_hosts()); a fixed text when a mask fails.
	 *
	 * @param string                $text       Text.
	 * @param Redactor              $redactor   Redactor.
	 * @param array<string, string> $paths      Placeholder => path.
	 * @param string[]              $hosts      The site's host names.
	 * @param string[]              $site_paths The site's path prefixes.
	 * @param bool                  $coarse     Whether every unknown first path segment is masked too (a large network).
	 * @return string
	 */
	public static function clean( string $text, Redactor $redactor, array $paths, array $hosts = array(), array $site_paths = array(), bool $coarse = false ): string {
		$masked = Report::mask_paths( $redactor->redact( Utf8::scrub( $text ) ), $paths );
		$masked = is_string( $masked ) ? Report::mask_hosts( $masked, $hosts, $site_paths, $coarse ) : null;
		return is_string( $masked ) ? $masked : 'The text could not be cleaned safely.';
	}

	/**
	 * The content directory: wp-config.php's WP_CONTENT_DIR when it sets one, WordPress's default otherwise (without
	 * WordPress, nothing else defines it).
	 *
	 * @param string $abspath The WordPress directory.
	 * @return string
	 */
	public static function content_dir( string $abspath ): string {
		return defined( 'WP_CONTENT_DIR' ) ? (string) constant( 'WP_CONTENT_DIR' ) : rtrim( $abspath, '/\\' ) . '/wp-content';
	}

	/**
	 * A php.ini size ("128M") in bytes, as PHP reads it; -1 for none.
	 *
	 * @param string $value Value.
	 * @return int
	 */
	public static function bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value ) {
			return -1;
		}
		$number = (int) $value;
		switch ( strtolower( substr( $value, -1 ) ) ) {
			case 'g':
				$number *= 1024;
				// Fall through.
			case 'm':
				$number *= 1024;
				// Fall through.
			case 'k':
				$number *= 1024;
		}
		return $number;
	}

	/**
	 * A line for the caller.
	 *
	 * @param string $line Line.
	 * @return void
	 */
	private function note( string $line ): void {
		call_user_func( $this->note, self::clean( $line, new Redactor( Redactor::installation_secrets() ), array( '{abspath}' => rtrim( $this->abspath, '/\\' ) ), $this->names['hosts'], $this->names['paths'], $this->names['coarse'] ) );
	}
}
