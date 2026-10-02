<?php
/**
 * The questions about tables another installation may use, and which answer holds for which tables.
 *
 * @package WPCheckpoint
 */

namespace WPCheckpoint\Restore;

defined( 'ABSPATH' ) || exit;

/**
 * Pure PHP. A question about one kind of table (IncomingTables::UNCERTAIN, SHARED) is named after the tables it is
 * about: its id is the kind's name and a digest of the canonical form of its list (the kind, the tables sorted and
 * each once, and for the shared kind the evidence too: the other installations' prefixes found, and whether the
 * search could not finish). An answer is stored under the id of the question it answered, so it holds for exactly
 * that list: when the list changes, its question has another id, the old answer matches nothing, and the question is
 * asked. Nothing else ties an answer to its tables (the work file that lists them is only shown).
 */
final class IncomingQuestions {

	/**
	 * Policy key (RestoreJob::POLICIES) => the kind of table it decides about.
	 */
	const KINDS = array(
		'uncertain_tables' => IncomingTables::UNCERTAIN,
		'shared_tables'    => IncomingTables::SHARED,
	);

	/**
	 * Policy key => the kind of its question (QuestionText).
	 */
	const QUESTION_KINDS = array(
		'uncertain_tables' => 'tables_of_either_installation',
		'shared_tables'    => 'tables_shared_with_another_installation',
	);

	/**
	 * The id of the question about these tables.
	 *
	 * @param string   $key      Policy key.
	 * @param string[] $tables   The tables.
	 * @param string[] $evidence The other installations' prefixes found (the shared kind).
	 * @param bool     $over     Whether the search for them could not finish (the shared kind).
	 * @return string
	 */
	public static function id( string $key, array $tables, array $evidence = array(), bool $over = false ): string {
		$canonical = array( $key, self::canonical( $tables ) );
		if ( IncomingTables::SHARED === self::KINDS[ $key ] ) {
			$canonical[] = self::canonical( $evidence );
			$canonical[] = $over ? 'over' : '';
		}
		// Hex: names are bytes, not necessarily UTF-8 or free of separators.
		return $key . '_' . substr( hash( 'sha256', implode( ';', $canonical ) ), 0, 16 );
	}

	/**
	 * What to do with each kind present, and what to ask.
	 *
	 * @param array<string, string[]> $listed   Kind (IncomingTables::UNCERTAIN, SHARED) => final names.
	 * @param string[]                $evidence The other installations' prefixes found.
	 * @param bool                    $over     Whether the search for them could not finish.
	 * @param array<string, mixed>    $answers  The job's answers (question id => choice).
	 * @param array<string, string>   $policy   RestoreJob::options()'s policy.
	 * @return array{decided: array<string, string>, from: array<string, string>, questions: array<int, array{id: string, kind: string, count: int, choices: string[]}>} Kind => restore or exclude; kind => "answer" or "policy"; the questions.
	 */
	public static function decide( array $listed, array $evidence, bool $over, array $answers, array $policy ): array {
		$out = array(
			'decided'   => array(),
			'from'      => array(),
			'questions' => array(),
		);
		foreach ( self::KINDS as $key => $kind ) {
			$tables = $listed[ $kind ] ?? array();
			if ( array() === $tables ) {
				continue;
			}
			$id     = self::id( $key, $tables, $evidence, $over );
			$answer = $answers[ $id ] ?? null;
			if ( 'restore' === $answer || 'exclude' === $answer ) {
				$out['decided'][ $kind ] = $answer;
				$out['from'][ $kind ]    = 'answer';
				continue;
			}
			$choice = $policy[ $key ] ?? 'ask';
			if ( 'restore' === $choice || 'exclude' === $choice ) {
				$out['decided'][ $kind ] = $choice;
				$out['from'][ $kind ]    = 'policy';
				continue;
			}
			$out['questions'][] = array(
				'id'      => $id,
				'kind'    => self::QUESTION_KINDS[ $key ],
				'count'   => count( array_unique( array_map( 'strval', $tables ) ) ),
				'choices' => array( 'restore', 'exclude' ),
			);
		}
		return $out;
	}

	/**
	 * A list in canonical form: sorted, each once, each name in hex.
	 *
	 * @param string[] $names Names.
	 * @return string
	 */
	private static function canonical( array $names ): string {
		$names = array_values( array_unique( array_map( 'strval', $names ) ) );
		sort( $names, SORT_STRING );
		return implode( ',', array_map( 'bin2hex', $names ) );
	}
}
