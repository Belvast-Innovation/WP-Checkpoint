<?php

namespace WPCheckpoint\Tests\Unit\Jobs;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\JobStore;
use WPCheckpoint\Jobs\JobType;
use WPCheckpoint\Jobs\PlainJson;
use WPCheckpoint\Jobs\RestoreJob;
use WPCheckpoint\Jobs\SessionExecutor;
use WPCheckpoint\Jobs\Step;
use WPCheckpoint\Jobs\StoreExecutor;
use WPCheckpoint\Jobs\SwapStep;
use WPCheckpoint\Jobs\WebJobs;
use WPCheckpoint\Jobs\WebRun;
use WPCheckpoint\Restore\ImportSession;
use WPCheckpoint\Standalone\Credentials;
use WPCheckpoint\Support\Redactor;
use WPCheckpoint\Tests\Fixtures\WebPathScanner;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * A run made without WordPress (a visitor's request, b2) goes only through code that does not need WordPress: no
 * WordPress function, no $wpdb, no call whose target cannot be read but those registered here with their targets, no
 * receiver whose type cannot be read but those registered with their classes, and on its way no wide catch that would
 * take a NotHere for something else; every class it names loads without WordPress. Read method by method, across
 * classes, by WebPathScanner; the subprocess test (b1b's) is the proof that it runs.
 */
final class WebPathScanTest extends TestCase {

	/**
	 * Where a run without WordPress starts: what its runtime calls.
	 */
	const ROOTS = array(
		'WPCheckpoint\Jobs\WebRun::__construct',
		'WPCheckpoint\Jobs\WebRun::tick',
	);

	/**
	 * Interface => the classes a run without WordPress gives for it (its assembly; checked by the test of the
	 * assembly).
	 */
	const BINDINGS = array(
		'WPCheckpoint\Jobs\RunnerRepository' => array( 'WPCheckpoint\Jobs\WebJobs' ),
		'WPCheckpoint\Jobs\StoreExecutor'    => array( 'WPCheckpoint\Jobs\SessionExecutor' ),
		'WPCheckpoint\Restore\Queries'       => array( 'WPCheckpoint\Restore\ImportSession' ),
		'WPCheckpoint\Jobs\Step'             => array( 'WPCheckpoint\Jobs\LeaveStep', 'WPCheckpoint\Jobs\WebSwapStep' ),
		'WPCheckpoint\Jobs\JobType'          => array( 'WPCheckpoint\Jobs\WebRestoreJob' ),
	);

	/**
	 * "file:method:receiver" => the classes it is (its type cannot be read from the source).
	 */
	const RECEIVERS = array(
		// The steps of the job's type: JobType::steps() is documented as Step[], the loop's variable is not.
		'src/Jobs/Runner.php:run_steps:$step'                      => array( 'WPCheckpoint\Jobs\Step' ),
		'src/Jobs/Runner.php:at_cli_only_step:$step'               => array( 'WPCheckpoint\Jobs\Step' ),
		// The registry's types, filtered or not, kept only when they are a JobType.
		'src/Jobs/JobTypes.php:all:$type'                          => array( 'WPCheckpoint\Jobs\JobType' ),
		// $tick_units is array{job: int, units: TickUnits}|null.
		"src/Jobs/Runner.php:persist:\$this->tick_units['units']" => array( 'WPCheckpoint\Jobs\TickUnits' ),
		// What SwapStep's connect returns: WebRun's closure opens an ImportSession.
		'src/Jobs/SwapStep.php:put_back_here:$db'                  => array( 'WPCheckpoint\Restore\ImportSession' ),
	);

	/**
	 * "file:method" => the methods its calls whose target cannot be read go to (those that define the closures).
	 */
	const DYNAMIC = array(
		// Runner: what WebRun gives in its options (closures in WebRun::runner(), PlainJson::encode), or, where it
		// gives nothing, the Runner's own defaults (closures in Runner::__construct()).
		'src/Jobs/Runner.php:__construct'          => array(), // hr_to_bytes: WebRun gives memory_limit, so it is never called.
		'src/Jobs/Runner.php:tick_once'            => array( 'WPCheckpoint\Jobs\Runner::__construct' ), // memory.
		'src/Jobs/Runner.php:now'                  => array( 'WPCheckpoint\Jobs\Runner::__construct' ), // clock.
		'src/Jobs/Runner.php:run_steps'            => array( 'WPCheckpoint\Jobs\PlainJson::encode' ), // encode.
		'src/Jobs/Runner.php:left'                 => array( 'WPCheckpoint\Jobs\WebRun::runner' ), // on_left.
		'src/Jobs/Runner.php:logger_for'           => array( 'WPCheckpoint\Jobs\WebRun::runner' ), // php_log, mask.
		'src/Jobs/Runner.php:budget_for'           => array(), // budget: WebRun gives a Budget, so neither a callable budget nor probe is called.
		'src/Jobs/Runner.php:persist'              => array(), // on_persist: a test seam; WebRun gives none.
		// JobContext: the Runner's clock and memory (its defaults) and the lease, checkpoint and record it makes.
		'src/Jobs/JobContext.php:__construct'      => array( 'WPCheckpoint\Jobs\Runner::__construct' ),
		'src/Jobs/JobContext.php:elapsed'          => array( 'WPCheckpoint\Jobs\Runner::__construct' ),
		'src/Jobs/JobContext.php:remaining_memory' => array( 'WPCheckpoint\Jobs\Runner::__construct' ),
		'src/Jobs/JobContext.php:should_stop'      => array( 'WPCheckpoint\Jobs\Runner::context' ),
		'src/Jobs/JobContext.php:confirm_lease'    => array( 'WPCheckpoint\Jobs\Runner::context' ),
		'src/Jobs/JobContext.php:checkpoint'       => array( 'WPCheckpoint\Jobs\Runner::run_steps', 'WPCheckpoint\Jobs\Runner::__construct' ),
		// SwapStep: its connect is WebRun's closure; its parts (test seams) WebRun gives none of.
		'src/Jobs/SwapStep.php:put_back_here'      => array( 'WPCheckpoint\Jobs\WebRun::runner' ),
		'src/Jobs/SwapStep.php:at'                 => array(),
		'src/Jobs/SwapStep.php:flush'              => array(),
		'src/Jobs/SwapStep.php:take_down'          => array(),
		'src/Jobs/SwapStep.php:random'             => array(),
		'src/Jobs/SwapStep.php:now'                => array(),
		'src/Jobs/SwapStep.php:sleep'              => array(),
		'src/Jobs/SwapStep.php:move'               => array(),
		// SwapHost: the closures SwapHost::without_wordpress() makes; its flush is what WebRun is given (b2's entry).
		'src/Jobs/SwapHost.php:base_prefix'        => array( 'WPCheckpoint\Jobs\SwapHost::without_wordpress' ),
		'src/Jobs/SwapHost.php:multisite'          => array( 'WPCheckpoint\Jobs\SwapHost::without_wordpress' ),
		'src/Jobs/SwapHost.php:network_id'         => array( 'WPCheckpoint\Jobs\SwapHost::without_wordpress' ),
		'src/Jobs/SwapHost.php:plugin'             => array( 'WPCheckpoint\Jobs\SwapHost::without_wordpress' ),
		'src/Jobs/SwapHost.php:flush'              => array(),
		// StateCarry's seam: the closure SwapStep::state_carry() gives (to SwapStep::at()).
		'src/Restore/StateCarry.php:at'            => array( 'WPCheckpoint\Jobs\SwapStep::state_carry' ),
		// Callables their callers give as arguments: closures read with those callers, named ones edges there.
		'src/Support/AtomicFile.php:write'         => array(),
		'src/Support/Deleter.php:delete_maintenance_file' => array(),
		'src/Restore/JobFence.php:within'          => array(),
		// A closure of its own.
		'src/Restore/Maintenance.php:check_code'   => array(),
		// JobStore: what WebJobs::__construct() gives it.
		'src/Jobs/JobStore.php:now'                => array( 'WPCheckpoint\Jobs\WebJobs::now' ),
		'src/Jobs/JobStore.php:save_progress'      => array( 'WPCheckpoint\Jobs\WebJobs::__construct', 'WPCheckpoint\Jobs\PlainJson::encode', 'WPCheckpoint\Jobs\JobStore::cut_message' ),
		'src/Jobs/JobStore.php:transition'         => array( 'WPCheckpoint\Jobs\WebJobs::__construct', 'WPCheckpoint\Jobs\PlainJson::encode' ), // No retry_check: WebJobs gives none (and refuses a retry).
		'src/Jobs/JobStore.php:write_transition'   => array( 'WPCheckpoint\Support\Redactor::redact', 'WPCheckpoint\Jobs\JobStore::cut_message', 'WPCheckpoint\Jobs\PlainJson::encode' ),
		// Logger: its fallback is the closure Runner::logger_for() gives.
		'src/Support/Logger.php:write'             => array( 'WPCheckpoint\Jobs\Runner::logger_for' ),
		// Closures their callers in the same class give; read with those callers.
		'src/Restore/ImportSession.php:quietly'    => array(),
		'src/Standalone/Connection.php:quietly'    => array(),
		// The note: given by the entries of b2, which are read with them (not part of this run's code).
		'src/Jobs/WebRun.php:note'                 => array(),
		'src/Jobs/WebJobs.php:note'                => array(),
	);

	/**
	 * "file:method:Class::method" => why that edge is never taken in a run without WordPress (a default the run's
	 * assembly replaces; checked by the test of the assembly).
	 */
	const CUTS = array(
		// The Runner's defaults of WordPress's, chosen only when an option is not given; WebRun gives every one.
		'src/Jobs/Runner.php:__construct:WPCheckpoint\Jobs\Runner::wordpress_clean'        => 'WebRun gives clean',
		'src/Jobs/Runner.php:__construct:WPCheckpoint\Jobs\Runner::to_php_log'             => 'WebRun gives php_log',
		'src/Jobs/Runner.php:__construct:WPCheckpoint\Support\TextMask::for_installation' => 'WebRun gives mask',
		'src/Jobs/Runner.php:__construct:WPCheckpoint\Jobs\Runner::probed_budget'          => 'WebRun gives a Budget: probe is never called',
	);

	/**
	 * "file:method:what" => why it is never so in a run without WordPress.
	 */
	const ALLOWED = array(
		// SwapStep makes a SwapHost of WordPress's only when none is given; WebRun always gives one made without it.
		'src/Jobs/SwapHost.php:of_wordpress:is_multisite'           => 'only when SwapStep is given no host; WebRun gives SwapHost::without_wordpress()',
		'src/Jobs/SwapHost.php:of_wordpress:get_current_network_id' => 'only when SwapStep is given no host; WebRun gives SwapHost::without_wordpress()',
		'src/Jobs/SwapHost.php:of_wordpress:plugin_basename'        => 'only when SwapStep is given no host; WebRun gives SwapHost::without_wordpress()',
		'src/Jobs/SwapHost.php:of_wordpress:wp_cache_flush'         => 'only when SwapStep is given no host; WebRun gives SwapHost::without_wordpress()',
		'src/Jobs/SwapHost.php:of_wordpress:$wpdb'                  => 'only when SwapStep is given no host; WebRun gives SwapHost::without_wordpress()',
		'src/Jobs/SwapHost.php:of_wordpress:WPCHECKPOINT_FILE'      => 'only when SwapStep is given no host; WebRun gives SwapHost::without_wordpress()',
		// Constants: ABSPATH is there before any class loads; wp-config.php's DB_* behind defined().
		'src/Jobs/Runner.php:__construct:ABSPATH'                   => 'every class\'s file exits without ABSPATH: a run without WordPress defines it (wp-config.php) before loading any',
		'src/Jobs/Runner.php:__construct:WP_CONTENT_DIR'            => 'in the default of paths, which WebRun gives',
		'src/Jobs/SwapStep.php:maintenance:ABSPATH'                 => 'every class\'s file exits without ABSPATH: a run without WordPress defines it (wp-config.php) before loading any',
		'src/Support/Deleter.php:delete_maintenance_file:ABSPATH'   => 'every class\'s file exits without ABSPATH: a run without WordPress defines it (wp-config.php) before loading any',
		'src/Support/AtomicFile.php:maintenance_dir:ABSPATH'        => 'every class\'s file exits without ABSPATH: a run without WordPress defines it (wp-config.php) before loading any',
		'src/Jobs/CursorSecrets.php:assert_none:DB_USER'            => 'behind defined( \'DB_USER\' ) (wp-config.php defines it)',
		// RunnerTexts translates only when made to; WebRun makes RunnerTexts( false ).
		'src/Jobs/RunnerTexts.php:waiting:__' => 'RunnerTexts( false ): the English text, __() is not called',
		'src/Jobs/RunnerTexts.php:busy:__' => 'RunnerTexts( false ): the English text, __() is not called',
		'src/Jobs/RunnerTexts.php:lost:__' => 'RunnerTexts( false ): the English text, __() is not called',
		'src/Jobs/RunnerTexts.php:refused:__' => 'RunnerTexts( false ): the English text, __() is not called',
		'src/Jobs/RunnerTexts.php:cli_only:__' => 'RunnerTexts( false ): the English text, __() is not called',
		'src/Jobs/RunnerTexts.php:files_elsewhere:__' => 'RunnerTexts( false ): the English text, __() is not called',
		// SwapHost's texts: English in a run without WordPress (SwapHost::without_wordpress()).
		'src/Jobs/SwapHost.php:putting_back:__'                     => 'SwapHost::without_wordpress(): web, the English text',
		'src/Jobs/SwapHost.php:as_before:__'                        => 'SwapHost::without_wordpress(): web, the English text',
		// Behind function_exists().
		'src/Support/Report.php:failure_text:__'                    => 'behind function_exists( \'__\' )',
		'src/Support/Paths.php:normalize:wp_normalize_path'         => 'behind function_exists( \'wp_normalize_path\' )',
		// JobTypes filters only when made to; WebRun makes JobTypes( false ).
		'src/Jobs/JobTypes.php:all:apply_filters'                   => 'JobTypes( false ): the filter is not applied',
		// Wide catches around what never throws NotHere.
		'src/Jobs/Runner.php:left:catch'                            => 'around release(), which WebJobs does (WebJobs::can()): it never throws NotHere',
		'src/Jobs/SessionExecutor.php:execute:catch'                => 'around ImportSession::write(), which never throws NotHere',
		'src/Jobs/SessionExecutor.php:select:catch'                 => 'around ImportSession::named_rows(), which never throws NotHere',
		'src/Restore/JobFence.php:within:catch'                     => 'rethrows what it caught, after its ROLLBACK',
	);

	/**
	 * What the storage of a run without WordPress does (WebJobs::can()).
	 */
	const CAN = array( 'now', 'find', 'gate', 'acquire', 'heartbeat', 'release', 'save_progress', 'transition', 'files_base', 'owns_files_of', 'log_event' );

	/**
	 * The scanner, with this test's registries.
	 *
	 * @param array<string, string> $sources Class => source (the controls).
	 * @param array<string, mixed>  $more    Registries in place of this test's.
	 * @return WebPathScanner
	 */
	public static function scanner( array $sources = array(), array $more = array() ): WebPathScanner {
		return new WebPathScanner(
			array_merge(
				array(
					'roots'     => self::ROOTS,
					'bindings'  => self::BINDINGS,
					'receivers' => self::RECEIVERS,
					'dynamic'   => self::DYNAMIC,
					'allowed'   => self::ALLOWED,
					'cuts'      => self::CUTS,
					'can'       => self::CAN,
				),
				$more
			),
			$sources
		);
	}

	public function test_the_reach_of_a_run_without_wordpress(): void {
		$reach = self::scanner()->run();
		$this->assertGreaterThan( 400, count( $reach['methods'] ), 'the control: the scan reaches the swap\'s rollback (434 methods when written)' );
		$this->assertContains( 'WPCheckpoint\Jobs\SwapStep::roll_back', $reach['methods'] );
		$this->assertSame( array(), $reach['problems'] );
	}

	public function test_every_registry_entry_is_used(): void {
		$used  = self::scanner()->run()['used'];
		$stale = array();
		foreach ( array(
			'binding'  => self::BINDINGS,
			'receiver' => self::RECEIVERS,
			'dynamic'  => self::DYNAMIC,
			'allowed'  => self::ALLOWED,
			'cut'      => self::CUTS,
		) as $kind => $registry ) {
			foreach ( array_keys( $registry ) as $key ) {
				if ( ! isset( $used[ $kind . ':' . $key ] ) ) {
					$stale[] = $kind . ':' . $key;
				}
			}
		}
		$this->assertNotEmpty( $used, 'the control: the scan records what it uses' );
		$this->assertSame( array(), $stale );
	}

	/**
	 * A private property's value.
	 *
	 * @param object $of   Object.
	 * @param string $name Property.
	 * @return mixed
	 */
	private static function prop( $of, string $name ) {
		$property = new \ReflectionProperty( $of, $name );
		$property->setAccessible( true );
		return $property->getValue( $of );
	}

	/**
	 * What the registries say of the assembly is what WebRun makes: each interface bound to the class given for it,
	 * each default of WordPress's cut or allowed because WebRun gives its own, each callable registered as going where
	 * it goes.
	 */
	public function test_the_assembly_is_what_the_registries_say(): void {
		$session = ( new \ReflectionClass( ImportSession::class ) )->newInstanceWithoutConstructor();
		$note    = static function ( string $line ): void {
			unset( $line );
		};
		$jobs    = new WebJobs( $session, 'wp_', ABSPATH, $note );
		$run     = new WebRun(
			Credentials::from_values(
				array(
					'name'     => 'db',
					'user'     => 'user',
					'password' => 'secret-password',
					'host'     => 'localhost',
					'prefix'   => 'site9_',
				)
			),
			ABSPATH,
			false,
			'wp-checkpoint/wp-checkpoint.php',
			$note
		);
		$runner  = $run->runner( $jobs );
		$this->assertSame( 'site9_', self::prop( $run, 'prefix' ), 'the prefix is the settings\' (wp-config.php\'s $table_prefix)' );

		// RunnerRepository => WebJobs; StoreExecutor => SessionExecutor; Queries => ImportSession.
		$this->assertSame( $jobs, self::prop( $runner, 'repository' ) );
		$store    = self::prop( $jobs, 'store' );
		$executor = self::prop( $store, 'db' );
		$this->assertInstanceOf( SessionExecutor::class, $executor );
		$this->assertSame( $session, self::prop( $executor, 'session' ) );
		$this->assertSame( array( SessionExecutor::class ), self::BINDINGS[ StoreExecutor::class ] );

		// JobType => WebRestoreJob; Step => LeaveStep, WebSwapStep; JobTypes( false ).
		$types = self::prop( $runner, 'types' );
		$this->assertFalse( self::prop( $types, 'filtered' ) );
		$all = $types->all();
		$this->assertSame( array( RestoreJob::ID ), array_keys( $all ) );
		$this->assertSame( self::BINDINGS[ JobType::class ], array( get_class( $all[ RestoreJob::ID ] ) ) );
		$steps = array_values( array_unique( array_map( 'get_class', $all[ RestoreJob::ID ]->steps() ) ) );
		sort( $steps );
		$this->assertSame( self::BINDINGS[ Step::class ], $steps );

		// SwapStep: a SwapHost made without WordPress (of_wordpress() allowed), no parts (their calls registered as
		// going nowhere), its connect a closure of WebRun::runner().
		$swap = self::prop( $all[ RestoreJob::ID ], 'swap' );
		$swap = $swap instanceof SwapStep ? $swap : self::prop( $swap, 'swap' ); // WebSwapStep's.
		$this->assertInstanceOf( SwapStep::class, $swap );
		$this->assertTrue( self::prop( $swap, 'host' )->web() );
		$this->assertSame( array(), self::prop( $swap, 'parts' ) );
		$this->assertInstanceOf( \Closure::class, self::prop( $swap, 'connect' ) );

		// The Runner's defaults of WordPress's (cut) and its other callables (registered).
		foreach ( array( 'clean', 'php_log', 'mask', 'on_left' ) as $given ) {
			$this->assertInstanceOf( \Closure::class, self::prop( $runner, $given ), $given );
		}
		$this->assertInstanceOf( Budget::class, self::prop( $runner, 'budget' ), 'a Budget: neither a callable budget nor probe is called' );
		$this->assertSame( array( PlainJson::class, 'encode' ), self::prop( $runner, 'encode' ) );
		$this->assertNull( self::prop( $runner, 'on_persist' ) );
		$this->assertSame( WebRun::bytes( (string) ini_get( 'memory_limit' ) ), self::prop( $runner, 'memory_limit' ), 'given: hr_to_bytes is not called' );
		$this->assertSame( array( '{abspath}', '{wp-content}' ), array_keys( self::prop( $runner, 'paths' ) ), 'given: the default (WP_CONTENT_DIR) is not read' );
		$this->assertFalse( self::prop( self::prop( $runner, 'texts' ), 'translate' ) );
		$this->assertTrue( self::prop( $runner, 'web' ) );
		$this->assertFalse( self::prop( $runner, 'cli' ) );

		// JobStore's callables, as registered.
		$this->assertSame( array( $jobs, 'now' ), self::prop( $store, 'now' ) );
		$this->assertSame( array( PlainJson::class, 'encode' ), self::prop( $store, 'encode' ) );
		$this->assertInstanceOf( Redactor::class, self::prop( $store, 'redact' )[0] );
		$this->assertSame( 'redact', self::prop( $store, 'redact' )[1] );
		$this->assertSame( array( JobStore::class, 'cut_message' ), self::prop( $store, 'fit' ) );
		$this->assertInstanceOf( \Closure::class, self::prop( $store, 'no_secrets' ) );
		$this->assertInstanceOf( \Closure::class, self::prop( $store, 'fence_absent' ) );
	}
}
