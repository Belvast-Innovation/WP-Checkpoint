<?php
/**
 * The swap step of a restore, in a run made without WordPress.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * The restore's swap step (its id, its site state and mark) where a run is made without WordPress: it only puts the
 * site back, as SwapStep does, and only for what RollbackEligibility takes on (SwapStep::put_back_here()); anything
 * else is left to WP-CLI. Not CliOnly: it is the one step such a run does.
 */
final class WebSwapStep implements Step, HoldsSite, MarksSite {

	/**
	 * The swap, made for a run without WordPress (its SwapHost says so).
	 *
	 * @var SwapStep
	 */
	private $swap;

	/**
	 * Constructor.
	 *
	 * @param SwapStep $swap The swap, with a SwapHost made without WordPress.
	 */
	public function __construct( SwapStep $swap ) {
		$this->swap = $swap;
	}

	/**
	 * Step id: the swap's.
	 *
	 * @return string
	 */
	public function id(): string {
		return SwapStep::ID;
	}

	/**
	 * Put the site back, or leave the job to WP-CLI.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 */
	public function run( JobContext $context ): StepResult {
		return $this->swap->put_back_here( $context );
	}

	/**
	 * The site state a cursor stands for, as the swap's.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return int|null
	 */
	public static function site_state( array $cursor ) {
		return SwapStep::site_state( $cursor );
	}

	/**
	 * The maintenance file's mark a cursor carries, as the swap's.
	 *
	 * @param array<string, mixed> $cursor Cursor.
	 * @return string|null
	 */
	public static function site_mark( array $cursor ) {
		return SwapStep::site_mark( $cursor );
	}

	/**
	 * Nothing is cleaned up here: a job is cancelled only in WP-CLI or WordPress (its cleanup needs the engine's
	 * reclaim, which a run without WordPress cannot do; the Runner leaves such a job before it).
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}
}
