<?php

namespace WPCheckpoint\Tests\Fixtures\Jobs;

use WPCheckpoint\Jobs\JobType;
use WPCheckpoint\Jobs\Step;

/**
 * A job type built from a list of steps (tests only).
 */
final class FixtureJobType implements JobType {

	/** @var string */
	private $id;

	/** @var Step[] */
	private $steps;

	/**
	 * @param Step[] $steps Steps in order.
	 */
	public function __construct( string $id, array $steps ) {
		$this->id    = $id;
		$this->steps = $steps;
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return ucfirst( $this->id );
	}

	public function step_ids(): array {
		return array_map(
			static function ( Step $step ): string {
				return $step->id();
			},
			$this->steps
		);
	}

	public function steps(): array {
		return $this->steps;
	}
}
