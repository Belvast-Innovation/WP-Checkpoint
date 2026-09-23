<?php
/**
 * The settings an export job is created with.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Files\Exclusions;
use WPCheckpoint\Files\ScanRoots;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages name option keys and values the caller gave; they are shown by the CLI and the REST layer after cleaning.

/**
 * Validates and normalises the options of an export job before they are
 * stored on it. Options hold identifiers, flags and rules only: content
 * groups, exclusion patterns, table names to leave out, and the policy an
 * unattended run answers the pre-flight's questions with. Unknown keys are
 * refused rather than ignored, so a misspelt option cannot silently mean
 * "default".
 *
 * Shape:
 *   contents:       { database: bool, files: string[] (ScanRoots::GROUPS) }
 *   exclusions:     string[] (globs, each compilable by Exclusions)
 *   exclude_tables: string[] (storable table names)
 *   policy:         { unreadable: ask|continue|fail, oversize: ask|exclude|fail, large_dirs: ask|include }
 *   answers:        anything JobRepository::answer() stored (never given at creation)
 */
final class ExportOptions {

	const POLICIES = array(
		'unreadable' => array( 'ask', 'continue', 'fail' ),
		'oversize'   => array( 'ask', 'exclude', 'fail' ),
		'large_dirs' => array( 'ask', 'include' ),
	);

	/**
	 * The policy of a run nobody attends: skip what cannot be read (and say
	 * so), keep every directory, and let a table with oversized rows fail
	 * the run rather than silently back up less of it.
	 */
	const UNATTENDED = array(
		'unreadable' => 'continue',
		'oversize'   => 'fail',
		'large_dirs' => 'include',
	);

	const MAX_EXCLUSIONS = 1000;
	const MAX_TABLES     = 10000;

	/**
	 * Validate and normalise. Missing keys take their defaults (everything
	 * included, no exclusions, ask every question).
	 *
	 * @param array<mixed> $options Options as given.
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException When a value is not allowed (the message names the key).
	 */
	public static function normalize( array $options ): array {
		$known = array( 'contents', 'exclusions', 'exclude_tables', 'include_tables', 'policy' );
		foreach ( array_keys( $options ) as $key ) {
			if ( ! in_array( $key, $known, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unknown export option "%s".', (string) $key ) );
			}
		}
		$contents = isset( $options['contents'] ) ? $options['contents'] : array();
		if ( ! is_array( $contents ) ) {
			throw new \InvalidArgumentException( '"contents" must be an object.' );
		}
		foreach ( array_keys( $contents ) as $key ) {
			if ( ! in_array( $key, array( 'database', 'files' ), true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unknown key "%s" in "contents".', (string) $key ) );
			}
		}
		$database = ! isset( $contents['database'] ) || true === $contents['database'];
		if ( isset( $contents['database'] ) && ! is_bool( $contents['database'] ) ) {
			throw new \InvalidArgumentException( '"contents.database" must be true or false.' );
		}
		$files = isset( $contents['files'] ) ? $contents['files'] : ScanRoots::GROUPS;
		if ( ! is_array( $files ) ) {
			throw new \InvalidArgumentException( '"contents.files" must be a list of content groups.' );
		}
		$groups = array();
		foreach ( $files as $group ) {
			if ( ! is_string( $group ) || ! in_array( $group, ScanRoots::GROUPS, true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unknown content group "%s"; known: %s.', is_scalar( $group ) ? (string) $group : gettype( $group ), implode( ', ', ScanRoots::GROUPS ) ) );
			}
			if ( ! in_array( $group, $groups, true ) ) {
				$groups[] = $group;
			}
		}
		if ( ! $database && array() === $groups ) {
			throw new \InvalidArgumentException( 'Nothing to back up: neither the database nor any content group is selected.' );
		}

		$exclusions = isset( $options['exclusions'] ) ? $options['exclusions'] : array();
		if ( ! is_array( $exclusions ) || count( $exclusions ) > self::MAX_EXCLUSIONS ) {
			throw new \InvalidArgumentException( sprintf( '"exclusions" must be a list of at most %d patterns.', self::MAX_EXCLUSIONS ) );
		}
		$globs = array();
		foreach ( $exclusions as $glob ) {
			if ( ! is_string( $glob ) || '' === trim( $glob ) || strlen( $glob ) > 1024 ) {
				throw new \InvalidArgumentException( 'Every exclusion must be a non-empty pattern of at most 1024 bytes.' );
			}
			$globs[] = trim( $glob );
		}
		$invalid = ( new Exclusions( $globs, array() ) )->invalid();
		if ( array() !== $invalid ) {
			throw new \InvalidArgumentException( sprintf( 'These exclusion patterns cannot be used: %s.', implode( ', ', $invalid ) ) );
		}

		$names   = self::table_names( $options, 'exclude_tables' );
		$include = self::table_names( $options, 'include_tables' );
		$both    = array_values( array_intersect( $names, $include ) );
		if ( array() !== $both ) {
			throw new \InvalidArgumentException( sprintf( 'These tables are both excluded and included: %s.', implode( ', ', $both ) ) );
		}

		$policy = isset( $options['policy'] ) ? $options['policy'] : array();
		if ( ! is_array( $policy ) ) {
			throw new \InvalidArgumentException( '"policy" must be an object.' );
		}
		foreach ( $policy as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( self::POLICIES[ $key ] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unknown policy "%s"; known: %s.', (string) $key, implode( ', ', array_keys( self::POLICIES ) ) ) );
			}
			if ( ! is_string( $value ) || ! in_array( $value, self::POLICIES[ $key ], true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Policy "%s" must be one of: %s.', $key, implode( ', ', self::POLICIES[ $key ] ) ) );
			}
		}
		$normalized_policy = array();
		foreach ( array_keys( self::POLICIES ) as $key ) {
			$normalized_policy[ $key ] = isset( $policy[ $key ] ) ? (string) $policy[ $key ] : 'ask';
		}

		return array(
			'contents'       => array(
				'database' => $database,
				'files'    => $groups,
			),
			'exclusions'     => $globs,
			'exclude_tables' => $names,
			'include_tables' => $include,
			'policy'         => $normalized_policy,
		);
	}

	/**
	 * A list of table names under an option key, deduplicated.
	 *
	 * @param array<string, mixed> $options Options.
	 * @param string               $key     exclude_tables or include_tables.
	 * @return string[]
	 * @throws \InvalidArgumentException When it is not a list of table names.
	 */
	private static function table_names( array $options, string $key ): array {
		$tables = isset( $options[ $key ] ) ? $options[ $key ] : array();
		if ( ! is_array( $tables ) || count( $tables ) > self::MAX_TABLES ) {
			throw new \InvalidArgumentException( sprintf( '"%s" must be a list of at most %d table names.', $key, self::MAX_TABLES ) );
		}
		$names = array();
		foreach ( $tables as $table ) {
			if ( ! is_string( $table ) || ! DatabaseExportStep::storable_name( $table ) ) {
				throw new \InvalidArgumentException( sprintf( 'Every entry of "%s" must be a table name.', $key ) );
			}
			if ( ! in_array( $table, $names, true ) ) {
				$names[] = $table;
			}
		}
		return $names;
	}
}
