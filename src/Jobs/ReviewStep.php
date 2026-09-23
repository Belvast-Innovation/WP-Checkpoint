<?php
/**
 * The step that puts the pre-flight's findings to the user, once.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Jobs;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages carry table names, paths and numbers; the runner stores them through the redactor and the presenter cleans them before display.

/**
 * Reads two files that no longer change (preflight.json, scan.summary.json)
 * and writes one of its own (review.json), whole, through a rename. With a
 * policy in the options, or answers from an earlier pause, the decisions
 * follow at once; otherwise the questions go to the user and the job
 * pauses. After the answer the same step runs again from the same inputs
 * and derives the same findings, so running it twice never adds a rule
 * twice: the decisions are a function of the inputs and the answers.
 *
 * Findings: files that cannot be read (skipped or stop), heavy
 * directories such as node_modules (kept or left out, each one its own
 * question), tables with rows over the single-row limit (their oversized
 * rows left out, or stop), files too large for this platform (always
 * stop, with the reason). The questions point at review.json for the
 * lists; they carry counts and ids only.
 */
final class ReviewStep implements Step {

	const ID = 'review';

	const HEAVY_MIN_BYTES = 52428800;
	const MAX_LISTED      = 10;

	/**
	 * Step id.
	 *
	 * @return string
	 */
	public function id(): string {
		return self::ID;
	}

	/**
	 * Decide or ask.
	 *
	 * @param JobContext $context Context.
	 * @return StepResult
	 * @throws TransientFailure When review.json cannot be written.
	 * @throws \RuntimeException When the export must stop (the message says why).
	 */
	public function run( JobContext $context ): StepResult {
		$work      = $context->work_path();
		$options   = ExportOptions::normalize( self::without_answers( $context->options() ) );
		$answers   = isset( $context->options()['answers'] ) && is_array( $context->options()['answers'] ) ? $context->options()['answers'] : array();
		$preflight = ExportPlan::read( $work, ExportPlan::PREFLIGHT );
		$scan      = ExportPlan::exists( $work, FileScanStep::SUMMARY ) ? ExportPlan::read( $work, FileScanStep::SUMMARY ) : array();
		$findings  = self::findings( $preflight, $scan );

		if ( $findings['too_large']['count'] > 0 ) {
			// The threshold the scanner judged with, recorded by it: the message never computes its own.
			$limit = isset( $scan['limits']['max_file_bytes'] ) ? (int) $scan['limits']['max_file_bytes'] : 0;
			$bits  = isset( $preflight['checks']['int_size'] ) ? (int) $preflight['checks']['int_size'] * 8 : 0;
			if ( 'index' === ( $scan['limits']['max_file_limit'] ?? 'int_size' ) ) {
				throw new \RuntimeException( sprintf( '%d files are larger than %d MB, the largest file the backup format can describe: %s. Move them out of the site or exclude them.', $findings['too_large']['count'], (int) ( $limit / 1048576 ), implode( ', ', $findings['too_large']['listed'] ) ) );
			}
			throw new \RuntimeException( sprintf( '%d files are larger than %d MB, the largest file a backup made by this server\'s %d-bit PHP can hold: %s. Move them out of the site or exclude them, or run the backup on 64-bit PHP.', $findings['too_large']['count'], (int) ( $limit / 1048576 ), $bits, implode( ', ', $findings['too_large']['listed'] ) ) );
		}

		list( $decisions, $questions ) = self::decide( $findings, $options['policy'], $answers );
		if ( array() !== $questions ) {
			ExportPlan::write( $work, ExportPlan::REVIEW, array( 'findings' => $findings ) );
			return StepResult::ask(
				array(),
				$questions,
				sprintf(
					/* translators: %d: number of questions */
					__( 'Waiting for your decision on %d questions', 'wp-checkpoint' ),
					count( $questions )
				)
			);
		}
		ExportPlan::write(
			$work,
			ExportPlan::REVIEW,
			array(
				'findings'  => $findings,
				'decisions' => $decisions,
				'asked'     => array() !== $answers,
			)
		);
		foreach ( $decisions['notes'] as $note ) {
			$context->logger()->info( $note );
		}
		return StepResult::done( __( 'Review finished', 'wp-checkpoint' ) );
	}

	/**
	 * Nothing to do: review.json lives in the work directory, which the
	 * engine removes; no tables or external resources.
	 *
	 * @param JobContext $context Context.
	 * @return void
	 */
	public function cleanup( JobContext $context ): void {
		unset( $context );
	}

	/**
	 * The options without the answers key (which the normaliser does not take).
	 *
	 * @param array<string, mixed> $options Options.
	 * @return array<string, mixed>
	 */
	private static function without_answers( array $options ): array {
		unset( $options['answers'] );
		return $options;
	}

