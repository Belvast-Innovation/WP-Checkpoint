<?php
/**
 * What a paused job's questions are about, in words.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Support\Directories;
use WPCheckpoint\Support\Paths;

defined( 'ABSPATH' ) || exit;

/**
 * A question stored on a job holds only an id, a kind, counts and choices
 * (JobRepository::validate_questions()); what it is about (which directory,
 * which table, which files) is in the job's work files. This turns both
 * into one line per question, for every place that asks: the terminal
 * (ExportRun) and the admin page (REST). Every text goes through the given
 * cleaner (JobPresenter::clean()); the work files are read only when the
 * job belongs to the current storage directory.
 */
final class QuestionText {

	/**
	 * Unreadable files listed with their question.
	 */
	const MAX_LISTED = 5;

	/**
	 * The job's questions in words.
	 *
	 * @param Job         $job         Paused job.
	 * @param Directories $directories Current storage directories.
	 * @param callable    $clean       Text cleaner.
	 * @return array<int, array{id: string, kind: string, choices: string[], text: string, listed: string[]}>
	 */
	public static function for_job( Job $job, Directories $directories, callable $clean ): array {
		$findings = array();
		$work     = self::work_dir( $job, $directories );
		if ( '' !== $work && ExportPlan::exists( $work, ExportPlan::REVIEW ) ) {
			try {
				$review   = ExportPlan::read( $work, ExportPlan::REVIEW );
				$findings = isset( $review['findings'] ) && is_array( $review['findings'] ) ? $review['findings'] : array();
			} catch ( \RuntimeException $e ) {
				$findings = array(); // Gone or changed since: each question is still listed, with a generic line.
			}
		}
		$out = array();
		foreach ( $job->questions as $question ) {
			$id     = (string) $question['id'];
			$listed = 'unreadable' === $id && isset( $findings['unreadable']['listed'] ) ? array_slice( array_filter( (array) $findings['unreadable']['listed'], 'is_string' ), 0, self::MAX_LISTED ) : array();
			$out[]  = array(
				'id'      => $id,
				'kind'    => (string) ( $question['kind'] ?? '' ),
				'choices' => array_map( 'strval', (array) ( $question['choices'] ?? array() ) ),
				'text'    => (string) call_user_func( $clean, self::describe( $id, $question, $findings ) ),
				'listed'  => array_map(
					static function ( string $p ) use ( $clean ): string {
						return (string) call_user_func( $clean, $p );
					},
					$listed
				),
			);
		}
		return $out;
	}

	/**
	 * The job's work directory, or '' when the job's storage is not the
	 * current storage directory (another directory choice or installation:
	 * never read).
	 *
	 * @param Job         $job         Job.
	 * @param Directories $directories Current storage directories.
	 * @return string
	 */
	public static function work_dir( Job $job, Directories $directories ): string {
		if ( ! Paths::same_location( $job->storage_path, $directories->base() ) ) {
			return '';
		}
		return Residue::work_dir( $job->storage_path, $job->id );
	}

	/**
	 * What a question is about, in a line (not yet cleaned).
	 *
	 * @param string               $id       Question id.
	 * @param array<string, mixed> $question Question.
	 * @param array<string, mixed> $findings Review findings.
	 * @return string
	 */
	public static function describe( string $id, array $question, array $findings ): string {
		$count = (int) ( $question['count'] ?? 0 );
		if ( 'unreadable' === $id ) {
			$listed = isset( $findings['unreadable']['listed'] ) ? array_slice( array_filter( (array) $findings['unreadable']['listed'], 'is_string' ), 0, self::MAX_LISTED ) : array();
			return sprintf( '%d files cannot be read and would not be in the backup%s. Continue without them, or stop?', $count, array() === $listed ? '' : ' (for example ' . implode( ', ', $listed ) . ')' );
		}
		if ( 1 === preg_match( '/\Alarge_dir_(\d+)\z/', $id, $m ) && isset( $findings['heavy'][ (int) $m[1] ]['p'], $findings['heavy'][ (int) $m[1] ]['bytes'] ) && is_scalar( $findings['heavy'][ (int) $m[1] ]['p'] ) ) {
			$dir = $findings['heavy'][ (int) $m[1] ];
			return sprintf( 'Directory %s holds %d MB (development files, not usually needed to restore the site). Include it, or leave it out?', (string) $dir['p'], (int) ( (int) $dir['bytes'] / 1048576 ) );
		}
		if ( 'large_dirs_more' === $id ) {
			return sprintf( '%d more large directories (listed in the job log). Include them, or leave them out?', $count );
		}
		if ( 1 === preg_match( '/\Aoversize_(\d+)\z/', $id, $m ) && isset( $findings['oversize'][ (int) $m[1] ]['table'], $findings['oversize'][ (int) $m[1] ]['limit'] ) && is_scalar( $findings['oversize'][ (int) $m[1] ]['table'] ) && array_key_exists( 'count', $findings['oversize'][ (int) $m[1] ] ) ) {
			$table = $findings['oversize'][ (int) $m[1] ];
			$rows  = null === $table['count'] ? 'may have rows' : sprintf( 'has %d rows', (int) $table['count'] );
			return sprintf( 'Table %s %s larger than the single-row limit of %d bytes. Leave those rows out, or stop?', (string) $table['table'], $rows, (int) $table['limit'] );
		}
		if ( 'oversize_more' === $id ) {
			return sprintf( '%d more tables have rows larger than the single-row limit (listed in the job log). Leave those rows out, or stop?', $count );
		}
		return sprintf( 'A decision is needed (%s).', (string) ( $question['kind'] ?? $id ) );
	}
}
