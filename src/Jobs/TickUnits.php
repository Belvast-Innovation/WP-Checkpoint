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
 * starts only while the budget is not spent and the time left covers the estimate of its kind with MARGIN: the
 * slowest of it in this tick, or in the last tick that ran one, whichever is slower. A kind never measured (no time
 * of it in this tick or kept from an earlier one) does not start, unless the step knows beforehand what a unit of it
 * takes at most (may_start()'s bound: that is its estimate then): the tick ends, and the next tick's first unit
 * measures it. What is kept for the next tick (Runner: in the cursor's reserved keys) is each kind's slowest in the
 * last tick that ran one, not the slowest ever: one slow unit weighs on the next tick, not on every tick after it.
 */
final class TickUnits {

	/**
	 * The time left must cover the estimate of a unit's kind (estimate()) times this.
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
	 * Kind => the slowest time of it in the last tick that ran one, as earlier ticks kept it, in seconds.
	 *
	 * @var array<string, float>
	 */
	private $kept = array();

	/**
	 * Kind => the slowest time of it in this tick, in seconds.
	 *
	 * @var array<string, float>
	 */
	private $slowest = array();

	/**
	 * Units started in this tick (may_start() said yes, or begun()).
	 *
	 * @var int
	 */
	private $count = 0;

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
				$this->kept[ $kind ] = (float) $seconds;
			}
		}
		$this->kept = array_slice( $this->kept, -self::MAX_KINDS, null, true );
	}

	/**
	 * Whether a unit of this kind may start now (and, when it may, that one did).
	 *
	 * @param string     $kind    Kind of unit.
	 * @param JobContext $context The run's context (its budget and clock).
	 * @param float|null $bound   What a unit of this kind takes at most, known beforehand: the estimate while the
	 *                            kind was never measured (a kind with no bound waits for a tick's first unit).
	 * @param float      $wait    Seconds the unit waits before it starts (a wait owed between two tries): the time
	 *                            left must cover it too.
	 * @return bool
	 */
	public function may_start( string $kind, JobContext $context, ?float $bound = null, float $wait = 0.0 ): bool {
		if ( ! $this->started ) {
			$this->started = true;
			++$this->count;
			return true;
		}
		$estimate = $this->estimate( $kind ) ?? $bound;
		if ( null === $estimate || $context->should_stop() || $context->remaining_seconds() < max( 0.0, $wait ) + $estimate * self::MARGIN ) {
			$this->declined = true;
			return false;
		}
		++$this->count;
		return true;
	}

	/**
	 * The estimate of a kind: the slower of this tick's slowest and the one kept from the last tick that ran one;
	 * null when it was never measured.
	 *
	 * @param string $kind Kind of unit.
	 * @return float|null
	 */
	private function estimate( string $kind ) {
		if ( ! isset( $this->slowest[ $kind ] ) && ! isset( $this->kept[ $kind ] ) ) {
			return null;
		}
		return max( $this->slowest[ $kind ] ?? 0.0, $this->kept[ $kind ] ?? 0.0 );
	}

	/**
	 * Units started in this tick so far (the Runner compares it around a run).
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->count;
	}

	/**
	 * Work was done in this tick (a step's run moved its cursor or ended, or a unit ran that may_start() was not asked
	 * about): what comes after it is not the tick's first unit.
	 *
	 * @return void
	 */
	public function begun(): void {
		$this->started = true;
		++$this->count;
	}

	/**
	 * A unit of this kind took this long. Marks the tick as started (what comes after is not its first unit), but is
	 * not counted (count()): a unit is counted when may_start() let it go, or by begun().
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
		$seconds = round( max( 0.0, $seconds ), 4 );
		if ( isset( $this->slowest[ $kind ] ) && $this->slowest[ $kind ] >= $seconds ) {
			return;
		}
		$this->slowest[ $kind ] = $seconds;
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
	 * What to keep for the next tick: kind => its slowest in the last tick that ran one (this tick's for the kinds it
	 * ran, what was kept for the others), the kinds this tick ran last; at most MAX_KINDS.
	 *
	 * @return array<string, float>
	 */
	public function estimates(): array {
		// A kind whose slowest this tick was nothing (units that cost nothing: PackStep's boundaries) keeps what was kept.
		$ran = array_filter(
			$this->slowest,
			function ( float $seconds, string $kind ): bool {
				return $seconds > 0.0 || ! isset( $this->kept[ $kind ] );
			},
			ARRAY_FILTER_USE_BOTH
		);
		$out = array_diff_key( $this->kept, $ran ) + $ran;
		return array_slice( $out, -self::MAX_KINDS, null, true );
	}
}
