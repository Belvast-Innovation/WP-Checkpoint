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
	 * Constructor.
	 *
	 * @param Credentials   $credentials The database settings and the table prefix.
	 * @param string        $abspath     The WordPress directory.
	 * @param bool          $multisite   Whether the site is a network.
	 * @param string        $plugin      This plugin's basename.
	 * @param callable      $note        function( string $line ): void.
	 * @param callable|null $flush       function(): mixed, or null.
	 */
	public function __construct( Credentials $credentials, string $abspath, bool $multisite, string $plugin, callable $note, $flush = null ) {
		$this->credentials = $credentials;
		$this->prefix      = $credentials->get( 'prefix' );
		$this->abspath     = $abspath;
		$this->multisite   = $multisite;
		$this->plugin      = $plugin;
		$this->note        = $note;
		$this->flush       = is_callable( $flush ) ? $flush : null;
	}

	/**
	 * One tick of a job, as a run without WordPress makes it.
	 *
	 * @param int        $job_id     Job id.
	 * @param float|null $started_at When the request started (its budget counts from there).
	 * @return TickResult
	 */
	public function tick( int $job_id, $started_at = null ): TickResult {
		$session = ImportSession::open( $this->credentials );
		try {
			$jobs = new WebJobs( $session, $this->prefix, $this->abspath, $this->note );
			$job  = $jobs->find( $job_id );
			if ( null === $job ) {
				return new TickResult( TickResult::MISSING, -1, null );
			}
			$network = $job->options['recorded'][ SwapStep::NETWORK_RECORD ] ?? null;
			if ( $this->multisite && ! is_int( $network ) ) {
				// The network's id is what the swap recorded; without it the carry back cannot tell its rows.
				$this->note( sprintf( 'Job %d is not taken on here: the swap did not record its network.', $job_id ) );
				return new TickResult( TickResult::LEFT, -1, $job, 'The swap did not record its network.' );
			}
			return $this->runner( $jobs, is_int( $network ) ? $network : 0 )->tick( $job_id, $started_at );
		} finally {
			$session->close();
		}
	}

	/**
	 * The Runner, as a run without WordPress makes it.
	 *
	 * @param WebJobs $jobs     The jobs' storage.
	 * @param int     $network  The network's id (0 on a single site).
	 * @return Runner
	 */
	public function runner( WebJobs $jobs, int $network = 0 ): Runner {
		$credentials = $this->credentials;
		$swap        = new SwapStep(
			static function () use ( $credentials ): ImportSession {
				return ImportSession::open( $credentials );
			},
			array(),
			SwapHost::without_wordpress( $this->prefix, $this->multisite, $network, $this->plugin, $this->flush )
		);
		$types       = new JobTypes( false );
		$types->add( new WebRestoreJob( $swap ) );
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
				'clean'        => static function ( string $text ) use ( $redactor, $paths ): string {
					return self::clean( $text, $redactor, $paths );
				},
				'php_log'      => static function ( string $line, array $extra ) use ( $redactor, $paths ): void {
					error_log( 'WP Checkpoint: ' . self::clean( $line, $redactor, array_merge( $extra, $paths ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the job's own log cannot be written.
				},
				'mask'         => static function ( Redactor $mask_redactor, array $extra ) use ( $paths ): TextMask {
					return new TextMask(
						$mask_redactor,
						array_merge( $extra, $paths ),
						array(),
						array(
							'paths'        => array(),
							'coarse'       => false,
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
	 * Text as such a run says it: redacted, its paths masked (a fixed text when a mask fails).
	 *
	 * @param string                $text     Text.
	 * @param Redactor              $redactor Redactor.
	 * @param array<string, string> $paths    Placeholder => path.
	 * @return string
	 */
	public static function clean( string $text, Redactor $redactor, array $paths ): string {
		$masked = Report::mask_paths( $redactor->redact( $text ), $paths );
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
		call_user_func( $this->note, $line );
	}
}
