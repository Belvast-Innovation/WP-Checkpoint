<?php

namespace WPCheckpoint\Tests\Fixtures\Jobs;

use WPCheckpoint\Jobs\Budget;
use WPCheckpoint\Jobs\Job;
use WPCheckpoint\Jobs\JobContext;
use WPCheckpoint\Support\Logger;
use WPCheckpoint\Support\Redactor;

/**
 * A job context over a temporary storage root for unit tests of steps:
 * the work directory is <root>/tmp/job-<id>, checkpoints are recorded,
 * the clock is a counter the test advances (each reading moves it by
 * $tick seconds, so a step that measures a unit sees it take time).
 */
final class WorkContext {

	/** @var string */
	public $root;

	/** @var int */
	public $id;

	/** @var float */
	public $now = 1000.0;

	/** @var float */
	public $tick = 0.0;

	/** @var array<int, array{cursor: array<string, mixed>, percent: int, message: string}> */
	public $checkpoints = array();

	/** @var array<string, mixed> */
	public $options = array();

	/** @var callable|null Lease callback given to the context (see JobContext), null for none. */
	public $lease;

	public function __construct( string $prefix = 'wpcheckpoint-step-', int $id = 7 ) {
		$this->root = sys_get_temp_dir() . '/' . $prefix . bin2hex( random_bytes( 4 ) );
		$this->id   = $id;
		mkdir( $this->root . '/tmp/job-' . $id, 0700, true );
		mkdir( $this->root . '/backups', 0700 );
		mkdir( $this->root . '/logs', 0700 );
	}

	public function work(): string {
		return $this->root . '/tmp/job-' . $this->id;
	}

	public function backups(): string {
		return $this->root . '/backups';
	}

	public function remove(): void {
		exec( 'rm -rf ' . escapeshellarg( $this->root ) );
	}

	/**
	 * A context with the given cursor and budget.
	 *
	 * @param array<string, mixed> $cursor  Cursor.
	 * @param int                  $seconds Time budget.
	 */
	public function context( array $cursor = array(), int $seconds = 20 ): JobContext {
		$job               = new Job();
		$job->id           = $this->id;
		$job->storage_path = $this->root;
		$job->options      = $this->options;
		$self              = $this;
		return new JobContext(
			$job,
			$cursor,
			new Budget( $seconds, 32 * 1048576, false ),
			new Logger( $this->root . '/logs/job.log', new Redactor() ),
			static function () use ( $self ): float {
				$self->now += $self->tick;
				return $self->now;
			},
			static function (): int {
				return 10 * 1048576;
			},
			$this->now,
			-1,
			static function ( array $cursor, int $percent, string $message ) use ( $self ): void {
				$self->checkpoints[] = array(
					'cursor'  => $cursor,
					'percent' => $percent,
					'message' => $message,
				);
			},
			$this->lease
		);
	}

	/**
	 * The last checkpointed cursor, or the given one when none was recorded.
	 *
	 * @param array<string, mixed> $fallback Fallback.
	 * @return array<string, mixed>
	 */
	public function last_cursor( array $fallback = array() ): array {
		return array() === $this->checkpoints ? $fallback : $this->checkpoints[ count( $this->checkpoints ) - 1 ]['cursor'];
	}

	public function log(): string {
		return (string) file_get_contents( $this->root . '/logs/job.log' );
	}
}