	/**
	 * The findings from the two inputs. Pure.
	 *
	 * @param array<string, mixed> $preflight preflight.json.
	 * @param array<string, mixed> $scan      scan.summary.json (empty when no files were scanned).
	 * @return array{unreadable: array{count: int, listed: string[]}, too_large: array{count: int, listed: string[]}, over_volume: array{count: int, listed: string[]}, heavy: array<int, array{p: string, bytes: int}>, oversize: array<int, array{table: string, exact: bool, count: int|null, limit: int}>, foreign: array<int, array{prefix: string, count: int, listed: string[], kept: int, kept_listed: string[]}>}
	 */
	public static function findings( array $preflight, array $scan ): array {
		$lists  = isset( $scan['lists'] ) && is_array( $scan['lists'] ) ? $scan['lists'] : array();
		$counts = isset( $scan['counts'] ) && is_array( $scan['counts'] ) ? $scan['counts'] : array();
		$listed = static function ( string $kind ) use ( $lists, $counts ): array {
			$list = isset( $lists[ $kind ] ) && is_array( $lists[ $kind ] ) ? array_values( array_map( 'strval', $lists[ $kind ] ) ) : array();
			return array(
				'count'  => isset( $counts[ $kind ] ) ? max( (int) $counts[ $kind ], count( $list ) ) : count( $list ),
				'listed' => $list,
			);
		};
		$heavy  = array();
		foreach ( isset( $lists['heavy'] ) && is_array( $lists['heavy'] ) ? $lists['heavy'] : array() as $p => $bytes ) {
			if ( (int) $bytes >= self::HEAVY_MIN_BYTES ) {
				$heavy[] = array(
					'p'     => (string) $p,
					'bytes' => (int) $bytes,
				);
			}
		}
		usort(
			$heavy,
			static function ( array $a, array $b ): int {
				if ( $a['bytes'] !== $b['bytes'] ) {
					return $b['bytes'] <=> $a['bytes'];
				}
				return strcmp( $a['p'], $b['p'] );
			}
		);
		$oversize = array();
		foreach ( isset( $preflight['findings']['oversize'] ) && is_array( $preflight['findings']['oversize'] ) ? $preflight['findings']['oversize'] : array() as $finding ) {
			if ( is_array( $finding ) && isset( $finding['table'] ) ) {
				$oversize[] = array(
					'table' => (string) $finding['table'],
					'exact' => ! empty( $finding['exact'] ),
					'count' => isset( $finding['count'] ) && is_int( $finding['count'] ) ? $finding['count'] : null,
					'limit' => isset( $finding['limit'] ) ? (int) $finding['limit'] : 0,
				);
			}
		}
		$foreign = array();
		foreach ( isset( $preflight['findings']['foreign'] ) && is_array( $preflight['findings']['foreign'] ) ? $preflight['findings']['foreign'] : array() as $group ) {
			if ( is_array( $group ) && isset( $group['prefix'] ) ) {
				$foreign[] = array(
					'prefix'      => (string) $group['prefix'],
					'count'       => (int) ( $group['count'] ?? 0 ),
					'listed'      => isset( $group['listed'] ) && is_array( $group['listed'] ) ? array_values( array_map( 'strval', $group['listed'] ) ) : array(),
					'kept'        => (int) ( $group['kept'] ?? 0 ),
					'kept_listed' => isset( $group['kept_listed'] ) && is_array( $group['kept_listed'] ) ? array_values( array_map( 'strval', $group['kept_listed'] ) ) : array(),
				);
			}
		}
		return array(
			'unreadable'  => $listed( 'unreadable' ),
			'too_large'   => $listed( 'too_large' ),
			'over_volume' => $listed( 'over_volume' ),
			'heavy'       => $heavy,
			'oversize'    => $oversize,
			'foreign'     => $foreign,
		);
	}

