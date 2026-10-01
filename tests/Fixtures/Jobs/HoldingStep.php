<?php

namespace WPCheckpoint\Tests\Fixtures\Jobs;

use WPCheckpoint\Jobs\HoldsSite;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Jobs\Step;
use WPCheckpoint\Jobs\StepResult;

/**
 * A step that says what it has done to the site by its cursor's "site" (tests only): an int, or none for null.
 */
class HoldingStep implements Step, HoldsSite {

	/** @var string */
	private $id;

	/** @var callable */
	private $run;

	/** @var callable|null */
	private $cleanup;

	public function __construct( string $id, callable $run, $cleanup = null ) {
		$this->id      = $id;
		$this->run     = $run;
		$this->cleanup = is_callable( $cleanup ) ? $cleanup : null;
	}

	public static function site_state( array $cursor ) {
		return isset( $cursor['site'] ) ? (int) $cursor['site'] : null;
	}

	public function id(): string {
		return $this->id;
	}

	public function run( JobContext $context ): StepResult {
		return call_user_func( $this->run, $context );
	}

	public function cleanup( JobContext $context ): void {
		if ( null !== $this->cleanup ) {
			call_user_func( $this->cleanup, $context );
		}
	}
}
