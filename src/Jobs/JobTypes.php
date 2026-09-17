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
		$filtered = apply_filters( 'wpcheckpoint_job_types', $this->builtin );
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
