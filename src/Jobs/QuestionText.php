<?php
/**
 * What a paused job's questions are about, in words.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

use WPCheckpoint\Restore\LinkedTargets;
use WPCheckpoint\Restore\IncomingQuestions;
use WPCheckpoint\Restore\IncomingTables;
use WPCheckpoint\Restore\RestoreFiles;
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
	 * Unreadable files, or tables, listed with their question.
	 */
	const MAX_LISTED = 5;

	/**
	 * The restore's questions about tables another installation may use (RestorePreflightStep), by their kind (their
	 * ids carry a digest of their tables, IncomingQuestions): the kind of table each is about (IncomingTables).
	 */
	const TABLE_QUESTIONS = array(
		'tables_of_either_installation'           => IncomingTables::UNCERTAIN,
		'tables_shared_with_another_installation' => IncomingTables::SHARED,
	);

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
		$tables   = array();
		$work     = self::work_dir( $job, $directories );
		if ( '' !== $work && ExportPlan::exists( $work, ExportPlan::REVIEW ) ) {
			try {
				$review   = ExportPlan::read( $work, ExportPlan::REVIEW );
				$findings = isset( $review['findings'] ) && is_array( $review['findings'] ) ? $review['findings'] : array();
			} catch ( \RuntimeException $e ) {
				$findings = array(); // Gone or changed since: each question is still listed, with a generic line.
			}
		}
		if ( '' !== $work && ExportPlan::exists( $work, RestoreFiles::INCOMING ) ) {
			try {
				$tables = ExportPlan::read( $work, RestoreFiles::INCOMING );
			} catch ( \RuntimeException $e ) {
				$tables = array(); // As above: the question stays, without its tables.
			}
		}
		$out = array();
		foreach ( $job->questions as $question ) {
			$id     = (string) $question['id'];
			$kind   = (string) ( $question['kind'] ?? '' );
			$listed = 'unreadable' === $id && isset( $findings['unreadable']['listed'] ) ? array_slice( array_filter( (array) $findings['unreadable']['listed'], 'is_string' ), 0, self::MAX_LISTED ) : array();
			$shown  = array();
			if ( isset( self::TABLE_QUESTIONS[ $kind ] ) && self::lists( $id, $kind, $tables ) ) {
				$shown  = $tables;
				$listed = array_slice( array_filter( (array) $tables[ self::TABLE_QUESTIONS[ $kind ] ], 'is_string' ), 0, self::MAX_LISTED );
			}
			if ( LinkedTargets::KIND === $kind && '' !== $work ) {
				$listed = self::linked( $id, $work, $clean );
			}
			$out[] = array(
				'id'      => $id,
				'kind'    => (string) ( $question['kind'] ?? '' ),
				'choices' => array_map( 'strval', (array) ( $question['choices'] ?? array() ) ),
				'text'    => (string) call_user_func( $clean, self::describe( $id, $question, $findings, $shown ) ),
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
	 * Whether the work file lists the tables (and evidence) of the question, as its id names them: a file written by
	 * another run (one that outlived its lease) may list others, and then none are shown. The file holds the names
	 * made valid UTF-8 for display, the id their bytes: a name that is not UTF-8 is not listed (the question, its
	 * count and the answer are not affected).
	 *
	 * @param string               $id     The question's id.
	 * @param string               $kind   The question's kind.
	 * @param array<string, mixed> $tables RestoreFiles::INCOMING.
	 * @return bool
	 */
	private static function lists( string $id, string $kind, array $tables ): bool {
		$key = array_search( $kind, IncomingQuestions::QUESTION_KINDS, true );
		if ( ! is_string( $key ) || ! isset( $tables[ self::TABLE_QUESTIONS[ $kind ] ] ) ) {
			return false;
		}
		$evidence = array_filter( (array) ( $tables['evidence'] ?? array() ), 'is_string' );
		return hash_equals( IncomingQuestions::id( $key, array_filter( (array) $tables[ self::TABLE_QUESTIONS[ $kind ] ], 'is_string' ), $evidence, ! empty( $tables['over'] ) ), $id );
	}

	/**
	 * The lines of the question about content directories that are not positively this site's, when its work
	 * file names the groups and targets its id was made from (a file written by another run may name others: then
	 * none are listed). Each target masked, numbered where two read the same (LinkedTargets::lines()).
	 *
	 * @param string   $id    The question's id.
	 * @param string   $work  Work directory.
	 * @param callable $clean Text cleaner.
	 * @return string[]
	 */
	private static function linked( string $id, string $work, callable $clean ): array {
		try {
			$file = ExportPlan::exists( $work, RestoreFiles::LINKED ) ? ExportPlan::read( $work, RestoreFiles::LINKED ) : array();
		} catch ( \RuntimeException $e ) {
			return array();
		}
		$why = array();
		foreach ( (array) ( $file['hex'] ?? array() ) as $group => $entry ) {
			$target = is_array( $entry ) ? self::unhex( $entry['target'] ?? null ) : false;
			$at     = is_array( $entry ) ? self::unhex( $entry['at'] ?? null ) : false;
			if ( false === $target || false === $at || ! is_string( $entry['verdict'] ?? null ) ) {
				return array();
			}
			$why[ (string) $group ] = array(
				'target'  => $target,
				'verdict' => $entry['verdict'],
				'at'      => $at,
			);
		}
		if ( array() === $why || ! hash_equals( LinkedTargets::id( $why ), $id ) ) {
			return array();
		}
		$entries = array();
		foreach ( (array) ( $file['entries'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['group'] ?? null ) && is_string( $entry['target'] ?? null ) && is_string( $entry['relation'] ?? null ) ) {
				$entries[] = array(
					'group'    => $entry['group'],
					'target'   => $entry['target'],
					'relation' => $entry['relation'],
					'verdict'  => is_string( $entry['verdict'] ?? null ) ? $entry['verdict'] : LinkedTargets::OUTSIDE,
					'at'       => is_string( $entry['at'] ?? null ) ? $entry['at'] : '',
				);
			}
		}
		return LinkedTargets::lines( $entries, $clean );
	}

	/**
	 * Bytes from hex as the work file holds them, or false when it is not hex.
	 *
	 * @param mixed $hex Hex.
	 * @return string|false
	 */
	private static function unhex( $hex ) {
		return is_string( $hex ) && 1 === preg_match( '/\A(?:[0-9a-f]{2})*\z/', $hex ) ? hex2bin( $hex ) : false;
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
		$base = $directories->base();
		if ( '' === $base || ! Paths::same_location( $job->storage_path, $base ) ) {
			return '';
		}
		return Residue::work_dir( $base, $job->id );
	}

	/**
	 * What a question is about, in a line (not yet cleaned).
	 *
	 * @param string               $id       Question id.
	 * @param array<string, mixed> $question Question.
	 * @param array<string, mixed> $findings Review findings.
	 * @param array<string, mixed> $tables   The restore's tables another installation may use (RestoreFiles::INCOMING).
	 * @return string
	 */
	public static function describe( string $id, array $question, array $findings, array $tables = array() ): string {
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
		if ( 'free_space' === $id ) {
			return sprintf( 'This server does not say how much disk space is free, so whether the restore\'s staged files (%d MB with a margin) fit cannot be confirmed. Continue anyway, or stop?', (int) ceil( (int) ( $question['bytes'] ?? 0 ) / 1048576 ) );
		}
		$kind = (string) ( $question['kind'] ?? '' );
		if ( 'tables_of_either_installation' === $kind ) {
			return sprintf(
				/* translators: %d: number of tables */
				_n(
					'%d table of the backup has the name of a table that may belong to this site or to another WordPress installation in the same database (listed below, all of them in the job log): its name fits both. Restore it: if it is the other installation\'s, its data is replaced by the backup\'s. Or leave it out: if it is this site\'s, it keeps its current data and is not restored.',
					'%d tables of the backup have the names of tables that may belong to this site or to another WordPress installation in the same database (listed below, all of them in the job log): their names fit both. Restore them: if they are the other installation\'s, their data is replaced by the backup\'s. Or leave them out: if they are this site\'s, they keep their current data and are not restored.',
					$count,
					'wp-checkpoint'
				),
				$count
			);
		}
		if ( 'tables_shared_with_another_installation' === $kind ) {
			$evidence = array_slice( array_filter( (array) ( $tables['evidence'] ?? array() ), 'is_string' ), 0, self::MAX_LISTED );
			$found    = '';
			if ( array() !== $evidence ) {
				/* translators: %s: table prefixes, comma-separated */
				$found .= ' ' . sprintf( __( 'This site\'s user table holds the roles of users of another installation, with the table prefix %s.', 'wp-checkpoint' ), implode( ', ', $evidence ) );
			}
			if ( ! empty( $tables['over'] ) ) {
				$found .= ' ' . __( 'This site\'s user table holds the roles of more other installations than could be told apart, so the restore counts it as shared.', 'wp-checkpoint' );
			}
			return sprintf(
				/* translators: %d: number of tables */
				_n(
					'%d table of the backup is used by this site and by another WordPress installation in the same database (listed below, all of them in the job log), for example a users table both share. Restore it: the other installation\'s data in it is replaced by the backup\'s too. Or leave it out: it keeps its current data, for this site as well.',
					'%d tables of the backup are used by this site and by another WordPress installation in the same database (listed below, all of them in the job log), for example a users table both share. Restore them: the other installation\'s data in them is replaced by the backup\'s too. Or leave them out: they keep their current data, for this site as well.',
					$count,
					'wp-checkpoint'
				),
				$count
			) . $found;
		}
		if ( LinkedTargets::KIND === $kind ) {
			return sprintf(
				/* translators: %d: number of content directories */
				_n(
					'%d content directory of this site is not positively this site\'s own (listed below, with why): it is outside this site\'s directories, or inside another WordPress installation, or whether it is this site\'s could not be told. The restore replaces a directory where it is, so it would replace the files there, which may be another installation\'s (a staging site whose uploads are the production site\'s, through a link or its settings, for example). Swap it as usual, or leave it out of the restore: it then stays as it is, and the job log says it was not restored. If it is this site\'s own (a deployment\'s shared directory, for example), swap it.',
					'%d content directories of this site are not positively this site\'s own (listed below, with why): each is outside this site\'s directories, or inside another WordPress installation, or whether it is this site\'s could not be told. The restore replaces a directory where it is, so it would replace the files there, which may be another installation\'s (a staging site whose uploads are the production site\'s, through a link or its settings, for example). Swap them as usual, or leave them out of the restore: they then stay as they are, and the job log says they were not restored. If they are this site\'s own (a deployment\'s shared directories, for example), swap them.',
					$count,
					'wp-checkpoint'
				),
				$count
			);
		}
		if ( 'oversize_more' === $id ) {
			return sprintf( '%d more tables have rows larger than the single-row limit (listed in the job log). Leave those rows out, or stop?', $count );
		}
		return sprintf( 'A decision is needed (%s).', (string) ( $question['kind'] ?? $id ) );
	}
}
