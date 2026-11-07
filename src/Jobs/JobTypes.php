<?php
/**
 * Registry of job types.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in types plus those added through the wpcheckpoint_job_types filter.
 */
final class JobTypes {

	/**
	 * Whether the types pass through the wpcheckpoint_job_types filter (in WordPress; a run without it has no filters).
	 *
	 * @var bool
	 */
	private $filtered;

	/**
	 * Constructor.
	 *
	 * @param bool $filtered Whether the types pass through the filter (false where WordPress is not loaded).
	 */
	public function __construct( bool $filtered = true ) {
		$this->filtered = $filtered;
	}

	/**
	 * Built-in types.
	 *
	 * @var JobType[]
	 */
	private $builtin = array();

	/**
	 * Register a built-in type.
	 *
	 * @param JobType $type Type.
	 * @return void
	 */
	public function add( JobType $type ): void {
		$this->builtin[ $type->id() ] = $type;
	}

	/**
	 * All types keyed by id.
	 *
	 * @return array<string, JobType>
	 */
	public function all(): array {
		/**
		 * Filters the registered job types.
		 *
		 * @param JobType[] $types Types keyed by id.
		 */
		$filtered = $this->filtered ? apply_filters( 'wpcheckpoint_job_types', $this->builtin ) : $this->builtin;
		$types    = array();
		foreach ( (array) $filtered as $type ) {
			if ( $type instanceof JobType && 1 === preg_match( '/^[a-z0-9_-]{1,64}$/', $type->id() ) ) {
				$types[ $type->id() ] = $type;
			}
		}
		return $types;
	}

	/**
	 * A type by id, or null.
	 *
	 * @param string $id Type id.
	 * @return JobType|null
	 */
	public function get( string $id ) {
		$types = $this->all();
		return isset( $types[ $id ] ) ? $types[ $id ] : null;
	}
}