	/**
	 * Decisions from a policy and answers, and the questions still open.
	 * Pure: the same inputs give the same output.
	 *
	 * @param array<string, mixed>  $findings Findings.
	 * @param array<string, string> $policy   Policy (each value ask|...).
	 * @param array<string, mixed>  $answers  Answers given so far.
	 * @return array{0: array{exclude_tables: string[], exclude_oversize: string[], exclude_paths: string[], notes: string[]}, 1: array<int, array<string, mixed>>}
	 * @throws \RuntimeException When a decision is to stop.
	 */
	public static function decide( array $findings, array $policy, array $answers ): array {
		$decisions = array(
			'exclude_tables'   => array(),
			'exclude_oversize' => array(),
			'exclude_paths'    => array(),
			'notes'            => array(),
		);
		$questions = array();
		$answer    = static function ( string $id, string $policy_key, array $choices ) use ( $policy, $answers ) {
			if ( isset( $answers[ $id ] ) && is_string( $answers[ $id ] ) && in_array( $answers[ $id ], $choices, true ) ) {
				return $answers[ $id ];
			}
			$value = isset( $policy[ $policy_key ] ) ? (string) $policy[ $policy_key ] : 'ask';
			if ( 'ask' === $value ) {
				return null;
			}
			return 'fail' === $value ? 'stop' : $value;
		};

		foreach ( isset( $findings['foreign'] ) ? $findings['foreign'] : array() as $group ) {
			// Not a question: decided by rule and named, so that a misjudged table can be added back or left out.
			if ( $group['count'] > 0 ) {
				$decisions['notes'][] = sprintf( '%d tables with the prefix %s are the core tables of another WordPress installation in the same database and are not in the backup (for example %s). If they belong to this site, include them by name (wp wpcheckpoint export --include-table=...).', $group['count'], $group['prefix'], implode( ', ', array_slice( $group['listed'], 0, 3 ) ) );
			}
			if ( $group['kept'] > 0 ) {
				$decisions['notes'][] = sprintf( '%d other tables with the prefix %s are in the backup although they may belong to that installation (for example %s). If they do, leave them out by name (wp wpcheckpoint export --exclude-table=...).', $group['kept'], $group['prefix'], implode( ', ', array_slice( $group['kept_listed'], 0, 3 ) ) );
			}
		}

		if ( $findings['unreadable']['count'] > 0 ) {
			$choice = $answer( 'unreadable', 'unreadable', array( 'continue', 'stop' ) );
			if ( null === $choice ) {
				$questions[] = array(
					'id'      => 'unreadable',
					'kind'    => 'unreadable',
					'count'   => $findings['unreadable']['count'],
					'file'    => ExportPlan::REVIEW,
					'choices' => array( 'continue', 'stop' ),
				);
			} elseif ( 'stop' === $choice ) {
				throw new \RuntimeException( sprintf( 'Stopped: %d files cannot be read and the backup would not contain them (for example %s).', $findings['unreadable']['count'], implode( ', ', array_slice( $findings['unreadable']['listed'], 0, 3 ) ) ) );
			} else {
				$decisions['notes'][] = sprintf( '%d files could not be read and are not in the backup (see the scan summary).', $findings['unreadable']['count'] );
			}
		}

		foreach ( $findings['heavy'] as $i => $dir ) {
			$id     = $i < self::MAX_LISTED ? 'large_dir_' . $i : 'large_dirs_more';
			$choice = $answer( $id, 'large_dirs', array( 'include', 'exclude' ) );
			if ( null === $choice ) {
				if ( $i < self::MAX_LISTED ) {
					$questions[] = array(
						'id'      => $id,
						'kind'    => 'large_dir',
						'bytes'   => $dir['bytes'],
						'file'    => ExportPlan::REVIEW,
						'choices' => array( 'include', 'exclude' ),
					);
				} elseif ( self::MAX_LISTED === $i ) {
					$questions[] = array(
						'id'      => $id,
						'kind'    => 'large_dirs',
						'count'   => count( $findings['heavy'] ) - self::MAX_LISTED,
						'file'    => ExportPlan::REVIEW,
						'choices' => array( 'include', 'exclude' ),
					);
				}
			} elseif ( 'exclude' === $choice ) {
				$decisions['exclude_paths'][] = $dir['p']; // A literal path, never a pattern: see ExportPlan::effective().
				$decisions['notes'][]         = sprintf( 'Directory %s (%d MB) was left out of the backup, as chosen.', $dir['p'], (int) ( $dir['bytes'] / 1048576 ) );
			}
		}

		foreach ( $findings['oversize'] as $i => $finding ) {
			$id     = $i < self::MAX_LISTED ? 'oversize_' . $i : 'oversize_more';
			$choice = $answer( $id, 'oversize', array( 'exclude', 'stop' ) );
			if ( null === $choice ) {
				if ( $i < self::MAX_LISTED ) {
					$question = array(
						'id'      => $id,
						'kind'    => $finding['exact'] ? 'oversize' : 'oversize_possible',
						'file'    => ExportPlan::REVIEW,
						'choices' => array( 'exclude', 'stop' ),
					);
					if ( null !== $finding['count'] ) {
						$question['count'] = $finding['count'];
					}
					$questions[] = $question;
				} elseif ( self::MAX_LISTED === $i ) {
					$questions[] = array(
						'id'      => $id,
						'kind'    => 'oversize_more',
						'count'   => count( $findings['oversize'] ) - self::MAX_LISTED,
						'file'    => ExportPlan::REVIEW,
						'choices' => array( 'exclude', 'stop' ),
					);
				}
			} elseif ( 'stop' === $choice ) {
				throw new \RuntimeException( sprintf( 'Stopped: table %s has rows larger than the single-row limit of %d bytes (as SQL)%s. Reduce them, or choose to leave them out.', $finding['table'], $finding['limit'], null !== $finding['count'] ? sprintf( ' (%d rows)', $finding['count'] ) : ' (found by sampling)' ) );
			} else {
				$decisions['exclude_oversize'][] = $finding['table'];
				$decisions['notes'][]            = sprintf( 'Table %s: rows larger than the single-row limit are left out, as chosen%s.', $finding['table'], null !== $finding['count'] ? sprintf( ' (%d rows at the pre-flight)', $finding['count'] ) : '' );
			}
		}
		return array( $decisions, $questions );
	}
}
