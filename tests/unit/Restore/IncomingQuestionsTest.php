<?php

namespace WPCheckpoint\Tests\Unit\Restore;

use WPCheckpoint\Restore\IncomingQuestions;
use WPCheckpoint\Restore\IncomingTables;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The questions about tables another installation may use, and the invariant of their answers (I-A): a table is
 * restored or left out by an answer only when that answer was given to a question that listed exactly the tables of
 * its kind as they are now (for the shared kind, with the same evidence). Checked on generated sequences of changes
 * to the live tables and the evidence, runs of the plan phase (some dying between the work file and the pause),
 * and whole or partial answers; and, as the reverse check, the generator finds the violations of the rule before
 * (one fixed id per kind, an answer used when the tables equal the work file's): a partial answer that reuses a
 * stale one (M-a), and a run that died after writing the work file (M-b).
 */
final class IncomingQuestionsTest extends TestCase {

	const POLICY = array(
		'uncertain_tables' => 'ask',
		'shared_tables'    => 'ask',
	);

	public function test_an_id_names_its_kind_and_the_canonical_form_of_its_list(): void {
		$a = IncomingQuestions::id( 'uncertain_tables', array( 'wp_b', 'wp_a', 'wp_b' ) );
		$this->assertSame( $a, IncomingQuestions::id( 'uncertain_tables', array( 'wp_a', 'wp_b' ) ), 'order and repeats do not matter' );
		$this->assertMatchesRegularExpression( '/\Auncertain_tables_[0-9a-f]{16}\z/', $a );
		$this->assertNotSame( $a, IncomingQuestions::id( 'uncertain_tables', array( 'wp_a', 'wp_b', 'wp_c' ) ), 'another list' );
		$this->assertNotSame( $a, IncomingQuestions::id( 'uncertain_tables', array( 'wp_a,wp_b' ) ), 'not the same as one name that joins them' );
		$this->assertNotSame( $a, IncomingQuestions::id( 'shared_tables', array( 'wp_a', 'wp_b' ) ), 'another kind' );
		$s = IncomingQuestions::id( 'shared_tables', array( 'wp_users', 'wp_usermeta' ), array( 'wp2_' ) );
		$this->assertSame( $s, IncomingQuestions::id( 'shared_tables', array( 'wp_usermeta', 'wp_users' ), array( 'wp2_', 'wp2_' ) ) );
		$this->assertNotSame( $s, IncomingQuestions::id( 'shared_tables', array( 'wp_users', 'wp_usermeta' ), array( 'wp2_', 'wp3_' ) ), 'new evidence: another question' );
		$this->assertNotSame( $s, IncomingQuestions::id( 'shared_tables', array( 'wp_users', 'wp_usermeta' ), array( 'wp2_' ), true ), 'a search that could not finish' );
		$this->assertSame( $a, IncomingQuestions::id( 'uncertain_tables', array( 'wp_a', 'wp_b' ), array( 'wp2_' ), true ), 'evidence is the shared kind\'s only' );
	}

	public function test_an_answer_decides_for_its_own_list_the_policy_without_one_and_otherwise_it_is_asked(): void {
		$listed = array(
			IncomingTables::UNCERTAIN => array( 'wp_old_shop' ),
			IncomingTables::SHARED    => array( 'wp_users', 'wp_usermeta' ),
		);
		$asked  = IncomingQuestions::decide( $listed, array( 'wp2_' ), false, array(), self::POLICY );
		$this->assertSame( array(), $asked['decided'] );
		$this->assertSame( array( 'tables_of_either_installation', 'tables_shared_with_another_installation' ), array_column( $asked['questions'], 'kind' ) );
		$this->assertSame( array( 1, 2 ), array_column( $asked['questions'], 'count' ) );
		$ids     = array_column( $asked['questions'], 'id' );
		$decided = IncomingQuestions::decide( $listed, array( 'wp2_' ), false, array( $ids[0] => 'exclude', $ids[1] => 'restore' ), self::POLICY );
		$this->assertSame(
			array(
				IncomingTables::UNCERTAIN => 'exclude',
				IncomingTables::SHARED    => 'restore',
			),
			$decided['decided']
		);
		$this->assertSame( array(), $decided['questions'] );
		$policy = IncomingQuestions::decide(
			$listed,
			array( 'wp2_' ),
			false,
			array(),
			array(
				'uncertain_tables' => 'restore',
				'shared_tables'    => 'exclude',
			)
		);
		$this->assertSame(
			array(
				IncomingTables::UNCERTAIN => 'policy',
				IncomingTables::SHARED    => 'policy',
			),
			$policy['from']
		);
		$this->assertSame( array(), IncomingQuestions::decide( array(), array(), false, array(), self::POLICY )['questions'], 'nothing to ask about' );
	}

	/**
	 * The rule before (8722eb2): one id per kind; an answer used when the kind's tables equal the work file's.
	 *
	 * @param array<string, string[]> $listed  Kind => tables.
	 * @param array<string, mixed>    $answers Answers.
	 * @param array<string, mixed>    $file    What the work file listed.
	 * @return array{decided: array<string, string>, from: array<string, string>, questions: array<int, array{id: string, kind: string, count: int, choices: string[]}>}
	 */
	private static function rule_before( array $listed, array $answers, array $file ): array {
		$out = array(
			'decided'   => array(),
			'from'      => array(),
			'questions' => array(),
		);
		foreach ( IncomingQuestions::KINDS as $key => $kind ) {
			$tables = $listed[ $kind ] ?? array();
			if ( array() === $tables ) {
				continue;
			}
			$seen = $file[ $kind ] ?? null;
			$same = is_array( $seen ) && self::sorted( $seen ) === self::sorted( $tables );
			if ( isset( $answers[ $key ] ) && $same ) {
				$out['decided'][ $kind ] = (string) $answers[ $key ];
				$out['from'][ $kind ]    = 'answer';
				continue;
			}
			$out['questions'][] = array(
				'id'      => $key,
				'kind'    => IncomingQuestions::QUESTION_KINDS[ $key ],
				'count'   => count( $tables ),
				'choices' => array( 'restore', 'exclude' ),
			);
		}
		return $out;
	}

	private static function sorted( array $list ): array {
		$list = array_values( array_unique( array_map( 'strval', $list ) ) );
		sort( $list, SORT_STRING );
		return $list;
	}

	/**
	 * One generated sequence under a rule; the violations of I-A, each with the steps before it.
	 *
	 * @param int  $seed   Seed.
	 * @param bool $before Whether to run the rule before instead of IncomingQuestions.
	 * @param array<string, int> $seen Controls, counted where they take effect.
	 * @return array<int, array{steps: string[], what: string}>
	 */
	private static function sequence( int $seed, bool $before, array &$seen ): array {
		mt_srand( $seed );
		$pool     = array( 'wp_old_a', 'wp_old_b', 'wp_old_c' );
		$state    = array(
			IncomingTables::UNCERTAIN => array( 'wp_old_a' ),
			IncomingTables::SHARED    => array(),
			'evidence'                => array(),
			'over'                    => false,
		);
		$answers  = array(); // As JobRepository::answer() keeps them: merged, never cleared.
		$given    = array(); // What each answer was given for: [kind, tables, evidence, choice].
		$paused   = null;    // The questions stored when the job paused, with what each listed.
		$file     = array(); // The work file (the rule before reads it).
		$steps    = array();
		$found    = array();
		$snapshot = static function ( array $state, string $kind ): string {
			$tables = self::sorted( $state[ $kind ] );
			return json_encode( IncomingTables::SHARED === $kind ? array( $tables, self::sorted( $state['evidence'] ), $state['over'] ) : array( $tables ) );
		};
		for ( $i = 0, $n = mt_rand( 4, 14 ); $i < $n; $i++ ) {
			$roll = mt_rand( 0, 9 );
			if ( null !== $paused && $roll < 5 ) {
				// The user answers some or all of the questions shown.
				$ids  = array_keys( $paused );
				$pick = 0 === mt_rand( 0, 2 ) && count( $ids ) > 1 ? array( $ids[ mt_rand( 0, count( $ids ) - 1 ) ] ) : $ids;
				$new  = array();
				foreach ( $pick as $id ) {
					$new[ $id ]   = 0 === mt_rand( 0, 1 ) ? 'restore' : 'exclude';
					$given[]      = array( $paused[ $id ]['kind'], $paused[ $id ]['snapshot'], $new[ $id ] );
				}
				$steps[] = count( $pick ) < count( $ids ) ? 'partial answer' : 'answer';
				if ( count( $pick ) < count( $ids ) ) {
					$seen['partial answer'] = ( $seen['partial answer'] ?? 0 ) + 1;
				}
				$answers = array_replace( $answers, $new );
				$paused  = null;
				continue;
			}
			if ( $roll < 7 ) {
				// The live tables or the evidence change.
				$what = mt_rand( 0, 2 );
				if ( 2 === $what ) {
					$state['evidence'] = 0 === mt_rand( 0, 1 ) ? array() : array_slice( array( 'wp2_', 'wp3_' ), 0, mt_rand( 1, 2 ) );
					$state['over']     = array() !== $state['evidence'] && 0 === mt_rand( 0, 3 );
					if ( $state['over'] ) {
						$seen['over'] = ( $seen['over'] ?? 0 ) + 1;
					}
				} else {
					$kind           = 0 === $what ? IncomingTables::UNCERTAIN : IncomingTables::SHARED;
					$list           = 0 === $what ? $pool : array( 'wp_users', 'wp_usermeta' );
					$state[ $kind ] = array_values( array_filter( $list, static function () { return 0 === mt_rand( 0, 1 ); } ) );
				}
				$steps[] = 'change';
				continue;
			}
			if ( null !== $paused ) {
				continue; // A paused job is not run.
			}
			// The plan phase runs.
			$listed   = array(
				IncomingTables::UNCERTAIN => $state[ IncomingTables::UNCERTAIN ],
				IncomingTables::SHARED    => $state[ IncomingTables::SHARED ],
			);
			$decision = $before ? self::rule_before( $listed, $answers, $file ) : IncomingQuestions::decide( $listed, $state['evidence'], $state['over'], $answers, self::POLICY );
			foreach ( $decision['decided'] as $kind => $choice ) {
				if ( 'answer' !== $decision['from'][ $kind ] ) {
					continue;
				}
				$seen['decided by an answer'] = ( $seen['decided by an answer'] ?? 0 ) + 1;
				if ( ! in_array( array( $kind, $snapshot( $state, $kind ), $choice ), $given, true ) ) {
					$found[] = array(
						'steps' => $steps,
						'what'  => $kind . ' decided "' . $choice . '" for tables no answer was given for',
					);
				}
			}
			if ( array() === $decision['questions'] ) {
				$steps[] = 'run: done';
				continue;
			}
			$file = $listed; // Written before the pause.
			if ( 0 === mt_rand( 0, 3 ) ) {
				$steps[]        = 'run: died before the pause';
				$seen['crash'] = ( $seen['crash'] ?? 0 ) + 1;
				continue;
			}
			$paused = array();
			foreach ( $decision['questions'] as $question ) {
				$kind                       = 'tables_of_either_installation' === $question['kind'] ? IncomingTables::UNCERTAIN : IncomingTables::SHARED;
				$paused[ $question['id'] ] = array(
					'kind'     => $kind,
					'snapshot' => $snapshot( $state, $kind ),
				);
			}
			$steps[]       = 'run: asked ' . count( $paused );
			$seen['asked'] = ( $seen['asked'] ?? 0 ) + 1;
		}
		return $found;
	}

	public function test_an_answer_decides_only_for_the_tables_it_was_given_for(): void {
		$seen  = array();
		$found = array();
		for ( $seed = 1; $seed <= 3000; $seed++ ) {
			$found = array_merge( $found, self::sequence( $seed, false, $seen ) );
		}
		$this->assertSame( array(), array_slice( $found, 0, 5 ), count( $found ) . ' violations' );
		foreach ( array( 'asked', 'partial answer', 'crash', 'decided by an answer', 'over' ) as $point ) {
			$this->assertGreaterThan( 0, $seen[ $point ] ?? 0, 'the control: ' . $point . ' ' . json_encode( $seen ) );
		}
	}

	public function test_the_generator_finds_both_ways_the_rule_before_broke_the_invariant(): void {
		$seen  = array();
		$found = array();
		for ( $seed = 1; $seed <= 3000; $seed++ ) {
			$found = array_merge( $found, self::sequence( $seed, true, $seen ) );
		}
		$this->assertNotSame( array(), $found, 'the rule before is caught' );
		$shapes = array(
			'M-a' => false,
			'M-b' => false,
		);
		foreach ( $found as $violation ) {
			// Its last pause and what came after it: a partial answer to it, or a run that died before pausing.
			$last = array_reverse( $violation['steps'] );
			foreach ( $last as $step ) {
				if ( 'partial answer' === $step ) {
					$shapes['M-a'] = true;
					break;
				}
				if ( 'run: died before the pause' === $step ) {
					$shapes['M-b'] = true;
					break;
				}
				if ( 'answer' === $step ) {
					break;
				}
			}
		}
		$this->assertSame(
			array(
				'M-a' => true,
				'M-b' => true,
			),
			$shapes,
			'a stale answer reused after a partial answer, and after a run that died between the work file and the pause'
		);
	}
}
