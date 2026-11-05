<?php
/**
 * The units of one tick, for every step that runs in it.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * What the Runner knows of the units in one tick, shared by every run of a step in it (each run has a JobContext of
 * its own; the Runner runs a step again within a tick while its budget lasts, and moves on to the next step):
 * whether a unit started in the tick, the slowest time of each kind of unit, and whether a unit was declined.
 *
 * The first unit of the tick starts whatever the budget: a tick that did nothing would make no progress. Any other
 * starts only while the budget is not spent and the time left covers the slowest of its kind with MARGIN; a kind
 * never measured (no time of it in this tick or an earlier one) does not start: the tick ends, and the next tick's
 * first unit measures it. The slowest times are kept across ticks (Runner: in the cursor's reserved keys), so a
 * tick judges by what earlier ticks saw.
 */
final class TickUnits {

	/**
	 * The time left must cover the slowest unit of its kind times this.
	 */
	const MARGIN = 1.5;

	/**
	 * Kinds of unit kept at most (the oldest go first).
	 */
	const MAX_KINDS = 16;

	/**
	 * A kind of unit: what a step names it.
	 */
	const KIND = '/\A[a-z][a-z0-9_.]{0,47}\z/';

	/**
	 * Whether a unit started in this tick.
	 *
	 * @var bool
	 */
	private $started = false;

	/**
	 * Whether a unit was declined in this tick.
	 *
	 * @var bool
	 */
	private $declined = false;

	/**
	 * Kind => the slowest time of it, in seconds (this tick's and earlier ticks').
	 *
	 * @var array<string, float>
	 */
	private $slowest = array();

	/**
	 * Constructor.
	 *
	 * @param mixed $estimates What earlier ticks kept (estimates()); anything else is taken as none.
	 */
	public function __construct( $estimates = array() ) {
		if ( ! is_array( $estimates ) ) {
			return;
		}
		foreach ( $estimates as $kind => $seconds ) {
			if ( is_string( $kind ) && 1 === preg_match( self::KIND, $kind ) && ( is_int( $seconds ) || is_float( $seconds ) ) && is_finite( (float) $seconds ) && $seconds >= 0 ) {
				$this->slowest[ $kind ] = (float) $seconds;
			}
		}
		$this->slowest = array_slice( $this->slowest, -self::MAX_KINDS, null, true );
	}

	/**
	 * Whether a unit of this kind may start now (and, when it may, that one did).
	 *
	 * @param string     $kind    Kind of unit.
	 * @param JobContext $context The run's context (its budget and clock).
	 * @return bool
	 */
	public function may_start( string $kind, JobContext $context ): bool {
		if ( ! $this->started ) {
			$this->started = true;
			return true;
		}
		$slowest = $this->slowest[ $kind ] ?? null;
		if ( null === $slowest || $context->should_stop() || $context->remaining_seconds() < $slowest * self::MARGIN ) {
			$this->declined = true;
			return false;
		}
		return true;
	}

	/**
	 * Work was done in this tick (a step's run moved its cursor or ended, or a unit ran that may_start() was not asked
	 * about): what comes after it is not the tick's first unit.
	 *
	 * @return void
	 */
	public function begun(): void {
		$this->started = true;
	}

	/**
	 * A unit of this kind took this long (and a unit ran in this tick: begun()).
	 *
	 * @param string $kind    Kind of unit.
	 * @param float  $seconds Its time.
	 * @return void
	 */
	public function took( string $kind, float $seconds ): void {
		$this->started = true;
		if ( 1 !== preg_match( self::KIND, $kind ) || ! is_finite( $seconds ) ) {
			return;
		}
		$seconds = max( 0.0, $seconds );
		if ( isset( $this->slowest[ $kind ] ) && $this->slowest[ $kind ] >= $seconds ) {
			return;
		}
		unset( $this->slowest[ $kind ] );
		$this->slowest[ $kind ] = round( $seconds, 4 );
		$this->slowest          = array_slice( $this->slowest, -self::MAX_KINDS, null, true );
	}

	/**
	 * Whether a unit was declined in this tick: the step stopped for it, and the tick ends.
	 *
	 * @return bool
	 */
	public function declined(): bool {
		return $this->declined;
	}

	/**
	 * What to keep for the next tick: kind => the slowest time of it.
	 *
	 * @return array<string, float>
	 */
	public function estimates(): array {
		return $this->slowest;
	}
}
