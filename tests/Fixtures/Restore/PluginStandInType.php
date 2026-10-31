<?php

namespace WPCheckpoint\Tests\Fixtures\Restore;

use WPCheckpoint\Jobs\JobType;

/**
 * A registered job type as PluginStandIn hands it out: the same type, whose steps that read the running plugin are
 * given the test's stand-in (PluginStandIn::give()) each time they are asked for.
 */
final class PluginStandInType implements JobType {

	/** @var JobType */
	private $type;

	public function __construct( JobType $type ) {
		$this->type = $type;
	}

	public function id(): string {
		return $this->type->id();
	}

	public function label(): string {
		return $this->type->label();
	}

	public function step_ids(): array {
		return $this->type->step_ids();
	}

	public function steps(): array {
		$steps = $this->type->steps();
		foreach ( $steps as $step ) {
			PluginStandIn::give( $step );
		}
		return $steps;
	}
}
